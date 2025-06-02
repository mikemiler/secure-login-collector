<?php
/**
 * Encryption Handler Class
 *
 * Handles all encryption and decryption operations including:
 * - RSA key generation and management
 * - Passkey-derived encryption (Pro version)
 * - XOR encryption (legacy)
 * - Double encryption for ultra-secure mode
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Secure_Login_Encryption_Handler
 *
 * Handles all encryption and decryption operations for the plugin.
 */
class Secure_Login_Encryption_Handler {

	/**
	 * Whether pro version is enabled.
	 *
	 * @var bool
	 */
	private $is_pro_version;

	/**
	 * Constructor - initializes encryption handler.
	 *
	 * @param bool $is_pro_version Whether pro version is enabled.
	 */
	public function __construct( $is_pro_version ) {
		$this->is_pro_version = $is_pro_version;

		// Register AJAX handlers for encryption operations.
		add_action( 'wp_ajax_generate_rsa_keys', array( $this, 'handle_generate_rsa_keys' ) );
		add_action( 'wp_ajax_export_public_key', array( $this, 'handle_export_public_key' ) );
		add_action( 'wp_ajax_register_passkey', array( $this, 'handle_register_passkey' ) );
		add_action( 'wp_ajax_authenticate_passkey', array( $this, 'handle_authenticate_passkey' ) );
		add_action( 'wp_ajax_reset_passkey', array( $this, 'handle_reset_passkey' ) );
		add_action( 'wp_ajax_encrypt_with_passkey', array( $this, 'handle_encrypt_with_passkey' ) );
		add_action( 'wp_ajax_test_passkey_encryption', array( $this, 'handle_test_passkey_encryption' ) );
	}

