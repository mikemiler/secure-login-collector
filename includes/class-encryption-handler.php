<?php
/**
 * Secure Encryption Handler with Passkey-Wrapped Keys
 * 
 * Implements the safest practical encryption:
 * 1. Client encrypts with server's RSA public key
 * 2. RSA private key is wrapped with passkey-derived key
 * 3. Only admin with passkey can unwrap and decrypt
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Secure_Login_Encryption_Handler {

	/**
	 * Master Key Manager instance.
	 *
	 * @var Master_Key_Manager
	 */
	private $master_key_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! class_exists( 'Master_Key_Manager' ) ) {
			require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-master-key-manager.php';
		}
		$this->master_key_manager = new Master_Key_Manager();

		// AJAX handlers
		add_action( 'wp_ajax_slc_get_public_key', array( $this, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_nopriv_slc_get_public_key', array( $this, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_slc_initialize_keys', array( $this, 'handle_initialize_keys' ) );
		add_action( 'wp_ajax_slc_get_wrapped_private_key', array( $this, 'handle_get_wrapped_private_key' ) );
		// Legacy handler for settings page
		add_action( 'wp_ajax_generate_rsa_keys', array( $this, 'handle_generate_rsa_keys' ) );
	}

	/**
	 * Initialize RSA keys with passkey wrapping during first passkey registration.
	 * This is called when admin registers their first passkey.
	 *
	 * @param string $passkey_derived_key Key derived from passkey authentication.
	 * @return array|WP_Error Result of key initialization.
	 */
	public function initialize_keys_with_passkey( $passkey_derived_key ) {
		// Check if keys already exist
		$public_key = get_option( 'secure_login_public_key' );
		$wrapped_key = get_option( 'secure_login_wrapped_private_key' );
		
		// If we have both public and wrapped keys, system is already initialized
		if ( $public_key && $wrapped_key ) {
			// Check if this is a valid wrapped key structure
			// The wrapped key is stored as an array, not JSON
			$wrapped_data = $wrapped_key;
			// Check for 'encrypted' key (correct) not 'encrypted_key' (wrong)
			if ( is_array( $wrapped_data ) && isset( $wrapped_data['encrypted'] ) ) {
				return array( 'status' => 'already_initialized' );
			}
			// Invalid wrapped key, clear it and continue
			delete_option( 'secure_login_wrapped_private_key' );
		}
		
		if ( $public_key ) {
			// We have a public key but no valid wrapped private key
			// Try to migrate from old encrypted format
			$encrypted_private = get_option( 'secure_login_private_key_encrypted' );
			if ( $encrypted_private ) {
				// Decrypt with old method first
				$private_key = $this->decrypt_private_key_legacy( $encrypted_private );
				if ( ! $private_key ) {
					// Can't decrypt old key - need to regenerate
					delete_option( 'secure_login_public_key' );
					delete_option( 'secure_login_private_key_encrypted' );
					// Fall through to generate new keys
				} else {
					// Successfully decrypted - wrap with passkey
					$wrap_result = $this->wrap_private_key( $private_key, $passkey_derived_key );
					if ( ! is_wp_error( $wrap_result ) ) {
						// Migration successful
						return array( 'status' => 'migrated', 'message' => 'Existing keys migrated to passkey encryption' );
					}
					// Wrapping failed, fall through to generate new keys
				}
			} else {
				// Have public key but no private key at all - inconsistent state
				// Clear and regenerate
				delete_option( 'secure_login_public_key' );
			}
		}

		// Generate new RSA keypair
		$keypair = $this->generate_rsa_keypair();
		if ( is_wp_error( $keypair ) ) {
			return $keypair;
		}

		// Store public key (publicly accessible)
		update_option( 'secure_login_public_key', $keypair['public'] );

		// Wrap private key with passkey-derived key
		return $this->wrap_private_key( $keypair['private'], $passkey_derived_key );
	}

	/**
	 * Generate RSA key pair.
	 *
	 * @return array|WP_Error Array with 'public' and 'private' keys.
	 */
	private function generate_rsa_keypair() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return new WP_Error( 'openssl_missing', 'OpenSSL extension required' );
		}

		$config = array(
			'digest_alg'       => 'sha256',
			'private_key_bits' => 2048, // 2048 is sufficient and faster
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);

		$keypair = openssl_pkey_new( $config );
		if ( ! $keypair ) {
			return new WP_Error( 'generation_failed', 'Failed to generate RSA keypair' );
		}

		// Extract private key
		openssl_pkey_export( $keypair, $private_key );

		// Extract public key
		$details = openssl_pkey_get_details( $keypair );
		
		return array(
			'public'  => $details['key'],
			'private' => $private_key,
		);
	}

	/**
	 * Wrap RSA private key with passkey-derived key.
	 *
	 * @param string $private_key RSA private key in PEM format.
	 * @param string $passkey_key Key derived from passkey authentication.
	 * @return array|WP_Error Wrapped key data or error.
	 */
	private function wrap_private_key( $private_key, $passkey_key ) {
		// Generate random IV for AES-256-GCM
		$iv = random_bytes( 16 );
		
		// Encrypt private key with passkey-derived key
		$tag = '';
		$encrypted = openssl_encrypt(
			$private_key,
			'aes-256-gcm',
			$passkey_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $encrypted ) {
			return new WP_Error( 'wrap_failed', 'Failed to wrap private key' );
		}

		// Store wrapped key
		$wrapped_data = array(
			'encrypted' => base64_encode( $encrypted ),
			'iv'        => base64_encode( $iv ),
			'tag'       => base64_encode( $tag ),
			'algorithm' => 'AES-256-GCM',
			'wrapped_at' => time(),
			'version'   => '1.0',
		);

		update_option( 'secure_login_wrapped_private_key', $wrapped_data );
		
		// Remove old encrypted key
		delete_option( 'secure_login_private_key_encrypted' );

		return array( 'status' => 'success', 'wrapped' => true );
	}

	/**
	 * Legacy decryption for migration.
	 *
	 * @param string $encrypted_key Encrypted private key.
	 * @return string|false Decrypted key or false.
	 */
	private function decrypt_private_key_legacy( $encrypted_key ) {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			$stored_key = get_option( 'secure_login_fallback_key' );
			if ( ! $stored_key ) {
				return false;
			}
			$key = base64_decode( $stored_key );
		} else {
			$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		}

		// Try new format first (with stored IV)
		if ( is_array( $encrypted_key ) && isset( $encrypted_key['data'], $encrypted_key['iv'] ) ) {
			$decrypted = openssl_decrypt(
				base64_decode( $encrypted_key['data'] ),
				'AES-256-CBC',
				$key,
				OPENSSL_RAW_DATA,
				base64_decode( $encrypted_key['iv'] )
			);
			if ( $decrypted !== false ) {
				return $decrypted;
			}
		}

		// Try legacy format
		if ( is_string( $encrypted_key ) ) {
			$iv = substr( hash( 'sha256', SECURE_AUTH_KEY . AUTH_KEY ), 0, 16 );
			return openssl_decrypt(
				base64_decode( $encrypted_key ),
				'AES-256-CBC',
				$key,
				0,
				$iv
			);
		}

		return false;
	}

	/**
	 * Handle AJAX request for public key.
	 */
	public function handle_get_public_key() {
		// Public key is public - no auth needed
		$public_key = get_option( 'secure_login_public_key' );
		
		if ( ! $public_key ) {
			wp_send_json_error( 'No public key available. Admin needs to initialize encryption.' );
			return;
		}

		wp_send_json_success( array(
			'public_key' => $public_key,
			'algorithm'  => 'RSA-OAEP',
			'key_size'   => 2048,
		) );
	}

	/**
	 * Handle key initialization (admin only).
	 */
	public function handle_initialize_keys() {
		// Check admin permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Verify nonce
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'slc_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		// Get passkey-derived key from request
		$passkey_key = sanitize_text_field( wp_unslash( $_POST['passkey_derived_key'] ?? '' ) );
		if ( empty( $passkey_key ) ) {
			wp_send_json_error( 'Passkey-derived key required' );
			return;
		}

		// Decode the key (it's base64 encoded from client)
		$passkey_key = base64_decode( $passkey_key );
		if ( strlen( $passkey_key ) !== 32 ) {
			wp_send_json_error( 'Invalid key length' );
			return;
		}

		// Initialize keys with passkey wrapping
		$result = $this->initialize_keys_with_passkey( $passkey_key );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle request for wrapped private key (admin only).
	 * Returns the wrapped key - client must unwrap with passkey.
	 */
	public function handle_get_wrapped_private_key() {
		// Check admin permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Verify nonce - accept both admin nonces
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'secure_login_admin_nonce' ) && 
		     ! wp_verify_nonce( $nonce, 'slc_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		// Get wrapped private key
		$wrapped_key = get_option( 'secure_login_wrapped_private_key' );
		
		if ( ! $wrapped_key ) {
			// Fallback to legacy encrypted key if no wrapped key
			$encrypted_key = get_option( 'secure_login_private_key_encrypted' );
			if ( $encrypted_key ) {
				wp_send_json_error( 'Keys not initialized with passkey. Please set up passkey first.' );
				return;
			}
			
			wp_send_json_error( 'No private key available' );
			return;
		}

		// Log access attempt for audit
		$this->log_key_access( get_current_user_id() );

		// Return wrapped key for client-side unwrapping
		wp_send_json_success( array(
			'wrapped_key' => $wrapped_key,
			'message' => 'Use passkey to unwrap and decrypt',
		) );
	}

	/**
	 * Log key access for audit trail.
	 *
	 * @param int $user_id User accessing the key.
	 */
	private function log_key_access( $user_id ) {
		$log = get_option( 'secure_login_key_access_log', array() );
		
		// Keep only last 100 entries
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		$log[] = array(
			'user_id'   => $user_id,
			'timestamp' => time(),
			'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
			'action'    => 'wrapped_key_retrieved',
		);

		update_option( 'secure_login_key_access_log', $log );
	}

	/**
	 * Get the public key for encryption.
	 *
	 * @return string|WP_Error Public key or error.
	 */
	public function get_public_key() {
		$public_key = get_option( 'secure_login_public_key' );
		
		if ( empty( $public_key ) ) {
			// Try to generate keys if admin
			if ( current_user_can( 'manage_options' ) ) {
				$keypair = $this->generate_rsa_keypair();
				if ( ! is_wp_error( $keypair ) ) {
					update_option( 'secure_login_public_key', $keypair['public'] );
					// Store private key with WordPress salt encryption temporarily
					$this->store_private_key_temporary( $keypair['private'] );
					return $keypair['public'];
				}
			}
			return new WP_Error( 'no_public_key', 'Public key not initialized' );
		}
		
		return $public_key;
	}

	/**
	 * Temporarily store private key with WordPress salt encryption.
	 * This is used before passkey setup is complete.
	 *
	 * @param string $private_key Private key to store.
	 */
	private function store_private_key_temporary( $private_key ) {
		$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		$iv = random_bytes( 16 );
		
		$encrypted = openssl_encrypt(
			$private_key,
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);
		
		update_option( 'secure_login_private_key_encrypted', array(
			'data' => base64_encode( $encrypted ),
			'iv'   => base64_encode( $iv ),
		) );
	}

	/**
	 * Check if system is initialized with passkey-wrapped keys.
	 *
	 * @return bool True if initialized.
	 */
	public static function is_initialized() {
		$wrapped_key = get_option( 'secure_login_wrapped_private_key' );
		$public_key = get_option( 'secure_login_public_key' );
		
		return ! empty( $wrapped_key ) && ! empty( $public_key );
	}

	/**
	 * Get initialization status details.
	 *
	 * @return array Status information.
	 */
	public static function get_status() {
		$status = array(
			'initialized' => self::is_initialized(),
			'has_public_key' => ! empty( get_option( 'secure_login_public_key' ) ),
			'has_wrapped_key' => ! empty( get_option( 'secure_login_wrapped_private_key' ) ),
			'has_legacy_key' => ! empty( get_option( 'secure_login_private_key_encrypted' ) ),
		);

		// Check access log
		$log = get_option( 'secure_login_key_access_log', array() );
		if ( ! empty( $log ) ) {
			$last_access = end( $log );
			$status['last_access'] = array(
				'user_id' => $last_access['user_id'],
				'time' => human_time_diff( $last_access['timestamp'] ) . ' ago',
			);
		}

		return $status;
	}

	/**
	 * Handle RSA key generation from settings page.
	 * DEPRECATED: This old method is no longer used.
	 * Keys should be generated through passkey registration only.
	 */
	public function handle_generate_rsa_keys() {
		wp_send_json_error( 
			__( 'Direct key generation is deprecated. Please register a passkey to initialize encryption keys. Visit the Passkeys Management page.', 'secure-login-collector' ) 
		);
	}
}