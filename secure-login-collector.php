<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Plugin file naming convention.
/**
 * Plugin Name: Secure Login Collector
 * Plugin URI: https://wp-mike.com
 * Description: Securely collects and stores encrypted login credentials from clients via frontend form with email notifications.
 * Version: 1.2.3
 * Author: Mike Miler
 * License: GPL v2 or later
 * Text Domain: secure-login-collector
 *
 * @package SecureLoginCollector
 *
 * @phpcs:disable WordPress.Files.FileName.InvalidClassFileName
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'SECULOCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SECULOCO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SECULOCO_VERSION', '1.2.3' );

// Initialize Freemius.
if ( ! function_exists( 'seculoco_fs' ) ) {
	// Check if vendor directory exists with Freemius SDK.
	if ( file_exists( SECULOCO_PLUGIN_DIR . 'vendor/freemius/start.php' ) ) {
		try {
			require_once SECULOCO_PLUGIN_DIR . 'includes/freemius-config.php';
		} catch ( Exception $e ) {
			// Log error but don't break plugin activation.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Secure Login Collector - Freemius Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}

/**
 * Main plugin class - handles initialization and coordination.
 */
class SecureLoginCollector {


	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Encryption handler instance.
	 *
	 * @var Seculoco_Encryption_Handler_V2
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
	 * Constructor - initializes the plugin.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'seculoco_data';

		// Load dependencies.
		$this->load_dependencies();

		// Initialize components.
		$this->init_components();

		// Hook into WordPress.
		add_action( 'init', array( $this, 'init' ) );

		// Plugin activation/deactivation hooks.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Load plugin dependencies.
	 */
	private function load_dependencies() {
		// Always load free version classes.
		include_once SECULOCO_PLUGIN_DIR . 'includes/class-encryption-handler-v2.php';
		include_once SECULOCO_PLUGIN_DIR . 'includes/class-database-manager.php';
		include_once SECULOCO_PLUGIN_DIR . 'includes/class-admin-interface.php';
		include_once SECULOCO_PLUGIN_DIR . 'includes/class-frontend-handler.php';
		include_once SECULOCO_PLUGIN_DIR . 'includes/class-settings-manager.php';

		// Load premium base classes only if available and licensed.
		// These provide pro functionality (passkey management, licensing, etc).
		// Files with __premium_only suffix are automatically removed by Freemius in free version.
		if ( function_exists( 'seculoco_fs' ) && seculoco_fs()->can_use_premium_code() ) {
			$premium_base_files = array(
				'includes/class-passkey-manager__premium_only.php',
				'includes/class-master-key-manager__premium_only.php',
				'includes/class-license-manager__premium_only.php',
			);

			foreach ( $premium_base_files as $file ) {
				if ( file_exists( SECULOCO_PLUGIN_DIR . $file ) ) {
					include_once SECULOCO_PLUGIN_DIR . $file;
				}
			}

			// Load pro extension files (hook into free version via filters/actions).
			// These files extend the free version with pro features.
			$premium_extension_files = array(
				'includes/class-frontend-handler__premium_only.php',
				'includes/class-admin-interface__premium_only.php',
				'includes/class-settings-manager__premium_only.php',
			);

			foreach ( $premium_extension_files as $file ) {
				if ( file_exists( SECULOCO_PLUGIN_DIR . $file ) ) {
					include_once SECULOCO_PLUGIN_DIR . $file;
				}
			}

			// Initialize Passkey Manager globally to register admin hooks.
			if ( class_exists( 'Passkey_Manager' ) ) {
				new Passkey_Manager();
			}
		}

		// Load Freemius hooks if available.
		if ( function_exists( 'seculoco_fs' ) && file_exists( SECULOCO_PLUGIN_DIR . 'includes/freemius-hooks.php' ) ) {
			include_once SECULOCO_PLUGIN_DIR . 'includes/freemius-hooks.php';
		}

		// Load Freemius initialization check.
		if ( file_exists( SECULOCO_PLUGIN_DIR . 'includes/freemius-init-check.php' ) ) {
			include_once SECULOCO_PLUGIN_DIR . 'includes/freemius-init-check.php';
		}

		// Load Freemius account redirect handler.
		if ( file_exists( SECULOCO_PLUGIN_DIR . 'includes/freemius-account-redirect.php' ) ) {
			include_once SECULOCO_PLUGIN_DIR . 'includes/freemius-account-redirect.php';
		}

		// Load Freemius uninstall handler.
		if ( file_exists( SECULOCO_PLUGIN_DIR . 'includes/freemius-uninstall.php' ) ) {
			include_once SECULOCO_PLUGIN_DIR . 'includes/freemius-uninstall.php';
		}
	}

	/**
	 * Initialize plugin components.
	 */
	private function init_components() {
		$this->encryption_handler = new Seculoco_Encryption_Handler_V2();
		$this->database_manager   = new Seculoco_Database_Manager( $this->table_name );
		$this->admin_interface    = new Seculoco_Admin_Interface( $this->table_name, $this->encryption_handler, $this->database_manager );
		$this->frontend_handler   = new Seculoco_Frontend_Handler( $this->table_name, $this->encryption_handler, $this->database_manager );
		$this->settings_manager   = new Seculoco_Settings_Manager( $this->encryption_handler );

		// Allow pro extensions to hook in after components are initialized.
		do_action( 'seculoco_components_initialized', $this );

		// Signal that encryption handler is ready for pro extensions.
		do_action( 'seculoco_encryption_handler_ready', $this->encryption_handler );
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		// Components handle their own initialization.
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		// Create main data table and perform activation tasks.
		$this->database_manager->create_table();
		$this->database_manager->upgrade_database();
		$this->database_manager->schedule_cleanup();

		// Allow pro extensions to run activation tasks.
		// Premium plugin will handle its own table creation (wrapped keys, etc).
		do_action( 'seculoco_activate' );

		// Pro-specific activation: Create master key manager table if pro version active.
		if ( class_exists( 'Master_Key_Manager' ) ) {
			$master_key_manager = new Master_Key_Manager();
			$master_key_manager->maybe_create_table();
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		$this->database_manager->clear_scheduled_cleanup();
	}

	/**
	 * Get client IP address.
	 */
	public static function get_client_ip() {
		$ip_keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) as $ip ) {
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
						return $ip;
					}
				}
			}
		}

		$remote_addr = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_addr = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return ! empty( $remote_addr ) ? $remote_addr : '0.0.0.0';
	}
}

// Initialize the plugin.
new SecureLoginCollector();
