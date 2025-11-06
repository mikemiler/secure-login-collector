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

			// Password caching for standard (password-based) encryption
			this.passwordCache = null;
			this.passwordCacheTimer = null;
			this.passwordCacheTimeout = 60000; // 60 seconds

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

            // Determine encryption type (defaults to password-based flow)
            const encryptionType = encryptedPackage.encryption_type || (encryptedPackage.is_pro_encrypted ? 'aes-rsa-passkey-v2' : 'aes-rsa-password-v3');

			if (encryptionType === 'aes-rsa-passkey-v2') {
				throw new Error('Passkey-protected entries require the PRO extension.');
			}

			return await this.getStandardPrivateKey(entryId);
		}

        /**
         * Retrieve and unwrap the standard (password-protected) private key.
         */
        async getStandardPrivateKey(entryId) {
            const response = await $.ajax({
                url: seculocoAjax.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_wrapped_private_key',
                    entry_id: entryId,
                    nonce: seculocoAjax.nonce
                }
            });

            if (!response.success) {
                throw new Error(response.data || 'Failed to get wrapped private key');
            }

            if (response.data.type !== 'standard' || !response.data.wrapped_key) {
                throw new Error('Expected password-based key material');
            }

            const wrappedKey = response.data.wrapped_key;

            let password = this.passwordCache;
            let remember = true;

            if (!password) {
                const promptResult = await this.promptForPassword();
                password = promptResult.password;
                remember = promptResult.remember;
            }

            try {
                const privateKeyPem = await this.unwrapPrivateKeyWithPassword(wrappedKey, password);

                if (remember) {
                    this.cachePassword(password);
                } else {
                    this.clearPasswordCache();
                }

                return await this.importRSAPrivateKey(privateKeyPem);
            } catch (error) {
                this.clearPasswordCache();
                throw error;
            }
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
                const normalizedAesKey = this.normalizeBase64(encryptedPackage.encrypted_aes_key, 'encrypted_aes_key');

                // Checkpoint 0.8: Validate base64 format for iv
                const normalizedIv = this.normalizeBase64(encryptedPackage.iv, 'iv');

                // Checkpoint 0.9: Validate base64 format for encrypted_data
                const normalizedEncryptedData = this.normalizeBase64(encryptedPackage.encrypted_data, 'encrypted_data');

                // ===== DECRYPTION PROCESS =====

                // Step 1: RSA decrypt the AES key
                const encryptedAesKey = this.base64ToArrayBuffer(normalizedAesKey);

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
                const iv = this.base64ToArrayBuffer(normalizedIv);

                const encryptedData = this.base64ToArrayBuffer(normalizedEncryptedData);

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
            let binaryString;
            try {
                binaryString = atob(base64);
            } catch (error) {
                throw new Error(`Base64 decoding failed: ${error.message}`);
            }
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        }

        /**
         * Helper: Normalise and validate base64 strings.
         *
         * Accepts base64 and base64url variants, strips whitespace, fixes padding,
         * and ensures only expected characters remain.
         *
         * @param {string} value - The value to normalise.
         * @param {string} field - Field name for error context.
         * @returns {string} Normalised base64 string.
         */
        normalizeBase64(value, field) {
            if (typeof value !== 'string') {
                throw new Error(`Invalid encrypted package: ${field} must be a string. Received: ${typeof value}`);
            }

            let trimmed = value.trim();
            if (trimmed === '') {
                throw new Error(`Invalid encrypted package: ${field} is empty`);
            }

            // Remove whitespace that might have slipped through sanitisation.
            trimmed = trimmed.replace(/\s+/g, '');

            // Support URL-safe variants by converting to standard base64 alphabet.
            let normalised = trimmed.replace(/-/g, '+').replace(/_/g, '/');

            // Ensure no unexpected characters remain.
            const invalidMatch = normalised.match(/[^A-Za-z0-9+/=]/);
            if (invalidMatch) {
                throw new Error(`Invalid encrypted package: ${field} contains illegal character "${invalidMatch[0]}"`);
            }

            // Fix padding if necessary.
            const padding = normalised.length % 4;
            if (padding !== 0) {
                normalised += '='.repeat(4 - padding);
            }

            // Final verification using atob to ensure we have valid base64.
            try {
                atob(normalised);
            } catch (error) {
                throw new Error(`Invalid encrypted package: ${field} is not valid base64. Error: ${error.message}`);
            }

			return normalised;
		}

        /**
         * Prompt the administrator for the password used to wrap the standard key.
         *
         * @returns {Promise<{password: string, remember: boolean}>}
         */
        async promptForPassword() {
            return new Promise((resolve, reject) => {
                const $backdrop = $('<div>').addClass('seculoco-password-prompt-backdrop');
                const $modal = $('<div>').addClass('seculoco-password-prompt-modal');

                $modal.html(`
                    <h2 class="seculoco-password-prompt-title">Enter Encryption Password</h2>
                    <p class="seculoco-password-prompt-description">
                        This data was encrypted with a password. Please enter the password to decrypt.
                    </p>
                    <div class="seculoco-password-input-wrapper">
                        <input
                            type="password"
                            class="seculoco-password-input"
                            id="seculoco-password-input"
                            placeholder="Enter password"
                            autocomplete="off"
                        />
                        <button type="button" class="seculoco-password-toggle-btn" aria-label="Toggle password visibility">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                    <div class="seculoco-password-prompt-checkbox">
                        <input
                            type="checkbox"
                            id="seculoco-remember-password"
                            checked
                        />
                        <label for="seculoco-remember-password">
                            Remember for this session (60 seconds)
                        </label>
                    </div>
                    <div class="seculoco-password-prompt-error" style="display: none;"></div>
                    <div class="seculoco-password-prompt-buttons">
                        <button type="button" class="seculoco-password-prompt-cancel-btn">Cancel</button>
                        <button type="button" class="seculoco-password-prompt-decrypt-btn">Decrypt</button>
                    </div>
                `);

                $('body').append($backdrop).append($modal);

                setTimeout(() => {
                    $('#seculoco-password-input').trigger('focus');
                }, 50);

                const closeModal = (error) => {
                    $(document).off('keydown.password-prompt');
                    $modal.remove();
                    $backdrop.remove();
                    if (error) {
                        reject(new Error(error));
                    }
                };

                $modal.find('.seculoco-password-toggle-btn').on('click', function() {
                    const $input = $('#seculoco-password-input');
                    const $icon = $(this).find('.dashicons');

                    if ($input.attr('type') === 'password') {
                        $input.attr('type', 'text');
                        $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                    } else {
                        $input.attr('type', 'password');
                        $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                    }
                });

                const resolveWithPassword = () => {
                    const password = $('#seculoco-password-input').val().trim();
                    const remember = $('#seculoco-remember-password').is(':checked');

                    if (!password) {
                        $modal.find('.seculoco-password-prompt-error')
                            .text('Please enter a password.')
                            .show();
                        return;
                    }

                    $(document).off('keydown.password-prompt');
                    $modal.remove();
                    $backdrop.remove();
                    resolve({ password, remember });
                };

                $modal.find('.seculoco-password-prompt-decrypt-btn').on('click', resolveWithPassword);

                $modal.find('.seculoco-password-prompt-cancel-btn').on('click', () => closeModal('Password prompt cancelled'));

                $backdrop.on('click', () => closeModal('Password prompt cancelled'));

                $(document).on('keydown.password-prompt', (event) => {
                    if (event.key === 'Escape') {
                        closeModal('Password prompt cancelled');
                    }
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        resolveWithPassword();
                    }
                });
            });
        }

        /**
         * Cache the password for a short period to improve UX.
         *
         * @param {string} password
         */
        cachePassword(password) {
            this.passwordCache = password;

            if (this.passwordCacheTimer) {
                clearTimeout(this.passwordCacheTimer);
            }

            this.passwordCacheTimer = setTimeout(() => {
                this.clearPasswordCache();
            }, this.passwordCacheTimeout);
        }

        /**
         * Clear any cached password.
         */
        clearPasswordCache() {
            this.passwordCache = null;
            if (this.passwordCacheTimer) {
                clearTimeout(this.passwordCacheTimer);
                this.passwordCacheTimer = null;
            }
        }

        /**
         * Unwrap the standard RSA private key using the provided password.
         *
         * @param {Object} wrappedKey
         * @param {string} password
         * @returns {Promise<string>} PEM encoded private key.
         */
        async unwrapPrivateKeyWithPassword(wrappedKey, password) {
            if (!password) {
                throw new Error('Password is required to decrypt this data.');
            }

            if (!wrappedKey || typeof wrappedKey !== 'object') {
                throw new Error('Invalid wrapped key data.');
            }

            try {
                const normalizedSalt = this.normalizeBase64(wrappedKey.salt, 'wrapped_key.salt');
                const normalizedIv = this.normalizeBase64(wrappedKey.iv, 'wrapped_key.iv');
                const normalizedCiphertext = this.normalizeBase64(wrappedKey.encrypted_data, 'wrapped_key.encrypted_data');
                const normalizedTag = this.normalizeBase64(wrappedKey.tag, 'wrapped_key.tag');

                const salt = this.base64ToArrayBuffer(normalizedSalt);
                const iv = new Uint8Array(this.base64ToArrayBuffer(normalizedIv));
                const ciphertext = new Uint8Array(this.base64ToArrayBuffer(normalizedCiphertext));
                const authTag = new Uint8Array(this.base64ToArrayBuffer(normalizedTag));

                const encoder = new TextEncoder();
                const passwordKeyMaterial = await crypto.subtle.importKey(
                    'raw',
                    encoder.encode(password),
                    'PBKDF2',
                    false,
                    ['deriveKey']
                );

                const wrappingKey = await crypto.subtle.deriveKey(
                    {
                        name: 'PBKDF2',
                        salt: salt,
                        iterations: 100000,
                        hash: 'SHA-256'
                    },
                    passwordKeyMaterial,
                    {
                        name: 'AES-GCM',
                        length: 256
                    },
                    false,
                    ['decrypt']
                );

                const combinedCiphertext = new Uint8Array(ciphertext.length + authTag.length);
                combinedCiphertext.set(ciphertext);
                combinedCiphertext.set(authTag, ciphertext.length);

                const decrypted = await crypto.subtle.decrypt(
                    {
                        name: 'AES-GCM',
                        iv: iv,
                        tagLength: authTag.length * 8
                    },
                    wrappingKey,
                    combinedCiphertext.buffer
                );

                return new TextDecoder().decode(decrypted);
            } catch (error) {
                console.error('Failed to unwrap private key:', error);
                throw new Error('Failed to unwrap key: ' + error.message);
            }
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
