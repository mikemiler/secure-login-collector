/**
 * Base Admin Decryption Framework
 * Progressive enhancement architecture with hook/plugin system
 *
 * FREE VERSION: Uses all base functionality with no extensions
 * PRO VERSION: Extends via hooks and registered providers
 */
(function($) {
    'use strict';

    /**
     * BaseAdminDecryption - Core decryption framework
     *
     * Extension Points:
     * - hooks: Event-based extension system for PRO features
     * - extensions: Provider-based functionality injection
     *
     * Hook Points:
     * - beforeDecrypt: Modify/validate before decryption starts
     * - afterDecrypt: Process decrypted data, add features
     * - beforeDisplay: Modify display HTML, add UI elements
     * - afterDisplay: Bind additional UI handlers (e.g., export button)
     * - beforeClear: Cleanup before clearing data
     * - afterClear: Finalize after data cleared
     */
    class BaseAdminDecryption {
        constructor() {
            // Core state management
            this.decryptedData = new Map();
            this.privateKey = null; // Cache the private key
            this.autoClearTimeout = 60000; // 60 seconds
            this.countdownSeconds = 60;
            this.clearTimer = null;
            this.countdownInterval = null;

            // Extension Registry - PRO features register here
            this.extensions = {
                keyProvider: null,      // PRO: Custom key management
                uiEnhancer: null,       // PRO: UI modifications
                featureProvider: null,  // PRO: Additional features (export, etc.)
                storageProvider: null   // PRO: Custom storage backends
            };

            // Hook System - PRO features can register callbacks
            this.hooks = {
                beforeDecrypt: [],   // fn(entryId, encryptedPackage) => modified/validated data
                afterDecrypt: [],    // fn(entryId, decryptedData) => processed data
                beforeDisplay: [],   // fn(entryId, html, data) => modified html
                afterDisplay: [],    // fn(entryId, $container, data) => void (bind handlers)
                beforeClear: [],     // fn() => void (cleanup)
                afterClear: []       // fn() => void (post-cleanup)
            };
        }

        /**
         * Initialize the decryption system
         */
        init() {
            // Bind decrypt button clicks
            $(document).on('click', '.decrypt-btn-v2', (e) => this.handleDecrypt(e));

            // Trigger initialization hook for extensions
            this.triggerHook('afterInit', this);
        }

        /**
         * Register a hook callback
         * @param {string} hookName - Name of the hook
         * @param {Function} callback - Callback function to execute
         */
        registerHook(hookName, callback) {
            if (!this.hooks[hookName]) {
                console.warn(`Hook "${hookName}" does not exist`);
                return;
            }

            if (typeof callback !== 'function') {
                console.warn('Hook callback must be a function');
                return;
            }

            this.hooks[hookName].push(callback);
        }

        /**
         * Trigger a hook with arguments
         * @param {string} hookName - Name of the hook to trigger
         * @param {...any} args - Arguments to pass to hook callbacks
         * @returns {Promise<any>} - Result from hooks (can be modified data)
         */
        triggerHook(hookName, ...args) {
            if (!this.hooks[hookName]) {
                return args[0]; // Return first arg unchanged if hook doesn't exist
            }

            let result = args[0]; // Initial value

            for (const callback of this.hooks[hookName]) {
                try {
                    const hookResult = callback(...args);
                    // If hook returns a value, use it as the new result
                    if (hookResult !== undefined) {
                        result = hookResult;
                        args[0] = hookResult; // Update first arg for next hook
                    }
                } catch (error) {
                    console.error(`Error in hook "${hookName}":`, error);
                }
            }

            return result;
        }

        /**
         * Register an extension provider
         * @param {string} type - Type of extension (keyProvider, uiEnhancer, etc.)
         * @param {Object} provider - Extension provider object
         */
        registerExtension(type, provider) {
            if (!this.extensions.hasOwnProperty(type)) {
                console.warn(`Extension type "${type}" is not valid`);
                return;
            }

            this.extensions[type] = provider;
        }

        /**
         * Handle decrypt button click
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        async handleDecrypt(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const entryId = parseInt($btn.data('id'), 10);

            // Check if already decrypted
            if (this.decryptedData.has(entryId)) {
                this.displayDecryptedData(entryId, $btn);
                return;
            }

            try {
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-unlock spin"></span>');

                // Step 1: Get encrypted data from server
                let encryptedPackage = await this.getEncryptedData(entryId);
                
                // HOOK: beforeDecrypt - PRO can modify/validate data
                encryptedPackage = await this.triggerHook('beforeDecrypt', encryptedPackage, entryId);

                // Step 2: Get private key (uses extension or default)
                if (!this.privateKey) {
                    this.privateKey = await this.getPrivateKey(entryId);
                }
                
                // Step 3: Decrypt the data (CORE CRYPTO - shared by FREE/PRO)
                let decrypted = await this.decryptData(encryptedPackage, this.privateKey);

                // HOOK: afterDecrypt - PRO can process/enhance decrypted data
                decrypted = this.triggerHook('afterDecrypt', decrypted, entryId);
                
                // Store and display
                this.decryptedData.set(entryId, decrypted);
                this.displayDecryptedData(entryId, $btn);

                $btn.html('<span class="dashicons dashicons-yes"></span>').addClass('button-success');

                // Start/reset auto-clear countdown
                this.resetAutoClear();

            } catch (error) {
                console.error('Decryption failed:', error);
                alert('Decryption failed: ' + error.message);
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-unlock"></span>');
            }
        }

        /**
         * Get encrypted data from server
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        async getEncryptedData(entryId) {
            const response = await $.ajax({
                url: seculocoAjax.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_encrypted_entry',
                    id: entryId,
                    nonce: seculocoAjax.nonce
                }
            });

            if (!response.success) {
                throw new Error(response.data || 'Failed to get encrypted data');
            }

            return response.data;
        }

        /**
         * Get private key - uses extension or default FREE method
         * EXTENSION POINT - PRO can override via keyProvider
         */
        async getPrivateKey(entryId) {
            // If PRO extension registered, use it
            if (this.extensions.keyProvider && typeof this.extensions.keyProvider.getKey === 'function') {
                return await this.extensions.keyProvider.getKey(entryId);
            }

            // DEFAULT: Use FREE version key
            return await this.getFreePrivateKey();
        }

        /**
         * Get free private key from server (already unwrapped)
         * CORE FUNCTIONALITY - FREE version uses this directly
         */
        async getFreePrivateKey() {
            // Use a dummy entry ID for free version (server returns free key regardless)
            const response = await $.ajax({
                url: seculocoAjax.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_wrapped_private_key',
                    entry_id: 0, // Server will return free key
                    nonce: seculocoAjax.nonce
                }
            });

            if (!response.success) {
                throw new Error('Failed to get private key');
            }

            if (response.data.type !== 'free') {
                throw new Error('Expected free version key');
            }

            // Free key is already decrypted, just decode it
            const privateKeyB64 = response.data.private_key;
            return await this.importRSAPrivateKey(atob(privateKeyB64));
        }

        /**
         * Import RSA private key from PEM
         * CORE CRYPTO - Used by both FREE and PRO
         */
        async importRSAPrivateKey(pem) {
            // Remove PEM headers and decode base64
            const pemContents = pem
                .replace('-----BEGIN PRIVATE KEY-----', '')
                .replace('-----END PRIVATE KEY-----', '')
                .replace('-----BEGIN RSA PRIVATE KEY-----', '')
                .replace('-----END RSA PRIVATE KEY-----', '')
                .replace(/\s/g, '');

            const binaryDer = this.base64ToArrayBuffer(pemContents);

            return await crypto.subtle.importKey(
                'pkcs8',
                binaryDer,
                {
                    name: 'RSA-OAEP',
                    hash: 'SHA-256'
                },
                false,
                ['decrypt']
            );
        }

        /**
         * Decrypt the actual data using RSA-OAEP + AES-GCM
         * CORE CRYPTO - Used by both FREE and PRO
         * This is the heart of the decryption system
         */
        async decryptData(encryptedPackage, privateKey) {
            // First: RSA decrypt the AES key
            const encryptedAesKey = this.base64ToArrayBuffer(encryptedPackage.encrypted_aes_key);
            const aesKeyBuffer = await crypto.subtle.decrypt(
                { name: 'RSA-OAEP' },
                privateKey,
                encryptedAesKey
            );

            // Import AES key
            const aesKey = await crypto.subtle.importKey(
                'raw',
                aesKeyBuffer,
                { name: 'AES-GCM', length: 256 },
                false,
                ['decrypt']
            );

            // Decrypt data with AES
            const iv = this.base64ToArrayBuffer(encryptedPackage.iv);
            const encryptedData = this.base64ToArrayBuffer(encryptedPackage.encrypted_data);

            const decrypted = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: iv },
                aesKey,
                encryptedData
            );

            return JSON.parse(new TextDecoder().decode(decrypted));
        }

        /**
         * Display decrypted data in the UI
         * CORE FUNCTIONALITY with EXTENSION POINTS
         */
        displayDecryptedData(entryId, $btn = null) {
            const data = this.decryptedData.get(entryId);
            if (!data) {
                
                return;
            }

            let $row;

            // If we have the button reference, use it to find the row
            if ($btn && $btn.length > 0) {
                $row = $btn.closest('tr');
            } else {
                // Try multiple selectors to find the table row
                $row = $(`tr[data-id="${entryId}"]`);

                if ($row.length === 0) {
                    $row = $(`tr[data-entry-id="${entryId}"]`);
                }

                if ($row.length === 0) {
                    // Try finding by button data attribute
                    const $foundBtn = $(`.decrypt-btn-v2[data-id="${entryId}"]`);
                    if ($foundBtn.length > 0) {
                        $row = $foundBtn.closest('tr');
                    }
                }
            }

            if ($row.length === 0) {
                console.error('Could not find table row for entry:', entryId);
                return;
            }

            const $container = $row.find('.decrypted-data-container');

            if ($container.length === 0) {
                // Build base HTML
                let html = `
                    <tr class="decrypted-row" data-entry-id="${entryId}">
                        <td colspan="8">
                            <div class="decrypted-data-container">
                                <div class="decrypted-header">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <strong>Decrypted Data</strong>
                                        <span class="auto-clear-warning">Auto-clears in <span id="decrypted-area-countdown">60</span> seconds</span>
                                    </div>
                                </div>
                                <div class="decrypted-content">
                                    <div class="field-group">
                                        <label>Username/Email:</label>
                                        <div class="field-value">
                                            <input type="text" readonly value="${this.escapeHtml(data.username_email || '')}" />
                                            <button class="copy-btn" data-value="${this.escapeHtml(data.username_email || '')}">Copy</button>
                                        </div>
                                    </div>
                                    <div class="field-group">
                                        <label>Password:</label>
                                        <div class="field-value">
                                            <input type="password" class="password-field" readonly value="${this.escapeHtml(data.password || '')}" />
                                            <button class="toggle-password-btn">Show</button>
                                            <button class="copy-btn" data-value="${this.escapeHtml(data.password || '')}">Copy</button>
                                        </div>
                                    </div>
                                    <div class="field-group">
                                        <label>Notes:</label>
                                        <div class="field-value">
                                            <textarea readonly placeholder="No additional notes">${this.escapeHtml(data.additional_notes || '')}</textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                `;

                // HOOK: beforeDisplay - PRO can modify HTML (add export button, etc.)
                html =  this.triggerHook('beforeDisplay', html, entryId, data);

                $row.after(html);

                // Get the newly created container
                const $newContainer = $row.next('.decrypted-row').find('.decrypted-data-container');

                // HOOK: afterDisplay - PRO can bind additional UI handlers
                 this.triggerHook('afterDisplay', entryId, $newContainer, data);
            } else {
                $container.parent().parent().toggle();
            }

            // Bind copy and toggle buttons (CORE functionality)
            this.bindCopyButtons(entryId);

            // Reset auto-clear timer
            this.resetAutoClear();
        }

        /**
         * Bind copy and toggle buttons
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        bindCopyButtons(entryId) {
            const $row = $(`.decrypted-row[data-entry-id="${entryId}"]`);

            $row.find('.copy-btn').off('click').on('click', async function(e) {
                e.preventDefault();
                const value = $(this).data('value');
                try {
                    await navigator.clipboard.writeText(value);
                    $(this).text('Copied!');
                    setTimeout(() => $(this).text('Copy'), 2000);
                } catch (err) {
                    alert('Failed to copy');
                }
            });

            $row.find('.toggle-password-btn').off('click').on('click', function(e) {
                e.preventDefault();
                const $field = $(this).siblings('.password-field');
                if ($field.attr('type') === 'password') {
                    $field.attr('type', 'text');
                    $(this).text('Hide');
                } else {
                    $field.attr('type', 'password');
                    $(this).text('Show');
                }
            });
        }

        /**
         * Auto-clear sensitive data after timeout
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        startAutoClear() {
            // Clear any existing timers
            this.stopAutoClear();

            // Reset countdown
            this.countdownSeconds = 60;
            $('#decrypted-area-countdown').text(this.countdownSeconds);

            // Start countdown interval (updates every second)
            this.countdownInterval = setInterval(() => {
                this.countdownSeconds--;
                $('#decrypted-area-countdown').text(this.countdownSeconds);

                // Change color when less than 10 seconds
                if (this.countdownSeconds <= 10) {
                    $('.auto-clear-warning').css('color', '#d63638');
                } else {
                    $('.auto-clear-warning').css('color', '#996800');
                }

                if (this.countdownSeconds <= 0) {
                    this.clearAllDecryptedData();
                }
            }, 1000);

            // Set main clear timer
            this.clearTimer = setTimeout(() => {
                this.clearAllDecryptedData();
            }, this.autoClearTimeout);
        }

        /**
         * Stop auto-clear timer
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        stopAutoClear() {
            clearTimeout(this.clearTimer);
            clearInterval(this.countdownInterval);
            this.clearTimer = null;
            this.countdownInterval = null;
        }

        /**
         * Reset auto-clear timer
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        resetAutoClear() {
            this.stopAutoClear();
            this.startAutoClear();
        }

        /**
         * Clear all decrypted data from memory and UI
         * CORE FUNCTIONALITY with EXTENSION POINTS
         */
        async clearAllDecryptedData() {
            // HOOK: beforeClear - PRO can cleanup custom data
            await this.triggerHook('beforeClear');

            // Clear decrypted data from memory
            this.decryptedData.clear();
            this.privateKey = null; // Clear cached key

            // Remove from UI
            $('.decrypted-row').remove();
            $('.decrypt-btn-v2').each(function() {
                $(this).prop('disabled', false).removeClass('button-success');
                $(this).html('<span class="dashicons dashicons-unlock"></span>');
            });

            this.stopAutoClear();

            // HOOK: afterClear - PRO can perform post-cleanup
            await this.triggerHook('afterClear');
        }

        /**
         * Helper: Convert base64 to ArrayBuffer
         * CORE UTILITY - Used by both FREE and PRO
         */
        base64ToArrayBuffer(base64) {
            const binaryString = atob(base64);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        }

        /**
         * Helper: Escape HTML
         * CORE UTILITY - Used by both FREE and PRO
         */
        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text || '').replace(/[&<>"']/g, (m) => map[m]);
        }

        /**
         * Public API: Get decrypted data for an entry
         * Useful for PRO extensions that need access to decrypted data
         */
        getDecryptedData(entryId) {
            return this.decryptedData.get(entryId);
        }

        /**
         * Public API: Check if entry is decrypted
         * Useful for PRO extensions
         */
        isDecrypted(entryId) {
            return this.decryptedData.has(entryId);
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        // Create global instance - accessible to PRO extensions
        window.seculocoDecrypt = new BaseAdminDecryption();
        window.seculocoDecrypt.init();

        // Backwards compatibility alias
        window.freeAdminDecryption = window.seculocoDecrypt;
    });

})(jQuery);
