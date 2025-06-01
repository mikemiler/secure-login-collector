/**
 * Secure Login Collector - Frontend Script with RSA Encryption
 * Now uses RSA encryption for all users (not just pro version)
 */

jQuery(document).ready(
    function ($) {
    
        // RSA encryption function using Web Crypto API
        async function encryptWithRSA(data, publicKeyPem)
        {
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
        async function importRSAKey(pemKey)
        {
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
    
        // Fallback XOR encryption for legacy support
        function encryptDataXOR(data, key)
        {
            let encrypted = '';
            let keyIndex = 0;
        
            for (let i = 0; i < data.length; i++) {
                const charCode = data.charCodeAt(i);
                const keyChar = key.charCodeAt(keyIndex % key.length);
                encrypted += String.fromCharCode(charCode ^ keyChar);
                keyIndex++;
            }
        
            return btoa(encrypted);
        }
    
        // Main encryption function - always tries RSA first
        async function encryptData(data)
        {
            // Always try RSA encryption first if public key is available
            if (secureLoginAjax.public_key) {
                try {
                    return await encryptWithRSA(data, secureLoginAjax.public_key);
                } catch (error) {
                    console.error('RSA encryption failed, falling back to XOR:', error);
                    // Fall back to XOR if RSA fails
                }
            }
        
            // Fallback to XOR encryption if RSA is not available or fails
            const hostname = window.location.hostname;
            const timestamp = Date.now().toString();
            const timestampSuffix = timestamp.slice(-6);
            const encryptionKey = hostname + timestampSuffix;
        
            return encryptDataXOR(data, encryptionKey);
        }
    
        // Form submission handler
        $('#secure-login-frontend-form').on(
            'submit', async function (e) {
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
                    const dataToEncrypt = JSON.stringify(
                        {
                            email: email,
                            name: userName,
                            service_name: serviceName,
                            login_data: loginData,
                            timestamp: new Date().toISOString()
                        }
                    );
            
                    // Encrypt the data
                    const encryptedData = await encryptData(dataToEncrypt);
            
                    // Determine encryption type used
                    const encryptionType = secureLoginAjax.public_key ? 'rsa' : 'xor';
            
                    // Prepare metadata
                    const metadata = {
                        email: email,
                        name: userName,
                        service_name: serviceName,
                        created_at: new Date().toISOString(),
                        encryption_type: encryptionType
                    };
            
                    // Add XOR-specific metadata if RSA wasn't used
                    if (encryptionType === 'xor') {
                        const hostname = window.location.hostname;
                        const timestamp = Date.now().toString();
                        const timestampSuffix = timestamp.slice(-6);
                
                        metadata.key_hostname = hostname;
                        metadata.key_timestamp_suffix = timestampSuffix;
                        metadata.encryption_key_hint = hostname + timestampSuffix;
                    }
            
                    // Submit to server
                    $.ajax(
                        {
                            url: secureLoginAjax.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'save_secure_login_data',
                                encrypted_data: encryptedData,
                                metadata: JSON.stringify(metadata),
                                nonce: secureLoginAjax.nonce
                            },
                            success: function (response) {
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
                            error: function () {
                                messageDiv.removeClass('success').addClass('error')
                                .text(secureLoginAjax.strings.network_error)
                                .show();
                            },
                            complete: function () {
                                submitBtn.prop('disabled', false).text(secureLoginAjax.strings.submit_securely);
                            }
                        }
                    );
            
                } catch (error) {
                    console.error('Encryption error:', error);
                    messageDiv.removeClass('success').addClass('error')
                    .text(secureLoginAjax.strings.encryption_error)
                    .show();
                    submitBtn.prop('disabled', false).text(secureLoginAjax.strings.submit_securely);
                }
            }
        );
    }
); 