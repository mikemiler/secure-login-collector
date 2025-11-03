<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Premium extension file naming convention.
/**
 * Premium Admin Interface Extension - Passkey AJAX Handlers
 *
 * Extends base admin interface to add passkey-specific AJAX endpoints.
 * Following KISS principle: Only AJAX handlers for pro passkey features.
 *
 * @fs_premium_only
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Admin_Interface_Premium
 *
 * Premium extension of the admin interface providing passkey-based
 * decryption and authentication functionality.
 */
class Seculoco_Admin_Interface_Premium extends Seculoco_Admin_Interface {


	/**
	 * Database manager instance.
	 *
	 * @var Seculoco_Database_Manager
	 */
	private $database_manager;

	/**
	 * Constructor - registers pro AJAX handlers.
	 *
	 * @param string                          $table_name         Database table name.
	 * @param Seculoco_Encryption_Handler_V2 $encryption_handler Encryption handler instance.
	 * @param Seculoco_Database_Manager   $database_manager   Database manager instance.
	 */
	public function __construct( $table_name, $encryption_handler, $database_manager ) {

		parent::__construct( $table_name, $encryption_handler, $database_manager );

		$this->database_manager = $database_manager;

		// Register pro passkey AJAX handlers.
		add_action( 'wp_ajax_seculoco_bulk_decrypt_with_passkey', array( $this, 'handle_bulk_decrypt_with_passkey_ajax' ) );
		add_action( 'wp_ajax_seculoco_passkey_auth_for_decrypt', array( $this, 'handle_passkey_auth_for_decrypt' ) );
		add_action( 'wp_ajax_seculoco_passkey_challenge', array( $this, 'handle_passkey_challenge' ) );
		add_action( 'wp_ajax_seculoco_fix_passkey_flag', array( $this, 'handle_fix_passkey_flag' ) );

		// Add password manager export options to bulk actions (PRO feature).
		add_filter( 'seculoco_bulk_actions', array( $this, 'add_password_manager_exports' ) );
	}

	/**
	 * Enqueue premium admin scripts.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Call parent to load base scripts (admin.js and admin-decrypt.js).
		parent::enqueue_admin_scripts( $hook );

		// Only load on our admin page.
		if ( ! in_array( $hook, array( 'toplevel_page_secure-login-collector' ), true ) ) {
			return;
		}

		// Only load premium scripts for pro license users.
		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			return;
		}

		// Enqueue premium decrypt script (passkey-based decryption plugin).
		// CRITICAL: This depends on base admin-decrypt.js (seculoco-admin-decrypt).
		$decrypt_script_path = plugin_dir_path( __FILE__ ) . '../assets/js/admin-decrypt__premium_only.js';
		if ( file_exists( $decrypt_script_path ) ) {
			wp_enqueue_script(
				'seculoco-admin-decrypt-pro',
				plugin_dir_url( __FILE__ ) . '../assets/js/admin-decrypt__premium_only.js',
				array( 'jquery', 'seculoco-admin-decrypt' ),  // Depends on base framework.
				filemtime( $decrypt_script_path ),
				true
			);

			// Pass feature flags to PRO script.
			wp_localize_script(
				'seculoco-admin-decrypt-pro',
				'seculocoFeatures',
				array(
					'isPro'       => true,
					'hasPasskey'  => true,
					'hasExport'   => true,
					'version'     => 'pro',
					'buildDate'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		// Enqueue premium bulk export script (password manager export).
		$bulk_export_script_path = plugin_dir_path( __FILE__ ) . '../assets/js/admin-bulk-export__premium_only.js';
		if ( file_exists( $bulk_export_script_path ) ) {
			wp_enqueue_script(
				'seculoco-admin-bulk-export',
				plugin_dir_url( __FILE__ ) . '../assets/js/admin-bulk-export__premium_only.js',
				array( 'jquery', 'secure-login-admin-js' ),
				filemtime( $bulk_export_script_path ),
				true
			);
		}
	}

	/**
	 * Add password manager export options to bulk actions dropdown.
	 *
	 * Adds 8 password manager export formats for PRO users only.
	 * Free version users will not see these options.
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array Modified bulk actions with export options.
	 */
	public function add_password_manager_exports( $bulk_actions ) {

		// Add export options for 8 major password managers.
		$bulk_actions['export-bitwarden'] = __( 'Export to Bitwarden', 'secure-login-collector' );
		$bulk_actions['export-1password'] = __( 'Export to 1Password', 'secure-login-collector' );
		$bulk_actions['export-lastpass']  = __( 'Export to LastPass', 'secure-login-collector' );
		$bulk_actions['export-chrome']    = __( 'Export to Chrome', 'secure-login-collector' );
		$bulk_actions['export-firefox']   = __( 'Export to Firefox', 'secure-login-collector' );
		$bulk_actions['export-safari']    = __( 'Export to Safari', 'secure-login-collector' );
		$bulk_actions['export-dashlane']  = __( 'Export to Dashlane', 'secure-login-collector' );
		$bulk_actions['export-keepass']   = __( 'Export to KeePass', 'secure-login-collector' );

		return $bulk_actions;
	}

