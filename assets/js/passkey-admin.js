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
            this.loadPasskeys();
        },

        isWebAuthnSupported() {
            return window.PublicKeyCredential !== undefined &&
                   navigator.credentials !== undefined;
        },

        bindEvents() {
            $('#register-passkey-btn').on('click', () => this.registerPasskey());
            $(document).on('click', '.delete-passkey', (e) => this.deletePasskey(e));
            $('#test-passkey-btn').on('click', () => this.testAuthentication());
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
                // Check if this is the first passkey
                const passkeys = await this.getPasskeysList();
                const isFirstPasskey = !passkeys || passkeys.length === 0;

                if (isFirstPasskey) {
                    // First passkey - need to initialize MWK and RSA keys
                    await this.registerFirstPasskey(name);
                } else {
                    // Additional passkey - need to authenticate with existing passkey first
                    await this.registerAdditionalPasskey(name);
                }

                this.showSuccess(passkeyAdmin.strings.register_success);
                $('#passkey-name').val('');
                this.loadPasskeys();

            } catch (error) {
                console.error('Registration error:', error);
                this.showError(error.message || passkeyAdmin.strings.register_failed);
            } finally {
                this.showSpinner(false);
            }
        },

        async registerFirstPasskey(name) {
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
                throw new Error(initResponse.data || 'Failed to initialize encryption');
            }

            // Step 3: Complete passkey registration
            const completeResponse = await $.ajax({
                url: passkeyAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'passkey_complete_registration',
                    nonce: passkeyAdmin.nonce,
                    name: name,
                    credential_id: this.arrayBufferToBase64(credential.rawId),
                    public_key: this.arrayBufferToBase64(credential.response.publicKey),
                    client_data: this.arrayBufferToBase64(credential.response.clientDataJSON),
                    attestation: this.arrayBufferToBase64(credential.response.attestationObject)
                }
            });

            if (!completeResponse.success) {
                throw new Error(completeResponse.data || 'Failed to complete registration');
            }
        },

        async registerAdditionalPasskey(name) {
            // Step 1: Authenticate with existing passkey to get MWK
            this.showStatus('Please authenticate with an existing passkey to add a new one...');
            
            const mwk = await this.authenticateForMWK();
            if (!mwk) {
                throw new Error('Failed to authenticate with existing passkey');
            }

            // Step 2: Create new passkey credential
            const credential = await this.createPasskeyCredential();

            // Step 3: Complete registration with wrapped MWK
            const completeResponse = await $.ajax({
                url: passkeyAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'passkey_complete_registration',
                    nonce: passkeyAdmin.nonce,
                    name: name,
                    credential_id: this.arrayBufferToBase64(credential.rawId),
                    public_key: this.arrayBufferToBase64(credential.response.publicKey),
                    client_data: this.arrayBufferToBase64(credential.response.clientDataJSON),
                    attestation: this.arrayBufferToBase64(credential.response.attestationObject),
                    wrapped_mwk: mwk // Pass the MWK to wrap with new passkey
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

        async authenticateForMWK() {
            try {
                // Get list of existing passkeys
                const passkeys = await this.getPasskeysList();
                if (!passkeys || passkeys.length === 0) {
                    throw new Error('No existing passkeys found');
                }

                // Get authentication challenge
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

                // Allow user to authenticate with any existing passkey
                const allowCredentials = passkeys.map(pk => ({
                    id: this.base64ToArrayBuffer(pk.credential_id),
                    type: 'public-key'
                }));

                // Authenticate
                const assertion = await navigator.credentials.get({
                    publicKey: {
                        challenge: this.base64ToArrayBuffer(challengeResponse.data.challenge),
                        allowCredentials: allowCredentials,
                        timeout: challengeResponse.data.timeout,
                        userVerification: 'required'
                    }
                });

                if (!assertion) {
                    throw new Error('Authentication cancelled');
                }

                // Get MWK from server (unwrapped using the authenticated passkey)
                const mwkResponse = await $.ajax({
                    url: passkeyAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'passkey_unwrap_mwk',
                        nonce: passkeyAdmin.nonce,
                        credential_id: this.arrayBufferToBase64(assertion.rawId)
                    }
                });

                if (!mwkResponse.success) {
                    throw new Error('Failed to retrieve MWK');
                }

                // Return the MWK (it will be re-wrapped with the new passkey)
                return mwkResponse.data.mwk;

            } catch (error) {
                console.error('MWK authentication error:', error);
                return null;
            }
        },

        async getPasskeysList() {
            try {
                const response = await $.ajax({
                    url: passkeyAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'passkey_list',
                        nonce: passkeyAdmin.nonce
                    }
                });

                return response.success ? response.data : [];
            } catch (error) {
                console.error('Failed to get passkeys list:', error);
                return [];
            }
        },

        async deletePasskey(e) {
            const $button = $(e.currentTarget);
            const credentialId = $button.data('credential-id');

            if (!confirm(passkeyAdmin.strings.delete_confirm)) {
                return;
            }

            $button.prop('disabled', true);

            try {
                const response = await $.ajax({
                    url: passkeyAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'passkey_delete',
                        nonce: passkeyAdmin.nonce,
                        credential_id: credentialId
                    }
                });

                if (response.success) {
                    $(`tr[data-credential-id="${credentialId}"]`).fadeOut(() => {
                        $(this).remove();
                        this.loadPasskeys();
                    });
                    this.showSuccess(passkeyAdmin.strings.delete_success);
                } else {
                    throw new Error(response.data || 'Failed to delete passkey');
                }
            } catch (error) {
                console.error('Delete error:', error);
                this.showError(error.message || 'Failed to delete passkey');
            } finally {
                $button.prop('disabled', false);
            }
        },

        async testAuthentication() {
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
        },

        async loadPasskeys() {
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
                $tbody.html('<tr><td colspan="5">No passkeys registered yet.</td></tr>');
                return;
            }

            let html = '';
            passkeys.forEach(passkey => {
                html += `
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
            });
            
            $tbody.html(html);
        },

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
            $('#registration-status').html(
                `<div class="notice notice-success inline"><p>${message}</p></div>`
            );
        },

        showError(message) {
            $('#registration-status').html(
                `<div class="notice notice-error inline"><p>${message}</p></div>`
            );
        },

        showStatus(message) {
            $('#registration-status').html(
                `<div class="notice notice-info inline"><p>${message}</p></div>`
            );
        },

        clearStatus() {
            $('#registration-status').empty();
        }
    };

    $(document).ready(() => PasskeyAdmin.init());

})(jQuery);