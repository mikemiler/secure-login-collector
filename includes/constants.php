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
 * SHARED CONSTANTS (Free & Pro)
 * ========================================
 */

// Version and setup.
define( 'SECULOCO_OPTION_DB_VERSION', 'seculoco_db_version' );
define( 'SECULOCO_OPTION_ENCRYPTION_VERSION', 'seculoco_encryption_version' );
define( 'SECULOCO_OPTION_SETUP_TIMESTAMP', 'seculoco_setup_timestamp' );
define( 'SECULOCO_OPTION_KEYS_CLEANUP_V3', 'seculoco_keys_cleanup_v3' );

// Master password salt (shared between free and pro).
define( 'SECULOCO_OPTION_MASTER_PASSWORD_SALT', 'seculoco_master_password_salt' );
define( 'SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE', 'seculoco_password_encryption_active' );
define( 'SECULOCO_OPTION_PASSWORD_ACTIVE', 'seculoco_password_active' );
define( 'SECULOCO_OPTION_PASSKEY_ACTIVE', 'seculoco_passkey_active' );

// Data expiration.
define( 'SECULOCO_OPTION_EXPIRATION_DAYS', 'seculoco_expiration_days' );

// Notifications.
define( 'SECULOCO_OPTION_ENABLE_NOTIFICATIONS', 'seculoco_enable_notifications' );
define( 'SECULOCO_OPTION_NOTIFICATION_EMAIL', 'seculoco_notification_email' );

// Frontend settings.
define( 'SECULOCO_OPTION_FRONTEND_TEXT_TYPE', 'seculoco_frontend_text_type' );
define( 'SECULOCO_OPTION_FRONTEND_FORM_TEXT', 'seculoco_frontend_form_text' );
define( 'SECULOCO_OPTION_HIDE_SERVICE_FOOTER', 'seculoco_hide_service_footer' );

// Uninstall settings.
define( 'SECULOCO_OPTION_DELETE_ON_UNINSTALL', 'seculoco_delete_on_uninstall' );

// Spam protection.
define( 'SECULOCO_OPTION_SPAM_SETTINGS', 'seculoco_spam_protection_settings' );
define( 'SECULOCO_OPTION_HONEYPOT_ENABLED', 'seculoco_honeypot_enabled' );
define( 'SECULOCO_OPTION_HONEYPOT_MIN_TIME', 'seculoco_honeypot_min_time' );
define( 'SECULOCO_OPTION_HONEYPOT_LOG', 'seculoco_honeypot_log' );

// Logging.
define( 'SECULOCO_OPTION_KEY_ACCESS_LOG', 'seculoco_key_access_log' );
define( 'SECULOCO_OPTION_KEY_OPERATIONS_LOG', 'seculoco_key_operations_log' );
define( 'SECULOCO_OPTION_UNIFIED_CRYPTO_LOG', 'seculoco_unified_crypto_log' );

/**
 * ========================================
 * FREE VERSION CONSTANTS
 * ========================================
 */

// Free encryption keys (v2 format / unified crypto).
define( 'SECULOCO_OPTION_PUBLIC_KEY', 'seculoco_public_key' );
define( 'SECULOCO_OPTION_PRIVATE_KEY_WRAPPED', 'seculoco_private_key_wrapped' );
define( 'SECULOCO_OPTION_PUBLIC_KEY_STANDARD', 'seculoco_public_key_standard' );
define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD', 'seculoco_wrapped_private_key_standard' );

// Unified crypto (passkey storage mirrors standard).
define( 'SECULOCO_OPTION_PUBLIC_KEY_PASSKEY', 'seculoco_public_key_passkey' );
define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PASSKEY', 'seculoco_wrapped_private_key_passkey' );

// Dynamic prefixes for unified crypto helper methods.
define( 'SECULOCO_OPTION_PUBLIC_KEY_PREFIX', 'seculoco_public_key_' );
define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PREFIX', 'seculoco_wrapped_private_key_' );

// Legacy free encryption keys (old naming - to be migrated).
define( 'SECULOCO_OPTION_PUBLIC_KEY_JWK_FREE', 'seculoco_public_key_jwk_free' );
define( 'SECULOCO_OPTION_KEY_WRAPPING_IV_FREE', 'seculoco_key_wrapping_iv_free' );
define( 'SECULOCO_OPTION_PUBLIC_KEY_FREE', 'seculoco_public_key_free' );
define( 'SECULOCO_OPTION_PRIVATE_KEY_FREE_ENCRYPTED', 'seculoco_private_key_free_encrypted' );
define( 'SECULOCO_OPTION_ULTRA_SECURE_MODE', 'seculoco_ultra_secure_mode' );


/**
 * ========================================
 * PRO VERSION CONSTANTS (Premium Only)
 * ========================================
 */

// Pro encryption keys.
define( 'SECULOCO_OPTION_PUBLIC_KEY_PRO', 'seculoco_public_key_pro' );
define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO', 'seculoco_wrapped_private_key_pro' );
define( 'SECULOCO_OPTION_PRO_KEYS_ACTIVE', 'seculoco_pro_keys_active' );

// Passkey (WebAuthn) settings.
define( 'SECULOCO_OPTION_GLOBAL_PASSKEY', 'seculoco_global_passkey' );
define( 'SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID', 'seculoco_passkey_credential_id' );
define( 'SECULOCO_OPTION_PASSKEY_REGISTERED', 'seculoco_passkey_registered' );
define( 'SECULOCO_OPTION_PASSKEY_REGISTERED_AT', 'seculoco_passkey_registered_at' );
define( 'SECULOCO_OPTION_PASSKEY_AAGUID_HASH', 'seculoco_passkey_aaguid_hash' );

// Rate limiting (premium spam protection).
define( 'SECULOCO_OPTION_RATE_LIMIT_ENABLED', 'seculoco_rate_limit_enabled' );
define( 'SECULOCO_OPTION_RATE_LIMIT_MAX_ATTEMPTS', 'seculoco_rate_limit_max_attempts' );
define( 'SECULOCO_OPTION_RATE_LIMIT_TIME_WINDOW', 'seculoco_rate_limit_time_window' );

// Upgrade tracking.
define( 'SECULOCO_OPTION_UPGRADE_COMPLETED', 'seculoco_upgrade_completed' );
define( 'SECULOCO_OPTION_USING_PRO_VERSION', 'seculoco_using_pro_version' );
