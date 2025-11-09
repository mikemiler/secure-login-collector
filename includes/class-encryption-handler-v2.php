<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Free Encryption Handler - Base Implementation
 *
 * Implements RSA-2048 + AES-256-CBC encryption for the free version.
 * WordPress salts used for key derivation and encryption.
 * Pro version extends this class via hooks to add advanced capabilities.
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'Seculoco_Encryption_Service' ) ) {
	/**
	 * Defines the contract for encryption services (free + premium).
	 */
	interface Seculoco_Encryption_Service {
		/**
		 * Initialize standard (password-based) keys.
		 *
		 * @param string $admin_password Optional admin password for key protection.
		 * @return array|WP_Error Result of key initialization.
		 */
		public function initialize_free_keys( $admin_password = '' );

		/**
		 * Initialize password-based keys via admin-provided password.
		 *
		 * @param string $password Admin password for key wrapping.
		 * @return array|WP_Error Result metadata or error.
		 */
		public function initialize_password_keys( $password );

		/**
		 * Reset password-based encryption keys.
		 *
		 * @return array Result with affected entry counts.
		 */
		public function reset_password_keys();

		/**
		 * Retrieve the active public key.
		 *
		 * @return string|WP_Error Public key or error.
		 */
		public function get_public_key();
	}
}

/**
 * Encryption Handler V2 - Base Class (Free Version)
 *
 * Handles free version encryption: RSA-2048 + AES-256-CBC with WordPress salts.
 * Pro version extends functionality via hooks without modifying base class.
 *
 * EXTENSION POINTS FOR PRO VERSION:
 *
 * 1. Filter: 'seculoco_get_public_key'
 *    - Allows pro version to return pro public key instead of free key
 *    - Parameters: $public_key_free (string)
 *    - Return: Modified public key (string)
 *    - Used in: handle_get_public_key()
 *
 * 2. Action: 'seculoco_get_wrapped_private_key_request'
 *    - Allows pro version to intercept private key requests
 *    - Parameters: $entry_id (int), $nonce (string)
 *    - Pro handler checks entry encryption type and sends JSON response if pro
 *    - Used in: handle_get_wrapped_private_key()
 *
 * ARCHITECTURAL PATTERN:
 * - Base class provides free functionality and extension points (hooks)
 * - Premium file hooks into these extension points to add pro features
 * - No conditional logic or license checks in base class
 * - Clean separation: Freemius SDK strips premium files for free version
 */
class Seculoco_Encryption_Handler_V2 implements Seculoco_Encryption_Service {