	/**
	 * Generate RSA key pair (available for all users).
	 */
	public function generate_rsa_keypair() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return new WP_Error( 'openssl_missing', __( 'OpenSSL extension is required for RSA encryption.', 'secure-login-collector' ) );
		}

		$config = array(
			'digest_alg'       => 'sha1',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);

		$keypair = openssl_pkey_new( $config );
		if ( ! $keypair ) {
			return new WP_Error( 'key_generation_failed', __( 'Failed to generate RSA key pair.', 'secure-login-collector' ) );
		}

		// Extract private key.
		openssl_pkey_export( $keypair, $private_key );

		// Extract public key.
		$public_key_details = openssl_pkey_get_details( $keypair );
		$public_key         = $public_key_details['key'];

		// Store keys securely.
		$this->store_keypair( $public_key, $private_key );

		return array(
			'public_key'  => $public_key,
			'private_key' => $private_key,
		);
	}

	/**
	 * Store RSA key pair securely.
	 *
	 * @param string $public_key  The public key to store.
	 * @param string $private_key The private key to store.
	 */
	private function store_keypair( $public_key, $private_key ) {
		// Store public key (can be accessed by frontend).
		update_option( 'secure_login_public_key', $public_key );

		// Store private key encrypted with WordPress salts.
		$encrypted_private_key = $this->encrypt_private_key( $private_key );
		update_option( 'secure_login_private_key_encrypted', $encrypted_private_key );

		// Store key generation timestamp.
		update_option( 'secure_login_keys_generated', current_time( 'mysql' ) );

		// Store key version for compatibility tracking.
		update_option( 'secure_login_key_version', '2.5.0' );
	}

	/**
	 * Encrypt private key using WordPress salts.
	 *
	 * @param string $private_key The private key to encrypt.
	 * @return string Encrypted private key.
	 */
	private function encrypt_private_key( $private_key ) {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return base64_encode( $private_key ); // Fallback to base64 if salts not available.
		}

		$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY );
		$iv  = substr( hash( 'sha256', SECURE_AUTH_KEY . AUTH_KEY ), 0, 16 );

		return base64_encode( openssl_encrypt( $private_key, 'AES-256-CBC', $key, 0, $iv ) );
	}

	/**
	 * Decrypt private key using WordPress salts.
	 *
	 * @param string $encrypted_private_key The encrypted private key to decrypt.
	 * @return string|false Decrypted private key or false on failure.
	 */
	private function decrypt_private_key( $encrypted_private_key ) {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return base64_decode( $encrypted_private_key ); // Fallback.
		}

		$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY );
		$iv  = substr( hash( 'sha256', SECURE_AUTH_KEY . AUTH_KEY ), 0, 16 );

		$decrypted = openssl_decrypt( base64_decode( $encrypted_private_key ), 'AES-256-CBC', $key, 0, $iv );

		if ( false === $decrypted ) {
			return base64_decode( $encrypted_private_key );
		}

		return $decrypted;
	}

	/**
	 * Get public key for frontend encryption.
	 *
	 * @return string|WP_Error Public key or error object.
	 */
	public function get_public_key() {
		$public_key = get_option( 'secure_login_public_key' );
		if ( ! $public_key ) {
			// Generate keys if they don't exist.
			$keypair = $this->generate_rsa_keypair();
			if ( is_wp_error( $keypair ) ) {
				return $keypair;
			}
			$public_key = $keypair['public_key'];
		}

		return $public_key;
	}

	/**
	 * Ensure RSA keys are generated and available.
	 */
	public function ensure_rsa_keys() {
		$public_key  = get_option( 'secure_login_public_key' );
		$key_version = get_option( 'secure_login_key_version', '1.0' );

		// Force regeneration if keys don't exist or were generated with old version.
		if ( ! $public_key || version_compare( $key_version, '2.5.0', '<' ) ) {
			$keypair = $this->generate_rsa_keypair();
			if ( ! is_wp_error( $keypair ) ) {
				update_option( 'secure_login_key_version', '2.5.0' );
			}
		}
	}

	/**
	 * Encrypt data using RSA (server-side).
	 *
	 * @param string $data The data to encrypt.
	 * @return string|false Encrypted data or false on failure.
	 */
	public function encrypt_rsa_data( $data ) {
		$public_key = $this->get_public_key();
		if ( is_wp_error( $public_key ) ) {
			return false;
		}

		// Load the public key resource.
		$public_key_resource = openssl_pkey_get_public( $public_key );
		if ( ! $public_key_resource ) {
			return false;
		}

		// Encrypt with RSA-OAEP.
		$encrypted = '';
		if ( ! openssl_public_encrypt( $data, $encrypted, $public_key_resource, OPENSSL_PKCS1_OAEP_PADDING ) ) {
			return false;
		}

		return base64_encode( $encrypted );
	}

	/**
	 * Decrypt RSA encrypted data.
	 *
	 * @param string $encrypted_data The encrypted data to decrypt.
	 * @return array|false Decrypted data array or false on failure.
	 */
	public function decrypt_rsa_data( $encrypted_data ) {
		// Get encrypted private key.
		$encrypted_private_key = get_option( 'secure_login_private_key_encrypted' );
		if ( ! $encrypted_private_key ) {
			return false;
		}

		// Decrypt private key.
		$private_key = $this->decrypt_private_key( $encrypted_private_key );
		if ( ! $private_key ) {
			return false;
		}

		// Decode base64 encrypted data.
		$encrypted_data = base64_decode( $encrypted_data );
		if ( false === $encrypted_data ) {
			return false;
		}

		// Load the private key resource.
		$private_key_resource = openssl_pkey_get_private( $private_key );
		if ( ! $private_key_resource ) {
			return false;
		}

		// Decrypt with RSA-OAEP.
		$decrypted = '';
		if ( ! openssl_private_decrypt( $encrypted_data, $decrypted, $private_key_resource, OPENSSL_PKCS1_OAEP_PADDING ) ) {
			return false;
		}

		// Parse JSON.
		$data = json_decode( $decrypted, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $data : false;
	}

	/**
	 * Encrypt data using XOR cipher (legacy).
	 *
	 * @param string $data           The data to encrypt.
	 * @param string $encryption_key The encryption key to use.
	 * @return string Encrypted data.
	 */
	public function encrypt_xor_data( $data, $encryption_key ) {
		$encrypted   = '';
		$key_index   = 0;
		$data_length = strlen( $data );
		$key_length  = strlen( $encryption_key );

		for ( $i = 0; $i < $data_length; $i++ ) {
			$char_code  = ord( $data[ $i ] );
			$key_char   = ord( $encryption_key[ $key_index % $key_length ] );
			$encrypted .= chr( $char_code ^ $key_char );
			++$key_index;
		}

		return base64_encode( $encrypted );
	}

	/**
	 * Decrypt XOR encrypted data (legacy).
	 *
	 * @param string $encrypted_data The encrypted data to decrypt.
	 * @param string $encryption_key The encryption key to use.
	 * @return array|false Decrypted data array or false on failure.
	 */
	public function decrypt_xor_data( $encrypted_data, $encryption_key ) {
		// Decode base64.
		$encrypted_data = base64_decode( $encrypted_data );

		// Decrypt using XOR cipher.
		$decrypted   = '';
		$key_index   = 0;
		$data_length = strlen( $encrypted_data );
		$key_length  = strlen( $encryption_key );

		for ( $i = 0; $i < $data_length; $i++ ) {
			$char_code  = ord( $encrypted_data[ $i ] );
			$key_char   = ord( $encryption_key[ $key_index % $key_length ] );
			$decrypted .= chr( $char_code ^ $key_char );
			++$key_index;
		}

		// Parse JSON.
		$data = json_decode( $decrypted, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $data : false;
	}

	/**
	 * Generate encryption key from passkey (Pro version).
	 *
	 * @param string|null $passkey_signature Optional passkey signature for key derivation.
	 * @return string|false Derived encryption key or false on failure.
	 */
	private function derive_key_from_passkey( $passkey_signature = null ) {
		if ( ! $this->is_pro_version ) {
			return false;
		}

		// Use the stored passkey public key for consistent key derivation.
		$passkey_public_key = get_option( 'secure_login_passkey_public_key' );
		if ( ! $passkey_public_key ) {
			return false;
		}

		// Use the passkey public key as the base for key derivation.
		$salt = get_option( 'secure_login_passkey_salt' );
		if ( ! $salt ) {
			// Generate and store a unique salt for this installation.
			$salt = wp_generate_password( 32, true, true );
			update_option( 'secure_login_passkey_salt', $salt );
		}

		// Derive 256-bit key using PBKDF2.
		return hash_pbkdf2( 'sha256', $passkey_public_key, $salt, 100000, 32, true );
	}

	/**
	 * Encrypt data using passkey-derived key (Pro version).
	 *
	 * @param string      $data             The data to encrypt.
	 * @param string|null $passkey_signature Optional passkey signature for encryption.
	 * @return string|false Encrypted data or false on failure.
	 */
	public function encrypt_with_passkey_key( $data, $passkey_signature = null ) {
		if ( ! $this->is_pro_version ) {
			return false;
		}

		$encryption_key = $this->derive_key_from_passkey( $passkey_signature );
		if ( false === $encryption_key ) {
			return false;
		}

		// Generate random IV.
		$iv = random_bytes( 16 );

		// Encrypt with AES-256-GCM for authenticated encryption.
		$encrypted = openssl_encrypt( $data, 'aes-256-gcm', $encryption_key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $encrypted ) {
			return false;
		}

		// Combine IV + tag + encrypted data.
		return base64_encode( $iv . $tag . $encrypted );
	}

	/**
	 * Decrypt data using passkey-derived key (Pro version).
	 *
	 * @param string      $encrypted_data    The encrypted data to decrypt.
	 * @param string|null $passkey_signature Optional passkey signature for decryption.
	 * @return string|false Decrypted data or false on failure.
	 */
	public function decrypt_with_passkey_key( $encrypted_data, $passkey_signature = null ) {
		if ( ! $this->is_pro_version ) {
			return false;
		}

		$encryption_key = $this->derive_key_from_passkey( $passkey_signature );
		if ( false === $encryption_key ) {
			return false;
		}

		// Decode and extract components.
		$data = base64_decode( $encrypted_data );
		if ( strlen( $data ) < 32 ) { // IV(16) + tag(16) minimum.
			return false;
		}

		$iv        = substr( $data, 0, 16 );
		$tag       = substr( $data, 16, 16 );
		$encrypted = substr( $data, 32 );

		// Decrypt with AES-256-GCM.
		$decrypted = openssl_decrypt( $encrypted, 'aes-256-gcm', $encryption_key, OPENSSL_RAW_DATA, $iv, $tag );

		return ( false !== $decrypted ) ? $decrypted : false;
	}

	/**
	 * Decrypt data with double encryption support.
	 *
	 * @param string      $encrypted_data  The encrypted data to decrypt.
	 * @param string      $encryption_type The type of encryption used.
	 * @param string|null $encryption_key  Optional encryption key for XOR decryption.
	 * @return array|false Decrypted data array or false on failure.
	 */
	public function decrypt_data( $encrypted_data, $encryption_type, $encryption_key = null ) {
		if ( 'passkey_derived' === $encryption_type ) {
			return $this->decrypt_passkey_derived_data( $encrypted_data );
		} elseif ( 'rsa' === $encryption_type ) {
			return $this->decrypt_rsa_data( $encrypted_data );
		} elseif ( $encryption_key ) {
			// XOR decryption.
			return $this->decrypt_xor_data( $encrypted_data, $encryption_key );
		}

		return false;
	}

	/**
	 * Decrypt passkey-derived encrypted data with double encryption support.
	 *
	 * @param string $encrypted_data The encrypted data to decrypt.
	 * @return array|false Decrypted data array or false on failure.
	 */
	private function decrypt_passkey_derived_data( $encrypted_data ) {
		if ( ! $this->is_pro_version ) {
			return false;
		}

		// Check if passkey authentication was successful.
		$user_id               = get_current_user_id();
		$passkey_authenticated = get_transient( 'secure_login_passkey_authenticated_' . $user_id );
		if ( ! $passkey_authenticated ) {
			return false;
		}

		// First decrypt with passkey-derived key.
		$first_decrypted = $this->decrypt_with_passkey_key( $encrypted_data, null );
		if ( false === $first_decrypted ) {
			return false;
		}

		// Check if this is double-encrypted data.
		$data = json_decode( $first_decrypted, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			// Single encryption.
			return $data;
		} else {
			// Double encryption - try RSA decryption of inner layer.
			$final_decrypted = $this->decrypt_rsa_data( $first_decrypted );
			return ( false !== $final_decrypted ) ? $final_decrypted : array( 'raw_data' => $first_decrypted );
		}
	}

	/**
	 * Test passkey encryption/decryption consistency.
	 *
	 * @return string Test result message.
	 */
	public function test_passkey_encryption() {
		if ( ! $this->is_pro_version ) {
			return 'Pro version required';
		}

		$passkey_registered = get_option( 'secure_login_passkey_registered', false );
		if ( ! $passkey_registered ) {
			return 'Passkey not registered';
		}

		// Check if passkey data is available.
		$passkey_public_key = get_option( 'secure_login_passkey_public_key' );
		if ( ! $passkey_public_key ) {
			return 'FAILED: Passkey public key not found in database';
		}

		$test_data = 'Test data for passkey encryption';

		// Test encryption.
		$encrypted = $this->encrypt_with_passkey_key( $test_data, null );
		if ( false === $encrypted ) {
			return 'FAILED: Encryption failed - check if passkey data is properly stored';
		}

		// Test decryption.
		$decrypted = $this->decrypt_with_passkey_key( $encrypted, null );
		if ( false === $decrypted ) {
			return 'FAILED: Decryption failed - encrypted data: ' . substr( $encrypted, 0, 50 ) . '...';
		}

		if ( $test_data === $decrypted ) {
			return 'SUCCESS: Passkey encryption/decryption working correctly. Encrypted length: ' . strlen( $encrypted ) . ' bytes';
		} else {
			return 'FAILED: Data mismatch. Original: "' . $test_data . '", Decrypted: "' . $decrypted . '"';
		}
	}

	// AJAX Handlers.

	/**
	 * Handle RSA key generation AJAX request.
	 */
	public function handle_generate_rsa_keys() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'generate_rsa_keys' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		$result = $this->generate_rsa_keypair();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
			return;
		}

		wp_send_json_success( __( 'RSA keys generated successfully.', 'secure-login-collector' ) );
	}

	/**
	 * Handle public key export AJAX request.
	 */
	public function handle_export_public_key() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'export_public_key' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		$public_key = $this->get_public_key();
		if ( is_wp_error( $public_key ) || ! $public_key ) {
			wp_send_json_error( __( 'No public key available.', 'secure-login-collector' ) );
			return;
		}

		wp_send_json_success( array( 'public_key' => $public_key ) );
	}

	/**
	 * Handle passkey registration AJAX request.
	 */
	public function handle_register_passkey() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'register_passkey' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		if ( ! $this->is_pro_version ) {
			wp_send_json_error( __( 'Pro version required.', 'secure-login-collector' ) );
			return;
		}

		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		$public_key    = sanitize_text_field( wp_unslash( $_POST['public_key'] ?? '' ) );

		if ( empty( $credential_id ) || empty( $public_key ) ) {
			wp_send_json_error( __( 'Missing credential data.', 'secure-login-collector' ) );
			return;
		}

		// Store passkey data.
		update_option( 'secure_login_passkey_credential_id', $credential_id );
		update_option( 'secure_login_passkey_public_key', $public_key );
		update_option( 'secure_login_passkey_registered', true );
		update_option( 'secure_login_passkey_user_id', get_current_user_id() );
		update_option( 'secure_login_passkey_registered_at', current_time( 'mysql' ) );

		wp_send_json_success( __( 'Passkey registered successfully.', 'secure-login-collector' ) );
	}

	/**
	 * Handle passkey authentication AJAX request.
	 */
	public function handle_authenticate_passkey() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'authenticate_passkey' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		if ( ! $this->is_pro_version ) {
			wp_send_json_error( __( 'Pro version required.', 'secure-login-collector' ) );
			return;
		}

		// Check if passkey is registered.
		$passkey_registered = get_option( 'secure_login_passkey_registered', false );
		if ( ! $passkey_registered ) {
			wp_send_json_error( __( 'Passkey not registered.', 'secure-login-collector' ) );
			return;
		}

		// Get the pending decrypt request.
		$decrypt_id = get_transient( 'secure_login_decrypt_request_' . get_current_user_id() );
		if ( ! $decrypt_id ) {
			wp_send_json_error( __( 'No pending decrypt request found.', 'secure-login-collector' ) );
			return;
		}

		// Verify the passkey authentication (simplified for demo).
		$signature          = sanitize_text_field( wp_unslash( $_POST['signature'] ?? '' ) );
		$authenticator_data = sanitize_text_field( wp_unslash( $_POST['authenticator_data'] ?? '' ) );

		if ( empty( $signature ) || empty( $authenticator_data ) ) {
			wp_send_json_error( __( 'Missing authentication data.', 'secure-login-collector' ) );
			return;
		}

		// In a real implementation, you would verify the signature here.
		// For this demo, we'll assume authentication is successful.

		// Set authentication flag.
		set_transient( 'secure_login_passkey_authenticated_' . get_current_user_id(), true, 300 ); // 5 minutes.

		// Clear the decrypt request.
		delete_transient( 'secure_login_decrypt_request_' . get_current_user_id() );

		// Decrypt the data using the admin interface's decrypt_data method.
		// NOTE: This direct database call should be refactored to use database manager,
		// but is currently needed for passkey authentication AJAX handler.
		global $wpdb;
		$table_name = $wpdb->prefix . 'secure_login_data';

		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $decrypt_id ) );
		if ( ! $row ) {
			wp_send_json_error( __( 'Entry not found.', 'secure-login-collector' ) );
			return;
		}

		// Parse metadata to get encryption type.
		$metadata        = json_decode( $row->metadata, true );
		$encryption_type = isset( $metadata['encryption_type'] ) ? $metadata['encryption_type'] : 'xor';

		// Decrypt the data.
		$decrypted_data = $this->decrypt_data( $row->encrypted_data, $encryption_type );

		if ( false === $decrypted_data ) {
			wp_send_json_error( __( 'Decryption failed.', 'secure-login-collector' ) );
			return;
		}

		wp_send_json_success( $decrypted_data );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) as $ip ) {
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
						return $ip;
					}
				}
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'Unknown';
	}

	/**
	 * Handle passkey reset AJAX request.
	 */
	public function handle_reset_passkey() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'reset_passkey' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		if ( ! $this->is_pro_version ) {
			wp_send_json_error( __( 'Pro version required.', 'secure-login-collector' ) );
			return;
		}

		// Set flag to allow re-registration.
		set_transient( 'secure_login_force_passkey_reregister_' . get_current_user_id(), true, 300 );

		wp_send_json_success( __( 'Passkey reset authorized.', 'secure-login-collector' ) );
	}

	/**
	 * Handle passkey encryption AJAX request (deprecated).
	 */
	public function handle_encrypt_with_passkey() {
		// This endpoint is deprecated - frontend should use RSA encryption.
		wp_send_json_error( __( 'This encryption method is no longer supported. Please use standard form submission.', 'secure-login-collector' ) );
	}

	/**
	 * Handle passkey encryption test AJAX request.
	 */
	public function handle_test_passkey_encryption() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'test_passkey_encryption' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		if ( ! $this->is_pro_version ) {
			wp_send_json_error( __( 'Pro version required.', 'secure-login-collector' ) );
			return;
		}

		$passkey_registered = get_option( 'secure_login_passkey_registered', false );
		if ( ! $passkey_registered ) {
			wp_send_json_error( __( 'Passkey not registered. Please register a passkey first.', 'secure-login-collector' ) );
			return;
		}

		// For testing purposes, temporarily set the authentication flag.
		// This allows the test to work without requiring actual passkey authentication.
		$user_id = get_current_user_id();
		set_transient( 'secure_login_passkey_authenticated_' . $user_id, true, 60 ); // 1 minute for test.

		// Run the test.
		$result = $this->test_passkey_encryption();

		// Clear the temporary authentication flag.
		delete_transient( 'secure_login_passkey_authenticated_' . $user_id );

		// Provide detailed result.
		if ( strpos( $result, 'SUCCESS' ) === 0 ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
