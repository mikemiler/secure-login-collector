jQuery(document).ready(function ($) {

    // Format decrypted data for better readability.
    function formatDecryptedData(data, metadata) {
        var html = '<div class="formatted-data">';

        // Login URL from metadata
        var loginUrl = metadata && metadata.login_url ? metadata.login_url : '';
        if (loginUrl && loginUrl !== secureLoginAjax.strings.not_provided) {
            html += '<div class="data-field">';
            html += '<strong>Login URL:</strong>';
            html += '<div class="field-row">';
            html += '<button type="button" class="button button-primary copy-field-btn" onclick="copyFieldData(this, \'' + escapeHtml(loginUrl) + '\')">Copy</button>';
            html += '<span class="field-value">' + escapeHtml(loginUrl) + '</span>';
            html += '</div>';
            html += '</div>';
        }

        // Username/Email
        if (data.username_email) {
            html += '<div class="data-field">';
            html += '<strong>Username/Email:</strong>';
            html += '<div class="field-row">';
            html += '<button type="button" class="button button-primary copy-field-btn" onclick="copyFieldData(this, \'' + escapeHtml(data.username_email) + '\')">Copy</button>';
            html += '<span class="field-value">' + escapeHtml(data.username_email) + '</span>';
            html += '</div>';
            html += '</div>';
        }

        // Password
        if (data.password) {
            html += '<div class="data-field">';
            html += '<strong>Password:</strong>';
            html += '<div class="field-row">';
            html += '<button type="button" class="button button-primary copy-field-btn" onclick="copyFieldData(this, \'' + escapeHtml(data.password) + '\')">Copy</button>';
            html += '<span class="field-value password-content">' + escapeHtml(data.password) + '</span>';
            html += '</div>';
            html += '</div>';
        }

        // Additional Notes (if provided)
        if (data.additional_notes && data.additional_notes.trim() !== '') {
            html += '<div class="data-field">';
            html += '<strong>Additional Notes:</strong>';
            html += '<div class="field-row">';
            html += '<button type="button" class="button button-primary copy-field-btn" onclick="copyFieldData(this, \'' + escapeHtml(data.additional_notes) + '\')">Copy</button>';
            html += '<span class="field-value notes-content">' + escapeHtml(data.additional_notes).replace(/\n/g, '<br>') + '</span>';
            html += '</div>';
            html += '</div>';
        }

        html += '</div>';

        return html;
    }

    // Escape HTML to prevent XSS.
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    // Edit functionality - FIXED: trim whitespace from field values
    $('.edit-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');
        var row = button.closest('tr');

        // Hide edit button, show save/cancel buttons
        button.hide();
        row.find('.save-btn, .cancel-btn').show();

        // Make fields editable
        row.find('.editable-field').each(function () {
            var field = $(this);
            var currentValue = field.text().trim(); // FIXED: Added .trim() to remove extra whitespace
            var input = $('<input type="text" class="edit-input" value="' + escapeHtml(currentValue) + '">');
            field.addClass('editing').html(input);
        });
    });

    // Cancel edit
    $('.cancel-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');
        var row = button.closest('tr');

        // Restore original values and hide save/cancel buttons
        row.find('.editable-field').each(function () {
            var field = $(this);
            var originalValue = field.find('.edit-input').val();
            field.removeClass('editing').text(originalValue);
        });

        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
    });

    // Save edit
    $('.save-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');
        var row = button.closest('tr');

        // Collect new values
        var newData = {};
        row.find('.editable-field').each(function () {
            var field = $(this);
            var fieldName = field.data('field');
            var newValue = field.find('.edit-input').val().trim(); // FIXED: Added .trim()
            newData[fieldName] = newValue;
        });

        button.prop('disabled', true);

        $.ajax({
            url: secureLoginAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'update_secure_login_metadata',
                update_id: id,
                metadata: newData,
                nonce: secureLoginAjax.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Update display with new values
                    row.find('.editable-field').each(function () {
                        var field = $(this);
                        var fieldName = field.data('field');
                        field.removeClass('editing').text(newData[fieldName]);
                    });

                    row.find('.save-btn, .cancel-btn').hide();
                    row.find('.edit-btn').show();
                } else {
                    alert(secureLoginAjax.strings.save_failed + (response.data || secureLoginAjax.strings.unknown_error));
                }
                button.prop('disabled', false);
            },
            error: function () {
                alert(secureLoginAjax.strings.network_error_save);
                button.prop('disabled', false);
            }
        });
    });

    // Decrypt functionality
    $('.decrypt-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');
        var hostname = button.data('hostname');
        var timestamp = button.data('timestamp');
        var encryptionType = button.data('encryption-type');
        var decryptedRow = $('#decrypted-row-' + id);

        // Check if this is pro version and passkey is available
        var isProVersion = window.secureLoginConfig.isProVersion;
        var passkeyRegistered = window.secureLoginConfig.passkeyRegistered;

        // SECURITY ENHANCEMENT: Pro version with passkey MUST use passkey authentication
        if (isProVersion && passkeyRegistered) {
            // Force passkey authentication - no choice given
            button.prop('disabled', true).attr('title', secureLoginAjax.strings.requesting_passkey);

            $.ajax({
                url: secureLoginAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'decrypt_secure_login_data',
                    decrypt_id: id,
                    use_passkey: 'true',
                    nonce: secureLoginAjax.nonce
                },
                success: function (response) {
                    if (response.success && response.data.requires_passkey) {
                        // Initiate passkey authentication
                        authenticateWithPasskey(id, button, decryptedRow);
                    } else {
                        alert(secureLoginAjax.strings.passkey_setup_failed + (response.data || secureLoginAjax.strings.unknown_error));
                        button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
                    }
                },
                error: function () {
                    alert(secureLoginAjax.strings.network_error_passkey);
                    button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
                }
            });
            return;
        }

        // For pro version without passkey or non-pro version, offer choice
        if (isProVersion && !passkeyRegistered) {
            // Pro version but no passkey registered - warn user and suggest registering passkey
            if (!confirm(secureLoginAjax.strings.pro_no_passkey_continue)) {
                return;
            }
        }

        // Traditional decryption (for non-pro or pro without passkey registered)
        try {
            var encryptionKey = '';

            // Check encryption type (RSA for all new entries, XOR only for legacy data)
            if (encryptionType === 'rsa') {
                // RSA encryption - no manual key needed, server handles decryption
                encryptionKey = 'rsa'; // Just a placeholder to indicate RSA
            } else if (hostname && timestamp) {
                // Legacy XOR data - reconstruct key for backward compatibility
                encryptionKey = hostname + timestamp;
            } else {
                // Very old legacy data - prompt user for manual key entry
                encryptionKey = prompt(secureLoginAjax.strings.enter_encryption_key);
                if (!encryptionKey) {
                    return;
                }
            }

            // Disable button and show loading.
            button.prop('disabled', true).attr('title', secureLoginAjax.strings.decrypting);

            // Make AJAX request to decrypt data.
            $.ajax({
                url: secureLoginAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'decrypt_secure_login_data',
                    decrypt_id: id,
                    encryption_key: encryptionKey,
                    nonce: secureLoginAjax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Store the decrypted data for export functionality
                        decryptedRow.data('decrypted-data', response.data.data || response.data);

                        // Format the data for better readability.
                        var formattedData = formatDecryptedData(response.data.data || response.data, response.data.metadata);
                        decryptedRow.find('.decrypted-json').html(formattedData);
                        decryptedRow.show();
                        button.attr('title', secureLoginAjax.strings.data_decrypted).addClass('button-secondary');
                        button.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-yes');
                    } else {
                        alert(secureLoginAjax.strings.decryption_failed + (response.data || secureLoginAjax.strings.unknown_error));
                        button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
                    }
                },
                error: function () {
                    alert(secureLoginAjax.strings.network_error_decryption);
                    button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
                }
            });

        } catch (e) {
            alert(secureLoginAjax.strings.error_processing_decryption + e.message);
        }
    });

    // Passkey authentication function
    function authenticateWithPasskey(id, button, decryptedRow) {
        button.attr('title', secureLoginAjax.strings.authenticate_with_passkey);

        // Check if WebAuthn is supported
        if (!window.PublicKeyCredential) {
            alert(secureLoginAjax.strings.webauthn_not_supported);
            button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
            return;
        }

        // Generate challenge
        var challenge = new Uint8Array(32);
        window.crypto.getRandomValues(challenge);

        var getCredentialDefaultArgs = {
            publicKey: {
                timeout: 60000,
                challenge: challenge,
                userVerification: "required"
            },
        };

        navigator.credentials.get(getCredentialDefaultArgs)
            .then((assertion) => {
                // Send authentication data to server for passkey verification and decryption
                button.attr('title', secureLoginAjax.strings.verifying_passkey);

                $.ajax({
                    url: secureLoginAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'decrypt_secure_login_data',
                        decrypt_id: id,
                        use_passkey: 'true',
                        passkey_verified: 'true', // Flag that passkey auth was successful
                        signature: btoa(String.fromCharCode(...new Uint8Array(assertion.response.signature))),
                        authenticator_data: btoa(String.fromCharCode(...new Uint8Array(assertion.response.authenticatorData))),
                        nonce: secureLoginAjax.nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            // Store the decrypted data for export functionality
                            decryptedRow.data('decrypted-data', response.data.data || response.data);

                            // Format the data for better readability.
                            var formattedData = formatDecryptedData(response.data.data || response.data, response.data.metadata);
                            decryptedRow.find('.decrypted-json').html(formattedData);
                            decryptedRow.show();
                            button.attr('title', secureLoginAjax.strings.data_decrypted_passkey).addClass('button-secondary');
                            button.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-yes');
                        } else {
                            alert(secureLoginAjax.strings.passkey_decryption_failed + (response.data || secureLoginAjax.strings.unknown_error));
                            button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
                        }
                    },
                    error: function () {
                        alert(secureLoginAjax.strings.network_error_passkey_decrypt);
                        button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
                    }
                });
            })
            .catch((err) => {
                console.error(secureLoginAjax.strings.passkey_auth_failed, err);
                alert(secureLoginAjax.strings.passkey_auth_failed + ' ' + err.message);
                button.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data);
            });
    }

    // Hide decrypted data
    $('.hide-decrypted').on('click', function () {
        var button = $(this);
        var id = button.data('id');
        var decryptedRow = $('#decrypted-row-' + id);
        var decryptBtn = $('.decrypt-btn[data-id="' + id + '"]');

        // Clear stored decrypted data for security
        decryptedRow.removeData('decrypted-data');

        decryptedRow.hide();
        decryptBtn.prop('disabled', false).attr('title', secureLoginAjax.strings.decrypt_data).removeClass('button-secondary');
        decryptBtn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-unlock');
    });

    // Extend functionality
    $('.extend-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');

        if (!confirm(secureLoginAjax.strings.confirm_extend_retention)) {
            return;
        }

        button.prop('disabled', true).text(secureLoginAjax.strings.extending);

        $.ajax({
            url: secureLoginAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'extend_secure_login_data',
                extend_id: id,
                nonce: secureLoginAjax.nonce
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message || secureLoginAjax.strings.retention_extended);
                    // Refresh page to show updated expiration
                    location.reload();
                } else {
                    alert('Extend failed: ' + (response.data || 'Unknown error'));
                }
                button.prop('disabled', false).text('Extend');
            },
            error: function () {
                alert('Network error occurred during extension.');
                button.prop('disabled', false).text('Extend');
            }
        });
    });

    // Delete functionality
    $('.delete-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');

        if (!confirm('Are you sure you want to delete this login data?')) {
            return;
        }

        button.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: secureLoginAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_secure_login_data',
                delete_id: id,
                nonce: secureLoginAjax.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Refresh page to show updated list
                    location.reload();
                } else {
                    alert('Delete failed: ' + (response.data || 'Unknown error'));
                    button.prop('disabled', false).text('Delete');
                }
            },
            error: function () {
                alert('Network error occurred during deletion.');
                button.prop('disabled', false).text('Delete');
            }
        });
    });

    // Password Manager Export functionality
    $(document).on('click', '.export-to-password-manager', function () {
        var button = $(this);
        var id = button.data('id');
        var decryptedRow = $('#decrypted-row-' + id);

        // Get the decrypted data from the current row
        var decryptedData = decryptedRow.data('decrypted-data');
        if (!decryptedData) {
            alert(window.secureLoginMessages.noDecryptedData);
            return;
        }

        // Get metadata from the table row
        var tableRow = button.closest('.decrypted-data-row').prev('tr');
        var email = tableRow.find('[data-field="email"]').text().trim();
        var name = tableRow.find('[data-field="name"]').text().trim();
        var loginUrl = tableRow.find('[data-field="login_url"]').text().trim();

        // Directly trigger the streamlined password manager modal
        triggerPasswordManagerSave(btoa(JSON.stringify(decryptedData)), btoa(JSON.stringify({
            email: email,
            name: name,
            login_url: loginUrl
        })));
    });

    // Modal functionality for manual entry
    $('#add-new-entry-btn').on('click', function () {
        $('#add-new-entry-modal').show();
    });

    $('.close-modal, #cancel-manual-entry').on('click', function () {
        $('#add-new-entry-modal').hide();
        $('#manual-add-form')[0].reset();
    });

    // Close modal when clicking outside
    $(window).on('click', function (event) {
        if (event.target.id === 'add-new-entry-modal') {
            $('#add-new-entry-modal').hide();
            $('#manual-add-form')[0].reset();
        }
    });

    // Handle manual entry form submission
    $('#manual-add-form').on('submit', function (e) {
        e.preventDefault();

        var form = $(this);
        var submitBtn = $('#save-manual-entry');

        // Get form data
        var email = $('#manual_email').val().trim();
        var name = $('#manual_name').val().trim();
        var loginUrl = $('#manual_login_url').val().trim();
        var usernameEmail = $('#manual_username_email').val().trim();
        var password = $('#manual_password').val().trim();
        var additionalNotes = $('#manual_additional_notes').val().trim();
        var encryptionMethod = $('#manual_encryption_method').val();

        // Validate required fields
        if (!email || !name || !loginUrl || !usernameEmail || !password) {
            alert(window.secureLoginMessages.fillAllFields);
            return;
        }

        // Disable submit button and show loading
        submitBtn.prop('disabled', true);

        // Prepare the login data to be encrypted
        var loginData = JSON.stringify({
            username_email: usernameEmail,
            password: password,
            additional_notes: additionalNotes,
            timestamp: new Date().toISOString()
        });

        // For manual entries, we'll use server-side encryption
        var metadata = {
            email: email,
            name: name,
            login_url: loginUrl,
            created_at: new Date().toISOString(),
            manually_added: true,
            added_by_user: window.secureLoginConfig.currentUserId
        };

        // Submit to server for encryption and storage
        $.ajax({
            url: secureLoginAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_manual_login_data',
                login_data: loginData,
                metadata: JSON.stringify(metadata),
                encryption_method: encryptionMethod,
                nonce: secureLoginAjax.nonce
            },
            success: function (response) {
                if (response.success) {
                    alert(window.secureLoginMessages.dataSavedSuccess);
                    $('#add-new-entry-modal').hide();
                    $('#manual-add-form')[0].reset();
                    location.reload(); // Refresh to show new entry
                } else {
                    alert(window.secureLoginMessages.errorSavingData + (response.data || window.secureLoginMessages.unknownError));
                }
            },
            error: function () {
                alert(window.secureLoginMessages.networkError);
            },
            complete: function () {
                submitBtn.prop('disabled', false).text(window.secureLoginMessages.saveEntry);
            }
        });
    });

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
        // Show confirmation modal
        var managerName = getManagerDisplayName(manager);
        var message = 'You have selected ' + entryIds.length + ' entries for bulk export to ' + managerName + '.\n\n';
        message += 'All selected entries will be decrypted using your passkey and then exported.\n\n';
        message += 'Continue?';

        if (!confirm(message)) {
            return;
        }

        // Start the bulk decrypt process
        $.ajax({
            url: secureLoginAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bulk_decrypt_with_passkey',
                entry_ids: entryIds,
                manager: manager,
                nonce: secureLoginAjax.nonce
            },
            success: function (response) {
                if (response.success && response.data.requires_passkey) {
                    // Show passkey authentication modal
                    showPasskeyBulkDecryptModal(response.data);
                } else if (response.success) {
                    // Direct success (shouldn't happen in first call)
                    processBulkDecryptedData(response.data);
                } else {
                    alert('Bulk export setup failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function () {
                alert('Network error occurred during bulk export setup.');
            }
        });
    }

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
            button.prop('disabled', true).text('Authenticating and decrypting ' + data.entry_count + ' entries...');

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
            alert(secureLoginAjax.strings.webauthn_not_supported);
            button.prop('disabled', false).text('Authenticate with Passkey to Decrypt All');
            return;
        }

        // Generate challenge
        var challenge = new Uint8Array(32);
        window.crypto.getRandomValues(challenge);

        var getCredentialDefaultArgs = {
            publicKey: {
                timeout: 60000,
                challenge: challenge,
                userVerification: "required"
            },
        };

        navigator.credentials.get(getCredentialDefaultArgs)
            .then((assertion) => {
                // Send authentication data to server for bulk decryption
                console.log('Sending bulk passkey authentication request...');

                var requestData = {
                    action: 'bulk_decrypt_with_passkey',
                    entry_ids: [], // Will be retrieved from transient
                    manager: data.manager,
                    passkey_verified: 'true',
                    nonce: secureLoginAjax.nonce
                };

                // Only add signature data if assertion is valid
                if (assertion && assertion.response) {
                    try {
                        requestData.signature = btoa(String.fromCharCode(...new Uint8Array(assertion.response.signature)));
                        requestData.authenticator_data = btoa(String.fromCharCode(...new Uint8Array(assertion.response.authenticatorData)));
                    } catch (e) {
                        console.error('Error encoding passkey data:', e);
                        alert('Error encoding passkey data: ' + e.message);
                        button.prop('disabled', false).text('Authenticate with Passkey to Decrypt All');
                        return;
                    }
                }

                $.ajax({
                    url: secureLoginAjax.ajaxurl,
                    type: 'POST',
                    data: requestData,
                    success: function (response) {
                        console.log('Bulk passkey authentication response:', response);
                        if (response.success) {
                            $('#bulk-passkey-modal').remove();
                            processBulkDecryptedData(response.data);
                        } else {
                            alert('Bulk passkey decryption failed: ' + (response.data || 'Unknown error'));
                            button.prop('disabled', false).text('Authenticate with Passkey to Decrypt All');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX error during bulk passkey decryption:', {
                            status: status,
                            error: error,
                            responseText: xhr.responseText,
                            xhr: xhr
                        });
                        alert('Network error occurred during bulk passkey decryption. Check console for details.');
                        button.prop('disabled', false).text('Authenticate with Passkey to Decrypt All');
                    }
                });
            })
            .catch((err) => {
                console.error('Bulk passkey authentication failed:', err);
                alert(secureLoginAjax.strings.passkey_auth_failed + ' ' + err.message);
                button.prop('disabled', false).text('Authenticate with Passkey to Decrypt All');
            });
    }

    // Process bulk decrypted data and generate CSV
    function processBulkDecryptedData(data) {
        alert(data.message);

        if (data.csv_data && data.csv_data.length > 0) {
            // Generate and download CSV
            var csvContent = generateCSVForManager(data.manager, data.csv_data);
            var filename = 'bulk_export_' + data.manager + '_' + new Date().getTime() + '.csv';
            downloadCSVFile(csvContent, filename);

            // Show completion message
            setTimeout(function () {
                alert(window.secureLoginMessages.bulkDecryptionCompleted || 'Bulk decryption completed. CSV file downloaded.');
            }, 500);
        }
    }

    // Handle traditional bulk export (fallback)
    function handleTraditionalBulkExport(entryIds, manager) {
        alert('Traditional bulk export is not implemented. Please decrypt entries individually first.');
    }

    // Get manager display name
    function getManagerDisplayName(manager) {
        var names = {
            'bitwarden': 'Bitwarden',
            '1password': '1Password',
            'lastpass': 'LastPass',
            'chrome': 'Chrome',
            'firefox': 'Firefox',
            'safari': 'Safari',
            'dashlane': 'Dashlane',
            'keepass': 'KeePass'
        };
        return names[manager] || manager;
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
});

