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
		define( 'SECULOCO_OPTION_RATE_LIMIT_ENABLED', 'seculoco_rate_limit_enabled' );
		define( 'SECULOCO_OPTION_RATE_LIMIT_MAX_ATTEMPTS', 'seculoco_rate_limit_max_attempts' );
		define( 'SECULOCO_OPTION_RATE_LIMIT_TIME_WINDOW', 'seculoco_rate_limit_time_window' );
		define( 'SECULOCO_OPTION_UPGRADE_COMPLETED', 'seculoco_upgrade_completed' );
		define( 'SECULOCO_OPTION_USING_PRO_VERSION', 'seculoco_using_pro_version' );
	}

	/**
	 * Register premium encryption constants (keys, passkeys, status).
	 *
	 * @return void
	 */
	private static function register_encryption_constants() {
		define( 'SECULOCO_OPTION_PUBLIC_KEY_PRO', 'seculoco_public_key_pro' );
		define( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO', 'seculoco_wrapped_private_key_pro' );
		define( 'SECULOCO_OPTION_PRO_KEYS_ACTIVE', 'seculoco_pro_keys_active' );
		define( 'SECULOCO_OPTION_GLOBAL_PASSKEY', 'seculoco_global_passkey' );
		define( 'SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID', 'seculoco_passkey_credential_id' );
		define( 'SECULOCO_OPTION_PASSKEY_REGISTERED', 'seculoco_passkey_registered' );
		define( 'SECULOCO_OPTION_PASSKEY_REGISTERED_AT', 'seculoco_passkey_registered_at' );
		define( 'SECULOCO_OPTION_PASSKEY_AAGUID_HASH', 'seculoco_passkey_aaguid_hash' );
	}

	/**
	 * Define a constant only if it has not been set already.
	 *
	 * @param string $name  Constant name.
	 * @param string $value Constant value.
	 * @return void
	 */
	private static function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}
}

add_action( 'seculoco_register_premium_constants', array( 'Seculoco_Premium_Constants', 'register' ), 5 );

if ( did_action( 'seculoco_register_premium_constants' ) ) {
	Seculoco_Premium_Constants::register();
}
