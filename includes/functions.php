<?php
/**
 * Global functions for Secure Login Collector.
 *
 * @package Secure_Login_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'seculoco_init' ) ) {
	/**
	 * Initialize the plugin.
	 *
	 * This function instantiates the main plugin class after Freemius has loaded.
	 * It ensures proper initialization order and prevents race conditions.
	 *
	 * @return SecureLoginCollector The plugin instance.
	 */
	function seculoco_init() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new SecureLoginCollector();
		}

		return $instance;
	}
}

if ( ! function_exists( 'seculoco_has_password_encryption' ) ) {
	/**
	 * Determine if password-based encryption is fully configured.
	 *
	 * Supports both the legacy option set (v2 free encryption) and the new
	 * unified crypto storage introduced in 1.4.x.
	 *
	 * @return bool
	 */
	function seculoco_has_password_encryption() {
		// Modern flags written by the current setup wizard.
		if ( (bool) get_option( SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE, false ) ) {
			return true;
		}

		// Fallback flag written by the encryption handler.
		if ( (bool) get_option( SECULOCO_OPTION_PASSWORD_ACTIVE, false ) ) {
			return true;
		}

		// Unified crypto storage (standard/password keys).
		$has_unified_keys = ! empty( get_option( SECULOCO_OPTION_PUBLIC_KEY_STANDARD, '' ) )
			&& ! empty( get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD, array() ) );
		if ( $has_unified_keys ) {
			return true;
		}

		// Legacy v2 storage (pre-unified crypto).
		if ( defined( 'SECULOCO_OPTION_ENCRYPTION_VERSION' )
			&& defined( 'SECULOCO_OPTION_PRIVATE_KEY_WRAPPED' )
			&& defined( 'SECULOCO_OPTION_MASTER_PASSWORD_SALT' ) ) {

			$legacy_version = get_option( SECULOCO_OPTION_ENCRYPTION_VERSION, '' );
			$legacy_key     = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED, false );
			$legacy_salt    = get_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT, false );

			if ( 'v2' === $legacy_version && $legacy_key && $legacy_salt ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'seculoco_has_passkey_encryption' ) ) {
	/**
	 * Determine if passkey-based encryption is active.
	 *
	 * @return bool
	 */
	function seculoco_has_passkey_encryption() {
		$active     = (bool) get_option( SECULOCO_OPTION_PASSKEY_ACTIVE, false );
		$registered = (bool) get_option( SECULOCO_OPTION_PASSKEY_REGISTERED, false );

		if ( $active && $registered ) {
			return true;
		}

		// Back-compat: older builds stored a more generic "pro keys active" flag.
		$pro_active = (bool) get_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, false );
		return $pro_active && $registered;
	}
}

if ( ! function_exists( 'seculoco_is_encryption_initialized' ) ) {
	/**
	 * Check if encryption is initialized and ready to use.
	 *
	 * @since 2.0.0
	 * @since 2.1.0 Updated to support unified crypto storage (1.4+).
	 * @return bool True if encryption is initialized, false otherwise.
	 */
	function seculoco_is_encryption_initialized() {
		return seculoco_has_password_encryption() || seculoco_has_passkey_encryption();
	}
}
