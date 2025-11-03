/**
 * Secure Login Collector - PRO Decryption Plugin
 *
 * Extends the base decryption framework with PRO-only features:
 * - Passkey authentication for key unwrapping
 * - Password manager export functionality
 * - Multi-key management (pro/free keys)
 *
 * @requires admin-decrypt.js (base framework)
 */

(function($) {
    'use strict';

    class ProDecryptionPlugin {
        constructor(baseFramework) {
            if (!baseFramework) {
                throw new Error('Base decryption framework not available!');
            }

            this.base = baseFramework;
            this.unwrappedKeys = { pro: null, free: null };
            this.registerWithBase();
            this.initEventHandlers();
        }

        /**
         * Register plugin with base framework
         */
        registerWithBase() {
            // Register PRO key provider (overrides base free-only key provider)
            // Base expects an object with a getKey method
            this.base.registerExtension('keyProvider', {
                getKey: (entryId) => this.handleProKeyUnwrapping(entryId)
            });

            // Add PRO UI enhancements after display
            this.base.registerHook('afterDisplay', (entryId, data) => this.addExportButton(entryId));

            // Handle multi-key clearing
            this.base.registerHook('beforeClear', () => this.clearProKeys());

            // Ensure we fetch the correct key per entry by clearing cached key
            // before each decrypt. This avoids reusing a previous entry's key
            // when mixed FREE/PRO entries are present.
            this.base.registerHook('beforeDecrypt', (encryptedPackage, entryId) => {
                this.base.privateKey = null;
                return encryptedPackage;
            });
        }

        /**
         * Initialize event handlers for PRO features
         */
        initEventHandlers() {
            $(document).on('click', '.export-to-password-manager', (e) => this.handlePasswordManagerExport(e));
        }

        /**
         * PRO key unwrapping with passkey authentication
         * Determines key type and unwraps accordingly
         */
        async handleProKeyUnwrapping(entryId) {
            // Determine key type
            const keyInfo = await this.getKeyType(entryId);
            const keyType = keyInfo.type;

            // Check if we have the right key cached
            if (this.unwrappedKeys[keyType]) {
                return this.unwrappedKeys[keyType];
            }

            // Get wrapped key from server
            const wrappedResponse = await $.ajax({
                url: seculocoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_wrapped_private_key',
                    entry_id: entryId,
                    nonce: seculocoAdmin.nonce
                }
            });

            if (!wrappedResponse.success) {
                throw new Error('Failed to get wrapped private key');
            }

            // Handle PRO vs FREE keys differently
            if (wrappedResponse.data.type === 'pro') {
                // PRO key: Authenticate with passkey and unwrap
                const wrappedKey = wrappedResponse.data.wrapped_key;
                const unwrappingKey = await this.authenticateWithPasskey();
                this.unwrappedKeys.pro = await this.unwrapKey(wrappedKey, unwrappingKey);
                return this.unwrappedKeys.pro;
            } else {
                // FREE key: Direct import (already decrypted)
                const privateKeyB64 = wrappedResponse.data.private_key;
                const privateKeyPem = atob(privateKeyB64);
                this.unwrappedKeys.free = await this.base.importRSAPrivateKey(privateKeyPem);
                return this.unwrappedKeys.free;
            }
        }

        /**
         * Determine if entry uses pro or free key
         */
        async getKeyType(entryId) {
            const response = await $.ajax({
                url: seculocoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_wrapped_private_key',
                    entry_id: entryId,
                    nonce: seculocoAdmin.nonce
                }
            });

            if (!response.success) {
                throw new Error('Failed to get key type information');
            }

            return { type: response.data.type || 'free' };
        }

        /**
         * Authenticate with passkey to derive unwrapping key
         */
        async authenticateWithPasskey() {
            if (!window.PublicKeyCredential) {
                throw new Error('WebAuthn not supported. Please use a modern browser.');
            }

            // Get challenge from server
            const challengeResponse = await $.ajax({
                url: seculocoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_passkey_challenge',
                    // This endpoint accepts seculoco_admin_nonce or seculoco_nonce; we pass the latter
                    nonce: seculocoAdmin.nonce
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

            return this.deriveUnwrappingKey(assertion);
        }

        /**
         * Derive unwrapping key from passkey assertion
         */
        async deriveUnwrappingKey(assertion) {
            const credentialId = new Uint8Array(assertion.rawId);
            const credentialIdB64 = btoa(String.fromCharCode(...credentialId));

            // Get user ID from server
            const userResponse = await $.ajax({
                url: (typeof secureLoginPasskeyData !== 'undefined' ? secureLoginPasskeyData.ajaxUrl : seculocoAdmin.ajaxurl),
                method: 'POST',
                data: {
                    action: 'get_current_user_id',
                    // This endpoint requires seculoco_admin_nonce or passkey_admin_nonce; use the passkey nonce
                    nonce: (typeof secureLoginPasskeyData !== 'undefined' ? secureLoginPasskeyData.nonce : seculocoAdmin.nonce)
                }
            });

            if (!userResponse.success) {
                throw new Error('Failed to get user ID for key derivation');
            }

            // Server derives key (has access to wp_salt())
            const keyResponse = await $.ajax({
                url: (typeof secureLoginPasskeyData !== 'undefined' ? secureLoginPasskeyData.ajaxUrl : seculocoAdmin.ajaxurl),
                method: 'POST',
                data: {
                    action: 'derive_passkey_unwrapping_key',
                    credential_id: credentialIdB64,
                    user_id: userResponse.data.user_id,
                    // This endpoint requires seculoco_admin_nonce or passkey_admin_nonce; use the passkey nonce
                    nonce: (typeof secureLoginPasskeyData !== 'undefined' ? secureLoginPasskeyData.nonce : seculocoAdmin.nonce)
                }
            });

            if (!keyResponse.success) {
                throw new Error('Failed to derive unwrapping key');
            }

            // Import derived key as AES-GCM key
            const keyBytes = this.base64ToArrayBuffer(keyResponse.data.key);
            return await crypto.subtle.importKey(
                'raw',
                keyBytes,
                { name: 'AES-GCM', length: 256 },
                false,
                ['decrypt']
            );
        }

        /**
         * Unwrap the RSA private key using AES-GCM
         */
        async unwrapKey(wrappedKey, unwrappingKey) {
            const iv = this.base64ToArrayBuffer(wrappedKey.iv);
            const tag = this.base64ToArrayBuffer(wrappedKey.tag);
            const encrypted = this.base64ToArrayBuffer(wrappedKey.encrypted);

            // AES-GCM: concatenate encrypted data and tag
            const ciphertext = new Uint8Array(encrypted.byteLength + tag.byteLength);
            ciphertext.set(new Uint8Array(encrypted), 0);
            ciphertext.set(new Uint8Array(tag), encrypted.byteLength);

            // Decrypt the private key
            const decrypted = await crypto.subtle.decrypt(
                {
                    name: 'AES-GCM',
                    iv: iv,
                    tagLength: tag.byteLength * 8
                },
                unwrappingKey,
                ciphertext
            );

            // Convert to PEM and import as CryptoKey
            const privateKeyPem = new TextDecoder().decode(decrypted);
            return await this.base.importRSAPrivateKey(privateKeyPem);
        }

        /**
         * Add export button to decrypted data display
         */
        addExportButton(entryId) {
            const $container = $(`.decrypted-row[data-entry-id="${entryId}"] .decrypted-header`);

            if ($container.length === 0) return;

            // Check if button already exists
            if ($container.find('.export-to-password-manager').length > 0) return;

            // Add export button
            const $exportBtn = $(`
                <button type="button" class="button button-primary export-to-password-manager"
                        data-id="${entryId}" title="Export to Password Manager" style="margin: 0;">
                    <span class="dashicons dashicons-cloud-upload" style="margin-top: 3px;"></span>
                    Export to Password Manager
                </button>
            `);

            $container.append($exportBtn);
        }

        /**
         * Clear PRO keys from memory
         */
        clearProKeys() {
            this.unwrappedKeys = { pro: null, free: null };
            console.log('PRO keys cleared from memory');
        }

        /**
         * Handle password manager export (PRO feature)
         */
        async handlePasswordManagerExport(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const entryId = parseInt($btn.data('id'), 10);

            try {
                // Get decrypted data from base framework
                const decryptedData = this.base.decryptedData.get(entryId);

                if (!decryptedData) {
                    alert('No decrypted data available. Please decrypt the data first.');
                    return;
                }

                // Get metadata from table
                const $tableRow = $(`#row-${entryId}`);
                const loginUrl = $tableRow.find('[data-field="login_url"]').text().trim();
                const name = $tableRow.find('[data-field="name"]').text().trim();

                // Trigger password manager
                await this.triggerPasswordManagerViaForm(decryptedData, loginUrl, name);

                // Show success
                this.showNotification('✓ Password manager triggered! Check your browser for the save prompt.', 'success');

            } catch (error) {
                if (error.message === 'User cancelled') {
                    return;
                }
                console.error('Password manager export failed:', error);
                alert('Export failed: ' + error.message);
            }
        }

        /**
         * Trigger password manager save using form submission
         */
        async triggerPasswordManagerViaForm(decryptedData, loginUrl, name) {
            // Ensure URL has protocol
            let actionUrl = loginUrl || window.location.href;
            if (actionUrl && !actionUrl.match(/^https?:\/\//)) {
                actionUrl = 'https://' + actionUrl;
            }

            // Remove existing form
            $('#seculoco-export-form, #seculoco-export-backdrop').remove();

            // Create form
            const form = this.createExportForm(decryptedData, actionUrl);
            const backdrop = this.createBackdrop();

            document.body.appendChild(backdrop);
            document.body.appendChild(form);

            return this.waitForFormSubmission(form, backdrop);
        }

        /**
         * Create export form with all fields
         */
        createExportForm(decryptedData, actionUrl) {
            const form = document.createElement('form');
            form.id = 'seculoco-export-form';
            form.method = 'POST';
            form.action = '#';
            form.autocomplete = 'on';
            form.style.cssText = `
                position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                z-index: 999999; background: white; padding: 30px; border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3); min-width: 500px; max-width: 600px;
                max-height: 90vh; overflow-y: auto;
            `;

            // Build form content
            form.innerHTML = this.buildFormHTML(decryptedData, actionUrl);

            // Bind form events
            this.bindFormEvents(form);

            return form;
        }

        /**
         * Build form HTML content
         */
        buildFormHTML(data, url) {
            return `
                <h3 style="margin: 0 0 20px 0; color: #333;">Export Login to Password Manager</h3>

                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                    <strong style="color: #856404;">⚠️ Important: URL Editing Required</strong>
                    <p style="margin: 8px 0 0 0; color: #856404; font-size: 13px;">
                        Copy the Login URL below and edit the pre-filled URL in your password manager before saving.
                    </p>
                </div>

                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Login URL (Copy this for later):</label>
                <div style="display: flex; gap: 8px; margin-bottom: 5px;">
                    <input type="text" id="seculoco-export-url" value="${this.escapeHtml(url)}"
                           style="flex: 1; padding: 8px; border: 1px solid #2271b1; border-radius: 4px; background: #f0f7ff;" />
                    <button type="button" id="copy-url-btn" class="button" style="padding: 8px 16px;">📋 Copy</button>
                </div>
                <small style="display: block; margin: 0 0 15px 0; color: #d63638; font-weight: 500;">
                    ⚠️ Remember to copy this URL! You'll need it to edit the password manager entry.
                </small>

                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Username:</label>
                <input type="text" name="username" id="seculoco-export-username" autocomplete="username"
                       value="${this.escapeHtml(data.username_email || '')}"
                       style="width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #8c8f94; border-radius: 4px;" />

                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Password:</label>
                <div style="position: relative; margin-bottom: 15px;">
                    <input type="password" name="password" id="seculoco-export-password" autocomplete="current-password"
                           value="${this.escapeHtml(data.password || '')}"
                           style="width: 100%; padding: 8px 40px 8px 8px; border: 1px solid #8c8f94; border-radius: 4px;" />
                    <button type="button" id="toggle-password-btn" title="Show/Hide Password"
                            style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 18px;">👁️</button>
                </div>

                ${data.additional_notes ? `
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #666;">Notes (for reference only):</label>
                    <textarea readonly style="width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px; background: #fffbf0; min-height: 80px; color: #666;">${this.escapeHtml(data.additional_notes)}</textarea>
                ` : ''}

                <div style="background: #f0f6fc; border-left: 3px solid #0969da; padding: 12px; margin-bottom: 20px; border-radius: 4px;">
                    ℹ️ <strong>Important:</strong> No data is sent anywhere. This only triggers your browser's password manager.
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="cancel-export-btn" class="button" style="padding: 8px 16px;">Cancel</button>
                    <button type="submit" class="button button-primary" style="padding: 10px 20px; font-weight: 600;">Save to Password Manager</button>
                </div>
            `;
        }

        /**
         * Bind form events
         */
        bindFormEvents(form) {
            const copyBtn = form.querySelector('#copy-url-btn');
            const toggleBtn = form.querySelector('#toggle-password-btn');
            const passwordField = form.querySelector('#seculoco-export-password');
            const urlField = form.querySelector('#seculoco-export-url');

            // Copy URL button
            copyBtn.addEventListener('click', () => {
                urlField.select();
                navigator.clipboard.writeText(urlField.value).then(() => {
                    copyBtn.textContent = '✓ Copied!';
                    copyBtn.style.background = '#46b450';
                    copyBtn.style.color = 'white';
                    setTimeout(() => {
                        copyBtn.textContent = '📋 Copy';
                        copyBtn.style.background = '';
                        copyBtn.style.color = '';
                    }, 2000);
                });
            });

            // Toggle password visibility
            toggleBtn.addEventListener('click', () => {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    toggleBtn.innerHTML = '🙈';
                } else {
                    passwordField.type = 'password';
                    toggleBtn.innerHTML = '👁️';
                }
            });
        }

        /**
         * Create backdrop
         */
        createBackdrop() {
            const backdrop = document.createElement('div');
            backdrop.id = 'seculoco-export-backdrop';
            backdrop.style.cssText = `
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5); z-index: 999998;
            `;
            return backdrop;
        }

        /**
         * Wait for form submission or cancellation
         */
        waitForFormSubmission(form, backdrop) {
            return new Promise((resolve, reject) => {
                const cleanup = () => {
                    if (form.parentNode) form.remove();
                    if (backdrop.parentNode) backdrop.remove();
                };

                const cancelBtn = form.querySelector('#cancel-export-btn');

                cancelBtn.addEventListener('click', () => {
                    cleanup();
                    reject(new Error('User cancelled'));
                });

                backdrop.addEventListener('click', () => {
                    cleanup();
                    reject(new Error('User cancelled'));
                });

                form.addEventListener('submit', (e) => {
                    e.preventDefault();

                    // Update action URL
                    const urlField = form.querySelector('#seculoco-export-url');
                    let finalUrl = urlField.value.trim();
                    if (finalUrl && !finalUrl.match(/^https?:\/\//)) {
                        finalUrl = 'https://' + finalUrl;
                    }
                    form.action = finalUrl;

                    cleanup();
                    setTimeout(() => resolve(), 100);
                });

                form.querySelector('button[type="submit"]').focus();
            });
        }

        /**
         * Show notification
         */
        showNotification(message, type = 'info') {
            $('.seculoco-notification').remove();

            const $notification = $(`
                <div class="seculoco-notification seculoco-notification-${type}">
                    ${this.escapeHtml(message)}
                </div>
            `);

            $('body').append($notification);

            setTimeout(() => {
                $notification.fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        }

        /**
         * Utility: Base64 to ArrayBuffer
         */
        base64ToArrayBuffer(base64) {
            const binary = atob(base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return bytes.buffer;
        }

        /**
         * Utility: Escape HTML
         */
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

    // Initialize plugin when document ready
    $(document).ready(() => {
        if ($('.secure-login-admin-table').length > 0) {
            if (window.seculocoDecrypt) {
                window.seculocoDecryptPro = new ProDecryptionPlugin(window.seculocoDecrypt);
                console.log('PRO decryption plugin loaded successfully');
            } else {
                console.error('Base decryption framework not loaded! PRO features unavailable.');
            }
        }
    });

})(jQuery);
