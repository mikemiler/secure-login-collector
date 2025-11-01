/**
 * Secure Login Collector - Frontend Script with AES-GCM + RSA + Passkey encryption
 * Implements the complete encryption flow as specified
 */

// Status Modal Class
class StatusModal {
    constructor() {
        this.modal = null;
        this.currentStep = 0;
        this.steps = [
            { text: 'Getting encryption key', subtext: 'Retrieving secure keys from server...' },
            { text: 'Encrypting your data', subtext: 'Using advanced AES-256 encryption...' },
            { text: 'Preparing secure package', subtext: 'Finalizing encrypted data...' },
            { text: 'Sending securely', subtext: 'Transmitting to secure server...' }
        ];
        this.createModal();
    }

    createModal() {
        const stepsHTML = this.steps.map((step, index) => `
            <div class="status-step pending" id="step-${index}">
                <div class="status-step-icon">${index + 1}</div>
                <div class="status-step-text">${step.text}</div>
            </div>
        `).join('');

        const modalHTML = `
            <div class="status-modal-overlay" id="statusModal">
                <div class="status-modal">
                    <div class="status-icon processing" id="statusIcon">
                        <div class="spinner"></div>
                    </div>
                    <div class="status-text" id="statusText">Processing your secure submission...</div>
                    <div class="status-checklist">
                        ${stepsHTML}
                    </div>
                    <div class="status-progress">
                        <div class="status-progress-bar" id="statusProgressBar"></div>
                    </div>
                    <button class="status-close-btn" id="statusCloseBtn" style="display: none;">Close</button>
                </div>
            </div>
        `;
        
        jQuery('body').append(modalHTML);
        this.modal = jQuery('#statusModal');
        
        // Close button handler
        jQuery('#statusCloseBtn').on('click', () => {
            this.hide();
        });
    }

    show() {
        this.currentStep = 0;
        this.resetModal();
        this.modal.addClass('show');
        // Start with first step as current
        this.setCurrentStep(0);
    }

    hide() {
        this.modal.removeClass('show');
        setTimeout(() => {
            // Completely remove the modal from DOM
            if (this.modal && this.modal.length) {
                this.modal.remove();
            }
            // Clear any remaining overlays to ensure no blocking elements
            jQuery('.status-modal-overlay').remove();
            this.modal = null;
        }, 300);
    }

    resetModal() {
        jQuery('#statusIcon').removeClass('success error').addClass('processing');
        jQuery('#statusIcon').html('<div class="spinner"></div>');
        jQuery('#statusProgressBar').css('width', '0%');
        jQuery('#statusCloseBtn').hide();
        
        // Reset all steps to pending
        jQuery('.status-step').removeClass('current completed').addClass('pending');
        jQuery('.status-step-icon').text(function(index) {
            return index + 1;
        });
    }

    setCurrentStep(stepIndex) {
        // Mark this step as current
        jQuery(`#step-${stepIndex}`).removeClass('pending completed').addClass('current');
        
        // Update progress
        const progress = ((stepIndex + 1) / this.steps.length) * 100;
        jQuery('#statusProgressBar').css('width', progress + '%');
    }

    async nextStep() {
        // Complete the current step if it exists
        if (this.currentStep < this.steps.length) {
            // Mark current step as completed with checkmark
            jQuery(`#step-${this.currentStep}`).removeClass('current').addClass('completed');
            jQuery(`#step-${this.currentStep} .status-step-icon`).text('✓');
            
            this.currentStep++;
            
            // Set next step as current if it exists
            if (this.currentStep < this.steps.length) {
                this.setCurrentStep(this.currentStep);
            }
            
            // Wait at least 1 second per step
            return new Promise(resolve => {
                setTimeout(resolve, 1000);
            });
        }
    }

