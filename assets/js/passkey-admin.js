/**
 * Passkey Admin Management JavaScript
 */
(function($) {
    'use strict';

    const PasskeyAdmin = {
        init() {
            if (!this.isWebAuthnSupported()) {
                this.showError(passkeyAdmin.strings.browser_not_supported);
                return;
            }

            this.bindEvents();
            // loadPasskeys removed - using server-side rendering
        },

        isWebAuthnSupported() {
            return window.PublicKeyCredential !== undefined &&
                   navigator.credentials !== undefined;
        },

        bindEvents() {
            $('#register-passkey-btn').on('click', () => this.registerPasskey());
            $('#delete-passkey-btn').on('click', () => this.deletePasskey());
            
            // Debug - check if delete button exists
            if ($('#delete-passkey-btn').length > 0) {
                console.log('Delete passkey button found and event bound');
            } else {
                console.log('Delete passkey button not found on page');
            }
        },

        async registerPasskey() {
            const name = $('#passkey-name').val().trim();
            if (!name) {
                this.showError('Please enter a name for the passkey');
                return;
            }

            this.showSpinner(true);
            this.clearStatus();

            try {
                // Check if a passkey already exists
                const status = await this.getPasskeyStatus();
                if (status.has_passkey) {
                    this.showError('A passkey is already registered. Please delete it first to register a new one.');
                    return;
                }

                // Register the single passkey
                await this.registerSinglePasskey(name);

                this.showSuccess(passkeyAdmin.strings.register_success);
                $('#passkey-name').val('');
                // Reload page after successful registration
                setTimeout(() => {
                    window.location.reload();
                }, 1500);

            } catch (error) {
                console.error('Registration error:', error);
                this.showError(error.message || passkeyAdmin.strings.register_failed);
            } finally {
                this.showSpinner(false);
            }
        },

        async registerSinglePasskey(name) {
            // Step 1: Create the passkey credential
            const credential = await this.createPasskeyCredential();
            
            // Step 2: Initialize the zero-knowledge setup (RSA keys + MWK)
            const initResponse = await $.ajax({
                url: passkeyAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'passkey_init_setup',
                    nonce: passkeyAdmin.nonce,
                    credential_id: this.arrayBufferToBase64(credential.rawId)
                }
            });

            if (!initResponse.success) {
                // Check if it's an "already initialized" error which might be from old keys
                if (typeof initResponse.data === 'string' && initResponse.data.includes('Already initialized')) {
                    console.warn('Keys already initialized, continuing with registration');
                } else {
                    throw new Error(initResponse.data || 'Failed to initialize encryption');
                }
            }
            
            // Check for already initialized or migrated status (non-error case)
            if (initResponse.data && initResponse.data.status) {
                if (initResponse.data.status === 'already_initialized') {
                    console.log('Using existing encryption keys');
                } else if (initResponse.data.status === 'migrated') {
                    console.log('Successfully migrated existing keys to passkey encryption');
                }
            }

            // Step 3: Complete passkey registration
            // Note: publicKey might be getPublicKey() in some browsers
            let publicKeyData;
            if (credential.response.publicKey) {
                publicKeyData = this.arrayBufferToBase64(credential.response.publicKey);
            } else if (credential.response.getPublicKey) {
                publicKeyData = this.arrayBufferToBase64(credential.response.getPublicKey());
            } else {
                // Fallback - not available in all browsers
                publicKeyData = 'not_available';
            }
            
            const completeResponse = await $.ajax({
                url: passkeyAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'passkey_complete_registration',
                    nonce: passkeyAdmin.nonce,
                    name: name,
                    credential_id: this.arrayBufferToBase64(credential.rawId),
                    public_key: publicKeyData,
                    client_data: this.arrayBufferToBase64(credential.response.clientDataJSON),
                    attestation: this.arrayBufferToBase64(credential.response.attestationObject)
                }
            });

            if (!completeResponse.success) {
                throw new Error(completeResponse.data || 'Failed to complete registration');
            }
        },


        async createPasskeyCredential() {
            // Start registration to get challenge
            const startResponse = await $.ajax({
                url: passkeyAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'passkey_start_registration',
                    nonce: passkeyAdmin.nonce
                }
            });

            if (!startResponse.success) {
                throw new Error(startResponse.data || 'Failed to start registration');
            }

            const options = startResponse.data;

            // Convert base64 strings to ArrayBuffers
            options.challenge = this.base64ToArrayBuffer(options.challenge);
            options.user.id = this.base64ToArrayBuffer(options.user.id);

            // Create credential
            return await navigator.credentials.create({
                publicKey: options
            });
        },

        async getPasskeyStatus() {
            try {
                const response = await $.ajax({
                    url: passkeyAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'passkey_get_status',
                        nonce: passkeyAdmin.nonce
                    }
                });

                return response.success ? response.data : { has_passkey: false, passkey: null };
            } catch (error) {
                console.error('Failed to get passkey status:', error);
                return { has_passkey: false, passkey: null };
            }
        },

        async deletePasskey() {
            console.log('Delete passkey triggered');
            const $button = $('#delete-passkey-btn');
            const credentialId = $button.data('credential-id');
            
            console.log('Credential ID to delete:', credentialId);

            if (!credentialId) {
                console.error('No credential ID found');
                this.showError('No credential ID found');
                return;
            }

            if (!confirm(passkeyAdmin.strings.delete_confirm)) {
                return;
            }

            $button.prop('disabled', true).text('Deleting...');

            try {
                console.log('Sending delete request with:', {
                    action: 'passkey_delete',
                    nonce: passkeyAdmin.nonce,
                    credential_id: credentialId
                });
                
                const response = await $.ajax({
                    url: passkeyAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'passkey_delete',
                        nonce: passkeyAdmin.nonce,
                        credential_id: credentialId
                    }
                });

                console.log('Delete response:', response);

                if (response.success) {
                    this.showSuccess(passkeyAdmin.strings.delete_success);
                    // Reload page after successful deletion
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    throw new Error(response.data || 'Failed to delete passkey');
                }
            } catch (error) {
                console.error('Delete error details:', error);
                if (error.responseJSON) {
                    console.error('Server response:', error.responseJSON);
                    this.showError(error.responseJSON.data || 'Failed to delete passkey');
                } else {
                    this.showError(error.message || 'Failed to delete passkey');
                }
                $button.prop('disabled', false).text('Delete Passkey');
            }
        },

        /* Removed test authentication - no longer needed */
        /*async testAuthentication() {
            const $button = $('#test-passkey-btn');
            const $result = $('#test-result');
            
            $button.prop('disabled', true);
            $result.html('<span class="spinner is-active"></span> Testing authentication...');

            try {
                // Get challenge
                const challengeResponse = await $.ajax({
                    url: passkeyAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'passkey_test_auth',
                        nonce: passkeyAdmin.nonce
                    }
                });

                if (!challengeResponse.success) {
                    throw new Error('Failed to get challenge');
                }

                // Authenticate with passkey
                const assertion = await navigator.credentials.get({
                    publicKey: {
                        challenge: this.base64ToArrayBuffer(challengeResponse.data.challenge),
                        timeout: challengeResponse.data.timeout,
                        userVerification: 'required'
                    }
                });

                // For testing purposes, we just verify we got an assertion
                if (assertion) {
                    $result.html('<div class="notice notice-success inline"><p>' + 
                               passkeyAdmin.strings.test_success + '</p></div>');
                } else {
                    throw new Error('No assertion received');
                }

            } catch (error) {
                console.error('Test error:', error);
                $result.html('<div class="notice notice-error inline"><p>' + 
                           (error.message || passkeyAdmin.strings.test_failed) + '</p></div>');
            } finally {
                $button.prop('disabled', false);
            }
        },*/

        /* Removed loadPasskeys - using page reload instead */
        /*async loadPasskeys() {
            try {
                const response = await $.ajax({
                    url: passkeyAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'passkey_list',
                        nonce: passkeyAdmin.nonce
                    }
                });

                if (response.success) {
                    this.renderPasskeyList(response.data);
                }
            } catch (error) {
                console.error('Load error:', error);
            }
        },

        renderPasskeyList(passkeys) {
            const $tbody = $('#passkey-list');
            
            if (!passkeys || passkeys.length === 0) {
                $tbody.html('<tr><td colspan="5">No passkey registered yet.</td></tr>');
                return;
            }

            // Since we only support single passkey, just render the first one
            const passkey = passkeys[0];
            const html = `
                <tr data-credential-id="${this.escapeHtml(passkey.credential_id)}">
                    <td>${this.escapeHtml(passkey.name)}</td>
                    <td><code>${this.escapeHtml(passkey.credential_id.substring(0, 20))}...</code></td>
                    <td>${this.escapeHtml(passkey.registered_at)}</td>
                    <td>${this.escapeHtml(passkey.last_used || 'Never')}</td>
                    <td>
                        <button class="button button-small delete-passkey" 
                                data-credential-id="${this.escapeHtml(passkey.credential_id)}">
                            Delete
                        </button>
                    </td>
                </tr>
            `;
            
            $tbody.html(html);
        },*/

        // Utility functions
        base64ToArrayBuffer(base64) {
            const binaryString = atob(base64);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        },

        arrayBufferToBase64(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.length; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary);
        },

        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        },

        showSpinner(show) {
            const $spinner = $('.passkey-registration-form .spinner');
            if (show) {
                $spinner.addClass('is-active');
            } else {
                $spinner.removeClass('is-active');
            }
        },

        showSuccess(message) {
            $('#passkey-status-message').html(
                `<div class="notice notice-success inline" style="margin-top: 10px;"><p>${message}</p></div>`
            );
        },

        showError(message) {
            $('#passkey-status-message').html(
                `<div class="notice notice-error inline" style="margin-top: 10px;"><p>${message}</p></div>`
            );
        },

        showStatus(message) {
            $('#passkey-status-message').html(
                `<div class="notice notice-info inline" style="margin-top: 10px;"><p>${message}</p></div>`
            );
        },

        clearStatus() {
            $('#passkey-status-message').empty();
        }
    };

    $(document).ready(() => PasskeyAdmin.init());

})(jQuery);