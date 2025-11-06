/**
 * Base Admin Decryption Framework
 * Progressive enhancement architecture with hook/plugin system
 *
 * FREE VERSION: Uses all base functionality with no extensions
 * PRO VERSION: Extends via hooks and registered providers
 */
(function($) {
    'use strict';

    /**
     * BaseAdminDecryption - Core decryption framework
     *
     * Extension Points:
     * - hooks: Event-based extension system for PRO features
     * - extensions: Provider-based functionality injection
     *
     * Hook Points:
     * - beforeDecrypt: Modify/validate before decryption starts
     * - afterDecrypt: Process decrypted data, add features
     * - beforeDisplay: Modify display HTML, add UI elements
     * - afterDisplay: Bind additional UI handlers (e.g., export button)
     * - beforeClear: Cleanup before clearing data
     * - afterClear: Finalize after data cleared
     */
    class BaseAdminDecryption {
        constructor() {
            // Core state management
            this.decryptedData = new Map();
            this.privateKey = null; // Cache the private key
            this.autoClearTimeout = 60000; // 60 seconds per entry

            // Per-entry timers: entryId -> { seconds, clearTimer, countdownInterval }
            this.entryTimers = new Map();

            // Key cache timer (separate from per-entry UI timers)
            this.keyCacheTimeout = 60000; // 60 seconds
            this.keyCacheTimer = null;

            // Extension Registry - PRO features register here
            this.extensions = {
                keyProvider: null,      // PRO: Custom key management
                uiEnhancer: null,       // PRO: UI modifications
                featureProvider: null,  // PRO: Additional features (export, etc.)
                storageProvider: null   // PRO: Custom storage backends
            };

            // Hook System - PRO features can register callbacks
            this.hooks = {
                beforeDecrypt: [],   // fn(entryId, encryptedPackage) => modified/validated data
                afterDecrypt: [],    // fn(entryId, decryptedData) => processed data
                beforeDisplay: [],   // fn(entryId, html, data) => modified html
                afterDisplay: [],    // fn(entryId, $container, data) => void (bind handlers)
                beforeClear: [],     // fn() => void (cleanup)
                afterClear: []       // fn() => void (post-cleanup)
            };
        }

        /**
         * Initialize the decryption system
         */
        init() {
            // Bind decrypt button clicks
            $(document).on('click', '.decrypt-btn-v2', (e) => this.handleDecrypt(e));

            // Trigger initialization hook for extensions
            this.triggerHook('afterInit', this);
        }

        /**
         * Register a hook callback
         * @param {string} hookName - Name of the hook
         * @param {Function} callback - Callback function to execute
         */
        registerHook(hookName, callback) {
            if (!this.hooks[hookName]) {
                console.warn(`Hook "${hookName}" does not exist`);
                return;
            }

            if (typeof callback !== 'function') {
                console.warn('Hook callback must be a function');
                return;
            }

            this.hooks[hookName].push(callback);
        }

        /**
         * Trigger a hook with arguments
         * @param {string} hookName - Name of the hook to trigger
         * @param {...any} args - Arguments to pass to hook callbacks
         * @returns {Promise<any>} - Result from hooks (can be modified data)
         */
        triggerHook(hookName, ...args) {
            if (!this.hooks[hookName]) {
                return args[0]; // Return first arg unchanged if hook doesn't exist
            }

            let result = args[0]; // Initial value

            for (const callback of this.hooks[hookName]) {
                try {
                    const hookResult = callback(...args);
                    // If hook returns a value, use it as the new result
                    if (hookResult !== undefined) {
                        result = hookResult;
                        args[0] = hookResult; // Update first arg for next hook
                    }
                } catch (error) {
                    console.error(`Error in hook "${hookName}":`, error);
                }
            }

            return result;
        }

        /**
         * Register an extension provider
         * @param {string} type - Type of extension (keyProvider, uiEnhancer, etc.)
         * @param {Object} provider - Extension provider object
         */
        registerExtension(type, provider) {
            if (!this.extensions.hasOwnProperty(type)) {
                console.warn(`Extension type "${type}" is not valid`);
                return;
            }

            this.extensions[type] = provider;
        }

        /**
         * Handle decrypt button click
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        async handleDecrypt(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const entryId = parseInt($btn.data('id'), 10);

            // Check if entry is marked as undecryptable
            if ($btn.data('undecryptable') === true || $btn.attr('data-undecryptable') === 'true') {
                alert('This data cannot be decrypted because the passkey used to encrypt it was deleted. The data is permanently inaccessible.');
                return;
            }

            // Check if already decrypted
            if (this.decryptedData.has(entryId)) {
                this.displayDecryptedData(entryId, $btn);
                return;
            }

            try {
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-unlock spin"></span>');

                // Step 1: Get encrypted data from server
                let encryptedPackage = await this.getEncryptedData(entryId);

                // HOOK: beforeDecrypt - PRO can modify/validate data
                encryptedPackage =  this.triggerHook('beforeDecrypt', encryptedPackage, entryId);

                // Step 2: Get private key (uses extension or default)
                if (!this.privateKey) {
                    this.privateKey = await this.getPrivateKey(entryId, encryptedPackage);
                }
                
                // Step 3: Decrypt the data (CORE CRYPTO - shared by FREE/PRO)
                let decrypted = await this.decryptData(encryptedPackage, this.privateKey);
                
                // HOOK: afterDecrypt - PRO can process/enhance decrypted data
                decrypted = this.triggerHook('afterDecrypt', decrypted, entryId);
                
                // Store and display
                this.decryptedData.set(entryId, decrypted);
                this.displayDecryptedData(entryId, $btn);

                $btn.html('<span class="dashicons dashicons-yes"></span>').addClass('button-success');

                // Start/reset per-entry auto-clear countdown
                this.resetEntryTimer(entryId);

                // Reset key cache timer (keeps privateKey for a short window)
                this.resetKeyCacheTimer();

            } catch (error) {
                console.error('Decryption failed:', error);
                alert('Decryption failed: ' + error.message);
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-unlock"></span>');
            }
        }

        /**
         * Get encrypted data from server
         * CORE FUNCTIONALITY - Used by both FREE and PRO
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
         * Get private key - uses extension or default FREE method
         * EXTENSION POINT - PRO can override via keyProvider
         */
         async getPrivateKey(entryId, encryptedPackage) {

            // If PRO extension registered, use it
            if (this.extensions.keyProvider && typeof this.extensions.keyProvider.getKey === 'function') {
                return await this.extensions.keyProvider.getKey(entryId, encryptedPackage);
            }

            // DEFAULT: Use FREE version key
            return await this.getFreePrivateKey();
        }

        /**
         * Get free private key from server (already unwrapped)
         * CORE FUNCTIONALITY - FREE version uses this directly
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
         * CORE CRYPTO - Used by both FREE and PRO
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
         * Decrypt the actual data using RSA-OAEP + AES-GCM
         * CORE CRYPTO - Used by both FREE and PRO
         * This is the heart of the decryption system
         */
        async decryptData(encryptedPackage, privateKey) {
            try {

                // ===== COMPREHENSIVE INPUT VALIDATION =====

                // Checkpoint 0.1: Validate Web Crypto API availability
                if (!window.crypto || !window.crypto.subtle) {
                    throw new Error('Web Crypto API not available. This feature requires a secure context (HTTPS).');
                }

                // Checkpoint 0.2: Validate encryptedPackage object
                if (!encryptedPackage || typeof encryptedPackage !== 'object') {
                    throw new Error('Invalid encrypted package: Must be an object. Received: ' + typeof encryptedPackage);
                }

                // Checkpoint 0.3: Validate encrypted_aes_key field
                if (!encryptedPackage.encrypted_aes_key) {
                    throw new Error('Invalid encrypted package: Missing encrypted_aes_key field. Available fields: ' + Object.keys(encryptedPackage).join(', '));
                }
                if (typeof encryptedPackage.encrypted_aes_key !== 'string') {
                    throw new Error('Invalid encrypted package: encrypted_aes_key must be a string. Received: ' + typeof encryptedPackage.encrypted_aes_key);
                }
                if (encryptedPackage.encrypted_aes_key.trim() === '') {
                    throw new Error('Invalid encrypted package: encrypted_aes_key is empty');
                }

                // Checkpoint 0.4: Validate iv field
                if (!encryptedPackage.iv) {
                    throw new Error('Invalid encrypted package: Missing iv (initialization vector) field. Available fields: ' + Object.keys(encryptedPackage).join(', '));
                }
                if (typeof encryptedPackage.iv !== 'string') {
                    throw new Error('Invalid encrypted package: iv must be a string. Received: ' + typeof encryptedPackage.iv);
                }
                if (encryptedPackage.iv.trim() === '') {
                    throw new Error('Invalid encrypted package: iv is empty');
                }

                // Checkpoint 0.5: Validate encrypted_data field
                if (!encryptedPackage.encrypted_data) {
                    throw new Error('Invalid encrypted package: Missing encrypted_data field. Available fields: ' + Object.keys(encryptedPackage).join(', '));
                }
                if (typeof encryptedPackage.encrypted_data !== 'string') {
                    throw new Error('Invalid encrypted package: encrypted_data must be a string. Received: ' + typeof encryptedPackage.encrypted_data);
                }
                if (encryptedPackage.encrypted_data.trim() === '') {
                    throw new Error('Invalid encrypted package: encrypted_data is empty');
                }

                // Checkpoint 0.6: Validate privateKey
                if (!privateKey) {
                    throw new Error('Invalid private key: Private key is null or undefined');
                }
                if (!(privateKey instanceof CryptoKey)) {
                    throw new Error('Invalid private key: Must be a CryptoKey instance. Received: ' + (privateKey.constructor ? privateKey.constructor.name : typeof privateKey));
                }
                if (privateKey.type !== 'private') {
                    throw new Error('Invalid private key: Key type must be "private". Received: ' + privateKey.type);
                }
                if (!privateKey.usages.includes('decrypt')) {
                    throw new Error('Invalid private key: Key must have "decrypt" usage. Available usages: ' + privateKey.usages.join(', '));
                }

                // Checkpoint 0.7: Validate base64 format for encrypted_aes_key
                try {
                    atob(encryptedPackage.encrypted_aes_key);
                } catch (e) {
                    throw new Error('Invalid encrypted package: encrypted_aes_key is not valid base64. Error: ' + e.message);
                }

                // Checkpoint 0.8: Validate base64 format for iv
                try {
                    atob(encryptedPackage.iv);
                } catch (e) {
                    throw new Error('Invalid encrypted package: iv is not valid base64. Error: ' + e.message);
                }

                // Checkpoint 0.9: Validate base64 format for encrypted_data
                try {
                    atob(encryptedPackage.encrypted_data);
                } catch (e) {
                    throw new Error('Invalid encrypted package: encrypted_data is not valid base64. Error: ' + e.message);
                }

                // ===== DECRYPTION PROCESS =====

                // Step 1: RSA decrypt the AES key
                const encryptedAesKey = this.base64ToArrayBuffer(encryptedPackage.encrypted_aes_key);

                let aesKeyBuffer;
                try {
                    aesKeyBuffer = await crypto.subtle.decrypt(
                        { name: 'RSA-OAEP', hash: 'SHA-256' },  // CRITICAL: Must match encryption hash
                        privateKey,
                        encryptedAesKey
                    );
                } catch (rsaError) {
                    console.error("❌ RSA-OAEP decryption failed:", rsaError);
                    console.error("RSA error name:", rsaError.name);
                    console.error("RSA error message:", rsaError.message);
                    throw new Error("RSA decryption failed: " + rsaError.message + ". This usually means the private key doesn't match the public key that encrypted this data, or the data is corrupted.");
                }

                // Step 2: Import AES key
                const aesKey = await crypto.subtle.importKey(
                    'raw',
                    aesKeyBuffer,
                    { name: 'AES-GCM', length: 256 },
                    false,
                    ['decrypt']
                );

                // Step 3: Prepare AES decryption parameters
                const iv = this.base64ToArrayBuffer(encryptedPackage.iv);

                const encryptedData = this.base64ToArrayBuffer(encryptedPackage.encrypted_data);

                // Step 4: Decrypt data with AES-GCM
                const decrypted = await crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: iv },
                    aesKey,
                    encryptedData
                );

                // Step 5: Decode and parse JSON
                const decodedText = new TextDecoder().decode(decrypted);

                const parsedData = JSON.parse(decodedText);

                return parsedData;

            } catch (error) {
                // Enhanced error logging with context
                console.error("❌ decryptData failed with error:", error);
                console.error("Error type:", error.name);
                console.error("Error message:", error.message);
                console.error("Error stack:", error.stack);
                console.error("Encrypted package keys:", encryptedPackage ? Object.keys(encryptedPackage) : 'null');
                console.error("Private key available:", !!privateKey);
                console.error("Private key type:", privateKey ? privateKey.type : 'N/A');
                console.error("Private key algorithm:", privateKey ? JSON.stringify(privateKey.algorithm) : 'N/A');

                // Capture error details
                const errorMsg = error.message || error.toString() || 'Unknown error';
                const errorName = error.name || 'Error';

                // Re-throw with enhanced message
                throw new Error(
                    'Decryption failed (' + errorName + '): ' + errorMsg +
                    ' | Package fields: ' + (encryptedPackage ? Object.keys(encryptedPackage).join(', ') : 'none') +
                    ' | Key type: ' + (privateKey ? privateKey.type : 'missing')
                );
            }
        }

        /**
         * Display decrypted data in the UI
         * CORE FUNCTIONALITY with EXTENSION POINTS
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
                // Build base HTML
                let html = `
                    <tr class="decrypted-row" data-entry-id="${entryId}">
                        <td colspan="8">
                            <div class="decrypted-data-container">
                                <div class="decrypted-header">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <strong>Decrypted Data</strong>
                                        <span class="auto-clear-warning" data-entry-id="${entryId}">Auto-clears in <span id="decrypted-area-countdown-${entryId}">60</span> seconds</span>
                                        <button type="button" class="button clear-now-btn" data-id="${entryId}" style="margin-left: 8px;">Clear now</button>
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

                // HOOK: beforeDisplay - PRO can modify HTML (add export button, etc.)
                html =  this.triggerHook('beforeDisplay', html, entryId, data);

                $row.after(html);

                // Get the newly created container
                const $newContainer = $row.next('.decrypted-row').find('.decrypted-data-container');

                // HOOK: afterDisplay - PRO can bind additional UI handlers
                 this.triggerHook('afterDisplay', entryId, $newContainer, data);
            } else {
                $container.parent().parent().toggle();
            }

            // Bind copy and toggle buttons (CORE functionality)
            this.bindCopyButtons(entryId);

            // Reset per-entry auto-clear timer when toggling display
            this.resetEntryTimer(entryId);
        }

        /**
         * Bind copy and toggle buttons
         * CORE FUNCTIONALITY - Used by both FREE and PRO
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

            // Bind clear-now button
            $row.find('.clear-now-btn').off('click').on('click', (e) => {
                e.preventDefault();
                const id = parseInt($(e.currentTarget).data('id'), 10);
                if (!isNaN(id)) {
                    this.clearEntryData(id);
                }
            });
        }

        /**
         * Auto-clear sensitive data after timeout
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        startEntryTimer(entryId) {
            // Stop existing timers for this entry
            this.stopEntryTimer(entryId);

            // Initialize countdown
            const countdownSelector = `#decrypted-area-countdown-${entryId}`;
            const warningSelector = `.decrypted-row[data-entry-id="${entryId}"] .auto-clear-warning`;

            const timerState = {
                seconds: Math.floor(this.autoClearTimeout / 1000),
                clearTimer: null,
                countdownInterval: null
            };

            // Set initial display
            $(countdownSelector).text(timerState.seconds);

            // Start countdown interval
            timerState.countdownInterval = setInterval(() => {
                timerState.seconds--;
                $(countdownSelector).text(timerState.seconds);

                // Change color when less than 10 seconds
                if (timerState.seconds <= 10) {
                    $(warningSelector).removeClass('seculoco-countdown-normal').addClass('seculoco-countdown-warning');
                } else {
                    $(warningSelector).removeClass('seculoco-countdown-warning').addClass('seculoco-countdown-normal');
                }

                if (timerState.seconds <= 0) {
                    this.clearEntryData(entryId);
                }
            }, 1000);

            // Set timeout to clear this entry
            timerState.clearTimer = setTimeout(() => {
                this.clearEntryData(entryId);
            }, this.autoClearTimeout);

            this.entryTimers.set(entryId, timerState);
        }

        /**
         * Stop auto-clear timer
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        stopEntryTimer(entryId) {
            const t = this.entryTimers.get(entryId);
            if (t) {
                clearTimeout(t.clearTimer);
                clearInterval(t.countdownInterval);
                this.entryTimers.delete(entryId);
            }
        }

        /**
         * Reset auto-clear timer
         * CORE FUNCTIONALITY - Used by both FREE and PRO
         */
        resetEntryTimer(entryId) {
            this.startEntryTimer(entryId);
        }

        // Clear a single entry's decrypted data and UI
        clearEntryData(entryId) {
            this.stopEntryTimer(entryId);
            this.decryptedData.delete(entryId);

            // Remove row UI for this entry
            const $row = $(`.decrypted-row[data-entry-id="${entryId}"]`);
            if ($row.length > 0) {
                $row.remove();
            }

            // Re-enable decrypt button for this entry if present
            const $btn = $(`.decrypt-btn-v2[data-id="${entryId}"]`);
            if ($btn.length > 0) {
                $btn.prop('disabled', false).removeClass('button-success');
                $btn.html('<span class="dashicons dashicons-unlock"></span>');
            }
        }

        // Key cache timer: clears privateKey only (not UI)
        resetKeyCacheTimer() {
            if (this.keyCacheTimer) {
                clearTimeout(this.keyCacheTimer);
            }
            this.keyCacheTimer = setTimeout(() => {
                this.privateKey = null;
                this.keyCacheTimer = null;
            }, this.keyCacheTimeout);
        }

        /**
         * Clear all decrypted data from memory and UI
         * CORE FUNCTIONALITY with EXTENSION POINTS
         */
        async clearAllDecryptedData() {
            // HOOK: beforeClear - PRO can cleanup custom data
            await this.triggerHook('beforeClear');

            // Clear all entry timers and UI
            for (const entryId of Array.from(this.decryptedData.keys())) {
                this.clearEntryData(entryId);
            }

            // Clear decrypted data and key cache
            this.decryptedData.clear();
            this.privateKey = null;
            if (this.keyCacheTimer) {
                clearTimeout(this.keyCacheTimer);
                this.keyCacheTimer = null;
            }

            // HOOK: afterClear - PRO can perform post-cleanup
            await this.triggerHook('afterClear');
        }

        /**
         * Helper: Convert base64 to ArrayBuffer
         * CORE UTILITY - Used by both FREE and PRO
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
         * CORE UTILITY - Used by both FREE and PRO
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

        /**
         * Public API: Get decrypted data for an entry
         * Useful for PRO extensions that need access to decrypted data
         */
        getDecryptedData(entryId) {
            return this.decryptedData.get(entryId);
        }

        /**
         * Public API: Check if entry is decrypted
         * Useful for PRO extensions
         */
        isDecrypted(entryId) {
            return this.decryptedData.has(entryId);
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        // Create global instance - accessible to PRO extensions
        window.seculocoDecrypt = new BaseAdminDecryption();
        window.seculocoDecrypt.init();

        // Backwards compatibility alias
        window.freeAdminDecryption = window.seculocoDecrypt;
    });

})(jQuery);
