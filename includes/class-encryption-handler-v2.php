<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Free Encryption Handler - Base Implementation
 *
 * Implements RSA-2048 + AES-256-CBC encryption for free version.
 * WordPress salts used for key derivation and encryption.
 * Pro version extends this class to add passkey-wrapping capabilities.
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
class Seculoco_Encryption_Handler_V2 {

	/**
	 * Constructor - Register AJAX handlers.
	 */
	public function __construct() {
		// AJAX handlers - using seculoco_ prefix (WordPress.org compliant, 4+ chars).
		add_action( 'wp_ajax_seculoco_get_public_key', array( $this, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_nopriv_seculoco_get_public_key', array( $this, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_seculoco_get_wrapped_private_key', array( $this, 'handle_get_wrapped_private_key' ) );
		add_action( 'wp_ajax_seculoco_initialize_free_keys', array( $this, 'handle_initialize_free_keys' ) );
		add_action( 'wp_ajax_seculoco_export_public_key', array( $this, 'handle_export_public_key' ) );
	}

	/**
	 * Generate RSA key pair (2048-bit).
	 *
	 * Marked protected to allow premium class to use it without reflection.
	 *
	 * @return array|WP_Error Array with 'public' and 'private' keys, or error.
	 */
	protected function generate_rsa_keypair() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return new WP_Error( 'openssl_missing', 'OpenSSL extension required' );
		}

		$config = array(
			'digest_alg'       => 'sha256',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);

		$keypair = openssl_pkey_new( $config );
		if ( ! $keypair ) {
			return new WP_Error( 'generation_failed', 'Failed to generate RSA keypair' );
		}

		// Extract private key.
		openssl_pkey_export( $keypair, $private_key );

		// Extract public key.
		$details = openssl_pkey_get_details( $keypair );