// Global functions for password manager export (must be outside jQuery ready)
function copyLoginData(button) {
    var loginData = button.getAttribute('data-login');
    navigator.clipboard.writeText(loginData).then(function () {
        var originalText = button.textContent;
        button.textContent = 'Copied!';
        setTimeout(function () {
            button.textContent = originalText;
        }, 2000);
    }).catch(function () {
        alert('Failed to copy to clipboard');
    });
}

// Function to copy individual field data
function copyFieldData(button, data) {
    // Try modern clipboard API first
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(data).then(function () {
            showCopyButtonFeedback(button);
        }).catch(function () {
            // Fallback to creating temporary input
            copyToClipboardFallback(data);
            showCopyButtonFeedback(button);
        });
    } else {
        // Fallback for older browsers
        copyToClipboardFallback(data);
        showCopyButtonFeedback(button);
    }
}

// Fallback copy function
function copyToClipboardFallback(text) {
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.opacity = '0';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
    } catch (err) {
        console.error('Failed to copy to clipboard:', err);
    }
    document.body.removeChild(textArea);
}

// Function to show copy button feedback
function showCopyButtonFeedback(button) {
    var originalText = button.textContent;
    var originalBg = button.style.background;
    button.textContent = '✓ Copied!';
    button.style.background = '#28a745';
    setTimeout(function () {
        button.textContent = originalText;
        button.style.background = originalBg;
    }, 2000);
}

