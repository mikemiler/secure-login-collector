/**
 * Password-Based Decryption Extension
 * PRO VERSION ONLY - Extends BaseAdminDecryption with password-based key unwrapping
 *
 * This extension adds support for password-protected encryption keys.
 * It registers a custom key provider that:
 * 1. Detects password-encrypted entries (encryption_type: 'aes-rsa-password-v2')
 * 2. Prompts user for password
 * 3. Derives unwrapping key from password using PBKDF2
 * 4. Unwraps RSA private key using AES-GCM
 * 5. Caches password in session memory (60 seconds)
 */
(function($) {
    'use strict';

    /**
     * Password-Based Key Provider
     * Handles password-protected RSA private keys
     */
    class PasswordKeyProvider {
        constructor(baseDecryption) {
            this.baseDecryption = baseDecryption;

            // Session cache for password (60 seconds)
            this.passwordCache = null;
            this.passwordCacheTimer = null;
            this.passwordCacheTimeout = 60000; // 60 seconds
        }

        /**
         * Main entry point - called by base decryption system
         * @param {number} entryId - Entry ID being decrypted
         * @param {object} encryptedPackage - Encrypted data package from server
         * @returns {Promise<CryptoKey>} - Unwrapped RSA private key
         */
        async getKey(entryId, encryptedPackage) {
            const encryptionType = encryptedPackage.encryption_type || 'aes-rsa-v2';

            // Check if this entry uses password-based encryption
            if (encryptionType !== 'aes-rsa-password-v2') {
                // Not a password entry, let base handler continue
                return await this.baseDecryption.getFreePrivateKey();
            }

            // This is a password-protected entry
            return await this.getPasswordProtectedKey(entryId);
        }

        /**
         * Get password-protected private key
         * @param {number} entryId - Entry ID
         * @returns {Promise<CryptoKey>} - Unwrapped RSA private key
         */
        async getPasswordProtectedKey(entryId) {
            // Step 1: Get wrapped private key from server
            const wrappedKeyData = await this.getWrappedPrivateKey(entryId);

            // Step 2: Get password from user (or cache)
            let password = this.passwordCache;
            if (!password) {
                password = await this.promptForPassword();
                this.cachePassword(password);
            }

            // Step 3: Unwrap the RSA private key using password
            try {
                const privateKey = await this.unwrapKeyWithPassword(
                    wrappedKeyData.wrapped_key,
                    wrappedKeyData.salt,
                    wrappedKeyData.iv,
                    password
                );
                return privateKey;
            } catch (error) {
                // Wrong password or corrupted data
                this.clearPasswordCache();
                throw new Error('Failed to unwrap key: ' + error.message);
            }
        }

        /**
         * Get wrapped private key from server
         * @param {number} entryId - Entry ID
         * @returns {Promise<object>} - Wrapped key data (wrapped_key, salt, iv)
         */
        async getWrappedPrivateKey(entryId) {
            const response = await $.ajax({
                url: seculocoAjax.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_wrapped_private_key',
                    entry_id: entryId,
                    nonce: seculocoAjax.nonce
                }
            });

            if (!response.success) {
                throw new Error('Failed to get wrapped private key');
            }

            if (response.data.type !== 'password') {
                throw new Error('Expected password-protected key');
            }

            return {
                wrapped_key: response.data.wrapped_key,
                salt: response.data.salt,
                iv: response.data.iv
            };
        }

        /**
         * Prompt user for password
         * @returns {Promise<string>} - User-entered password
         */
        async promptForPassword() {
            return new Promise((resolve, reject) => {
                // Create modal backdrop
                const $backdrop = $('<div>').addClass('seculoco-password-prompt-backdrop');

                // Create modal
                const $modal = $('<div>').addClass('seculoco-password-prompt-modal');

                // Build modal HTML
                $modal.html(`
                    <h2 class="seculoco-password-prompt-title">Enter Encryption Password</h2>
                    <p class="seculoco-password-prompt-description">
                        This data was encrypted with a password. Please enter the password to decrypt.
                    </p>
                    <div class="seculoco-password-input-wrapper">
                        <input
                            type="password"
                            class="seculoco-password-input"
                            id="seculoco-password-input"
                            placeholder="Enter password"
                            autocomplete="off"
                        />
                        <button type="button" class="seculoco-password-toggle-btn" aria-label="Toggle password visibility">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                    <div class="seculoco-password-prompt-checkbox">
                        <input
                            type="checkbox"
                            id="seculoco-remember-password"
                            checked
                        />
                        <label for="seculoco-remember-password">
                            Remember for this session (60 seconds)
                        </label>
                    </div>
                    <div class="seculoco-password-prompt-error" style="display: none;"></div>
                    <div class="seculoco-password-prompt-buttons">
                        <button type="button" class="seculoco-password-prompt-cancel-btn">Cancel</button>
                        <button type="button" class="seculoco-password-prompt-decrypt-btn">Decrypt</button>
                    </div>
                `);

                // Append to body
                $('body').append($backdrop).append($modal);

                // Focus password input
                setTimeout(() => {
                    $('#seculoco-password-input').focus();
                }, 100);

                // Toggle password visibility
                $modal.find('.seculoco-password-toggle-btn').on('click', function() {
                    const $input = $('#seculoco-password-input');
                    const $icon = $(this).find('.dashicons');

                    if ($input.attr('type') === 'password') {
                        $input.attr('type', 'text');
                        $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                    } else {
                        $input.attr('type', 'password');
                        $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                    }
                });

                // Handle decrypt button
                $modal.find('.seculoco-password-prompt-decrypt-btn').on('click', () => {
                    const password = $('#seculoco-password-input').val().trim();
                    const rememberPassword = $('#seculoco-remember-password').is(':checked');

                    if (!password) {
                        $modal.find('.seculoco-password-prompt-error')
                            .text('Please enter a password')
                            .show();
                        return;
                    }

                    // Remove modal
                    $modal.remove();
                    $backdrop.remove();

                    // Resolve with password (cache will be handled by caller)
                    resolve(password);
                });

                // Handle cancel button
                $modal.find('.seculoco-password-prompt-cancel-btn').on('click', () => {
                    $modal.remove();
                    $backdrop.remove();
                    reject(new Error('Password prompt cancelled'));
                });

                // Handle Enter key
                $modal.find('#seculoco-password-input').on('keypress', (e) => {
                    if (e.which === 13) {
                        $modal.find('.seculoco-password-prompt-decrypt-btn').click();
                    }
                });

                // Handle Escape key
                $(document).on('keydown.password-prompt', (e) => {
                    if (e.key === 'Escape') {
                        $(document).off('keydown.password-prompt');
                        $modal.remove();
                        $backdrop.remove();
                        reject(new Error('Password prompt cancelled'));
                    }
                });

                // Close on backdrop click
                $backdrop.on('click', () => {
                    $(document).off('keydown.password-prompt');
                    $modal.remove();
                    $backdrop.remove();
                    reject(new Error('Password prompt cancelled'));
                });
            });
        }

        /**
         * Unwrap RSA private key using password-derived wrapping key
         *
         * Process:
         * 1. Derive AES-GCM wrapping key from password using PBKDF2
         * 2. Unwrap the RSA private key using AES-GCM
         * 3. Return CryptoKey ready for RSA-OAEP decryption
         *
         * @param {string} wrappedKeyB64 - Base64 encoded wrapped key
         * @param {string} saltB64 - Base64 encoded salt for PBKDF2
         * @param {string} ivB64 - Base64 encoded IV for AES-GCM
         * @param {string} password - User's password
         * @returns {Promise<CryptoKey>} - Unwrapped RSA private key
         */
        async unwrapKeyWithPassword(wrappedKeyB64, saltB64, ivB64, password) {
            try {
                // Step 1: Derive wrapping key from password using PBKDF2
                const encoder = new TextEncoder();
                const passwordBuffer = encoder.encode(password);

                // Import password as key material
                const passwordKey = await crypto.subtle.importKey(
                    'raw',
                    passwordBuffer,
                    'PBKDF2',
                    false,
                    ['deriveKey']
                );

                // Decode salt
                const salt = this.baseDecryption.base64ToArrayBuffer(saltB64);

                // Derive AES-GCM wrapping key from password
                const wrappingKey = await crypto.subtle.deriveKey(
                    {
                        name: 'PBKDF2',
                        salt: salt,
                        iterations: 100000, // Must match server-side
                        hash: 'SHA-256'
                    },
                    passwordKey,
                    {
                        name: 'AES-GCM',
                        length: 256
                    },
                    false,
                    ['unwrapKey']
                );

                // Step 2: Unwrap RSA private key using AES-GCM
                const wrappedKey = this.baseDecryption.base64ToArrayBuffer(wrappedKeyB64);
                const iv = this.baseDecryption.base64ToArrayBuffer(ivB64);

                const unwrappedKey = await crypto.subtle.unwrapKey(
                    'pkcs8',                    // Format of wrapped key
                    wrappedKey,                 // Wrapped key data
                    wrappingKey,                // AES-GCM wrapping key
                    {
                        name: 'AES-GCM',
                        iv: iv
                    },
                    {
                        name: 'RSA-OAEP',       // Target key algorithm
                        hash: 'SHA-256'
                    },
                    false,                      // Not extractable
                    ['decrypt']                 // Key usage
                );

                return unwrappedKey;

            } catch (error) {
                console.error('Unwrap key error:', error);
                throw new Error('Failed to unwrap key with password: ' + error.message);
            }
        }

        /**
         * Cache password in session memory
         * @param {string} password - Password to cache
         */
        cachePassword(password) {
            this.passwordCache = password;

            // Clear existing timer
            if (this.passwordCacheTimer) {
                clearTimeout(this.passwordCacheTimer);
            }

            // Set new timer to clear cache
            this.passwordCacheTimer = setTimeout(() => {
                this.clearPasswordCache();
            }, this.passwordCacheTimeout);
        }

        /**
         * Clear password cache
         */
        clearPasswordCache() {
            this.passwordCache = null;
            if (this.passwordCacheTimer) {
                clearTimeout(this.passwordCacheTimer);
                this.passwordCacheTimer = null;
            }
        }
    }

    // Initialize when base decryption is ready
    $(document).ready(function() {
        // Wait for base decryption to initialize
        if (window.seculocoDecrypt) {
            const passwordProvider = new PasswordKeyProvider(window.seculocoDecrypt);
            window.seculocoDecrypt.registerExtension('keyProvider', passwordProvider);

            console.log('✅ Password-based decryption extension loaded');
        } else {
            console.error('❌ Base decryption not found - password extension cannot load');
        }
    });

})(jQuery);
