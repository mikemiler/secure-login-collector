<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * @fs_premium_only
 *
 * Premium Encryption Handler - AJAX Wrapper for Pro Features
 *
 * Extends base encryption handler to add pro AJAX endpoints.
 * All encryption logic is inherited from parent class - this file only
 * handles the HTTP layer (AJAX handlers) and Master Key Manager initialization.
 *
 * KISS principle: No business logic duplication, no reflection, minimal code.
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption Handler V2 - Premium AJAX Wrapper
 *
 * Extends base class to add:
 * - Pro AJAX handlers (seculoco_initialize_pro_keys, seculoco_delete_pro_keys)
 * - Master Key Manager initialization
 *
 * All encryption business logic is inherited from parent class.
 */
class Seculoco_Encryption_Handler_V2_Premium extends Seculoco_Encryption_Handler_V2 {

	/**
	 * Constructor - Register pro AJAX handlers.
	 */
	public function __construct() {
		// Initialize parent class (registers free AJAX handlers).
		parent::__construct();

		// Register pro AJAX handlers (delegates to inherited methods).
		add_action( 'wp_ajax_seculoco_initialize_pro_keys', array( $this, 'handle_initialize_pro_keys' ) );
		add_action( 'wp_ajax_seculoco_delete_pro_keys', array( $this, 'handle_delete_pro_keys' ) );
	}

	/**
	 * Handle AJAX to initialize pro keys.
	 *
	 * Validates input and delegates to inherited initialize_pro_keys() method.
	 */
	public function handle_initialize_pro_keys() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'seculoco_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		// Get passkey-derived key from request.
		$passkey_key = sanitize_text_field( wp_unslash( $_POST['passkey_derived_key'] ?? '' ) );
		if ( empty( $passkey_key ) ) {
			wp_send_json_error( 'Passkey-derived key required' );
			return;
		}

		

		// Decode the key (it's base64 encoded from client).
		$passkey_key = base64_decode( $passkey_key );
		if ( strlen( $passkey_key ) !== 32 ) {
			wp_send_json_error( 'Invalid key length' );
			return;
		}

		// Delegate to inherited method (no code duplication!).
		$result = $this->initialize_pro_keys( $passkey_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
			return;
		}
		return array(
			'status'  => 'success',
			'type'    => 'pro',
			'message' => 'Pro version keys initialized',
		);
		//wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX to delete pro keys.
	 *
	 * Delegates to inherited delete_pro_keys() method.
	 */
	public function handle_delete_pro_keys() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'seculoco_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		// Delegate to inherited method (no code duplication!).
		$result = $this->delete_pro_keys();
		wp_send_json_success( $result );
	}

	/**
	 * Get system status including both free and pro keys.
	 *
	 * Extends parent get_status() to include pro encryption status.
	 *
	 * @return array Status information for both free and pro.
	 */
	public static function get_status() {
		// Get base free status.
		$status = parent::get_status();

		// Add pro status.
		$status['pro'] = array(
			'active'          => self::is_pro_active(),
			'has_public_key'  => ! empty( get_option( 'seculoco_public_key_pro' ) ),
			'has_wrapped_key' => ! empty( get_option( 'seculoco_wrapped_private_key_pro' ) ),
		);

		return $status;
	}
}
