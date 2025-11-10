<?php
/**
 * Global functions for Secure Login Collector.
 *
 * @package Secure_Login_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		/**
		 * Filter: seculoco_has_passkey_encryption
		 *
		 * Premium builds can hook into this filter to report passkey availability.
		 *
		 * @param bool $active Whether passkey encryption is active (defaults to false).
		 */
		return (bool) apply_filters( 'seculoco_has_passkey_encryption', false );
	}
}

if ( ! function_exists( 'seculoco_is_encryption_initialized' ) ) {
	/**
	 * Check if encryption is initialized and ready to use.
	 *
	 * @since  2.0.0
	 * @since  2.1.0 Updated to support unified crypto storage (1.4+).
	 * @return bool True if encryption is initialized, false otherwise.
	 */
	function seculoco_is_encryption_initialized() {
		return seculoco_has_password_encryption() || seculoco_has_passkey_encryption();
	}
}

if ( ! function_exists( 'seculoco_is_premium_active' ) ) {
	/**
	 * Determine if premium code is available and licensed.
	 *
	 * @return bool
	 */
	function seculoco_is_premium_active() {
		if ( defined( 'SECULOCO_SIMULATE_FREE_VERSION' ) && SECULOCO_SIMULATE_FREE_VERSION ) {
			return false;
		}

		if ( ! function_exists( 'seculoco_fs' ) ) {
			return false;
		}

		$fs = seculoco_fs();

		if ( ! $fs || ! method_exists( $fs, 'can_use_premium_code' ) ) {
			return false;
		}

		return (bool) $fs->can_use_premium_code();
	}
}

if ( ! function_exists( 'seculoco_get_expiration_days' ) ) {
	/**
	 * Get the configured auto-delete retention window.
	 *
	 * @return int Number of days, or 0 when disabled/unavailable.
	 */
	function seculoco_get_expiration_days() {
		if ( ! seculoco_is_premium_active() || ! defined( 'SECULOCO_OPTION_EXPIRATION_DAYS' ) ) {
			return 0;
		}

		$days = (int) get_option( SECULOCO_OPTION_EXPIRATION_DAYS, 30 );
		return $days > 0 ? $days : 0;
	}
}

if ( ! function_exists( 'seculoco_is_auto_delete_enabled' ) ) {
	/**
	 * Helper to determine if auto-delete is active.
	 *
	 * @return bool
	 */
	function seculoco_is_auto_delete_enabled() {
		return seculoco_get_expiration_days() > 0;
	}
}

if ( ! function_exists( 'seculoco_has_honeypot_feature' ) ) {
	/**
	 * Whether the honeypot feature is available.
	 *
	 * Free builds default to false; premium re-enables via filters.
	 *
	 * @return bool
	 */
	function seculoco_has_honeypot_feature() {
		return (bool) apply_filters( 'seculoco_has_honeypot_feature', false );
	}
}

if ( ! function_exists( 'seculoco_is_honeypot_enabled' ) ) {
	/**
	 * Check if honeypot protection should run.
	 *
	 * @return bool
	 */
	function seculoco_is_honeypot_enabled() {
		if ( ! seculoco_has_honeypot_feature() ) {
			return false;
		}

		return (bool) apply_filters( 'seculoco_is_honeypot_enabled', false );
	}
}
