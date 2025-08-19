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
            this.unwrappedKey = null;
            this.autoClearTimeout = 60000; // 60 seconds
            this.init();
        }

        init() {
            // Bind decrypt buttons
            $(document).on('click', '.decrypt-btn', (e) => this.handleDecrypt(e));
            
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
                this.displayDecryptedData(entryId);
                return;
            }

            try {
                $btn.prop('disabled', true).text('Authenticating...');
                
                // Step 1: Get encrypted data from server
                const encryptedPackage = await this.getEncryptedData(entryId);
                
                // Step 2: Get wrapped private key (if not cached)
                if (!this.unwrappedKey) {
                    await this.unwrapPrivateKey();
                }
                
                // Step 3: Decrypt the data
                const decrypted = await this.decryptData(encryptedPackage);
                
                // Store and display
                this.decryptedData.set(entryId, decrypted);
                this.displayDecryptedData(entryId);
                
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
                url: ajaxurl,
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
         * Unwrap the private key using passkey authentication
         */
        async unwrapPrivateKey() {
            // Get wrapped key from server
            const wrappedResponse = await $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'slc_get_wrapped_private_key',
                    nonce: secureLoginAdmin.nonce
                }
            });

            if (!wrappedResponse.success) {
                throw new Error('Failed to get wrapped private key');
            }

            const wrappedKey = wrappedResponse.data.wrapped_key;

            // Authenticate with passkey to get unwrapping key
            const unwrappingKey = await this.authenticateWithPasskey();

            // Unwrap the private key
            this.unwrappedKey = await this.unwrapKey(wrappedKey, unwrappingKey);
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
                url: ajaxurl,
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

            // Derive key from passkey authentication
            // Using the signature as key material (this is a simplified version)
            const signature = new Uint8Array(assertion.response.signature);
            const keyMaterial = await crypto.subtle.importKey(
                'raw',
                signature.slice(0, 32), // Use first 32 bytes of signature
                'HKDF',
                false,
                ['deriveKey']
            );

            // Derive AES key for unwrapping
            const unwrappingKey = await crypto.subtle.deriveKey(
                {
                    name: 'HKDF',
                    hash: 'SHA-256',
                    salt: new TextEncoder().encode('SLC-UNWRAP'),
                    info: new TextEncoder().encode('passkey-unwrap')
                },
                keyMaterial,
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

            // Combine encrypted data and tag for AES-GCM
            const ciphertext = new Uint8Array(encrypted.byteLength + tag.byteLength);
            ciphertext.set(new Uint8Array(encrypted), 0);
            ciphertext.set(new Uint8Array(tag), encrypted.byteLength);

            // Decrypt the private key
            const decrypted = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: iv },
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
        async decryptData(encryptedPackage) {
            // First: RSA decrypt the AES key
            const encryptedAesKey = this.base64ToArrayBuffer(encryptedPackage.encrypted_aes_key);
            const aesKeyBuffer = await crypto.subtle.decrypt(
                { name: 'RSA-OAEP' },
                this.unwrappedKey,
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
        displayDecryptedData(entryId) {
            const data = this.decryptedData.get(entryId);
            if (!data) return;

            const $row = $(`tr[data-id="${entryId}"]`);
            const $container = $row.find('.decrypted-data-container');
            
            if ($container.length === 0) {
                // Create container if doesn't exist
                const html = `
                    <tr class="decrypted-row" data-entry-id="${entryId}">
                        <td colspan="7">
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
                                    ${data.additional_notes ? `
                                    <div class="field-group">
                                        <label>Notes:</label>
                                        <div class="field-value">
                                            <textarea readonly>${this.escapeHtml(data.additional_notes)}</textarea>
                                        </div>
                                    </div>
                                    ` : ''}
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
            
            $row.find('.copy-btn').off('click').on('click', async function() {
                const value = $(this).data('value');
                try {
                    await navigator.clipboard.writeText(value);
                    $(this).text('Copied!');
                    setTimeout(() => $(this).text('Copy'), 2000);
                } catch (err) {
                    alert('Failed to copy');
                }
            });

            $row.find('.toggle-password-btn').off('click').on('click', function() {
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
            this.unwrappedKey = null;
            
            // Remove from UI
            $('.decrypted-row').remove();
            $('.decrypt-btn').prop('disabled', false).text('Decrypt').removeClass('success');
            
            console.log('Sensitive data cleared from memory');
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