<?php
/**
 * Admin Interface Class
 * 
 * Handles admin interface operations including:
 * - Admin pages and menus
 * - Data viewing and management
 * - AJAX handlers for admin operations
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Include WP_List_Table if not already included
if (!class_exists('WP_List_Table')) {
    include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Secure Login List Table Class extending WP_List_Table
 */
class Secure_Login_List_Table extends WP_List_Table
{
    
    private $table_name;
    private $database_manager;
    private $encryption_handler;
    private $is_pro_version;
    
    public function __construct($table_name, $database_manager, $encryption_handler, $is_pro_version)
    {
        $this->table_name = $table_name;
        $this->database_manager = $database_manager;
        $this->encryption_handler = $encryption_handler;
        $this->is_pro_version = $is_pro_version;
        
        parent::__construct(
            array(
            'singular' => 'login_entry',
            'plural'   => 'login_entries',
            'ajax'     => false
            )
        );
    }
    
    /**
     * Define table columns
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
            'actions'    => __('Actions', 'secure-login-collector')
        );
    }
    
    /**
     * Define sortable columns
     */
    public function get_sortable_columns()
    {
        return array(
            'email'      => array('email', false),
            'name'       => array('name', false),
            'login_url'  => array('login_url', false),
            'created_at' => array('created_at', false),
            'encryption' => array('encryption', false),
            'expires'    => array('expires', false)
        );
    }
    
    /**
     * Define bulk actions
     */
    public function get_bulk_actions()
    {
        return array(
            'export-bitwarden'  => __('Export Bitwarden CSV', 'secure-login-collector'),
            'export-1password'  => __('Export 1Password CSV', 'secure-login-collector'),
            'export-lastpass'   => __('Export LastPass CSV', 'secure-login-collector'),
            'export-chrome'     => __('Export Chrome CSV', 'secure-login-collector'),
            'export-firefox'    => __('Export Firefox CSV', 'secure-login-collector'),
            'export-safari'     => __('Export Safari CSV', 'secure-login-collector'),
            'export-dashlane'   => __('Export Dashlane CSV', 'secure-login-collector'),
            'export-keepass'    => __('Export KeePass CSV', 'secure-login-collector'),
            'delete'            => __('Delete', 'secure-login-collector')
        );
    }
    
    /**
     * Render checkbox column
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
     * Render email column
     */
    public function column_email($item)
    {
        $metadata = json_decode($item->metadata, true);
        $email = $metadata['email'] ?? __('N/A', 'secure-login-collector');
        
        return sprintf(
            '<span class="editable-field" data-field="email" data-id="%s">%s</span>',
            $item->id,
            esc_html($email)
        );
    }
    
    /**
     * Render name column
     */
    public function column_name($item)
    {
        $metadata = json_decode($item->metadata, true);
        $name = $metadata['name'] ?? __('N/A', 'secure-login-collector');
        
        return sprintf(
            '<span class="editable-field" data-field="name" data-id="%s">%s</span>',
            $item->id,
            esc_html($name)
        );
    }
    
    /**
     * Render login URL column
     */
    public function column_login_url($item)
    {
        $metadata = json_decode($item->metadata, true);
        $login_url = $metadata['login_url'] ?? $metadata['service_name'] ?? __('Not provided', 'secure-login-collector');
        
        return sprintf(
            '<span class="editable-field" data-field="login_url" data-id="%s">%s</span>',
            $item->id,
            esc_html($login_url)
        );
    }
    
    /**
     * Render created date column
     */
    public function column_created_at($item)
    {
        return esc_html(date('M j, Y g:i A', strtotime($item->created_at)));
    }
    
    /**
     * Render encryption method column
     */
    public function column_encryption($item)
    {
        $metadata = json_decode($item->metadata, true);
        $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'xor';
        $encryption_info = $this->get_encryption_method_info($encryption_type);
        
        return sprintf(
            '<span class="encryption-method %s" title="%s">%s</span>',
            esc_attr($encryption_info['class']),
            esc_attr($encryption_info['description']),
            $encryption_info['name']
        );
    }
    
    /**
     * Render expires column
     */
    public function column_expires($item)
    {
        return $this->database_manager->calculate_expiration($item->retention_until);
    }
    
