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

            // Zero-knowledge: DEK cache and timer
            this.cachedDEK = null;
            this.dekCacheTimeout = 60000; // 60 seconds
            this.dekCacheTimer = null;
            this.dekInactivityTimer = null;

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

            // Clear DEK cache on page unload
            $(window).on('beforeunload', () => {
                this.clearDEKCache();
            });

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
                    this.privateKey = await this.getPrivateKey(entryId);
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

                // Reset DEK inactivity timer on successful decryption
                if (this.cachedDEK) {
                    this.resetDEKInactivityTimer();
                }

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
         async getPrivateKey(entryId) {

            // If PRO extension registered, use it
            if (this.extensions.keyProvider && typeof this.extensions.keyProvider.getKey === 'function') {
                return await this.extensions.keyProvider.getKey(entryId);
            }

            // DEFAULT: Use FREE version key
            return await this.getFreePrivateKey(entryId);
        }

        /**
         * Get free private key from server (PBKDF2-wrapped for zero-knowledge security)
         * CORE FUNCTIONALITY - FREE version uses this directly
         */
        async getFreePrivateKey(entryId) {
            const response = await $.ajax({
                url: seculocoAjax.ajaxurl,
                method: 'POST',
                data: {
                    action: 'seculoco_get_wrapped_private_key',
                    entry_id: entryId || 0,
                    nonce: seculocoAjax.nonce
                }
            });

            if (!response.success) {
                throw new Error('Failed to get private key');
            }

            // Check if we have cached DEK for this session
            if (this.cachedDEK) {
                return this.cachedDEK;
            }

            // Prompt for master password
            const masterPassword = await this.promptMasterPassword();

            // Parse wrapped key structure - handle both formats
            // Server sends: { wrapped_key: {wrapped_dek, iv, tag}, salt }
            let wrappedDEK, salt, iv;

            if (response.data.wrapped_key && typeof response.data.wrapped_key === 'object') {
                // FREE version format: wrapped_key is an object with {wrapped_dek, iv, tag}
                wrappedDEK = response.data.wrapped_key;
                salt = response.data.salt;
                iv = response.data.wrapped_key.iv; // IV is inside wrapped_key object
            } else if (response.data.wrapped_dek) {
                // Alternative format: wrapped_dek directly in response
                wrappedDEK = response.data.wrapped_dek;
                salt = response.data.salt;
                iv = response.data.iv;
            } else {
                throw new Error('Invalid private key response format. Missing wrapped_key or wrapped_dek.');
            }

            // Validate all required fields are present
            if (!wrappedDEK || !salt) {
                throw new Error('Invalid private key response: Missing required fields (wrapped_dek or salt).');
            }

            // Unwrap DEK with master password
            const dek = await this.unwrapDEKWithPassword(wrappedDEK, masterPassword, salt, iv);

            // Cache DEK in memory (not localStorage!)
            this.cachedDEK = dek;
            this.startDEKCacheTimer();

            return dek;
        }

        /**
         * Import RSA private key from PEM or base64 string
         * CORE CRYPTO - Used by both FREE and PRO
         * @param {string} pem - PEM-formatted private key or base64 string
         * @returns {Promise<CryptoKey>} - Imported RSA private key
         */
        async importRSAPrivateKey(pem) {
            // Validate input
            if (!pem || typeof pem !== 'string') {
                throw new Error('Invalid private key: Expected non-empty string, got ' + typeof pem);
            }

            const trimmedPem = pem.trim();
            if (trimmedPem === '') {
                throw new Error('Invalid private key: Empty string after trimming');
            }

            console.log('🔑 PEM key length:', trimmedPem.length);
            console.log('🔑 PEM starts with:', trimmedPem.substring(0, 50));
            console.log('🔑 PEM ends with:', trimmedPem.substring(trimmedPem.length - 50));

            // Remove PEM headers and decode base64
            // Handle both standard PEM and PKCS#1 RSA format
            const pemContents = trimmedPem
                .replace('-----BEGIN PRIVATE KEY-----', '')
                .replace('-----END PRIVATE KEY-----', '')
                .replace('-----BEGIN RSA PRIVATE KEY-----', '')
                .replace('-----END RSA PRIVATE KEY-----', '')
                .replace(/\s/g, '');

            console.log('📝 Base64 content length after header removal:', pemContents.length);
            console.log('📝 First 50 chars:', pemContents.substring(0, 50));
            console.log('📝 Contains non-Latin1?', /[^\x00-\xFF]/.test(pemContents));

            // Check for common encoding issues
            if (/[^\x00-\xFF]/.test(pemContents)) {
                console.error('❌ Base64 contains non-Latin1 characters!');
                // Try to find problematic characters
                for (let i = 0; i < Math.min(pemContents.length, 200); i++) {
                    const charCode = pemContents.charCodeAt(i);
                    if (charCode > 255) {
                        console.error(`Character at position ${i}: "${pemContents[i]}" (code: ${charCode})`);
                    }
                }
                throw new Error('Invalid private key: PEM content contains non-Latin1 characters. The key may be corrupted or use an unsupported encoding.');
            }

            // Validate base64 content
            if (pemContents === '') {
                throw new Error('Invalid private key: No content after removing PEM headers');
            }

            let binaryDer;
            try {
                binaryDer = this.base64ToArrayBuffer(pemContents);
            } catch (error) {
                console.error('❌ Base64 decode error:', error);
                console.error('Base64 sample:', pemContents.substring(0, 100));
                throw new Error('Invalid private key format: Failed to decode base64 content. ' + error.message);
            }

            // Validate decoded data has content
            if (binaryDer.byteLength === 0) {
                throw new Error('Invalid private key: Decoded data is empty');
            }

            try {
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
            } catch (error) {
                console.error('Failed to import RSA private key:', error);
                throw new Error('Failed to import private key: ' + error.message + '. The key may be corrupted or in an unsupported format.');
            }
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

                // Note: Base64 validation removed - base64ToArrayBuffer() handles all validation
                // including whitespace cleaning and error handling

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
            // Validate input
            if (!base64 || typeof base64 !== 'string') {
                throw new Error('Invalid base64 input: Expected non-empty string, got ' + typeof base64);
            }

            // Remove ALL whitespace (newlines, spaces, tabs, carriage returns)
            // This is standard for base64 processing and handles PEM-formatted keys
            const cleanBase64 = base64.replace(/\s+/g, '');

            // Check if string is empty after cleaning
            if (cleanBase64 === '') {
                throw new Error('Invalid base64 input: Empty string after removing whitespace');
            }

            // Try to decode FIRST - atob() is the authoritative validator
            let binaryString;
            try {
                binaryString = atob(cleanBase64);
            } catch (e) {
                // Only validate format if atob() fails
                const base64Pattern = /^[A-Za-z0-9+/]*={0,2}$/;
                if (!base64Pattern.test(cleanBase64)) {
                    throw new Error('Invalid base64 format: Contains invalid characters. Decoding error: ' + e.message);
                }
                throw new Error('Base64 decoding failed: ' + e.message + '. This may indicate corrupted data.');
            }

            // Convert to ArrayBuffer
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
         * Zero-knowledge: Derive PBKDF2 key from password
         * @param {string} password - Master password
         * @param {string} salt - Base64-encoded salt
         * @param {number} iterations - PBKDF2 iterations (default: 600000)
         * @returns {Promise<CryptoKey>} - Derived AES-GCM key
         */
        async derivePBKDF2Key(password, salt, iterations = 600000) {
            // Convert password to ArrayBuffer
            const encoder = new TextEncoder();
            const passwordBuffer = encoder.encode(password);

            // Import password as CryptoKey for PBKDF2
            const passwordKey = await crypto.subtle.importKey(
                'raw',
                passwordBuffer,
                'PBKDF2',
                false,
                ['deriveBits', 'deriveKey']
            );

            // Convert salt from base64 to ArrayBuffer
            const saltBuffer = this.base64ToArrayBuffer(salt);

            // Derive AES-GCM key using PBKDF2
            const derivedKey = await crypto.subtle.deriveKey(
                {
                    name: 'PBKDF2',
                    salt: saltBuffer,
                    iterations: iterations,
                    hash: 'SHA-256'
                },
                passwordKey,
                {
                    name: 'AES-GCM',
                    length: 256
                },
                false,
                ['encrypt', 'decrypt']
            );

            return derivedKey;
        }

        /**
         * Zero-knowledge: Unwrap DEK with master password
         * @param {Object} wrappedDEK - Wrapped DEK object {wrapped_dek/ciphertext, iv, tag}
         * @param {string} masterPassword - Master password
         * @param {string} salt - Base64-encoded salt
         * @param {string} iv - Base64-encoded IV (unused if wrappedDEK has its own IV)
         * @returns {Promise<CryptoKey>} - Unwrapped RSA private key
         */
        async unwrapDEKWithPassword(wrappedDEK, masterPassword, salt, iv) {
            // Validate input
            if (!wrappedDEK || typeof wrappedDEK !== 'object') {
                throw new Error('Invalid wrapped DEK: Expected object, got ' + typeof wrappedDEK);
            }

            // Derive wrapping key with PBKDF2
            const wrappingKey = await this.derivePBKDF2Key(masterPassword, salt);

            // Parse wrapped DEK - handle both field naming conventions
            // Server may send 'wrapped_dek' or 'ciphertext'
            const ciphertextField = wrappedDEK.wrapped_dek || wrappedDEK.ciphertext;
            if (!ciphertextField) {
                const availableFields = Object.keys(wrappedDEK).join(', ');
                throw new Error('Invalid wrapped DEK format: Missing ciphertext/wrapped_dek field. Available fields: ' + availableFields);
            }
       
            // Validate and parse all required components
            if (!wrappedDEK.iv && !iv) {
                throw new Error('Invalid wrapped DEK format: Missing IV (initialization vector)');
            }
            if (!wrappedDEK.tag) {
                const availableFields = Object.keys(wrappedDEK).join(', ');
                throw new Error('Invalid wrapped DEK format: Missing authentication tag. Available fields: ' + availableFields);
            }

            const dekIV = this.base64ToArrayBuffer(wrappedDEK.iv || iv);
            const dekCiphertext = this.base64ToArrayBuffer(ciphertextField);
            const dekTag = this.base64ToArrayBuffer(wrappedDEK.tag);

            // Validate decoded buffers
            if (dekIV.byteLength !== 12) {
                throw new Error('Invalid IV length: Expected 12 bytes, got ' + dekIV.byteLength);
            }
            if (dekTag.byteLength !== 16) {
                throw new Error('Invalid tag length: Expected 16 bytes, got ' + dekTag.byteLength);
            }

            // AES-GCM: concatenate ciphertext and tag
            const encryptedDEK = new Uint8Array(dekCiphertext.byteLength + dekTag.byteLength);
            encryptedDEK.set(new Uint8Array(dekCiphertext), 0);
            encryptedDEK.set(new Uint8Array(dekTag), dekCiphertext.byteLength);

            // Decrypt DEK with AES-256-GCM
            let decryptedDEK;
            try {
                decryptedDEK = await crypto.subtle.decrypt(
                    {
                        name: 'AES-GCM',
                        iv: dekIV,
                        tagLength: 128
                    },
                    wrappingKey,
                    encryptedDEK
                );
            } catch (error) {
                console.error('DEK decryption failed:', error);
                throw new Error('Invalid master password or corrupted wrapped key data. Please verify your master password is correct.');
            }
            // Convert DEK to PEM and import as RSA private key
            // Use UTF-8 decoder for PEM format (should be ASCII-compatible)
            const dekPEM = new TextDecoder('utf-8').decode(decryptedDEK);

            console.log('🔐 Decrypted DEK length:', decryptedDEK.byteLength, 'bytes');
            console.log('📄 PEM key preview (first 100 chars):', dekPEM.substring(0, 100));
            console.log('📄 PEM key preview (last 100 chars):', dekPEM.substring(dekPEM.length - 100));
            console.log('🔍 Contains non-ASCII?', /[^\x00-\x7F]/.test(dekPEM));

            // Check if decrypted data is PEM format (should start with -----BEGIN)
            if (!dekPEM.trim().startsWith('-----BEGIN')) {
                console.warn('⚠️  Decrypted key is not in PEM format!');
                console.warn('Key appears to be in DER format - attempting direct import');
                console.warn('First 20 bytes (hex):', Array.from(new Uint8Array(decryptedDEK).slice(0, 20))
                    .map(b => b.toString(16).padStart(2, '0')).join(' '));

                // Try to import as raw DER format (PKCS#8)
                try {
                    console.log('🔄 Attempting to import DER format key directly...');
                    const derKey = await crypto.subtle.importKey(
                        'pkcs8',
                        decryptedDEK,  // Use raw ArrayBuffer (DER format)
                        {
                            name: 'RSA-OAEP',
                            hash: 'SHA-256'
                        },
                        false,
                        ['decrypt']
                    );
                    console.log('✅ Successfully imported DER format key!');
                    console.warn('⚠️  WARNING: Your encryption keys are stored in an old/incompatible format.');
                    console.warn('After decrypting your data, please go to Settings and reinitialize your encryption keys.');
                    return derKey;
                } catch (derError) {
                    console.error('❌ Failed to import as DER format:', derError);
                    throw new Error(
                        'Invalid private key format: The decrypted key is neither valid PEM nor DER format. ' +
                        'This usually means the encryption keys are corrupted. ' +
                        'Please go to Settings → Master Password and click "Reinitialize Encryption Keys" to fix this issue. ' +
                        'WARNING: Reinitializing will make existing encrypted data undecryptable! ' +
                        'Original error: ' + derError.message
                    );
                }
            }

            return await this.importRSAPrivateKey(dekPEM);
        }

        /**
         * Zero-knowledge: Prompt for master password
         * @returns {Promise<string>} - Master password
         */
        async promptMasterPassword() {
            return new Promise((resolve, reject) => {
                // Check if modal already exists
                if ($('#seculoco-master-password-modal').length > 0) {
                    $('#seculoco-master-password-modal').remove();
                }

                // Create modal HTML
                const modalHTML = `
                    <div id="seculoco-master-password-modal" class="seculoco-modal-overlay">
                        <div class="seculoco-modal-container">
                            <div class="seculoco-modal-header">
                                <h2>Master Password Required</h2>
                                <p>Enter your master password to decrypt stored credentials</p>
                            </div>
                            <div class="seculoco-modal-content">
                                <div class="seculoco-field-group">
                                    <label for="seculoco-master-password-input">Master Password:</label>
                                    <div class="seculoco-password-input-group">
                                        <input
                                            type="password"
                                            id="seculoco-master-password-input"
                                            class="seculoco-password-input"
                                            placeholder="Enter master password"
                                            autocomplete="off"
                                        />
                                        <button type="button" class="seculoco-toggle-password-btn" id="seculoco-toggle-master-password">
                                            <span class="dashicons dashicons-visibility"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="seculoco-field-group">
                                    <label>
                                        <input type="checkbox" id="seculoco-remember-password" />
                                        Remember for this session (60 seconds)
                                    </label>
                                </div>
                                <div class="seculoco-error-message" id="seculoco-password-error" style="display: none;"></div>
                            </div>
                            <div class="seculoco-modal-footer">
                                <button type="button" class="button button-primary" id="seculoco-submit-password">Unlock</button>
                                <button type="button" class="button" id="seculoco-cancel-password">Cancel</button>
                            </div>
                        </div>
                    </div>
                `;

                $('body').append(modalHTML);

                const $modal = $('#seculoco-master-password-modal');
                const $input = $('#seculoco-master-password-input');
                const $error = $('#seculoco-password-error');

                // Focus input
                setTimeout(() => $input.focus(), 100);

                // Toggle password visibility
                $('#seculoco-toggle-master-password').on('click', function() {
                    const $passwordInput = $('#seculoco-master-password-input');
                    const $icon = $(this).find('.dashicons');

                    if ($passwordInput.attr('type') === 'password') {
                        $passwordInput.attr('type', 'text');
                        $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                    } else {
                        $passwordInput.attr('type', 'password');
                        $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                    }
                });

                // Submit handler
                const submitPassword = () => {
                    const password = $input.val().trim();

                    if (!password) {
                        $error.text('Please enter your master password').show();
                        return;
                    }

                    $modal.remove();
                    resolve(password);
                };

                // Cancel handler
                const cancelPassword = () => {
                    $modal.remove();
                    reject(new Error('Master password prompt cancelled'));
                };

                // Bind events
                $('#seculoco-submit-password').on('click', submitPassword);
                $('#seculoco-cancel-password').on('click', cancelPassword);

                // Enter key submits
                $input.on('keypress', (e) => {
                    if (e.which === 13) {
                        submitPassword();
                    }
                });

                // Escape key cancels
                $(document).on('keydown.seculoco-modal', (e) => {
                    if (e.which === 27) {
                        $(document).off('keydown.seculoco-modal');
                        cancelPassword();
                    }
                });
            });
        }

        /**
         * Zero-knowledge: Start DEK cache timer
         */
        startDEKCacheTimer() {
            // Clear existing timers
            this.clearDEKCacheTimers();

            // Main cache timer (60 seconds)
            this.dekCacheTimer = setTimeout(() => {
                this.clearDEKCache();
            }, this.dekCacheTimeout);

            // Inactivity timer (60 seconds)
            this.resetDEKInactivityTimer();
        }

        /**
         * Zero-knowledge: Reset DEK inactivity timer
         */
        resetDEKInactivityTimer() {
            if (this.dekInactivityTimer) {
                clearTimeout(this.dekInactivityTimer);
            }

            this.dekInactivityTimer = setTimeout(() => {
                this.clearDEKCache();
            }, 60000); // 60 seconds of inactivity
        }

        /**
         * Zero-knowledge: Clear DEK cache timers
         */
        clearDEKCacheTimers() {
            if (this.dekCacheTimer) {
                clearTimeout(this.dekCacheTimer);
                this.dekCacheTimer = null;
            }
            if (this.dekInactivityTimer) {
                clearTimeout(this.dekInactivityTimer);
                this.dekInactivityTimer = null;
            }
        }

        /**
         * Zero-knowledge: Clear DEK from memory
         */
        clearDEKCache() {
            this.cachedDEK = null;
            this.clearDEKCacheTimers();
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
