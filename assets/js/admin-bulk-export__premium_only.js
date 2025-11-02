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

            // Check if this is pro version with passkey registered
            var isProVersion = window.secureLoginConfig.isProVersion;
            var passkeyRegistered = window.secureLoginConfig.passkeyRegistered;

            if (isProVersion && passkeyRegistered) {
                // Use new passkey bulk decryption workflow
                handleBulkExportWithPasskey(entryIds, manager);
            } else {
                // Fall back to traditional bulk export (without actual decryption)
                handleTraditionalBulkExport(entryIds, manager);
            }
        }
    });

    // Handle bulk export with passkey authentication
    function handleBulkExportWithPasskey(entryIds, manager) {
        // Show passkey authentication modal directly with entry IDs
        showPasskeyBulkDecryptModal({
            entry_ids: entryIds,
            entry_count: entryIds.length,
            manager: manager,
            message: 'You have selected ' + entryIds.length + ' entries for bulk export. All selected entries will be decrypted using your passkey and then exported to ' + manager + ' format.'
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

        // Create modal
        var modal = $('<div id="bulk-passkey-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; display: flex; align-items: center; justify-content: center;">');
        var modalContent = $('<div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; text-align: center;">');

        modalContent.html(
            '<h3 style="margin: 0 0 20px 0;">🔐 ' + (window.secureLoginMessages.bulkDecryptWithPasskey || 'Bulk Decrypt with Passkey') + '</h3>' +
            '<p style="margin: 0 0 20px 0;">' + data.message + '</p>' +
            '<button id="bulk-passkey-auth-btn" class="button button-primary" style="font-size: 16px; padding: 10px 20px;">' +
            (window.secureLoginMessages.authenticateWithPasskeyToDecryptAll || 'Authenticate with Passkey to Decrypt All') +
            '</button>' +
            '<div style="margin-top: 15px;"><button id="bulk-passkey-cancel-btn" class="button">Cancel</button></div>'
        );

        modal.append(modalContent);
        $('body').append(modal);

        // Handle passkey authentication
        $('#bulk-passkey-auth-btn').on('click', function () {
            var button = $(this);
            button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Authenticating...');

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

    // Authenticate with passkey for bulk decrypt
    function authenticateWithPasskeyForBulkDecrypt(data, button) {
        // Check if WebAuthn is supported
        if (!window.PublicKeyCredential) {
            alert(seculocoAjax.strings.webauthn_not_supported);
            button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey to Decrypt All');
            return;
        }

        // First, get the passkey challenge and credentials from server
        $.ajax({
            url: seculocoAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'passkey_get_challenge',
                nonce: seculocoAjax.nonce
            },
            success: function(challengeResponse) {
                if (!challengeResponse.success) {
                    alert('Failed to get passkey challenge: ' + (challengeResponse.data || 'Unknown error'));
                    button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey to Decrypt All');
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
                        // After successful passkey authentication, perform client-side bulk decryption
                        try {
                            button.html('<span class="dashicons dashicons-update-alt spin"></span> Initializing...');

                            // Store the entry IDs and manager for processing
                            const entryIds = data.entry_ids || [];
                            const manager = data.manager;

                            // Use existing SecureAdminDecryption for bulk decryption
                            let decryptedEntries = [];

                            // Check if SecureAdminDecryption is available
                            if (!window.secureAdminDecryption && window.SecureAdminDecryption) {
                                window.secureAdminDecryption = new SecureAdminDecryption();
                            }

                            if (!window.secureAdminDecryption) {
                                throw new Error('Decryption module not available. Please refresh the page.');
                            }

                            const decryptor = window.secureAdminDecryption;

                            // Derive the unwrapping key from the assertion ONCE
                            button.html('<span class="dashicons dashicons-admin-network spin"></span> Deriving key...');
                            const derivedKey = await decryptor.deriveUnwrappingKeyFromAssertion(assertion);
                            const preAuthData = { derivedKey: derivedKey };

                            let processed = 0;

                            // Decrypt each entry using existing decrypt logic
                            for (const entryId of entryIds) {
                                processed++;
                                button.html('<span class="dashicons dashicons-unlock spin"></span> ' + processed + '/' + entryIds.length);

                                try {
                                    // Check if already decrypted
                                    if (decryptor.decryptedData.has(entryId)) {
                                        const decrypted = decryptor.decryptedData.get(entryId);
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
                                        continue;
                                    }

                                    // Get encrypted data
                                    const encryptedPackage = await decryptor.getEncryptedData(entryId);

                                    // Get key type
                                    const keyInfo = await decryptor.getKeyType(entryId);
                                    const keyType = keyInfo.type;

                                    // Unwrap key if needed (once per key type) - pass preAuthData to avoid re-authentication
                                    if (!decryptor.unwrappedKeys[keyType]) {
                                        await decryptor.unwrapPrivateKey(entryId, keyType, preAuthData);
                                    }

                                    const privateKey = decryptor.unwrappedKeys[keyType];

                                    // Decrypt the data
                                    const decrypted = await decryptor.decryptData(encryptedPackage, privateKey);

                                    // Store in cache
                                    decryptor.decryptedData.set(entryId, decrypted);

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

                                } catch (error) {
                                    console.error('Failed to decrypt entry ' + entryId + ':', error);
                                }
                            }

                            if (decryptedEntries.length > 0) {
                                $('#bulk-passkey-modal').remove();

                                // Generate CSV with actual decrypted data
                                const csvContent = generateCSVForManager(manager, decryptedEntries);
                                const filename = 'bulk_export_' + manager + '_decrypted_' + new Date().getTime() + '.csv';
                                downloadCSVFile(csvContent, filename);


                            } else {
                                alert('Failed to decrypt any entries.');
                                button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey to Decrypt All');
                            }
                        } catch (error) {
                            console.error('Bulk decryption error:', error);
                            alert('Bulk decryption failed: ' + error.message);
                            button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey to Decrypt All');
                        }
                    })
                    .catch((err) => {
                        console.error('Bulk passkey authentication failed:', err);
                        alert(seculocoAjax.strings.passkey_auth_failed + ' ' + err.message);
                        button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey to Decrypt All');
                    });
            },
            error: function(xhr, status, error) {
                console.error('Failed to get passkey challenge:', error);
                alert('Failed to get passkey challenge. Please try again.');
                button.prop('disabled', false).html('<span class="dashicons dashicons-shield-alt"></span> Authenticate with Passkey to Decrypt All');
            }
        });
    }

    // Handle traditional bulk export (fallback)
    function handleTraditionalBulkExport(entryIds, manager) {
        // First, submit the form to create the transient
        var form = $('#posts-filter');
        form.off('submit'); // Remove our handler temporarily

        // Submit form via AJAX to set up the transient
        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: form.serialize(),
            success: function() {
                // Now call the process_bulk_export AJAX action
                $.ajax({
                    url: seculocoAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'seculoco_bulk_export',
                        nonce: seculocoAjax.nonce
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
                                    alert('Export completed. CSV file downloaded.\n\nNote: Login credentials are encrypted and marked as "[ENCRYPTED - Decrypt client-side]".\nTo export with decrypted data, decrypt individual entries first.');
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
            },
            error: function() {
                alert('Failed to initiate export.');
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