    /**
     * Render actions column
     */
    public function column_actions($item)
    {
        $metadata = json_decode($item->metadata, true);
        $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'xor';
        $hostname = isset($metadata['key_hostname']) ? $metadata['key_hostname'] : '';
        $timestamp_suffix = isset($metadata['key_timestamp_suffix']) ? $metadata['key_timestamp_suffix'] : '';
        
        $actions = array();
        
        // Edit button with icon
        $actions[] = sprintf(
            '<button type="button" class="button edit-btn" data-id="%s" title="%s"><span class="dashicons dashicons-edit"></span></button>',
            $item->id,
            esc_attr__('Edit entry', 'secure-login-collector')
        );
        
        // Save/Cancel buttons (hidden by default) with icons
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
        
        // Decrypt button with icon
        $actions[] = sprintf(
            '<button type="button" class="button decrypt-btn" data-id="%s" data-hostname="%s" data-timestamp="%s" data-encryption-type="%s" title="%s"><span class="dashicons dashicons-unlock"></span></button>',
            $item->id,
            esc_attr($hostname),
            esc_attr($timestamp_suffix),
            esc_attr($encryption_type),
            esc_attr__('Decrypt data', 'secure-login-collector')
        );
        
        // Extend button (if expiration is enabled) with icon
        $expiration_days = get_option('secure_login_expiration_days', 30);
        if ($expiration_days > 0) {
            $actions[] = sprintf(
                '<button type="button" class="button button-secondary extend-btn" data-id="%s" title="%s"><span class="dashicons dashicons-calendar-alt"></span></button>',
                $item->id,
                esc_attr__('Extend retention period', 'secure-login-collector')
            );
        }
        
        // Delete button with icon
        $actions[] = sprintf(
            '<button type="button" class="button button-secondary delete-btn" data-id="%s" title="%s"><span class="dashicons dashicons-trash" style="color: #d63384;"></span></button>',
            $item->id,
            esc_attr__('Delete entry', 'secure-login-collector')
        );
        
        return implode(' ', $actions);
    }
    
    /**
     * Get encryption method info
     */
    private function get_encryption_method_info($encryption_type)
    {
        switch ($encryption_type) {
        case 'passkey_derived':
            return array(
                    'name' => __('🔐 Ultra-Secure (Passkey)', 'secure-login-collector'),
                    'class' => 'encryption-ultra-secure',
                    'description' => __('Passkey-derived encryption for maximum security.', 'secure-login-collector')
                );
        case 'rsa':
            return array(
                    'name' => __('🔒 RSA-2048', 'secure-login-collector'),
                    'class' => 'encryption-rsa',
                    'description' => __('Industry-standard RSA encryption.', 'secure-login-collector')
                );
        case 'xor':
        default:
            return array(
                    'name' => __('🔓 XOR (Legacy)', 'secure-login-collector'),
                    'class' => 'encryption-xor',
                    'description' => __('Legacy XOR encryption.', 'secure-login-collector')
                );
        }
    }
    
    /**
     * Prepare table items
     */
    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Handle bulk actions
        $this->process_bulk_action();
        
        // Get search term
        $search = isset($_REQUEST['s']) ? wp_unslash(trim($_REQUEST['s'])) : '';
        
        // Get sorting parameters
        $orderby = isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'created_at';
        $order = isset($_REQUEST['order']) ? $_REQUEST['order'] : 'desc';
        
        // Get pagination parameters
        $per_page = $this->get_items_per_page('login_entries_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        
        // Get data
        $data = $this->database_manager->get_entries_for_list_table($search, $orderby, $order, $per_page, $offset);
        $total_items = $this->database_manager->get_total_entries($search);
        
        $this->items = $data;
        
        // Set pagination
        $this->set_pagination_args(
            array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
            )
        );
    }
    
    /**
     * Process bulk actions
     */
    public function process_bulk_action()
    {
        $action = $this->current_action();
        
        if (!$action) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-login_entries')) {
            wp_die(__('Security check failed.', 'secure-login-collector'));
        }
        
        $ids = isset($_REQUEST['login_entries']) ? array_map('intval', $_REQUEST['login_entries']) : array();
        
        if (empty($ids)) {
            return;
        }
        
        if ($action === 'delete') {
            foreach ($ids as $id) {
                $this->database_manager->delete_entry($id);
            }
            
            $message = sprintf(
                _n('%d entry deleted.', '%d entries deleted.', count($ids), 'secure-login-collector'),
                count($ids)
            );
            
            add_settings_error('secure_login_bulk', 'bulk_delete', $message, 'updated');
        } elseif (strpos($action, 'export-') === 0) {
            // Handle CSV exports via AJAX (will be processed by JavaScript)
            $manager = str_replace('export-', '', $action);
            
            // Store export request in transient for AJAX processing
            set_transient(
                'secure_login_bulk_export_' . get_current_user_id(), array(
                'manager' => $manager,
                'ids' => $ids
                ), 300
            );
            
            $message = sprintf(
                __('Bulk export initiated for %d entries. Please wait...', 'secure-login-collector'),
                count($ids)
            );
            
            add_settings_error('secure_login_bulk', 'bulk_export', $message, 'updated');
        }
    }
    
    /**
     * Display the search box
     */
    public function search_box($text, $input_id)
    {
        if (empty($_REQUEST['s']) && !$this->has_items()) {
            return;
        }
        
        $input_id = $input_id . '-search-input';
        
        if (!empty($_REQUEST['orderby'])) {
            echo '<input type="hidden" name="orderby" value="' . esc_attr($_REQUEST['orderby']) . '" />';
        }
        if (!empty($_REQUEST['order'])) {
            echo '<input type="hidden" name="order" value="' . esc_attr($_REQUEST['order']) . '" />';
        }
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo $text; ?>:</label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" value="<?php echo esc_attr($_REQUEST['s'] ?? ''); ?>" placeholder="<?php echo esc_attr__('Search in email, name, login URL...', 'secure-login-collector'); ?>" />
            <?php submit_button($text, '', '', false, array('id' => 'search-submit')); ?>
        </p>
        <?php
    }
    
    /**
     * Override the single row display to add decrypted data row
     */
    public function single_row($item)
    {
        echo '<tr id="row-' . $item->id . '">';
        $this->single_row_columns($item);
        echo '</tr>';
        
        // Add hidden row for decrypted data
        echo '<tr id="decrypted-row-' . $item->id . '" class="decrypted-data-row" style="display: none;">';
        echo '<td colspan="' . count($this->get_columns()) . '">';
        echo '<div class="decrypted-content">';
        echo '<h4>' . __('Decrypted Data:', 'secure-login-collector') . '</h4>';
        echo '<div class="decrypted-json" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 3px; max-height: 600px; overflow-y: auto;"></div>';
        echo '<div style="margin-top: 10px;">';
        echo '<button type="button" class="button button-primary export-to-password-manager" data-id="' . $item->id . '">' . __('Export to Password Manager', 'secure-login-collector') . '</button>';
        echo '<button type="button" class="button hide-decrypted" data-id="' . $item->id . '">' . __('Hide', 'secure-login-collector') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
}

