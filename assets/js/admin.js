jQuery(document).ready(function ($) {

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

    // Escape attribute values to prevent XSS in data attributes
    function escapeAttr(text) {
        // Escape quotes and HTML entities for safe use in attributes
        return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // Event delegation for dynamically created copy buttons
    $(document).on('click', '.copy-field-btn', function() {
        var button = this;
        var data = $(this).attr('data-copy-value');
        
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
    });

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
            url: seculocoAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'seculoco_update_metadata',
                update_id: id,
                metadata: newData,
                nonce: seculocoAjax.nonce
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
                    alert(seculocoAjax.strings.save_failed + (response.data || seculocoAjax.strings.unknown_error));
                }
                button.prop('disabled', false);
            },
            error: function () {
                alert(seculocoAjax.strings.network_error_save);
                button.prop('disabled', false);
            }
        });
    });


    // Hide decrypted data
    $('.hide-decrypted').on('click', function () {
        var button = $(this);
        var id = button.data('id');
        var decryptedRow = $('#decrypted-row-' + id);
        var decryptBtn = $('.decrypt-btn-v2[data-id="' + id + '"]');

       
        decryptedRow.removeData('decrypted-data');

        decryptedRow.hide();
        decryptBtn.prop('disabled', false).removeClass('button-success');
        decryptBtn.html('<span class="dashicons dashicons-unlock"></span>');
    });

    // Extend functionality
    $('.extend-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');

        if (!confirm(seculocoAjax.strings.confirm_extend_retention)) {
            return;
        }

        button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span>');

        $.ajax({
            url: seculocoAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'seculoco_extend_entry',
                extend_id: id,
                nonce: seculocoAjax.nonce
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message || seculocoAjax.strings.retention_extended);
                    // Refresh page to show updated expiration
                    location.reload();
                } else {
                    alert('Extend failed: ' + (response.data || 'Unknown error'));
                }
                button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span>');
            },
            error: function () {
                alert('Network error occurred during extension.');
                button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span>');
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

        button.prop('disabled', true).html('<span class="dashicons dashicons-trash spin"></span>');

        $.ajax({
            url: seculocoAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'seculoco_delete_entry',
                delete_id: id,
                nonce: seculocoAjax.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Refresh page to show updated list
                    location.reload();
                } else {
                    alert('Delete failed: ' + (response.data || 'Unknown error'));
                    button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>');
                }
            },
            error: function () {
                alert('Network error occurred during deletion.');
                button.prop('disabled', false).text('Delete');
            }
        });
    });



// Handle fix passkey flag button
$(document).on('click', '#fix-passkey-flag-btn', function() {
    const button = $(this);
    const resultSpan = $('#fix-passkey-flag-result');
    
    button.prop('disabled', true).html('<span class="dashicons dashicons-admin-tools spin"></span>');
    resultSpan.html('<span style="color: #666;">Processing...</span>');
    
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'seculoco_fix_passkey_flag',
            nonce: seculocoAjax.nonce
        },
        success: function(response) {
            if (response.success) {
                resultSpan.html('<span style="color: #4CAF50;">✅ ' + response.data + '</span>');
                // Hide the warning notice after a delay
                setTimeout(function() {
                    button.closest('.notice').fadeOut();
                }, 3000);
            } else {
                resultSpan.html('<span style="color: #f44336;">❌ Error: ' + response.data + '</span>');
            }
        },
        error: function() {
            resultSpan.html('<span style="color: #f44336;">❌ Network error occurred</span>');
        },
        complete: function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-admin-tools"></span> Fix');
        }
    });
}); 
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

// Removed global copyFieldData function - now using event delegation

// Fallback copy function
function copyToClipboardFallback(text) {
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.className = 'seculoco-copy-to-clipboard';
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
    button.textContent = '✓ Copied!';
    button.classList.add('seculoco-copy-btn-success');
    setTimeout(function () {
        button.textContent = originalText;
        button.classList.remove('seculoco-copy-btn-success');
    }, 2000);
}
