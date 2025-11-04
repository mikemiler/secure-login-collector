<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Plugin file naming convention.
/**
 * Plugin Name: Secure Login Collector
 * Plugin URI: https://wp-mike.com
 * Description: Securely collects and stores encrypted login credentials from clients via frontend form with email notifications.
 * Version: 1.2.10
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

// ============================================
// GUARD 1: Prevent dual loading
// ============================================
// If another version is already loaded, show notice and stop execution.
if ( defined( 'SECULOCO_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>' . esc_html__( 'Secure Login Collector:', 'secure-login-collector' ) . '</strong> ';
			echo esc_html__( 'Multiple versions detected. Please deactivate one version.', 'secure-login-collector' );
			echo ' ' . esc_html__( 'Active version:', 'secure-login-collector' ) . ' ' . esc_html( SECULOCO_VERSION );
			echo '</p></div>';
		}
	);
	return; // Stop execution.
}

define( 'SECULOCO_VERSION', '1.2.10' );

// Define plugin constants with guards.
if ( ! defined( 'SECULOCO_PLUGIN_DIR' ) ) {
	define( 'SECULOCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SECULOCO_PLUGIN_URL' ) ) {
	define( 'SECULOCO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

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

// ============================================
// GUARD 2: Check for class conflicts
// ============================================
if ( class_exists( 'SecureLoginCollector' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>' . esc_html__( 'Secure Login Collector:', 'secure-login-collector' ) . '</strong> ';
			echo esc_html__( 'Class conflict detected. Another plugin may be using the same class name.', 'secure-login-collector' );
			echo '</p></div>';
		}
	);
	return;
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

		// Add upgrade notices.
		add_action( 'admin_notices', array( $this, 'show_upgrade_notices' ) );
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
		include_once SECULOCO_PLUGIN_DIR . 'includes/class-spam-protection.php';
		include_once SECULOCO_PLUGIN_DIR . 'includes/class-settings-manager.php';

		// Load premium base classes only if available and licensed.
		// These provide pro functionality (passkey management, licensing, etc).
		// Files with __premium_only suffix are automatically removed by Freemius in free version.
		// For local testing: Add define( 'SECULOCO_SIMULATE_FREE_VERSION', true ); to wp-config.php
		$can_load_premium = function_exists( 'seculoco_fs' )
			&& seculoco_fs()->can_use_premium_code()
			&& ! defined( 'SECULOCO_SIMULATE_FREE_VERSION' );

		if ( $can_load_premium ) {
			$premium_base_files = array(
				'includes/class-passkey-manager__premium_only.php',
				'includes/class-master-key-manager__premium_only.php',
				'includes/class-license-manager__premium_only.php',
				'includes/class-spam-protection__premium_only.php',
			);

			foreach ( $premium_base_files as $file ) {
				if ( file_exists( SECULOCO_PLUGIN_DIR . $file ) ) {
					include_once SECULOCO_PLUGIN_DIR . $file;
				}
			}

			// Load pro extension files (hook into free version via filters/actions).
			// These files extend the free version with pro features.
			$premium_extension_files = array(
				'includes/class-encryption-handler-v2__premium_only.php',
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
			if ( class_exists( 'Seculoco_Passkey_Manager' ) ) {
				new Seculoco_Passkey_Manager();
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

		// Load Freemius uninstall handler.
		if ( file_exists( SECULOCO_PLUGIN_DIR . 'includes/freemius-uninstall.php' ) ) {
			include_once SECULOCO_PLUGIN_DIR . 'includes/freemius-uninstall.php';
		}

		// Load upgrade handler for migration logic (Pro version only).
		// This file has __premium_only suffix and will be automatically removed in free version.
		if ( $can_load_premium && file_exists( SECULOCO_PLUGIN_DIR . 'includes/class-upgrade-handler__premium_only.php' ) ) {
			include_once SECULOCO_PLUGIN_DIR . 'includes/class-upgrade-handler__premium_only.php';
		}
	}

	/**
	 * Initialize plugin components.
	 */
	private function init_components() {
		// Initialize encryption handler - use premium class if available.
		if ( class_exists( 'Seculoco_Encryption_Handler_V2_Premium' ) ) {
			$this->encryption_handler = new Seculoco_Encryption_Handler_V2_Premium();
		} else {
			$this->encryption_handler = new Seculoco_Encryption_Handler_V2();
		}
		$this->database_manager   = new Seculoco_Database_Manager( $this->table_name );

		// Initialize spam protection (honeypot and bot detection).
		$this->spam_protection = new Seculoco_Spam_Protection();

		// Initialize premium spam protection (rate limiting and advanced detection) for pro users only.
		if ( class_exists( 'Seculoco_Spam_Protection_Premium' ) ) {
			$this->spam_protection_premium = new Seculoco_Spam_Protection_Premium();
		}

		// Initialize admin interface - use premium class if available.
		if ( class_exists( 'Seculoco_Admin_Interface_Premium' ) ) {
			$this->admin_interface = new Seculoco_Admin_Interface_Premium( $this->table_name, $this->encryption_handler, $this->database_manager );
		} else {
			$this->admin_interface = new Seculoco_Admin_Interface( $this->table_name, $this->encryption_handler, $this->database_manager );
		}

		$this->frontend_handler   = new Seculoco_Frontend_Handler( $this->table_name, $this->encryption_handler, $this->database_manager, $this->spam_protection );
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
		// Handle upgrade from free version if it exists (Pro version only).
		// The upgrade handler class is only loaded in Pro version (__premium_only file).
		if ( class_exists( 'Seculoco_Upgrade_Handler' ) ) {
			Seculoco_Upgrade_Handler::handle_free_to_pro_migration();
		}

		// Create or update main data table.
		// Uses dbDelta() which is idempotent - safe if table already exists.
		$this->database_manager->create_table();
		$this->database_manager->schedule_cleanup();

		// Store database version for future schema migrations.
		update_option( 'seculoco_db_version', SECULOCO_VERSION );

		// Allow pro extensions to run activation tasks.
		// Premium plugin will handle its own table creation (wrapped keys, etc).
		do_action( 'seculoco_activate' );
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		$this->database_manager->clear_scheduled_cleanup();
	}

	/**
	 * Show upgrade success notices.
	 */
	public function show_upgrade_notices() {
		// Check if we just upgraded from free version.
		$upgrade_success = get_transient( 'seculoco_migration_success' );
		if ( $upgrade_success ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Secure Login Collector Pro activated successfully!', 'secure-login-collector' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The free version has been deactivated automatically.', 'secure-login-collector' ); ?>
					<?php esc_html_e( 'All your data and settings are preserved.', 'secure-login-collector' ); ?>
				</p>
				<p>
					<em><?php esc_html_e( 'You can now safely delete the free version plugin if desired.', 'secure-login-collector' ); ?></em>
				</p>
			</div>
			<?php
			delete_transient( 'seculoco_migration_success' );
		}
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

// ============================================
// GUARD 3: Prevent duplicate instantiation
// ============================================
if ( ! isset( $GLOBALS['seculoco_instance'] ) ) {
	// Initialize the plugin.
	$GLOBALS['seculoco_instance'] = new SecureLoginCollector();
}
