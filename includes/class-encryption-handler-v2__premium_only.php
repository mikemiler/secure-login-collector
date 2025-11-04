<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Premium feature file.
/**
 * Pro Encryption Handler - Premium Features
 *
 * @fs_premium_only
 *
 * Extends base encryption handler to add passkey-wrapping capabilities.
 * Uses WordPress hooks for clean integration with free version.
 *
 * @package SecureLoginCollector
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Premium Encryption Handler - Pro Features
 *
 * Extends Seculoco_Encryption_Handler_V2 to add:
 * - Passkey-based key wrapping (AES-256-GCM)
 * - Pro key initialization and management
 * - Enhanced status reporting
 *
 * @since 1.0.0
 */
class Seculoco_Encryption_Handler_V2_Premium extends Seculoco_Encryption_Handler_V2 {

	/**
	 * Constructor - Register pro-specific hooks and filters.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Call parent constructor to register base AJAX handlers.
		parent::__construct();

		// Register pro-specific filters and actions.
		$this->register_pro_hooks();
	}

	/**
	 * Register WordPress hooks for pro features.
	 *
	 * Uses WordPress hooks pattern for clean integration with base class.
	 * All hooks check license status before executing pro logic.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_pro_hooks() {
		// Filter: Return pro public key when pro is active.
		add_filter( 'seculoco_get_public_key', array( $this, 'filter_public_key' ), 10, 1 );

		// Action: Intercept private key requests to return pro wrapped key.
		add_action( 'seculoco_get_wrapped_private_key_request', array( $this, 'action_handle_pro_key_request' ), 10, 2 );

		// Filter: Determine encryption type for frontend submissions.
		add_filter( 'seculoco_determine_encryption_type', array( $this, 'filter_determine_encryption_type' ), 10, 2 );

		// Filter: Set encryption type based on pro status (legacy).
		add_filter( 'seculoco_encryption_type', array( $this, 'filter_encryption_type' ), 10, 1 );

		// Action: Initialize pro encryption keys.
		add_action( 'seculoco_initialize_encryption_keys', array( $this, 'action_initialize_pro_keys' ), 10, 1 );

		// Action: Delete pro encryption keys.
		add_action( 'seculoco_delete_encryption_keys', array( $this, 'action_delete_pro_keys' ), 10, 0 );

		// Filter: Enhance status with pro information.
		add_filter( 'seculoco_encryption_status', array( $this, 'filter_encryption_status' ), 10, 1 );
	}

	/**
	 * Filter public key to return pro key when active.
	 *
	 * @since 1.0.0
	 * @param string $public_key Default public key (free version).
	 * @return string Pro public key if active, otherwise free key.
	 */
	public function filter_public_key( $public_key ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return $public_key;
		}

		// Check if pro keys are initialized and passkey is registered.
		$is_pro_active      = get_option( 'seculoco_pro_keys_active', false );
		$passkey_registered = get_option( 'seculoco_passkey_registered', false );

		if ( $is_pro_active && $passkey_registered ) {
			$pro_key = get_option( 'seculoco_public_key_pro' );
			if ( ! empty( $pro_key ) ) {
				return $pro_key;
			}
		}

