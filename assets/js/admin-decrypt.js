/**
 * Free Version Admin Decryption
 * Handles decryption for free version entries (no passkey required)
 */
(function($) {
    'use strict';

    class FreeAdminDecryption {
        constructor() {
            this.decryptedData = new Map();
            this.privateKey = null; // Cache the free private key
            this.autoClearTimeout = 60000; // 60 seconds
            this.countdownSeconds = 60;
            this.clearTimer = null;
            this.countdownInterval = null;
            this.init();
        }

        init() {
            // Bind decrypt button clicks
            $(document).on('click', '.decrypt-btn-v2', (e) => this.handleDecrypt(e));
        }

        /**
         * Handle decrypt button click
         */
        async handleDecrypt(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const entryId = parseInt($btn.data('id'), 10);

            // Check if already decrypted
            if (this.decryptedData.has(entryId)) {
                this.displayDecryptedData(entryId, $btn);
                return;
            }

            try {
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-unlock spin"></span>');

                // Step 1: Get encrypted data from server
                const encryptedPackage = await this.getEncryptedData(entryId);

                // Step 2: Get free private key (cache it if we don't have it)
                if (!this.privateKey) {
                    this.privateKey = await this.getFreePrivateKey();
                }

                // Step 3: Decrypt the data
                const decrypted = await this.decryptData(encryptedPackage, this.privateKey);

                // Store and display
                this.decryptedData.set(entryId, decrypted);
                this.displayDecryptedData(entryId, $btn);

                $btn.html('<span class="dashicons dashicons-yes"></span>').addClass('button-success');

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
                url: seculocoAjax.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_encrypted_entry',
                    id: entryId,
                    nonce: seculocoAjax.nonce
                }
            });

            if (!response.success) {
                throw new Error(response.data || 'Failed to get encrypted data');
            }

            return response.data;
        }

        /**
         * Get free private key from server (already unwrapped)
         */
        async getFreePrivateKey() {
            // Use a dummy entry ID for free version (server returns free key regardless)
            const response = await $.ajax({
                url: seculocoAjax.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_wrapped_private_key',
                    entry_id: 0, // Server will return free key
                    nonce: seculocoAjax.nonce
                }
            });

            if (!response.success) {
                throw new Error('Failed to get private key');
            }

            if (response.data.type !== 'free') {
                throw new Error('Expected free version key');
            }

            // Free key is already decrypted, just decode it
            const privateKeyB64 = response.data.private_key;
            return await this.importRSAPrivateKey(atob(privateKeyB64));
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
                    $row = $(`tr[data-entry-id="${entryId}"]`);
                }
                
                if ($row.length === 0) {
                    // Try finding by button data attribute
                    const $foundBtn = $(`.decrypt-btn-v2[data-id="${entryId}"]`);
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

            // Bind copy and toggle buttons
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

        /**
         * Clear all decrypted data from memory and UI
         */
        clearAllDecryptedData() {
            // Clear decrypted data from memory
            this.decryptedData.clear();
            this.privateKey = null; // Clear cached key

            // Remove from UI
            $('.decrypted-row').remove();
            $('.decrypt-btn-v2').each(function() {
                $(this).prop('disabled', false).removeClass('button-success');
                $(this).html('<span class="dashicons dashicons-unlock"></span>');
            });

            this.stopAutoClear();
        }

        /**
         * Helper: Convert base64 to ArrayBuffer
         */
        base64ToArrayBuffer(base64) {
            const binaryString = atob(base64);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        }

        /**
         * Helper: Escape HTML
         */
        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text || '').replace(/[&<>"']/g, (m) => map[m]);
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        window.freeAdminDecryption = new FreeAdminDecryption();
    });

})(jQuery);

