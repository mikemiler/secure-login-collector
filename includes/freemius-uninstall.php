<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Freemius Uninstall Handler
 *
 * This file contains the uninstall cleanup function that integrates with Freemius.
 * It's called via Freemius's 'after_uninstall' action hook.
 *
 * @package SecureLoginCollector
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'seculoco_get_constant_value' ) ) {
	/**
	 * Helper to safely fetch constant values.
	 *
	 * @param string $constant_name Constant identifier.
	 *
	 * @return string|null
	 */
	function seculoco_get_constant_value( $constant_name ) {
		return defined( $constant_name ) ? constant( $constant_name ) : null;
	}
}

/**
 * Freemius uninstall cleanup function
 *
 * This function is called by Freemius after the plugin is uninstalled.
 * It handles cleanup of all plugin data if the user has enabled the cleanup option.
 *
 * @since 1.0.1
 * @return void
 */
/**
 * Core uninstall cleanup routine (idempotent).
 *
 * @return void
 */
function seculoco_run_uninstall_cleanup() {
	static $has_run = false;

	if ( $has_run ) {
		return;
	}

	$has_run = true;

	/**
	 * Fires before the uninstall cleanup routine runs.
	 *
	 * Allows premium add-ons to bootstrap anything needed for uninstall (e.g. constants).
	 *
	 * @since 1.3.0
	 */
	do_action( 'seculoco_before_uninstall_cleanup' );

	try {
		// ============================================
		// CRITICAL: Check if Pro version is active
		// ============================================
		// If user upgraded to Pro and is now deleting Free plugin,
		// we must NOT delete data as Pro version still needs it!
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check for active Pro version.
		$pro_plugin_paths = array(
			'secure-login-collector-pro/secure-login-collector.php',
			'secure-login-collector-pro/secure-login-collector-pro.php',
		);

		foreach ( $pro_plugin_paths as $pro_path ) {
			if ( is_plugin_active( $pro_path ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
					error_log( 'Secure Login Collector: Pro version active, preserving data during Free plugin uninstall.' );
				}
				return; // Exit without deleting - Pro version needs this data!
			}
		}

		// Also check if we're flagged as using pro version.
		if ( get_option( SECULOCO_OPTION_USING_PRO_VERSION, false ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				error_log( 'Secure Login Collector: Pro version flag detected, preserving data.' );
			}
			return;
		}

		// Check if user has opted to delete all data on uninstall.
		$delete_on_uninstall = get_option( SECULOCO_OPTION_DELETE_ON_UNINSTALL, false );

		if ( ! $delete_on_uninstall ) {
			// User has chosen to keep data, so exit without deleting anything.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				error_log( 'Secure Login Collector: User opted to keep data on uninstall.' );
			}
			return;
		}

		global $wpdb;

		// 1. Delete the custom database table.
		$table_name = $wpdb->prefix . 'seculoco_data';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' );

		if ( false === $result ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				error_log( 'Secure Login Collector: Failed to drop table ' . $table_name );
			}
		}

		// 2. Delete all plugin options.
		/**
		 * Filters the option names scheduled for deletion during uninstall.
		 *
		 * @since 1.3.0
		 *
		 * @param array $option_names Option identifiers slated for deletion.
		 */
		$plugin_options = apply_filters(
			'seculoco_uninstall_option_names',
			array_filter(
				array(
					// Settings - using constants.
					seculoco_get_constant_value( 'SECULOCO_OPTION_NOTIFICATION_EMAIL' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_ENABLE_NOTIFICATIONS' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_EXPIRATION_DAYS' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_FRONTEND_FORM_TEXT' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_FRONTEND_TEXT_TYPE' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_SPAM_SETTINGS' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_HONEYPOT_LOG' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_DELETE_ON_UNINSTALL' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_HONEYPOT_ENABLED' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_HONEYPOT_MIN_TIME' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_SPAM_SETTINGS' ),

					// Encryption keys and related data - using constants.
					seculoco_get_constant_value( 'SECULOCO_OPTION_PASSWORD_ACTIVE' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PUBLIC_KEY_STANDARD' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PUBLIC_KEY_PASSKEY' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PASSKEY' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PASSKEY_ACTIVE' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PUBLIC_KEY' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PUBLIC_KEY_PRO' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PRO_KEYS_ACTIVE' ),

					// Passkey options - using constants.
					seculoco_get_constant_value( 'SECULOCO_OPTION_GLOBAL_PASSKEY' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PASSKEY_REGISTERED' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PASSKEY_REGISTERED_AT' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_PASSKEY_AAGUID_HASH' ),

					// Logging - using constants.
					seculoco_get_constant_value( 'SECULOCO_OPTION_KEY_ACCESS_LOG' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_KEY_OPERATIONS_LOG' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_UNIFIED_CRYPTO_LOG' ),

					// Version tracking - using constants.
					seculoco_get_constant_value( 'SECULOCO_OPTION_DB_VERSION' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_UPGRADE_COMPLETED' ),
					seculoco_get_constant_value( 'SECULOCO_OPTION_USING_PRO_VERSION' ),

					// Freemius related options (external library - no constants).
					'fs_accounts',
					'fs_active_plugins',
					'fs_api_cache',
					'fs_debug_mode',
				)
			)
		);

		// Delete each option with error handling.
		$options_deleted = 0;
		foreach ( $plugin_options as $option ) {
			if ( delete_option( $option ) ) {
				++$options_deleted;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Deleted ' . $options_deleted . ' options.' );
		}

		// 3. Delete user meta related to passkeys.
		$users = get_users();
		$user_meta_deleted = 0;

		foreach ( $users as $user ) {
			if ( delete_user_meta( $user->ID, 'seculoco_passkeys' ) ) {
				++$user_meta_deleted;
			}
			if ( delete_user_meta( $user->ID, 'seculoco_passkey_challenge' ) ) {
				++$user_meta_deleted;
			}
			if ( delete_user_meta( $user->ID, 'seculoco_wrapped_master_key' ) ) {
				++$user_meta_deleted;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Deleted ' . $user_meta_deleted . ' user meta entries.' );
		}

		// 4. Delete any transients that might exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients_result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_seculoco_%',
				'_transient_timeout_seculoco_%'
			)
		);

		if ( false === $transients_result && defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Failed to delete transients.' );
		}

		// 5. Clear any scheduled cron jobs.
		$timestamp = wp_next_scheduled( 'seculoco_cleanup_expired' );
		if ( $timestamp ) {
			$unscheduled = wp_unschedule_event( $timestamp, 'seculoco_cleanup_expired' );
			if ( ! $unscheduled && defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				error_log( 'Secure Login Collector: Failed to unschedule cron job.' );
			}
		}

		// 6. Clear object cache.
		wp_cache_flush();

		// Log successful completion.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Plugin data has been completely removed via Freemius uninstall hook.' );
		}

	} catch ( Exception $e ) {
		// Catch any exceptions and log them.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Uninstall error - ' . $e->getMessage() );
		}
	}
}

/**
 * Wrapper for Freemius uninstall hook.
 *
 * @return void
 */
function seculoco_fs_uninstall_cleanup() {
	seculoco_run_uninstall_cleanup();
}

/**
 * Wrapper for WordPress core uninstall hook.
 *
 * @return void
 */
function seculoco_wp_uninstall_cleanup() {
	seculoco_run_uninstall_cleanup();
}

/**
 * Register the uninstall cleanup function with Freemius
 *
 * This hooks into Freemius's after_uninstall action to ensure
 * cleanup happens after Freemius completes its own uninstall process.
 *
 * @since 1.0.1
 */
function seculoco_register_freemius_uninstall() {
	if ( function_exists( 'seculoco_fs' ) && seculoco_fs() ) {
		seculoco_fs()->add_action( 'after_uninstall', 'seculoco_fs_uninstall_cleanup' );
	}
}

// Register the uninstall handler when Freemius is loaded.
add_action( 'seculoco_fs_loaded', 'seculoco_register_freemius_uninstall' );
