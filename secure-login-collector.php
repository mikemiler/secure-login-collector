<?php
/**
 * Plugin Name: Secure Login Collector
 * Plugin URI: https://wp-mike.com
 * Description: Securely collects and stores encrypted login credentials from clients via frontend form with email notifications.
 * Version: 1.4.10
 * Author: Mike Miler
 * License: GPL v2 or later
 * Text Domain: secure-login-collector
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if (! defined('ABSPATH') ) {
    exit;
}

// ============================================
// GUARD 1: Prevent dual loading
// ============================================
// If another version is already loaded, show notice and stop execution.
if (defined('SECULOCO_VERSION') ) {
    add_action(
        'admin_notices',
        function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Secure Login Collector:', 'secure-login-collector') . '</strong> ';
            echo esc_html__('Multiple versions detected. Please deactivate one version.', 'secure-login-collector');
            echo ' ' . esc_html__('Active version:', 'secure-login-collector') . ' ' . esc_html(SECULOCO_VERSION);
            echo '</p></div>';
        }
    );
    return; // Stop execution.
}

define('SECULOCO_VERSION', '1.4.10');

// Define plugin constants with guards.
if (! defined('SECULOCO_PLUGIN_DIR') ) {
    define('SECULOCO_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (! defined('SECULOCO_PLUGIN_URL') ) {
    define('SECULOCO_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once SECULOCO_PLUGIN_DIR . 'includes/class-loader.php';
Seculoco_Loader::load();


// Initialize Freemius.
if (! function_exists('seculoco_fs') ) {
    // Check if vendor directory exists with Freemius SDK.
    if (file_exists(SECULOCO_PLUGIN_DIR . 'vendor/freemius/start.php') ) {
        try {
            include_once SECULOCO_PLUGIN_DIR . 'includes/freemius-config.php';
        } catch ( Exception $e ) {
            // Log error but don't break plugin activation.
            if (defined('WP_DEBUG') && WP_DEBUG ) {
                error_log('Secure Login Collector - Freemius Error: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
     * Encryption handler instance.
     *
     * @var Seculoco_Encryption_Service
     */
    private $encryption_handler;

    /**
     * Admin interface instance.
     *
     * @var Seculoco_Admin_Interface
     */
    private $admin_interface;

    /**
     * Frontend handler instance.
     *
     * @var Seculoco_Frontend_Handler
     */
    private $frontend_handler;

    /**
     * Settings manager instance.
     *
     * @var Seculoco_Settings_Manager
     */
    private $settings_manager;

    /**
     * Database manager instance.
     *
     * @var Seculoco_Database_Manager
     */
    private $database_manager;

    /**
     * Spam protection instance (includes honeypot and bot detection).
     *
     * @var Seculoco_Spam_Protection
     */
    private $spam_protection;

    /**
     * Premium spam protection instance (includes rate limiting and advanced detection).
     * Only available for pro users. Extends base spam protection functionality.
     *
     * @var Seculoco_Spam_Protection_Premium|null
     */
    private $spam_protection_premium;

    /**
     * Flag indicating whether any encryption keys are ready (password or passkey).
     *
     * @var bool
     */
    private $encryption_ready = false;

    /**
     * Constructor - initializes the plugin.
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'seculoco_data';

        // Initialize components.
        $this->init_components();

		$this->encryption_handler = Seculoco_Encryption_Handler_Factory::get_shared_handler();

        // Hook into WordPress.
        add_action('init', array( $this, 'init' ));

        // Plugin activation/deactivation hooks.
        register_activation_hook(__FILE__, array( $this, 'activate' ));
        register_deactivation_hook(__FILE__, array( $this, 'deactivate' ));

        // Add upgrade notices.
        add_action('admin_notices', array( $this, 'show_upgrade_notices' ));
        add_action('admin_notices', array( $this, 'maybe_show_encryption_notice' ), 5);

        // Handle free plugin deletion (Pro version only).
        add_action('admin_post_seculoco_delete_free_plugin', array( $this, 'handle_delete_free_plugin' ));
    }

    /**
     * Initialize plugin components.
     */
    private function init_components()
    {
        // Initialize encryption handler - use premium class if available.
        $this->encryption_handler = Seculoco_Encryption_Handler_Factory::get_shared_handler();
        $this->database_manager   = new Seculoco_Database_Manager($this->table_name);

        // Initialize spam protection (honeypot and bot detection).
        $this->spam_protection = new Seculoco_Spam_Protection();

        // Initialize premium spam protection (rate limiting and advanced detection) for pro users only.
        if (class_exists('Seculoco_Spam_Protection_Premium') ) {
            $this->spam_protection_premium = new Seculoco_Spam_Protection_Premium();
        }

        // Initialize admin interface - use premium class if available.
        if (class_exists('Seculoco_Admin_Interface_Premium') ) {
            $this->admin_interface = new Seculoco_Admin_Interface_Premium($this->table_name, $this->encryption_handler, $this->database_manager);
        } else {
            $this->admin_interface = new Seculoco_Admin_Interface($this->table_name, $this->encryption_handler, $this->database_manager);
        }

        $this->frontend_handler   = new Seculoco_Frontend_Handler($this->table_name, $this->encryption_handler, $this->database_manager, $this->spam_protection);
        $this->settings_manager   = new Seculoco_Settings_Manager($this->encryption_handler);

        // Allow pro extensions to hook in after components are initialized.
        do_action('seculoco_components_initialized', $this);

        // Signal that encryption handler is ready for pro extensions.
        do_action('seculoco_encryption_handler_ready', $this->encryption_handler);

        if (function_exists('seculoco_is_encryption_initialized') ) {
            $this->encryption_ready = seculoco_is_encryption_initialized();
        } else {
            $this->encryption_ready = $this->has_password_keys() || $this->has_passkey_keys();
        }
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
        // Handle upgrade from free version if it exists (Pro version only).
        // The upgrade handler class is only loaded in Pro version (__premium_only file).
        if (class_exists('Seculoco_Upgrade_Handler') ) {
            Seculoco_Upgrade_Handler::handle_free_to_pro_migration();
        }

        // Create or update main data table.
        // Uses dbDelta() which is idempotent - safe if table already exists.
        $this->database_manager->create_table();
        $this->database_manager->schedule_cleanup();

        // Store database version for future schema migrations.
        update_option(SECULOCO_OPTION_DB_VERSION, SECULOCO_VERSION);

        // Allow pro extensions to run activation tasks.
        // Premium plugin will handle its own table creation (wrapped keys, etc).
        do_action('seculoco_activate');
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate()
    {
        $this->database_manager->clear_scheduled_cleanup();
    }

    /**
     * Show upgrade success notices.
     */
    public function show_upgrade_notices()
    {
        // Check if we just upgraded from free version.
        $upgrade_success = get_transient('seculoco_migration_success');
        if ($upgrade_success ) {
            // Check if free plugin still exists.
            $free_plugin_exists = $this->free_plugin_directory_exists();

            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('Secure Login Collector Pro activated successfully!', 'secure-login-collector'); ?></strong>
                </p>
                <p>
            <?php esc_html_e('The free version has been deactivated automatically.', 'secure-login-collector'); ?>
            <?php esc_html_e('All your data and settings are preserved.', 'secure-login-collector'); ?>
                </p>
            <?php if ($free_plugin_exists ) : ?>
                    <p>
                        <strong><?php esc_html_e('Clean up:', 'secure-login-collector'); ?></strong>
                <?php esc_html_e('The free version plugin is no longer needed.', 'secure-login-collector'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete the free version plugin? The Pro version will continue working normally.', 'secure-login-collector')); ?>');">
                <?php wp_nonce_field('seculoco_delete_free_plugin', 'seculoco_delete_nonce'); ?>
                        <input type="hidden" name="action" value="seculoco_delete_free_plugin">
                        <button type="submit" class="button button-primary">
                <?php esc_html_e('Delete Free Plugin', 'secure-login-collector'); ?>
                        </button>
                        <span style="margin-left: 10px; color: #666;">
                <?php esc_html_e('(Recommended - keeps your plugins list clean)', 'secure-login-collector'); ?>
                        </span>
                    </form>
            <?php endif; ?>
            </div>
            <?php
            delete_transient('seculoco_migration_success');
        }

        // Check if free plugin was just deleted.
        $delete_success = get_transient('seculoco_free_deleted');
        if ($delete_success ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('Free plugin deleted successfully!', 'secure-login-collector'); ?></strong>
                </p>
                <p><?php esc_html_e('Your Pro version continues working normally with all data intact.', 'secure-login-collector'); ?></p>
            </div>
            <?php
            delete_transient('seculoco_free_deleted');
        }

        // Check if deletion failed.
        $delete_error = get_transient('seculoco_free_delete_error');
        if ($delete_error ) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php esc_html_e('Could not delete free plugin:', 'secure-login-collector'); ?></strong>
                </p>
                <p><?php echo esc_html($delete_error); ?></p>
                <p>
                    <em><?php esc_html_e('You can manually delete it from the Plugins page if desired.', 'secure-login-collector'); ?></em>
                </p>
            </div>
            <?php
            delete_transient('seculoco_free_delete_error');
        }
    }

    /**
     * Show setup notice when no encryption keys exist.
     */
    public function maybe_show_encryption_notice()
    {
        if ($this->encryption_ready || ! is_admin() || ! current_user_can('manage_options') ) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || strpos($screen->id, 'secure-login-collector') === false ) {
            return;
        }

        $show_passkey_button = class_exists('Seculoco_Passkey_Manager');
        ?>
        <div class="notice notice-warning seculoco-setup-notice">
            <p>
                <strong><?php esc_html_e('Encryption setup required:', 'secure-login-collector'); ?></strong>
        <?php esc_html_e('Finish configuring your password or passkey encryption before collecting client credentials.', 'secure-login-collector'); ?>
            </p>
            <p>
                <button type="button" class="seculoco-btn seculoco-btn-primary seculoco-password-setup-btn">
                    <span>🔐</span> <?php esc_html_e('Setup Password Encryption', 'secure-login-collector'); ?>
                </button>
        <?php if ($show_passkey_button ) : ?>
                    <button type="button" class="seculoco-btn seculoco-btn-secondary seculoco-passkey-register-btn" data-authenticator-type="cross-platform">
                        <span>🔑</span> <?php esc_html_e('Register Passkey', 'secure-login-collector'); ?>
                    </button>
        <?php endif; ?>
            </p>
        <?php if ($show_passkey_button ) : ?>
                <div class="seculoco-passkey-status"></div>
        <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Determine if password-based keys are configured.
     *
     * @return bool
     */
    private function has_password_keys()
    {
        if (function_exists('seculoco_has_password_encryption') ) {
            return seculoco_has_password_encryption();
        }

        return (bool) get_option(SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE, false);
    }

    /**
     * Determine if passkey-based keys are configured.
     *
     * @return bool
     */
    private function has_passkey_keys()
    {
        if (function_exists('seculoco_has_passkey_encryption') ) {
            return seculoco_has_passkey_encryption();
        }

        return (bool) ( get_option(SECULOCO_OPTION_PRO_KEYS_ACTIVE, false) && get_option(SECULOCO_OPTION_PASSKEY_REGISTERED, false) );
    }

    /**
     * Check if free plugin directory exists.
     *
     * @return bool True if free plugin directory exists.
     */
    private function free_plugin_directory_exists()
    {
        $free_paths = array(
        WP_PLUGIN_DIR . '/secure-login-collector',
        WP_PLUGIN_DIR . '/secure-login-collector-free',
        );

        foreach ( $free_paths as $path ) {
            if (is_dir($path) && $path !== SECULOCO_PLUGIN_DIR ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle free plugin deletion request.
     * This is Pro version only functionality.
     */
    public function handle_delete_free_plugin()
    {
        // Security checks.
        if (! current_user_can('delete_plugins') ) {
            wp_die(esc_html__('You do not have permission to delete plugins.', 'secure-login-collector'));
        }

        // Verify nonce.
        if (! isset($_POST['seculoco_delete_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seculoco_delete_nonce'])), 'seculoco_delete_free_plugin') ) {
            wp_die(esc_html__('Security check failed.', 'secure-login-collector'));
        }

        // Find and delete free plugin directory.
        $free_paths = array(
        WP_PLUGIN_DIR . '/secure-login-collector',
        WP_PLUGIN_DIR . '/secure-login-collector-free',
        );

        $deleted = false;
        foreach ( $free_paths as $free_path ) {
            // Make sure we're not deleting the Pro version!
            if ($free_path === SECULOCO_PLUGIN_DIR ) {
                continue;
            }

            if (is_dir($free_path) ) {
                // Use WordPress filesystem API for safe deletion.
                include_once ABSPATH . 'wp-admin/includes/file.php';

                global $wp_filesystem;
                if (! WP_Filesystem() ) {
                    set_transient('seculoco_free_delete_error', __('Could not initialize WordPress filesystem.', 'secure-login-collector'), 60);
                    wp_safe_redirect(admin_url('plugins.php'));
                    exit;
                }

                // Delete the directory.
                $result = $wp_filesystem->delete($free_path, true);

                if ($result ) {
                    $deleted = true;
                    set_transient('seculoco_free_deleted', true, 60);
                } else {
                    set_transient('seculoco_free_delete_error', __('File system error during deletion.', 'secure-login-collector'), 60);
                }
                break;
            }
        }

        if (! $deleted ) {
            set_transient('seculoco_free_delete_error', __('Free plugin directory not found.', 'secure-login-collector'), 60);
        }

        // Redirect back to plugins page.
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    /**
     * Get client IP address.
     */
    public static function get_client_ip()
    {
        $ip_keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

        foreach ( $ip_keys as $key ) {
            if (array_key_exists($key, $_SERVER) === true ) {
                foreach ( explode(',', sanitize_text_field(wp_unslash($_SERVER[ $key ]))) as $ip ) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ) {
                        return $ip;
                    }
                }
            }
        }

        $remote_addr = '';
        if (isset($_SERVER['REMOTE_ADDR']) ) {
            $remote_addr = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return ! empty($remote_addr) ? $remote_addr : '0.0.0.0';
    }
}

/**
 * Initialize the plugin
 *
 * This function instantiates the main plugin class after Freemius has loaded.
 * It ensures proper initialization order and prevents race conditions.
 *
 * @return SecureLoginCollector The plugin instance
 */
if(!function_exists('seculoco_init')) {
    function seculoco_init()
    {
        static $instance = null;

        if (null === $instance ) {
            $instance = new SecureLoginCollector();
        }

        return $instance;
    }
}

register_uninstall_hook(__FILE__, 'seculoco_wp_uninstall_cleanup');

/**
 * Boot plugin after WordPress finishes loading all plugins.
 *
 * We wait for `plugins_loaded` so the premium loader has already fired and
 * registered any __premium_only classes before the factory wiring runs.
 */
add_action(
    'plugins_loaded',
    function () {
        seculoco_init();
    },
    20
);
