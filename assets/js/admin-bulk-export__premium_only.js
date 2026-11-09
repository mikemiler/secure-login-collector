/**
 * Bulk Export with Passkey Authentication - Premium Feature
 * @fs_premium_only
 * @package SecureLoginCollector
 */

// Defensive fallback for transition period: seculocoAdmin -> seculocoAjax
if (typeof seculocoAjax === 'undefined' && typeof seculocoAdmin !== 'undefined') {
    window.seculocoAjax = seculocoAdmin;
}

jQuery(document).ready(function ($) {
    'use strict';

    // Intercept bulk action form submission to handle passkey authentication
    $('form').on('submit', function (e) {
        var form = $(this);
        var action = form.find('select[name="action"]').val() || form.find('select[name="action2"]').val();

        // Check if this is a bulk export action
        if (action && action.indexOf('export-') === 0) {
            e.preventDefault();

            var selectedEntries = form.find('input[name="login_entries[]"]:checked');
            if (selectedEntries.length === 0) {
                alert('No entries selected for export.');
                return;
            }

            var manager = action.replace('export-', '');
            var entryIds = [];
            selectedEntries.each(function () {
                entryIds.push($(this).val());
            });

            // Always use client-side decryption workflow
            // This works for both FREE and PRO users
            // FREE entries decrypt without passkey, PRO entries require passkey
            handleBulkExportWithPasskey(entryIds, manager);
        }
    });

    // Check encryption types for selected entries and validate for undecryptable items
    async function checkEncryptionTypes(entryIds) {
        var proCount = 0;
        var freeCount = 0;
        var undecryptableCount = 0;
        var undecryptableIds = [];

        // Query each entry to determine encryption type and decryptability
        for (var i = 0; i < entryIds.length; i++) {
            try {
                var response = await $.ajax({
                    url: seculocoAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'seculoco_get_wrapped_private_key',
                        entry_id: entryIds[i],
                        nonce: seculocoAjax.nonce
                    }
                });

                if (response.success && response.data.type) {
                    if (response.data.type === 'pro') {
                        proCount++;
                        // Check if pro key is available (not deleted)
                        if (!response.data.wrapped_key || response.data.wrapped_key === '') {
                            undecryptableCount++;
                            undecryptableIds.push(entryIds[i]);
                        }
                    } else {
                        freeCount++;
                    }
                }
            } catch (error) {
                console.error('Failed to check encryption type for entry ' + entryIds[i], error);
                // Assume PRO to be safe (will prompt for passkey)
                proCount++;
            }
        }

        return {
            hasPro: proCount > 0,
            hasFree: freeCount > 0,
            counts: { pro: proCount, free: freeCount },
            total: entryIds.length,
            undecryptableCount: undecryptableCount,
            undecryptableIds: undecryptableIds
        };
    }

    // Handle bulk export with passkey authentication
    async function handleBulkExportWithPasskey(entryIds, manager) {
        // Check encryption types first
        var encryptionCheck = await checkEncryptionTypes(entryIds);

        // CLIENT-SIDE VALIDATION: Check for undecryptable entries
        if (encryptionCheck.undecryptableCount > 0) {
            var errorMessage = 'Cannot export: ' + encryptionCheck.undecryptableCount + ' selected item';
            if (encryptionCheck.undecryptableCount > 1) {
                errorMessage += 's are';
            } else {
                errorMessage += ' is';
            }
            errorMessage += ' undecryptable. ';

            if (encryptionCheck.undecryptableCount === 1) {
                errorMessage += 'This item was encrypted with a deleted passkey and cannot be recovered. ';
            } else {
                errorMessage += 'These items were encrypted with a deleted passkey and cannot be recovered. ';
            }

            errorMessage += 'Please deselect the item';
            if (encryptionCheck.undecryptableCount > 1) {
                errorMessage += 's';
            }
            errorMessage += ' marked as "Undecryptable" and try again.\n\n';
            errorMessage += 'Undecryptable entry IDs: ' + encryptionCheck.undecryptableIds.join(', ');

            // Show error modal with highlighting
            showUndecryptableErrorModal(errorMessage, encryptionCheck.undecryptableIds);
            return;
        }

        var message;
        if (!encryptionCheck.hasPro && encryptionCheck.hasFree) {
            // All FREE entries - no passkey needed
            message = 'You have selected ' + entryIds.length + ' FREE entries for bulk export. All entries will be decrypted client-side (no passkey required) and exported to ' + manager + ' format.';
        } else if (encryptionCheck.hasPro && !encryptionCheck.hasFree) {
            // All PRO entries
            message = 'You have selected ' + entryIds.length + ' PRO entries for bulk export. All entries will be decrypted using your passkey and then exported to ' + manager + ' format.';
        } else {
            // Mixed FREE and PRO
            message = 'You have selected ' + entryIds.length + ' entries (' + encryptionCheck.counts.pro + ' PRO, ' + encryptionCheck.counts.free + ' FREE) for bulk export. PRO entries will be decrypted using your passkey, FREE entries will be decrypted client-side, then exported to ' + manager + ' format.';
        }

        // Show passkey authentication modal with encryption info
        showPasskeyBulkDecryptModal({
            entry_ids: entryIds,
            entry_count: entryIds.length,
            manager: manager,
            message: message,
            encryption_check: encryptionCheck
        });
    }

    // Use the global base64ToArrayBuffer function if not already defined
    if (typeof window.seculocoBase64ToArrayBuffer === 'undefined') {
        window.seculocoBase64ToArrayBuffer = function(base64) {
            var binaryString = window.atob(base64);
            var bytes = new Uint8Array(binaryString.length);
            for (var i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        };
    }

    // Legacy alias for backwards compatibility within this file
    if (typeof window.base64ToArrayBuffer === 'undefined') {
        window.base64ToArrayBuffer = window.seculocoBase64ToArrayBuffer;
    }

    // Local reference is now global, no need for duplicate

    // Show passkey authentication modal for bulk decrypt
    function showPasskeyBulkDecryptModal(data) {
        // Remove any existing modal
        $('#bulk-passkey-modal').remove();

        var encryptionCheck = data.encryption_check || { hasPro: true, hasFree: false };

        // If all FREE entries, skip modal and proceed directly to decryption
        if (!encryptionCheck.hasPro && encryptionCheck.hasFree) {
            // All FREE - no passkey needed
            proceedWithBulkDecryption(data, null);
            return;
        }

        // Create modal for PRO or mixed entries
        var modal = $('<div id="bulk-passkey-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; display: flex; align-items: center; justify-content: center;">');
        var modalContent = $('<div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; text-align: center;">');

        var modalTitle = encryptionCheck.hasPro && encryptionCheck.hasFree
            ? '🔐 Bulk Decrypt with Mixed Encryption'
            : '🔐 ' + (window.seculocoMessages.bulkDecryptWithPasskey || 'Bulk Decrypt with Passkey');

        var buttonText = encryptionCheck.hasPro && encryptionCheck.hasFree
            ? 'Authenticate with Passkey (for ' + encryptionCheck.counts.pro + ' PRO entries)'
            : (window.seculocoMessages.authenticateWithPasskeyToDecryptAll || 'Authenticate with Passkey to Decrypt All');

        modalContent.html(
            '<h3 style="margin: 0 0 20px 0;">' + modalTitle + '</h3>' +
            '<p style="margin: 0 0 20px 0;">' + data.message + '</p>' +
            '<div id="bulk-progress-info" style="margin: 0 0 20px 0; padding: 15px; background: #f0f6fc; border-radius: 4px; display: none;">' +
            '<div style="font-weight: bold; margin-bottom: 10px; font-size: 16px;" id="bulk-progress-text">Preparing...</div>' +
            '<div style="background: #ddd; height: 8px; border-radius: 4px; overflow: hidden;">' +
            '<div id="bulk-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>' +
            '</div>' +
            '</div>' +
            '<button id="bulk-passkey-auth-btn" class="button button-primary" style="font-size: 16px; padding: 10px 20px;">' +
            buttonText +
            '</button>' +
            '<div style="margin-top: 15px;"><button id="bulk-passkey-cancel-btn" class="button">Cancel</button></div>'
        );

        modal.append(modalContent);
        $('body').append(modal);

        // Handle passkey authentication
        $('#bulk-passkey-auth-btn').on('click', function () {
            var button = $(this);
            button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Authenticating...');

            // Show progress area
            $('#bulk-progress-info').show();
            $('#bulk-progress-text').text('Authenticating with passkey...');

            authenticateWithPasskeyForBulkDecrypt(data, button);
        });

        // Handle cancel
        $('#bulk-passkey-cancel-btn').on('click', function () {
            modal.remove();
        });

        // Close on background click
        modal.on('click', function (e) {
            if (e.target === modal[0]) {
                modal.remove();
            }
        });
    }

    // Proceed with bulk decryption (with or without passkey)
    async function proceedWithBulkDecryption(data, preAuthData) {
        // Show a simple progress modal if none exists
        if ($('#bulk-passkey-modal').length === 0) {
            var modal = $('<div id="bulk-passkey-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; display: flex; align-items: center; justify-content: center;">');
            var modalContent = $('<div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; text-align: center;">');

            modalContent.html(
                '<h3 style="margin: 0 0 20px 0;">🔓 Bulk Decryption Progress</h3>' +
                '<div id="bulk-progress-info" style="margin: 0 0 20px 0; padding: 15px; background: #f0f6fc; border-radius: 4px;">' +
                '<div style="font-weight: bold; margin-bottom: 10px; font-size: 16px;" id="bulk-progress-text">Initializing...</div>' +
                '<div style="background: #ddd; height: 8px; border-radius: 4px; overflow: hidden;">' +
                '<div id="bulk-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>' +
                '</div>' +
                '</div>'
            );

            modal.append(modalContent);
            $('body').append(modal);
        }

        try {
            // Store the entry IDs and manager for processing
            const entryIds = data.entry_ids || [];
            const manager = data.manager;

            // Use current decryption framework
            const base = window.seculocoDecrypt;
            const pro = window.seculocoDecryptPro;

            let decryptedEntries = [];
            let successCount = 0;
            let failedCount = 0;
            let failedEntryIds = [];
            let skippedEntryIds = [];
            let standardKey = null; // Password-unwrapped key, reused for all FREE entries in this run
            let standardKeyError = null; // First master password failure/cancel; no further prompts in this run

            if (!base) {
                throw new Error('Decryption module not available. Please refresh the page.');
            }

            // These CSV formats have no usable notes column and their importers reject rows without
            // a password, so free-text entries (single credentials field) cannot be exported there.
            const managerNeedsLegacyFields = ['chrome', 'firefox', 'safari'].indexOf(manager) !== -1;

            const addExportRow = function (entryId, decrypted, name, website) {
                if (managerNeedsLegacyFields && !base.isLegacyFormat(decrypted)) {
                    skippedEntryIds.push(entryId);
                    return;
                }
                decryptedEntries.push({
                    name: name,
                    website: website,
                    username: decrypted.username_email || '',
                    password: decrypted.password || '',
                    notes: decrypted.additional_notes || ''
                });
            };

            $('#bulk-progress-text').text('Starting decryption...');
            $('#bulk-progress-bar').addClass('seculoco-progress-bar-width-5').css('width', '');


            let processed = 0;

            // Decrypt each entry using existing decrypt logic
            for (const entryId of entryIds) {
                processed++;
                const progressPercent = Math.round((processed / entryIds.length) * 90) + 5; // 5-95%

                try {
                    // Check if already decrypted
                    if (base.decryptedData.has(entryId)) {
                        const decrypted = base.decryptedData.get(entryId);
                        const $row = $('tr').filter(function() {
                            return $(this).find('.decrypt-btn[data-id="' + entryId + '"], .decrypt-btn-v2[data-id="' + entryId + '"]').length > 0;
                        });

                        addExportRow(
                            entryId,
                            decrypted,
                            $row.find('[data-field="name"]').text() || 'Entry ' + entryId,
                            $row.find('[data-field="login_url"]').text() || ''
                        );
                        successCount++;
                        $('#bulk-progress-text').text('Entry ' + processed + ' of ' + entryIds.length + ' (cached)');
                        $('#bulk-progress-bar').removeClass('seculoco-progress-bar-width-5 seculoco-progress-bar-width-100').css('width', progressPercent + '%');
                        continue;
                    }

                    // Get encrypted data
                    const encryptedPackage = await base.getEncryptedData(entryId);

                    // Query key info for this entry
                    const keyInfoResp = await $.ajax({
                        url: seculocoAjax.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'seculoco_get_wrapped_private_key',
                            entry_id: entryId,
                            nonce: seculocoAjax.nonce
                        }
                    });
                    if (!keyInfoResp.success || !keyInfoResp.data) {
                        throw new Error('Failed to get key info');
                    }
                    const keyType = keyInfoResp.data.type || 'free';

                    // Update progress with key type
                    $('#bulk-progress-text').text('Decrypting entry ' + processed + ' of ' + entryIds.length + ' (' + keyType.toUpperCase() + ' encryption)');
                    $('#bulk-progress-bar').removeClass('seculoco-progress-bar-width-5 seculoco-progress-bar-width-100').css('width', progressPercent + '%');

                    // Get/import private key for this entry
                    let privateKey;
                    if (keyType === 'pro') {
                        if (!preAuthData || !preAuthData.derivedKey) {
                            throw new Error('Passkey not authenticated for PRO entries.');
                        }
                        const wrappedKey = keyInfoResp.data.wrapped_key;
                        privateKey = await pro.unwrapKey(wrappedKey, preAuthData.derivedKey);
                    } else {
                        // Password-wrapped key: the server only returns the wrapped key, so unwrap it
                        // with the master password once and reuse it for the rest of the run.
                        if (standardKeyError) {
                            throw standardKeyError;
                        }
                        if (!standardKey) {
                            try {
                                standardKey = await base.getStandardPrivateKey(entryId);
                            } catch (keyError) {
                                standardKeyError = keyError;
                                throw keyError;
                            }
                        }
                        privateKey = standardKey;
                    }

                    // Decrypt the data. Not cached in base.decryptedData: only the auto-cleared
                    // single-entry view may keep plaintext in memory.
                    const decrypted = await base.decryptData(encryptedPackage, privateKey);

                    // Get metadata from table
                    const $row = $('tr').filter(function() {
                        return $(this).find('.decrypt-btn[data-id="' + entryId + '"], .decrypt-btn-v2[data-id="' + entryId + '"]').length > 0;
                    });

                    const name = $row.find('[data-field="name"]').text() ||
                                encryptedPackage.metadata?.name ||
                                'Entry ' + entryId;
                    const website = $row.find('[data-field="login_url"]').text() ||
                                   encryptedPackage.metadata?.login_url ||
                                   '';

                    // Add to results
                    addExportRow(entryId, decrypted, name, website);

                    successCount++;

                } catch (error) {
                    failedCount++;
                    failedEntryIds.push(entryId);
                    console.error('Failed to decrypt entry ' + entryId + ':', error);
                    // Continue with next entry instead of failing completely
                }
            }

            // Show results summary
            $('#bulk-passkey-modal').remove();

            if (decryptedEntries.length > 0) {
                // Generate CSV with actual decrypted data
                $('#bulk-progress-text').text('Generating CSV file...');
                $('#bulk-progress-bar').removeClass('seculoco-progress-bar-width-5').addClass('seculoco-progress-bar-width-100').css('width', '');

                const csvContent = generateCSVForManager(manager, decryptedEntries);
                const filename = 'bulk_export_' + manager + '_decrypted_' + new Date().getTime() + '.csv';
                downloadCSVFile(csvContent, filename);

                // Show success summary
                let summaryMessage = (failedCount > 0 || skippedEntryIds.length > 0)
                    ? 'Bulk export completed with issues.\n\n'
                    : 'Bulk export completed!\n\n';
                summaryMessage += 'Exported: ' + decryptedEntries.length + ' entries\n';
                if (failedCount > 0) {
                    summaryMessage += 'Failed to decrypt: ' + failedCount + ' entries\n';
                    summaryMessage += 'Failed entry IDs: ' + failedEntryIds.join(', ') + '\n';
                }
                if (skippedEntryIds.length > 0) {
                    summaryMessage += 'NOT exported: ' + skippedEntryIds.length + ' entries\n';
                    summaryMessage += 'Not exported entry IDs: ' + skippedEntryIds.join(', ') + '\n';
                }
                if (failedCount > 0 || skippedEntryIds.length > 0) {
                    summaryMessage += '\nThe CSV file contains only the exported entries.';
                    if (skippedEntryIds.length > 0) {
                        summaryMessage += ' ' + skippedFreeTextHint();
                    }
                } else {
                    summaryMessage += '\nAll entries were successfully decrypted and exported.';
                }

                alert(summaryMessage);
                

            } else if (skippedEntryIds.length > 0) {
                // Entries were decrypted but none can be represented in this format
                let skippedMessage = 'Nothing was exported.\n\n';
                skippedMessage += 'NOT exported: ' + skippedEntryIds.length + ' entries (IDs: ' + skippedEntryIds.join(', ') + ')\n';
                if (failedCount > 0) {
                    skippedMessage += 'Failed to decrypt: ' + failedCount + ' entries (IDs: ' + failedEntryIds.join(', ') + ')\n';
                }
                skippedMessage += '\n' + skippedFreeTextHint();

                alert(skippedMessage);
            } else {
                // All entries failed
                let errorMessage = 'Failed to decrypt any entries.\n\n';
                errorMessage += 'Total entries attempted: ' + entryIds.length + '\n';
                errorMessage += 'Failed entries: ' + failedCount + '\n';
                errorMessage += 'Failed entry IDs: ' + failedEntryIds.join(', ') + '\n\n';
                errorMessage += 'Please check the console for detailed error messages.';

                alert(errorMessage);
            }
        } catch (error) {
            console.error('Bulk decryption error:', error);
            alert('Bulk decryption failed: ' + error.message);
            $('#bulk-passkey-modal').remove();
        }
    }

    // Authenticate with passkey for bulk decrypt.
    // The passkey ceremony and key derivation live in the PRO decrypt plugin
    // (window.seculocoDecryptPro.authenticateWithPasskey). Only PRF-wrapped (2.1.0+) keys
    // can be unlocked; older setups get the "outdated setup" error.
    async function authenticateWithPasskeyForBulkDecrypt(data, button) {
        function resetButton() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey');
        }

        if (!window.PublicKeyCredential) {
            alert(seculocoAjax.strings.webauthn_not_supported);
            resetButton();
            return;
        }

        try {
            if (!window.seculocoDecryptPro) {
                throw new Error('Decryption module not available. Please refresh the page.');
            }

            // The Pro wrapped key is the same for every PRO entry; fetch it via the first PRO entry.
            var wrappedKey = null;
            var lastError = '';
            for (var i = 0; i < data.entry_ids.length && !wrappedKey; i++) {
                var keyInfoResp = await $.ajax({
                    url: seculocoAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'seculoco_get_wrapped_private_key',
                        entry_id: data.entry_ids[i],
                        nonce: seculocoAjax.nonce
                    }
                });
                if (keyInfoResp.success && keyInfoResp.data && keyInfoResp.data.type === 'pro' && keyInfoResp.data.wrapped_key) {
                    wrappedKey = keyInfoResp.data.wrapped_key;
                } else if (!keyInfoResp.success && typeof keyInfoResp.data === 'string') {
                    lastError = keyInfoResp.data;
                }
            }

            if (!wrappedKey) {
                throw new Error(lastError || 'No passkey-protected entry found in the selection.');
            }

            $('#bulk-progress-info').show();
            $('#bulk-progress-text').text('Authenticating with passkey...');

            // One passkey ceremony; the derived key unwraps every PRO entry in the run.
            const derivedKey = await window.seculocoDecryptPro.authenticateWithPasskey(wrappedKey);

            button.html('<span class="dashicons dashicons-admin-network spin"></span> Decrypting...');
            $('#bulk-progress-text').text('Passkey verified. Decrypting entries...');
            $('#bulk-progress-bar').addClass('seculoco-progress-bar-width-5');

            await proceedWithBulkDecryption(data, { derivedKey: derivedKey });
        } catch (error) {
            console.error('Bulk passkey authentication failed:', error);
            alert(seculocoAjax.strings.passkey_auth_failed + ' ' + (error && error.message ? error.message : ''));
            resetButton();
        }
    }

    // Handle traditional bulk export (fallback)
    function handleTraditionalBulkExport(entryIds, manager) {
        // Call AJAX endpoint directly with entry IDs and manager
        $.ajax({
            url: seculocoAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'seculoco_bulk_export',
                nonce: seculocoAjax.nonce,
                entry_ids: entryIds,
                manager: manager
            },
            success: function (response) {
                if (response.success) {
                    // Process the exported data
                    var csvData = response.data.data;
                    if (csvData && csvData.length > 0) {
                        // Generate and download CSV
                        var csvContent = generateCSVForManager(response.data.manager, csvData);
                        var filename = 'export_' + response.data.manager + '_' + new Date().getTime() + '.csv';
                        downloadCSVFile(csvContent, filename);

                        // Show completion message
                        setTimeout(function () {
                            alert('Export completed. CSV file downloaded.\n\nNote: Login credentials are encrypted and marked as "[ENCRYPTED - Decrypt client-side]".\nTo export with decrypted data, use passkey authentication.');
                        }, 500);
                    }
                } else {
                    alert('Export failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function () {
                alert('Network error occurred during export.');
            }
        });
    }

    // Explains why free-text entries were left out of a Chrome/Firefox/Safari export
    function skippedFreeTextHint() {
        return 'These entries store all login data in a single free-text field. Chrome, Firefox and Safari CSV imports need a separate username and password, so the data would be lost. Export these entries to Bitwarden, 1Password or KeePass instead (their notes field keeps the full text).';
    }

    // Generate CSV content for specific manager
    function generateCSVForManager(manager, data) {
        var csvContent = '';

        switch (manager) {
            case 'bitwarden':
                csvContent = 'folder,favorite,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp\n';
                data.forEach(function (row) {
                    csvContent += ',,1,"' + escapeCSV(row.name) + '","' + escapeCSV(row.notes) + '",,0,"' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '",\n';
                });
                break;

            case '1password':
                csvContent = 'Title,URL,Username,Password,Notes,Type\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '","' + escapeCSV(row.notes) + '",Login\n';
                });
                break;

            case 'lastpass':
                csvContent = 'url,username,password,extra,name,grouping,fav\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '","' + escapeCSV(row.notes) + '","' + escapeCSV(row.name) + '",,0\n';
                });
                break;

            case 'chrome':
                csvContent = 'name,url,username,password\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '"\n';
                });
                break;

            case 'firefox':
                csvContent = 'url,username,password,httpRealm,formActionOrigin,guid,timeCreated,timeLastUsed,timePasswordChanged\n';
                data.forEach(function (row) {
                    var timestamp = new Date().getTime();
                    csvContent += '"' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '","","' + escapeCSV(row.website) + '","{' + generateGUID() + '}",' + timestamp + ',' + timestamp + ',' + timestamp + '\n';
                });
                break;

            case 'safari':
                csvContent = 'Title,URL,Username,Password,Notes,OTPAuth\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '","' + escapeCSV(row.notes) + '",\n';
                });
                break;

            case 'dashlane':
                csvContent = 'name,url,username,password,note\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '","' + escapeCSV(row.notes) + '"\n';
                });
                break;

            case 'keepass':
                csvContent = 'Account,Login Name,Password,Web Site,Comments\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.username) + '","' + escapeCSVPassword(row.password) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.notes) + '"\n';
                });
                break;
        }

        return csvContent;
    }

    // Function to escape CSV values. Cells starting with =, +, -, @, tab or carriage return
    // are prefixed with a single quote so spreadsheet apps do not run them as formulas
    // (submitted values are client-controlled).
    function escapeCSV(value) {
        value = escapeCSVPassword(value);
        return /^[=+\-@\t\r]/.test(value) ? "'" + value : value;
    }

    // Password cells only double quotes: a password must import unchanged.
    function escapeCSVPassword(value) {
        if (typeof value !== 'string') value = '';
        return value.replace(/"/g, '""');
    }

    // Function to generate GUID for Firefox
    function generateGUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // Function to download CSV file (uses global if available)
    if (typeof window.seculocoDownloadCSVFile === 'undefined') {
        window.seculocoDownloadCSVFile = function(content, filename) {
            var blob = new Blob([content], { type: 'text/csv' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        };
    }

    // Legacy alias for backwards compatibility within this file
    if (typeof window.downloadCSVFile === 'undefined') {
        window.downloadCSVFile = window.seculocoDownloadCSVFile;
    }

    /**
     * Show error modal for undecryptable entries with visual highlighting.
     *
     * @param {string} errorMessage - The error message to display.
     * @param {array} undecryptableIds - Array of undecryptable entry IDs.
     */
    function showUndecryptableErrorModal(errorMessage, undecryptableIds) {
        // Remove any existing modal
        $('#undecryptable-error-modal').remove();

        // Highlight undecryptable rows in the table
        highlightUndecryptableRows(undecryptableIds);

        // Create error modal
        var modal = $('<div id="undecryptable-error-modal" class="seculoco-error-modal-overlay">');
        var modalContent = $('<div class="seculoco-error-modal-content">');

        modalContent.html(
            '<h3 class="seculoco-error-modal-title">⚠️ Export Validation Error</h3>' +
            '<div class="seculoco-error-modal-warning-box">' +
            '<p class="seculoco-error-modal-warning-text">' + escapeHtml(errorMessage) + '</p>' +
            '</div>' +
            '<div class="seculoco-error-modal-instructions">' +
            '<strong>What to do:</strong>' +
            '<ol class="seculoco-error-modal-instructions-list">' +
            '<li>Scroll up to see highlighted rows (marked in red)</li>' +
            '<li>Uncheck the checkbox for each undecryptable entry</li>' +
            '<li>Try the export again with only decryptable entries selected</li>' +
            '</ol>' +
            '</div>' +
            '<div class="seculoco-error-modal-button-container">' +
            '<button id="undecryptable-error-close" class="button button-primary seculoco-error-modal-close-btn">Close</button>' +
            '</div>'
        );

        modal.append(modalContent);
        $('body').append(modal);

        // Handle close button
        $('#undecryptable-error-close').on('click', function () {
            modal.remove();
        });

        // Close on background click
        modal.on('click', function (e) {
            if (e.target === modal[0]) {
                modal.remove();
            }
        });
    }

    /**
     * Highlight undecryptable rows in the admin table.
     *
     * @param {array} undecryptableIds - Array of entry IDs to highlight.
     */
    function highlightUndecryptableRows(undecryptableIds) {
        // Remove any existing highlights
        $('tr.undecryptable-highlight').removeClass('undecryptable-highlight');

        // Add highlight class to undecryptable rows
        undecryptableIds.forEach(function (entryId) {
            var checkbox = $('input[name="login_entries[]"][value="' + entryId + '"]');
            if (checkbox.length > 0) {
                var row = checkbox.closest('tr');
                row.addClass('undecryptable-highlight');
                row.addClass('undecryptable-highlight');
            }
        });

        // Add CSS animation for pulsing effect (if not already added)
        if (!$('#undecryptable-highlight-styles').length) {
            $('<style id="undecryptable-highlight-styles">' +
                '@keyframes pulse-red {' +
                '0%, 100% { background-color: #ffebee; }' +
                '50% { background-color: #ffcdd2; }' +
                '}' +
                '</style>').appendTo('head');
        }

        // Scroll to first undecryptable row
        if (undecryptableIds.length > 0) {
            var firstCheckbox = $('input[name="login_entries[]"][value="' + undecryptableIds[0] + '"]');
            if (firstCheckbox.length > 0) {
                $('html, body').animate({
                    scrollTop: firstCheckbox.closest('tr').offset().top - 100
                }, 500);
            }
        }
    }

    /**
     * Escape HTML to prevent XSS.
     *
     * @param {string} text - Text to escape.
     * @return {string} Escaped text.
     */
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

});
