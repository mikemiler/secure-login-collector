<?php
/**
 * Pro Encryption Handler - Premium Features
 *
 * @fs_premium_only
 *
 * Extends base encryption handler to add passkey-wrapping capabilities.
 * Uses WordPress hooks for clean integration with free version.
 *
 * @package SecureLoginCollector
 * @since   1.0.0
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
	 * @since  1.0.0
	 * @return void
	 */
	private function register_pro_hooks() {
		// Filter: Return pro public key when pro is active.
		add_filter( 'seculoco_get_public_key', array( $this, 'filter_public_key' ), 10, 2 );
		add_filter( 'seculoco_public_key_method', array( $this, 'determine_public_key_method' ), 10, 1 );

		// Action: Intercept private key requests to return pro wrapped key.
		add_action( 'seculoco_get_wrapped_private_key_request', array( $this, 'action_handle_pro_key_request' ), 10, 2 );

		// Filter: Determine encryption type for frontend submissions.
		add_filter( 'seculoco_determine_encryption_type', array( $this, 'filter_determine_encryption_type' ), 10, 2 );

		// Surface pro encryption state to shared helpers.
		add_filter( 'seculoco_is_entry_pro_encrypted', array( $this, 'filter_is_entry_pro_encrypted' ), 10, 3 );
		add_filter( 'seculoco_has_passkey_encryption', array( $this, 'has_passkey_encryption' ) );
	}

	/**
	 * Filter public key to return pro key when active.
	 *
	 * @since  1.0.0
	 * @param  string $public_key Default public key (free version).
	 * @return string Pro public key if active, otherwise free key.
	 */
	public function filter_public_key( $public_key, $method ) {
		if ( 'passkey' !== $method ) {
			return $public_key;
		}

		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return $public_key;
		}

		if ( $this->is_passkey_ready() ) {
			$pro_key = get_option( SECULOCO_OPTION_PUBLIC_KEY_PRO );
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
	 * @since  1.0.0
	 * @param  int    $entry_id The entry ID being decrypted.
	 * @param  string $nonce    The verified nonce.
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
		$encryption_type = $metadata['encryption_type'] ?? 'aes-rsa-password-v3';

		// Check if this entry uses pro encryption.
		if ( ! in_array( $encryption_type, array( 'aes-rsa-passkey-v2', 'rsa_passkey_protected' ), true ) ) {
			return; // Free entry, let base handler continue.
		}

		// This is a pro entry - send pro wrapped key and exit.
		$pro_wrapped_key = get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO );

		if ( empty( $pro_wrapped_key ) ) {
			wp_send_json_error( 'No PRO private key available' );
			return; // Explicit return, though wp_send_json_error exits.
		}

		// Blobs written before 2.1.0 use a wrapping the browser cannot unlock any more.
		if ( ! Seculoco_Passkey_Manager::is_current_wrapped_key( $pro_wrapped_key ) ) {
			wp_send_json_error( Seculoco_Passkey_Manager::get_outdated_setup_message() );
			return;
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
	 * @since  1.0.0
	 * @param  array $encryption_metadata Default encryption metadata.
	 * @param  array $metadata            Submission metadata (email, name, login_url).
	 * @return array Modified encryption metadata.
	 */
	public function filter_determine_encryption_type( $encryption_metadata, $metadata ) {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return $encryption_metadata;
		}

		// Pro keys active, passkey registered and a current (2.1.0+) wrapped key stored.
		if ( ! $this->is_passkey_ready() ) {
			return $encryption_metadata;
		}

		// Get the passkey credential ID.
		$passkey = get_option( SECULOCO_OPTION_GLOBAL_PASSKEY );
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
	 * Whether passkey encryption is active.
	 *
	 * @param bool $active Current state.
	 * @return bool
	 */
	public function has_passkey_encryption( $active = false ) {
		if ( $active ) {
			return true;
		}

		if ( ! $this->is_pro_license_active() ) {
			return false;
		}

		return $this->is_passkey_ready();
	}

	/**
	 * Identify whether an entry is pro-encrypted.
	 *
	 * @param bool   $is_pro   Current determination.
	 * @param array  $metadata Entry metadata.
	 * @param object $entry    Database row.
	 * @return bool
	 */
	public function filter_is_entry_pro_encrypted( $is_pro, $metadata, $entry ) {
		if ( $is_pro ) {
			return true;
		}

		if ( ! empty( $metadata['is_pro_encrypted'] ) ) {
			return true;
		}

		$package = json_decode( $entry->encrypted_data ?? '', true );
		return ! empty( $package['isProEncrypted'] );
	}

	/**
	 * Get enhanced status with pro information.
	 *
	 * Overrides parent get_status() to include pro details.
	 *
	 * @since  1.0.0
	 * @return array Complete status including pro information.
	 */
	public static function get_status() {
		// Get base status from parent.
		$status = parent::get_status();

		// Add pro status if license is active.
		if ( Seculoco_License_Manager::has_pro_license() ) {
			$status['pro'] = array(
				'license_active'     => true,
				'has_public_key'     => ! empty( get_option( SECULOCO_OPTION_PUBLIC_KEY_PRO ) ),
				'has_wrapped_key'    => ! empty( get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO ) ),
				'keys_active'        => (bool) get_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, false ),
				'passkey_registered' => (bool) get_option( SECULOCO_OPTION_PASSKEY_REGISTERED, false ),
			);
		}

		return $status;
	}

	/**
	 * Delete pro encryption keys.
	 *
	 * Removes all pro encryption keys and resets pro status flags.
	 *
	 * @since  1.0.0
	 * @return array|WP_Error Result of deletion.
	 */
	public function delete_pro_keys() {
		// Check if pro license is active.
		if ( ! $this->is_pro_license_active() ) {
			return new WP_Error( 'no_pro_license', 'Pro license required' );
		}

		// Delete pro keys.
		delete_option( SECULOCO_OPTION_PUBLIC_KEY_PRO );
		delete_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO );
		delete_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE );

		// Log operation.
		$this->log_key_operation( 'pro_keys_deleted' );

		return array(
			'status'  => 'success',
			'type'    => 'pro',
			'message' => 'Pro keys deleted successfully',
		);
	}

	/**
	 * Determine if passkey encryption has been provisioned.
	 *
	 * @return bool
	 */
	private function is_passkey_ready() {
		return Seculoco_Passkey_Manager::is_setup_ready();
	}

	/**
	 * Check if pro license is active.
	 *
	 * Uses Freemius SDK to verify license status.
	 *
	 * @since  1.0.0
	 * @return bool True if pro license is active.
	 */
	private function is_pro_license_active() {
		// Check if Freemius SDK is available.
		if ( ! class_exists( 'Seculoco_License_Manager' ) ) {
			return false;
		}

		return Seculoco_License_Manager::has_pro_license();
	}

	/**
	 * Choose the public key method (standard/passkey).
	 *
	 * @param string $method Current method.
	 * @return string
	 */
	public function determine_public_key_method( $method ) {
		if ( $this->is_passkey_ready() && $this->is_pro_license_active() ) {
			return 'passkey';
		}

		return $method;
	}
}
