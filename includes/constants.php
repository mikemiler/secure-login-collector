<?php
/**
 * WordPress Option Constants
 *
 * Centralized location for all wp_options keys used by the plugin.
 * This ensures consistency and makes it easier to maintain option names.
 *
 * @package Secure_Login_Collector
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ========================================
 * GENERAL OPTION CONSTANTS (Free & Shared)
 * ========================================
 *
 * Premium-only general constants register themselves via the
 * `seculoco_register_premium_constants` hook defined at the end of this file.
 */

// Version and setup.
define( 'SECULOCO_OPTION_DB_VERSION', 'seculoco_db_version' );

// Master password salt (shared between free and pro).
define( 'SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE', 'seculoco_password_encryption_active' );
define( 'SECULOCO_OPTION_PASSWORD_ACTIVE', 'seculoco_password_active' );
define( 'SECULOCO_OPTION_PASSKEY_ACTIVE', 'seculoco_passkey_active' );
if ( ! defined( 'SECULOCO_OPTION_PASSKEY_REGISTERED' ) ) {
	define( 'SECULOCO_OPTION_PASSKEY_REGISTERED', 'seculoco_passkey_registered' );
}
if ( ! defined( 'SECULOCO_OPTION_PASSKEY_REGISTERED_AT' ) ) {
	define( 'SECULOCO_OPTION_PASSKEY_REGISTERED_AT', 'seculoco_passkey_registered_at' );
}
if ( ! defined( 'SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID' ) ) {
	define( 'SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID', 'seculoco_passkey_credential_id' );
}
if ( ! defined( 'SECULOCO_OPTION_PASSKEY_AAGUID_HASH' ) ) {
	define( 'SECULOCO_OPTION_PASSKEY_AAGUID_HASH', 'seculoco_passkey_aaguid_hash' );
}
if ( ! defined( 'SECULOCO_OPTION_GLOBAL_PASSKEY' ) ) {
	define( 'SECULOCO_OPTION_GLOBAL_PASSKEY', 'seculoco_global_passkey' );
}
if ( ! defined( 'SECULOCO_OPTION_PRO_KEYS_ACTIVE' ) ) {
	define( 'SECULOCO_OPTION_PRO_KEYS_ACTIVE', 'seculoco_pro_keys_active' );
}

// Notifications.
define( 'SECULOCO_OPTION_ENABLE_NOTIFICATIONS', 'seculoco_enable_notifications' );
define( 'SECULOCO_OPTION_NOTIFICATION_EMAIL', 'seculoco_notification_email' );

// Frontend settings.
define( 'SECULOCO_OPTION_FRONTEND_TEXT_TYPE', 'seculoco_frontend_text_type' );
define( 'SECULOCO_OPTION_FRONTEND_FORM_TEXT', 'seculoco_frontend_form_text' );

// Uninstall settings.
define( 'SECULOCO_OPTION_DELETE_ON_UNINSTALL', 'seculoco_delete_on_uninstall' );
define( 'SECULOCO_OPTION_USING_PRO_VERSION', 'seculoco_using_pro_version' );

// Spam protection (honeypot settings/flags register via premium constants hook).
define( 'SECULOCO_OPTION_SPAM_SETTINGS', 'seculoco_spam_protection_settings' );

// Logging.
define( 'SECULOCO_OPTION_KEY_ACCESS_LOG', 'seculoco_key_access_log' );
define( 'SECULOCO_OPTION_KEY_OPERATIONS_LOG', 'seculoco_key_operations_log' );
define( 'SECULOCO_OPTION_UNIFIED_CRYPTO_LOG', 'seculoco_unified_crypto_log' );

if ( ! defined( 'SECULOCO_SIMULATE_FREE_VERSION' ) ) {
	define( 'SECULOCO_SIMULATE_FREE_VERSION', false );
}

/**
 * ========================================
 * FREE ENCRYPTION CONSTANTS
 * ========================================
 *
 * Premium encryption constants (e.g., PRO key storage, passkeys) are
 * registered via `seculoco_register_premium_constants`.
 */

// Free encryption keys (v2 format / unified crypto).
define( 'SECULOCO_OPTION_PUBLIC_KEY', 'seculoco_public_key' );
define( 'SECULOCO_OPTION_PUBLIC_KEY_STANDARD', 'seculoco_public_key_standard' );
define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD', 'seculoco_wrapped_private_key_standard' );

// Unified crypto (passkey storage mirrors standard).
define( 'SECULOCO_OPTION_PUBLIC_KEY_PASSKEY', 'seculoco_public_key_passkey' );
define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PASSKEY', 'seculoco_wrapped_private_key_passkey' );

// Dynamic prefixes for unified crypto helper methods.
define( 'SECULOCO_OPTION_PUBLIC_KEY_PREFIX', 'seculoco_public_key_' );
define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PREFIX', 'seculoco_wrapped_private_key_' );

/**
 * Allow premium builds to register their own general/encryption constants.
 */
if ( function_exists( 'do_action' ) ) {
	/**
	 * Fires when the free constants have loaded, giving the premium add-on
	 * a spot to register its general and encryption constants.
	 */
	do_action( 'seculoco_register_premium_constants' );
}
