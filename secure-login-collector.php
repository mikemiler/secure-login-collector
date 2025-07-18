<?php

/**
 * Plugin Name: Secure Login Collector
 * Plugin URI: https://wp-mike.com
 * Description: Securely collects and stores encrypted login credentials from clients via frontend form with email notifications.
 * Version: 2.5.2
 * Author: Mike Miler
 * License: GPL v2 or later
 * Text Domain: secure-login-collector
 *
 * @package SecureLoginCollector
 *
 * Pro Version Activation:
 * To enable pro version features, add this line to your wp-config.php:
 * define('SECURE_LOGIN_PRO', true);
 */

// Prevent direct access.
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('SECURE_LOGIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SECURE_LOGIN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SECURE_LOGIN_VERSION', '2.5.2');

// Initialize Freemius.
if (! function_exists('slc_fs')) {
    // Check if vendor directory exists with Freemius SDK
    if (file_exists(SECURE_LOGIN_PLUGIN_DIR . 'vendor/freemius/start.php')) {
        try {
            require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/freemius-config.php';
        } catch (Exception $e) {
            // Log error but don't break plugin activation
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Secure Login Collector - Freemius Error: ' . $e->getMessage());
            }
        }
    }
}

/**
 * Main plugin class - handles initialization and coordination.
 */
class SecureLoginCollector
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
     * Admin interface instance.
     *
     * @var Secure_Login_Admin_Interface
     */
    private $admin_interface;

    /**
     * Frontend handler instance.
     *
     * @var Secure_Login_Frontend_Handler
     */
    private $frontend_handler;

    /**
     * Settings manager instance.
     *
     * @var Secure_Login_Settings_Manager
     */
    private $settings_manager;

    /**
     * Database manager instance.
     *
     * @var Secure_Login_Database_Manager
     */
    private $database_manager;

    /**
     * Constructor - initializes the plugin.
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name     = $wpdb->prefix . 'secure_login_data';
        $this->is_pro_version = $this->check_pro_version();

        // Load dependencies.
        $this->load_dependencies();

        // Initialize components.
        $this->init_components();

        // Load plugin text domain for translations.
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Hook into WordPress.
        add_action('init', array($this, 'init'));

        // Plugin activation/deactivation hooks.
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Load plugin dependencies.
     */
    private function load_dependencies()
    {
        include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-encryption-handler.php';
        include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-admin-interface.php';
        include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-frontend-handler.php';
        include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-settings-manager.php';
        include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-database-manager.php';

        // Load Freemius hooks if available
        if (function_exists('slc_fs') && file_exists(SECURE_LOGIN_PLUGIN_DIR . 'includes/freemius-hooks.php')) {
            include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/freemius-hooks.php';
        }

        // Load Freemius initialization check
        if (file_exists(SECURE_LOGIN_PLUGIN_DIR . 'includes/freemius-init-check.php')) {
            include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/freemius-init-check.php';
        }

        // Load Freemius account redirect handler
        if (file_exists(SECURE_LOGIN_PLUGIN_DIR . 'includes/freemius-account-redirect.php')) {
            include_once SECURE_LOGIN_PLUGIN_DIR . 'includes/freemius-account-redirect.php';
        }
        
    }

    /**
     * Initialize plugin components.
     */
    private function init_components()
    {
        $this->encryption_handler = new Secure_Login_Encryption_Handler($this->is_pro_version);
        $this->database_manager   = new Secure_Login_Database_Manager($this->table_name);
        $this->admin_interface    = new Secure_Login_Admin_Interface($this->table_name, $this->is_pro_version, $this->encryption_handler, $this->database_manager);
        $this->frontend_handler   = new Secure_Login_Frontend_Handler($this->table_name, $this->is_pro_version, $this->encryption_handler, $this->database_manager);
        $this->settings_manager   = new Secure_Login_Settings_Manager($this->is_pro_version, $this->encryption_handler);
    }

    /**
     * Load plugin text domain for translations.
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('secure-login-collector', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Check if pro version is available.
     */
    private function check_pro_version()
    {
        // First check if Freemius is loaded and user has active license
        if (function_exists('slc_fs')) {
            try {
                $fs = slc_fs();
                if ($fs && is_object($fs) && method_exists($fs, 'is_paying') && $fs->is_paying()) {
                    return true;
                }
            } catch (Exception $e) {
                // Freemius error - fall through to constant check
            }
        }

        // Fallback to constant check for development/testing
        return defined('SECURE_LOGIN_PRO') && SECURE_LOGIN_PRO === true;
    }

    /**
     * Initialize the plugin.
     */
    public function init()
    {
        // Components handle their own initialization.
    }

    /**
     * Plugin activation.
     */
    public function activate()
    {
        $this->database_manager->create_table();
        $this->database_manager->upgrade_database();
        $this->encryption_handler->ensure_rsa_keys();
        $this->database_manager->schedule_cleanup();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate()
    {
        $this->database_manager->clear_scheduled_cleanup();
    }

    /**
     * Get client IP address.
     */
    public static function get_client_ip()
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

        $remote_addr = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $remote_addr = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return ! empty($remote_addr) ? $remote_addr : '0.0.0.0';
    }
}

// Initialize the plugin.
new SecureLoginCollector();