class Secure_Login_Admin_Interface
{
    
    private $table_name;
    private $is_pro_version;
    private $encryption_handler;
    private $database_manager;
    private $list_table;
    
    public function __construct($table_name, $is_pro_version, $encryption_handler, $database_manager)
    {
        $this->table_name = $table_name;
        $this->is_pro_version = $is_pro_version;
        $this->encryption_handler = $encryption_handler;
        $this->database_manager = $database_manager;
        
        // Register hooks.
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Register AJAX handlers.
        add_action('wp_ajax_decrypt_secure_login_data', array($this, 'handle_decrypt_ajax'));
        add_action('wp_ajax_delete_secure_login_data', array($this, 'handle_delete_ajax'));
        add_action('wp_ajax_extend_secure_login_data', array($this, 'handle_extend_ajax'));
        add_action('wp_ajax_save_manual_login_data', array($this, 'handle_save_manual_login_data'));
        add_action('wp_ajax_update_secure_login_metadata', array($this, 'handle_update_metadata_ajax'));
        add_action('wp_ajax_process_bulk_export', array($this, 'handle_bulk_export_ajax'));
        add_action('wp_ajax_bulk_decrypt_with_passkey', array($this, 'handle_bulk_decrypt_with_passkey_ajax'));
        
        // Add screen option for items per page
        add_action('load-toplevel_page_secure-login-data', array($this, 'add_screen_options'));
    }
    
    /**
     * Add screen options for the admin page
     */
    public function add_screen_options()
    {
        add_screen_option(
            'per_page', array(
            'label' => __('Entries per page', 'secure-login-collector'),
            'default' => 20,
            'option' => 'login_entries_per_page'
            )
        );
        
        // Initialize the list table
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
        add_menu_page(
            __('Secure Login Data', 'secure-login-collector'),
            __('Login Data', 'secure-login-collector'),
            'manage_options',
            'secure-login-data',
            array($this, 'admin_page'),
            'dashicons-lock',
            30
        );
    }
    
