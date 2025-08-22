/**
 * Secure Admin Decryption with Passkey-Unwrapping
 * 
 * Implements the complete decryption flow:
 * 1. Get encrypted data and wrapped private key from server
 * 2. Authenticate with passkey to derive unwrapping key
 * 3. Unwrap RSA private key in browser
 * 4. Decrypt data with unwrapped key
 * 5. Auto-clear after timeout
 */

(function($) {
    'use strict';

    class SecureAdminDecryption {
        constructor() {
            this.decryptedData = new Map();
            this.unwrappedKeys = { pro: null, free: null }; // Cache both key types separately
            this.autoClearTimeout = 60000; // 60 seconds
            this.init();
        }

        init() {
            // Bind decrypt buttons (both old and new class names)
            $(document).on('click', '.decrypt-btn, .decrypt-btn-v2', (e) => this.handleDecrypt(e));
            
            // Auto-clear sensitive data
            this.startAutoClear();
        }

        /**
         * Handle decrypt button click
         */
        async handleDecrypt(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const entryId = $btn.data('id');
            

            
            // Check if already decrypted
            if (this.decryptedData.has(entryId)) {

                this.displayDecryptedData(entryId, $btn);
                return;
            }

            try {
                $btn.prop('disabled', true).text('Authenticating...');

                
                // Step 1: Get encrypted data from server
                const encryptedPackage = await this.getEncryptedData(entryId);

                // Step 2: Get wrapped private key (determine type first)
                const keyInfo = await this.getKeyType(entryId);
                const keyType = keyInfo.type;
                
                // Check if we have the right key cached
                if (!this.unwrappedKeys[keyType]) {

                    await this.unwrapPrivateKey(entryId, keyType);
                }

                const privateKey = this.unwrappedKeys[keyType];

                
                // Step 3: Decrypt the data
                const decrypted = await this.decryptData(encryptedPackage, privateKey);
                
                // Store and display
                this.decryptedData.set(entryId, decrypted);
                this.displayDecryptedData(entryId, $btn);
                
                $btn.text('Decrypted').addClass('success');
                
            } catch (error) {
                console.error('Decryption failed:', error);
                alert('Decryption failed: ' + error.message);
                $btn.prop('disabled', false).text('Decrypt');
            }
        }

        /**
         * Get encrypted data from server
         */
        async getEncryptedData(entryId) {
            const response = await $.ajax({
                url: secureLoginAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_encrypted_entry',
                    id: entryId,
                    nonce: secureLoginAdmin.nonce
                }
            });

            if (!response.success) {
                throw new Error(response.data || 'Failed to get encrypted data');
            }

            return response.data;
        }

        /**
         * Get key type for an entry (determines if pro or free key is needed)
         */
        async getKeyType(entryId) {
            const response = await $.ajax({
                url: secureLoginAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'slc_get_wrapped_private_key',
                    entry_id: entryId,
                    nonce: secureLoginAdmin.nonce
                }
            });

            if (!response.success) {
                throw new Error('Failed to get key type information');
            }

            return { type: response.data.type || 'free' };
        }

        /**
         * Unwrap the private key using passkey authentication
         * @param {string} entryId - The entry ID
         * @param {string} keyType - The key type (pro or free)
         * @param {object} preAuthData - Optional pre-authenticated data {credentialId, userId, derivedKey}
         */
        async unwrapPrivateKey(entryId, keyType, preAuthData = null) {
            // Get wrapped key from server (pass entry ID to determine pro vs free key)
            const wrappedResponse = await $.ajax({
                url: secureLoginAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'slc_get_wrapped_private_key',
                    entry_id: entryId,
                    nonce: secureLoginAdmin.nonce
                }
            });

            if (!wrappedResponse.success) {
                console.error('Failed to get wrapped private key:', wrappedResponse);
                
                throw new Error('Failed to get wrapped private key');
            }
            
            
            // Handle different key types (pro vs free)
            if (wrappedResponse.data.type === 'pro') {
                // Pro key requires passkey authentication
                const wrappedKey = wrappedResponse.data.wrapped_key;

                let unwrappingKey;
                
                // Check if we have pre-authenticated data (from bulk export)
                if (preAuthData && preAuthData.derivedKey) {
                    unwrappingKey = preAuthData.derivedKey;
                } else {
                    // Authenticate with passkey to get unwrapping key
                    unwrappingKey = await this.authenticateWithPasskey();
                }
                
                // Unwrap the private key

                this.unwrappedKeys.pro = await this.unwrapKey(wrappedKey, unwrappingKey);

            } else {
                // Free key is already decrypted, just decode it
                const privateKeyB64 = wrappedResponse.data.private_key;

                
                // Import the private key directly (it's already in PEM format)
                this.unwrappedKeys.free = await this.importRSAPrivateKey(atob(privateKeyB64));
            }
        }

        /**
         * Authenticate with passkey and derive unwrapping key
         */
        async authenticateWithPasskey() {
            // Check WebAuthn support
            if (!window.PublicKeyCredential) {
                throw new Error('WebAuthn not supported. Please use a modern browser.');
            }

            // Get challenge from server
            const challengeResponse = await $.ajax({
                url: secureLoginAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'passkey_get_challenge',
                    nonce: secureLoginAdmin.nonce
                }
            });

            if (!challengeResponse.success) {
                throw new Error('Failed to get authentication challenge');
            }

            const challenge = this.base64ToArrayBuffer(challengeResponse.data.challenge);
            const allowCredentials = challengeResponse.data.credentials.map(cred => ({
                type: 'public-key',
                id: this.base64ToArrayBuffer(cred.id)
            }));

            // Authenticate with passkey
            const assertion = await navigator.credentials.get({
                publicKey: {
                    challenge: challenge,
                    allowCredentials: allowCredentials,
                    userVerification: 'required',
                    timeout: 60000
                }
            });

            // Derive the unwrapping key from the assertion
            return this.deriveUnwrappingKeyFromAssertion(assertion);
        }

        /**
         * Derive unwrapping key from passkey assertion
         * @param {PublicKeyCredential} assertion - The passkey assertion
         * @returns {CryptoKey} The derived unwrapping key
         */
        async deriveUnwrappingKeyFromAssertion(assertion) {
            // Get credential ID from assertion
            const credentialId = new Uint8Array(assertion.rawId);
            const credentialIdB64 = btoa(String.fromCharCode(...credentialId));
            
            // Get user ID (we'll need to get this from server or session)
            const userResponse = await $.ajax({
                url: secureLoginAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_current_user_id',
                    nonce: secureLoginAdmin.nonce
                }
            });
            
            if (!userResponse.success) {
                throw new Error('Failed to get user ID for key derivation');
            }
            
            const userId = userResponse.data.user_id;
            
            // Match server-side key derivation: credential_id + user_id + salt
            // Note: We can't access wp_salt() from client, so we'll ask server to derive the key
            const keyResponse = await $.ajax({
                url: secureLoginAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'derive_passkey_unwrapping_key',
                    credential_id: credentialIdB64,
                    user_id: userId,
                    nonce: secureLoginAdmin.nonce
                }
            });
            
            if (!keyResponse.success) {
                throw new Error('Failed to derive unwrapping key');
            }
            
            // Import the derived key as AES-GCM key
            const keyBytes = this.base64ToArrayBuffer(keyResponse.data.key);
            const unwrappingKey = await crypto.subtle.importKey(
                'raw',
                keyBytes,
                { name: 'AES-GCM', length: 256 },
                false,
                ['decrypt']
            );

            return unwrappingKey;
        }

        /**
         * Unwrap the RSA private key
         */
        async unwrapKey(wrappedKey, unwrappingKey) {
            const iv = this.base64ToArrayBuffer(wrappedKey.iv);
            const tag = this.base64ToArrayBuffer(wrappedKey.tag);
            const encrypted = this.base64ToArrayBuffer(wrappedKey.encrypted);

            // For AES-GCM, concatenate encrypted data and tag
            const ciphertext = new Uint8Array(encrypted.byteLength + tag.byteLength);
            ciphertext.set(new Uint8Array(encrypted), 0);
            ciphertext.set(new Uint8Array(tag), encrypted.byteLength);

            
            // Decrypt the private key - AES-GCM with proper tag length
            const decrypted = await crypto.subtle.decrypt(
                { 
                    name: 'AES-GCM', 
                    iv: iv,
                    tagLength: tag.byteLength * 8 // Convert bytes to bits (should be 128)
                },
                unwrappingKey,
                ciphertext
            );


            // Convert to PEM format string
            const privateKeyPem = new TextDecoder().decode(decrypted);
            
            // Import as CryptoKey for use
            return await this.importRSAPrivateKey(privateKeyPem);
        }

        /**
         * Import RSA private key from PEM
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
         * Decrypt the actual data
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
         * Display decrypted data
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
                    // Try alternative selectors
                    $row = $(`tr[data-entry-id="${entryId}"]`);

                }
                
                if ($row.length === 0) {
                    // Try finding by button data attribute
                    const $foundBtn = $(`.decrypt-btn[data-id="${entryId}"], .decrypt-btn-v2[data-id="${entryId}"]`);
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
                // Create container if doesn't exist
                const html = `
                    <tr class="decrypted-row" data-entry-id="${entryId}">
                        <td colspan="8">
                            <div class="decrypted-data-container">
                                <div class="decrypted-header">
                                    <strong>Decrypted Data</strong>
                                    <span class="auto-clear-warning">Auto-clears in 60 seconds</span>
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
                $row.after(html);
            } else {
                $container.parent().parent().toggle();
            }

            // Bind copy buttons
            this.bindCopyButtons(entryId);
            
            // Reset auto-clear timer
            this.resetAutoClear();
        }

        /**
         * Bind copy and toggle buttons
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
         */
        startAutoClear() {
            this.clearTimer = setTimeout(() => {
                this.clearAllDecryptedData();
            }, this.autoClearTimeout);
        }

        resetAutoClear() {
            clearTimeout(this.clearTimer);
            this.startAutoClear();
        }

        clearAllDecryptedData() {
            // Clear decrypted data from memory
            this.decryptedData.clear();
            this.unwrappedKeys = { pro: null, free: null };
            
            // Remove from UI
            $('.decrypted-row').remove();
            $('.decrypt-btn').prop('disabled', false).text('Decrypt').removeClass('success');
            

        }

        /**
         * Utility functions
         */
        base64ToArrayBuffer(base64) {
            const binary = atob(base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return bytes.buffer;
        }

        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    }

    // Initialize when document ready
    $(document).ready(() => {
        if ($('.secure-login-admin-table').length > 0) {
            window.secureAdminDecryption = new SecureAdminDecryption();
        }
    });

})(jQuery);