	/**
	 * Constructor - Register AJAX handlers and cleanup old keys.
	 */
	public function __construct() {
		// AJAX handlers - using seculoco_ prefix (WordPress.org compliant, 4+ chars).
		add_action( 'wp_ajax_seculoco_get_public_key', array( $this, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_nopriv_seculoco_get_public_key', array( $this, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_seculoco_get_wrapped_private_key', array( $this, 'handle_get_wrapped_private_key' ) );
		add_action( 'wp_ajax_seculoco_initialize_free_keys', array( $this, 'handle_initialize_free_keys' ) );
	}

	/**
	 * Get unified crypto instance.
	 *
	 * @return Secure_Login_Collector_Unified_Crypto Unified crypto instance.
	 */
	protected function get_unified_crypto() {
		$class = apply_filters( 'seculoco_unified_crypto_class', 'Secure_Login_Collector_Unified_Crypto' );

		if ( 'Secure_Login_Collector_Unified_Crypto' === $class && ! class_exists( $class ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-unified-crypto.php';
		}

		return new $class();
	}

	/**
	 * Initialize standard keys (password-based encryption).
	 *
	 * @param string $admin_password Optional admin password for key protection.
	 * @return array|WP_Error Result of key initialization.
	 */
	public function initialize_free_keys( $admin_password = '' ) {
		$unified = $this->get_unified_crypto();
		
		// Check if standard keys already exist.
		if ( $unified->has_keys( 'standard' ) ) {
			// Ensure status flags stay in sync when keys already exist.
			update_option( SECULOCO_OPTION_PASSWORD_ACTIVE, true );
			update_option( SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE, true );

			return array(
				'status' => 'already_initialized',
				'type'   => 'standard',
			);
		}

		// Generate new RSA keypair.
		$keypair = $unified->generate_keypair();
		
		if ( is_wp_error( $keypair ) ) {
			return $keypair;
		}

		// Wrap private key with password.
		if ( empty( $admin_password ) ) {
			// Use temporary password if not provided.
			$admin_password = wp_generate_password( 32, true, true );
		}

		$wrapped_key = $unified->wrap_private_key(
			$keypair['private'],
			'password',
			$admin_password
		);

		if ( is_wp_error( $wrapped_key ) ) {
			return $wrapped_key;
		}

		// Store wrapped key and public key.
		$result = $unified->store_wrapped_key(
			'standard',
			$wrapped_key,
			$keypair['public']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Mark standard encryption as active.
		update_option( SECULOCO_OPTION_PASSWORD_ACTIVE, true );
		update_option( SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE, true );

		// Log initialization.
		$this->log_key_operation( 'standard_keys_initialized' );

		return array(
			'status'  => 'success',
			'type'    => 'standard',
			'message' => 'Standard encryption keys initialized',
		);
	}

	/**
	 * Initialize password-based keys (alias for initialize_free_keys).
	 * Used by AJAX handlers for password setup.
	 *
	 * @param string $password Admin password for key wrapping.
	 * @return array|WP_Error Result metadata or error.
	 */
	public function initialize_password_keys( $password ) {
		$result = $this->initialize_free_keys( $password );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status  = isset( $result['status'] ) ? $result['status'] : 'unknown';
		$success = in_array( $status, array( 'success', 'already_initialized' ), true );

		if ( $success ) {
			update_option( SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE, true );
		}

		return array(
			'success' => $success,
			'status'  => $status,
			'type'    => isset( $result['type'] ) ? $result['type'] : 'standard',
			'message' => isset( $result['message'] ) ? $result['message'] : '',
		);
	}

	/**
	 * Reset password-based encryption keys.
	 * Marks all password-encrypted data as undecryptable.
	 *
	 * @return array Result with affected_entries count.
	 */
	public function reset_password_keys() {
		global $wpdb;
		$table = $wpdb->prefix . 'seculoco_data';

		// Mark all password-encrypted entries as undecryptable.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET undecryptable = 1,
				    undecryptable_at = %s
				WHERE JSON_EXTRACT(metadata, '$.encryption_type') = %s
				  AND undecryptable = 0",
				current_time( 'mysql' ),
				'aes-rsa-password-v3'
			)
		);

		// Delete password keys.
		$unified = $this->get_unified_crypto();
		$unified->delete_keys( 'standard' );

		// Clear password active flag.
		delete_option( SECULOCO_OPTION_PASSWORD_ACTIVE );
		delete_option( SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE );

		// Log operation.
		$this->log_key_operation( 'password_keys_reset', array( 'affected_entries' => $affected ) );

		return array(
			'success'          => true,
			'affected_entries' => $affected,
		);
	}

	/**
	 * Handle AJAX request for public key.
	 *
	 * EXTENSION POINT: Pro version can filter 'seculoco_get_public_key' to return
	 * an alternative key when advanced encryption is active.
	 */
	public function handle_get_public_key() {
		$unified = $this->get_unified_crypto();
		$method  = apply_filters( 'seculoco_public_key_method', 'standard' );
		$last_error = '';
		$public_key = '';

		if ( 'standard' === $method ) {
			$public_key = $unified->get_public_key( 'standard' );

			if ( is_wp_error( $public_key ) ) {
				$last_error = $public_key->get_error_message();
				$public_key = '';

				$should_initialize = apply_filters( 'seculoco_should_initialize_standard_keys', true );

				if ( $should_initialize && current_user_can( 'manage_options' ) ) {
					$result = $this->initialize_free_keys();
					if ( ! is_wp_error( $result ) ) {
						$public_key = $unified->get_public_key( 'standard' );
					}

					if ( is_wp_error( $public_key ) ) {
						$last_error = $public_key->get_error_message();
						$public_key = '';
					}
				}
			}
		}

		/**
		 * Filter the public key to use for encryption.
		 *
		 * Pro version can hook into this filter to return the appropriate key when available.
		 *
		 * @since 1.0.0
		 * @param string $public_key The public key (standard or alternative).
		 * @param string $method     Active encryption method identifier.
		 */

		$public_key = apply_filters( 'seculoco_get_public_key', $public_key, $method );

		if ( empty( $public_key ) ) {
			$message = $last_error ? $last_error : __( 'No encryption key available.', 'secure-login-collector' );
			wp_send_json_error( $message );
			return;
		}

		// Don't expose which key type - frontend doesn't need to know.
		wp_send_json_success(
			array(
				'public_key' => $public_key,
				'algorithm'  => 'RSA-OAEP',
				'key_size'   => 2048,
			)
		);
	}

	/**
	 * Handle request for wrapped private key (admin only).
	 *
	 * Returns wrapped private key data for client-side unwrapping.
	 *
	 * SECURITY NOTE: Server returns wrapped (encrypted) private key to browser.
	 * Client-side decryption using the administrator's password is required.
	 * This is acceptable because:
	 * 1. Only admins with manage_options capability can access
	 * 2. Nonce verification is required
	 * 3. Connection must be over HTTPS
	 * 4. Unwrapping happens client-side (server never sees passwords)
	 *
	 * EXTENSION POINT: Pro version can hook into 'seculoco_get_wrapped_private_key_request'
	 * action to intercept requests and handle advanced authentication flows.
	 * If pro handler sends JSON response (wp_send_json_*), this method exits early.
	 */
	public function handle_get_wrapped_private_key() {
		// Check admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Verify nonce (accept multiple nonce names for compatibility).
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) &&
			! wp_verify_nonce( $nonce, 'seculoco_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		// Get entry_id to determine encryption type.
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		/**
		 * Action hook for passkey key retrieval.
		 *
		 * Pro version hooks here to check if this entry uses passkey encryption.
		 * If yes, pro handler sends JSON response and exits.
		 * If no, execution continues to standard key handling below.
		 *
		 * @since 1.0.0
		 * @param int    $entry_id The entry ID being decrypted.
		 * @param string $nonce    The verified nonce.
		 */
		do_action( 'seculoco_get_wrapped_private_key_request', $entry_id, $nonce );

		// If pro handler sent response, it would have exited by now.
		// Continue with standard key handling.

		$unified = $this->get_unified_crypto();

		// Get wrapped standard key.
		$wrapped_key = $unified->get_wrapped_key( 'standard' );

		if ( is_wp_error( $wrapped_key ) ) {
			wp_send_json_error( 'No private key available: ' . $wrapped_key->get_error_message() );
			return;
		}

		// Log this security-sensitive operation.
		$this->log_key_access( get_current_user_id(), 'standard' );

		// Return wrapped key for client-side unwrapping.
		$wrapped_response = array(
			'wrapped_dek'    => isset( $wrapped_key['encrypted_data'] ) ? $wrapped_key['encrypted_data'] : '',
			'iv'             => isset( $wrapped_key['iv'] ) ? $wrapped_key['iv'] : '',
			'tag'            => isset( $wrapped_key['tag'] ) ? $wrapped_key['tag'] : '',
			'algorithm'      => isset( $wrapped_key['algorithm'] ) ? $wrapped_key['algorithm'] : '',
			'kdf'            => isset( $wrapped_key['kdf'] ) ? $wrapped_key['kdf'] : '',
			'kdf_iterations' => isset( $wrapped_key['kdf_iterations'] ) ? $wrapped_key['kdf_iterations'] : '',
			'kdf_hash'       => isset( $wrapped_key['kdf_hash'] ) ? $wrapped_key['kdf_hash'] : '',
		);

		wp_send_json_success(
			array(
				'wrapped_key' => $wrapped_response,
				'salt'        => isset( $wrapped_key['salt'] ) ? $wrapped_key['salt'] : '',
				'type'        => 'standard',
				'message'     => 'Password required for unwrapping',
			)
		);
	}

	/**
	 * Handle AJAX request to initialize free keys.
	 */
	public function handle_initialize_free_keys() {
		// Check admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Verify nonce.
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		$result = $this->initialize_free_keys();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Log key access for audit trail.
	 *
	 * Marked protected to allow premium class to use it.
	 *
	 * @param int    $user_id User accessing the key.
	 * @param string $type    Type of key accessed (default: 'free').
	 */
	protected function log_key_access( $user_id, $type = 'standard' ) {
		$log = get_option( SECULOCO_OPTION_KEY_ACCESS_LOG, array() );

		// Keep only last 100 entries.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		$log[] = array(
			'user_id'   => $user_id,
			'timestamp' => time(),
			'ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'action'    => 'key_retrieved',
			'type'      => $type,
		);

		update_option( SECULOCO_OPTION_KEY_ACCESS_LOG, $log );
	}

	/**
	 * Log key operations for audit trail.
	 *
	 * Marked protected to allow premium class to use it without reflection.
	 *
	 * @param string $operation Operation performed (e.g., 'free_keys_initialized').
	 * @param array  $metadata Optional metadata to include in log entry.
	 */
	protected function log_key_operation( $operation, $metadata = array() ) {
		$log = get_option( SECULOCO_OPTION_KEY_OPERATIONS_LOG, array() );

		// Keep only last 50 operations.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		$log_entry = array(
			'operation' => $operation,
			'timestamp' => time(),
			'user_id'   => get_current_user_id(),
		);

		// Add metadata if provided.
		if ( ! empty( $metadata ) ) {
			$log_entry['metadata'] = $metadata;
		}

		$log[] = $log_entry;

		update_option( SECULOCO_OPTION_KEY_OPERATIONS_LOG, $log );
	}

	/**
	 * Get the public key (standard encryption).
	 *
	 * @return string|WP_Error Public key or error.
	 */
	public function get_public_key() {
		$unified = $this->get_unified_crypto();
		$public_key = $unified->get_public_key( 'standard' );

		if ( is_wp_error( $public_key ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				$result = $this->initialize_free_keys();
				if ( ! is_wp_error( $result ) ) {
					return $unified->get_public_key( 'standard' );
				}
			}
			return new WP_Error( 'no_public_key', 'Public key not initialized' );
		}

		return $public_key;
	}

	/**
	 * Get system status for encryption (pro status added by premium class).
	 *
	 * @return array Status information.
	 */
	public static function get_status() {
		if ( ! class_exists( 'Secure_Login_Collector_Unified_Crypto' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-unified-crypto.php';
		}
		$unified = new Secure_Login_Collector_Unified_Crypto();

		$status = array(
			'standard' => array(
				'has_public_key'  => $unified->has_keys( 'standard' ),
				'has_wrapped_key' => ! empty( get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD ) ),
				'active'          => (bool) get_option( SECULOCO_OPTION_PASSWORD_ACTIVE, false ),
			),
		);

		return apply_filters( 'seculoco_encryption_status', $status, $unified );
	}
}
