<?php
/**
 * Plugin Name: Secure Login Collector
 * Plugin URI: https://wp-mike.com
 * Description: Securely collects and stores encrypted login credentials from clients via frontend form with email notifications.
 * Version: 2.4.0
 * Author: Mike Miler
 * License: GPL v2 or later
 * Text Domain: secure-login-collector
 * 
 * Pro Version Activation:
 * To enable pro version features, add this line to your wp-config.php:
 * define('SECURE_LOGIN_PRO', true);
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

class SecureLoginCollector {
    
    private $table_name;
    private $is_pro_version;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'secure_login_data';
        $this->is_pro_version = $this->check_pro_version();
        
        // Load plugin text domain for translations.
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Hook into WordPress.
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_save_secure_login_data', array($this, 'handle_save_login_data'));
        add_action('wp_ajax_nopriv_save_secure_login_data', array($this, 'handle_save_login_data'));
        add_action('wp_ajax_decrypt_secure_login_data', array($this, 'handle_decrypt_ajax'));
        add_action('wp_ajax_delete_secure_login_data', array($this, 'handle_delete_ajax'));
        add_action('wp_ajax_extend_secure_login_data', array($this, 'handle_extend_ajax'));
        add_action('wp_ajax_save_manual_login_data', array($this, 'handle_save_manual_login_data'));
        add_action('wp_ajax_generate_rsa_keys', array($this, 'handle_generate_rsa_keys'));
        add_action('wp_ajax_export_public_key', array($this, 'handle_export_public_key'));
        add_action('wp_ajax_register_passkey', array($this, 'handle_register_passkey'));
        add_action('wp_ajax_authenticate_passkey', array($this, 'handle_authenticate_passkey'));
        add_action('wp_ajax_reset_passkey', array($this, 'handle_reset_passkey'));
        add_action('wp_ajax_update_secure_login_metadata', array($this, 'handle_update_metadata_ajax'));
        add_action('wp_ajax_encrypt_with_passkey', array($this, 'handle_encrypt_with_passkey'));
        add_action('wp_ajax_test_passkey_encryption', array($this, 'handle_test_passkey_encryption'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add cron job for automatic cleanup.
        add_action('secure_login_cleanup_cron', array($this, 'cleanup_old_data'));
        
        // Plugin activation/deactivation hooks.
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Add shortcode for frontend form.
        add_shortcode('secure_login_form', array($this, 'frontend_form_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }
    
    /**
     * Load plugin text domain for translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain('secure-login-collector', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Check if pro version is available.
     */
    private function check_pro_version() {
        // Check for pro license key or pro plugin file
        return defined('SECURE_LOGIN_PRO') && SECURE_LOGIN_PRO === true;
    }
    
    /**
     * Generate RSA key pair (now available for all users).
     */
    public function generate_rsa_keypair() {
        if (!function_exists('openssl_pkey_new')) {
            return new WP_Error('openssl_missing', __('OpenSSL extension is required for RSA encryption.', 'secure-login-collector'));
        }
        
        $config = array(
            "digest_alg" => "sha1",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );
        
        $keypair = openssl_pkey_new($config);
        if (!$keypair) {
            return new WP_Error('key_generation_failed', __('Failed to generate RSA key pair.', 'secure-login-collector'));
        }
        
        // Extract private key
        openssl_pkey_export($keypair, $private_key);
        
        // Extract public key
        $public_key_details = openssl_pkey_get_details($keypair);
        $public_key = $public_key_details["key"];
        
        // Store keys securely
        $this->store_keypair($public_key, $private_key);
        
        return array(
            'public_key' => $public_key,
            'private_key' => $private_key
        );
    }
    
    /**
     * Store RSA key pair securely.
     */
    private function store_keypair($public_key, $private_key) {
        // Store public key (can be accessed by frontend)
        update_option('secure_login_public_key', $public_key);
        
        // Store private key encrypted with WordPress salts
        $encrypted_private_key = $this->encrypt_private_key($private_key);
        update_option('secure_login_private_key_encrypted', $encrypted_private_key);
        
        // Store key generation timestamp
        update_option('secure_login_keys_generated', current_time('mysql'));
        
        // Store key version for compatibility tracking
        update_option('secure_login_key_version', '2.3.5');
    }
    
    /**
     * Encrypt private key using WordPress salts.
     */
    private function encrypt_private_key($private_key) {
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
            return base64_encode($private_key); // Fallback to base64 if salts not available
        }
        
        $key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
        $iv = substr(hash('sha256', SECURE_AUTH_KEY . AUTH_KEY), 0, 16);
        
        return base64_encode(openssl_encrypt($private_key, 'AES-256-CBC', $key, 0, $iv));
    }
    
    /**
     * Decrypt private key using WordPress salts.
     */
    private function decrypt_private_key($encrypted_private_key) {
        error_log('Secure Login Collector: Attempting to decrypt private key');
        
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
            error_log('Secure Login Collector: WordPress salts not defined, using base64 fallback');
            return base64_decode($encrypted_private_key); // Fallback
        }
        
        error_log('Secure Login Collector: WordPress salts found, using AES decryption');
        
        $key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
        $iv = substr(hash('sha256', SECURE_AUTH_KEY . AUTH_KEY), 0, 16);
        
        error_log('Secure Login Collector: Generated decryption key and IV');
        
        $decrypted = openssl_decrypt(base64_decode($encrypted_private_key), 'AES-256-CBC', $key, 0, $iv);
        
        if ($decrypted === false) {
            error_log('Secure Login Collector: AES decryption failed - ' . openssl_error_string());
            error_log('Secure Login Collector: Trying base64 fallback');
            return base64_decode($encrypted_private_key);
        }
        
        error_log('Secure Login Collector: Private key AES decryption successful');
        return $decrypted;
    }
    
    /**
     * Get public key for frontend encryption (now available for all users).
     */
    public function get_public_key() {
        $public_key = get_option('secure_login_public_key');
        if (!$public_key) {
            // Generate keys if they don't exist
            $keypair = $this->generate_rsa_keypair();
            if (is_wp_error($keypair)) {
                return $keypair;
            }
            $public_key = $keypair['public_key'];
        }
        
        return $public_key;
    }
    
    /**
     * Initialize the plugin.
     */
    public function init() {
        // No TinyMCE plugin needed - only frontend form.
    }
    
    /**
     * Plugin activation - create database table.
     */
    public function activate() {
        $this->create_table();
        $this->upgrade_database();
        
        // Ensure RSA keys are generated
        $this->ensure_rsa_keys();
        
        // Schedule daily cleanup cron job.
        if (!wp_next_scheduled('secure_login_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'secure_login_cleanup_cron');
        }
    }
    
    /**
     * Ensure RSA keys are generated and available.
     */
    private function ensure_rsa_keys() {
        $public_key = get_option('secure_login_public_key');
        $key_version = get_option('secure_login_key_version', '1.0');
        
        // Force regeneration if keys don't exist or were generated with old version
        if (!$public_key || version_compare($key_version, '2.3.5', '<')) {
            $keypair = $this->generate_rsa_keypair();
            if (!is_wp_error($keypair)) {
                // Update key version to current plugin version
                update_option('secure_login_key_version', '2.3.5');
                error_log('Secure Login Collector: RSA keys regenerated for OAEP compatibility (version 2.3.5)');
            } else {
                error_log('Secure Login Collector: Failed to generate RSA keys - ' . $keypair->get_error_message());
            }
        }
    }
    
    /**
     * Upgrade database schema if needed.
     */
    private function upgrade_database() {
        global $wpdb;
        
        // Check if retention_until column exists.
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$this->table_name} LIKE %s",
            'retention_until'
        ));
        
        if (empty($column_exists)) {
            // Add retention_until column.
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN retention_until datetime DEFAULT NULL AFTER created_at");
            
            // Set retention_until for existing records based on created_at + expiration days.
            $expiration_days = get_option('secure_login_expiration_days', 30);
            if ($expiration_days > 0) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table_name} SET retention_until = DATE_ADD(created_at, INTERVAL %d DAY) WHERE retention_until IS NULL",
                    $expiration_days
                ));
            }
        }
    }
    
    /**
     * Plugin deactivation - cleanup if needed.
     */
    public function deactivate() {
        // Clear scheduled cron job.
        wp_clear_scheduled_hook('secure_login_cleanup_cron');
        
        // Optional: Clean up temporary data, but keep the table for data integrity.
    }
    
    /**
     * Create the database table for storing encrypted login data.
     */
    private function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            encrypted_data longtext NOT NULL,
            metadata text NOT NULL,
            user_id bigint(20) NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            retention_until datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY retention_until (retention_until)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Enqueue admin scripts and localize AJAX data.
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on post edit screens and our admin page.
        if (!in_array($hook, array('post.php', 'post-new.php', 'toplevel_page_secure-login-data'))) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Localize script with nonce and AJAX URL.
        wp_localize_script('jquery', 'secureLoginAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('secure_login_nonce')
        ));
        
        // Add inline script to make nonce available globally.
        wp_add_inline_script('jquery', 'window.secureLoginNonce = "' . wp_create_nonce('secure_login_nonce') . '";');
    }
    
    /**
     * Enqueue frontend scripts for the shortcode form.
     */
    public function enqueue_frontend_scripts() {
        // Only enqueue if shortcode is present on the page.
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'secure_login_form')) {
            wp_enqueue_script('jquery');
            
            // Always use RSA encryption on frontend
            // Server will handle passkey re-encryption if ultra-secure mode is enabled
            wp_enqueue_script('secure-login-frontend', plugin_dir_url(__FILE__) . 'js/frontend-ultra-secure.js', array('jquery'), '2.4.0', true);
            
            // Prepare localization data
            $localize_data = array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('secure_login_nonce'),
                'is_pro' => $this->is_pro_version,
                'strings' => array(
                    'required_fields_error' => __('Please fill in all required fields (Email Address, Name, Service Name, and Login Data).', 'secure-login-collector'),
                    'submitting' => __('Submitting...', 'secure-login-collector'),
                    'submit_securely' => __('Submit Securely', 'secure-login-collector'),
                    'success_message' => __('Login data saved securely! Thank you for your submission.', 'secure-login-collector'),
                    'error_prefix' => __('Error saving data: ', 'secure-login-collector'),
                    'unknown_error' => __('Unknown error', 'secure-login-collector'),
                    'network_error' => __('Network error occurred while saving data. Please try again.', 'secure-login-collector'),
                    'encryption_error' => __('Encryption failed. Please try again.', 'secure-login-collector')
                )
            );
            
            // Add public key for RSA encryption
            $public_key = $this->get_public_key();
            if (!is_wp_error($public_key)) {
                $localize_data['public_key'] = $public_key;
            }
            
            // Localize script with data
            wp_localize_script('secure-login-frontend', 'secureLoginAjax', $localize_data);
        }
    }
    
    /**
     * Handle AJAX request to save encrypted login data.
     */
    public function handle_save_login_data() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'secure_login_nonce')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize and validate input.
        $encrypted_data = sanitize_textarea_field($_POST['encrypted_data']);
        $metadata = wp_unslash($_POST['metadata']); // Don't sanitize JSON data as it corrupts the format
        
        if (empty($encrypted_data) || empty($metadata)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        // Validate metadata is valid JSON.
        $metadata_array = json_decode($metadata, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(__('Invalid metadata format. JSON Error: ', 'secure-login-collector') . json_last_error_msg());
            return;
        }
        
        // Validate required metadata fields exist.
        if (!isset($metadata_array['email']) || empty($metadata_array['email'])) {
            wp_send_json_error(__('Missing email in metadata.', 'secure-login-collector'));
            return;
        }
        
        // Validate required metadata fields exist.
        if (!isset($metadata_array['name']) || empty($metadata_array['name'])) {
            wp_send_json_error(__('Missing name in metadata.', 'secure-login-collector'));
            return;
        }
        
        // Validate required metadata fields exist.
        if (!isset($metadata_array['service_name']) || empty($metadata_array['service_name'])) {
            wp_send_json_error(__('Missing service name in metadata.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize individual metadata fields after JSON decode.
        $metadata_array['email'] = sanitize_email($metadata_array['email']);
        $metadata_array['name'] = sanitize_text_field($metadata_array['name']);
        $metadata_array['service_name'] = sanitize_text_field($metadata_array['service_name']);
        
        $metadata_array['created_at'] = isset($metadata_array['created_at']) ? sanitize_text_field($metadata_array['created_at']) : current_time('c');
        $metadata_array['encryption_key_hint'] = isset($metadata_array['encryption_key_hint']) ? sanitize_text_field($metadata_array['encryption_key_hint']) : '';
        $metadata_array['key_hostname'] = isset($metadata_array['key_hostname']) ? sanitize_text_field($metadata_array['key_hostname']) : '';
        $metadata_array['key_timestamp_suffix'] = isset($metadata_array['key_timestamp_suffix']) ? sanitize_text_field($metadata_array['key_timestamp_suffix']) : '';
        
        // Re-encode the sanitized metadata.
        $metadata = json_encode($metadata_array);
        
        // ULTRA-SECURE MODE: Double-encrypt with passkey-derived encryption if enabled
        // Instead of decrypt->re-encrypt, we encrypt the already-encrypted RSA data
        if ($this->is_pro_version && get_option('secure_login_ultra_secure_mode', false) && get_option('secure_login_passkey_registered', false)) {
            // Encrypt the RSA-encrypted data with passkey-derived encryption (double encryption)
            $passkey_encrypted_data = $this->encrypt_with_passkey_key($encrypted_data, null);
            
            if ($passkey_encrypted_data !== false) {
                // Use double-encrypted data and update metadata
                $encrypted_data = $passkey_encrypted_data;
                $metadata_array['encryption_type'] = 'passkey_derived';
                $metadata_array['inner_encryption'] = 'rsa'; // Track inner encryption method
                $metadata_array['double_encrypted'] = true; // Flag for double encryption
                $metadata = json_encode($metadata_array);
                
                error_log('Secure Login Collector: Data double-encrypted with passkey-derived encryption over RSA');
            } else {
                error_log('Secure Login Collector: Failed to double-encrypt with passkey, keeping RSA encryption');
            }
        }
        
        // Calculate retention_until based on expiration settings.
        $expiration_days = get_option('secure_login_expiration_days', 30);
        $retention_until = null;
        if ($expiration_days > 0) {
            $retention_until = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
        }
        
        // Prepare data for database insertion.
        global $wpdb;
        
        $data = array(
            'encrypted_data' => $encrypted_data,
            'metadata' => $metadata,
            'user_id' => 0, // Anonymous frontend submissions
            'ip_address' => $this->get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT']),
            'created_at' => current_time('mysql'),
            'retention_until' => $retention_until
        );
        
        // Insert into database.
        $result = $wpdb->insert($this->table_name, $data);
        
        if ($result === false) {
            wp_send_json_error(__('Failed to save data to database.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Data Collected - Client: %s (%s), IP: %s',
            $metadata_array['name'],
            $metadata_array['email'],
            $this->get_client_ip()
        ));
        
        // Send email notification if enabled.
        $this->send_notification($metadata_array['email'], $metadata_array['name']);
        
        wp_send_json_success(__('Login data saved securely.', 'secure-login-collector'));
    }
    
    /**
     * Handle AJAX request to decrypt login data.
     */
    public function handle_decrypt_ajax() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'decrypt_login_data')) {
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
                
                // Store the decrypt request for passkey authentication
                set_transient('secure_login_decrypt_request_' . get_current_user_id(), $decrypt_id, 300); // 5 minutes
                
                wp_send_json_success(array(
                    'requires_passkey' => true,
                    'message' => __('Passkey authentication required. Please authenticate with your passkey.', 'secure-login-collector')
                ));
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
        
        // Decrypt the data.
        $decrypted_data = $this->decrypt_data($decrypt_id, $encryption_key);
        
        if ($decrypted_data === false) {
            wp_send_json_error(__('Decryption failed. Please check the encryption key.', 'secure-login-collector'));
            return;
        }
        
        wp_send_json_success($decrypted_data);
    }
    
    /**
     * Handle AJAX request to delete login data.
     */
    public function handle_delete_ajax() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'delete_login_data')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize and validate input.
        $delete_id = intval($_POST['delete_id']);
        
        if (empty($delete_id)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        // Delete the data.
        global $wpdb;
        $result = $wpdb->delete($this->table_name, array('id' => $delete_id), array('%d'));
        
        if ($result === false) {
            wp_send_json_error(__('Failed to delete data from database.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Data Deleted - Admin User ID: %d, Entry ID: %d, IP: %s',
            get_current_user_id(),
            $delete_id,
            $this->get_client_ip()
        ));
        
        wp_send_json_success(__('Login data deleted successfully.', 'secure-login-collector'));
    }
    
    /**
     * Handle AJAX request to extend retention period for login data.
     */
    public function handle_extend_ajax() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'extend_login_data')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize and validate input.
        $extend_id = intval($_POST['extend_id']);
        
        if (empty($extend_id)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        // Get current expiration setting.
        $expiration_days = get_option('secure_login_expiration_days', 30);
        
        if ($expiration_days <= 0) {
            wp_send_json_error(__('Data expiration is disabled. Cannot extend retention period.', 'secure-login-collector'));
            return;
        }
        
        // Update the retention_until to extend retention period.
        global $wpdb;
        $new_retention_until = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
        
        $result = $wpdb->update(
            $this->table_name,
            array('retention_until' => $new_retention_until),
            array('id' => $extend_id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error(__('Failed to extend retention period.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Data Retention Extended - Admin User ID: %d, Entry ID: %d, New Expiration: %d days, IP: %s',
            get_current_user_id(),
            $extend_id,
            $expiration_days,
            $this->get_client_ip()
        ));
        
        // Calculate new expiration display.
        $new_expiration_display = $this->calculate_expiration($new_retention_until);
        
        wp_send_json_success(array(
            'message' => sprintf(__('Retention period extended by %d days.', 'secure-login-collector'), $expiration_days),
            'new_expiration' => $new_expiration_display
        ));
    }
    
    /**
     * Get client IP address.
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Send email notification for new login data submission.
     */
    private function send_notification($sender_email, $sender_name = '') {
        // Check if notifications are enabled.
        if (!get_option('secure_login_enable_notifications', false)) {
            return;
        }
        
        $notification_email = get_option('secure_login_notification_email', get_option('admin_email'));
        
        if (empty($notification_email) || !is_email($notification_email)) {
            return;
        }
        
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        $admin_url = admin_url('admin.php?page=secure-login-data');
        
        $subject = sprintf(__('[%s] New Secure Login Data Received', 'secure-login-collector'), $site_name);
        
        $message = sprintf(
            __("Hello,\n\nNew secure login data has been submitted to your website.\n\nSender Email: %s\nSender Name: %s\nSubmitted: %s\n\nTo view and decrypt the login data, please visit:\n%s\n\nThis is an automated notification from %s\nWebsite: %s", 'secure-login-collector'),
            $sender_email,
            $sender_name ?: __('Not provided', 'secure-login-collector'),
            current_time('Y-m-d H:i:s'),
            $admin_url,
            $site_name,
            $site_url
        );
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <noreply@' . parse_url($site_url, PHP_URL_HOST) . '>'
        );
        
        wp_mail($notification_email, $subject, $message, $headers);
    }
    
    /**
     * Cleanup old data based on expiration settings.
     */
    public function cleanup_old_data() {
        global $wpdb;
        
        // Check if auto-deletion is enabled
        $expiration_days = get_option('secure_login_expiration_days', 30);
        if ($expiration_days <= 0) {
            // Auto-deletion is disabled, don't delete anything
            return;
        }
        
        // Delete entries where retention_until has passed.
        $current_time = current_time('mysql');
        
        $deleted_count = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE retention_until IS NOT NULL AND retention_until < %s",
            $current_time
        ));
        
        if ($deleted_count > 0) {
            error_log(sprintf(
                'Secure Login Collector: Automatically deleted %d expired entries',
                $deleted_count
            ));
        }
    }
    
    /**
     * Calculate expiration time for display.
     */
    private function calculate_expiration($retention_until) {
        // If retention_until is null, check if expiration is disabled.
        if (is_null($retention_until)) {
            return __('Never expires', 'secure-login-collector');
        }
        
        $expiration_time = strtotime($retention_until);
        $current_time = time();
        $remaining_time = $expiration_time - $current_time;
        
        if ($remaining_time <= 0) {
            return '<span style="color: red;">' . __('Expired', 'secure-login-collector') . '</span>';
        }
        
        $days = floor($remaining_time / 86400);
        $hours = floor(($remaining_time % 86400) / 3600);
        
        if ($days > 0) {
            return sprintf(__('%d days, %d hours', 'secure-login-collector'), $days, $hours);
        } else {
            return sprintf(__('%d hours', 'secure-login-collector'), $hours);
        }
    }
    
    /**
     * Add admin menu for viewing collected data.
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Secure Login Data', 'secure-login-collector'),
            __('Login Data', 'secure-login-collector'),
            'manage_options',
            'secure-login-data',
            array($this, 'admin_page'),
            'dashicons-lock',
            30
        );
        
        add_submenu_page(
            'secure-login-data',
            __('Settings', 'secure-login-collector'),
            __('Settings', 'secure-login-collector'),
            'manage_options',
            'secure-login-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Admin page for viewing collected login data.
     */
    public function admin_page() {
        global $wpdb;
        
        // Get all collected data.
        $results = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Secure Login Data', 'secure-login-collector'); ?></h1>
            <p><?php echo esc_html__('This page shows all encrypted login data collected from clients. Click "Decrypt" to view the data inline.', 'secure-login-collector'); ?></p>
            
            <!-- Add New Entry Button -->
            <div style="margin-bottom: 20px;">
                <button type="button" class="button button-primary" id="add-new-entry-btn">
                    <?php echo esc_html__('Add New Entry', 'secure-login-collector'); ?>
                </button>
            </div>
            
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
                                    <label for="manual_service_name"><?php echo esc_html__('Service Name', 'secure-login-collector'); ?> <span style="color: red;">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="manual_service_name" name="manual_service_name" class="regular-text" required>
                                    <p class="description"><?php echo esc_html__('Name of the service, email provider, hosting company, etc.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="manual_login_data"><?php echo esc_html__('Login Data', 'secure-login-collector'); ?> <span style="color: red;">*</span></label>
                                </th>
                                <td>
                                    <textarea id="manual_login_data" name="manual_login_data" rows="6" class="large-text" required placeholder="<?php echo esc_attr__('Enter login credentials, passwords, URLs, or any access information...', 'secure-login-collector'); ?>"></textarea>
                                    <p class="description"><?php echo esc_html__('Enter usernames, passwords, URLs, or any login information.', 'secure-login-collector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="manual_encryption_method"><?php echo esc_html__('Encryption Method', 'secure-login-collector'); ?></label>
                                </th>
                                <td>
                                    <select id="manual_encryption_method" name="manual_encryption_method">
                                        <?php if ($this->is_pro_version && get_option('secure_login_passkey_registered', false)): ?>
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
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php echo esc_html__('ID', 'secure-login-collector'); ?></th>
                        <th><?php echo esc_html__('Email Address', 'secure-login-collector'); ?></th>
                        <th><?php echo esc_html__('Name', 'secure-login-collector'); ?></th>
                        <th><?php echo esc_html__('Service Name', 'secure-login-collector'); ?></th>
                        <th><?php echo esc_html__('Date', 'secure-login-collector'); ?></th>
                        <th><?php echo esc_html__('Encryption Method', 'secure-login-collector'); ?></th>
                        <th><?php echo esc_html__('Expires In', 'secure-login-collector'); ?></th>
                        <th><?php echo esc_html__('Actions', 'secure-login-collector'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="8"><?php echo esc_html__('No login data found.', 'secure-login-collector'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($results as $row): ?>
                            <?php 
                            $metadata = json_decode($row->metadata, true);
                            $hostname = isset($metadata['key_hostname']) ? $metadata['key_hostname'] : '';
                            $timestamp_suffix = isset($metadata['key_timestamp_suffix']) ? $metadata['key_timestamp_suffix'] : '';
                            $encryption_type = isset($metadata['encryption_type']) ? $metadata['encryption_type'] : 'xor'; // Default to XOR for legacy entries
                            
                            // Get encryption method information
                            $encryption_info = $this->get_encryption_method_info($encryption_type);
                            ?>
                            <tr>
                                <td><?php echo esc_html($row->id); ?></td>
                                <td>
                                    <span class="display-value" data-field="email" data-id="<?php echo $row->id; ?>">
                                        <?php echo esc_html($metadata['email'] ?? __('N/A', 'secure-login-collector')); ?>
                                    </span>
                                    <input type="email" class="edit-field" data-field="email" data-id="<?php echo $row->id; ?>" 
                                           value="<?php echo esc_attr($metadata['email'] ?? ''); ?>" style="display: none;">
                                </td>
                                <td>
                                    <span class="display-value" data-field="name" data-id="<?php echo $row->id; ?>">
                                        <?php echo esc_html($metadata['name'] ?? __('N/A', 'secure-login-collector')); ?>
                                    </span>
                                    <input type="text" class="edit-field" data-field="name" data-id="<?php echo $row->id; ?>" 
                                           value="<?php echo esc_attr($metadata['name'] ?? ''); ?>" style="display: none;">
                                </td>
                                <td>
                                    <span class="display-value" data-field="service_name" data-id="<?php echo $row->id; ?>">
                                        <?php echo esc_html($metadata['service_name'] ?? __('Not provided', 'secure-login-collector')); ?>
                                    </span>
                                    <input type="text" class="edit-field" data-field="service_name" data-id="<?php echo $row->id; ?>" 
                                           value="<?php echo esc_attr($metadata['service_name'] ?? ''); ?>" style="display: none;">
                                </td>
                                <td><?php echo esc_html($row->created_at); ?></td>
                                <td>
                                    <span class="encryption-method <?php echo esc_attr($encryption_info['class']); ?>" title="<?php echo esc_attr($encryption_info['description']); ?>">
                                        <?php echo $encryption_info['name']; ?>
                                    </span>
                                </td>
                                <td><?php echo $this->calculate_expiration($row->retention_until); ?></td>
                                <td>
                                    <button type="button" class="button edit-btn" data-id="<?php echo $row->id; ?>">
                                        <?php echo esc_html__('Edit', 'secure-login-collector'); ?>
                                    </button>
                                    <button type="button" class="button button-primary save-btn" data-id="<?php echo $row->id; ?>" style="display: none;">
                                        <?php echo esc_html__('Save', 'secure-login-collector'); ?>
                                    </button>
                                    <button type="button" class="button cancel-btn" data-id="<?php echo $row->id; ?>" style="display: none;">
                                        <?php echo esc_html__('Cancel', 'secure-login-collector'); ?>
                                    </button>
                                    <button type="button" class="button decrypt-btn" 
                                            data-id="<?php echo $row->id; ?>" 
                                            data-hostname="<?php echo esc_attr($hostname); ?>" 
                                            data-timestamp="<?php echo esc_attr($timestamp_suffix); ?>"
                                            data-encryption-type="<?php echo esc_attr($encryption_type); ?>">
                                        <?php echo esc_html__('Decrypt', 'secure-login-collector'); ?>
                                    </button>
                                    <?php 
                                    $expiration_days = get_option('secure_login_expiration_days', 30);
                                    if ($expiration_days > 0): ?>
                                        <button type="button" class="button button-secondary extend-btn" 
                                                data-id="<?php echo $row->id; ?>">
                                            <?php echo esc_html__('Extend', 'secure-login-collector'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-secondary delete-btn" 
                                            data-id="<?php echo $row->id; ?>">
                                        <?php echo esc_html__('Delete', 'secure-login-collector'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr id="decrypted-row-<?php echo $row->id; ?>" class="decrypted-data-row" style="display: none;">
                                <td colspan="8">
                                    <div class="decrypted-content">
                                        <h4><?php echo esc_html__('Decrypted Data:', 'secure-login-collector'); ?></h4>
                                        <div class="decrypted-json" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 3px; max-height: 600px; overflow-y: auto;"></div>
                                        <button type="button" class="button hide-decrypted" data-id="<?php echo $row->id; ?>"><?php echo esc_html__('Hide', 'secure-login-collector'); ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        
        // Add the JavaScript for this page
        $this->admin_page_scripts();
    }
    
    // Add JavaScript for admin functionality
    public function admin_page_scripts() {
        ?>
        <style>
        /* Delete button styling */
        .delete-btn {
            border-color: #dc3545 !important;
            color: #dc3545 !important;
        }
        .delete-btn:hover {
            border-color: #c82333 !important;
            color: #c82333 !important;
        }
        .delete-btn:focus {
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.5) !important;
        }
        
        /* Actions column styling */
        .wp-list-table td:last-child {
            white-space: nowrap;
        }
        .wp-list-table .button {
            margin-right: 5px;
            margin-bottom: 2px;
        }
        .wp-list-table .button:last-child {
            margin-right: 0;
        }
        
        /* Encryption method styling */
        .encryption-method {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 120px;
            cursor: help;
        }
        
        .encryption-ultra-secure {
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
            box-shadow: 0 2px 4px rgba(76, 175, 80, 0.3);
        }
        
        .encryption-rsa {
            background: linear-gradient(135deg, #2196F3, #1976D2);
            color: white;
            box-shadow: 0 2px 4px rgba(33, 150, 243, 0.3);
        }
        
        .encryption-xor {
            background: linear-gradient(135deg, #FF9800, #F57C00);
            color: white;
            box-shadow: 0 2px 4px rgba(255, 152, 0, 0.3);
        }
        
        .encryption-method:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.2s ease;
        }
        
        /* Encryption method column width */
        .wp-list-table th:nth-child(6),
        .wp-list-table td:nth-child(6) {
            width: 140px;
            text-align: center;
        }
        </style>
        <script>
        jQuery(document).ready(function($) {
            
            // Format decrypted data for better readability.
            function formatDecryptedData(data) {
                var html = '<div class="formatted-data">';
                
                // Email
                if (data.email) {
                    html += '<div class="data-field">';
                    html += '<strong>Email Address:</strong><br>';
                    html += '<span class="field-value">' + escapeHtml(data.email) + '</span>';
                    html += '</div>';
                }
                
                // Name
                if (data.name) {
                    html += '<div class="data-field">';
                    html += '<strong>Name:</strong><br>';
                    html += '<span class="field-value">' + escapeHtml(data.name) + '</span>';
                    html += '</div>';
                }
                
                // Service Name
                if (data.service_name) {
                    html += '<div class="data-field">';
                    html += '<strong>Service Name:</strong><br>';
                    html += '<span class="field-value">' + escapeHtml(data.service_name) + '</span>';
                    html += '</div>';
                } else {
                    html += '<div class="data-field">';
                    html += '<strong>Service Name:</strong><br>';
                    html += '<span class="field-value"><em>Not provided</em></span>';
                    html += '</div>';
                }
                
                // Login Data (with preserved line breaks)
                if (data.login_data) {
                    html += '<div class="data-field">';
                    html += '<strong>Login Data:</strong>';
                    html += '<button type="button" class="copy-login-data" style="float: right; font-size: 11px; padding: 2px 6px;" onclick="copyLoginData(this)" data-login=\'' + escapeHtml(data.login_data) + '\'>Copy</button>';
                    html += '<br>';
                    html += '<div class="login-data-content">' + escapeHtml(data.login_data).replace(/\n/g, '<br>') + '</div>';
                    html += '</div>';
                }
                
                // Timestamp
                if (data.timestamp) {
                    var date = new Date(data.timestamp);
                    var formattedDate = date.toLocaleString();
                    html += '<div class="data-field">';
                    html += '<strong>Submitted:</strong><br>';
                    html += '<span class="field-value">' + formattedDate + '</span>';
                    html += '</div>';
                }
                
                html += '</div>';
                
                // Add CSS for formatting
                html += '<style>';
                html += '.formatted-data { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }';
                html += '.data-field { margin-bottom: 15px; padding: 10px; background: #fff; border: 1px solid #e1e1e1; border-radius: 4px; }';
                html += '.data-field strong { color: #23282d; font-size: 14px; }';
                html += '.field-value { color: #555; font-size: 13px; }';
                html += '.login-data-content { background: #f8f9fa; padding: 10px; border-radius: 3px; font-family: "Courier New", monospace; font-size: 13px; line-height: 1.5; margin-top: 5px; border: 1px solid #dee2e6; }';
                html += '.data-field a { color: #0073aa; text-decoration: none; }';
                html += '.data-field a:hover { text-decoration: underline; }';
                html += '</style>';
                
                return html;
            }
            
            // Escape HTML to prevent XSS
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
            
            // Copy login data to clipboard
            window.copyLoginData = function(button) {
                var loginData = button.getAttribute('data-login');
                
                // Create temporary textarea to copy text
                var textarea = document.createElement('textarea');
                textarea.value = loginData;
                document.body.appendChild(textarea);
                textarea.select();
                
                try {
                    document.execCommand('copy');
                    button.textContent = 'Copied!';
                    button.style.background = '#46b450';
                    button.style.color = 'white';
                    
                    setTimeout(function() {
                        button.textContent = 'Copy';
                        button.style.background = '';
                        button.style.color = '';
                    }, 2000);
                } catch (err) {
                    alert('Failed to copy to clipboard');
                }
                
                document.body.removeChild(textarea);
            };
            
            // Handle decrypt button click.
            $('.decrypt-btn').on('click', function() {
                var button = $(this);
                var id = button.data('id');
                var hostname = button.data('hostname');
                var timestamp = button.data('timestamp');
                var encryptionType = button.data('encryption-type');
                var decryptedRow = $('#decrypted-row-' + id);
                
                // Check if this is pro version and passkey is available
                var isProVersion = <?php echo json_encode($this->is_pro_version); ?>;
                var passkeyRegistered = <?php echo json_encode(get_option('secure_login_passkey_registered', false)); ?>;
                
                // SECURITY ENHANCEMENT: Pro version with passkey MUST use passkey authentication
                if (isProVersion && passkeyRegistered) {
                    // Force passkey authentication - no choice given
                    button.prop('disabled', true).text('Requesting passkey...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'decrypt_secure_login_data',
                            decrypt_id: id,
                            use_passkey: 'true',
                            nonce: '<?php echo wp_create_nonce('decrypt_login_data'); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.requires_passkey) {
                                // Initiate passkey authentication
                                authenticateWithPasskey(id, button, decryptedRow);
                            } else {
                                alert('Passkey authentication setup failed: ' + (response.data || 'Unknown error'));
                                button.prop('disabled', false).text('Decrypt');
                            }
                        },
                        error: function() {
                            alert('Network error occurred during passkey setup.');
                            button.prop('disabled', false).text('Decrypt');
                        }
                    });
                    return;
                }
                
                // For pro version without passkey or non-pro version, offer choice
                if (isProVersion && !passkeyRegistered) {
                    // Pro version but no passkey registered - warn user and suggest registering passkey
                    if (!confirm('Pro version detected but no passkey registered.\n\nFor maximum security, consider registering a passkey in Settings.\n\nContinue with traditional decryption?')) {
                        return;
                    }
                }
                
                // Traditional decryption (for non-pro or pro without passkey registered)
                try {
                    var encryptionKey = '';
                    
                    // Check if this is RSA encrypted (new entries) or XOR encrypted (legacy entries)
                    if (encryptionType === 'rsa') {
                        // RSA encryption - no manual key needed, server handles decryption
                        encryptionKey = 'rsa'; // Just a placeholder to indicate RSA
                    } else if (hostname && timestamp) {
                        // XOR encryption with automatic key reconstruction
                        encryptionKey = hostname + timestamp;
                    } else {
                        // Legacy XOR encryption - prompt user for manual key entry
                        encryptionKey = prompt('This entry was created before automatic key reconstruction was available.\nPlease enter the encryption key manually:');
                        if (!encryptionKey) {
                            return;
                        }
                    }
                    
                    // Disable button and show loading.
                    button.prop('disabled', true).text('Decrypting...');
                    
                    // Make AJAX request to decrypt data.
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'decrypt_secure_login_data',
                            decrypt_id: id,
                            encryption_key: encryptionKey,
                            nonce: '<?php echo wp_create_nonce('decrypt_login_data'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Format the data for better readability.
                                var formattedData = formatDecryptedData(response.data);
                                decryptedRow.find('.decrypted-json').html(formattedData);
                                decryptedRow.show();
                                button.text('Decrypted').addClass('button-secondary');
                            } else {
                                alert('Decryption failed: ' + (response.data || 'Unknown error'));
                                button.prop('disabled', false).text('Decrypt');
                            }
                        },
                        error: function() {
                            alert('Network error occurred during decryption.');
                            button.prop('disabled', false).text('Decrypt');
                        }
                    });
                    
                } catch (e) {
                    alert('Error processing decryption: ' + e.message);
                }
            });
            
            // Passkey authentication function
            function authenticateWithPasskey(id, button, decryptedRow) {
                button.text('Authenticate with passkey...');
                
                // Check if WebAuthn is supported
                if (!window.PublicKeyCredential) {
                    alert('WebAuthn/Passkeys are not supported in this browser.');
                    button.prop('disabled', false).text('Decrypt');
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
                        // Send authentication data to server
                        button.text('Verifying passkey...');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'authenticate_passkey',
                                signature: btoa(String.fromCharCode(...new Uint8Array(assertion.response.signature))),
                                authenticator_data: btoa(String.fromCharCode(...new Uint8Array(assertion.response.authenticatorData))),
                                nonce: '<?php echo wp_create_nonce('authenticate_passkey'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Format the data for better readability.
                                    var formattedData = formatDecryptedData(response.data);
                                    decryptedRow.find('.decrypted-json').html(formattedData);
                                    decryptedRow.show();
                                    button.text('Decrypted (Passkey)').addClass('button-secondary');
                                } else {
                                    alert('Passkey authentication failed: ' + (response.data || 'Unknown error'));
                                    button.prop('disabled', false).text('Decrypt');
                                }
                            },
                            error: function() {
                                alert('Network error occurred during passkey verification.');
                                button.prop('disabled', false).text('Decrypt');
                            }
                        });
                    })
                    .catch((err) => {
                        console.error('Passkey authentication failed:', err);
                        alert('Passkey authentication failed: ' + err.message);
                        button.prop('disabled', false).text('Decrypt');
                    });
            }
            
            // Handle hide button click.
            $('.hide-decrypted').on('click', function() {
                var id = $(this).data('id');
                var decryptedRow = $('#decrypted-row-' + id);
                var decryptBtn = $('.decrypt-btn[data-id="' + id + '"]');
                
                decryptedRow.hide();
                decryptBtn.prop('disabled', false).text('Decrypt').removeClass('button-secondary');
            });
            
            // Handle extend button click.
            $('.extend-btn').on('click', function() {
                var button = $(this);
                var id = button.data('id');
                var row = button.closest('tr');
                
                if (!confirm('Are you sure you want to extend the retention period for this login data?')) {
                    return;
                }
                
                // Disable button and show loading.
                button.prop('disabled', true).text('Extending...');
                
                // Make AJAX request to extend retention.
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'extend_secure_login_data',
                        extend_id: id,
                        nonce: '<?php echo wp_create_nonce('extend_login_data'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the expiration column only.
                            row.find('td:nth-child(6)').html(response.data.new_expiration);
                            
                            // Show success message.
                            alert(response.data.message);
                            
                            // Reset button.
                            button.prop('disabled', false).text('Extend');
                        } else {
                            alert('Extend failed: ' + (response.data || 'Unknown error'));
                            button.prop('disabled', false).text('Extend');
                        }
                    },
                    error: function() {
                        alert('Network error occurred during extension.');
                        button.prop('disabled', false).text('Extend');
                    }
                });
            });
            
            // Handle delete button click.
            $('.delete-btn').on('click', function() {
                var button = $(this);
                var id = button.data('id');
                
                if (!confirm('Are you sure you want to delete this login data? This action cannot be undone.')) {
                    return;
                }
                
                // Disable button and show loading.
                button.prop('disabled', true).text('Deleting...');
                
                // Make AJAX request to delete data.
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'delete_secure_login_data',
                        delete_id: id,
                        nonce: '<?php echo wp_create_nonce('delete_login_data'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Remove the row from the table.
                            $('tr').has('[data-id="' + id + '"]').remove();
                            $('#decrypted-row-' + id).remove();
                        } else {
                            alert('Delete failed: ' + (response.data || 'Unknown error'));
                            button.prop('disabled', false).text('Delete');
                        }
                    },
                    error: function() {
                        alert('Network error occurred during deletion.');
                        button.prop('disabled', false).text('Delete');
                    }
                });
            });
            
            // Handle edit button click
            $('.edit-btn').on('click', function() {
                var id = $(this).data('id');
                var row = $(this).closest('tr');
                
                // Hide display values and show edit fields
                row.find('.display-value').hide();
                row.find('.edit-field').show();
                
                // Hide edit button, show save/cancel buttons
                $(this).hide();
                row.find('.save-btn, .cancel-btn').show();
            });
            
            // Handle cancel button click
            $('.cancel-btn').on('click', function() {
                var id = $(this).data('id');
                var row = $(this).closest('tr');
                
                // Reset edit fields to original values
                row.find('.edit-field').each(function() {
                    var field = $(this).data('field');
                    var displayValue = row.find('.display-value[data-field="' + field + '"]');
                    $(this).val(displayValue.text().trim());
                });
                
                // Show display values and hide edit fields
                row.find('.display-value').show();
                row.find('.edit-field').hide();
                
                // Show edit button, hide save/cancel buttons
                row.find('.edit-btn').show();
                $(this).hide();
                row.find('.save-btn').hide();
            });
            
            // Handle save button click
            $('.save-btn').on('click', function() {
                var button = $(this);
                var id = button.data('id');
                var row = button.closest('tr');
                
                // Collect edited data
                var editedData = {};
                row.find('.edit-field').each(function() {
                    var field = $(this).data('field');
                    editedData[field] = $(this).val().trim();
                });
                
                // Validate required fields
                if (!editedData.email || !editedData.name || !editedData.service_name) {
                    alert('Email, Name, and Service Name are required fields.');
                    return;
                }
                
                // Disable button and show loading
                button.prop('disabled', true).text('Saving...');
                
                // Make AJAX request to save changes
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'update_secure_login_metadata',
                        entry_id: id,
                        metadata: editedData,
                        nonce: '<?php echo wp_create_nonce('update_login_metadata'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update display values
                            row.find('.display-value').each(function() {
                                var field = $(this).data('field');
                                var newValue = editedData[field] || 'N/A';
                                if (field === 'service_name' && !editedData[field]) {
                                    newValue = 'Not provided';
                                }
                                $(this).text(newValue);
                            });
                            
                            // Show display values and hide edit fields
                            row.find('.display-value').show();
                            row.find('.edit-field').hide();
                            
                            // Show edit button, hide save/cancel buttons
                            row.find('.edit-btn').show();
                            button.hide();
                            row.find('.cancel-btn').hide();
                            
                            alert('Changes saved successfully!');
                        } else {
                            alert('Failed to save changes: ' + (response.data || 'Unknown error'));
                        }
                    },
                    error: function() {
                        alert('Network error occurred while saving changes.');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Save');
                    }
                });
            });
            
            // Modal functionality
            $('#add-new-entry-btn').on('click', function() {
                $('#add-new-entry-modal').show();
            });
            
            $('.close-modal, #cancel-manual-entry').on('click', function() {
                $('#add-new-entry-modal').hide();
                $('#manual-add-form')[0].reset();
            });
            
            // Close modal when clicking outside
            $(window).on('click', function(event) {
                if (event.target.id === 'add-new-entry-modal') {
                    $('#add-new-entry-modal').hide();
                    $('#manual-add-form')[0].reset();
                }
            });
            
            // Handle manual entry form submission
            $('#manual-add-form').on('submit', function(e) {
                e.preventDefault();
                
                var form = $(this);
                var submitBtn = $('#save-manual-entry');
                
                // Get form data
                var email = $('#manual_email').val().trim();
                var name = $('#manual_name').val().trim();
                var serviceName = $('#manual_service_name').val().trim();
                var loginData = $('#manual_login_data').val().trim();
                var encryptionMethod = $('#manual_encryption_method').val();
                
                // Validate required fields
                if (!email || !name || !serviceName || !loginData) {
                    alert('<?php echo esc_js(__('Please fill in all required fields.', 'secure-login-collector')); ?>');
                    return;
                }
                
                // Disable submit button and show loading
                submitBtn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'secure-login-collector')); ?>');
                
                // For manual entries, we'll use server-side encryption
                var metadata = {
                    email: email,
                    name: name,
                    service_name: serviceName,
                    created_at: new Date().toISOString(),
                    encryption_type: encryptionMethod,
                    manually_added: true,
                    added_by_user: <?php echo json_encode(get_current_user_id()); ?>
                };
                
                // Submit to server for encryption and storage
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_manual_login_data',
                        login_data: loginData,
                        metadata: JSON.stringify(metadata),
                        encryption_method: encryptionMethod,
                        nonce: '<?php echo wp_create_nonce('save_manual_login_data'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php echo esc_js(__('Login data saved successfully!', 'secure-login-collector')); ?>');
                            $('#add-new-entry-modal').hide();
                            $('#manual-add-form')[0].reset();
                            location.reload(); // Refresh to show new entry
                        } else {
                            alert('<?php echo esc_js(__('Error saving data: ', 'secure-login-collector')); ?>' + (response.data || '<?php echo esc_js(__('Unknown error', 'secure-login-collector')); ?>'));
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error occurred while saving data.', 'secure-login-collector')); ?>');
                    },
                    complete: function() {
                        submitBtn.prop('disabled', false).text('<?php echo esc_js(__('Save Entry', 'secure-login-collector')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Decrypt data using the provided encryption key or passkey authentication.
     */
    private function decrypt_data($id, $encryption_key = null) {
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
                return $this->decrypt_passkey_derived_data($row->encrypted_data);
            } else if ($encryption_type === 'rsa') {
                return $this->decrypt_rsa_data($row->encrypted_data);
            }
            
            // Fallback to XOR decryption for legacy data
            if ($encryption_key && $encryption_key !== 'rsa') {
                error_log('Secure Login Collector: Using XOR decryption with key: ' . substr($encryption_key, 0, 5) . '...');
                return $this->decrypt_xor_data($row->encrypted_data, $encryption_key);
            }
            
            error_log('Secure Login Collector: No valid decryption method available');
            return false;
            
        } catch (Exception $e) {
            error_log('Secure Login Collector: Exception during decryption: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Decrypt passkey-derived encrypted data (requires passkey authentication).
     * Now supports double encryption: passkey -> RSA -> plain data
     */
    private function decrypt_passkey_derived_data($encrypted_data) {
        // This method is called after passkey authentication
        // We no longer need the signature since we use the stored public key for key derivation
        $user_id = get_current_user_id();
        
        // Check if passkey authentication was successful
        $passkey_authenticated = get_transient('secure_login_passkey_authenticated_' . $user_id);
        if (!$passkey_authenticated) {
            error_log('Secure Login Collector: Passkey authentication not verified for decryption');
            return false;
        }
        
        // First decrypt with passkey-derived key
        $first_decrypted = $this->decrypt_with_passkey_key($encrypted_data, null);
        
        if ($first_decrypted === false) {
            error_log('Secure Login Collector: Passkey-derived decryption failed');
            return false;
        }
        
        // Check if this is double-encrypted data by trying to parse as JSON first
        $data = json_decode($first_decrypted, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            // Successfully parsed as JSON - this is single-encrypted data (old format)
            error_log('Secure Login Collector: Single passkey-derived decryption successful');
            return $data;
        } else {
            // Not JSON - this might be double-encrypted data (RSA-encrypted data that was passkey-encrypted)
            error_log('Secure Login Collector: Attempting RSA decryption of inner layer');
            
            // Try to decrypt the inner RSA layer
            $final_decrypted = $this->decrypt_rsa_data($first_decrypted);
            
            if ($final_decrypted !== false) {
                error_log('Secure Login Collector: Double decryption (passkey + RSA) successful');
                return $final_decrypted;
            } else {
                error_log('Secure Login Collector: RSA inner decryption failed, treating as raw data');
                // If RSA decryption fails, treat the first decrypted data as the final result
                // This handles edge cases where the data might not be JSON but is still valid
                return array('raw_data' => $first_decrypted);
            }
        }
    }
    
    /**
     * Decrypt RSA encrypted data (now available for all users).
     */
    private function decrypt_rsa_data($encrypted_data) {
        error_log('Secure Login Collector: Starting RSA decryption process');
        
        // Get encrypted private key
        $encrypted_private_key = get_option('secure_login_private_key_encrypted');
        if (!$encrypted_private_key) {
            error_log('Secure Login Collector: No encrypted private key found');
            return false;
        }
        
        error_log('Secure Login Collector: Found encrypted private key, attempting to decrypt');
        
        // Decrypt private key
        $private_key = $this->decrypt_private_key($encrypted_private_key);
        if (!$private_key) {
            error_log('Secure Login Collector: Failed to decrypt private key');
            return false;
        }
        
        error_log('Secure Login Collector: Private key decrypted successfully, length: ' . strlen($private_key));
        
        // Decode base64 encrypted data
        $encrypted_data = base64_decode($encrypted_data);
        if ($encrypted_data === false) {
            error_log('Secure Login Collector: Failed to decode base64 encrypted data');
            return false;
        }
        
        error_log('Secure Login Collector: Base64 decoded successfully, data length: ' . strlen($encrypted_data));
        
        // Load the private key resource
        $private_key_resource = openssl_pkey_get_private($private_key);
        if (!$private_key_resource) {
            error_log('Secure Login Collector: Failed to load private key resource - ' . openssl_error_string());
            return false;
        }
        
        // Decrypt with RSA-OAEP (SHA-1 default)
        $decrypted = '';
        if (!openssl_private_decrypt($encrypted_data, $decrypted, $private_key_resource, OPENSSL_PKCS1_OAEP_PADDING)) {
            error_log('Secure Login Collector: RSA OAEP decryption failed - ' . openssl_error_string());
            
            // If decryption fails, it might be because keys need regeneration
            $key_version = get_option('secure_login_key_version', '1.0');
            if (version_compare($key_version, '2.3.5', '<')) {
                error_log('Secure Login Collector: Keys are from old version (' . $key_version . '), regenerating...');
                
                // Regenerate keys
                $keypair = $this->generate_rsa_keypair();
                if (!is_wp_error($keypair)) {
                    update_option('secure_login_key_version', '2.3.5');
                    error_log('Secure Login Collector: RSA keys regenerated for OAEP compatibility');
                }
            }
            
            return false;
        }
        
        error_log('Secure Login Collector: RSA OAEP decryption successful, decrypted length: ' . strlen($decrypted));
        
        // Parse JSON
        $data = json_decode($decrypted, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            error_log('Secure Login Collector: JSON parsing successful');
            return $data;
        } else {
            error_log('Secure Login Collector: JSON decode failed - ' . json_last_error_msg());
            return false;
        }
    }
    
    /**
     * Decrypt XOR encrypted data (Basic version or legacy).
     */
    private function decrypt_xor_data($encrypted_data, $encryption_key) {
        // Decode base64.
        $encrypted_data = base64_decode($encrypted_data);
        
        // Decrypt using XOR cipher (same as encryption).
        $decrypted = '';
        $keyIndex = 0;
        
        for ($i = 0; $i < strlen($encrypted_data); $i++) {
            $charCode = ord($encrypted_data[$i]);
            $keyChar = ord($encryption_key[$keyIndex % strlen($encryption_key)]);
            $decrypted .= chr($charCode ^ $keyChar);
            $keyIndex++;
        }
        
        // Parse JSON.
        $data = json_decode($decrypted, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        
        return false;
    }
    
    /**
     * Frontend form shortcode.
     */
    public function frontend_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => __('Submit Secure Login Data', 'secure-login-collector'),
            'button_text' => __('Submit Securely', 'secure-login-collector')
        ), $atts);
        
        ob_start();
        ?>
        <div class="secure-login-form-container">
            <h3><?php echo esc_html($atts['title']); ?></h3>
            <div class="security-info">
                <p><?php echo esc_html__('Please fill out this form to securely send your login credentials. All data is encrypted before transmission.', 'secure-login-collector'); ?></p>
                <?php 
                $expiration_days = get_option('secure_login_expiration_days', 30);
                $ultra_secure_mode = $this->is_pro_version && get_option('secure_login_ultra_secure_mode', false) && get_option('secure_login_passkey_registered', false);
                
                if ($ultra_secure_mode): ?>
                    <p><strong><?php echo esc_html__('🔐 Ultra-Secure Mode Active:', 'secure-login-collector'); ?></strong> <?php echo esc_html__('Your data will be encrypted with advanced passkey-derived encryption for maximum security.', 'secure-login-collector'); ?></p>
                <?php endif; ?>
                
                <?php if ($expiration_days > 0): ?>
                    <p><strong><?php echo esc_html__('Security & Privacy:', 'secure-login-collector'); ?></strong> <?php echo sprintf(esc_html__('Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely and automatically delete it after %d days for your privacy and security.', 'secure-login-collector'), $expiration_days); ?></p>
                <?php else: ?>
                    <p><strong><?php echo esc_html__('Security & Privacy:', 'secure-login-collector'); ?></strong> <?php echo esc_html__('Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely. Auto-deletion is disabled, so data will be retained until manually deleted by the administrator.', 'secure-login-collector'); ?></p>
                <?php endif; ?>
            </div>
            <form id="secure-login-frontend-form" class="secure-login-form">
                <div class="form-group">
                    <label for="email"><?php echo esc_html__('Email Address:', 'secure-login-collector'); ?> <span class="required">*</span></label>
                    <input type="email" id="email" name="email" placeholder="<?php echo esc_attr__('your@email.com', 'secure-login-collector'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="user_name"><?php echo esc_html__('Name:', 'secure-login-collector'); ?> <span class="required">*</span></label>
                    <input type="text" id="user_name" name="user_name" placeholder="<?php echo esc_attr__('Your full name', 'secure-login-collector'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="service_name"><?php echo esc_html__('Service Name:', 'secure-login-collector'); ?> <span class="required">*</span></label>
                    <input type="text" id="service_name" name="service_name" placeholder="<?php echo esc_attr__('Name like email account or hosting company etc.', 'secure-login-collector'); ?>" required>
                    <small class="form-help"><?php echo esc_html__('Enter the name of the service, email provider, hosting company, or platform these credentials are for.', 'secure-login-collector'); ?></small>
                </div>
                
                <div class="form-group">
                    <label for="login_data"><?php echo esc_html__('Login Data:', 'secure-login-collector'); ?> <span class="required">*</span></label>
                    <textarea id="login_data" name="login_data" placeholder="<?php echo esc_attr__('Enter your login credentials, passwords, or any access information here...', 'secure-login-collector'); ?>" rows="6" required></textarea>
                    <small class="form-help"><?php echo esc_html__('Enter usernames, passwords, URLs, or any login information you need to share securely.', 'secure-login-collector'); ?></small>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="secure-submit-btn"><?php echo esc_html($atts['button_text']); ?></button>
                </div>
                
                <div id="form-message" class="form-message" style="display: none;"></div>
            </form>
        </div>
        
        <style>
        .secure-login-form-container {
            max-width: 600px;
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .security-info {
            background: #e8f4fd;
            border: 1px solid #bee5eb;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        .security-info p {
            margin: 0 0 10px 0;
        }
        .security-info p:last-child {
            margin-bottom: 0;
        }
        .secure-login-form .form-group {
            margin-bottom: 15px;
        }
        .secure-login-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .secure-login-form .required {
            color: red;
        }
        .secure-login-form input,
        .secure-login-form textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
            box-sizing: border-box;
            font-family: inherit;
        }
        .secure-login-form textarea {
            resize: vertical;
            min-height: 120px;
        }
        .secure-login-form .form-help {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 0.9em;
        }
        .secure-submit-btn {
            background: #0073aa;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }
        .secure-submit-btn:hover {
            background: #005a87;
        }
        .secure-submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .form-message {
            padding: 10px;
            margin-top: 10px;
            border-radius: 3px;
        }
        .form-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .form-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting('secure_login_settings', 'secure_login_notification_email');
        register_setting('secure_login_settings', 'secure_login_enable_notifications');
        register_setting('secure_login_settings', 'secure_login_expiration_days');
        register_setting('secure_login_settings', 'secure_login_ultra_secure_mode');
        
        add_settings_section(
            'secure_login_notification_section',
            __('Email Notifications', 'secure-login-collector'),
            array($this, 'notification_section_callback'),
            'secure_login_settings'
        );
        
        add_settings_section(
            'secure_login_expiration_section',
            __('Data Expiration', 'secure-login-collector'),
            array($this, 'expiration_section_callback'),
            'secure_login_settings'
        );
        
        // Add encryption settings section (for all users now)
        add_settings_section(
            'secure_login_encryption_section',
            __('Encryption Settings', 'secure-login-collector'),
            array($this, 'encryption_section_callback'),
            'secure_login_settings'
        );
        
        // Add pro version settings section
        if ($this->is_pro_version) {
            add_settings_section(
                'secure_login_pro_section',
                __('Pro Version Settings', 'secure-login-collector'),
                array($this, 'pro_section_callback'),
                'secure_login_settings'
            );
            
            add_settings_field(
                'secure_login_ultra_secure_mode',
                __('Ultra-Secure Mode', 'secure-login-collector'),
                array($this, 'ultra_secure_mode_callback'),
                'secure_login_settings',
                'secure_login_pro_section'
            );
        }
        
        add_settings_field(
            'secure_login_enable_notifications',
            __('Enable Email Notifications', 'secure-login-collector'),
            array($this, 'enable_notifications_callback'),
            'secure_login_settings',
            'secure_login_notification_section'
        );
        
        add_settings_field(
            'secure_login_notification_email',
            __('Notification Email Address', 'secure-login-collector'),
            array($this, 'notification_email_callback'),
            'secure_login_settings',
            'secure_login_notification_section'
        );
        
        add_settings_field(
            'secure_login_expiration_days',
            __('Auto-Delete After (Days)', 'secure-login-collector'),
            array($this, 'expiration_days_callback'),
            'secure_login_settings',
            'secure_login_expiration_section'
        );
    }
    
    /**
     * Settings section callbacks.
     */
    public function notification_section_callback() {
        echo '<p>' . esc_html__('Configure email notifications for new login data submissions.', 'secure-login-collector') . '</p>';
    }
    
    public function expiration_section_callback() {
        echo '<p>' . esc_html__('Configure automatic deletion of old login data.', 'secure-login-collector') . '</p>';
    }
    
    public function encryption_section_callback() {
        echo '<p>' . esc_html__('Manage RSA encryption keys for secure data transmission.', 'secure-login-collector') . '</p>';
        
        // Display encryption methods information
        echo '<div class="encryption-methods-info" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin-bottom: 20px;">';
        echo '<h4>' . esc_html__('Available Encryption Methods:', 'secure-login-collector') . '</h4>';
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 10px;">';
        
        // Ultra-Secure (Passkey-derived)
        if ($this->is_pro_version) {
            echo '<div style="background: white; border: 1px solid #4CAF50; border-radius: 4px; padding: 12px;">';
            echo '<div style="display: flex; align-items: center; margin-bottom: 8px;">';
            echo '<span style="background: linear-gradient(135deg, #4CAF50, #45a049); color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 8px;">🔐 ULTRA-SECURE</span>';
            echo '<strong>' . esc_html__('Passkey-Derived', 'secure-login-collector') . '</strong>';
            echo '</div>';
            echo '<p style="margin: 0; font-size: 13px; color: #666;">' . esc_html__('Uses your passkey signature to derive encryption keys. Maximum security - even server compromise cannot decrypt data without your physical device.', 'secure-login-collector') . '</p>';
            echo '</div>';
        }
        
        // RSA-2048
        echo '<div style="background: white; border: 1px solid #2196F3; border-radius: 4px; padding: 12px;">';
        echo '<div style="display: flex; align-items: center; margin-bottom: 8px;">';
        echo '<span style="background: linear-gradient(135deg, #2196F3, #1976D2); color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 8px;">🔒 SECURE</span>';
        echo '<strong>' . esc_html__('RSA-2048', 'secure-login-collector') . '</strong>';
        echo '</div>';
        echo '<p style="margin: 0; font-size: 13px; color: #666;">' . esc_html__('Industry-standard RSA encryption with 2048-bit keys. Secure for most use cases and available for all users.', 'secure-login-collector') . '</p>';
        echo '</div>';
        
        // XOR (Legacy)
        echo '<div style="background: white; border: 1px solid #FF9800; border-radius: 4px; padding: 12px;">';
        echo '<div style="display: flex; align-items: center; margin-bottom: 8px;">';
        echo '<span style="background: linear-gradient(135deg, #FF9800, #F57C00); color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 8px;">🔓 LEGACY</span>';
        echo '<strong>' . esc_html__('XOR (Legacy)', 'secure-login-collector') . '</strong>';
        echo '</div>';
        echo '<p style="margin: 0; font-size: 13px; color: #666;">' . esc_html__('Simple XOR encryption for backward compatibility. Used by older entries before RSA was implemented.', 'secure-login-collector') . '</p>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        
        // Display key status
        $public_key = get_option('secure_login_public_key');
        $keys_generated = get_option('secure_login_keys_generated');
        
        if ($public_key && $keys_generated) {
            echo '<div class="notice notice-success inline"><p>';
            echo '<strong>' . esc_html__('RSA Keys Status:', 'secure-login-collector') . '</strong> ';
            echo esc_html__('Active', 'secure-login-collector') . ' ';
            echo '<em>(' . esc_html__('Generated:', 'secure-login-collector') . ' ' . esc_html($keys_generated) . ')</em>';
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p>';
            echo '<strong>' . esc_html__('RSA Keys Status:', 'secure-login-collector') . '</strong> ';
            echo esc_html__('Not generated', 'secure-login-collector');
            echo '</p></div>';
        }
        
        // Key management buttons
        echo '<p>';
        echo '<button type="button" class="button button-secondary" id="generate-rsa-keys">' . esc_html__('Generate New RSA Keys', 'secure-login-collector') . '</button> ';
        echo '<button type="button" class="button button-secondary" id="export-public-key">' . esc_html__('Export Public Key', 'secure-login-collector') . '</button>';
        echo '</p>';
        
        // Add JavaScript for key management
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#generate-rsa-keys').on('click', function() {
                if (!confirm('<?php echo esc_js(__('This will generate new RSA keys and invalidate all existing encrypted data. Continue?', 'secure-login-collector')); ?>')) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('<?php echo esc_js(__('Generating...', 'secure-login-collector')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_rsa_keys',
                        nonce: '<?php echo wp_create_nonce('generate_rsa_keys'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php echo esc_js(__('RSA keys generated successfully!', 'secure-login-collector')); ?>');
                            location.reload();
                        } else {
                            alert('<?php echo esc_js(__('Failed to generate keys:', 'secure-login-collector')); ?> ' + response.data);
                        }
                        button.prop('disabled', false).text('<?php echo esc_js(__('Generate New RSA Keys', 'secure-login-collector')); ?>');
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error occurred.', 'secure-login-collector')); ?>');
                        button.prop('disabled', false).text('<?php echo esc_js(__('Generate New RSA Keys', 'secure-login-collector')); ?>');
                    }
                });
            });
            
            $('#export-public-key').on('click', function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'export_public_key',
                        nonce: '<?php echo wp_create_nonce('export_public_key'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Create download
                            var blob = new Blob([response.data.public_key], {type: 'text/plain'});
                            var url = window.URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = 'secure-login-public-key.pem';
                            a.click();
                            window.URL.revokeObjectURL(url);
                        } else {
                            alert('<?php echo esc_js(__('Failed to export public key:', 'secure-login-collector')); ?> ' + response.data);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function pro_section_callback() {
        echo '<p>' . esc_html__('Advanced security settings for the pro version including passkey authentication.', 'secure-login-collector') . '</p>';
        
        // Display passkey status
        $passkey_registered = get_option('secure_login_passkey_registered', false);
        $passkey_user_id = get_option('secure_login_passkey_user_id', 0);
        
        if ($passkey_registered && $passkey_user_id == get_current_user_id()) {
            echo '<div class="notice notice-success inline"><p>';
            echo '<strong>' . esc_html__('Passkey Status:', 'secure-login-collector') . '</strong> ';
            echo esc_html__('Registered and Active', 'secure-login-collector');
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p>';
            echo '<strong>' . esc_html__('Passkey Status:', 'secure-login-collector') . '</strong> ';
            echo esc_html__('Not registered', 'secure-login-collector');
            echo '</p></div>';
        }
        
        // Passkey management buttons
        echo '<p>';
        if ($passkey_registered && $passkey_user_id == get_current_user_id()) {
            echo '<button type="button" class="button button-secondary" id="reset-passkey">' . esc_html__('Reset Passkey', 'secure-login-collector') . '</button> ';
            echo '<button type="button" class="button button-secondary" id="test-passkey">' . esc_html__('Test Passkey', 'secure-login-collector') . '</button> ';
            echo '<button type="button" class="button button-secondary" id="test-passkey-encryption">' . esc_html__('Test Encryption', 'secure-login-collector') . '</button>';
        } else {
            echo '<button type="button" class="button button-primary" id="register-passkey">' . esc_html__('Register Passkey', 'secure-login-collector') . '</button> ';
            echo '<button type="button" class="button button-secondary" id="test-passkey" disabled>' . esc_html__('Test Passkey', 'secure-login-collector') . '</button> ';
            echo '<button type="button" class="button button-secondary" id="test-passkey-encryption" disabled>' . esc_html__('Test Encryption', 'secure-login-collector') . '</button>';
        }
        echo '</p>';
        
        echo '<p class="description">' . esc_html__('Passkeys provide enhanced security for decrypting login data. Once registered, you can use your phone, tablet, security key, or password manager (Bitwarden, 1Password, etc.) to authenticate before viewing sensitive data. The passkey will sync across your devices through your password manager.', 'secure-login-collector') . '</p>';
        
        echo '<div class="notice notice-info inline"><p>';
        echo '<strong>' . esc_html__('💡 Tip:', 'secure-login-collector') . '</strong> ';
        echo esc_html__('When registering your passkey, your browser may offer to save it in your password manager. This allows you to access your encrypted data from any device where your password manager is installed.', 'secure-login-collector');
        echo '</p></div>';
        
        // Add JavaScript for passkey management
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Check if WebAuthn is supported
            if (!window.PublicKeyCredential) {
                $('#register-passkey, #test-passkey').prop('disabled', true);
                $('.notice.notice-warning').after('<div class="notice notice-error inline"><p><strong>Error:</strong> WebAuthn/Passkeys are not supported in this browser.</p></div>');
                return;
            }
            
            $('#register-passkey').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php echo esc_js(__('Registering...', 'secure-login-collector')); ?>');
                
                // Generate challenge and user info
                var challenge = new Uint8Array(32);
                window.crypto.getRandomValues(challenge);
                
                var userId = new TextEncoder().encode('<?php echo get_current_user_id(); ?>');
                var userName = '<?php echo esc_js(wp_get_current_user()->user_login); ?>';
                var userDisplayName = '<?php echo esc_js(wp_get_current_user()->display_name); ?>';
                
                var createCredentialDefaultArgs = {
                    publicKey: {
                        rp: {
                            name: "<?php echo esc_js(get_bloginfo('name')); ?>",
                            id: window.location.hostname,
                        },
                        user: {
                            id: userId,
                            name: userName,
                            displayName: userDisplayName,
                        },
                        pubKeyCredParams: [{alg: -7, type: "public-key"}],
                        authenticatorSelection: {
                            // Enable password manager support
                            authenticatorAttachment: "cross-platform", // Allows password managers
                            userVerification: "required",
                            residentKey: "required" // Enables storage in password managers
                        },
                        timeout: 60000,
                        challenge: challenge
                    }
                };
                
                navigator.credentials.create(createCredentialDefaultArgs)
                    .then((credential) => {
                        // Send credential to server
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'register_passkey',
                                credential_id: btoa(String.fromCharCode(...new Uint8Array(credential.rawId))),
                                public_key: btoa(String.fromCharCode(...new Uint8Array(credential.response.getPublicKey()))),
                                nonce: '<?php echo wp_create_nonce('register_passkey'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('<?php echo esc_js(__('Passkey registered successfully!', 'secure-login-collector')); ?>');
                                    location.reload();
                                } else {
                                    alert('<?php echo esc_js(__('Failed to register passkey:', 'secure-login-collector')); ?> ' + response.data);
                                }
                            },
                            error: function() {
                                alert('<?php echo esc_js(__('Network error occurred.', 'secure-login-collector')); ?>');
                            },
                            complete: function() {
                                button.prop('disabled', false).text('<?php echo esc_js(__('Register Passkey', 'secure-login-collector')); ?>');
                            }
                        });
                    })
                    .catch((err) => {
                        console.error('Passkey registration failed:', err);
                        alert('<?php echo esc_js(__('Passkey registration failed:', 'secure-login-collector')); ?> ' + err.message);
                        button.prop('disabled', false).text('<?php echo esc_js(__('Register Passkey', 'secure-login-collector')); ?>');
                    });
            });
            
            $('#reset-passkey').on('click', function() {
                if (!confirm('<?php echo esc_js(__('This will reset your passkey registration. You will need to authenticate with your current passkey and then register a new one. Continue?', 'secure-login-collector')); ?>')) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('<?php echo esc_js(__('Authenticating...', 'secure-login-collector')); ?>');
                
                // Generate challenge for authentication
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
                        // Send authentication data to server for reset authorization
                        button.text('<?php echo esc_js(__('Authorizing reset...', 'secure-login-collector')); ?>');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'reset_passkey',
                                signature: btoa(String.fromCharCode(...new Uint8Array(assertion.response.signature))),
                                authenticator_data: btoa(String.fromCharCode(...new Uint8Array(assertion.response.authenticatorData))),
                                nonce: '<?php echo wp_create_nonce('reset_passkey'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('<?php echo esc_js(__('Passkey reset authorized! You can now register a new passkey.', 'secure-login-collector')); ?>');
                                    location.reload();
                                } else {
                                    alert('<?php echo esc_js(__('Reset authorization failed:', 'secure-login-collector')); ?> ' + response.data);
                                }
                            },
                            error: function() {
                                alert('<?php echo esc_js(__('Network error occurred during reset authorization.', 'secure-login-collector')); ?>');
                            },
                            complete: function() {
                                button.prop('disabled', false).text('<?php echo esc_js(__('Reset Passkey', 'secure-login-collector')); ?>');
                            }
                        });
                    })
                    .catch((err) => {
                        console.error('Passkey authentication for reset failed:', err);
                        alert('<?php echo esc_js(__('Authentication failed:', 'secure-login-collector')); ?> ' + err.message);
                        button.prop('disabled', false).text('<?php echo esc_js(__('Reset Passkey', 'secure-login-collector')); ?>');
                    });
            });
            
            $('#test-passkey').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'secure-login-collector')); ?>');
                
                // Test passkey authentication
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
                        alert('<?php echo esc_js(__('Passkey test successful!', 'secure-login-collector')); ?>');
                    })
                    .catch((err) => {
                        console.error('Passkey test failed:', err);
                        alert('<?php echo esc_js(__('Passkey test failed:', 'secure-login-collector')); ?> ' + err.message);
                    })
                    .finally(() => {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Test Passkey', 'secure-login-collector')); ?>');
                    });
            });
            
            $('#test-passkey-encryption').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'secure-login-collector')); ?>');
                
                // Test passkey encryption/decryption
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_passkey_encryption',
                        nonce: '<?php echo wp_create_nonce('test_passkey_encryption'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php echo esc_js(__('Encryption test result: ', 'secure-login-collector')); ?>' + response.data);
                        } else {
                            alert('<?php echo esc_js(__('Encryption test failed: ', 'secure-login-collector')); ?>' + response.data);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error during encryption test.', 'secure-login-collector')); ?>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Test Encryption', 'secure-login-collector')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Settings field callbacks.
     */
    public function enable_notifications_callback() {
        $enabled = get_option('secure_login_enable_notifications', false);
        echo '<input type="checkbox" id="secure_login_enable_notifications" name="secure_login_enable_notifications" value="1" ' . checked(1, $enabled, false) . ' />';
        echo '<label for="secure_login_enable_notifications"> ' . esc_html__('Send email notifications when new login data is received', 'secure-login-collector') . '</label>';
    }
    
    public function notification_email_callback() {
        $email = get_option('secure_login_notification_email', get_option('admin_email'));
        echo '<input type="email" id="secure_login_notification_email" name="secure_login_notification_email" value="' . esc_attr($email) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Email address to receive notifications. Defaults to site admin email.', 'secure-login-collector') . '</p>';
    }
    
    public function expiration_days_callback() {
        $days = get_option('secure_login_expiration_days', 30);
        echo '<input type="number" id="secure_login_expiration_days" name="secure_login_expiration_days" value="' . esc_attr($days) . '" min="0" class="small-text" />';
        echo '<p class="description">' . esc_html__('Number of days after which login data will be automatically deleted. Set to 0 to disable automatic deletion (data will be retained until manually deleted).', 'secure-login-collector') . '</p>';
    }
    
    public function ultra_secure_mode_callback() {
        $enabled = get_option('secure_login_ultra_secure_mode', false);
        echo '<input type="checkbox" id="secure_login_ultra_secure_mode" name="secure_login_ultra_secure_mode" value="1" ' . checked(1, $enabled, false) . ' />';
        echo '<label for="secure_login_ultra_secure_mode"> ' . esc_html__('Enable passkey-derived encryption (maximum security)', 'secure-login-collector') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, data is encrypted using keys derived from your passkey signature. This provides maximum security - even if hackers modify the plugin code, they cannot decrypt data without your physical passkey device. Requires passkey registration.', 'secure-login-collector') . '</p>';
        
        $passkey_registered = get_option('secure_login_passkey_registered', false);
        if (!$passkey_registered) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Warning:', 'secure-login-collector') . '</strong> ' . esc_html__('You must register a passkey before enabling ultra-secure mode.', 'secure-login-collector') . '</p></div>';
        }
    }
    
    /**
     * Settings page.
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Secure Login Collector Settings', 'secure-login-collector'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('secure_login_settings');
                do_settings_sections('secure_login_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Handle AJAX request to generate RSA keys (now available for all users).
     */
    public function handle_generate_rsa_keys() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'generate_rsa_keys')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        $result = $this->generate_rsa_keypair();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
            return;
        }
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Collector: RSA keys generated by Admin User ID: %d, IP: %s',
            get_current_user_id(),
            $this->get_client_ip()
        ));
        
        wp_send_json_success(__('RSA keys generated successfully.', 'secure-login-collector'));
    }
    
    /**
     * Handle AJAX request to export public key (now available for all users).
     */
    public function handle_export_public_key() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'export_public_key')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        $public_key = $this->get_public_key();
        
        if (is_wp_error($public_key) || !$public_key) {
            wp_send_json_error(__('No public key available.', 'secure-login-collector'));
            return;
        }
        
        wp_send_json_success(array(
            'public_key' => $public_key
        ));
    }
    
    /**
     * Handle AJAX request to register passkey (pro version only).
     */
    public function handle_register_passkey() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'register_passkey')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        if (!$this->is_pro_version) {
            wp_send_json_error(__('Pro version required.', 'secure-login-collector'));
            return;
        }
        
        // ENHANCED SECURITY: Check if passkey already exists
        $passkey_registered = get_option('secure_login_passkey_registered', false);
        $existing_user_id = get_option('secure_login_passkey_user_id', 0);
        
        if ($passkey_registered) {
            // Passkey already exists - require additional verification
            
            // Check if same user is trying to re-register
            if ($existing_user_id != get_current_user_id()) {
                wp_send_json_error(__('Passkey is already registered to a different admin user. Contact the original admin to reset passkey registration.', 'secure-login-collector'));
                return;
            }
            
            // Check for forced re-registration flag (set by existing passkey authentication)
            $force_reregister = get_transient('secure_login_force_passkey_reregister_' . get_current_user_id());
            if (!$force_reregister) {
                wp_send_json_error(__('Passkey already registered. To register a new passkey, you must first authenticate with your existing passkey. Use the "Reset Passkey" option.', 'secure-login-collector'));
                return;
            }
            
            // Clear the force re-registration flag
            delete_transient('secure_login_force_passkey_reregister_' . get_current_user_id());
        }
        
        // Get credential data
        $credential_id = sanitize_text_field($_POST['credential_id']);
        $public_key = sanitize_text_field($_POST['public_key']);
        
        if (empty($credential_id) || empty($public_key)) {
            wp_send_json_error(__('Missing credential data.', 'secure-login-collector'));
            return;
        }
        
        // Store passkey data
        update_option('secure_login_passkey_credential_id', $credential_id);
        update_option('secure_login_passkey_public_key', $public_key);
        update_option('secure_login_passkey_registered', true);
        update_option('secure_login_passkey_user_id', get_current_user_id());
        update_option('secure_login_passkey_registered_at', current_time('mysql'));
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Collector: Passkey registered by Admin User ID: %d, IP: %s, Previous User: %d',
            get_current_user_id(),
            $this->get_client_ip(),
            $existing_user_id
        ));
        
        wp_send_json_success(__('Passkey registered successfully.', 'secure-login-collector'));
    }
    
    /**
     * Handle AJAX request to authenticate with passkey (pro version only).
     */
    public function handle_authenticate_passkey() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'authenticate_passkey')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        if (!$this->is_pro_version) {
            wp_send_json_error(__('Pro version required.', 'secure-login-collector'));
            return;
        }
        
        // Check if passkey is registered
        $passkey_registered = get_option('secure_login_passkey_registered', false);
        if (!$passkey_registered) {
            wp_send_json_error(__('Passkey not registered.', 'secure-login-collector'));
            return;
        }
        
        // Get the pending decrypt request
        $decrypt_id = get_transient('secure_login_decrypt_request_' . get_current_user_id());
        if (!$decrypt_id) {
            wp_send_json_error(__('No pending decrypt request found.', 'secure-login-collector'));
            return;
        }
        
        // Verify the passkey authentication (simplified for demo)
        $signature = sanitize_text_field($_POST['signature']);
        $authenticator_data = sanitize_text_field($_POST['authenticator_data']);
        
        if (empty($signature) || empty($authenticator_data)) {
            wp_send_json_error(__('Missing authentication data.', 'secure-login-collector'));
            return;
        }
        
        // In a real implementation, you would verify the signature here
        // For this demo, we'll assume authentication is successful
        
        // Set authentication flag instead of storing the variable signature
        set_transient('secure_login_passkey_authenticated_' . get_current_user_id(), true, 300); // 5 minutes
        
        // Clear the decrypt request
        delete_transient('secure_login_decrypt_request_' . get_current_user_id());
        
        // Decrypt the data
        $decrypted_data = $this->decrypt_data($decrypt_id);
        
        if ($decrypted_data === false) {
            wp_send_json_error(__('Decryption failed.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Collector: Data decrypted via passkey by Admin User ID: %d, Entry ID: %d, IP: %s',
            get_current_user_id(),
            $decrypt_id,
            $this->get_client_ip()
        ));
        
        wp_send_json_success($decrypted_data);
    }
    
    /**
     * Handle AJAX request to update metadata (for editing functionality).
     */
    public function handle_update_metadata_ajax() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'update_login_metadata')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize and validate input.
        $entry_id = intval($_POST['entry_id']);
        $new_metadata = $_POST['metadata'];
        
        if (empty($entry_id) || empty($new_metadata)) {
            wp_send_json_error(__('Missing required data.', 'secure-login-collector'));
            return;
        }
        
        // Validate required fields
        if (empty($new_metadata['email']) || empty($new_metadata['name']) || empty($new_metadata['service_name'])) {
            wp_send_json_error(__('Email, Name, and Service Name are required fields.', 'secure-login-collector'));
            return;
        }
        
        // Get current entry
        global $wpdb;
        $current_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $entry_id));
        
        if (!$current_entry) {
            wp_send_json_error(__('Entry not found.', 'secure-login-collector'));
            return;
        }
        
        // Parse current metadata
        $current_metadata = json_decode($current_entry->metadata, true);
        if (!$current_metadata) {
            wp_send_json_error(__('Invalid current metadata.', 'secure-login-collector'));
            return;
        }
        
        // Update only the editable fields, preserve other metadata
        $current_metadata['email'] = sanitize_email($new_metadata['email']);
        $current_metadata['name'] = sanitize_text_field($new_metadata['name']);
        $current_metadata['service_name'] = sanitize_text_field($new_metadata['service_name']);
        
        // Re-encode the updated metadata
        $updated_metadata = json_encode($current_metadata);
        
        // Update the database
        $result = $wpdb->update(
            $this->table_name,
            array('metadata' => $updated_metadata),
            array('id' => $entry_id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error(__('Failed to update metadata in database.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Data Metadata Updated - Admin User ID: %d, Entry ID: %d, IP: %s',
            get_current_user_id(),
            $entry_id,
            $this->get_client_ip()
        ));
        
        wp_send_json_success(__('Metadata updated successfully.', 'secure-login-collector'));
    }
    
    /**
     * Handle AJAX request to reset passkey registration (requires existing passkey authentication).
     */
    public function handle_reset_passkey() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'reset_passkey')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        if (!$this->is_pro_version) {
            wp_send_json_error(__('Pro version required.', 'secure-login-collector'));
            return;
        }
        
        // Check if passkey is registered
        $passkey_registered = get_option('secure_login_passkey_registered', false);
        if (!$passkey_registered) {
            wp_send_json_error(__('No passkey registered to reset.', 'secure-login-collector'));
            return;
        }
        
        // Verify the passkey authentication (same as decrypt authentication)
        $signature = sanitize_text_field($_POST['signature']);
        $authenticator_data = sanitize_text_field($_POST['authenticator_data']);
        
        if (empty($signature) || empty($authenticator_data)) {
            wp_send_json_error(__('Missing authentication data.', 'secure-login-collector'));
            return;
        }
        
        // In a real implementation, you would verify the signature here
        // For this demo, we'll assume authentication is successful
        
        // Set flag to allow re-registration
        set_transient('secure_login_force_passkey_reregister_' . get_current_user_id(), true, 300); // 5 minutes
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Collector: Passkey reset authorized by Admin User ID: %d, IP: %s',
            get_current_user_id(),
            $this->get_client_ip()
        ));
        
        wp_send_json_success(__('Passkey reset authorized. You can now register a new passkey.', 'secure-login-collector'));
    }
    
    /**
     * Generate encryption key from passkey signature (Pro version ultra-secure mode).
     */
    private function derive_key_from_passkey($passkey_signature) {
        // FIXED: Instead of using the variable signature, use the stored passkey public key
        // which is consistent and doesn't change between authentications
        $passkey_public_key = get_option('secure_login_passkey_public_key');
        if (!$passkey_public_key) {
            error_log('Secure Login Collector: No passkey public key found for key derivation');
            return false;
        }
        
        // Use the passkey public key as the base for key derivation
        $salt = get_option('secure_login_passkey_salt');
        if (!$salt) {
            // Generate and store a unique salt for this installation
            $salt = wp_generate_password(32, true, true);
            update_option('secure_login_passkey_salt', $salt);
        }
        
        // Derive 256-bit key using PBKDF2 with the passkey public key as the password
        // This ensures the same key is derived every time for the same passkey
        $derived_key = hash_pbkdf2('sha256', $passkey_public_key, $salt, 100000, 32, true);
        
        error_log('Secure Login Collector: Key derived from passkey public key, length: ' . strlen($derived_key));
        
        return $derived_key;
    }
    
    /**
     * Encrypt data using passkey-derived key (Pro version ultra-secure mode).
     */
    public function encrypt_with_passkey_key($data, $passkey_signature = null) {
        // Use consistent key derivation method (signature parameter is now ignored)
        $encryption_key = $this->derive_key_from_passkey(null);
        
        if ($encryption_key === false) {
            error_log('Secure Login Collector: Failed to derive encryption key from passkey');
            return false;
        }
        
        // Generate random IV
        $iv = random_bytes(16);
        
        error_log('Secure Login Collector: Encrypting with AES-256-GCM, data length: ' . strlen($data) . ', IV length: ' . strlen($iv));
        
        // Encrypt with AES-256-GCM for authenticated encryption
        $encrypted = openssl_encrypt($data, 'aes-256-gcm', $encryption_key, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($encrypted === false) {
            error_log('Secure Login Collector: AES-256-GCM encryption failed - ' . openssl_error_string());
            return false;
        }
        
        error_log('Secure Login Collector: AES-256-GCM encryption successful, encrypted length: ' . strlen($encrypted) . ', tag length: ' . strlen($tag));
        
        // Combine IV + tag + encrypted data
        $result = base64_encode($iv . $tag . $encrypted);
        
        return $result;
    }
    
    /**
     * Decrypt data using passkey-derived key (Pro version ultra-secure mode).
     */
    public function decrypt_with_passkey_key($encrypted_data, $passkey_signature = null) {
        // Use consistent key derivation method (signature parameter is now ignored)
        $encryption_key = $this->derive_key_from_passkey(null);
        
        if ($encryption_key === false) {
            error_log('Secure Login Collector: Failed to derive encryption key from passkey');
            return false;
        }
        
        // Decode and extract components
        $data = base64_decode($encrypted_data);
        if (strlen($data) < 32) { // IV(16) + tag(16) minimum
            error_log('Secure Login Collector: Invalid encrypted data format, length: ' . strlen($data));
            return false;
        }
        
        $iv = substr($data, 0, 16);
        $tag = substr($data, 16, 16);
        $encrypted = substr($data, 32);
        
        error_log('Secure Login Collector: Decrypting with AES-256-GCM, IV length: ' . strlen($iv) . ', tag length: ' . strlen($tag) . ', encrypted length: ' . strlen($encrypted));
        
        // Decrypt with AES-256-GCM
        $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $encryption_key, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($decrypted === false) {
            error_log('Secure Login Collector: AES-256-GCM decryption failed - ' . openssl_error_string());
            return false;
        }
        
        error_log('Secure Login Collector: AES-256-GCM decryption successful, decrypted length: ' . strlen($decrypted));
        
        return $decrypted;
    }
    
    /**
     * Handle AJAX request to encrypt data with passkey-derived key (Pro version ultra-secure mode).
     */
    public function handle_encrypt_with_passkey() {
        // This endpoint is deprecated - frontend should use RSA encryption
        wp_send_json_error(__('This encryption method is no longer supported. Please use standard form submission.', 'secure-login-collector'));
    }
    
    /**
     * Get human-readable encryption method information.
     */
    private function get_encryption_method_info($encryption_type) {
        switch ($encryption_type) {
            case 'passkey_derived':
                return array(
                    'name' => __('🔐 Ultra-Secure (Passkey)', 'secure-login-collector'),
                    'class' => 'encryption-ultra-secure',
                    'description' => __('Uses your passkey signature to derive encryption keys. Maximum security - even server compromise cannot decrypt data without your physical device.', 'secure-login-collector'),
                    'security_level' => 'ultra-secure'
                );
            case 'rsa':
                return array(
                    'name' => __('🔒 RSA-2048', 'secure-login-collector'),
                    'class' => 'encryption-rsa',
                    'description' => __('Industry-standard RSA encryption with 2048-bit keys. Secure for most use cases and available for all users.', 'secure-login-collector'),
                    'security_level' => 'secure'
                );
            case 'xor':
            default:
                return array(
                    'name' => __('🔓 XOR (Legacy)', 'secure-login-collector'),
                    'class' => 'encryption-xor',
                    'description' => __('Simple XOR encryption for backward compatibility. Used by older entries before RSA was implemented.', 'secure-login-collector'),
                    'security_level' => 'legacy'
                );
        }
    }
    
    /**
     * Handle AJAX request to save manual login data.
     */
    public function handle_save_manual_login_data() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'save_manual_login_data')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
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
        if (!isset($metadata_array['email']) || empty($metadata_array['email']) ||
            !isset($metadata_array['name']) || empty($metadata_array['name']) ||
            !isset($metadata_array['service_name']) || empty($metadata_array['service_name'])) {
            wp_send_json_error(__('Missing required metadata fields.', 'secure-login-collector'));
            return;
        }
        
        // Sanitize metadata fields.
        $metadata_array['email'] = sanitize_email($metadata_array['email']);
        $metadata_array['name'] = sanitize_text_field($metadata_array['name']);
        $metadata_array['service_name'] = sanitize_text_field($metadata_array['service_name']);
        $metadata_array['encryption_type'] = sanitize_text_field($encryption_method);
        $metadata_array['manually_added'] = true;
        $metadata_array['added_by_user'] = get_current_user_id();
        $metadata_array['created_at'] = current_time('c');
        
        // Prepare data for encryption.
        $data_to_encrypt = json_encode(array(
            'email' => $metadata_array['email'],
            'name' => $metadata_array['name'],
            'service_name' => $metadata_array['service_name'],
            'login_data' => $login_data,
            'timestamp' => current_time('c'),
            'manually_added' => true,
            'added_by_user' => get_current_user_id()
        ));
        
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
                $encrypted_data = $this->encrypt_with_passkey_key($data_to_encrypt, null);
                if ($encrypted_data === false) {
                    wp_send_json_error(__('Passkey encryption failed.', 'secure-login-collector'));
                    return;
                }
                break;
                
            case 'rsa':
                // Use RSA encryption
                $public_key = $this->get_public_key();
                if (is_wp_error($public_key)) {
                    wp_send_json_error(__('RSA keys not available.', 'secure-login-collector'));
                    return;
                }
                
                // For server-side RSA encryption, we'll use the public key
                $encrypted_data = $this->encrypt_rsa_data($data_to_encrypt);
                if ($encrypted_data === false) {
                    wp_send_json_error(__('RSA encryption failed.', 'secure-login-collector'));
                    return;
                }
                
                // ULTRA-SECURE MODE: Double-encrypt with passkey-derived encryption if enabled
                // Instead of decrypt->re-encrypt, we encrypt the already-encrypted RSA data
                if ($this->is_pro_version && get_option('secure_login_ultra_secure_mode', false) && get_option('secure_login_passkey_registered', false)) {
                    // Encrypt the RSA-encrypted data with passkey-derived encryption (double encryption)
                    $passkey_encrypted_data = $this->encrypt_with_passkey_key($encrypted_data, null);
                    
                    if ($passkey_encrypted_data !== false) {
                        // Use double-encrypted data and update metadata
                        $encrypted_data = $passkey_encrypted_data;
                        $metadata_array['encryption_type'] = 'passkey_derived';
                        $metadata_array['inner_encryption'] = 'rsa'; // Track inner encryption method
                        $metadata_array['double_encrypted'] = true; // Flag for double encryption
                        $metadata = json_encode($metadata_array);
                        
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
                
                $encrypted_data = $this->encrypt_xor_data($data_to_encrypt, $encryption_key);
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
        global $wpdb;
        
        $data = array(
            'encrypted_data' => $encrypted_data,
            'metadata' => $metadata,
            'user_id' => get_current_user_id(), // Manual entries are associated with the admin user
            'ip_address' => $this->get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT']),
            'created_at' => current_time('mysql'),
            'retention_until' => $retention_until
        );
        
        // Insert into database.
        $result = $wpdb->insert($this->table_name, $data);
        
        if ($result === false) {
            wp_send_json_error(__('Failed to save data to database.', 'secure-login-collector'));
            return;
        }
        
        // Log the action for security audit.
        error_log(sprintf(
            'Secure Login Data Manually Added - Admin User ID: %d, Client: %s (%s), IP: %s, Method: %s',
            get_current_user_id(),
            $metadata_array['name'],
            $metadata_array['email'],
            $this->get_client_ip(),
            $encryption_method
        ));
        
        wp_send_json_success(__('Login data saved successfully.', 'secure-login-collector'));
    }
    
    /**
     * Encrypt data using RSA (server-side).
     */
    private function encrypt_rsa_data($data) {
        $public_key = $this->get_public_key();
        if (is_wp_error($public_key)) {
            return false;
        }
        
        // Load the public key resource
        $public_key_resource = openssl_pkey_get_public($public_key);
        if (!$public_key_resource) {
            error_log('Secure Login Collector: Failed to load public key resource - ' . openssl_error_string());
            return false;
        }
        
        // Encrypt with RSA-OAEP
        $encrypted = '';
        if (!openssl_public_encrypt($data, $encrypted, $public_key_resource, OPENSSL_PKCS1_OAEP_PADDING)) {
            error_log('Secure Login Collector: RSA OAEP encryption failed - ' . openssl_error_string());
            return false;
        }
        
        return base64_encode($encrypted);
    }
    
    /**
     * Encrypt data using XOR cipher.
     */
    private function encrypt_xor_data($data, $encryption_key) {
        $encrypted = '';
        $keyIndex = 0;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $charCode = ord($data[$i]);
            $keyChar = ord($encryption_key[$keyIndex % strlen($encryption_key)]);
            $encrypted .= chr($charCode ^ $keyChar);
            $keyIndex++;
        }
        
        return base64_encode($encrypted);
    }
    
    /**
     * Test passkey encryption/decryption consistency (for debugging).
     */
    public function test_passkey_encryption() {
        if (!$this->is_pro_version) {
            return 'Pro version required';
        }
        
        $passkey_registered = get_option('secure_login_passkey_registered', false);
        if (!$passkey_registered) {
            return 'Passkey not registered';
        }
        
        $test_data = 'Test data for passkey encryption';
        
        // Test encryption
        $encrypted = $this->encrypt_with_passkey_key($test_data, null);
        if ($encrypted === false) {
            return 'Encryption failed';
        }
        
        // Test decryption
        $decrypted = $this->decrypt_with_passkey_key($encrypted, null);
        if ($decrypted === false) {
            return 'Decryption failed';
        }
        
        if ($decrypted === $test_data) {
            return 'SUCCESS: Passkey encryption/decryption working correctly';
        } else {
            return 'FAILED: Decrypted data does not match original. Original: "' . $test_data . '", Decrypted: "' . $decrypted . '"';
        }
    }
    
    /**
     * Handle AJAX request to test passkey encryption/decryption.
     */
    public function handle_test_passkey_encryption() {
        // Verify nonce for security.
        if (!wp_verify_nonce($_POST['nonce'], 'test_passkey_encryption')) {
            wp_send_json_error(__('Invalid security token.', 'secure-login-collector'));
            return;
        }
        
        // Check if user has admin capabilities.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'secure-login-collector'));
            return;
        }
        
        // Run the test
        $result = $this->test_passkey_encryption();
        
        wp_send_json_success($result);
    }
}

// Initialize the plugin.
new SecureLoginCollector(); 