<?php
/**
 * Encryption Handler Factory
 *
 * Provides factories for retrieving the appropriate encryption handler
 * (free or premium) without leaking premium implementation details into
 * free builds.
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory responsible for returning encryption handler instances.
 */
class Seculoco_Encryption_Handler_Factory {


	/**
	 * Shared handler instance.
	 *
	 * @var Seculoco_Encryption_Service|null
	 */
	private static $shared_handler = null;

	/**
	 * Get a shared encryption handler instance.
	 *
	 * @return Seculoco_Encryption_Service
	 */
	public static function get_shared_handler() {
		if ( null === self::$shared_handler ) {
			self::$shared_handler = self::instantiate_handler();
			return self::$shared_handler;
		}

		if ( self::should_use_premium_handler() && ! self::$shared_handler instanceof Seculoco_Encryption_Handler_V2_Premium ) {
			self::$shared_handler = self::instantiate_handler();
		}

		return self::$shared_handler;
	}

	/**
	 * Create a new encryption handler instance.
	 *
	 * @return Seculoco_Encryption_Service
	 */
	public static function create() {
		return self::instantiate_handler();
	}

	/**
	 * Instantiate the appropriate handler based on availability.
	 *
	 * @return Seculoco_Encryption_Service
	 */
	private static function instantiate_handler() {
		if ( self::should_use_premium_handler() ) {
			return new Seculoco_Encryption_Handler_V2_Premium();
		}

		return new Seculoco_Encryption_Handler_V2();
	}

	/**
	 * Determine if premium handler should be used.
	 *
	 * @return bool
	 */
	private static function should_use_premium_handler() {
		return class_exists( 'Seculoco_Encryption_Handler_V2_Premium' );
	}
}
