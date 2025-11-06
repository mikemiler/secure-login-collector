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

if ( ! function_exists( 'seculoco_is_encryption_initialized' ) ) {
	/**
	 * Check if encryption is initialized and ready to use.
	 *
	 * Checks for both free and pro encryption configurations.
	 * Returns true if either:
	 * - Free version: master password salt and wrapped key exist
	 * - Pro version: passkey-wrapped keys are active
	 *
	 * @since 2.0.0
	 * @return bool True if encryption is initialized, false otherwise.
	 */
	function seculoco_is_encryption_initialized() {
		// Check for pro encryption first (if available).
		if ( class_exists( 'Seculoco_Encryption_Handler_V2' ) && method_exists( 'Seculoco_Encryption_Handler_V2', 'is_pro_active' ) ) {
			if ( Seculoco_Encryption_Handler_V2::is_pro_active() ) {
				return true;
			}
		}

		// Check for free encryption setup.
		$encryption_version = get_option( SECULOCO_OPTION_ENCRYPTION_VERSION, '' );
		$has_private_key    = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED, false );
		$has_salt           = get_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT, false );

		

		return ( 'v2' === $encryption_version && $has_private_key && $has_salt );
	}
}