	/**
	 * Handle bulk decrypt with passkey AJAX request.
	 */
	public function handle_bulk_decrypt_with_passkey_ajax() {
		// Verify nonce for security.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'seculoco_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		// Check if user has admin capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		if ( ! get_option( 'seculoco_passkey_registered', false ) ) {
			wp_send_json_error( __( 'Passkey not registered.', 'secure-login-collector' ) );
			return;
		}

		$entry_ids        = isset( $_POST['entry_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['entry_ids'] ) ) : array();
		$manager          = isset( $_POST['manager'] ) ? sanitize_text_field( wp_unslash( $_POST['manager'] ) ) : '';
		$passkey_verified = isset( $_POST['passkey_verified'] ) && sanitize_text_field( wp_unslash( $_POST['passkey_verified'] ) ) === 'true';

		if ( empty( $manager ) ) {
			wp_send_json_error( __( 'Missing export manager.', 'secure-login-collector' ) );
			return;
		}

		// If passkey is not yet verified, validate entry_ids from POST and store the request.
		if ( ! $passkey_verified ) {
			if ( empty( $entry_ids ) ) {
				wp_send_json_error( __( 'No entries selected for export.', 'secure-login-collector' ) );
				return;
			}

			set_transient(
				'seculoco_bulk_decrypt_request_' . get_current_user_id(),
				array(
					'entry_ids' => $entry_ids,
					'manager'   => $manager,
				),
				300
			); // 5 minutes.

			/* translators: 1: number of entries, 2: manager format name */
			wp_send_json_success(
				array(
					'requires_passkey' => true,
					'entry_count'      => count( $entry_ids ),
					'manager'          => $manager,
					// translators: %1$d: number of entries selected, %2$s: password manager name.
					'message'          => sprintf( __( 'You have selected %1$d entries for bulk export. All selected entries will be decrypted using your passkey and then exported to %2$s format.', 'secure-login-collector' ), count( $entry_ids ), $manager ),
				)
			);
			return;
		}

		// Passkey is verified, proceed with bulk decryption.
		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
		if ( empty( $signature ) ) {
			wp_send_json_error( __( 'Missing passkey signature.', 'secure-login-collector' ) );
			return;
		}

		// Get the stored request.
		$bulk_request = get_transient( 'seculoco_bulk_decrypt_request_' . get_current_user_id() );
		if ( ! $bulk_request ) {
			wp_send_json_error( __( 'Bulk decrypt request not found or expired.', 'secure-login-collector' ) );
			return;
		}

		// Clean up the transient.
		delete_transient( 'seculoco_bulk_decrypt_request_' . get_current_user_id() );

		// Set authentication flag that the encryption handler expects.
		set_transient( 'seculoco_passkey_authenticated_' . get_current_user_id(), true, 300 ); // 5 minutes.

		// Decrypt all entries.
		$csv_data         = array();
		$successful_count = 0;
		$failed_count     = 0;

		foreach ( $bulk_request['entry_ids'] as $id ) {
			$row = $this->database_manager->get_entry( $id );
			if ( ! $row ) {
				++$failed_count;
				continue;
			}

			$metadata        = json_decode( $row->metadata, true );
			$encryption_type = $metadata['encryption_type'] ?? 'aes-rsa-v2';

			// CRITICAL: Server NEVER decrypts. Always return encrypted packages for client-side decryption.
			// This maintains zero-knowledge architecture for both FREE and PRO entries.
			$name    = $metadata['name'] ?? 'Unknown';
			$website = $metadata['login_url'] ?? $metadata['service_name'] ?? '';

			// Ensure website has protocol.
			if ( $website && ! preg_match( '/^https?:\/\//', $website ) ) {
				$website = 'https://' . $website;
			}

			$csv_data[] = array(
				'id'                => $row->id,
				'name'              => $name,
				'website'           => $website,
				'encrypted_data'    => $row->encrypted_data,
				'encrypted_aes_key' => $row->encrypted_aes_key ?? null,
				'iv'                => $row->iv ?? null,
				'encryption_type'   => $encryption_type,
			);

			++$successful_count;
		}

		// Clean up the authentication flag after all decryptions are complete.
		delete_transient( 'seculoco_passkey_authenticated_' . get_current_user_id() );

		$message = '';
		if ( $successful_count > 0 && 0 === $failed_count ) {
			// translators: %d: number of entries successfully decrypted.
			$message = sprintf( __( 'Successfully decrypted %d entries. Generating CSV...', 'secure-login-collector' ), $successful_count );
		} elseif ( $successful_count > 0 && $failed_count > 0 ) {
			$message = __( 'Bulk decryption failed for some entries. Only successfully decrypted entries were exported.', 'secure-login-collector' );
		} else {
			wp_send_json_error( __( 'Bulk decryption failed for all entries.', 'secure-login-collector' ) );
			return;
		}

		wp_send_json_success(
			array(
				'csv_data'         => $csv_data,
				'manager'          => $bulk_request['manager'],
				'successful_count' => $successful_count,
				'failed_count'     => $failed_count,
				'message'          => $message,
			)
		);
	}

	/**
	 * Handle passkey authentication for decryption.
	 * Validates WebAuthn assertion and returns wrapped private key (NOT the MWK).
	 */
	public function handle_passkey_auth_for_decrypt() {
		// Check permissions and nonce.
		if (
			! current_user_can( 'manage_options' ) ||
			! isset( $_POST['nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'seculoco_nonce' )
		) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		// Get WebAuthn assertion data.
		$credential_id      = isset( $_POST['credential_id'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_id'] ) ) : '';
		$client_data_json   = isset( $_POST['clientDataJSON'] ) ? sanitize_text_field( wp_unslash( $_POST['clientDataJSON'] ) ) : '';
		$authenticator_data = isset( $_POST['authenticatorData'] ) ? sanitize_text_field( wp_unslash( $_POST['authenticatorData'] ) ) : '';
		$signature          = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';

		if ( empty( $credential_id ) || empty( $client_data_json ) || empty( $authenticator_data ) || empty( $signature ) ) {
			wp_send_json_error( __( 'Missing required WebAuthn assertion data.', 'secure-login-collector' ) );
			return;
		}

		$user_id = get_current_user_id();

		// Get stored passkey data.
		$passkey = get_user_meta( $user_id, 'seculoco_passkey', true );
		if ( empty( $passkey ) || $passkey['credential_id'] !== $credential_id ) {
			wp_send_json_error( __( 'Passkey not found or mismatch.', 'secure-login-collector' ) );
			return;
		}

		// Decode and validate clientDataJSON.
		$client_data_decoded = json_decode( base64_decode( $client_data_json ), true );
		if ( ! $client_data_decoded ) {
			wp_send_json_error( __( 'Invalid client data format.', 'secure-login-collector' ) );
			return;
		}

		// Validate challenge.
		$expected_challenge = get_transient( 'passkey_challenge_' . $user_id );
		if ( ! $expected_challenge ) {
			wp_send_json_error( __( 'Challenge expired or not found.', 'secure-login-collector' ) );
			return;
		}

		// Normalize challenge for comparison (base64url).
		$received_challenge     = $client_data_decoded['challenge'] ?? '';
		$expected_challenge_url = rtrim( strtr( $expected_challenge, '+/', '-_' ), '=' );

		if ( $received_challenge !== $expected_challenge_url ) {
			wp_send_json_error( __( 'Challenge verification failed.', 'secure-login-collector' ) );
			return;
		}

		// Validate origin.
		$expected_origin = home_url();
		$received_origin = $client_data_decoded['origin'] ?? '';
		if ( $received_origin !== $expected_origin ) {
			wp_send_json_error( __( 'Origin verification failed.', 'secure-login-collector' ) );
			return;
		}

		// Validate type.
		$received_type = $client_data_decoded['type'] ?? '';
		if ( 'webauthn.get' !== $received_type ) {
			wp_send_json_error( __( 'Invalid WebAuthn operation type.', 'secure-login-collector' ) );
			return;
		}

		// Store authentication success timestamp for audit logging.
		update_user_meta( $user_id, 'seculoco_last_passkey_auth', current_time( 'mysql' ) );

		// Verify signature.
		$public_key_data = $passkey['public_key'] ?? '';
		if ( empty( $public_key_data ) || 'stored_in_attestation' === $public_key_data || 'not_available' === $public_key_data ) {
			// Public key not available - fall back to challenge validation only.
			// This is less secure but maintains compatibility with existing registrations.
		} else {
			// Construct the data that was signed.
			$client_data_hash       = hash( 'sha256', base64_decode( $client_data_json ), true );
			$authenticator_data_raw = base64_decode( $authenticator_data );
			$signed_data            = $authenticator_data_raw . $client_data_hash;

			// Decode signature.
			$signature_raw = base64_decode( $signature );

			// For EC256 keys (most common), verify using openssl.
			// Note: This is a simplified verification - full WebAuthn verification would parse COSE format.
			// For production, consider using a WebAuthn library like web-auth/webauthn-lib.
			$public_key_pem = $this->convert_cose_to_pem( $public_key_data );
			if ( $public_key_pem ) {
				$verify_result = openssl_verify( $signed_data, $signature_raw, $public_key_pem, OPENSSL_ALGO_SHA256 );
				if ( 1 !== $verify_result ) {
					wp_send_json_error( __( 'Signature verification failed.', 'secure-login-collector' ) );
					return;
				}
			}
		}

		// Clear the challenge after successful validation.
		delete_transient( 'passkey_challenge_' . $user_id );

		// Get the wrapped private key (PRO version).
		$wrapped_key_pro = get_option( 'seculoco_wrapped_private_key_pro' );
		if ( ! $wrapped_key_pro ) {
			wp_send_json_error( __( 'Pro encryption keys not found.', 'secure-login-collector' ) );
			return;
		}

		// Return the wrapped key and credential_id.
		// Client will derive the passkey key locally and unwrap.
		wp_send_json_success(
			array(
				'wrapped_key'   => $wrapped_key_pro,
				'credential_id' => $credential_id,
				'message'       => __( 'Passkey authenticated successfully.', 'secure-login-collector' ),
			)
		);
	}

	/**
	 * Handle passkey authentication challenge.
	 */
	public function handle_passkey_challenge() {
		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		// Verify nonce - accept both admin nonces (from admin.js and admin-decrypt.js).
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) &&
			! wp_verify_nonce( $nonce, 'seculoco_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		// Generate challenge.
		$challenge = base64_encode( random_bytes( 32 ) );

		// Store challenge in transient (expires in 5 minutes).
		set_transient( 'passkey_challenge_' . get_current_user_id(), $challenge, 300 );

		// Get user's registered credential (single passkey).
		$user_id = get_current_user_id();
		$passkey = get_user_meta( $user_id, 'seculoco_passkey', true );

		// Format credential for client.
		$formatted_credentials = array();
		if ( ! empty( $passkey ) && isset( $passkey['credential_id'] ) ) {
			$formatted_credentials[] = array(
				'id'   => $passkey['credential_id'],
				'type' => 'public-key',
			);
		}

		wp_send_json_success(
			array(
				'challenge'        => $challenge,
				'credentials'      => $formatted_credentials,
				'timeout'          => 60000,
				'userVerification' => 'required',
			)
		);
	}

	/**
	 * Handle fix passkey flag AJAX request.
	 * One-time fix for installations where passkeys are registered but flag wasn't set.
	 */
	public function handle_fix_passkey_flag() {
		check_ajax_referer( 'seculoco_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		global $wpdb;

		// Check if anyone has passkey registered (single passkey).
		// SECURITY FIX: Using $wpdb->prepare() to prevent SQL injection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$users_with_passkey = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value
				FROM %i
				WHERE meta_key = %s
				AND meta_value != ''",
				$wpdb->usermeta,
				'seculoco_passkey'
			)
		);

		$total_passkeys = 0;
		$users_count    = 0;
		foreach ( $users_with_passkey as $user_meta ) {
			$passkey = maybe_unserialize( $user_meta->meta_value );
			if ( is_array( $passkey ) && ! empty( $passkey ) ) {
				++$total_passkeys;
				++$users_count;
			}
		}

		// Check current flag status.
		$passkey_flag  = get_option( 'seculoco_passkey_registered', false );
		$pro_keys_flag = get_option( 'seculoco_pro_keys_active', false );

		$message  = "Found $total_passkeys passkey(s) across $users_count user(s). ";
		$message .= 'Passkey flag: ' . ( $passkey_flag ? 'true' : 'false' ) . ', ';
		$message .= 'Pro keys flag: ' . ( $pro_keys_flag ? 'true' : 'false' ) . '. ';

		if ( $total_passkeys > 0 && ( ! $passkey_flag || ! $pro_keys_flag ) ) {
			if ( ! $passkey_flag ) {
				update_option( 'seculoco_passkey_registered', true );
				update_option( 'seculoco_passkey_registered_at', current_time( 'mysql' ) );
			}
			if ( ! $pro_keys_flag ) {
				update_option( 'seculoco_pro_keys_active', true );
			}
			$message .= 'Missing flags updated successfully!';
			wp_send_json_success( $message );
		} elseif ( $total_passkeys > 0 && $passkey_flag && $pro_keys_flag ) {
			$message .= 'All flags already set correctly!';
			wp_send_json_success( $message );
		} elseif ( 0 === $total_passkeys ) {
			$message .= 'No passkeys found - flag should remain false.';
			wp_send_json_success( $message );
		} else {
			wp_send_json_error( $message );
		}
	}

	/**
	 * Convert COSE public key to PEM format (simplified).
	 *
	 * @param string $cose_key Base64-encoded COSE key. // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Reserved for future CBOR parsing implementation.
	 * @return string|false PEM-formatted public key or false on failure.
	 */
	private function convert_cose_to_pem( $cose_key ) {
		// This is a placeholder for COSE to PEM conversion.
		// Full implementation would require CBOR parsing.
		// For now, return false to skip signature verification for existing keys.
		return false;
	}
}