    /**
     * Enqueue admin scripts and localize AJAX data.
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on our admin page.
        if (!in_array($hook, array('toplevel_page_secure-login-data'))) {
            return;
        }
        
        // Enqueue CSS file
        wp_enqueue_style(
            'secure-login-admin-css',
            plugin_dir_url(__FILE__) . '../assets/css/admin.css',
            array(),
            '1.0.0'
        );
        
        // Enqueue JavaScript file
        wp_enqueue_script(
            'secure-login-admin-js',
            plugin_dir_url(__FILE__) . '../assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script(
            'secure-login-admin-js', 'secureLoginAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('secure_login_nonce')
            )
        );
        
        // Localize script with configuration data
        wp_localize_script(
            'secure-login-admin-js', 'secureLoginConfig', array(
            'isProVersion' => $this->is_pro_version,
            'passkeyRegistered' => get_option('secure_login_passkey_registered', false),
            'currentUserId' => get_current_user_id()
            )
        );
        
        // Localize script with translatable messages
        wp_localize_script(
            'secure-login-admin-js', 'secureLoginMessages', array(
            'noDecryptedData' => __('No decrypted data available. Please decrypt the data first.', 'secure-login-collector'),
            'fillAllFields' => __('Please fill in all required fields.', 'secure-login-collector'),
            'saving' => __('Saving...', 'secure-login-collector'),
            'dataSavedSuccess' => __('Login data saved successfully!', 'secure-login-collector'),
            'errorSavingData' => __('Error saving data: ', 'secure-login-collector'),
            'unknownError' => __('Unknown error', 'secure-login-collector'),
            'networkError' => __('Network error occurred while saving data.', 'secure-login-collector'),
            'saveEntry' => __('Save Entry', 'secure-login-collector'),
            'bulkDecryptWithPasskey' => __('Bulk Decrypt with Passkey', 'secure-login-collector'),
            'authenticateWithPasskeyToDecryptAll' => __('Authenticate with Passkey to Decrypt All', 'secure-login-collector'),
            'bulkDecryptionCompleted' => __('Bulk decryption completed. CSV file downloaded.', 'secure-login-collector')
            )
        );
        
        // Add inline script to make nonce available globally (kept for backward compatibility)
        wp_add_inline_script('secure-login-admin-js', 'window.secureLoginNonce = "' . wp_create_nonce('secure_login_nonce') . '";');
    }
    
    /**
     * Admin page for viewing collected login data using WP_List_Table.
     */
    public function admin_page()
    {
        // Initialize list table if not already done
        if (!$this->list_table) {
            $this->list_table = new Secure_Login_List_Table(
                $this->table_name,
                $this->database_manager,
                $this->encryption_handler,
                $this->is_pro_version
            );
        }
        
        // Prepare table items
        $this->list_table->prepare_items();
        
        // Display admin notices
        settings_errors('secure_login_bulk');
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Secure Login Data', 'secure-login-collector'); ?></h1>
            <a href="#" class="page-title-action" id="add-new-entry-btn"><?php echo esc_html__('Add New Entry', 'secure-login-collector'); ?></a>
            <hr class="wp-header-end">
            
            <p><?php echo esc_html__('This page shows all encrypted login data collected from clients. Use the search box to filter entries and bulk actions for management.', 'secure-login-collector'); ?></p>
            
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
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
                            <tr>
                                <th scope="row">
                                    <label for="manual_encryption_method"><?php echo esc_html__('Encryption Method', 'secure-login-collector'); ?></label>
                                </th>
                                <td>
                                    <select id="manual_encryption_method" name="manual_encryption_method">
                                        <?php if ($this->is_pro_version && get_option('secure_login_passkey_registered', false)) : ?>
                                            <option value="passkey_derived"><?php echo esc_html__('🔐 Ultra-Secure (Passkey)', 'secure-login-collector'); ?></option>
                                        <?php endif; ?>
                                        <option value="rsa" selected><?php echo esc_html__('🔒 RSA-2048', 'secure-login-collector'); ?></option>
                                        <option value="xor"><?php echo esc_html__('🔓 XOR (Legacy)', 'secure-login-collector'); ?></option>
                                    </select>
                                    <p class="description"><?php echo esc_html__('Choose the encryption method for this entry.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
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
     */
    private function get_encryption_method_info($encryption_type)
    {
        switch ($encryption_type) {
        case 'passkey_derived':
            return array(
                    'name' => __('🔐 Ultra-Secure (Passkey)', 'secure-login-collector'),
                    'class' => 'encryption-ultra-secure',
                    'description' => __('Passkey-derived encryption for maximum security.', 'secure-login-collector')
                );
        case 'rsa':
            return array(
                    'name' => __('🔒 RSA-2048', 'secure-login-collector'),
                    'class' => 'encryption-rsa',
                    'description' => __('Industry-standard RSA encryption.', 'secure-login-collector')
                );
        case 'xor':
        default:
            return array(
                    'name' => __('🔓 XOR (Legacy)', 'secure-login-collector'),
                    'class' => 'encryption-xor',
                    'description' => __('Legacy XOR encryption.', 'secure-login-collector')
                );
        }
    }
    
    // AJAX Handlers
    
    /**
     * Handle bulk export AJAX request
     */
    public function handle_bulk_export_ajax()
    {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Get the export request from transient
        $export_data = get_transient('secure_login_bulk_export_' . get_current_user_id());
        
        if (!$export_data) {
            wp_send_json_error(__('Export request not found or expired.', 'secure-login-collector'));
            return;
        }
        
        $manager = $export_data['manager'];
        $ids = $export_data['ids'];
        
        // Clean up the transient
        delete_transient('secure_login_bulk_export_' . get_current_user_id());
        
        // Collect export data
        $csv_data = array();
        
        foreach ($ids as $id) {
            $row = $this->database_manager->get_entry($id);
            if (!$row) {
                continue;
            }
            
            $metadata = json_decode($row->metadata, true);
            
            // For bulk export, we'll include a note that this needs to be decrypted
            $csv_data[] = array(
                'name' => $metadata['name'] ?? 'Unknown',
                'website' => $metadata['login_url'] ?? $metadata['service_name'] ?? '',
                'username' => '[ENCRYPTED - DECRYPT FIRST]',
                'password' => '[ENCRYPTED - DECRYPT FIRST]',
                'notes' => 'Entry ID: ' . $id . ' - Please decrypt individual entries before export'
            );
        }
        
        wp_send_json_success(
            array(
            'manager' => $manager,
            'data' => $csv_data,
            'message' => sprintf(__('Bulk export prepared for %d entries.', 'secure-login-collector'), count($csv_data))
            )
        );
    }
    
    /**
     * Handle bulk decrypt with passkey AJAX request
     */
    public function handle_bulk_decrypt_with_passkey_ajax()
    {
        // Log the request for debugging
        error_log('Bulk decrypt with passkey AJAX request received. POST data: ' . print_r($_POST, true));
        
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce')) {
            error_log('Bulk decrypt: Invalid security token');
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            error_log('Bulk decrypt: Insufficient permissions for user ID: ' . get_current_user_id());
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Check if this is pro version with passkey
        if (!$this->is_pro_version) {
            error_log('Bulk decrypt: Pro version required');
            wp_send_json_error(__('Pro version required.', 'secure-login-collector'));
            return;
        }
        
        if (!get_option('secure_login_passkey_registered', false)) {
            error_log('Bulk decrypt: Passkey not registered');
            wp_send_json_error(__('Passkey not registered.', 'secure-login-collector'));
            return;
        }
        
        $entry_ids = isset($_POST['entry_ids']) ? array_map('intval', $_POST['entry_ids']) : array();
        $manager = isset($_POST['manager']) ? sanitize_text_field($_POST['manager']) : '';
        $passkey_verified = isset($_POST['passkey_verified']) && $_POST['passkey_verified'] === 'true';
        
        if (empty($manager)) {
            wp_send_json_error(__('Missing export manager.', 'secure-login-collector'));
            return;
        }
        
        // If passkey is not yet verified, validate entry_ids from POST and store the request
        if (!$passkey_verified) {
            if (empty($entry_ids)) {
                wp_send_json_error(__('No entries selected for export.', 'secure-login-collector'));
                return;
            }
            
            set_transient(
                'secure_login_bulk_decrypt_request_' . get_current_user_id(), array(
                'entry_ids' => $entry_ids,
                'manager' => $manager
                ), 300
            ); // 5 minutes
            
            wp_send_json_success(
                array(
                'requires_passkey' => true,
                'entry_count' => count($entry_ids),
                'manager' => $manager,
                'message' => sprintf(__('You have selected %d entries for bulk export. All selected entries will be decrypted using your passkey and then exported to %s format.', 'secure-login-collector'), count($entry_ids), $manager)
                )
            );
            return;
        }
        
        // Passkey is verified, proceed with bulk decryption
        $signature = isset($_POST['signature']) ? sanitize_text_field($_POST['signature']) : '';
        if (empty($signature)) {
            wp_send_json_error(__('Missing passkey signature.', 'secure-login-collector'));
            return;
        }
        
        // Get the stored request
        $bulk_request = get_transient('secure_login_bulk_decrypt_request_' . get_current_user_id());
        if (!$bulk_request) {
            wp_send_json_error(__('Bulk decrypt request not found or expired.', 'secure-login-collector'));
            return;
        }
        
        // Clean up the transient
        delete_transient('secure_login_bulk_decrypt_request_' . get_current_user_id());
        
        // Set authentication flag that the encryption handler expects
        set_transient('secure_login_passkey_authenticated_' . get_current_user_id(), true, 300); // 5 minutes
        
        // Decrypt all entries
        $csv_data = array();
        $successful_count = 0;
        $failed_count = 0;
        
        foreach ($bulk_request['entry_ids'] as $id) {
            $row = $this->database_manager->get_entry($id);
            if (!$row) {
                $failed_count++;
                continue;
            }
            
            $metadata = json_decode($row->metadata, true);
            $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'xor';
            
            // Decrypt using encryption handler with passkey authentication
            $decrypted_data = $this->encryption_handler->decrypt_data($row->encrypted_data, $encryption_type);
            
            if ($decrypted_data === false) {
                $failed_count++;
                continue;
            }
            
            // Parse the decrypted data
            // Check if decrypted data is already an array or needs JSON decoding
            if (is_array($decrypted_data)) {
                $login_data = $decrypted_data;
            } elseif (is_string($decrypted_data)) {
                $login_data = json_decode($decrypted_data, true);
                if (!$login_data) {
                    $failed_count++;
                    continue;
                }
            } else {
                $failed_count++;
                continue;
            }
            
            // Prepare CSV data based on manager format
            $name = $metadata['name'] ?? 'Unknown';
            $website = $metadata['login_url'] ?? $metadata['service_name'] ?? '';
            $username = $login_data['username_email'] ?? '';
            $password = $login_data['password'] ?? '';
            $notes = $login_data['additional_notes'] ?? '';
            
            // Ensure website has protocol
            if ($website && !preg_match('/^https?:\/\//', $website)) {
                $website = 'https://' . $website;
            }
            
            $csv_data[] = array(
                'name' => $name,
                'website' => $website,
                'username' => $username,
                'password' => $password,
                'notes' => $notes
            );
            
            $successful_count++;
        }
        
        // Clean up the authentication flag
        delete_transient('secure_login_passkey_authenticated_' . get_current_user_id());
        
        // Log the action for security audit
        error_log(
            sprintf(
                'Secure Login Data Bulk Decrypted via Passkey - Admin User ID: %d, Successfully decrypted: %d, Failed: %d, IP: %s',
                get_current_user_id(),
                $successful_count,
                $failed_count,
                SecureLoginCollector::get_client_ip()
            )
        );
        
        $message = '';
        if ($successful_count > 0 && $failed_count === 0) {
            $message = sprintf(__('Successfully decrypted %d entries. Generating CSV...', 'secure-login-collector'), $successful_count);
        } elseif ($successful_count > 0 && $failed_count > 0) {
            $message = sprintf(__('Bulk decryption failed for some entries. Only successfully decrypted entries were exported.', 'secure-login-collector'), $successful_count, $failed_count);
        } else {
            wp_send_json_error(__('Bulk decryption failed for all entries.', 'secure-login-collector'));
            return;
        }
        
        wp_send_json_success(
            array(
            'csv_data' => $csv_data,
            'manager' => $bulk_request['manager'],
            'successful_count' => $successful_count,
            'failed_count' => $failed_count,
            'message' => $message
            )
        );
    }
    
    public function handle_decrypt_ajax()
    {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize and validate input.
        $decrypt_id = intval($_POST['decrypt_id']);
        $encryption_key = isset($_POST['encryption_key']) ? sanitize_text_field($_POST['encryption_key']) : null;
        $use_passkey = isset($_POST['use_passkey']) && $_POST['use_passkey'] === 'true';
        $passkey_verified = isset($_POST['passkey_verified']) && $_POST['passkey_verified'] === 'true';
        
        if (empty($decrypt_id)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        // SECURITY ENHANCEMENT: In Pro version with registered passkey, ONLY allow passkey authentication
        if ($this->is_pro_version) {
            $passkey_registered = get_option('secure_login_passkey_registered', false);
            
            if ($passkey_registered) {
                // Passkey is registered - FORCE passkey authentication, no traditional decryption allowed
                if (!$use_passkey) {
                    wp_send_json_error(__('Passkey authentication is required in Pro version. Traditional decryption is disabled for enhanced security.', 'secure-login-collector'));
                    return;
                }
                
                // If passkey is not yet verified, store the decrypt request for passkey authentication
                if (!$passkey_verified) {
                    set_transient('secure_login_decrypt_request_' . get_current_user_id(), $decrypt_id, 300); // 5 minutes
                    
                    wp_send_json_success(
                        array(
                        'requires_passkey' => true,
                        'message' => __('Passkey authentication required. Please authenticate with your passkey.', 'secure-login-collector')
                        )
                    );
                    return;
                }
                
                // Passkey is verified, proceed with passkey decryption
                $signature = isset($_POST['signature']) ? sanitize_text_field($_POST['signature']) : '';
                if (empty($signature)) {
                    wp_send_json_error(__('Missing passkey signature.', 'secure-login-collector'));
                    return;
                }
                
                // Set authentication flag that the encryption handler expects
                set_transient('secure_login_passkey_authenticated_' . get_current_user_id(), true, 300); // 5 minutes
                
                // Use the encryption handler to decrypt with passkey
                global $wpdb;
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $decrypt_id));
                
                if (!$row) {
                    wp_send_json_error(__('Data not found.', 'secure-login-collector'));
                    return;
                }
                
                $metadata = json_decode($row->metadata, true);
                $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'xor';
                
                // Decrypt using encryption handler with passkey authentication
                $decrypted_data = $this->encryption_handler->decrypt_data($row->encrypted_data, $encryption_type);
                
                // Clean up the authentication flag
                delete_transient('secure_login_passkey_authenticated_' . get_current_user_id());
                
                if ($decrypted_data === false) {
                    wp_send_json_error(__('Passkey decryption failed. Please check your passkey authentication.', 'secure-login-collector'));
                    return;
                }
                
                // Log the action for security audit
                error_log(
                    sprintf(
                        'Secure Login Data Decrypted via Passkey - Admin User ID: %d, Entry ID: %d, IP: %s',
                        get_current_user_id(),
                        $decrypt_id,
                        SecureLoginCollector::get_client_ip()
                    )
                );
                
                wp_send_json_success(
                    array(
                    'data' => $decrypted_data,
                    'metadata' => $metadata
                    )
                );
                return;
            } else {
                // Pro version but no passkey registered - allow traditional decryption but warn user
                if ($use_passkey) {
                    wp_send_json_error(__('Passkey not registered. Please register a passkey first or use traditional decryption.', 'secure-login-collector'));
                    return;
                }
            }
        }
        
        // Traditional decryption (for non-pro or pro without passkey registered)
        if (empty($encryption_key)) {
            wp_send_json_error(__('Missing encryption key.', 'secure-login-collector'));
            return;
        }
        
        // Decrypt the data using the private decrypt_data method.
        $decrypted_data = $this->decrypt_data($decrypt_id, $encryption_key);
        
        if ($decrypted_data === false) {
            wp_send_json_error(__('Decryption failed. Please check the encryption key.', 'secure-login-collector'));
            return;
        }
        
        // Get metadata for the response
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $decrypt_id));
        $metadata = $row ? json_decode($row->metadata, true) : array();
        
