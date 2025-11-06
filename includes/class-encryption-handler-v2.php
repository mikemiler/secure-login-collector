<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Free Encryption Handler - Base Implementation
 *
 * Implements RSA-2048 + AES-256-GCM encryption with PBKDF2 key derivation.
 * Zero-Knowledge Architecture with master password protection.
 * Pro version extends this class to add passkey-wrapping capabilities.
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption Handler - Base Class (Free Version)
 *
 * Handles free version encryption: RSA-2048 + AES-256-GCM with PBKDF2 key derivation.
 * Pro version extends functionality via hooks without modifying base class.
 *
 * EXTENSION POINTS FOR PRO VERSION:
 *
 * 1. Filter: 'seculoco_get_public_key'
 *    - Allows pro version to return pro public key instead of free key
 *    - Parameters: $public_key (string)
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
		add_action( 'wp_ajax_seculoco_initialize_keys', array( $this, 'handle_initialize_keys' ) );
		add_action( 'wp_ajax_seculoco_setup_master_password', array( $this, 'handle_setup_master_password' ) );
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

		// Diagnostic: Check private key encoding
		error_log( '[SecuLoco] Generated private key length: ' . strlen( $private_key ) );
		error_log( '[SecuLoco] Private key first 100 chars: ' . substr( $private_key, 0, 100 ) );
		error_log( '[SecuLoco] Private key last 100 chars: ' . substr( $private_key, -100 ) );
		error_log( '[SecuLoco] Private key encoding: ' . mb_detect_encoding( $private_key, 'UTF-8, ASCII, ISO-8859-1', true ) );

		// Check for non-ASCII characters
		$has_non_ascii = preg_match( '/[^\x00-\x7F]/', $private_key );
		error_log( '[SecuLoco] Contains non-ASCII: ' . ( $has_non_ascii ? 'YES' : 'NO' ) );

		// Extract public key.
		$details = openssl_pkey_get_details( $keypair );

		return array(
			'public'  => $details['key'],
			'private' => $private_key,
		);
	}

	/**
	 * Initialize free version keys with PBKDF2-based encryption.
	 *
	 * Implements Zero-Knowledge Architecture with master password protection:
	 * - Generates RSA-2048 keypair
	 * - Wraps private key with user's master password using PBKDF2 + AES-GCM
	 * - Stores wrapped key, salt, IV, and tag in wp_options
	 * - Master password is NEVER stored (zero-knowledge)
	 *
	 * @since 2.0.0
	 * @param string $master_password User's master password (minimum 8 characters required).
	 * @return array|WP_Error Result of key initialization.
	 */
	public function initialize_free_keys( $master_password ) {
		// Validate password strength.
		if ( strlen( $master_password ) < 8 ) {
			return new WP_Error( 'weak_password', 'Master password must be at least 8 characters' );
		}

		// Check if keys already exist.
		$existing_wrapped = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED );
		if ( $existing_wrapped ) {
			return array(
				'status'  => 'already_initialized',
				'type'    => 'free',
				'message' => 'Encryption keys already initialized',
			);
		}

		// Generate new RSA keypair.
		$keypair = $this->generate_rsa_keypair();
		if ( is_wp_error( $keypair ) ) {
			return $keypair;
		}

		// Generate unique salt for this installation.
		$salt = $this->generate_master_password_salt();

		// Wrap private key with master password.
		$wrapped = $this->wrap_dek_with_password( $keypair['private'], $master_password, $salt );
		if ( is_wp_error( $wrapped ) ) {
			return $wrapped;
		}

		// Store public key.
		update_option( SECULOCO_OPTION_PUBLIC_KEY, $keypair['public'] );

		// Store wrapped private key with all components.
		update_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED, $wrapped );

		// Store salt (required for unwrapping).
		update_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT, $salt );

		// Log initialization.
		$this->log_key_operation( 'free_keys_initialized' );

		return array(
			'status'  => 'success',
			'type'    => 'free',
			'message' => 'Free version keys initialized with PBKDF2 encryption',
		);
	}

	/**
	 * Generate cryptographically random salt for master password.
	 *
	 * Used in PBKDF2 key derivation to ensure unique encryption keys per installation.
	 * Salt is stored in wp_options and must never be lost.
	 *
	 * @since 2.0.0
	 * @return string Base64-encoded 32-byte salt.
	 */
	protected function generate_master_password_salt() {
		return base64_encode( random_bytes( 32 ) );
	}

	/**
	 * Wrap DEK (Data Encryption Key) with master password using PBKDF2 + AES-GCM.
	 *
	 * Implementation of Zero-Knowledge Architecture v2.0 specification:
	 * - PBKDF2-SHA256 with 600,000 iterations (OWASP 2023 minimum)
	 * - AES-256-GCM for authenticated encryption
	 * - Unique 12-byte IV per wrap operation
	 * - 16-byte authentication tag for tamper detection
	 *
	 * @since 2.0.0
	 * @param string $dek             The Data Encryption Key (private key) to wrap.
	 * @param string $master_password User's master password (not stored anywhere).
	 * @param string $salt            Base64-encoded salt for PBKDF2 derivation.
	 * @return array|WP_Error Array with 'wrapped_dek' (base64), 'iv' (base64), 'tag' (base64), or error.
	 */
	protected function wrap_dek_with_password( $dek, $master_password, $salt ) {
		// DIAGNOSTIC: Check what's being wrapped
		error_log( '[SecuLoco] wrap_dek_with_password called' );
		error_log( '[SecuLoco] DEK type: ' . gettype( $dek ) );
		error_log( '[SecuLoco] DEK length: ' . strlen( $dek ) );
		error_log( '[SecuLoco] DEK first 100 chars: ' . substr( $dek, 0, 100 ) );
		error_log( '[SecuLoco] DEK starts with PEM header? ' . ( strpos( $dek, '-----BEGIN' ) === 0 ? 'YES' : 'NO' ) );
		error_log( '[SecuLoco] DEK encoding: ' . mb_detect_encoding( $dek, 'UTF-8, ASCII, ISO-8859-1', true ) );

		if ( ! function_exists( 'hash_pbkdf2' ) ) {
			return new WP_Error( 'pbkdf2_missing', 'PBKDF2 not available on this system' );
		}

		// Decode salt from base64.
		$salt_raw = base64_decode( $salt );
		if ( false === $salt_raw || strlen( $salt_raw ) !== 32 ) {
			return new WP_Error( 'invalid_salt', 'Salt must be 32 bytes' );
		}

		// Derive 256-bit key from password using PBKDF2-SHA256.
		// 600,000 iterations per OWASP 2023 recommendations.
		$kek = hash_pbkdf2( 'sha256', $master_password, $salt_raw, 600000, 32, true );

		// Generate unique 12-byte IV for this wrap operation.
		$iv = random_bytes( 12 );

		// Encrypt DEK with AES-256-GCM (authenticated encryption).
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$dek,
			'aes-256-gcm',
			$kek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16 // 16-byte tag length (128 bits).
		);

		if ( false === $ciphertext ) {
			return new WP_Error( 'encryption_failed', 'Failed to encrypt DEK with master password' );
		}

		return array(
			'wrapped_dek' => trim( base64_encode( $ciphertext ) ),
			'iv'          => trim( base64_encode( $iv ) ),
			'tag'         => trim( base64_encode( $tag ) ),
		);
	}

	/**
	 * Unwrap DEK (Data Encryption Key) with master password.
	 *
	 * Reverses the wrapping process using PBKDF2 + AES-GCM.
	 * Validates authentication tag to detect tampering.
	 *
	 * @since 2.0.0
	 * @param array  $wrapped_data  Array with 'wrapped_dek', 'iv', 'tag' (all base64-encoded).
	 * @param string $master_password User's master password.
	 * @param string $salt          Base64-encoded salt for PBKDF2 derivation.
	 * @return string|WP_Error Unwrapped DEK (private key) or error.
	 */
	protected function unwrap_dek_with_password( $wrapped_data, $master_password, $salt ) {
		if ( ! function_exists( 'hash_pbkdf2' ) ) {
			return new WP_Error( 'pbkdf2_missing', 'PBKDF2 not available on this system' );
		}

		// Validate input structure.
		if ( ! isset( $wrapped_data['wrapped_dek'], $wrapped_data['iv'], $wrapped_data['tag'] ) ) {
			return new WP_Error( 'invalid_wrapped_data', 'Wrapped data missing required fields' );
		}

		// Decode components from base64.
		$ciphertext = base64_decode( $wrapped_data['wrapped_dek'] );
		$iv         = base64_decode( $wrapped_data['iv'] );
		$tag        = base64_decode( $wrapped_data['tag'] );
		$salt_raw   = base64_decode( $salt );

		// Validate decoded data.
		if ( false === $ciphertext || false === $iv || false === $tag || false === $salt_raw ) {
			return new WP_Error( 'invalid_encoding', 'Failed to decode wrapped data' );
		}

		if ( strlen( $iv ) !== 12 || strlen( $tag ) !== 16 || strlen( $salt_raw ) !== 32 ) {
			return new WP_Error( 'invalid_lengths', 'Invalid IV, tag, or salt length' );
		}

		// Derive same key from password using PBKDF2-SHA256.
		$kek = hash_pbkdf2( 'sha256', $master_password, $salt_raw, 600000, 32, true );

		// Decrypt DEK with AES-256-GCM.
		$plaintext = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$kek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			return new WP_Error( 'decryption_failed', 'Failed to unwrap DEK - incorrect password or tampered data' );
		}

		return $plaintext;
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
		// Get public key.
		$public_key = get_option( SECULOCO_OPTION_PUBLIC_KEY );

		/**
		 * Filter the public key to use for encryption.
		 *
		 * Pro version can hook into this filter to return pro key when available.
		 *
		 * @since 1.0.0
		 * @param string $public_key The free version public key (default).
		 */
		$public_key = apply_filters( 'seculoco_get_public_key', $public_key );

		if ( empty( $public_key ) ) {
			wp_send_json_error( 'No encryption key available. Please initialize encryption keys in Settings.' );
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
	 * PBKDF2-based encryption (zero-knowledge):
	 * - Returns wrapped private key for client-side unwrapping, OR
	 * - Unwraps server-side if master password provided (convenience mode)
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

		// Get wrapped key and salt.
		$wrapped_key = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED );
		$salt        = get_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT );

		if ( ! $wrapped_key || ! $salt ) {
			wp_send_json_error( 'No private key available. Please initialize encryption keys in Settings.' );
			return;
		}

		// Get master password from request (for unwrapping).
		$master_password = isset( $_POST['master_password'] ) ? wp_unslash( $_POST['master_password'] ) : '';

		if ( empty( $master_password ) ) {
			// Return wrapped key for client-side unwrapping (zero-knowledge mode).
			$this->log_key_access( get_current_user_id(), 'free-wrapped' );

			wp_send_json_success(
				array(
					'wrapped_key' => $wrapped_key,
					'salt'        => $salt,
					'type'        => 'free',
					'mode'        => 'zero-knowledge',
					'message'     => 'Wrapped key - requires master password for unwrapping',
				)
			);
			return;
		}

		// Server-side unwrapping requested (convenience mode).
		$private_key = $this->unwrap_dek_with_password( $wrapped_key, $master_password, $salt );

		if ( is_wp_error( $private_key ) ) {
			wp_send_json_error( 'Failed to unwrap private key: ' . $private_key->get_error_message() );
			return;
		}

		// Log this security-sensitive operation.
		$this->log_key_access( get_current_user_id(), 'free-unwrapped' );

		wp_send_json_success(
			array(
				'private_key' => base64_encode( $private_key ),
				'type'        => 'free',
				'mode'        => 'server-unwrapped',
				'message'     => 'Private key unwrapped with master password',
			)
		);
	}

	/**
	 * Handle AJAX request to initialize encryption keys with PBKDF2.
	 *
	 * @since 2.0.0
	 */
	public function handle_initialize_keys() {
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

		// Get master password from request.
		$master_password = isset( $_POST['master_password'] ) ? wp_unslash( $_POST['master_password'] ) : '';

		if ( empty( $master_password ) ) {
			wp_send_json_error( 'Master password is required for initialization' );
			return;
		}

		// Initialize encryption keys.
		$result = $this->initialize_free_keys( $master_password );

		// Clear password from memory.
		unset( $master_password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Handle AJAX request to set up master password with client-wrapped keys.
	 *
	 * Receives RSA keypair components that were generated and wrapped in browser.
	 * Stores wrapped private key, public key, salt, and IV in database.
	 *
	 * Security: Master password wizard generates RSA-4096 keypair in browser,
	 * wraps private key with PBKDF2-derived key, sends only wrapped components.
	 *
	 * @since 2.0.0
	 */
	public function handle_setup_master_password() {
		// Check admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
			return;
		}

		// Verify nonce (wizard uses seculoco_wizard_nonce).
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'seculoco_wizard_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token', 'secure-login-collector' ) );
			return;
		}

		// Validate required fields.
		$wrapped_private_key = isset( $_POST['wrapped_private_key'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wrapped_private_key'] ) ) : '';
		$public_key_jwk      = isset( $_POST['public_key_jwk'] ) ? wp_unslash( $_POST['public_key_jwk'] ) : ''; // JSON, sanitized below.
		$salt                = isset( $_POST['master_password_salt'] ) ? sanitize_text_field( wp_unslash( $_POST['master_password_salt'] ) ) : '';
		$iv                  = isset( $_POST['key_wrapping_iv'] ) ? sanitize_text_field( wp_unslash( $_POST['key_wrapping_iv'] ) ) : '';
		$tag                 = isset( $_POST['key_wrapping_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['key_wrapping_tag'] ) ) : '';

		if ( empty( $wrapped_private_key ) || empty( $public_key_jwk ) || empty( $salt ) || empty( $iv ) || empty( $tag ) ) {
			wp_send_json_error( __( 'Missing required key material', 'secure-login-collector' ) );
			return;
		}

		// Check if keys already exist.
		$existing_public = get_option( SECULOCO_OPTION_PUBLIC_KEY );
		if ( $existing_public ) {
			wp_send_json_error( __( 'Encryption keys already initialized. Please delete existing keys first.', 'secure-login-collector' ) );
			return;
		}

		// Decode and validate JWK.
		$public_key_data = json_decode( $public_key_jwk, true );
		if ( ! $public_key_data || ! isset( $public_key_data['n'], $public_key_data['e'] ) ) {
			wp_send_json_error( __( 'Invalid public key format', 'secure-login-collector' ) );
			return;
		}

		// Convert JWK to PEM format.
		$public_key_pem = $this->jwk_to_pem( $public_key_data );
		if ( is_wp_error( $public_key_pem ) ) {
			wp_send_json_error( $public_key_pem->get_error_message() );
			return;
		}

		// Store components in database (FREE version keys).
		update_option( SECULOCO_OPTION_PUBLIC_KEY, $public_key_pem );
		update_option(
			SECULOCO_OPTION_PRIVATE_KEY_WRAPPED,
			array(
				'wrapped_dek' => $wrapped_private_key,
				'iv'          => $iv,
				'tag'         => $tag,
			)
		);
		update_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT, $salt );
		update_option( SECULOCO_OPTION_ENCRYPTION_VERSION, 'v2' );

		// Log initialization.
		$this->log_key_operation( 'client_side_keys_initialized' );

		wp_send_json_success(
			array(
				'status'   => 'success',
				'message'  => __( 'Master password configured successfully', 'secure-login-collector' ),
				'redirect' => admin_url( 'admin.php?page=secure-login-collector' ),
			)
		);
	}

	/**
	 * Convert JWK (JSON Web Key) to PEM format.
	 *
	 * Converts RSA public key from JWK format (used by Web Crypto API)
	 * to PEM format (used by PHP/OpenSSL).
	 *
	 * @param array $jwk JWK public key data with 'n' (modulus) and 'e' (exponent).
	 * @return string|WP_Error PEM-formatted public key or error.
	 */
	protected function jwk_to_pem( $jwk ) {
		if ( ! isset( $jwk['n'], $jwk['e'] ) ) {
			return new WP_Error( 'invalid_jwk', __( 'Invalid JWK format: missing n or e', 'secure-login-collector' ) );
		}

		// Base64url decode the modulus (n) and exponent (e).
		$modulus  = $this->base64url_decode( $jwk['n'] );
		$exponent = $this->base64url_decode( $jwk['e'] );

		if ( false === $modulus || false === $exponent ) {
			return new WP_Error( 'decode_failed', __( 'Failed to decode JWK components', 'secure-login-collector' ) );
		}

		// Build ASN.1 DER sequence for RSA public key.
		// Reference: RFC 3447 (PKCS#1) and RFC 5280 (SubjectPublicKeyInfo).
		$der = $this->build_rsa_public_key_der( $modulus, $exponent );

		if ( is_wp_error( $der ) ) {
			return $der;
		}

		// Convert DER to PEM format.
		$pem  = "-----BEGIN PUBLIC KEY-----\n";
		$pem .= chunk_split( base64_encode( $der ), 64, "\n" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$pem .= "-----END PUBLIC KEY-----\n";

		return $pem;
	}

	/**
	 * Base64url decode (RFC 4648).
	 *
	 * @param string $input Base64url-encoded string.
	 * @return string|false Decoded binary data or false on failure.
	 */
	protected function base64url_decode( $input ) {
		$remainder = strlen( $input ) % 4;
		if ( $remainder ) {
			$padlen = 4 - $remainder;
			$input .= str_repeat( '=', $padlen );
		}
		return base64_decode( strtr( $input, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Build ASN.1 DER-encoded RSA public key.
	 *
	 * Creates SubjectPublicKeyInfo structure for RSA public key.
	 *
	 * @param string $modulus  Binary modulus (n).
	 * @param string $exponent Binary exponent (e).
	 * @return string|WP_Error DER-encoded public key or error.
	 */
	protected function build_rsa_public_key_der( $modulus, $exponent ) {
		// Build RSA public key sequence (SEQUENCE { n INTEGER, e INTEGER }).
		$mod_integer = $this->asn1_integer( $modulus );
		$exp_integer = $this->asn1_integer( $exponent );
		$rsa_key     = $this->asn1_sequence( $mod_integer . $exp_integer );

		// Wrap in BIT STRING.
		$bit_string = $this->asn1_bit_string( $rsa_key );

		// RSA algorithm identifier: SEQUENCE { OBJECT IDENTIFIER rsaEncryption, NULL }.
		$rsa_oid    = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"; // OID 1.2.840.113549.1.1.1.
		$null       = "\x05\x00";
		$algo_id    = $this->asn1_sequence( $rsa_oid . $null );

		// Final SubjectPublicKeyInfo: SEQUENCE { algorithm, subjectPublicKey }.
		$spki = $this->asn1_sequence( $algo_id . $bit_string );

		return $spki;
	}

	/**
	 * Encode ASN.1 INTEGER.
	 *
	 * @param string $bytes Binary integer value.
	 * @return string ASN.1 encoded INTEGER.
	 */
	protected function asn1_integer( $bytes ) {
		// Remove leading zero bytes.
		$bytes = ltrim( $bytes, "\x00" );

		// Add padding byte if high bit is set (to keep it positive).
		if ( ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\x00" . $bytes;
		}

		return "\x02" . $this->asn1_length( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Encode ASN.1 SEQUENCE.
	 *
	 * @param string $content Sequence content.
	 * @return string ASN.1 encoded SEQUENCE.
	 */
	protected function asn1_sequence( $content ) {
		return "\x30" . $this->asn1_length( strlen( $content ) ) . $content;
	}

	/**
	 * Encode ASN.1 BIT STRING.
	 *
	 * @param string $content Bit string content.
	 * @return string ASN.1 encoded BIT STRING.
	 */
	protected function asn1_bit_string( $content ) {
		return "\x03" . $this->asn1_length( strlen( $content ) + 1 ) . "\x00" . $content;
	}

	/**
	 * Encode ASN.1 length.
	 *
	 * @param int $length Length value.
	 * @return string ASN.1 encoded length.
	 */
	protected function asn1_length( $length ) {
		if ( $length < 128 ) {
			return chr( $length );
		}

		$temp = '';
		while ( $length > 0 ) {
			$temp    = chr( $length % 256 ) . $temp;
			$length  = (int) floor( $length / 256 );
		}

		return chr( 0x80 | strlen( $temp ) ) . $temp;
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
	 */
	protected function log_key_operation( $operation ) {
		$log = get_option( SECULOCO_OPTION_KEY_OPERATIONS_LOG, array() );

		// Keep only last 50 operations.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		$log[] = array(
			'operation' => $operation,
			'timestamp' => time(),
			'user_id'   => get_current_user_id(),
		);

		update_option( SECULOCO_OPTION_KEY_OPERATIONS_LOG, $log );
	}

	/**
	 * Get the public key.
	 *
	 * @return string|WP_Error Public key or error.
	 */
	public function get_public_key() {
		$public_key = get_option( SECULOCO_OPTION_PUBLIC_KEY );

		if ( ! $public_key ) {
			return new WP_Error( 'no_public_key', 'Public key not initialized. Please initialize encryption keys in Settings.' );
		}

		return $public_key;
	}

	/**
	 * Check if PRO encryption is active.
	 *
	 * @return bool True if PRO keys are active.
	 */
	public static function is_pro_active() {
		return (bool) get_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, false );
	}

	/**
	 * Get system status for free version (pro status added by premium class).
	 *
	 * @return array Status information for free version.
	 */
	public static function get_status() {
		$has_public_key  = ! empty( get_option( SECULOCO_OPTION_PUBLIC_KEY ) );
		$has_private_key = ! empty( get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED ) );
		$has_salt        = ! empty( get_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT ) );

		return array(
			'free' => array(
				'has_public_key'  => $has_public_key,
				'has_private_key' => $has_private_key,
				'has_salt'        => $has_salt,
				'initialized'     => $has_public_key && $has_private_key && $has_salt,
			),
		);
	}
}
