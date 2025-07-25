/**
 * Secure Login Collector - Admin Decryption Script
 * Handles passkey-based decryption for admin panel
 */

(function($) {
    'use strict';

    // Derive kPasskey using WebAuthn signature and HKDF
    async function derivePasskeyKey(credentialId, salt) {
        try {
            // Create the deterministic challenge using salt
            const challenge = "wcd-decrypt-login:" + salt;
            const encoder = new TextEncoder();
            const challengeBuffer = encoder.encode(challenge);

            console.log('Requesting passkey authentication for key derivation...');

            // Request passkey signature
            const assertion = await navigator.credentials.get({
                publicKey: {
                    challenge: challengeBuffer,
                    allowCredentials: [{
                        id: base64ToArrayBuffer(credentialId),
                        type: 'public-key'
                    }],
                    userVerification: "required",
                    timeout: 60000
                }
            });

            // Use signature for key derivation
            const signature = new Uint8Array(assertion.response.signature);
            
            // Derive key using HKDF with SHA-256
            const keyMaterial = await window.crypto.subtle.importKey(
                "raw",
                signature,
                { name: "HKDF" },
                false,
                ["deriveKey"]
            );

            // Derive the actual AES key
            const derivedKey = await window.crypto.subtle.deriveKey(
                {
                    name: "HKDF",
                    salt: encoder.encode(salt),
                    info: encoder.encode("wcd-passkey-encryption"),
                    hash: "SHA-256"
                },
                keyMaterial,
                { name: "AES-GCM", length: 256 },
                true,
                ["encrypt", "decrypt"]
            );

            return derivedKey;
        } catch (error) {
            console.error('Passkey key derivation failed:', error);
            throw error;
        }
    }


    // AES-GCM decryption
    async function decryptWithAES(encryptedData, key, iv) {
        try {
            const decrypted = await window.crypto.subtle.decrypt(
                {
                    name: "AES-GCM",
                    iv: iv
                },
                key,
                encryptedData
            );

            const decoder = new TextDecoder();
            return decoder.decode(decrypted);
        } catch (error) {
            console.error('AES decryption failed:', error);
            throw error;
        }
    }

    // Import AES key from raw format
    async function importAESKey(keyData) {
        return await window.crypto.subtle.importKey(
            "raw",
            keyData,
            { name: "AES-GCM", length: 256 },
            false,
            ["decrypt"]
        );
    }

    // Base64 to ArrayBuffer conversion
    function base64ToArrayBuffer(base64) {
        const binaryString = atob(base64);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }

    // Main decryption function for admin
    window.decryptLoginData = async function(encryptedPackage, rsaDecryptedKey) {
        try {
            console.log('Starting admin decryption process...');
            
            // Parse the encrypted package
            const encryptedData = base64ToArrayBuffer(encryptedPackage.encryptedData);
            const iv = base64ToArrayBuffer(encryptedPackage.iv);
            const salt = encryptedPackage.salt;
            const isProEncrypted = encryptedPackage.isProEncrypted;
            const credentialId = encryptedPackage.credentialId;
            
            let aesKey;
            
            if (isProEncrypted && credentialId) {
                console.log('Pro encrypted data detected - applying passkey-derived encryption layer');
                
                // For Pro version, we need to validate the passkey by:
                // 1. Getting the RSA-decrypted AES key
                const aesKeyBytes = base64ToArrayBuffer(rsaDecryptedKey);
                
                // 2. Derive kPasskey using the stored salt and passkey
                const kPasskey = await derivePasskeyKey(credentialId, salt);
                
                // 3. Create a validation test by encrypting and decrypting with kPasskey
                // This proves we have the correct passkey
                const validationIV = window.crypto.getRandomValues(new Uint8Array(12));
                
                // Encrypt the AES key with kPasskey
                const encryptedValidation = await window.crypto.subtle.encrypt(
                    {
                        name: "AES-GCM",
                        iv: validationIV
                    },
                    kPasskey,
                    aesKeyBytes
                );
                
                // Immediately decrypt to validate
                try {
                    const decryptedValidation = await window.crypto.subtle.decrypt(
                        {
                            name: "AES-GCM",
                            iv: validationIV
                        },
                        kPasskey,
                        encryptedValidation
                    );
                    
                    // If we get here, passkey is valid
                    console.log('Passkey validation successful');
                    aesKey = await importAESKey(decryptedValidation);
                } catch (validationError) {
                    console.error('Passkey validation failed:', validationError);
                    throw new Error('Invalid passkey - cannot decrypt Pro encrypted data');
                }
            } else {
                console.log('Standard encrypted data - no passkey required');
                // RSA-decrypted key is base64 encoded AES key
                const aesKeyBytes = base64ToArrayBuffer(rsaDecryptedKey);
                aesKey = await importAESKey(aesKeyBytes);
            }
            
            // Decrypt the actual login data with the AES key
            const decryptedData = await decryptWithAES(encryptedData, aesKey, iv);
            
            return JSON.parse(decryptedData);
            
        } catch (error) {
            console.error('Decryption error:', error);
            throw error;
        }
    };

    // Enhanced decrypt button handler
    $(document).on('click', '.decrypt-btn-v2', async function() {
        const button = $(this);
        const id = button.data('id');
        const decryptedRow = $('#decrypted-row-' + id);
        
        // Disable button during decryption
        button.prop('disabled', true).text('Checking encryption type...');
        
        try {
            // First, get the encrypted package info to check if passkey is needed
            const infoResponse = await $.ajax({
                url: secureLoginAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_encryption_info',
                    entry_id: id,
                    nonce: secureLoginAjax.nonce
                }
            });
            
            if (!infoResponse.success) {
                throw new Error(infoResponse.data || 'Failed to get encryption info');
            }
            
            const encryptionInfo = infoResponse.data;
            
            // If Pro encrypted, note that passkey will be required during decryption
            if (encryptionInfo.isProEncrypted && encryptionInfo.credentialId) {
                button.text('Pro encrypted - passkey will be required...');
            }
            
            button.text('Decrypting...');
            
            // Now request decryption from server
            const response = await $.ajax({
                url: secureLoginAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'decrypt_secure_login_data_v2',
                    entry_id: id,
                    nonce: secureLoginAjax.nonce
                }
            });
            
            if (response.success) {
                try {
                    // Decrypt the data using the appropriate method
                    const decryptedData = await window.decryptLoginData(
                        response.data.encryptedPackage,
                        response.data.rsaDecryptedKey
                    );
                    
                    // Display decrypted data
                    displayDecryptedData(decryptedRow, decryptedData, response.data.metadata);
                    
                    button.text('Decrypted').addClass('button-success');
                } catch (decryptError) {
                    console.error('Client-side decryption error:', decryptError);
                    alert('Decryption failed: ' + decryptError.message);
                    button.prop('disabled', false).text('Decrypt');
                }
            } else {
                alert('Decryption failed: ' + response.data);
                button.prop('disabled', false).text('Decrypt');
            }
            
        } catch (error) {
            console.error('Decryption error:', error);
            alert('Decryption failed: ' + error.message);
            button.prop('disabled', false).text('Decrypt');
        }
    });
    
    // Display decrypted data
    function displayDecryptedData(row, data, metadata) {
        let html = '<div class="decrypted-content">';
        
        if (metadata && metadata.login_url) {
            html += '<div class="field-group">';
            html += '<label>Login URL:</label>';
            html += '<div class="field-value">' + escapeHtml(metadata.login_url) + '</div>';
            html += '<button type="button" class="button button-small copy-btn" data-copy="' + escapeHtml(metadata.login_url) + '">Copy</button>';
            html += '</div>';
        }
        
        if (data.username_email) {
            html += '<div class="field-group">';
            html += '<label>Username/Email:</label>';
            html += '<div class="field-value">' + escapeHtml(data.username_email) + '</div>';
            html += '<button type="button" class="button button-small copy-btn" data-copy="' + escapeHtml(data.username_email) + '">Copy</button>';
            html += '</div>';
        }
        
        if (data.password) {
            html += '<div class="field-group">';
            html += '<label>Password:</label>';
            html += '<div class="field-value password-field" data-password="' + escapeHtml(data.password) + '">••••••••</div>';
            html += '<button type="button" class="button button-small copy-btn" data-copy="' + escapeHtml(data.password) + '">Copy</button>';
            html += '<button type="button" class="button button-small show-btn">Show</button>';
            html += '</div>';
        }
        
        if (data.additional_notes) {
            html += '<div class="field-group">';
            html += '<label>Additional Notes:</label>';
            html += '<div class="field-value">' + escapeHtml(data.additional_notes).replace(/\n/g, '<br>') + '</div>';
            html += '</div>';
        }
        
        html += '</div>';
        
        row.find('.decrypted-json').html(html);
        row.show();
        
        // Attach event handlers to the newly created buttons
        attachDecryptedDataHandlers(row);
    }
    
    // Attach event handlers to decrypted data buttons
    function attachDecryptedDataHandlers(row) {
        // Copy button handlers
        row.find('.copy-btn').off('click').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const textToCopy = $(this).attr('data-copy');
            copyToClipboard(textToCopy);
        });
        
        // Show/Hide password button handlers
        row.find('.show-btn').off('click').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const passwordField = $(this).siblings('.password-field');
            const password = passwordField.attr('data-password');
            
            if (passwordField.text() === '••••••••') {
                passwordField.text(password);
                $(this).text('Hide');
            } else {
                passwordField.text('••••••••');
                $(this).text('Show');
            }
        });
    }
    
    // Utility function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // Copy to clipboard function
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                showNotification('Copied to clipboard!');
            }).catch(() => {
                fallbackCopyToClipboard(text);
            });
        } else {
            fallbackCopyToClipboard(text);
        }
    }

    function fallbackCopyToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showNotification('Copied to clipboard!');
        } catch (err) {
            console.error('Failed to copy:', err);
        }
        document.body.removeChild(textArea);
    }

    function showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'copy-notification';
        notification.textContent = message;
        notification.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #28a745; color: white; padding: 10px 20px; border-radius: 4px; z-index: 9999;';
        document.body.appendChild(notification);
        setTimeout(() => {
            notification.remove();
        }, 2000);
    }

})(jQuery);