// Function to trigger streamlined password manager save modal
function triggerPasswordManagerSave(base64DecryptedData, base64Metadata) {
    try {
        var decryptedData = JSON.parse(atob(base64DecryptedData));
        var metadata = JSON.parse(atob(base64Metadata));

        // Remove any existing form
        var existingForm = document.getElementById('temp-password-manager-form');
        if (existingForm) {
            existingForm.remove();
        }

        // Get the login URL
        var loginUrl = metadata.login_url || '';
        if (loginUrl && !loginUrl.match(/^https?:\/\//)) {
            loginUrl = 'https://' + loginUrl;
        }

        // Create streamlined export modal overlay
        var overlay = document.createElement('div');
        overlay.id = 'temp-password-manager-form';
        overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; display: flex; align-items: center; justify-content: center; overflow-y: auto; padding: 20px; box-sizing: border-box;';

        var formContainer = document.createElement('div');
        formContainer.style.cssText = 'background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto;';

        formContainer.innerHTML =
            '<h3 style="margin: 0 0 20px 0; text-align: center; color: #333;">🔐 Export to Password Manager</h3>' +
            '<p style="margin: 0 0 20px 0; font-size: 14px; color: #666; text-align: center;">Login credentials for: <strong>' + (metadata.name || 'Account') + '</strong></p>' +

            // CSV Export Section
            '<div style="background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px;">' +
            '<h4 style="margin: 0 0 15px 0; color: #333;">📄 Download CSV Files</h4>' +
            '<div style="display: grid; gap: 8px;">' +
            '<button type="button" onclick="exportForPasswordManager(\'bitwarden\')" style="background: #175ddc; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 Bitwarden CSV</button>' +
            '<button type="button" onclick="exportForPasswordManager(\'1password\')" style="background: #0f69ff; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 1Password CSV</button>' +
            '<button type="button" onclick="exportForPasswordManager(\'lastpass\')" style="background: #d32f2f; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 LastPass CSV</button>' +
            '<button type="button" onclick="exportForPasswordManager(\'chrome\')" style="background: #4285f4; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 Chrome CSV</button>' +
            '<button type="button" onclick="exportForPasswordManager(\'firefox\')" style="background: #ff9500; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 Firefox CSV</button>' +
            '<button type="button" onclick="exportForPasswordManager(\'safari\')" style="background: #007aff; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 Safari CSV</button>' +
            '<button type="button" onclick="exportForPasswordManager(\'dashlane\')" style="background: #00b300; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 Dashlane CSV</button>' +
            '<button type="button" onclick="exportForPasswordManager(\'keepass\')" style="background: #326ce5; color: white; border: none; padding: 10px; border-radius: 4px; cursor: pointer; font-size: 14px;">📁 KeePass CSV</button>' +
            '</div>' +
            '<p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">Each file is formatted specifically for that password manager\'s import feature</p>' +
            '</div>' +

            // Instructions Section
            '<div style="background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; color: #1565c0;">' +
            '<strong>💡 Instructions:</strong><br>' +
            '1. Use the copy buttons in the decrypted data above to manually copy individual fields<br>' +
            '2. Download CSV file for your specific password manager<br>' +
            '3. Import the CSV file using your password manager\'s import feature<br>' +
            '4. Delete the downloaded CSV file after import for security' +
            '</div>' +

            // Close button
            '<div style="text-align: center;">' +
            '<button type="button" id="closePasswordManagerModal" style="background: #666; color: white; padding: 12px 30px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer;">Close</button>' +
            '</div>';

        overlay.appendChild(formContainer);
        document.body.appendChild(overlay);

        // Store data for other functions
        window.currentPasswordData = {
            username: decryptedData.username_email || '',
            password: decryptedData.password || '',
            website: loginUrl,
            name: metadata.name || 'Account',
            notes: decryptedData.additional_notes || ''
        };

        // Add event listeners
        document.getElementById('closePasswordManagerModal').addEventListener('click', function () {
            overlay.remove();
            delete window.currentPasswordData;
        });

        // Close overlay when clicking outside
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                overlay.remove();
                delete window.currentPasswordData;
            }
        });

    } catch (error) {
        console.error('Error showing password manager options:', error);
        alert('Error preparing password manager options: ' + error.message);
    }
}

