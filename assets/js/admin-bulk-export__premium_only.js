/**
 * Bulk Export with Passkey Authentication - Premium Feature
 * @fs_premium_only
 * @package SecureLoginCollector
 */

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

    // Check encryption types for selected entries
    async function checkEncryptionTypes(entryIds) {
        var proCount = 0;
        var freeCount = 0;

        // Query each entry to determine encryption type
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
            total: entryIds.length
        };
    }

    // Handle bulk export with passkey authentication
    async function handleBulkExportWithPasskey(entryIds, manager) {
        // Check encryption types first
        var encryptionCheck = await checkEncryptionTypes(entryIds);

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
    if (typeof window.base64ToArrayBuffer === 'undefined') {
        window.base64ToArrayBuffer = function(base64) {
            var binaryString = window.atob(base64);
            var bytes = new Uint8Array(binaryString.length);
            for (var i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        };
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
            console.log('All entries are FREE encrypted - skipping passkey authentication');
            proceedWithBulkDecryption(data, null);
            return;
        }

        // Create modal for PRO or mixed entries
        var modal = $('<div id="bulk-passkey-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; display: flex; align-items: center; justify-content: center;">');
        var modalContent = $('<div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; text-align: center;">');

        var modalTitle = encryptionCheck.hasPro && encryptionCheck.hasFree
            ? '🔐 Bulk Decrypt with Mixed Encryption'
            : '🔐 ' + (window.secureLoginMessages.bulkDecryptWithPasskey || 'Bulk Decrypt with Passkey');

        var buttonText = encryptionCheck.hasPro && encryptionCheck.hasFree
            ? 'Authenticate with Passkey (for ' + encryptionCheck.counts.pro + ' PRO entries)'
            : (window.secureLoginMessages.authenticateWithPasskeyToDecryptAll || 'Authenticate with Passkey to Decrypt All');

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

            if (!base) {
                throw new Error('Decryption module not available. Please refresh the page.');
            }

            $('#bulk-progress-text').text('Starting decryption...');
            $('#bulk-progress-bar').css('width', '5%');

            console.log('Starting bulk decryption for ' + entryIds.length + ' entries');

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

                        decryptedEntries.push({
                            name: $row.find('[data-field="name"]').text() || 'Entry ' + entryId,
                            website: $row.find('[data-field="login_url"]').text() || '',
                            username: decrypted.username_email || '',
                            password: decrypted.password || '',
                            notes: decrypted.additional_notes || ''
                        });
                        successCount++;
                        $('#bulk-progress-text').text('Entry ' + processed + ' of ' + entryIds.length + ' (cached)');
                        $('#bulk-progress-bar').css('width', progressPercent + '%');
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
                    $('#bulk-progress-bar').css('width', progressPercent + '%');

                    // Get/import private key for this entry
                    let privateKey;
                    if (keyType === 'pro') {
                        if (!preAuthData || !preAuthData.derivedKey) {
                            throw new Error('Passkey not authenticated for PRO entries.');
                        }
                        const wrappedKey = keyInfoResp.data.wrapped_key;
                        privateKey = await pro.unwrapKey(wrappedKey, preAuthData.derivedKey);
                    } else {
                        const privateKeyB64 = keyInfoResp.data.private_key;
                        const privateKeyPem = atob(privateKeyB64);
                        privateKey = await base.importRSAPrivateKey(privateKeyPem);
                    }

                    // Decrypt the data
                    const decrypted = await base.decryptData(encryptedPackage, privateKey);

                    // Store in cache
                    base.decryptedData.set(entryId, decrypted);

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
                    decryptedEntries.push({
                        name: name,
                        website: website,
                        username: decrypted.username_email || '',
                        password: decrypted.password || '',
                        notes: decrypted.additional_notes || ''
                    });

                    successCount++;
                    console.log('Successfully decrypted entry ' + entryId + ' (' + successCount + ' of ' + entryIds.length + ')');

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
                $('#bulk-progress-bar').css('width', '100%');

                const csvContent = generateCSVForManager(manager, decryptedEntries);
                const filename = 'bulk_export_' + manager + '_decrypted_' + new Date().getTime() + '.csv';
                downloadCSVFile(csvContent, filename);

                // Show success summary
                let summaryMessage = 'Bulk export completed!\n\n';
                summaryMessage += 'Successfully decrypted: ' + successCount + ' entries\n';
                if (failedCount > 0) {
                    summaryMessage += 'Failed to decrypt: ' + failedCount + ' entries\n';
                    summaryMessage += 'Failed entry IDs: ' + failedEntryIds.join(', ') + '\n\n';
                    summaryMessage += 'The CSV file contains only successfully decrypted entries.';
                } else {
                    summaryMessage += '\nAll entries were successfully decrypted and exported.';
                }

                alert(summaryMessage);
                console.log('Bulk export summary:', {
                    total: entryIds.length,
                    success: successCount,
                    failed: failedCount,
                    failedIds: failedEntryIds
                });

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

    // Authenticate with passkey for bulk decrypt
    function authenticateWithPasskeyForBulkDecrypt(data, button) {
        // Check if WebAuthn is supported
        if (!window.PublicKeyCredential) {
            alert(seculocoAjax.strings.webauthn_not_supported);
            button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey');
            return;
        }

        // First, get the passkey challenge and credentials from server
        $.ajax({
            url: (typeof secureLoginPasskeyData !== 'undefined' ? secureLoginPasskeyData.ajaxUrl : (typeof seculocoAdmin !== 'undefined' ? seculocoAdmin.ajaxurl : seculocoAjax.ajaxurl)),
            type: 'POST',
            data: {
                action: 'seculoco_passkey_challenge',
                // This endpoint accepts seculoco_admin_nonce or seculoco_nonce
                nonce: (typeof seculocoAdmin !== 'undefined' ? seculocoAdmin.nonce : seculocoAjax.nonce)
            },
            success: function(challengeResponse) {
                if (!challengeResponse.success) {
                    alert('Failed to get passkey challenge: ' + (challengeResponse.data || 'Unknown error'));
                    button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey');
                    return;
                }

                // Convert base64 challenge to ArrayBuffer
                var challengeData = challengeResponse.data.challenge;
                var challenge = window.base64ToArrayBuffer(challengeData);

                // Prepare credentials array
                var allowCredentials = [];
                if (challengeResponse.data.credentials && challengeResponse.data.credentials.length > 0) {
                    allowCredentials = challengeResponse.data.credentials.map(function(cred) {
                        return {
                            type: 'public-key',
                            id: window.base64ToArrayBuffer(cred.id)
                        };
                    });
                }

                var getCredentialDefaultArgs = {
                    publicKey: {
                        timeout: 60000,
                        challenge: challenge,
                        allowCredentials: allowCredentials,
                        userVerification: "required"
                    },
                };

                navigator.credentials.get(getCredentialDefaultArgs)
                    .then(async (assertion) => {
                        // After successful passkey authentication, derive key and proceed
                        try {
                            button.html('<span class="dashicons dashicons-admin-network spin"></span> Deriving key...');
                            $('#bulk-progress-info').show();
                            $('#bulk-progress-text').text('Deriving encryption key from passkey...');
                            $('#bulk-progress-bar').css('width', '5%');

                            if (!window.seculocoDecryptPro) {
                                throw new Error('Decryption module not available. Please refresh the page.');
                            }

                            // Derive the unwrapping key from the assertion ONCE using PRO plugin
                            const derivedKey = await window.seculocoDecryptPro.deriveUnwrappingKey(assertion);

                            // Create preAuthData object to pass to bulk decryption
                            const preAuthData = { derivedKey: derivedKey };

                            console.log('Derived unwrapping key successfully for bulk decryption');

                            // Proceed with bulk decryption
                            await proceedWithBulkDecryption(data, preAuthData);

                        } catch (error) {
                            console.error('Passkey authentication error:', error);
                            alert('Authentication failed: ' + error.message);
                            button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey');
                        }
                    })
                    .catch((err) => {
                        console.error('Bulk passkey authentication failed:', err);
                        alert(seculocoAjax.strings.passkey_auth_failed + ' ' + err.message);
                        button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey');
                    });
            },
            error: function(xhr, status, error) {
                console.error('Failed to get passkey challenge:', error);
                alert('Failed to get passkey challenge. Please try again.');
                button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey');
            }
        });
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

    // Generate CSV content for specific manager
    function generateCSVForManager(manager, data) {
        var csvContent = '';

        switch (manager) {
            case 'bitwarden':
                csvContent = 'folder,favorite,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp\n';
                data.forEach(function (row) {
                    csvContent += ',,1,"' + escapeCSV(row.name) + '","' + escapeCSV(row.notes) + '",,0,"' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '",\n';
                });
                break;

            case '1password':
                csvContent = 'Title,URL,Username,Password,Notes,Type\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '","' + escapeCSV(row.notes) + '",Login\n';
                });
                break;

            case 'lastpass':
                csvContent = 'url,username,password,extra,name,grouping,fav\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '","' + escapeCSV(row.notes) + '","' + escapeCSV(row.name) + '",,0\n';
                });
                break;

            case 'chrome':
                csvContent = 'name,url,username,password\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '"\n';
                });
                break;

            case 'firefox':
                csvContent = 'url,username,password,httpRealm,formActionOrigin,guid,timeCreated,timeLastUsed,timePasswordChanged\n';
                data.forEach(function (row) {
                    var timestamp = new Date().getTime();
                    csvContent += '"' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '","","' + escapeCSV(row.website) + '","{' + generateGUID() + '}",' + timestamp + ',' + timestamp + ',' + timestamp + '\n';
                });
                break;

            case 'safari':
                csvContent = 'Title,URL,Username,Password,Notes,OTPAuth\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '","' + escapeCSV(row.notes) + '",\n';
                });
                break;

            case 'dashlane':
                csvContent = 'name,url,username,password,note\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '","' + escapeCSV(row.notes) + '"\n';
                });
                break;

            case 'keepass':
                csvContent = 'Account,Login Name,Password,Web Site,Comments\n';
                data.forEach(function (row) {
                    csvContent += '"' + escapeCSV(row.name) + '","' + escapeCSV(row.username) + '","' + escapeCSV(row.password) + '","' + escapeCSV(row.website) + '","' + escapeCSV(row.notes) + '"\n';
                });
                break;
        }

        return csvContent;
    }

    // Function to escape CSV values
    function escapeCSV(value) {
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
    if (typeof window.downloadCSVFile === 'undefined') {
        window.downloadCSVFile = function(content, filename) {
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

});
