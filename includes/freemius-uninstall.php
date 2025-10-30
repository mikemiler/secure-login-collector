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

/**
 * Freemius uninstall cleanup function
 *
 * This function is called by Freemius after the plugin is uninstalled.
 * It handles cleanup of all plugin data if the user has enabled the cleanup option.
 *
 * @since 1.0.1
 * @return void
 */
function slc_fs_uninstall_cleanup() {
	try {
		// Check if user has opted to delete all data on uninstall.
		$delete_on_uninstall = get_option( 'secure_login_delete_on_uninstall', false );

		if ( ! $delete_on_uninstall ) {
			// User has chosen to keep data, so exit without deleting anything.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				error_log( 'Secure Login Collector: User opted to keep data on uninstall.' );
			}
			return;
		}

		global $wpdb;

		// 1. Delete the custom database table.
		$table_name = $wpdb->prefix . 'secure_login_data';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$result = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );

		if ( false === $result ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				error_log( 'Secure Login Collector: Failed to drop table ' . $table_name );
			}
		}

		// 2. Delete all plugin options.
		$plugin_options = array(
			// Settings.
			'secure_login_notification_email',
			'secure_login_enable_notifications',
			'secure_login_expiration_days',
			'secure_login_ultra_secure_mode',
			'secure_login_frontend_form_text',
			'secure_login_frontend_text_type',
			'secure_login_delete_on_uninstall',

			// Encryption keys and related data.
			'secure_login_public_key',
			'secure_login_private_key',
			'secure_login_wrapped_private_key',
			'secure_login_private_key_encrypted',
			'secure_login_public_key_free',
			'secure_login_private_key_free_encrypted',
			'secure_login_public_key_pro',
			'secure_login_wrapped_private_key_pro',
			'secure_login_pro_keys_active',
			'secure_login_passkey_registered',
			'secure_login_master_key_wrapped',
			'secure_login_key_access_log',
			'secure_login_session_keys',

			// Database version.
			'secure_login_db_version',

			// Freemius related options.
			'fs_accounts',
			'fs_active_plugins',
			'fs_api_cache',
			'fs_debug_mode',
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
			if ( delete_user_meta( $user->ID, 'secure_login_passkeys' ) ) {
				++$user_meta_deleted;
			}
			if ( delete_user_meta( $user->ID, 'secure_login_passkey_challenge' ) ) {
				++$user_meta_deleted;
			}
			if ( delete_user_meta( $user->ID, 'secure_login_wrapped_master_key' ) ) {
				++$user_meta_deleted;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Deleted ' . $user_meta_deleted . ' user meta entries.' );
		}

		// 4. Delete any transients that might exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients_result = $wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_secure_login_%'
			OR option_name LIKE '_transient_timeout_secure_login_%'"
		);

		if ( false === $transients_result && defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Failed to delete transients.' );
		}

		// 5. Clear any scheduled cron jobs.
		$timestamp = wp_next_scheduled( 'secure_login_cleanup_expired' );
		if ( $timestamp ) {
			$unscheduled = wp_unschedule_event( $timestamp, 'secure_login_cleanup_expired' );
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
 * Register the uninstall cleanup function with Freemius
 *
 * This hooks into Freemius's after_uninstall action to ensure
 * cleanup happens after Freemius completes its own uninstall process.
 *
 * @since 1.0.1
 */
function slc_register_freemius_uninstall() {
	if ( function_exists( 'slc_fs' ) && slc_fs() ) {
		slc_fs()->add_action( 'after_uninstall', 'slc_fs_uninstall_cleanup' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			error_log( 'Secure Login Collector: Freemius uninstall handler registered.' );
		}
	}
}

// Register the uninstall handler when Freemius is loaded.
add_action( 'slc_fs_loaded', 'slc_register_freemius_uninstall' );