// Function to export CSV for specific password managers
function exportForPasswordManager(manager) {
    var data = window.currentPasswordData;
    if (!data) return;

    var csvContent = '';
    var filename = '';

    switch (manager) {
        case 'bitwarden':
            // Bitwarden CSV format: folder,favorite,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp
            csvContent = 'folder,favorite,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp\n';
            csvContent += ',,1,"' + escapeCSV(data.name) + '","' + escapeCSV(data.notes) + '",,0,"' + escapeCSV(data.website) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '",';
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_bitwarden.csv';
            break;

        case '1password':
            // 1Password CSV format: Title,URL,Username,Password,Notes,Type
            csvContent = 'Title,URL,Username,Password,Notes,Type\n';
            csvContent += '"' + escapeCSV(data.name) + '","' + escapeCSV(data.website) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '","' + escapeCSV(data.notes) + '",Login';
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_1password.csv';
            break;

        case 'lastpass':
            // LastPass CSV format: url,username,password,extra,name,grouping,fav
            csvContent = 'url,username,password,extra,name,grouping,fav\n';
            csvContent += '"' + escapeCSV(data.website) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '","' + escapeCSV(data.notes) + '","' + escapeCSV(data.name) + '",,0';
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_lastpass.csv';
            break;

        case 'chrome':
            // Chrome CSV format: name,url,username,password
            csvContent = 'name,url,username,password\n';
            csvContent += '"' + escapeCSV(data.name) + '","' + escapeCSV(data.website) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '"';
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_chrome.csv';
            break;

        case 'firefox':
            // Firefox CSV format: url,username,password,httpRealm,formActionOrigin,guid,timeCreated,timeLastUsed,timePasswordChanged
            csvContent = 'url,username,password,httpRealm,formActionOrigin,guid,timeCreated,timeLastUsed,timePasswordChanged\n';
            var timestamp = new Date().getTime();
            csvContent += '"' + escapeCSV(data.website) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '","","' + escapeCSV(data.website) + '","{' + generateGUID() + '}",' + timestamp + ',' + timestamp + ',' + timestamp;
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_firefox.csv';
            break;

        case 'safari':
            // Safari CSV format: Title,URL,Username,Password,Notes,OTPAuth
            csvContent = 'Title,URL,Username,Password,Notes,OTPAuth\n';
            csvContent += '"' + escapeCSV(data.name) + '","' + escapeCSV(data.website) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '","' + escapeCSV(data.notes) + '",';
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_safari.csv';
            break;

        case 'dashlane':
            // Dashlane CSV format: name,url,username,password,note
            csvContent = 'name,url,username,password,note\n';
            csvContent += '"' + escapeCSV(data.name) + '","' + escapeCSV(data.website) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '","' + escapeCSV(data.notes) + '"';
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_dashlane.csv';
            break;

        case 'keepass':
            // KeePass CSV format: Account,Login Name,Password,Web Site,Comments
            csvContent = 'Account,Login Name,Password,Web Site,Comments\n';
            csvContent += '"' + escapeCSV(data.name) + '","' + escapeCSV(data.username) + '","' + escapeCSV(data.password) + '","' + escapeCSV(data.website) + '","' + escapeCSV(data.notes) + '"';
            filename = data.name.replace(/[^a-zA-Z0-9]/g, '_') + '_keepass.csv';
            break;
    }

    if (csvContent) {
        downloadCSVFile(csvContent, filename);

        // Show instructions
        var instructions = getImportInstructions(manager);
        setTimeout(function () {
            alert('CSV file downloaded! ' + instructions);
        }, 500);
    }
}

