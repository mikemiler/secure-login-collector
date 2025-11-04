<?php
/**
 * Upgrade Handler - Manages migration from free to pro version
 *
 * This class handles the transition when users upgrade from the free version
 * to the pro version of the plugin. It migrates settings, data, and deactivates
 * the free version automatically.
 *
 * @package SecureLoginCollector
 * @since 1.3.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles upgrade from free to pro version.
 */
class Seculoco_Upgrade_Handler {

	/**
	 * Free version plugin path.
	 *
	 * @var string
	 */
	const FREE_PLUGIN_PATH = 'secure-login-collector/secure-login-collector.php';

	/**
	 * Pro version plugin path.
	 *
	 * @var string
	 */
	const PRO_PLUGIN_PATH = 'secure-login-collector-pro/secure-login-collector.php';

	/**
	 * Handle upgrade from free to pro version.
	 *
	 * This function is called during pro version activation. It:
	 * 1. Detects if free version is active
	 * 2. Deactivates free version
	 * 3. Sets pro version flag (for uninstall protection)
	 * 4. Shows success notice
	 *
	 * Note: No data migration needed - Free and Pro use the same database tables and options!
	 *
	 * @return bool True if upgrade was handled, false otherwise.
	 */
	public static function handle_free_to_pro_migration() {
		// Check if upgrade already completed.
		if ( get_option( 'seculoco_upgrade_completed', false ) ) {
			return false;
		}

		// Check if free version is active.
		if ( ! self::is_free_version_active() ) {
			return false;
		}

		// Deactivate free version (Pro replaces it).
		self::deactivate_free_version();

		// Set flag that Pro version is now active.
		// This protects data if user later deletes Free plugin.
		update_option( 'seculoco_using_pro_version', true );

		// Mark upgrade as completed.
		update_option( 'seculoco_upgrade_completed', true );

		// Show success notice to admin.
		set_transient( 'seculoco_migration_success', true, 60 );

		return true;
	}

	/**
	 * Check if free version is active.
	 *
	 * @return bool True if free version is active.
	 */
	private static function is_free_version_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check both possible free version paths.
		$free_paths = array(
			self::FREE_PLUGIN_PATH,
			'secure-login-collector-free/secure-login-collector.php',
		);

		foreach ( $free_paths as $path ) {
			if ( is_plugin_active( $path ) ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Deactivate free version plugin.
	 *
	 * This safely deactivates the free version without losing data.
	 */
	private static function deactivate_free_version() {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Try to deactivate all possible free version paths.
		$free_paths = array(
			self::FREE_PLUGIN_PATH,
			'secure-login-collector-free/secure-login-collector.php',
		);

		foreach ( $free_paths as $path ) {
			if ( is_plugin_active( $path ) ) {
				deactivate_plugins( $path, true ); // true = silent deactivation.
			}
		}
	}

	/**
	 * Reset upgrade status (for testing purposes).
	 *
	 * This should only be used during development/testing.
	 */
	public static function reset_upgrade_status() {
		delete_option( 'seculoco_upgrade_completed' );
		delete_option( 'seculoco_using_pro_version' );
		delete_transient( 'seculoco_migration_success' );
		delete_transient( 'seculoco_migration_warning' );
	}

	/**
	 * Check if we're running pro version.
	 *
	 * @return bool True if pro version is active.
	 */
	public static function is_pro_version() {
		// Check Freemius first.
		if ( function_exists( 'seculoco_fs' ) && seculoco_fs()->can_use_premium_code() ) {
			return true;
		}

		// Fallback to option.
		return (bool) get_option( 'seculoco_using_pro_version', false );
	}

	/**
	 * Get upgrade status information.
	 *
	 * @return array Status information.
	 */
	public static function get_upgrade_status() {
		return array(
			'upgrade_completed'   => (bool) get_option( 'seculoco_upgrade_completed', false ),
			'using_pro_version'   => self::is_pro_version(),
			'db_version'          => get_option( 'seculoco_db_version', 'unknown' ),
			'free_version_active' => self::is_free_version_active(),
		);
	}
}