		return array(
			'public'  => $details['key'],
			'private' => $private_key,
		);
	}

	/**
	 * Initialize free version keys (standard RSA without passkey).
	 *
	 * @return array|WP_Error Result of key initialization.
	 */
	public function initialize_free_keys() {
		// Check if free keys already exist.
		$public_key_free            = get_option( 'seculoco_public_key_free' );
		$private_key_free_encrypted = get_option( 'seculoco_private_key_free_encrypted' );

		if ( $public_key_free && $private_key_free_encrypted ) {
			return array(
				'status' => 'already_initialized',
				'type'   => 'free',
			);
		}

		// Generate new RSA keypair for free version.
		$keypair = $this->generate_rsa_keypair();
		if ( is_wp_error( $keypair ) ) {
			return $keypair;
		}

		// Store public key (publicly accessible).
		update_option( 'seculoco_public_key_free', $keypair['public'] );

		// Encrypt private key with WordPress salts for free version.
		$encrypted_private = $this->encrypt_with_wp_salts( $keypair['private'] );
		update_option( 'seculoco_private_key_free_encrypted', $encrypted_private );

		// Log initialization.
		$this->log_key_operation( 'free_keys_initialized' );

		return array(
			'status'  => 'success',
			'type'    => 'free',
			'message' => 'Free version keys initialized',
		);
	}

	/**
	 * Encrypt data with WordPress salts (for free version).
	 *
	 * Uses AUTH_KEY and SECURE_AUTH_KEY for derivation.
	 * Returns AES-256-CBC encrypted data with IV.
	 *
	 * @param string $data Data to encrypt.
	 * @return array Encrypted data with IV (base64 encoded).
	 */
	private function encrypt_with_wp_salts( $data ) {
		$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		$iv  = random_bytes( 16 );

		$encrypted = openssl_encrypt(
			$data,
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		return array(
			'data' => base64_encode( $encrypted ),
			'iv'   => base64_encode( $iv ),
		);
	}

	/**
	 * Decrypt data with WordPress salts (for free version).
	 *
	 * @param array $encrypted_data Encrypted data with IV (base64 encoded).
	 * @return string|false Decrypted data or false on error.
	 */
	private function decrypt_with_wp_salts( $encrypted_data ) {
		$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );

		return openssl_decrypt(
			base64_decode( $encrypted_data['data'] ),
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA,
			base64_decode( $encrypted_data['iv'] )
		);
	}

	/**
	 * Handle AJAX request for public key.
	 *
	 * Returns PRO key if available (passkey registered), otherwise FREE key.
	 * Frontend does NOT know which type it received - keeps PRO/FREE status private.
	 *
	 * EXTENSION POINT: Pro version can filter 'seculoco_get_public_key' to return
	 * pro key instead of free key when pro keys are active.
	 */
	public function handle_get_public_key() {
		// Get free version public key.
		$public_key_free = get_option( 'seculoco_public_key_free' );

		// Initialize free keys if not exists.
		if ( ! $public_key_free ) {
			$result = $this->initialize_free_keys();
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( 'Failed to initialize encryption keys: ' . $result->get_error_message() );
				return;
			}
			$public_key_free = get_option( 'seculoco_public_key_free' );
		}

		/**
		 * Filter the public key to use for encryption.
		 *
		 * Pro version can hook into this filter to return pro key when available.
		 *
		 * @since 1.0.0
		 * @param string $public_key_free The free version public key (default).
		 */
		$public_key = apply_filters( 'seculoco_get_public_key', $public_key_free );

		if ( empty( $public_key ) ) {
			wp_send_json_error( 'No encryption key available' );
			return;
		}

		// Don't expose which key type - frontend doesn't need to know.
		wp_send_json_success(
			array(
				'public_key' => $public_key,
				'algorithm'  => 'RSA-OAEP',
				'key_size'   => 2048,
				// NO 'type' field - keep PRO/FREE status private.
			)
		);
	}

	/**
	 * Handle request for wrapped private key (admin only).
	 *
	 * Returns free private key (base64 encoded only).
	 *
	 * SECURITY NOTE: Free version returns base64-encoded private key to browser.
	 * This is acceptable because:
	 * 1. Only admins with manage_options capability can access
	 * 2. Nonce verification is required
	 * 3. Connection must be over HTTPS
	 * 4. Decryption happens client-side (server never sees passwords)
	 *
	 * EXTENSION POINT: Pro version can hook into 'seculoco_get_wrapped_private_key_request'
	 * action to intercept pro key requests and handle them with passkey authentication.
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
		 * Action hook for pro key retrieval.
		 *
		 * Pro version hooks here to check if this entry uses pro encryption.
		 * If yes, pro handler sends JSON response and exits.
		 * If no, execution continues to free key handling below.
		 *
		 * @since 1.0.0
		 * @param int    $entry_id The entry ID being decrypted.
		 * @param string $nonce    The verified nonce.
		 */
		do_action( 'seculoco_get_wrapped_private_key_request', $entry_id, $nonce );

		// If pro handler sent response, it would have exited by now.
		// Continue with free key handling.

		// FREE: Return private key decrypted with WP salts.
		$encrypted_key = get_option( 'seculoco_private_key_free_encrypted' );

		if ( ! $encrypted_key ) {
			wp_send_json_error( 'No private key available' );
			return;
		}

		// For free version, decrypt with WP salts and return.
		$private_key = $this->decrypt_with_wp_salts( $encrypted_key );
		if ( ! $private_key ) {
			wp_send_json_error( 'Failed to decrypt private key' );
			return;
		}

		// Log this security-sensitive operation.
		$this->log_key_access( get_current_user_id(), 'free' );

		// Return as a "wrapped" format for consistency.
		wp_send_json_success(
			array(
				'private_key' => base64_encode( $private_key ),
				'type'        => 'free',
				'message'     => 'Free version key - no passkey required',
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
	 * Handle AJAX request to export public key.
	 */
	public function handle_export_public_key() {
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

		$public_key = get_option( 'seculoco_public_key_free' );

		if ( ! $public_key ) {
			wp_send_json_error( 'No public key found' );
			return;
		}

		wp_send_json_success(
			array(
				'public_key' => $public_key,
				'type'       => 'free',
			)
		);
	}

	/**
	 * Log key access for audit trail.
	 *
	 * Marked protected to allow premium class to use it.
	 *
	 * @param int    $user_id User accessing the key.
	 * @param string $type    Type of key accessed (default: 'free').
	 */
	protected function log_key_access( $user_id, $type = 'free' ) {
		$log = get_option( 'seculoco_key_access_log', array() );

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

		update_option( 'seculoco_key_access_log', $log );
	}

	/**
	 * Log key operations for audit trail.
	 *
	 * Marked protected to allow premium class to use it without reflection.
	 *
	 * @param string $operation Operation performed (e.g., 'free_keys_initialized').
	 */
	protected function log_key_operation( $operation ) {
		$log = get_option( 'seculoco_key_operations_log', array() );

		// Keep only last 50 operations.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		$log[] = array(
			'operation' => $operation,
			'timestamp' => time(),
			'user_id'   => get_current_user_id(),
		);

		update_option( 'seculoco_key_operations_log', $log );
	}

	/**
	 * Get the public key.
	 *
	 * Initializes free keys if they don't exist (admin only).
	 *
	 * @return string|WP_Error Public key or error.
	 */
	public function get_public_key() {
		$public_key_free = get_option( 'seculoco_public_key_free' );

		if ( ! $public_key_free ) {
			// Try to initialize free keys if admin.
			if ( current_user_can( 'manage_options' ) ) {
				$result = $this->initialize_free_keys();
				if ( ! is_wp_error( $result ) ) {
					return get_option( 'seculoco_public_key_free' );
				}
			}
			return new WP_Error( 'no_public_key', 'Public key not initialized' );
		}

		return $public_key_free;
	}

	/**
	 * Check if PRO encryption is active.
	 *
	 * @return bool True if PRO keys are active.
	 */
	public static function is_pro_active() {
		return (bool) get_option( 'seculoco_pro_keys_active', false );
	}

	/**
	 * Get system status for free version (pro status added by premium class).
	 *
	 * @return array Status information for free version.
	 */
	public static function get_status() {
		return array(
			'free' => array(
				'has_public_key'  => ! empty( get_option( 'seculoco_public_key_free' ) ),
				'has_private_key' => ! empty( get_option( 'seculoco_private_key_free_encrypted' ) ),
			),
		);
	}
}
