<?php

/**
 * Admin Interface Class
 *
 * Handles admin interface operations including:
 * - Admin pages and menus
 * - Data viewing and management
 * - AJAX handlers for admin operations
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if (! defined('ABSPATH')) {
    exit;
}

// Include WP_List_Table if not already included.
if (! class_exists('WP_List_Table')) {
    include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Secure Login List Table Class extending WP_List_Table
 *
 * Custom list table for displaying secure login data with WordPress admin styling.
 */
class Secure_Login_List_Table extends WP_List_Table
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
     * @var Secure_Login_Database_Manager
     */
    private $database_manager;

    /**
     * Encryption handler instance.
     *
     * @var Secure_Login_Encryption_Handler
     */
    private $encryption_handler;

    /**
     * Whether pro version is enabled.
     *
     * @var bool
     */
    private $is_pro_version;

    /**
     * Constructor - initializes list table.
     *
     * @param string                          $table_name         Database table name.
     * @param Secure_Login_Database_Manager   $database_manager   Database manager instance.
     * @param Secure_Login_Encryption_Handler $encryption_handler Encryption handler instance.
     * @param bool                            $is_pro_version     Whether pro version is enabled.
     */
    public function __construct($table_name, $database_manager, $encryption_handler, $is_pro_version)
    {
        $this->table_name         = $table_name;
        $this->database_manager   = $database_manager;
        $this->encryption_handler = $encryption_handler;
        $this->is_pro_version     = $is_pro_version;

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
            'email'      => array('email', false),
            'name'       => array('name', false),
            'login_url'  => array('login_url', false),
            'created_at' => array('created_at', false),
            'encryption' => array('encryption', false),
            'expires'    => array('expires', false),
        );
    }

    /**
     * Define bulk actions.
     *
     * @return array Bulk action definitions.
     */
    public function get_bulk_actions()
    {
        return array(
            'export-bitwarden' => __('Export Bitwarden CSV', 'secure-login-collector'),
            'export-1password' => __('Export 1Password CSV', 'secure-login-collector'),
            'export-lastpass'  => __('Export LastPass CSV', 'secure-login-collector'),
            'export-chrome'    => __('Export Chrome CSV', 'secure-login-collector'),
            'export-firefox'   => __('Export Firefox CSV', 'secure-login-collector'),
            'export-safari'    => __('Export Safari CSV', 'secure-login-collector'),
            'export-dashlane'  => __('Export Dashlane CSV', 'secure-login-collector'),
            'export-keepass'   => __('Export KeePass CSV', 'secure-login-collector'),
            'delete'           => __('Delete', 'secure-login-collector'),
        );
    }

    /**
     * Render checkbox column.
     *
     * @param object $item Row data.
     * @return string Checkbox HTML.
     */
    public function column_cb($item)
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
     * @param object $item Row data.
     * @return string Email column HTML.
     */
    public function column_email($item)
    {
        $metadata = json_decode($item->metadata, true);
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
     * @param object $item Row data.
     * @return string Name column HTML.
     */
    public function column_name($item)
    {
        $metadata = json_decode($item->metadata, true);
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
     * @param object $item Row data.
     * @return string Login URL column HTML.
     */
    public function column_login_url($item)
    {
        $metadata  = json_decode($item->metadata, true);
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
     * @param object $item Row data.
     * @return string Date column HTML.
     */
    public function column_created_at($item)
    {
        return esc_html(gmdate('M j, Y g:i A', strtotime($item->created_at)));
    }

    /**
     * Render encryption method column.
     *
     * @param object $item Row data.
     * @return string Encryption method column HTML.
     */
    public function column_encryption($item)
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
     * @param object $item Row data.
     * @return string Expires column HTML.
     */
    public function column_expires($item)
    {
        return $this->database_manager->calculate_expiration($item->retention_until);
    }

    /**
     * Render actions column.
     *
     * @param object $item Row data.
     * @return string Actions column HTML.
     */
    public function column_actions($item)
    {
        $metadata         = json_decode($item->metadata, true);
        $encryption_type  = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'rsa';
        $hostname         = isset($metadata['key_hostname']) ? $metadata['key_hostname'] : '';
        $timestamp_suffix = isset($metadata['key_timestamp_suffix']) ? $metadata['key_timestamp_suffix'] : '';

        $actions = array();

        // Edit button with icon.
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

        // Always use v2 decrypt button since we only support current encryption format
        $actions[] = sprintf(
            '<button type="button" class="button decrypt-btn-v2" data-id="%s" data-hostname="%s" data-timestamp="%s" data-encryption-type="%s" title="%s"><span class="dashicons dashicons-unlock"></span></button>',
            $item->id,
            esc_attr($hostname),
            esc_attr($timestamp_suffix),
            esc_attr($encryption_type),
            esc_attr__('Decrypt data', 'secure-login-collector')
        );

        // Extend button (if expiration is enabled) with icon.
        $expiration_days = get_option('secure_login_expiration_days', 30);
        if ($expiration_days > 0) {
            $actions[] = sprintf(
                '<button type="button" class="button button-secondary extend-btn" data-id="%s" title="%s"><span class="dashicons dashicons-calendar-alt"></span></button>',
                $item->id,
                esc_attr__('Extend retention period', 'secure-login-collector')
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
     * @param string $encryption_type The encryption type.
     * @return array Encryption method information.
     */
    private function get_encryption_method_info($encryption_type)
    {
        switch ($encryption_type) {
            case 'aes-rsa-v2':
                return array(
                    'name'        => __('🔐 Secure', 'secure-login-collector'),
                    'class'       => 'encryption-rsa',
                    'description' => __('AES-256-GCM encryption with RSA key protection.', 'secure-login-collector'),
                );
            case 'aes-rsa-passkey-v2':
                return array(
                    'name'        => __('🔐 Ultra-Secure', 'secure-login-collector'),
                    'class'       => 'encryption-ultra-secure',
                    'description' => __('AES-256-GCM + RSA with passkey authentication required for decryption.', 'secure-login-collector'),
                );
            case 'passkey_derived':
                return array(
                    'name'        => __('🔐 Ultra-Secure', 'secure-login-collector'),
                    'class'       => 'encryption-ultra-secure',
                    'description' => __('Passkey-derived encryption for maximum security.', 'secure-login-collector'),
                );
            case 'rsa':
                return array(
                    'name'        => __('🔒 RSA-2048', 'secure-login-collector'),
                    'class'       => 'encryption-rsa',
                    'description' => __('Industry-standard RSA encryption.', 'secure-login-collector'),
                );
            default:
                return array(
                    'name'        => __('🔒 RSA-2048', 'secure-login-collector'),
                    'class'       => 'encryption-rsa',
                    'description' => __('Industry-standard RSA encryption.', 'secure-login-collector'),
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

        $this->_column_headers = array($columns, $hidden, $sortable);

        // Handle bulk actions.
        $this->process_bulk_action();

        // Get search term.
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';

        // Get sorting parameters.
        $orderby = isset($_REQUEST['orderby']) ? sanitize_text_field(wp_unslash($_REQUEST['orderby'])) : 'created_at';
        $order   = isset($_REQUEST['order']) ? sanitize_text_field(wp_unslash($_REQUEST['order'])) : 'desc';

        // Get pagination parameters.
        $per_page     = $this->get_items_per_page('login_entries_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset       = ($current_page - 1) * $per_page;

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
     * Process bulk actions.
     */
    public function process_bulk_action()
    {
        $action = $this->current_action();

        if (! $action) {
            return;
        }

        // Verify nonce.
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 'bulk-login_entries')) {
            wp_die(esc_html__('Security check failed.', 'secure-login-collector'));
        }

        $ids = isset($_REQUEST['login_entries']) ? array_map('intval', wp_unslash($_REQUEST['login_entries'])) : array();

        if (empty($ids)) {
            return;
        }

        if ('delete' === $action) {
            foreach ($ids as $id) {
                $this->database_manager->delete_entry($id);
            }

            // translators: %d: number of entries deleted.
            $message = sprintf(
                _n('%d entry deleted.', '%d entries deleted.', count($ids), 'secure-login-collector'),
                count($ids)
            );

            add_settings_error('secure_login_bulk', 'bulk_delete', $message, 'updated');
        } elseif (strpos($action, 'export-') === 0) {
            // Handle CSV exports via AJAX (will be processed by JavaScript).
            $manager = str_replace('export-', '', $action);

            // Store export request in transient for AJAX processing.
            set_transient(
                'secure_login_bulk_export_' . get_current_user_id(),
                array(
                    'manager' => $manager,
                    'ids'     => $ids,
                ),
                300
            );

            /* translators: %d: number of entries for export */
            $message = sprintf(
                __('Bulk export initiated for %d entries. Please wait...', 'secure-login-collector'),
                count($ids)
            );

            add_settings_error('secure_login_bulk', 'bulk_export', $message, 'updated');
        }
    }

    /**
     * Display the search box.
     *
     * @param string $text     The search button text.
     * @param string $input_id The search input ID.
     */
    public function search_box($text, $input_id)
    {
        if (empty($_REQUEST['s']) && ! $this->has_items()) {
            return;
        }

        $input_id = $input_id . '-search-input';

        if (! empty($_REQUEST['orderby'])) {
            echo '<input type="hidden" name="orderby" value="' . esc_attr(sanitize_text_field(wp_unslash($_REQUEST['orderby']))) . '" />';
        }
        if (! empty($_REQUEST['order'])) {
            echo '<input type="hidden" name="order" value="' . esc_attr(sanitize_text_field(wp_unslash($_REQUEST['order']))) . '" />';
        }
?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($text); ?>:</label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_REQUEST['s'] ?? ''))); ?>" placeholder="<?php echo esc_attr__('Search in email, name, login URL...', 'secure-login-collector'); ?>" />
            <?php submit_button($text, '', '', false, array('id' => 'search-submit')); ?>
        </p>
    <?php
    }

    /**
     * Override the single row display to add decrypted data row.
     *
     * @param object $item Row data.
     */
    public function single_row($item)
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

/**
 * Class Secure_Login_Admin_Interface
 *
 * Handles the admin interface for the secure login collector plugin.
 */
class Secure_Login_Admin_Interface
{

    /**
     * Database table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Whether pro version is enabled.
     *
     * @var bool
     */
    private $is_pro_version;

    /**
     * Encryption handler instance.
     *
     * @var Secure_Login_Encryption_Handler
     */
    private $encryption_handler;

    /**
     * Database manager instance.
     *
     * @var Secure_Login_Database_Manager
     */
    private $database_manager;

    /**
     * List table instance.
     *
     * @var Secure_Login_List_Table
     */
    private $list_table;

    /**
     * Constructor - initializes admin interface.
     *
     * @param string                          $table_name         Database table name.
     * @param bool                            $is_pro_version     Whether pro version is enabled.
     * @param Secure_Login_Encryption_Handler $encryption_handler Encryption handler instance.
     * @param Secure_Login_Database_Manager   $database_manager   Database manager instance.
     */
    public function __construct($table_name, $is_pro_version, $encryption_handler, $database_manager)
    {
        $this->table_name         = $table_name;
        $this->is_pro_version     = $is_pro_version;
        $this->encryption_handler = $encryption_handler;
        $this->database_manager   = $database_manager;

        // Register hooks.
        add_action('admin_menu', array($this, 'add_admin_menu'), 5);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Register AJAX handlers.
        add_action('wp_ajax_decrypt_secure_login_data_v2', array($this, 'handle_decrypt_ajax_v2'));
        add_action('wp_ajax_get_encryption_info', array($this, 'handle_get_encryption_info'));
        add_action('wp_ajax_delete_secure_login_data', array($this, 'handle_delete_ajax'));
        add_action('wp_ajax_extend_secure_login_data', array($this, 'handle_extend_ajax'));
        add_action('wp_ajax_save_manual_login_data', array($this, 'handle_save_manual_login_data'));
        add_action('wp_ajax_update_secure_login_metadata', array($this, 'handle_update_metadata_ajax'));
        add_action('wp_ajax_process_bulk_export', array($this, 'handle_bulk_export_ajax'));
        add_action('wp_ajax_bulk_decrypt_with_passkey', array($this, 'handle_bulk_decrypt_with_passkey_ajax'));

        // Add screen option for items per page.
        add_action('load-toplevel_page_secure-login-collector', array($this, 'add_screen_options'));
    }

    /**
     * Add screen options for the admin page.
     */
    public function add_screen_options()
    {
        add_screen_option(
            'per_page',
            array(
                'label'   => __('Entries per page', 'secure-login-collector'),
                'default' => 20,
                'option'  => 'login_entries_per_page',
            )
        );

        // Initialize the list table.
        $this->list_table = new Secure_Login_List_Table(
            $this->table_name,
            $this->database_manager,
            $this->encryption_handler,
            $this->is_pro_version
        );
    }

    /**
     * Add admin menu for viewing collected data.
     */
    public function add_admin_menu()
    {
        $menu_title = __('Login Data', 'secure-login-collector');

        // Add Pro badge if using free version
        if (function_exists('slc_fs') && slc_fs()->is_not_paying()) {
            $menu_title .= ' <span style="color: #f18500; font-size: 10px; vertical-align: super;">PRO</span>';
        }

        add_menu_page(
            __('Secure Login Data', 'secure-login-collector'),
            $menu_title,
            'manage_options',
            'secure-login-collector',
            array($this, 'admin_page'),
            'dashicons-lock',
            30
        );
    }

    /**
     * Enqueue admin scripts and localize AJAX data.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on our admin page.
        if (! in_array($hook, array('toplevel_page_secure-login-collector'), true)) {
            return;
        }

        // Enqueue CSS file.
        wp_enqueue_style(
            'secure-login-admin-css',
            plugin_dir_url(__FILE__) . '../assets/css/admin.css',
            array(),
            '1.0.0'
        );

        // Enqueue JavaScript file.
        wp_enqueue_script(
            'secure-login-admin-js',
            plugin_dir_url(__FILE__) . '../assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Enqueue the new decrypt script for v2 encryption.
        wp_enqueue_script(
            'secure-login-admin-decrypt',
            plugin_dir_url(__FILE__) . '../assets/js/admin-decrypt.js',
            array('jquery', 'secure-login-admin-js'),
            '1.0.0',
            true
        );

        // Localize script with AJAX data.
        wp_localize_script(
            'secure-login-admin-js',
            'secureLoginAjax',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('secure_login_nonce'),
                'strings' => array(
                    'not_provided'                => __('Not provided', 'secure-login-collector'),
                    'saving'                      => __('Saving...', 'secure-login-collector'),
                    'save_failed'                 => __('Save failed: ', 'secure-login-collector'),
                    'unknown_error'               => __('Unknown error', 'secure-login-collector'),
                    'network_error_save'          => __('Network error occurred during save.', 'secure-login-collector'),
                    'requesting_passkey'          => __('Requesting passkey...', 'secure-login-collector'),
                    'passkey_setup_failed'        => __('Passkey authentication setup failed: ', 'secure-login-collector'),
                    'decrypt_data'                => __('Decrypt data', 'secure-login-collector'),
                    'network_error_passkey'       => __('Network error occurred during passkey setup.', 'secure-login-collector'),
                    'pro_no_passkey_continue'     => __('Pro version detected but no passkey registered. Continue with traditional decryption?', 'secure-login-collector'),
                    'enter_encryption_key'        => __('This entry was created before automatic key reconstruction was available. Please enter the encryption key manually:', 'secure-login-collector'),
                    'decrypting'                  => __('Decrypting...', 'secure-login-collector'),
                    'data_decrypted'              => __('Data decrypted', 'secure-login-collector'),
                    'decryption_failed'           => __('Decryption failed: ', 'secure-login-collector'),
                    'network_error_decryption'    => __('Network error occurred during decryption.', 'secure-login-collector'),
                    'error_processing_decryption' => __('Error processing decryption: ', 'secure-login-collector'),
                    'authenticate_with_passkey'   => __('Authenticate with passkey...', 'secure-login-collector'),
                    'webauthn_not_supported'      => __('WebAuthn/Passkeys are not supported in this browser.', 'secure-login-collector'),
                    'verifying_passkey'           => __('Verifying passkey and decrypting...', 'secure-login-collector'),
                    'data_decrypted_passkey'      => __('Data decrypted with passkey', 'secure-login-collector'),
                    'passkey_decryption_failed'   => __('Passkey decryption failed: ', 'secure-login-collector'),
                    'network_error_passkey_decrypt' => __('Network error occurred during passkey decryption.', 'secure-login-collector'),
                    'passkey_auth_failed'         => __('Passkey authentication failed:', 'secure-login-collector'),
                    'confirm_extend_retention'    => __('Are you sure you want to extend the retention period for this entry?', 'secure-login-collector'),
                    'extending'                   => __('Extending...', 'secure-login-collector'),
                    'retention_extended'          => __('Retention period extended successfully.', 'secure-login-collector'),
                ),
            )
        );

        // Localize script with configuration data.
        wp_localize_script(
            'secure-login-admin-js',
            'secureLoginConfig',
            array(
                'isProVersion'      => $this->is_pro_version,
                'passkeyRegistered' => get_option('secure_login_passkey_registered', false),
                'currentUserId'     => get_current_user_id(),
            )
        );

        // Localize script with translatable messages.
        wp_localize_script(
            'secure-login-admin-js',
            'secureLoginMessages',
            array(
                'noDecryptedData'                     => __('No decrypted data available. Please decrypt the data first.', 'secure-login-collector'),
                'fillAllFields'                       => __('Please fill in all required fields.', 'secure-login-collector'),
                'saving'                              => __('Saving...', 'secure-login-collector'),
                'dataSavedSuccess'                    => __('Login data saved successfully!', 'secure-login-collector'),
                'errorSavingData'                     => __('Error saving data: ', 'secure-login-collector'),
                'unknownError'                        => __('Unknown error', 'secure-login-collector'),
                'networkError'                        => __('Network error occurred while saving data.', 'secure-login-collector'),
                'saveEntry'                           => __('Save Entry', 'secure-login-collector'),
                'bulkDecryptWithPasskey'              => __('Bulk Decrypt with Passkey', 'secure-login-collector'),
                'authenticateWithPasskeyToDecryptAll' => __('Authenticate with Passkey to Decrypt All', 'secure-login-collector'),
                'bulkDecryptionCompleted'             => __('Bulk decryption completed. CSV file downloaded.', 'secure-login-collector'),
            )
        );

        // Add inline script to make nonce available globally (kept for backward compatibility).
        wp_add_inline_script('secure-login-admin-js', 'window.secureLoginNonce = "' . wp_create_nonce('secure_login_nonce') . '";');
    }

    /**
     * Admin page for viewing collected login data using WP_List_Table.
     */
    public function admin_page()
    {
        // Initialize list table if not already done.
        if (! $this->list_table) {
            $this->list_table = new Secure_Login_List_Table(
                $this->table_name,
                $this->database_manager,
                $this->encryption_handler,
                $this->is_pro_version
            );
        }

        // Prepare table items.
        $this->list_table->prepare_items();

        // Display admin notices.
        settings_errors('secure_login_bulk');

    ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Secure Login Data', 'secure-login-collector'); ?></h1>
            <a href="#" class="page-title-action" id="add-new-entry-btn"><?php echo esc_html__('Add New Entry', 'secure-login-collector'); ?></a>
            <hr class="wp-header-end">

            <p><?php echo esc_html__('This page shows all encrypted login data collected from clients. Use the search box to filter entries and bulk actions for management.', 'secure-login-collector'); ?></p>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                <?php
                $this->list_table->search_box(__('Search entries', 'secure-login-collector'), 'secure-login-entries');
                $this->list_table->display();
                ?>
            </form>

            <!-- Add New Entry Modal -->
            <div id="add-new-entry-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                <div style="background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 5px; width: 80%; max-width: 600px; position: relative;">
                    <span class="close-modal" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
                    <h2><?php echo esc_html__('Add New Login Data Entry', 'secure-login-collector'); ?></h2>

                    <form id="manual-add-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="manual_email"><?php echo esc_html__('Email Address', 'secure-login-collector'); ?> <span style="color: red;">*</span></label>
                                </th>
                                <td>
                                    <input type="email" id="manual_email" name="manual_email" class="regular-text" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="manual_name"><?php echo esc_html__('Name', 'secure-login-collector'); ?> <span style="color: red;">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="manual_name" name="manual_name" class="regular-text" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="manual_login_url"><?php echo esc_html__('Login URL', 'secure-login-collector'); ?> <span style="color: red;">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="manual_login_url" name="manual_login_url" class="regular-text" required>
                                    <p class="description"><?php echo esc_html__('Login URL or service name where these credentials are used.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="manual_username_email"><?php echo esc_html__('Username/Email', 'secure-login-collector'); ?> <span style="color: red;">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="manual_username_email" name="manual_username_email" class="regular-text" required>
                                    <p class="description"><?php echo esc_html__('The username or email address used to log into this service.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="manual_password"><?php echo esc_html__('Password', 'secure-login-collector'); ?> <span style="color: red;">*</span></label>
                                </th>
                                <td>
                                    <input type="password" id="manual_password" name="manual_password" class="regular-text" required>
                                    <p class="description"><?php echo esc_html__('The password for this account.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="manual_additional_notes"><?php echo esc_html__('Additional Notes', 'secure-login-collector'); ?></label>
                                </th>
                                <td>
                                    <textarea id="manual_additional_notes" name="manual_additional_notes" rows="4" class="large-text" placeholder="<?php echo esc_attr__('Any additional information, security questions, backup codes, etc. (optional)', 'secure-login-collector'); ?>"></textarea>
                                    <p class="description"><?php echo esc_html__('Optional: Any additional information like security questions, backup codes, or special instructions.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <?php if ($this->is_pro_version && get_option('secure_login_ultra_secure_mode', false) && get_option('secure_login_passkey_registered', false)) : ?>
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html__('Encryption Method', 'secure-login-collector'); ?>
                                </th>
                                <td>
                                    <strong><?php echo esc_html__('🔐 Ultra-Secure (AES-256 + RSA-2048 + Passkey)', 'secure-login-collector'); ?></strong>
                                    <p class="description"><?php echo esc_html__('Ultra-secure mode is enabled. All entries will be encrypted with triple-layer protection.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <?php else : ?>
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html__('Encryption Method', 'secure-login-collector'); ?>
                                </th>
                                <td>
                                    <strong><?php echo esc_html__('🔒 Secure (AES-256 + RSA-2048)', 'secure-login-collector'); ?></strong>
                                    <p class="description"><?php echo esc_html__('Standard encryption with AES-256 and RSA-2048 protection.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary" id="save-manual-entry">
                                <?php echo esc_html__('Save Entry', 'secure-login-collector'); ?>
                            </button>
                            <button type="button" class="button" id="cancel-manual-entry">
                                <?php echo esc_html__('Cancel', 'secure-login-collector'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Get human-readable encryption method information.
     *
     * @param string $encryption_type The encryption type.
     * @return array Encryption method information.
     */
    private function get_encryption_method_info($encryption_type)
    {
        switch ($encryption_type) {
            case 'aes-rsa-v2':
                return array(
                    'name'        => __('🔐 AES-256 + RSA', 'secure-login-collector'),
                    'class'       => 'encryption-aes-rsa',
                    'description' => __('AES-256-GCM encryption with RSA key protection.', 'secure-login-collector'),
                );
            case 'aes-rsa-passkey-v2':
                return array(
                    'name'        => __('🔐 Ultra-Secure (Passkey)', 'secure-login-collector'),
                    'class'       => 'encryption-ultra-secure',
                    'description' => __('AES-256-GCM + RSA with passkey authentication required for decryption.', 'secure-login-collector'),
                );
            case 'passkey_derived':
                return array(
                    'name'        => __('🔐 Ultra-Secure (Passkey)', 'secure-login-collector'),
                    'class'       => 'encryption-ultra-secure',
                    'description' => __('Passkey-derived encryption for maximum security.', 'secure-login-collector'),
                );
            case 'rsa':
                return array(
                    'name'        => __('🔒 RSA-2048', 'secure-login-collector'),
                    'class'       => 'encryption-rsa',
                    'description' => __('Industry-standard RSA encryption.', 'secure-login-collector'),
                );
            default:
                return array(
                    'name'        => __('🔒 RSA-2048', 'secure-login-collector'),
                    'class'       => 'encryption-rsa',
                    'description' => __('Industry-standard RSA encryption.', 'secure-login-collector'),
                );
        }
    }

    // AJAX Handlers.

    /**
     * Handle bulk export AJAX request.
     */
    public function handle_bulk_export_ajax()
    {
        // Verify nonce for security.
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'secure_login_nonce')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }

        // Check if user has admin capabilities.
        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }

        // Get the export request from transient.
        $export_data = get_transient('secure_login_bulk_export_' . get_current_user_id());

        if (! $export_data) {
            wp_send_json_error(__('Export request not found or expired.', 'secure-login-collector'));
            return;
        }

        $manager = $export_data['manager'];
        $ids     = $export_data['ids'];

        // Clean up the transient.
        delete_transient('secure_login_bulk_export_' . get_current_user_id());

        // Collect export data.
        $csv_data = array();

        foreach ($ids as $id) {
            $row = $this->database_manager->get_entry($id);
            if (! $row) {
                continue;
            }

            $metadata = json_decode($row->metadata, true);

            // For bulk export, we'll include a note that this needs to be decrypted.
            $csv_data[] = array(
                'name'     => $metadata['name'] ?? 'Unknown',
                'website'  => $metadata['login_url'] ?? $metadata['service_name'] ?? '',
                'username' => '[ENCRYPTED - DECRYPT FIRST]',
                'password' => '[ENCRYPTED - DECRYPT FIRST]',
                'notes'    => 'Entry ID: ' . $id . ' - Please decrypt individual entries before export',
            );
        }

        wp_send_json_success(
            array(
                'manager' => $manager,
                'data'    => $csv_data,
                /* translators: %d: number of entries */
                'message' => sprintf(__('Bulk export prepared for %d entries.', 'secure-login-collector'), count($csv_data)),
            )
        );
    }

    /**
     * Handle bulk decrypt with passkey AJAX request.
     */
    public function handle_bulk_decrypt_with_passkey_ajax()
    {
        // Verify nonce for security.
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'secure_login_nonce')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }

        // Check if user has admin capabilities.
        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }

        // Check if this is pro version with passkey.
        if (! $this->is_pro_version) {
            wp_send_json_error(__('Pro version required.', 'secure-login-collector'));
            return;
        }

        if (! get_option('secure_login_passkey_registered', false)) {
            wp_send_json_error(__('Passkey not registered.', 'secure-login-collector'));
            return;
        }

        $entry_ids        = isset($_POST['entry_ids']) ? array_map('intval', wp_unslash($_POST['entry_ids'])) : array();
        $manager          = isset($_POST['manager']) ? sanitize_text_field(wp_unslash($_POST['manager'])) : '';
        $passkey_verified = isset($_POST['passkey_verified']) && sanitize_text_field(wp_unslash($_POST['passkey_verified'])) === 'true';

        if (empty($manager)) {
            wp_send_json_error(__('Missing export manager.', 'secure-login-collector'));
            return;
        }

        // If passkey is not yet verified, validate entry_ids from POST and store the request.
        if (! $passkey_verified) {
            if (empty($entry_ids)) {
                wp_send_json_error(__('No entries selected for export.', 'secure-login-collector'));
                return;
            }

            set_transient(
                'secure_login_bulk_decrypt_request_' . get_current_user_id(),
                array(
                    'entry_ids' => $entry_ids,
                    'manager'   => $manager,
                ),
                300
            ); // 5 minutes.

            /* translators: 1: number of entries, 2: manager format name */
            wp_send_json_success(
                array(
                    'requires_passkey' => true,
                    'entry_count'      => count($entry_ids),
                    'manager'          => $manager,
                    // translators: %1$d: number of entries selected, %2$s: password manager name.
                    'message'          => sprintf(__('You have selected %1$d entries for bulk export. All selected entries will be decrypted using your passkey and then exported to %2$s format.', 'secure-login-collector'), count($entry_ids), $manager),
                )
            );
            return;
        }

        // Passkey is verified, proceed with bulk decryption.
        $signature = isset($_POST['signature']) ? sanitize_text_field(wp_unslash($_POST['signature'])) : '';
        if (empty($signature)) {
            wp_send_json_error(__('Missing passkey signature.', 'secure-login-collector'));
            return;
        }

        // Get the stored request.
        $bulk_request = get_transient('secure_login_bulk_decrypt_request_' . get_current_user_id());
        if (! $bulk_request) {
            wp_send_json_error(__('Bulk decrypt request not found or expired.', 'secure-login-collector'));
            return;
        }

        // Clean up the transient.
        delete_transient('secure_login_bulk_decrypt_request_' . get_current_user_id());

        // Set authentication flag that the encryption handler expects.
        set_transient('secure_login_passkey_authenticated_' . get_current_user_id(), true, 300); // 5 minutes.

        // Decrypt all entries.
        $csv_data         = array();
        $successful_count = 0;
        $failed_count     = 0;

        foreach ($bulk_request['entry_ids'] as $id) {
            $row = $this->database_manager->get_entry($id);
            if (! $row) {
                ++$failed_count;
                continue;
            }

            $metadata        = json_decode($row->metadata, true);
            $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'rsa';

            // Decrypt using encryption handler with passkey authentication.
            $decrypted_data = $this->encryption_handler->decrypt_data($row->encrypted_data, $encryption_type);

            if (false === $decrypted_data) {
                ++$failed_count;
                continue;
            }

            // Parse the decrypted data.
            // Check if decrypted data is already an array or needs JSON decoding.
            if (is_array($decrypted_data)) {
                $login_data = $decrypted_data;
            } elseif (is_string($decrypted_data)) {
                $login_data = json_decode($decrypted_data, true);
                if (! $login_data) {
                    ++$failed_count;
                    continue;
                }
            } else {
                ++$failed_count;
                continue;
            }

            // Prepare CSV data based on manager format.
            $name     = $metadata['name'] ?? 'Unknown';
            $website  = $metadata['login_url'] ?? $metadata['service_name'] ?? '';
            $username = $login_data['username_email'] ?? '';
            $password = $login_data['password'] ?? '';
            $notes    = $login_data['additional_notes'] ?? '';

            // Ensure website has protocol.
            if ($website && ! preg_match('/^https?:\/\//', $website)) {
                $website = 'https://' . $website;
            }

            $csv_data[] = array(
                'name'     => $name,
                'website'  => $website,
                'username' => $username,
                'password' => $password,
                'notes'    => $notes,
            );

            ++$successful_count;
        }

        // Clean up the authentication flag after all decryptions are complete.
        delete_transient('secure_login_passkey_authenticated_' . get_current_user_id());

        $message = '';
        if ($successful_count > 0 && 0 === $failed_count) {
            // translators: %d: number of entries successfully decrypted.
            $message = sprintf(__('Successfully decrypted %d entries. Generating CSV...', 'secure-login-collector'), $successful_count);
        } elseif ($successful_count > 0 && $failed_count > 0) {
            $message = __('Bulk decryption failed for some entries. Only successfully decrypted entries were exported.', 'secure-login-collector');
        } else {
            wp_send_json_error(__('Bulk decryption failed for all entries.', 'secure-login-collector'));
            return;
        }

        wp_send_json_success(
            array(
                'csv_data'         => $csv_data,
                'manager'          => $bulk_request['manager'],
                'successful_count' => $successful_count,
                'failed_count'     => $failed_count,
                'message'          => $message,
            )
        );
    }


    /**
     * Get client IP address.
     *
     * @return string Client IP address.
     */
    private function get_client_ip()
    {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', sanitize_text_field(wp_unslash($_SERVER[$key]))) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'Unknown';
    }

    /**
     * Handle delete AJAX request.
     */
    public function handle_delete_ajax()
    {
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'secure_login_nonce') || ! current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }

        $delete_id = intval(wp_unslash($_POST['delete_id'] ?? 0));
        if (empty($delete_id)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }

        $result = $this->database_manager->delete_entry($delete_id);
        if (false === $result) {
            wp_send_json_error(__('Failed to delete data.', 'secure-login-collector'));
            return;
        }

        wp_send_json_success(__('Data deleted successfully.', 'secure-login-collector'));
    }

    /**
     * Handle extend retention AJAX request.
     */
    public function handle_extend_ajax()
    {
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'secure_login_nonce') || ! current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }

        $extend_id = intval(wp_unslash($_POST['extend_id'] ?? 0));
        if (empty($extend_id)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }

        $result = $this->database_manager->extend_retention($extend_id);
        if (false === $result) {
            wp_send_json_error(__('Failed to extend retention.', 'secure-login-collector'));
            return;
        }

        // Get updated expiration display.
        $row                    = $this->database_manager->get_entry($extend_id);
        $new_expiration_display = $this->database_manager->calculate_expiration($row->retention_until);
        $expiration_days        = get_option('secure_login_expiration_days', 30);
        // translators: %d: number of days.
        wp_send_json_success(
            array(
                'message'        => sprintf(__('Retention period extended by %d days.', 'secure-login-collector'), $expiration_days),
                'new_expiration' => $new_expiration_display,
            )
        );
    }

    /**
     * Handle save manual login data AJAX request.
     */
    public function handle_save_manual_login_data()
    {
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'secure_login_nonce') || ! current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }

        // Sanitize and validate input.
        $login_data        = sanitize_textarea_field(wp_unslash($_POST['login_data'] ?? ''));
        $metadata          = wp_unslash($_POST['metadata'] ?? ''); // Don't sanitize JSON data as it corrupts the format.

        if (empty($login_data) || empty($metadata)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }

        // Validate metadata is valid JSON.
        $metadata_array = json_decode($metadata, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            wp_send_json_error(__('Invalid metadata format.', 'secure-login-collector'));
            return;
        }

        // Validate required metadata fields.
        if (
            ! isset($metadata_array['email']) || empty($metadata_array['email'])
            || ! isset($metadata_array['name']) || empty($metadata_array['name'])
            || ! isset($metadata_array['login_url']) || empty($metadata_array['login_url'])
        ) {
            wp_send_json_error(__('Missing required metadata fields.', 'secure-login-collector'));
            return;
        }

        // Sanitize metadata fields.
        $metadata_array['email']           = sanitize_email($metadata_array['email']);
        $metadata_array['name']            = sanitize_text_field($metadata_array['name']);
        $metadata_array['login_url']       = sanitize_text_field($metadata_array['login_url']);
        $metadata_array['manually_added']  = true;
        $metadata_array['added_by_user']   = get_current_user_id();
        $metadata_array['created_at']      = current_time('c');

        // The login_data already contains the structured data to encrypt.
        $data_to_encrypt = $login_data;

        // Create v2 format encryption package - matching frontend-secure.js encryption flow
        // Generate AES key and IV
        $aes_key = openssl_random_pseudo_bytes(32); // 256-bit key
        $iv = openssl_random_pseudo_bytes(12); // 96-bit IV for GCM
        $salt = openssl_random_pseudo_bytes(32); // 32 bytes salt like frontend
        
        // Encrypt the data with AES-GCM
        $cipher = 'aes-256-gcm';
        $tag = '';
        $encrypted_content = openssl_encrypt(
            $data_to_encrypt,
            $cipher,
            $aes_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if (false === $encrypted_content) {
            wp_send_json_error(__('AES encryption failed.', 'secure-login-collector'));
            return;
        }
        
        // Combine encrypted content with auth tag for GCM
        $encrypted_with_tag = $encrypted_content . $tag;
        
        // Get RSA public key
        $public_key = $this->encryption_handler->get_public_key();
        if (is_wp_error($public_key)) {
            wp_send_json_error(__('RSA keys not available.', 'secure-login-collector'));
            return;
        }
        
        // Check if ultra-secure mode is enabled (Pro with passkey)
        $is_pro_encrypted = false;
        $server_credential_id = null;
        
        if ($this->is_pro_version && get_option('secure_login_passkey_registered', false)) {
            // For ultra-secure mode, mark as pro encrypted
            // The passkey encryption happens client-side during decryption
            $is_pro_encrypted = true;
            $server_credential_id = get_option('secure_login_passkey_credential_id', '');
        }
        
        // RSA encrypt the AES key (raw bytes, not base64)
        $encrypted_aes_key = '';
        if (!openssl_public_encrypt($aes_key, $encrypted_aes_key, $public_key, OPENSSL_PKCS1_OAEP_PADDING)) {
            wp_send_json_error(__('RSA key encryption failed.', 'secure-login-collector'));
            return;
        }
        
        // Create the v2 encrypted package matching frontend format exactly
        $encrypted_package = array(
            'encryptedData'   => base64_encode($encrypted_with_tag),
            'rsaEncryptedKey' => base64_encode($encrypted_aes_key),
            'iv'              => base64_encode($iv),
            'salt'            => base64_encode($salt), // Base64 encode like frontend
            'isProEncrypted'  => $is_pro_encrypted,
            'credentialId'    => $server_credential_id,
            'version'         => 2,
        );
        
        // Update metadata for v2 format
        $metadata_array['encryption_type'] = $is_pro_encrypted ? 'aes-rsa-passkey-v2' : 'aes-rsa-v2';
        $metadata_array['encryption_version'] = 2;
        $metadata_array['is_pro_encrypted'] = $is_pro_encrypted;
        
        // Store as JSON-encoded package
        $encrypted_data = wp_json_encode($encrypted_package);

        // Re-encode the metadata.
        $metadata = wp_json_encode($metadata_array);

        // Calculate retention_until based on expiration settings.
        $expiration_days = get_option('secure_login_expiration_days', 30);
        $retention_until = null;
        if ($expiration_days > 0) {
            $retention_until = gmdate('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
        }

        // Prepare data for database insertion.
        $data = array(
            'encrypted_data'  => $encrypted_data, // Already JSON-encoded v2 package
            'metadata'        => $metadata,
            'user_id'         => get_current_user_id(), // Manual entries are associated with the admin user.
            'ip_address'      => $this->get_client_ip(),
            'user_agent'      => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'created_at'      => current_time('mysql'),
            'retention_until' => $retention_until,
        );

        // Insert into database using database manager.
        $result = $this->database_manager->insert_entry($data);

        if (false === $result) {
            wp_send_json_error(__('Failed to save data to database.', 'secure-login-collector'));
            return;
        }

        wp_send_json_success(__('Login data saved successfully.', 'secure-login-collector'));
    }

    /**
     * Handle update metadata AJAX request.
     */
    public function handle_update_metadata_ajax()
    {
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'secure_login_nonce') || ! current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }

        $update_id    = intval(wp_unslash($_POST['update_id'] ?? 0));
        $new_metadata = wp_unslash($_POST['metadata'] ?? array()); // Don't sanitize before validation as it's an array.

        if (empty($update_id) || empty($new_metadata)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }

        // Get current metadata and update only the specified fields.
        $row = $this->database_manager->get_entry($update_id);
        if (! $row) {
            wp_send_json_error(__('Entry not found.', 'secure-login-collector'));
            return;
        }

        $current_metadata = json_decode($row->metadata, true);

        // Update only the provided fields.
        foreach ($new_metadata as $field => $value) {
            $current_metadata[$field] = sanitize_text_field($value);
        }

        $updated_metadata = wp_json_encode($current_metadata);

        $result = $this->database_manager->update_entry_metadata($update_id, $updated_metadata);

        if (false === $result) {
            wp_send_json_error(__('Failed to update metadata.', 'secure-login-collector'));
            return;
        }

        wp_send_json_success(__('Metadata updated successfully.', 'secure-login-collector'));
    }

    /**
     * Handle AJAX request to get encryption info for an entry.
     * This is used to check if passkey authentication is needed before decryption.
     *
     * @return void
     */
    public function handle_get_encryption_info()
    {
        // Check permissions and nonce.
        if (
            ! current_user_can('manage_options') ||
            ! isset($_POST['nonce']) ||
            ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'secure_login_nonce')
        ) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }

        $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
        if (! $entry_id) {
            wp_send_json_error(__('Invalid entry ID.', 'secure-login-collector'));
            return;
        }

        // Get the encrypted data from database.
        $entry = $this->database_manager->get_entry($entry_id);
        if (! $entry) {
            wp_send_json_error(__('Entry not found.', 'secure-login-collector'));
            return;
        }

        // Parse the encrypted package.
        $encrypted_package = json_decode($entry->encrypted_data, true);
        if (! $encrypted_package || ! isset($encrypted_package['version']) || $encrypted_package['version'] !== 2) {
            wp_send_json_error(__('Invalid encryption format.', 'secure-login-collector'));
            return;
        }

        // Return encryption info.
        wp_send_json_success(array(
            'isProEncrypted' => $encrypted_package['isProEncrypted'] ?? false,
            'credentialId'   => $encrypted_package['credentialId'] ?? null,
        ));
    }

    /**
     * Handle AJAX decrypt request for v2 encrypted data.
     * This handles the new AES-GCM + RSA + optional Passkey encryption format.
     *
     * @return void
     */
    public function handle_decrypt_ajax_v2()
    {
        // Check permissions and nonce.
        if (
            ! current_user_can('manage_options') ||
            ! isset($_POST['nonce']) ||
            ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'secure_login_nonce')
        ) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }

        $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
        if (! $entry_id) {
            wp_send_json_error(__('Invalid entry ID.', 'secure-login-collector'));
            return;
        }

        // Get the encrypted data from database.
        $entry = $this->database_manager->get_entry($entry_id);
        if (! $entry) {
            wp_send_json_error(__('Entry not found.', 'secure-login-collector'));
            return;
        }

        // Parse the encrypted package.
        $encrypted_package = json_decode($entry->encrypted_data, true);
        if (! $encrypted_package || ! isset($encrypted_package['version']) || $encrypted_package['version'] !== 2) {
            wp_send_json_error(__('Invalid encryption format. This entry uses an older encryption format.', 'secure-login-collector'));
            return;
        }

        // Get the RSA-encrypted key.
        $rsa_encrypted_key = $encrypted_package['rsaEncryptedKey'];

        // Decrypt the RSA layer to get the AES key (or passkey-encrypted AES key).
        $decrypted_key_data = $this->encryption_handler->decrypt_rsa_key($rsa_encrypted_key);

        if (false === $decrypted_key_data) {
            wp_send_json_error(__('Failed to decrypt RSA layer.', 'secure-login-collector'));
            return;
        }

        // Return the encrypted package and RSA-decrypted key to frontend.
        // The frontend will handle passkey decryption if needed.
        wp_send_json_success(array(
            'encryptedPackage' => $encrypted_package,
            'rsaDecryptedKey'  => $decrypted_key_data,
            'metadata'         => json_decode($entry->metadata, true),
        ));
    }
}
