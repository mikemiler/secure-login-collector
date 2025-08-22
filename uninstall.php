<?php
/**
 * Uninstall Secure Login Collector
 *
 * This file is executed when the plugin is uninstalled.
 * It handles cleanup of all plugin data if the user has enabled the cleanup option.
 *
 * @package SecureLoginCollector
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if user has opted to delete all data on uninstall.
$delete_on_uninstall = get_option( 'secure_login_delete_on_uninstall', false );

if ( ! $delete_on_uninstall ) {
	// User has chosen to keep data, so exit without deleting anything.
	return;
}

/**
 * Delete all plugin data
 * User has explicitly opted to remove everything
 */

global $wpdb;

// 1. Delete the custom database table
$table_name = $wpdb->prefix . 'secure_login_data';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// 2. Delete all plugin options
$plugin_options = array(
	// Settings
	'secure_login_notification_email',
	'secure_login_enable_notifications',
	'secure_login_expiration_days',
	'secure_login_ultra_secure_mode',
	'secure_login_frontend_form_text',
	'secure_login_frontend_text_type',
	'secure_login_delete_on_uninstall',
	
	// Encryption keys and related data
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
	
	// Database version
	'secure_login_db_version',
	
	// Freemius related options (if using Freemius)
	'fs_accounts',
	'fs_active_plugins',
	'fs_api_cache',
	'fs_debug_mode',
);

// Delete each option
foreach ( $plugin_options as $option ) {
	delete_option( $option );
}

// 3. Delete user meta related to passkeys
$users = get_users();
foreach ( $users as $user ) {
	delete_user_meta( $user->ID, 'secure_login_passkeys' );
	delete_user_meta( $user->ID, 'secure_login_passkey_challenge' );
	delete_user_meta( $user->ID, 'secure_login_wrapped_master_key' );
}

// 4. Delete any transients that might exist
$wpdb->query( 
	"DELETE FROM {$wpdb->options} 
	WHERE option_name LIKE '_transient_secure_login_%' 
	OR option_name LIKE '_transient_timeout_secure_login_%'"
);

// 5. Clear any scheduled cron jobs
$timestamp = wp_next_scheduled( 'secure_login_cleanup_expired' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'secure_login_cleanup_expired' );
}

// 6. Clear object cache
wp_cache_flush();

// Log the uninstall for debugging (optional - remove in production)
if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
	error_log( 'Secure Login Collector: Plugin data has been completely removed.' );
}