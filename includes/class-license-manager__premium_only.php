<?php
/**
 * @fs_premium_only
 *
 * Premium feature: License Management
 * This file is only included in the premium version.
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_License_Manager
 *
 * Simple wrapper for Freemius licensing checks.
 * Uses KISS principle - delegates all licensing to Freemius.
 */
class Seculoco_License_Manager {

	/**
	 * Check if user has active pro license.
	 *
	 * This is the ONLY licensing check needed - Freemius handles everything.
	 *
	 * @return bool True if user has paid license, false otherwise.
	 */
	public static function has_pro_license() {
		return function_exists( 'seculoco_fs' )
			&& seculoco_fs()->is_registered()
			&& seculoco_fs()->is_paying();
	}
}
