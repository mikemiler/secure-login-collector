<?php
/**
 * Premium Frontend Handler for Secure Login Collector.
 *
 * @fs_premium_only
 *
 * Premium Frontend Handler
 * Extends free version with pro features via hooks
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Frontend_Handler_Pro
 *
 * Handles pro-specific frontend functionality via hooks.
 */
class Seculoco_Frontend_Handler_Pro {

	/**
	 * Constructor - hooks into free version's actions/filters.
	 */
	public function __construct() {
		// Add pro flag to frontend JS config.
		add_filter( 'seculoco_frontend_js_config', array( $this, 'add_pro_js_config' ) );

		// Filter encryption metadata for pro keys.
		add_filter( 'seculoco_encryption_metadata', array( $this, 'add_pro_encryption_metadata' ), 10, 2 );
	}

	/**
	 * Add pro flag to frontend JavaScript configuration.
	 *
	 * @param array $config Existing JS configuration.
	 * @return array Modified configuration with pro flag.
	 */
	public function add_pro_js_config( $config ) {
		$config['is_pro'] = true;
		return $config;
	}

	/**
	 * Add pro encryption metadata if pro keys are active.
	 *
	 * @param array $metadata      Existing metadata.
	 * @param array $login_data    Login data being saved.
	 * @return array Modified metadata.
	 */
	public function add_pro_encryption_metadata( $metadata, $login_data ) {
		// Check if pro keys are active.
		if ( get_option( 'seculoco_pro_keys_active', false ) ) {
			// Mark as Pro encrypted - data will be encrypted with pro public key.
			// The passkey decryption happens on the admin side during decryption.
			$metadata['is_pro_encrypted']     = true;
			$metadata['server_credential_id'] = get_option( 'seculoco_passkey_credential_id' );
		}

		return $metadata;
	}
}

// Initialize pro frontend handler.
new Seculoco_Frontend_Handler_Pro();
