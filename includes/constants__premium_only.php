<?php
/**
 * Premium-only option constants.
 *
 * Registers general and encryption-related option names that are only
 * required when the Pro features are active.
 *
 * @package Secure_Login_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class responsible for registering Pro-only constants.
 */
class Seculoco_Premium_Constants {


	/**
	 * Track whether the constants have already been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register all premium constants (general + encryption).
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		self::register_general_constants();
		self::register_encryption_constants();
	}

	/**
	 * Register premium general constants (non-encryption).
	 *
	 * @return void
	 */
	private static function register_general_constants() {
		self::maybe_define( 'SECULOCO_OPTION_EXPIRATION_DAYS', 'seculoco_expiration_days' );
		self::maybe_define( 'SECULOCO_OPTION_RATE_LIMIT_ENABLED', 'seculoco_rate_limit_enabled' );
		self::maybe_define( 'SECULOCO_OPTION_RATE_LIMIT_MAX_ATTEMPTS', 'seculoco_rate_limit_max_attempts' );
		self::maybe_define( 'SECULOCO_OPTION_RATE_LIMIT_TIME_WINDOW', 'seculoco_rate_limit_time_window' );
		self::maybe_define( 'SECULOCO_OPTION_UPGRADE_COMPLETED', 'seculoco_upgrade_completed' );
		self::maybe_define( 'SECULOCO_OPTION_HONEYPOT_ENABLED', 'seculoco_honeypot_enabled' );
		self::maybe_define( 'SECULOCO_OPTION_HONEYPOT_MIN_TIME', 'seculoco_honeypot_min_time' );
		self::maybe_define( 'SECULOCO_OPTION_HONEYPOT_LOG', 'seculoco_honeypot_log' );
	}

	/**
	 * Register premium encryption constants (keys, passkeys, status).
	 *
	 * @return void
	 */
	private static function register_encryption_constants() {
		self::maybe_define( 'SECULOCO_OPTION_PUBLIC_KEY_PRO', 'seculoco_public_key_pro' );
		self::maybe_define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO', 'seculoco_wrapped_private_key_pro' );
		self::maybe_define( 'SECULOCO_OPTION_PRO_KEYS_ACTIVE', 'seculoco_pro_keys_active' );
		self::maybe_define( 'SECULOCO_OPTION_GLOBAL_PASSKEY', 'seculoco_global_passkey' );
		self::maybe_define( 'SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID', 'seculoco_passkey_credential_id' );
		self::maybe_define( 'SECULOCO_OPTION_PASSKEY_REGISTERED', 'seculoco_passkey_registered' );
		self::maybe_define( 'SECULOCO_OPTION_PASSKEY_REGISTERED_AT', 'seculoco_passkey_registered_at' );
		self::maybe_define( 'SECULOCO_OPTION_PASSKEY_AAGUID_HASH', 'seculoco_passkey_aaguid_hash' );
		self::maybe_define( 'SECULOCO_OPTION_ULTRA_SECURE_MODE', 'seculoco_ultra_secure_mode' );
	}

	/**
	 * Define a constant only if it has not been defined yet.
	 *
	 * @param string $name  Constant identifier.
	 * @param string $value Constant value.
	 * @return void
	 */
	private static function maybe_define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}
}

add_action( 'seculoco_register_premium_constants', array( 'Seculoco_Premium_Constants', 'register' ), 5 );
add_action( 'seculoco_before_uninstall_cleanup', array( 'Seculoco_Premium_Constants', 'register' ), 5 );

if ( did_action( 'seculoco_register_premium_constants' ) ) {
	Seculoco_Premium_Constants::register();
}

/**
 * Ensure Pro-specific options are removed alongside the base plugin options.
 *
 * @param array $option_names Free plugin option identifiers slated for deletion.
 *
 * @return array
 */
function seculoco_premium_extend_uninstall_options( $option_names ) {
		$premium_only_options = array(
			defined( 'SECULOCO_OPTION_RATE_LIMIT_ENABLED' ) ? SECULOCO_OPTION_RATE_LIMIT_ENABLED : null,
			defined( 'SECULOCO_OPTION_RATE_LIMIT_MAX_ATTEMPTS' ) ? SECULOCO_OPTION_RATE_LIMIT_MAX_ATTEMPTS : null,
			defined( 'SECULOCO_OPTION_RATE_LIMIT_TIME_WINDOW' ) ? SECULOCO_OPTION_RATE_LIMIT_TIME_WINDOW : null,
			defined( 'SECULOCO_OPTION_EXPIRATION_DAYS' ) ? SECULOCO_OPTION_EXPIRATION_DAYS : null,
			defined( 'SECULOCO_OPTION_HONEYPOT_ENABLED' ) ? SECULOCO_OPTION_HONEYPOT_ENABLED : null,
			defined( 'SECULOCO_OPTION_HONEYPOT_MIN_TIME' ) ? SECULOCO_OPTION_HONEYPOT_MIN_TIME : null,
		);

		return array_values(
			array_unique(
				array_filter(
					array_merge( $option_names, $premium_only_options )
				)
			)
		);
}

add_filter( 'seculoco_uninstall_option_names', 'seculoco_premium_extend_uninstall_options' );
