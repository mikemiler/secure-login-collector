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
            this.countdownSeconds = 60;
            this.countdownInterval = null;
            this.clearTimer = null;
            this.init();
        }

        init() {
            // Bind decrypt buttons (both old and new class names)
            $(document).on('click', '.decrypt-btn, .decrypt-btn-v2', (e) => this.handleDecrypt(e));

            // Bind password manager export button (PRO feature)
            $(document).on('click', '.export-to-password-manager', (e) => this.handlePasswordManagerExport(e));
        }

        /**
         * Handle decrypt button click
         */
        async handleDecrypt(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            // Normalize ID to number for consistent storage/retrieval
            const entryId = parseInt($btn.data('id'), 10);

            console.log('Decrypt clicked for entry ID:', entryId, 'Type:', typeof entryId);

            // Check if already decrypted
            if (this.decryptedData.has(entryId)) {
                console.log('Entry already decrypted, displaying...');
                this.displayDecryptedData(entryId, $btn);
                return;
            }

            try {
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-unlock spin"></span>');


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
                console.log('Storing decrypted data for entry:', entryId, 'Type:', typeof entryId);
                this.decryptedData.set(entryId, decrypted);
                console.log('Decrypted data stored. Map now has:', Array.from(this.decryptedData.keys()));
                this.displayDecryptedData(entryId, $btn);

                $btn.html('<span class="dashicons dashicons-yes"></span>').addClass('success');

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
         */
        async getEncryptedData(entryId) {
            const response = await $.ajax({
                url: seculocoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_encrypted_entry',
                    id: entryId,
                    nonce: seculocoAdmin.nonce
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
         * Unwrap the private key using passkey authentication
         * @param {string} entryId - The entry ID
         * @param {string} keyType - The key type (pro or free)
         * @param {object} preAuthData - Optional pre-authenticated data {credentialId, userId, derivedKey}
         */
        async unwrapPrivateKey(entryId, keyType, preAuthData = null) {
            // Get wrapped key from server (pass entry ID to determine pro vs free key)
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
                url: seculocoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'passkey_get_challenge',
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
                url: seculocoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_current_user_id',
                    nonce: seculocoAdmin.nonce
                }
            });
            
            if (!userResponse.success) {
                throw new Error('Failed to get user ID for key derivation');
            }
            
            const userId = userResponse.data.user_id;
            
            // Match server-side key derivation: credential_id + user_id + salt
            // Note: We can't access wp_salt() from client, so we'll ask server to derive the key
            const keyResponse = await $.ajax({
                url: seculocoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'derive_passkey_unwrapping_key',
                    credential_id: credentialIdB64,
                    user_id: userId,
                    nonce: seculocoAdmin.nonce
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
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <strong>Decrypted Data</strong>
                                        <span class="auto-clear-warning">Auto-clears in <span id="decrypted-area-countdown">60</span> seconds</span>
                                    </div>
                                    <button type="button" class="button button-primary export-to-password-manager" data-id="${entryId}" title="Export to Password Manager" style="margin: 0;">
                                        <span class="dashicons dashicons-cloud-upload" style="margin-top: 3px;"></span> Export to Password Manager
                                    </button>
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

        stopAutoClear() {
            clearTimeout(this.clearTimer);
            clearInterval(this.countdownInterval);
            this.clearTimer = null;
            this.countdownInterval = null;
        }

        resetAutoClear() {
            this.stopAutoClear();
            this.startAutoClear();
        }

        clearAllDecryptedData() {
            // Stop all timers
            this.stopAutoClear();

            // Clear decrypted data from memory
            this.decryptedData.clear();
            this.unwrappedKeys = { pro: null, free: null };

            // Remove from UI and reset buttons
            $('.decrypted-row').remove();
            $('.decrypt-btn, .decrypt-btn-v2').each(function() {
                $(this).prop('disabled', false)
                    .html('<span class="dashicons dashicons-unlock"></span>')
                    .removeClass('success button-success');
            });

            console.log('Decrypted data auto-cleared from memory');
        }

        /**
         * Handle Password Manager Export (PRO Feature)
         * Implements Option 2: Same-Page Form Submission
         *
         * Creates a hidden form with credentials, submits it using History API
         * to trigger browser password manager without page reload.
         * Universal compatibility: Works in ALL browsers (Chrome, Firefox, Safari, Edge).
         */
        async handlePasswordManagerExport(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            // Normalize ID to number (same as decrypt method)
            const entryId = parseInt($btn.data('id'), 10);

            console.log('Export clicked for entry:', entryId, 'Type:', typeof entryId);
            console.log('Available decrypted data keys:', Array.from(this.decryptedData.keys()));
            console.log('Has this entry?', this.decryptedData.has(entryId));

            try {
                // Get decrypted data using normalized ID
                const decryptedData = this.decryptedData.get(entryId);

                if ( !decryptedData) {
                    console.error('No decrypted data found for entry:', entryId);
                    console.error('Map contents:', this.decryptedData);
                    alert('No decrypted data available. Please decrypt the data first.');
                    return;
                }

                // Get metadata from the table row
                const $tableRow = $(`#row-${entryId}`);
                const loginUrl = $tableRow.find('[data-field="login_url"]').text().trim();
                const name = $tableRow.find('[data-field="name"]').text().trim();

                // Trigger the form submission directly (no confirmation)
                await this.triggerPasswordManagerViaForm(decryptedData, loginUrl, name);

                // Show success message
                this.showNotification('✓ Password manager triggered! Check your browser for the save prompt.', 'success');

            } catch (error) {
                // Don't show error if user cancelled the modal
                if (error.message === 'User cancelled') {
                    console.log('User cancelled password manager export');
                    return;
                }
                console.error('Password manager export failed:', error);
                alert('Export failed: ' + error.message);
            }
        }

        /**
         * Trigger password manager save using same-page form submission
         * Option 2 from PASSWORD_MANAGER_TRIGGER_PLAN.md
         *
         * @param {Object} decryptedData - The decrypted login credentials
         * @param {string} loginUrl - The website URL
         * @param {string} name - Account name for identification
         */
        async triggerPasswordManagerViaForm(decryptedData, loginUrl, name) {
            console.log('Triggering password manager with data:', {
                username: decryptedData.username_email,
                hasPassword: !!decryptedData.password,
                loginUrl: loginUrl
            });

            // Ensure URL has protocol
            let actionUrl = loginUrl || window.location.href;
            if (actionUrl && !actionUrl.match(/^https?:\/\//)) {
                actionUrl = 'https://' + actionUrl;
            }

            // Remove any existing export form
            const existingForm = document.getElementById('seculoco-export-form');
            if (existingForm) {
                existingForm.remove();
            }

            // Create a VISIBLE form (password managers need to see it!)
            const form = document.createElement('form');
            form.id = 'seculoco-export-form';
            form.method = 'POST';
            form.action = '#';
            form.autocomplete = 'on';

            // Make form visible but styled nicely as an overlay
            form.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 999999;
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                min-width: 500px;
                max-width: 600px;
                max-height: 90vh;
                overflow-y: auto;
            `;

            // Add heading
            const heading = document.createElement('h3');
            heading.textContent = 'Export Login to Password Manager';
            heading.style.cssText = 'margin: 0 0 20px 0; color: #333;';
            form.appendChild(heading);

            // Add important notice box
            const noticeBox = document.createElement('div');
            noticeBox.style.cssText = 'background: #fff3cd; border: 1px solid #ffc107; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px;';
            noticeBox.innerHTML = `
                <div style="display: flex; align-items: flex-start; gap: 10px;">
                    <span style="font-size: 24px; line-height: 1;">⚠️</span>
                    <div style="flex: 1;">
                        <strong style="display: block; margin-bottom: 8px; color: #856404;">Important: URL Editing Required</strong>
                        <p style="margin: 0 0 10px 0; color: #856404; font-size: 13px; line-height: 1.5;">
                            Your password manager will pre-fill your website domain instead of the login URL.
                            <br />
                            <strong>Before saving:</strong> Copy the Login URL below and edit the pre-filled url before saving the login data to your password manager.
                        </p>
                    </div>
                </div>
            `;
            form.appendChild(noticeBox);

            // Add instructions
            const instructions = document.createElement('p');
            instructions.textContent = 'Review the details below and click "Submit". Your browser will ask if you want to save this password.';
            instructions.style.cssText = 'margin: 0 0 20px 0; color: #666; font-size: 14px;';
            form.appendChild(instructions);

            // Create Login URL field (EDITABLE) with copy button
            const urlLabel = document.createElement('label');
            urlLabel.textContent = 'Login URL (Copy this for later):';
            urlLabel.style.cssText = 'display: block; margin-bottom: 5px; font-weight: bold;';
            form.appendChild(urlLabel);

            // Container for URL field and copy button
            const urlContainer = document.createElement('div');
            urlContainer.style.cssText = 'display: flex; gap: 8px; margin-bottom: 5px;';

            const urlField = document.createElement('input');
            urlField.type = 'text';
            urlField.id = 'seculoco-export-url';
            urlField.value = actionUrl;
            urlField.style.cssText = 'flex: 1; padding: 8px; border: 1px solid #2271b1; border-radius: 4px; box-sizing: border-box; background: #f0f7ff; font-size: 14px;';
            urlField.placeholder = 'https://example.com/login';
            urlContainer.appendChild(urlField);

            // Add copy button
            const copyUrlButton = document.createElement('button');
            copyUrlButton.type = 'button';
            copyUrlButton.textContent = '📋 Copy';
            copyUrlButton.className = 'button';
            copyUrlButton.style.cssText = 'padding: 8px 16px; white-space: nowrap;';
            copyUrlButton.addEventListener('click', () => {
                urlField.select();
                navigator.clipboard.writeText(urlField.value).then(() => {
                    const originalText = copyUrlButton.textContent;
                    copyUrlButton.textContent = '✓ Copied!';
                    copyUrlButton.style.background = '#46b450';
                    copyUrlButton.style.color = 'white';
                    setTimeout(() => {
                        copyUrlButton.textContent = originalText;
                        copyUrlButton.style.background = '';
                        copyUrlButton.style.color = '';
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    alert('Failed to copy. Please manually select and copy the URL.');
                });
            });
            urlContainer.appendChild(copyUrlButton);

            form.appendChild(urlContainer);

            const urlHint = document.createElement('small');
            urlHint.innerHTML = '⚠️ <strong>Remember to copy this URL!</strong> You\'ll need it to edit the password manager entry before saving.';
            urlHint.style.cssText = 'display: block; margin: 0 0 15px 0; color: #d63638; font-size: 12px; font-weight: 500;';
            form.appendChild(urlHint);

            // Create username field with proper autocomplete attribute
            const usernameLabel = document.createElement('label');
            usernameLabel.textContent = 'Username:';
            usernameLabel.style.cssText = 'display: block; margin-bottom: 5px; font-weight: bold;';
            form.appendChild(usernameLabel);

            const usernameField = document.createElement('input');
            usernameField.type = 'text';
            usernameField.name = 'username';
            usernameField.id = 'seculoco-export-username';
            usernameField.autocomplete = 'username';
            usernameField.value = decryptedData.username_email || '';
            usernameField.style.cssText = 'width: 100%; padding: 8px; margin-bottom: 5px; border: 1px solid #8c8f94; border-radius: 4px; box-sizing: border-box; background: #fff;';
            form.appendChild(usernameField);

            const usernameHint = document.createElement('small');
            usernameHint.textContent = 'You can edit this field if needed before saving.';
            usernameHint.style.cssText = 'display: block; margin: 0 0 15px 0; color: #666; font-size: 12px; font-style: italic;';
            form.appendChild(usernameHint);

            // Create password field with proper autocomplete attribute and visibility toggle
            const passwordLabel = document.createElement('label');
            passwordLabel.textContent = 'Password:';
            passwordLabel.style.cssText = 'display: block; margin-bottom: 5px; font-weight: bold;';
            form.appendChild(passwordLabel);

            // Container for password field and toggle button
            const passwordContainer = document.createElement('div');
            passwordContainer.style.cssText = 'position: relative; margin-bottom: 5px;';

            const passwordField = document.createElement('input');
            passwordField.type = 'password';
            passwordField.name = 'password';
            passwordField.id = 'seculoco-export-password';
            passwordField.autocomplete = 'current-password';
            passwordField.value = decryptedData.password || '';
            passwordField.style.cssText = 'width: 100%; padding: 8px 40px 8px 8px; border: 1px solid #8c8f94; border-radius: 4px; box-sizing: border-box; background: #fff;';
            passwordContainer.appendChild(passwordField);

            // Add password visibility toggle button
            const togglePasswordBtn = document.createElement('button');
            togglePasswordBtn.type = 'button';
            togglePasswordBtn.innerHTML = '👁️';
            togglePasswordBtn.title = 'Show/Hide Password';
            togglePasswordBtn.style.cssText = 'position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 18px; padding: 4px; opacity: 0.6;';
            togglePasswordBtn.addEventListener('click', () => {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    togglePasswordBtn.innerHTML = '🙈';
                    togglePasswordBtn.style.opacity = '1';
                } else {
                    passwordField.type = 'password';
                    togglePasswordBtn.innerHTML = '👁️';
                    togglePasswordBtn.style.opacity = '0.6';
                }
            });
            togglePasswordBtn.addEventListener('mouseenter', () => {
                togglePasswordBtn.style.opacity = '1';
            });
            togglePasswordBtn.addEventListener('mouseleave', () => {
                if (passwordField.type === 'password') {
                    togglePasswordBtn.style.opacity = '0.6';
                }
            });
            passwordContainer.appendChild(togglePasswordBtn);

            form.appendChild(passwordContainer);

            const passwordHint = document.createElement('small');
            passwordHint.textContent = 'You can edit this field if needed before saving. Click the eye icon to show/hide.';
            passwordHint.style.cssText = 'display: block; margin: 0 0 15px 0; color: #666; font-size: 12px; font-style: italic;';
            form.appendChild(passwordHint);

            // Add notes field (READ-ONLY, for reference, NOT submitted)
            if (decryptedData.additional_notes) {
                const notesLabel = document.createElement('label');
                notesLabel.textContent = 'Notes (for reference only):';
                notesLabel.style.cssText = 'display: block; margin-bottom: 5px; font-weight: bold; color: #666;';
                form.appendChild(notesLabel);

                const notesField = document.createElement('textarea');
                notesField.value = decryptedData.additional_notes;
                notesField.readOnly = true;
                notesField.style.cssText = 'width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; background: #fffbf0; min-height: 80px; font-size: 13px; color: #666;';
                form.appendChild(notesField);

                const notesHint = document.createElement('small');
                notesHint.textContent = 'These notes are for your reference and will NOT be sent to the password manager.';
                notesHint.style.cssText = 'display: block; margin: -10px 0 10px 0; color: #996600; font-size: 12px; font-style: italic;';
                form.appendChild(notesHint);

                // Add pro-tip for multiple logins
                const proTip = document.createElement('div');
                proTip.style.cssText = 'background: #e7f3ff; border-left: 3px solid #2271b1; padding: 12px; margin-bottom: 15px; border-radius: 4px;';
                proTip.innerHTML = `
                    <div style="display: flex; align-items: flex-start; gap: 8px;">
                        <span style="font-size: 16px; line-height: 1;">💡</span>
                        <div style="flex: 1;">
                            <strong style="color: #2271b1; font-size: 13px;">Pro Tip: Multiple Logins</strong>
                            <p style="margin: 5px 0 0 0; color: #135e96; font-size: 12px; line-height: 1.5;">
                                If your client sent multiple logins in the notes, you can copy each login from the notes and replace the username/password fields above.
                                Then save to your password manager. Repeat this process for each login to save them all one-by-one.
                            </p>
                        </div>
                    </div>
                `;
                form.appendChild(proTip);
            }

            // Add "no data sent" disclaimer
            const disclaimer = document.createElement('div');
            disclaimer.style.cssText = 'background: #f0f6fc; border-left: 3px solid #0969da; padding: 12px; margin-bottom: 20px; border-radius: 4px;';
            disclaimer.innerHTML = `
                <div style="display: flex; align-items: flex-start; gap: 8px;">
                    <span style="font-size: 16px; line-height: 1;">ℹ️</span>
                    <div style="flex: 1;">
                        <strong style="color: #0969da; font-size: 13px;">Important Note</strong>
                        <p style="margin: 5px 0 0 0; color: #135e96; font-size: 12px; line-height: 1.5;">
                            No data will actually be sent anywhere. This form only triggers your browser's password manager to save the credentials locally on your device.
                        </p>
                    </div>
                </div>
            `;
            form.appendChild(disclaimer);

            // Create buttons container
            const buttonsDiv = document.createElement('div');
            buttonsDiv.style.cssText = 'display: flex; gap: 10px; justify-content: flex-end;';

            // Create cancel button
            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.textContent = 'Cancel';
            cancelButton.className = 'button';
            cancelButton.style.cssText = 'padding: 8px 16px;';
            buttonsDiv.appendChild(cancelButton);

            // Create submit button
            const submitButton = document.createElement('button');
            submitButton.type = 'submit';
            submitButton.textContent = 'Save to Password Manager';
            submitButton.className = 'button button-primary';
            submitButton.style.cssText = 'padding: 10px 20px; font-weight: 600;';
            buttonsDiv.appendChild(submitButton);

            form.appendChild(buttonsDiv);

            // Create backdrop
            const backdrop = document.createElement('div');
            backdrop.id = 'seculoco-export-backdrop';
            backdrop.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999998;
            `;

            // Add to document
            document.body.appendChild(backdrop);
            document.body.appendChild(form);

            // Return promise that resolves after form submission
            return new Promise((resolve, reject) => {
                // Handle cancel
                const cleanup = () => {
                    if (form.parentNode) document.body.removeChild(form);
                    if (backdrop.parentNode) document.body.removeChild(backdrop);
                };

                cancelButton.addEventListener('click', () => {
                    cleanup();
                    reject(new Error('User cancelled'));
                });

                backdrop.addEventListener('click', () => {
                    cleanup();
                    reject(new Error('User cancelled'));
                });

                // Handle form submission
                form.addEventListener('submit', (e) => {
                    e.preventDefault(); // Prevent actual navigation

                    // Update form action with the potentially edited URL
                    const editedUrl = urlField.value.trim();
                    if (editedUrl) {
                        let finalUrl = editedUrl;
                        if (!finalUrl.match(/^https?:\/\//)) {
                            finalUrl = 'https://' + finalUrl;
                        }
                        form.action = finalUrl;
                        console.log('Form submitted with URL:', finalUrl);
                    }

                    console.log('Form submitted - password manager should detect this', {
                        action: form.action,
                        username: usernameField.value,
                        hasPassword: !!passwordField.value
                    });

                    // Close the modal immediately
                    cleanup();

                    // Small delay to let password manager capture the submission
                    setTimeout(() => {
                        resolve();
                    }, 100);
                });

                // Focus the submit button
                submitButton.focus();
            });
        }

        /**
         * Show notification message
         */
        showNotification(message, type = 'info') {
            // Remove any existing notifications
            $('.seculoco-notification').remove();

            // Create notification element
            const $notification = $(`
                <div class="seculoco-notification seculoco-notification-${type}">
                    ${this.escapeHtml(message)}
                </div>
            `);

            // Add to page
            $('body').append($notification);

            // Auto-remove after 5 seconds
            setTimeout(() => {
                $notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
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