    showSuccess(message = 'Data sent successfully!') {
        // Complete the final step
        if (this.currentStep < this.steps.length) {
            jQuery(`#step-${this.currentStep}`).removeClass('current').addClass('completed');
            jQuery(`#step-${this.currentStep} .status-step-icon`).text('✓');
        }
        
        // Ensure all steps are marked as completed
        jQuery('.status-step').removeClass('current pending').addClass('completed');
        jQuery('.status-step-icon').text('✓');
        
        jQuery('#statusIcon').removeClass('processing error').addClass('success');
        jQuery('#statusIcon').html('✓');
        jQuery('#statusText').text('Success!');
        jQuery('#statusProgressBar').css('width', '100%');
        jQuery('#statusCloseBtn').show();
    }

    showError(message = 'Something went wrong') {
        jQuery('#statusIcon').removeClass('processing success').addClass('error');
        jQuery('#statusIcon').html('✗');
        jQuery('#statusText').text('Error: ' + message);
        jQuery('#statusCloseBtn').show();
    }
}

jQuery(document).ready(function ($) {

    // Password visibility toggle functionality
    $('.password-toggle-btn').on('click', function () {
        const $button = $(this);
        const $passwordField = $button.siblings('input[type="password"], input[type="text"]');
        const $icon = $button.find('.dashicons');

        if ($passwordField.attr('type') === 'password') {
            $passwordField.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            $button.attr('aria-label', seculocoAjax.strings.hide_password || 'Hide password');
        } else {
            $passwordField.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            $button.attr('aria-label', seculocoAjax.strings.show_password || 'Show password');
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
            throw new Error(seculocoAjax.strings.encryption_failed || 'Encryption failed');
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

        // Basic validation - only check for required fields
        if (!email || !userName || !loginUrl || !usernameEmail || !password) {
            messageDiv.removeClass('success').addClass('error')
                .text(seculocoAjax.strings.required_fields_error)
                .show();
            return;
        }

        // Disable submit button and show loading
        submitBtn.prop('disabled', true).text(seculocoAjax.strings.submitting);
        messageDiv.hide();

        // Initialize and show status modal
        const statusModal = new StatusModal();
        statusModal.show();
        
        // Ensure old message is hidden
        messageDiv.hide();

        try {
            // Step 1: Getting encryption key (wait for animation)
            await statusModal.nextStep();

            // Check if RSA public key is available
            if (!seculocoAjax.public_key) {
                throw new Error(seculocoAjax.strings.rsa_key_not_available);
            }

            // Step 2: Encrypting data
            await statusModal.nextStep();
            // Prepare login data
            const loginData = {
                username_email: usernameEmail,
                password: password,
                additional_notes: additionalNotes,
                timestamp: new Date().toISOString()
            };

            // Encrypt the data
            const encryptedPackage = await encryptLoginData(
                loginData,
                seculocoAjax.public_key,
                seculocoAjax.is_pro
            );

            // Step 3: Preparing secure package
            await statusModal.nextStep();

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

            // Step 4: Sending securely
            await statusModal.nextStep();

            // Submit to server
            $.ajax({
                url: seculocoAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'seculoco_save_entry_v2',
                    submission: JSON.stringify(submissionData),
                    nonce: seculocoAjax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        statusModal.showSuccess(seculocoAjax.strings.success_message);
                        form[0].reset();
                    } else {
                        const errorMsg = seculocoAjax.strings.error_prefix + (response.data || seculocoAjax.strings.unknown_error);
                        statusModal.showError(errorMsg);
                        messageDiv.removeClass('success').addClass('error')
                            .text(errorMsg)
                            .show();
                    }
                },
                error: function () {
                    statusModal.showError(seculocoAjax.strings.network_error);
                    messageDiv.removeClass('success').addClass('error')
                        .text(seculocoAjax.strings.network_error)
                        .show();
                },
                complete: function () {
                    submitBtn.prop('disabled', false).text(seculocoAjax.strings.submit_securely);
                }
            });

        } catch (error) {
            console.error('Encryption error:', error);
            const errorMsg = seculocoAjax.strings.encryption_error + ': ' + error.message;
            statusModal.showError(errorMsg);
            messageDiv.removeClass('success').addClass('error')
                .text(errorMsg)
                .show();
            submitBtn.prop('disabled', false).text(seculocoAjax.strings.submit_securely);
        }
    });
});