/**
 * Secure Login Collector - Ultra-Secure Frontend Script
 * Uses passkey-derived encryption for maximum security
 */

jQuery(document).ready(function($) {
    
    // Passkey-derived encryption function
    async function encryptWithPasskeyKey(data) {
        try {
            // First, authenticate with passkey to get signature
            const challenge = new Uint8Array(32);
            window.crypto.getRandomValues(challenge);
            
            const getCredentialArgs = {
                publicKey: {
                    timeout: 60000,
                    challenge: challenge,
                    userVerification: "required"
                }
            };
            
            const assertion = await navigator.credentials.get(getCredentialArgs);
            
            // Extract signature for key derivation
            const signature = new Uint8Array(assertion.response.signature);
            const signatureBase64 = btoa(String.fromCharCode(...signature));
            
            // Send data and signature to server for encryption
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: secureLoginAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'encrypt_with_passkey',
                        data: data,
                        signature: signatureBase64,
                        nonce: secureLoginAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            resolve(response.data.encrypted_data);
                        } else {
                            reject(new Error(response.data || 'Encryption failed'));
                        }
                    },
                    error: function() {
                        reject(new Error('Network error during encryption'));
                    }
                });
            });
            
        } catch (error) {
            console.error('Passkey encryption failed:', error);
            throw new Error('Passkey encryption failed: ' + error.message);
        }
    }
    
    // Fallback RSA encryption function
    async function encryptWithRSA(data, publicKeyPem) {
        try {
            // Convert PEM to ArrayBuffer
            const publicKey = await importRSAKey(publicKeyPem);
            
            // Convert data to ArrayBuffer
            const encoder = new TextEncoder();
            const dataBuffer = encoder.encode(data);
            
            // Encrypt with RSA-OAEP
            const encrypted = await window.crypto.subtle.encrypt(
                {
                    name: "RSA-OAEP"
                },
                publicKey,
                dataBuffer
            );
            
            // Convert to base64
            return btoa(String.fromCharCode(...new Uint8Array(encrypted)));
            
        } catch (error) {
            console.error('RSA encryption failed:', error);
            throw new Error('Encryption failed');
        }
    }
    
    // Import RSA public key from PEM format
    async function importRSAKey(pemKey) {
        // Remove PEM headers and whitespace
        const pemContents = pemKey
            .replace(/-----BEGIN PUBLIC KEY-----/, '')
            .replace(/-----END PUBLIC KEY-----/, '')
            .replace(/\s/g, '');
        
        // Convert base64 to ArrayBuffer
        const binaryString = atob(pemContents);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        
        // Import the key with SHA-1 to match OpenSSL's OAEP default
        return await window.crypto.subtle.importKey(
            'spki',
            bytes.buffer,
            {
                name: 'RSA-OAEP',
                hash: 'SHA-1'
            },
            false,
            ['encrypt']
        );
    }
    
    // Main encryption function
    async function encryptData(data) {
        // Check if ultra-secure mode is enabled
        if (secureLoginAjax.ultra_secure_mode && secureLoginAjax.passkey_registered) {
            try {
                return await encryptWithPasskeyKey(data);
            } catch (error) {
                console.error('Passkey encryption failed, falling back to RSA:', error);
                // Fall back to RSA if passkey fails
            }
        }
        
        // Fallback to RSA encryption
        if (secureLoginAjax.public_key) {
            try {
                return await encryptWithRSA(data, secureLoginAjax.public_key);
            } catch (error) {
                console.error('RSA encryption failed:', error);
                throw new Error('All encryption methods failed');
            }
        }
        
        throw new Error('No encryption method available');
    }
    
    // Form submission handler
    $('#secure-login-frontend-form').on('submit', async function(e) {
        e.preventDefault();
        
        const form = $(this);
        const submitBtn = form.find('.secure-submit-btn');
        const messageDiv = $('#form-message');
        
        // Get form data
        const email = $('#email').val().trim();
        const userName = $('#user_name').val().trim();
        const serviceName = $('#service_name').val().trim();
        const loginData = $('#login_data').val().trim();
        
        // Validate required fields
        if (!email || !userName || !serviceName || !loginData) {
            messageDiv.removeClass('success').addClass('error')
                .text(secureLoginAjax.strings.required_fields_error)
                .show();
            return;
        }
        
        // Disable submit button and show loading
        submitBtn.prop('disabled', true).text(secureLoginAjax.strings.submitting);
        messageDiv.hide();
        
        try {
            // Prepare data for encryption
            const dataToEncrypt = JSON.stringify({
                email: email,
                name: userName,
                service_name: serviceName,
                login_data: loginData,
                timestamp: new Date().toISOString()
            });
            
            // Encrypt the data
            const encryptedData = await encryptData(dataToEncrypt);
            
            // Determine encryption type used
            let encryptionType = 'rsa'; // Default fallback
            if (secureLoginAjax.ultra_secure_mode && secureLoginAjax.passkey_registered) {
                encryptionType = 'passkey_derived';
            }
            
            // Prepare metadata
            const metadata = {
                email: email,
                name: userName,
                service_name: serviceName,
                created_at: new Date().toISOString(),
                encryption_type: encryptionType
            };
            
            // Submit to server
            $.ajax({
                url: secureLoginAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'save_secure_login_data',
                    encrypted_data: encryptedData,
                    metadata: JSON.stringify(metadata),
                    nonce: secureLoginAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        messageDiv.removeClass('error').addClass('success')
                            .text(secureLoginAjax.strings.success_message)
                            .show();
                        form[0].reset();
                    } else {
                        messageDiv.removeClass('success').addClass('error')
                            .text(secureLoginAjax.strings.error_prefix + (response.data || secureLoginAjax.strings.unknown_error))
                            .show();
                    }
                },
                error: function() {
                    messageDiv.removeClass('success').addClass('error')
                        .text(secureLoginAjax.strings.network_error)
                        .show();
                },
                complete: function() {
                    submitBtn.prop('disabled', false).text(secureLoginAjax.strings.submit_securely);
                }
            });
            
        } catch (error) {
            console.error('Encryption error:', error);
            messageDiv.removeClass('success').addClass('error')
                .text(secureLoginAjax.strings.encryption_error)
                .show();
            submitBtn.prop('disabled', false).text(secureLoginAjax.strings.submit_securely);
        }
    });
}); 