/**
 * Secure Login Collector - Frontend Script with AES-GCM + RSA + Passkey encryption
 * Implements the complete encryption flow as specified
 */

jQuery(document).ready(function ($) {

    // Password visibility toggle functionality
    $('.password-toggle-btn').on('click', function () {
        const $button = $(this);
        const $passwordField = $button.siblings('input[type="password"], input[type="text"]');
        const $icon = $button.find('.dashicons');

        if ($passwordField.attr('type') === 'password') {
            $passwordField.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            $button.attr('aria-label', secureLoginAjax.strings.hide_password || 'Hide password');
        } else {
            $passwordField.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            $button.attr('aria-label', secureLoginAjax.strings.show_password || 'Show password');
        }
    });

    // Generate random 256-bit AES key
    async function generateAESKey() {
        return await window.crypto.subtle.generateKey(
            {
                name: "AES-GCM",
                length: 256
            },
            true,
            ["encrypt", "decrypt"]
        );
    }

    // Export AES key to raw format
    async function exportAESKey(key) {
        return await window.crypto.subtle.exportKey("raw", key);
    }

    // Import AES key from raw format
    async function importAESKey(keyData) {
        return await window.crypto.subtle.importKey(
            "raw",
            keyData,
            { name: "AES-GCM", length: 256 },
            false,
            ["encrypt", "decrypt"]
        );
    }

    // AES-GCM encryption
    async function encryptWithAES(data, key) {
        const encoder = new TextEncoder();
        const dataBuffer = encoder.encode(data);
        
        // Generate random IV
        const iv = window.crypto.getRandomValues(new Uint8Array(12));
        
        // Encrypt
        const encrypted = await window.crypto.subtle.encrypt(
            {
                name: "AES-GCM",
                iv: iv
            },
            key,
            dataBuffer
        );
        
        return {
            encrypted: new Uint8Array(encrypted),
            iv: iv
        };
    }

    // RSA encryption function
    async function encryptWithRSA(data, publicKeyPem) {
        try {
            const publicKey = await importRSAKey(publicKeyPem);
            
            // Handle both ArrayBuffer and string inputs
            let dataBuffer;
            if (data instanceof ArrayBuffer) {
                dataBuffer = data;
            } else {
                const encoder = new TextEncoder();
                dataBuffer = encoder.encode(data);
            }

            const encrypted = await window.crypto.subtle.encrypt(
                { name: "RSA-OAEP" },
                publicKey,
                dataBuffer
            );

            return btoa(String.fromCharCode(...new Uint8Array(encrypted)));
        } catch (error) {
            console.error('RSA encryption failed:', error);
            throw new Error(secureLoginAjax.strings.encryption_failed || 'Encryption failed');
        }
    }

    // Import RSA public key from PEM format
    async function importRSAKey(pemKey) {
        const pemContents = pemKey
            .replace(/-----BEGIN PUBLIC KEY-----/, '')
            .replace(/-----END PUBLIC KEY-----/, '')
            .replace(/\s/g, '');

        const binaryString = atob(pemContents);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }

        return await window.crypto.subtle.importKey(
            'spki',
            bytes.buffer,
            {
                name: 'RSA-OAEP',
                hash: 'SHA-256'
            },
            false,
            ['encrypt']
        );
    }


    // Main encryption function - implements the complete flow
    async function encryptLoginData(loginData, rsaPublicKey, isPro) {
        try {
            console.log('Starting encryption process. Pro version:', isPro);
            
            // Step 1: Generate random AES key
            const aesKey = await generateAESKey();
            const rawAesKey = await exportAESKey(aesKey);
            
            // Step 2: Encrypt login data with AES-GCM
            const encryptedData = await encryptWithAES(JSON.stringify(loginData), aesKey);
            
            // Step 3: Generate random salt
            const salt = btoa(String.fromCharCode(...window.crypto.getRandomValues(new Uint8Array(32))));
            
            // Step 4: RSA encrypt the raw AES key directly
            // In Pro version, the passkey encryption happens on the admin side during decryption
            const rsaEncryptedKey = await encryptWithRSA(rawAesKey, rsaPublicKey);
            
            // Return the complete encrypted package
            return {
                encryptedData: btoa(String.fromCharCode(...encryptedData.encrypted)),
                rsaEncryptedKey: rsaEncryptedKey,
                iv: btoa(String.fromCharCode(...encryptedData.iv)),
                salt: salt,
                isProEncrypted: false, // Will be determined server-side
                credentialId: null // Clients don't have passkeys
            };
            
        } catch (error) {
            console.error('Encryption error:', error);
            throw error;
        }
    }

    // Form submission handler
    $('#secure-login-frontend-form').on('submit', async function (e) {
        e.preventDefault();

        const form = $(this);
        const submitBtn = form.find('.secure-submit-btn');
        const messageDiv = $('#form-message');

        // Get form data
        const email = $('#email').val().trim();
        const userName = $('#user_name').val().trim();
        const loginUrl = $('#login_url').val().trim();
        const usernameEmail = $('#username_email').val().trim();
        const password = $('#password').val().trim();
        const additionalNotes = $('#additional_notes').val().trim();

        // Validate required fields
        if (!email || !userName || !loginUrl || !usernameEmail || !password) {
            messageDiv.removeClass('success').addClass('error')
                .text(secureLoginAjax.strings.required_fields_error)
                .show();
            return;
        }

        // Disable submit button and show loading
        submitBtn.prop('disabled', true).text(secureLoginAjax.strings.submitting);
        messageDiv.hide();

        try {
            // Prepare login data
            const loginData = {
                username_email: usernameEmail,
                password: password,
                additional_notes: additionalNotes,
                timestamp: new Date().toISOString()
            };

            // Check if RSA public key is available
            if (!secureLoginAjax.public_key) {
                throw new Error(secureLoginAjax.strings.rsa_key_not_available);
            }

            // Encrypt the data
            const encryptedPackage = await encryptLoginData(
                loginData,
                secureLoginAjax.public_key,
                secureLoginAjax.is_pro
            );

            // Prepare submission data
            const submissionData = {
                encryptedData: encryptedPackage.encryptedData,
                rsaEncryptedKey: encryptedPackage.rsaEncryptedKey,
                iv: encryptedPackage.iv,
                salt: encryptedPackage.salt,
                isProEncrypted: encryptedPackage.isProEncrypted,
                credentialId: encryptedPackage.credentialId,
                metadata: {
                    email: email,
                    name: userName,
                    login_url: loginUrl,
                    created_at: new Date().toISOString()
                }
            };

            console.log('Submitting encrypted data. Pro encrypted:', submissionData.isProEncrypted);

            // Submit to server
            $.ajax({
                url: secureLoginAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'save_secure_login_data_v2',
                    submission: JSON.stringify(submissionData),
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
            });

        } catch (error) {
            console.error('Encryption error:', error);
            messageDiv.removeClass('success').addClass('error')
                .text(secureLoginAjax.strings.encryption_error + ': ' + error.message)
                .show();
            submitBtn.prop('disabled', false).text(secureLoginAjax.strings.submit_securely);
        }
    });
});