        wp_send_json_success(
            array(
            'data' => $decrypted_data,
            'metadata' => $metadata
            )
        );
    }
    
    /**
     * Private method to decrypt data (moved from encryption handler for admin use).
     */
    private function decrypt_data($id, $encryption_key = null)
    {
        global $wpdb;
        
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
        
        if (!$row) {
            error_log('Secure Login Collector: No data found for ID: ' . $id);
            return false;
        }
        
        try {
            // Check if this is passkey-derived encrypted data
            $metadata = json_decode($row->metadata, true);
            $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'xor';
            
            error_log('Secure Login Collector: Decrypting data with encryption type: ' . $encryption_type);
            
            if ($encryption_type === 'passkey_derived') {
                // For passkey-derived encryption, we need the passkey signature
                // This should be handled through the passkey authentication flow
                return $this->encryption_handler->decrypt_data($row->encrypted_data, $encryption_type, $encryption_key);
            } else if ($encryption_type === 'rsa') {
                return $this->encryption_handler->decrypt_rsa_data($row->encrypted_data);
            }
            
            // Fallback to XOR decryption for legacy data
            if ($encryption_key && $encryption_key !== 'rsa') {
                error_log('Secure Login Collector: Using XOR decryption with key: ' . substr($encryption_key, 0, 5) . '...');
                return $this->encryption_handler->decrypt_xor_data($row->encrypted_data, $encryption_key);
            }
            
            error_log('Secure Login Collector: No valid decryption method available');
            return false;
            
        } catch (Exception $e) {
            error_log('Secure Login Collector: Exception during decryption: ' . $e->getMessage());
            return false;
        }
    }
    
    public function handle_delete_ajax()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        $delete_id = intval($_POST['delete_id']);
        if (empty($delete_id)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        $result = $this->database_manager->delete_entry($delete_id);
        if ($result === false) {
            wp_send_json_error(__('Failed to delete data.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(
            sprintf(
                'Secure Login Data Deleted - Admin User ID: %d, Entry ID: %d, IP: %s',
                get_current_user_id(),
                $delete_id,
                SecureLoginCollector::get_client_ip()
            )
        );
        
        wp_send_json_success(__('Data deleted successfully.', 'secure-login-collector'));
    }
    
    public function handle_extend_ajax()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        $extend_id = intval($_POST['extend_id']);
        if (empty($extend_id)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        $result = $this->database_manager->extend_retention($extend_id);
        if ($result === false) {
            wp_send_json_error(__('Failed to extend retention.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(
            sprintf(
                'Secure Login Data Retention Extended - Admin User ID: %d, Entry ID: %d, IP: %s',
                get_current_user_id(),
                $extend_id,
                SecureLoginCollector::get_client_ip()
            )
        );
        
        // Get updated expiration display.
        $row = $this->database_manager->get_entry($extend_id);
        $new_expiration_display = $this->database_manager->calculate_expiration($row->retention_until);
        
        $expiration_days = get_option('secure_login_expiration_days', 30);
        wp_send_json_success(
            array(
            'message' => sprintf(__('Retention period extended by %d days.', 'secure-login-collector'), $expiration_days),
            'new_expiration' => $new_expiration_display
            )
        );
    }
    
    public function handle_save_manual_login_data()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize and validate input.
        $login_data = wp_unslash($_POST['login_data']);
        $metadata = wp_unslash($_POST['metadata']);
        $encryption_method = sanitize_text_field($_POST['encryption_method']);
        
        if (empty($login_data) || empty($metadata)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        // Validate metadata is valid JSON.
        $metadata_array = json_decode($metadata, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(__('Invalid metadata format.', 'secure-login-collector'));
            return;
        }
        
        // Validate required metadata fields.
        if (!isset($metadata_array['email']) || empty($metadata_array['email']) 
            || !isset($metadata_array['name']) || empty($metadata_array['name']) 
            || !isset($metadata_array['login_url']) || empty($metadata_array['login_url'])
        ) {
            wp_send_json_error(__('Missing required metadata fields.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize metadata fields.
        $metadata_array['email'] = sanitize_email($metadata_array['email']);
        $metadata_array['name'] = sanitize_text_field($metadata_array['name']);
        $metadata_array['login_url'] = sanitize_text_field($metadata_array['login_url']);
        $metadata_array['encryption_type'] = sanitize_text_field($encryption_method);
        $metadata_array['manually_added'] = true;
        $metadata_array['added_by_user'] = get_current_user_id();
        $metadata_array['created_at'] = current_time('c');
        
        // The login_data already contains the structured data to encrypt
        $data_to_encrypt = $login_data;
        
        // Encrypt data based on selected method.
        $encrypted_data = '';
        
        switch ($encryption_method) {
        case 'passkey_derived':
            if (!$this->is_pro_version) {
                wp_send_json_error(__('Pro version required for passkey encryption.', 'secure-login-collector'));
                return;
            }
                
            if (!get_option('secure_login_passkey_registered', false)) {
                wp_send_json_error(__('Passkey not registered.', 'secure-login-collector'));
                return;
            }
                
            // For manual entries, encrypt directly with passkey-derived key
            $encrypted_data = $this->encryption_handler->encrypt_with_passkey_key($data_to_encrypt, null);
            if ($encrypted_data === false) {
                wp_send_json_error(__('Passkey encryption failed.', 'secure-login-collector'));
                return;
            }
            break;
                
        case 'rsa':
            // Use RSA encryption
            $public_key = $this->encryption_handler->get_public_key();
            if (is_wp_error($public_key)) {
                wp_send_json_error(__('RSA keys not available.', 'secure-login-collector'));
                return;
            }
                
            // For server-side RSA encryption, we'll use the public key
            $encrypted_data = $this->encryption_handler->encrypt_rsa_data($data_to_encrypt);
            if ($encrypted_data === false) {
                wp_send_json_error(__('RSA encryption failed.', 'secure-login-collector'));
                return;
            }
                
            // ULTRA-SECURE MODE: Double-encrypt with passkey-derived encryption if enabled
            // Instead of decrypt->re-encrypt, we encrypt the already-encrypted RSA data
            if ($this->is_pro_version && get_option('secure_login_ultra_secure_mode', false) && get_option('secure_login_passkey_registered', false)) {
                // Encrypt the RSA-encrypted data with passkey-derived encryption (double encryption)
                $passkey_encrypted_data = $this->encryption_handler->encrypt_with_passkey_key($encrypted_data, null);
                    
                if ($passkey_encrypted_data !== false) {
                    // Use double-encrypted data and update metadata
                    $encrypted_data = $passkey_encrypted_data;
                    $metadata_array['encryption_type'] = 'passkey_derived';
                    $metadata_array['inner_encryption'] = 'rsa'; // Track inner encryption method
                    $metadata_array['double_encrypted'] = true; // Flag for double encryption
                        
                    error_log('Secure Login Collector: Data double-encrypted with passkey-derived encryption over RSA');
                } else {
                    error_log('Secure Login Collector: Failed to double-encrypt with passkey, keeping RSA encryption');
                }
            }
            break;
                
        case 'xor':
        default:
            // Use XOR encryption
            $hostname = parse_url(get_site_url(), PHP_URL_HOST);
            $timestamp = time();
            $timestamp_suffix = substr((string)$timestamp, -6);
            $encryption_key = $hostname . $timestamp_suffix;
                
            // Add XOR-specific metadata
            $metadata_array['key_hostname'] = $hostname;
            $metadata_array['key_timestamp_suffix'] = $timestamp_suffix;
            $metadata_array['encryption_key_hint'] = $encryption_key;
                
            $encrypted_data = $this->encryption_handler->encrypt_xor_data($data_to_encrypt, $encryption_key);
            break;
        }
        
        // Re-encode the metadata.
        $metadata = json_encode($metadata_array);
        
        // Calculate retention_until based on expiration settings.
        $expiration_days = get_option('secure_login_expiration_days', 30);
        $retention_until = null;
        if ($expiration_days > 0) {
            $retention_until = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
        }
        
        // Prepare data for database insertion.
        $data = array(
            'encrypted_data' => $encrypted_data,
            'metadata' => $metadata,
            'user_id' => get_current_user_id(), // Manual entries are associated with the admin user
            'ip_address' => SecureLoginCollector::get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT']),
            'created_at' => current_time('mysql'),
            'retention_until' => $retention_until
        );
        
        // Insert into database using database manager.
        $result = $this->database_manager->insert_entry($data);
        
        if ($result === false) {
            wp_send_json_error(__('Failed to save data to database.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(
            sprintf(
                'Secure Login Data Manually Added - Admin User ID: %d, Client: %s (%s), IP: %s, Method: %s',
                get_current_user_id(),
                $metadata_array['name'],
                $metadata_array['email'],
                SecureLoginCollector::get_client_ip(),
                $encryption_method
            )
        );
        
        wp_send_json_success(__('Login data saved successfully.', 'secure-login-collector'));
    }
    
    public function handle_update_metadata_ajax()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(__('Invalid security token or insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        $update_id = intval($_POST['update_id']);
        $new_metadata = $_POST['metadata'];
        
        if (empty($update_id) || empty($new_metadata)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        // Get current metadata and update only the specified fields
        $row = $this->database_manager->get_entry($update_id);
        if (!$row) {
            wp_send_json_error(__('Entry not found.', 'secure-login-collector'));
            return;
        }
        
        $current_metadata = json_decode($row->metadata, true);
        
        // Update only the provided fields
        foreach ($new_metadata as $field => $value) {
            $current_metadata[$field] = sanitize_text_field($value);
        }
        
        $updated_metadata = json_encode($current_metadata);
        
        $result = $this->database_manager->update_entry_metadata($update_id, $updated_metadata);
        
        if ($result === false) {
            wp_send_json_error(__('Failed to update metadata.', 'secure-login-collector'));
            return;
        }
        
        wp_send_json_success(__('Metadata updated successfully.', 'secure-login-collector'));
    }
}