// Function to escape CSV values
function escapeCSV(value) {
    if (typeof value !== 'string') value = '';
    return value.replace(/"/g, '""');
}

// Function to download CSV file
function downloadCSVFile(content, filename) {
    var blob = new Blob([content], { type: 'text/csv' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Function to get import instructions for each password manager
function getImportInstructions(manager) {
    switch (manager) {
        case 'bitwarden':
            return 'To import: 1) Open Bitwarden vault 2) Go to Tools > Import Data 3) Select "Bitwarden (csv)" format 4) Choose the downloaded file';
        case '1password':
            return 'To import: 1) Open 1Password 2) Go to File > Import 3) Select "CSV" format 4) Choose the downloaded file';
        case 'lastpass':
            return 'To import: 1) Open LastPass vault 2) Go to Advanced Options > Import 3) Select "LastPass CSV" format 4) Choose the downloaded file';
        case 'chrome':
            return 'To import: 1) Open Chrome 2) Go to Settings > Passwords > Import 3) Choose the downloaded file';
        case 'firefox':
            return 'To import: 1) Open Firefox 2) Go to about:logins 3) Click menu (⋯) > Import from File 4) Choose the downloaded file';
        case 'safari':
            return 'To import: 1) Open Safari 2) Go to File > Import From > CSV file 3) Choose the downloaded file';
        case 'dashlane':
            return 'To import: 1) Open Dashlane 2) Go to Settings > Import 3) Select "CSV" format 4) Choose the downloaded file';
        case 'keepass':
            return 'To import: 1) Open KeePass 2) Go to File > Import 3) Select "Generic CSV Importer" 4) Choose the downloaded file';
        default:
            return 'Import the CSV file using your password manager\'s import feature.';
    }
}

// Function to generate GUID for Firefox
function generateGUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
} 