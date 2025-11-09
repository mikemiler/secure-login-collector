<?php
/**
 * List Table Class
 *
 * Custom WP_List_Table implementation for displaying secure login data.
 *
 * @package Secure_Login_Collector
 */

// Prevent direct access.
if (! defined('ABSPATH') ) {
    exit;
}

// Include WP_List_Table if not already included.
if (! class_exists('WP_List_Table') ) {
    include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Secure Login List Table Class extending WP_List_Table
 *
 * Custom list table for displaying secure login data with WordPress admin styling.
 */
class Seculoco_List_Table extends WP_List_Table
{


    /**
     * Database table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Database manager instance.
     *
     * @var Seculoco_Database_Manager
     */
    private $database_manager;

    /**
     * Encryption handler instance.
     *
     * @var Seculoco_Encryption_Handler_V2
     */
    private $encryption_handler;

    /**
     * Constructor - initializes list table.
     *
     * @param string                         $table_name         Database table name.
     * @param Seculoco_Database_Manager      $database_manager   Database manager instance.
     * @param Seculoco_Encryption_Handler_V2 $encryption_handler Encryption handler instance.
     */
    public function __construct( $table_name, $database_manager, $encryption_handler )
    {
        $this->table_name         = $table_name;
        $this->database_manager   = $database_manager;
        $this->encryption_handler = $encryption_handler;

        parent::__construct(
            array(
            'singular' => 'login_entry',
            'plural'   => 'login_entries',
            'ajax'     => false,
            )
        );
    }

    /**
     * Define table columns.
     *
     * @return array Column definitions.
     */
    public function get_columns()
    {
        return array(
        'cb'         => '<input type="checkbox" />',
        'email'      => __('Email Address', 'secure-login-collector'),
        'name'       => __('Name', 'secure-login-collector'),
        'login_url'  => __('Login URL', 'secure-login-collector'),
        'created_at' => __('Date', 'secure-login-collector'),
        'encryption' => __('Encryption Method', 'secure-login-collector'),
        'expires'    => __('Expires In', 'secure-login-collector'),
        'actions'    => __('Actions', 'secure-login-collector'),
        );
    }

    /**
     * Define sortable columns.
     *
     * @return array Sortable column definitions.
     */
    public function get_sortable_columns()
    {
        return array(
        'email'      => array( 'email', false ),
        'name'       => array( 'name', false ),
        'login_url'  => array( 'login_url', false ),
        'created_at' => array( 'created_at', false ),
        'encryption' => array( 'encryption', false ),
        'expires'    => array( 'expires', false ),
        );
    }

    /**
     * Define bulk actions.
     *
     * @return array Bulk action definitions.
     */
    public function get_bulk_actions()
    {

        $bulk_actions = array(
        'delete' => __('Delete', 'secure-login-collector'),
        );
        return apply_filters('seculoco_bulk_actions', $bulk_actions);
    }

    /**
     * Render checkbox column.
     *
     * @param  object $item Row data.
     * @return string Checkbox HTML.
     */
    public function column_cb( $item )
    {
        return sprintf(
            '<input type="checkbox" name="login_entries[]" value="%s" class="select-entry" data-id="%s" />',
            $item->id,
            $item->id
        );
    }

    /**
     * Render email column.
     *
     * @param  object $item Row data.
     * @return string Email column HTML.
     */
    public function column_email( $item )
    {
        $metadata = $this->parse_metadata($item->metadata);
        $email    = $metadata['email'] ?? __('N/A', 'secure-login-collector');

        return sprintf(
            '<span class="editable-field" data-field="email" data-id="%s">%s</span>',
            $item->id,
            esc_html($email)
        );
    }

    /**
     * Render name column.
     *
     * @param  object $item Row data.
     * @return string Name column HTML.
     */
    public function column_name( $item )
    {
        $metadata = $this->parse_metadata($item->metadata);
        $name     = $metadata['name'] ?? __('N/A', 'secure-login-collector');

        return sprintf(
            '<span class="editable-field" data-field="name" data-id="%s">%s</span>',
            $item->id,
            esc_html($name)
        );
    }

    /**
     * Render login URL column.
     *
     * @param  object $item Row data.
     * @return string Login URL column HTML.
     */
    public function column_login_url( $item )
    {
        $metadata  = $this->parse_metadata($item->metadata);
        $login_url = $metadata['login_url'] ?? $metadata['service_name'] ?? __('Not provided', 'secure-login-collector');

        return sprintf(
            '<span class="editable-field" data-field="login_url" data-id="%s">%s</span>',
            $item->id,
            esc_html($login_url)
        );
    }

    /**
     * Render created date column.
     *
     * @param  object $item Row data.
     * @return string Date column HTML.
     */
    public function column_created_at( $item )
    {
        return esc_html(gmdate('M j, Y g:i A', strtotime($item->created_at)));
    }

    /**
     * Render encryption method column.
     *
     * @param  object $item Row data.
     * @return string Encryption method column HTML.
     */
    public function column_encryption( $item )
    {
        $metadata        = json_decode($item->metadata, true);
        $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'rsa';
        $encryption_info = $this->get_encryption_method_info($encryption_type);

        return sprintf(
            '<span class="encryption-method %s" title="%s">%s</span>',
            esc_attr($encryption_info['class']),
            esc_attr($encryption_info['description']),
            $encryption_info['name']
        );
    }

    /**
     * Render expires column.
     *
     * @param  object $item Row data.
     * @return string Expires column HTML.
     */
    public function column_expires( $item )
    {
        $is_expired = isset($item->is_expired) ? $item->is_expired : 0;
        return $this->database_manager->calculate_expiration($item->retention_until, $is_expired);
    }

    /**
     * Render actions column.
     *
     * @param  object $item Row data.
     * @return string Actions column HTML.
     */
    public function column_actions( $item )
    {
        $metadata         = json_decode($item->metadata, true);
        $encryption_type  = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'rsa';
        $hostname         = isset($metadata['key_hostname']) ? $metadata['key_hostname'] : '';
        $timestamp_suffix = isset($metadata['key_timestamp_suffix']) ? $metadata['key_timestamp_suffix'] : '';
        $is_expired       = isset($item->is_expired) && 1 === $item->is_expired;

        // Check if entry is undecryptable (passkey-encrypted but passkey was deleted).
        // Use database flag as primary indicator (more reliable than checking options).
        $is_undecryptable = false;

        if (isset($item->undecryptable) && 1 === (int) $item->undecryptable ) {
            $is_undecryptable = true;
        } else {
            // Fallback: Check encrypted_data for legacy entries not yet marked.
            $encrypted_data = json_decode($item->encrypted_data, true);
            if (is_array($encrypted_data) && ! empty($encrypted_data['credentialId']) && $encrypted_data['isProEncrypted'] ) {
                // Entry was encrypted with a passkey. Check if that passkey still exists.
                $credential_id = $encrypted_data['credentialId'];
                $passkey_data  = get_option('passkey_credential_' . $credential_id, false);
                if (! $passkey_data ) {
                    $is_undecryptable = true;
                }
            }
        }

        $actions = array();

        // Decrypt button (disabled for expired or undecryptable entries).
        if ($is_expired ) {
            // Show disabled decrypt button for expired entries.
            $actions[] = sprintf(
                '<button type="button" class="button button-expired" disabled title="%s"><span class="dashicons dashicons-unlock"></span></button>',
                esc_attr__('Data has been purged (expired)', 'secure-login-collector')
            );
        } elseif ($is_undecryptable ) {
            // Show disabled decrypt button with undecryptable indicator.

            $actions[] = sprintf(
                '<button type="button" class="button decrypt-btn-v2" data-id="%s" data-undecryptable="true" disabled title="%s"><span class="dashicons dashicons-lock"></span></button>',
                $item->id,
                esc_attr__('Cannot decrypt: encryption key was deleted', 'secure-login-collector')
            );
        } else {
            // Normal decrypt button.
            $actions[] = sprintf(
                '<button type="button" class="button decrypt-btn-v2" data-id="%s" data-hostname="%s" data-timestamp="%s" data-encryption-type="%s" title="%s"><span class="dashicons dashicons-unlock"></span></button>',
                $item->id,
                esc_attr($hostname),
                esc_attr($timestamp_suffix),
                esc_attr($encryption_type),
                esc_attr__('Decrypt data', 'secure-login-collector')
            );
        }

        // Extend button (only for non-expired entries if expiration is enabled) with icon.
        $expiration_days = seculoco_get_expiration_days();
        if ($expiration_days > 0 && ! $is_expired && ! $is_undecryptable ) {
            $actions[] = sprintf(
                '<button type="button" class="button button-secondary extend-btn" data-id="%s" title="%s"><span class="dashicons dashicons-calendar-alt"></span></button>',
                $item->id,
                esc_attr__('Extend retention period', 'secure-login-collector')
            );
        } elseif (seculoco_is_premium_active()) {
            $actions[] = sprintf(
                '<button type="button" class="button button-secondary extend-btn" title="%s" style="opacity: 0.7" disabled><span class="dashicons dashicons-calendar-alt"></span></button>',
                $item->id,
                esc_attr__('Cannot extend retention period', 'secure-login-collector')
            );
        }

        // Edit button with icon (disabled for expired entries).
        if (! $is_expired ) {
            $actions[] = sprintf(
                '<button type="button" class="button edit-btn" data-id="%s" title="%s"><span class="dashicons dashicons-edit"></span></button>',
                $item->id,
                esc_attr__('Edit entry', 'secure-login-collector')
            );

            // Save/Cancel buttons (hidden by default) with icons.
            $actions[] = sprintf(
                '<button type="button" class="button button-primary save-btn" data-id="%s" title="%s" style="display: none;"><span class="dashicons dashicons-yes"></span></button>',
                $item->id,
                esc_attr__('Save changes', 'secure-login-collector')
            );

            $actions[] = sprintf(
                '<button type="button" class="button cancel-btn" data-id="%s" title="%s" style="display: none;"><span class="dashicons dashicons-no"></span></button>',
                $item->id,
                esc_attr__('Cancel editing', 'secure-login-collector')
            );
        }

        // Delete button with icon.
        $actions[] = sprintf(
            '<button type="button" class="button button-secondary delete-btn" data-id="%s" title="%s"><span class="dashicons dashicons-trash" style="color: #d63384;"></span></button>',
            $item->id,
            esc_attr__('Delete entry', 'secure-login-collector')
        );

        return implode(' ', $actions);
    }

    /**
     * Get encryption method info.
     *
     * @param  string $encryption_type The encryption type.
     * @return array Encryption method information.
     */
    private function get_encryption_method_info( $encryption_type )
    {
        switch ( $encryption_type ) {
        case 'aes-rsa-v2':
            return array(
                    'name'        => __('Password Protected', 'secure-login-collector'),
                    'class'       => 'encryption-rsa',
                    'description' => __('AES-256-GCM encryption with RSA key protection.', 'secure-login-collector'),
            );
        case 'aes-rsa-passkey-v2':
            return array(
                    'name'        => __('Passkey Protected', 'secure-login-collector'),
                    'class'       => 'encryption-ultra-secure',
                    'description' => __('AES-256-GCM + RSA with passkey authentication required for decryption.', 'secure-login-collector'),
            );
            
        default:
            return array(
                    'name'        => __('Unknown', 'secure-login-collector'),
                    'class'       => 'encryption-rsa',
                    'description' => __('Somehow encrypted', 'secure-login-collector'),
            );
        }
    }

    /**
     * Prepare table items.
     */
    public function prepare_items()
    {
        $columns  = $this->get_columns();
        $hidden   = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array( $columns, $hidden, $sortable );

        // Handle bulk actions.
        $this->process_bulk_action();

        // Get search term.
     // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table search/sort parameters.
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        // Get sorting parameters.
     // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table search/sort parameters.
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'created_at';
     // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table search/sort parameters.
        $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'desc';

        // Get pagination parameters.
        $per_page     = $this->get_items_per_page('login_entries_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        // Get data.
        $data        = $this->database_manager->get_entries_for_list_table($search, $orderby, $order, $per_page, $offset);
        $total_items = $this->database_manager->get_total_entries($search);

        $this->items = $data;

        // Set pagination.
        $this->set_pagination_args(
            array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
            )
        );
    }

    /**
     * Parse metadata JSON for display.
     * In v2 format, metadata is stored as plain JSON (not encrypted).
     *
     * @param  string $metadata_json The metadata JSON string.
     * @return array Parsed metadata array.
     */
    private function parse_metadata( $metadata_json )
    {
        $metadata = json_decode($metadata_json, true);

        if (! $metadata ) {
            return array();
        }

        // V2 format: metadata is stored in plain text.
        if (isset($metadata['encryption_version']) && 2 === $metadata['encryption_version'] ) {
            return $metadata;
        }

        // Legacy format: may have encrypted fields.
        if (isset($metadata['metadata_encrypted']) && $metadata['metadata_encrypted'] && isset($metadata['encrypted_fields']) ) {
            // Decrypt legacy encrypted fields.
            $key = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY), 0, 32);

            foreach ( $metadata['encrypted_fields'] as $field => $encrypted_value ) {
                $data = base64_decode($encrypted_value); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Legitimate decryption operation.
                if (strlen($data) > 16 ) {
                    $iv        = substr($data, 0, 16);
                    $encrypted = substr($data, 16);
                    $decrypted = openssl_decrypt(
                        $encrypted,
                        'aes-256-cbc',
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv
                    );
                    if (false !== $decrypted ) {
                        $metadata[ $field ] = $decrypted;
                    }
                }
            }

            unset($metadata['encrypted_fields']);
        }

        return $metadata;
    }

    /**
     * Process bulk actions.
     */
    public function process_bulk_action()
    {
        $action = $this->current_action();

        if (! $action ) {
            return;
        }

        // Verify nonce.
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'bulk-login_entries') ) {
            wp_die(esc_html__('Security check failed.', 'secure-login-collector'));
        }

        $ids = isset($_POST['login_entries']) ? array_map('intval', wp_unslash($_POST['login_entries'])) : array();

        if (empty($ids) ) {
            return;
        }

        if ('delete' === $action ) {
            foreach ( $ids as $id ) {
                $this->database_manager->delete_entry($id);
            }

            $message = sprintf(
            /* translators: %d: number of entries deleted */
                _n('%d entry deleted.', '%d entries deleted.', count($ids), 'secure-login-collector'),
                count($ids)
            );

            add_settings_error('seculoco_bulk', 'bulk_delete', $message, 'updated');
        } elseif (strpos($action, 'export-') === 0 ) {
            // Handle CSV exports via AJAX (will be processed by JavaScript).
            $manager = str_replace('export-', '', $action);

            // Store export request in transient for AJAX processing.
            set_transient(
                'seculoco_bulk_export_' . get_current_user_id(),
                array(
                'manager' => $manager,
                'ids'     => $ids,
                ),
                300
            );

            $message = sprintf(
            /* translators: %d: number of entries prepared for export */
                __('Bulk export initiated for %d entries. Please wait...', 'secure-login-collector'),
                count($ids)
            );

            add_settings_error('seculoco_bulk', 'bulk_export', $message, 'updated');
        }
    }

    /**
     * Display the search box.
     *
     * @param string $text     The search button text.
     * @param string $input_id The search input ID.
     */
    public function search_box( $text, $input_id )
    {
     // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are read-only GET parameters used for display/filtering, not form submissions.
        $search_term = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        if (empty($search_term) && ! $this->has_items() ) {
            return;
        }

        $input_id = $input_id . '-search-input';

        if (! empty($_GET['orderby']) ) {
            echo '<input type="hidden" name="orderby" value="' . esc_attr(sanitize_text_field(wp_unslash($_GET['orderby']))) . '" />';
        }
        if (! empty($_GET['order']) ) {
            echo '<input type="hidden" name="order" value="' . esc_attr(sanitize_text_field(wp_unslash($_GET['order']))) . '" />';
        }
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($text); ?>:</label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET['s'] ?? ''))); ?>" placeholder="<?php echo esc_attr__('Search in email, name, login URL...', 'secure-login-collector'); ?>" />
        <?php submit_button($text, '', '', false, array( 'id' => 'search-submit' )); ?>
        </p>
        <?php
     // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Override the single row display to add decrypted data row.
     *
     * @param object $item Row data.
     */
    public function single_row( $item )
    {
        echo '<tr id="row-' . esc_attr($item->id) . '">';
        $this->single_row_columns($item);
        echo '</tr>';

        // Add hidden row for decrypted data.
        echo '<tr id="decrypted-row-' . esc_attr($item->id) . '" class="decrypted-data-row" style="display: none;">';
        echo '<td colspan="' . count($this->get_columns()) . '">';
        echo '<div class="decrypted-content">';
        echo '<h4>' . esc_html__('Decrypted Data:', 'secure-login-collector') . '</h4>';
        echo '<div class="decrypted-json"></div>';
        echo '<div style="margin-top: 10px;">';
        echo '<button type="button" class="button button-primary export-to-password-manager" data-id="' . esc_attr($item->id) . '" title="' . esc_attr__('Export to Password Manager', 'secure-login-collector') . '"> ' . esc_html__('Export to Password Manager', 'secure-login-collector') . '</button>';
        echo '<button type="button" class="button hide-decrypted" data-id="' . esc_attr($item->id) . '" title="' . esc_attr__('Hide decrypted data', 'secure-login-collector') . '"><span class="dashicons dashicons-hidden"></span></button>';
        echo '</div>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
}