		return $public_key;
	}

	/**
	 * Action: Handle pro key requests by checking entry type and sending appropriate response.
	 *
	 * This action fires during AJAX private key retrieval. If the entry uses pro encryption,
	 * this method sends the pro wrapped key and exits. Otherwise, it returns control to the
	 * base class to handle free key retrieval.
	 *
	 * @since 1.0.0
	 * @param int    $entry_id The entry ID being decrypted.
	 * @param string $nonce    The verified nonce.
	 * @return void
	 */
	public function action_handle_pro_key_request( $entry_id, $nonce ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return; // Let base handler continue with free key.
		}

		// Check if entry_id provided and valid.
		if ( $entry_id <= 0 ) {
			return; // Let base handler continue.
		}

		// Check if this entry uses pro encryption.
		global $wpdb;
		$table_name = $wpdb->prefix . 'seculoco_data';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT metadata FROM {$table_name} WHERE id = %d",
				$entry_id
			)
		);
		// phpcs:enable

		if ( ! $entry || empty( $entry->metadata ) ) {
			return; // Let base handler continue.
		}

		$metadata        = json_decode( $entry->metadata, true );
		$encryption_type = $metadata['encryption_type'] ?? 'aes-rsa-v2';

		// Check if this entry uses pro encryption.
		if ( ! in_array( $encryption_type, array( 'aes-rsa-passkey-v2', 'rsa_passkey_protected' ), true ) ) {
			return; // Free entry, let base handler continue.
		}

		// This is a pro entry - send pro wrapped key and exit.
		$pro_wrapped_key = get_option( 'seculoco_wrapped_private_key_pro' );

		if ( empty( $pro_wrapped_key ) ) {
			wp_send_json_error( 'No PRO private key available' );
			return; // Explicit return, though wp_send_json_error exits.
		}

		// Log this security-sensitive operation.
		$this->log_key_access( get_current_user_id(), 'pro' );

		// Send pro wrapped key - client must authenticate with passkey to unwrap.
		wp_send_json_success(
			array(
				'wrapped_key' => $pro_wrapped_key,
				'type'        => 'pro',
				'message'     => 'PRO key - passkey authentication required',
			)
		);
		// wp_send_json_success calls exit(), so base handler never executes for pro entries.
	}

	/**
	 * Filter: Determine encryption type for frontend form submissions.
	 *
	 * This filter is called when the frontend handler receives encrypted data.
	 * It determines whether the data should be marked as pro-encrypted based on
	 * the current server configuration (pro keys active + passkey registered).
	 *
	 * @since 1.0.0
	 * @param array $encryption_metadata Default encryption metadata.
	 * @param array $metadata Submission metadata (email, name, login_url).
	 * @return array Modified encryption metadata.
	 */
	public function filter_determine_encryption_type( $encryption_metadata, $metadata ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return $encryption_metadata;
		}

		// Check if pro keys are active and passkey is registered.
		$is_pro_active      = get_option( 'seculoco_pro_keys_active', false );
		$passkey_registered = get_option( 'seculoco_passkey_registered', false );

		if ( ! $is_pro_active || ! $passkey_registered ) {
			return $encryption_metadata;
		}

		// Get the passkey credential ID.
		$passkey = get_option( 'seculoco_global_passkey' );
		if ( ! is_array( $passkey ) || empty( $passkey['credential_id'] ) ) {
			return $encryption_metadata;
		}

		// Return pro encryption metadata.
		return array(
			'is_pro_encrypted' => true,
			'credential_id'    => $passkey['credential_id'],
			'encryption_type'  => 'aes-rsa-passkey-v2',
		);
	}

	/**
	 * Filter encryption type based on pro status (legacy support).
	 *
	 * @since 1.0.0
	 * @param string $type Default encryption type.
	 * @return string Encryption type (pro or free).
	 */
	public function filter_encryption_type( $type ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return 'aes-rsa-v2';
		}

		// Check if pro keys are active.
		$is_pro_active      = get_option( 'seculoco_pro_keys_active', false );
		$passkey_registered = get_option( 'seculoco_passkey_registered', false );

		if ( $is_pro_active && $passkey_registered ) {
			return 'aes-rsa-passkey-v2';
		}

		return 'aes-rsa-v2';
	}

	/**
	 * Action: Initialize pro encryption keys with passkey wrapping.
	 *
	 * @since 1.0.0
	 * @param string $passkey_derived_key 32-byte key derived from passkey.
	 * @return void
	 */
	public function action_initialize_pro_keys( $passkey_derived_key ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return;
		}

		// Check admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Call parent method to initialize pro keys.
		$result = $this->initialize_pro_keys( $passkey_derived_key );

		// Log result for debugging.
		if ( is_wp_error( $result ) ) {
			error_log( 'Pro key initialization failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * Action: Delete pro encryption keys.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function action_delete_pro_keys() {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return;
		}

		// Check admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Call parent method to delete pro keys.
		$result = $this->delete_pro_keys();

		// Log result for debugging.
		if ( is_wp_error( $result ) ) {
			error_log( 'Pro key deletion failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * Filter encryption status to add pro information.
	 *
	 * Overrides parent get_status() to add pro status information.
	 *
	 * @since 1.0.0
	 * @param array $status Default status from parent class.
	 * @return array Enhanced status with pro information.
	 */
	public function filter_encryption_status( $status ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return $status;
		}

		// Add pro status information.
		$status['pro'] = array(
			'license_active'     => true,
			'has_public_key'     => ! empty( get_option( 'seculoco_public_key_pro' ) ),
			'has_wrapped_key'    => ! empty( get_option( 'seculoco_wrapped_private_key_pro' ) ),
			'keys_active'        => (bool) get_option( 'seculoco_pro_keys_active', false ),
			'passkey_registered' => (bool) get_option( 'seculoco_passkey_registered', false ),
		);

		return $status;
	}

	/**
	 * Get enhanced status with pro information.
	 *
	 * Overrides parent get_status() to include pro details.
	 *
	 * @since 1.0.0
	 * @return array Complete status including pro information.
	 */
	public static function get_status() {
		// Get base status from parent.
		$status = parent::get_status();

		// Add pro status if license is active.
		if ( Seculoco_License_Manager::has_pro_license() ) {
			$status['pro'] = array(
				'license_active'     => true,
				'has_public_key'     => ! empty( get_option( 'seculoco_public_key_pro' ) ),
				'has_wrapped_key'    => ! empty( get_option( 'seculoco_wrapped_private_key_pro' ) ),
				'keys_active'        => (bool) get_option( 'seculoco_pro_keys_active', false ),
				'passkey_registered' => (bool) get_option( 'seculoco_passkey_registered', false ),
			);
		}

		return $status;
	}

	/**
	 * Initialize pro keys with passkey wrapping.
	 *
	 * Generates RSA keypair for pro encryption and wraps private key with passkey-derived key.
	 *
	 * @since 1.0.0
	 * @param string $passkey_derived_key 32-byte key derived from passkey.
	 * @return array|WP_Error Result of initialization.
	 */
	public function initialize_pro_keys( $passkey_derived_key ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return new WP_Error( 'no_pro_license', 'Pro license required for passkey encryption' );
		}

		// Validate passkey-derived key.
		if ( empty( $passkey_derived_key ) || strlen( $passkey_derived_key ) !== 32 ) {
			return new WP_Error( 'invalid_key', 'Invalid passkey-derived key (must be 32 bytes)' );
		}

		// Check if pro keys already exist.
		$public_key_pro         = get_option( 'seculoco_public_key_pro' );
		$wrapped_private_key_pro = get_option( 'seculoco_wrapped_private_key_pro' );

		if ( $public_key_pro && $wrapped_private_key_pro ) {
			return array(
				'status'  => 'already_initialized',
				'type'    => 'pro',
				'message' => 'Pro keys already exist',
			);
		}

		// Generate new RSA keypair for pro version.
		$keypair = $this->generate_rsa_keypair();
		if ( is_wp_error( $keypair ) ) {
			return $keypair;
		}

		// Store public key (publicly accessible).
		update_option( 'seculoco_public_key_pro', $keypair['public'] );

		// Wrap private key with passkey-derived key using AES-256-GCM.
		$wrapped_key_data = $this->wrap_private_key( $keypair['private'], $passkey_derived_key );
		if ( is_wp_error( $wrapped_key_data ) ) {
			// Cleanup public key if wrapping fails.
			delete_option( 'seculoco_public_key_pro' );
			return $wrapped_key_data;
		}

		// Store wrapped private key.
		update_option( 'seculoco_wrapped_private_key_pro', $wrapped_key_data );

		// Mark pro keys as active.
		update_option( 'seculoco_pro_keys_active', true );

		// Log operation.
		$this->log_key_operation( 'pro_keys_initialized' );

		return array(
			'status'  => 'success',
			'type'    => 'pro',
			'message' => 'Pro keys initialized with passkey wrapping',
		);
	}

	/**
	 * Delete pro encryption keys.
	 *
	 * Removes all pro encryption keys and resets pro status flags.
	 *
	 * @since 1.0.0
	 * @return array|WP_Error Result of deletion.
	 */
	public function delete_pro_keys() {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return new WP_Error( 'no_pro_license', 'Pro license required' );
		}

		// Delete pro keys.
		delete_option( 'seculoco_public_key_pro' );
		delete_option( 'seculoco_wrapped_private_key_pro' );
		delete_option( 'seculoco_pro_keys_active' );

		// Log operation.
		$this->log_key_operation( 'pro_keys_deleted' );

		return array(
			'status'  => 'success',
			'type'    => 'pro',
			'message' => 'Pro keys deleted successfully',
		);
	}

	/**
	 * Wrap private key with passkey-derived key using AES-256-GCM.
	 *
	 * Encrypts the RSA private key with AES-256-GCM for secure storage.
	 * Uses a random 12-byte IV and includes authentication tag.
	 *
	 * @since 1.0.0
	 * @param string $private_key RSA private key to wrap.
	 * @param string $wrapping_key 32-byte wrapping key.
	 * @return array|WP_Error Wrapped key data or error.
	 */
	public function wrap_private_key( $private_key, $wrapping_key ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return new WP_Error( 'no_pro_license', 'Pro license required for key wrapping' );
		}

		// Validate inputs.
		if ( empty( $private_key ) ) {
			return new WP_Error( 'invalid_key', 'Private key cannot be empty' );
		}

		if ( strlen( $wrapping_key ) !== 32 ) {
			return new WP_Error( 'invalid_wrapping_key', 'Wrapping key must be 32 bytes' );
		}

		// Check if OpenSSL is available.
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'openssl_missing', 'OpenSSL extension required' );
		}

		// Generate random 12-byte IV for AES-GCM.
		$iv = random_bytes( 12 );

		// Encrypt private key with AES-256-GCM.
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$private_key,
			'aes-256-gcm',
			$wrapping_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16  // 16-byte authentication tag.
		);

		if ( false === $ciphertext ) {
			return new WP_Error( 'encryption_failed', 'Failed to wrap private key' );
		}

		// Return wrapped key data (all base64 encoded for storage).
		return array(
			'ciphertext' => base64_encode( $ciphertext ),
			'iv'         => base64_encode( $iv ),
			'tag'        => base64_encode( $tag ),
			'algorithm'  => 'aes-256-gcm',
		);
	}

	/**
	 * Check if pro license is active.
	 *
	 * Uses Freemius SDK to verify license status.
	 *
	 * @since 1.0.0
	 * @return bool True if pro license is active.
	 */
	private function is_pro_license_active() {
		// Check if Freemius SDK is available.
		if ( ! class_exists( 'Seculoco_License_Manager' ) ) {
			return false;
		}

		return Seculoco_License_Manager::has_pro_license();
	}
}
