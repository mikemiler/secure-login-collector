<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Modern class naming convention.
/**
 * Unified Cryptography Module
 *
 * Provides a single, consistent interface for RSA key generation, key wrapping,
 * and key storage with support for both password-based (standard) and passkey-based
 * key protection methods.
 *
 * Architecture:
 * - Single source of truth for all cryptographic operations
 * - Supports two wrapping methods: password (PBKDF2) and passkey (PBKDF2 from credential)
 * - Uses AES-256-GCM for key wrapping (authenticated encryption)
 * - Stores wrapped keys separately by method (standard vs passkey)
 *
 * Security Features:
 * - RSA-2048 keypair generation with SHA-256
 * - PBKDF2 key derivation (100,000 iterations)
 * - AES-256-GCM authenticated encryption
 * - Random salts (32 bytes) and IVs (12 bytes)
 * - Client-side unwrapping only (server never sees unwrapped keys)
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified Crypto Handler
 *
 * Handles RSA key generation, key wrapping with password or passkey,
 * and secure storage of wrapped private keys.
 */
class Secure_Login_Collector_Unified_Crypto {

	/**
	 * PBKDF2 iteration count for key derivation.
	 */
	const PBKDF2_ITERATIONS = 100000;

	/**
	 * Salt length in bytes.
	 */
	const SALT_LENGTH = 32;

	/**
	 * IV length for AES-GCM (96 bits / 12 bytes recommended).
	 */
	const IV_LENGTH = 12;

	/**
	 * Wrapping key length (256 bits / 32 bytes for AES-256).
	 */
	const WRAPPING_KEY_LENGTH = 32;

	/**
	 * Generate RSA-2048 keypair.
	 *
	 * Creates a new RSA keypair with 2048-bit key size using SHA-256 digest.
	 * Returns both public and private keys in PEM format.
	 *
	 * @return array|WP_Error Array with 'public' and 'private' keys (PEM format), or WP_Error on failure.
	 */
	public function generate_keypair() {
		// Check OpenSSL availability.
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return new WP_Error(
				'openssl_missing',
				__( 'OpenSSL PHP extension is required for key generation.', 'secure-login-collector' )
			);
		}

		// Configure RSA keypair generation.
		$config = array(
			'digest_alg'       => 'sha256',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);

		// Generate keypair.
		$keypair = openssl_pkey_new( $config );
		if ( false === $keypair ) {
			return new WP_Error(
				'keypair_generation_failed',
				__( 'Failed to generate RSA keypair. Check OpenSSL configuration.', 'secure-login-collector' )
			);
		}

		// Extract private key (PEM format).
		$private_key_export = openssl_pkey_export( $keypair, $private_key );
		if ( false === $private_key_export ) {
			return new WP_Error(
				'private_key_export_failed',
				__( 'Failed to export private key.', 'secure-login-collector' )
			);
		}

		// Extract public key (PEM format).
		$key_details = openssl_pkey_get_details( $keypair );
		if ( false === $key_details || ! isset( $key_details['key'] ) ) {
			return new WP_Error(
				'public_key_export_failed',
				__( 'Failed to export public key.', 'secure-login-collector' )
			);
		}

		return array(
			'public'  => $key_details['key'],
			'private' => $private_key,
		);
	}

	/**
	 * Wrap private key with password or passkey-derived key.
	 *
	 * Wraps (encrypts) the RSA private key using AES-256-GCM with a key derived
	 * from either a password or passkey credential ID using PBKDF2.
	 *
	 * @param string $private_key  The RSA private key in PEM format.
	 * @param string $method       Wrapping method: 'password' or 'passkey'.
	 * @param string $key_material The password string or passkey credential ID (base64).
	 *
	 * @return array|WP_Error Array with wrapped data structure, or WP_Error on failure.
	 */
	public function wrap_private_key( $private_key, $method, $key_material ) {
		// Validate inputs.
		if ( empty( $private_key ) || empty( $key_material ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Private key and key material are required for wrapping.', 'secure-login-collector' )
			);
		}

		if ( ! in_array( $method, array( 'password', 'passkey' ), true ) ) {
			return new WP_Error(
				'invalid_method',
				__( 'Wrapping method must be "password" or "passkey".', 'secure-login-collector' )
			);
		}

		// Generate random salt.
		try {
			$salt = random_bytes( self::SALT_LENGTH );
		} catch ( Exception $e ) {
			return new WP_Error(
				'random_bytes_failed',
				__( 'Failed to generate random salt.', 'secure-login-collector' )
			);
		}

		// Derive wrapping key using PBKDF2.
		$wrapping_key = hash_pbkdf2(
			'sha256',
			$key_material,
			$salt,
			self::PBKDF2_ITERATIONS,
			self::WRAPPING_KEY_LENGTH,
			true // Raw binary output.
		);

		if ( false === $wrapping_key ) {
			return new WP_Error(
				'key_derivation_failed',
				__( 'Failed to derive wrapping key with PBKDF2.', 'secure-login-collector' )
			);
		}

		// Generate random IV for AES-GCM.
		try {
			$iv = random_bytes( self::IV_LENGTH );
		} catch ( Exception $e ) {
			return new WP_Error(
				'random_bytes_failed',
				__( 'Failed to generate random IV.', 'secure-login-collector' )
			);
		}

		// Encrypt private key with AES-256-GCM.
		$tag            = '';
		$encrypted_data = openssl_encrypt(
			$private_key,
			'aes-256-gcm',
			$wrapping_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'', // Additional authenticated data (empty).
			16  // Tag length (128 bits).
		);

		if ( false === $encrypted_data ) {
			return new WP_Error(
				'encryption_failed',
				__( 'Failed to encrypt private key with AES-256-GCM.', 'secure-login-collector' )
			);
		}

		// Return wrapped data structure.
		return array(
			'method'         => $method,
			'encrypted_data' => base64_encode( $encrypted_data ),
			'iv'             => base64_encode( $iv ),
			'salt'           => base64_encode( $salt ),
			'tag'            => base64_encode( $tag ),
			'algorithm'      => 'AES-256-GCM',
			'kdf'            => 'PBKDF2',
			'kdf_iterations' => self::PBKDF2_ITERATIONS,
			'kdf_hash'       => 'SHA-256',
		);
	}

	/**
	 * Unwrap private key with password or passkey-derived key.
	 *
	 * NOTE: This method is designed for CLIENT-SIDE use only.
	 * The server should NEVER call this method directly with user passwords or passkeys.
	 * This is provided as a reference implementation for JavaScript client-side decryption.
	 *
	 * @param array  $wrapped_data Data structure from wrap_private_key().
	 * @param string $method       Wrapping method: 'password' or 'passkey'.
	 * @param string $key_material The password string or passkey credential ID (base64).
	 *
	 * @return string|WP_Error Unwrapped private key in PEM format, or WP_Error on failure.
	 */
	public function unwrap_private_key( $wrapped_data, $method, $key_material ) {
		// Validate inputs.
		if ( ! is_array( $wrapped_data ) || empty( $key_material ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Wrapped data and key material are required for unwrapping.', 'secure-login-collector' )
			);
		}

		// Validate method matches.
		if ( ! isset( $wrapped_data['method'] ) || $wrapped_data['method'] !== $method ) {
			return new WP_Error(
				'method_mismatch',
				__( 'Wrapping method does not match wrapped data.', 'secure-login-collector' )
			);
		}

		// Extract wrapped data components.
		$encrypted_data = base64_decode( $wrapped_data['encrypted_data'] );
		$iv             = base64_decode( $wrapped_data['iv'] );
		$salt           = base64_decode( $wrapped_data['salt'] );
		$tag            = base64_decode( $wrapped_data['tag'] );

		if ( false === $encrypted_data || false === $iv || false === $salt || false === $tag ) {
			return new WP_Error(
				'invalid_wrapped_data',
				__( 'Wrapped data is corrupted or invalid.', 'secure-login-collector' )
			);
		}

		// Derive wrapping key using PBKDF2 (same as wrapping).
		$wrapping_key = hash_pbkdf2(
			'sha256',
			$key_material,
			$salt,
			self::PBKDF2_ITERATIONS,
			self::WRAPPING_KEY_LENGTH,
			true
		);

		if ( false === $wrapping_key ) {
			return new WP_Error(
				'key_derivation_failed',
				__( 'Failed to derive wrapping key for unwrapping.', 'secure-login-collector' )
			);
		}

		// Decrypt private key with AES-256-GCM.
		$decrypted_key = openssl_decrypt(
			$encrypted_data,
			'aes-256-gcm',
			$wrapping_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $decrypted_key ) {
			return new WP_Error(
				'decryption_failed',
				__( 'Failed to decrypt private key. Incorrect password or corrupted data.', 'secure-login-collector' )
			);
		}

		return $decrypted_key;
	}

	/**
	 * Derive wrapping key from password using PBKDF2.
	 *
	 * This is a helper method that can be called from JavaScript equivalent
	 * for client-side key derivation. Server-side use should be avoided.
	 *
	 * @param string $password User password.
	 * @param string $salt     Salt (base64 encoded).
	 *
	 * @return string|WP_Error Derived key (base64 encoded), or WP_Error on failure.
	 */
	public function derive_wrapping_key_password( $password, $salt ) {
		if ( empty( $password ) || empty( $salt ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Password and salt are required for key derivation.', 'secure-login-collector' )
			);
		}

		$salt_bytes = base64_decode( $salt );
		if ( false === $salt_bytes ) {
			return new WP_Error(
				'invalid_salt',
				__( 'Invalid salt format. Must be base64 encoded.', 'secure-login-collector' )
			);
		}

		$derived_key = hash_pbkdf2(
			'sha256',
			$password,
			$salt_bytes,
			self::PBKDF2_ITERATIONS,
			self::WRAPPING_KEY_LENGTH,
			true
		);

		if ( false === $derived_key ) {
			return new WP_Error(
				'key_derivation_failed',
				__( 'Failed to derive key from password.', 'secure-login-collector' )
			);
		}

		return base64_encode( $derived_key );
	}

	/**
	 * Derive wrapping key from passkey credential ID using PBKDF2.
	 *
	 * This is a helper method that can be called from JavaScript equivalent
	 * for client-side key derivation. Server-side use should be avoided.
	 *
	 * @param string $credential_id Passkey credential ID (base64 encoded).
	 * @param string $salt          Salt (base64 encoded).
	 *
	 * @return string|WP_Error Derived key (base64 encoded), or WP_Error on failure.
	 */
	public function derive_wrapping_key_passkey( $credential_id, $salt ) {
		if ( empty( $credential_id ) || empty( $salt ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Credential ID and salt are required for key derivation.', 'secure-login-collector' )
			);
		}

		$credential_bytes = base64_decode( $credential_id );
		$salt_bytes       = base64_decode( $salt );

		if ( false === $credential_bytes || false === $salt_bytes ) {
			return new WP_Error(
				'invalid_input_format',
				__( 'Credential ID and salt must be base64 encoded.', 'secure-login-collector' )
			);
		}

		$derived_key = hash_pbkdf2(
			'sha256',
			$credential_bytes,
			$salt_bytes,
			self::PBKDF2_ITERATIONS,
			self::WRAPPING_KEY_LENGTH,
			true
		);

		if ( false === $derived_key ) {
			return new WP_Error(
				'key_derivation_failed',
				__( 'Failed to derive key from passkey credential.', 'secure-login-collector' )
			);
		}

		return base64_encode( $derived_key );
	}

	/**
	 * Get public key for specified method.
	 *
	 * Returns the public key for either 'standard' (password-wrapped) or 'passkey' method.
	 *
	 * @param string $method Method: 'standard' or 'passkey'.
	 *
	 * @return string|WP_Error Public key in PEM format, or WP_Error if not found.
	 */
	public function get_public_key( $method ) {
		if ( ! in_array( $method, array( 'standard', 'passkey' ), true ) ) {
			return new WP_Error(
				'invalid_method',
				__( 'Method must be "standard" or "passkey".', 'secure-login-collector' )
			);
		}

		$option_name = 'seculoco_public_key_' . $method;
		$public_key  = get_option( $option_name );

		if ( empty( $public_key ) ) {
			return new WP_Error(
				'public_key_not_found',
				/* translators: %s: method name (standard or passkey) */
				sprintf( __( 'Public key not found for method: %s', 'secure-login-collector' ), $method )
			);
		}

		return $public_key;
	}

	/**
	 * Store wrapped private key and public key.
	 *
	 * Saves the wrapped private key data and corresponding public key to WordPress options.
	 * Each method (standard/passkey) has separate storage.
	 *
	 * @param string $method       Method: 'standard' or 'passkey'.
	 * @param array  $wrapped_data Wrapped private key data from wrap_private_key().
	 * @param string $public_key   Public key in PEM format.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function store_wrapped_key( $method, $wrapped_data, $public_key ) {
		// Validate method.
		if ( ! in_array( $method, array( 'standard', 'passkey' ), true ) ) {
			return new WP_Error(
				'invalid_method',
				__( 'Method must be "standard" or "passkey".', 'secure-login-collector' )
			);
		}

		// Validate wrapped data.
		if ( ! is_array( $wrapped_data ) || empty( $public_key ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'Wrapped data and public key are required for storage.', 'secure-login-collector' )
			);
		}

		// Store wrapped private key.
		$wrapped_key_option = 'seculoco_wrapped_private_key_' . $method;
		$updated_wrapped    = update_option( $wrapped_key_option, $wrapped_data );

		// Store public key.
		$public_key_option = 'seculoco_public_key_' . $method;
		$updated_public    = update_option( $public_key_option, $public_key );

		if ( false === $updated_wrapped || false === $updated_public ) {
			return new WP_Error(
				'storage_failed',
				__( 'Failed to store wrapped key or public key in database.', 'secure-login-collector' )
			);
		}

		// Log the operation.
		$this->log_key_operation( 'key_stored', $method );

		return true;
	}

	/**
	 * Get wrapped private key data.
	 *
	 * Retrieves the wrapped private key data structure for the specified method.
	 *
	 * @param string $method Method: 'standard' or 'passkey'.
	 *
	 * @return array|WP_Error Wrapped key data array, or WP_Error if not found.
	 */
	public function get_wrapped_key( $method ) {
		if ( ! in_array( $method, array( 'standard', 'passkey' ), true ) ) {
			return new WP_Error(
				'invalid_method',
				__( 'Method must be "standard" or "passkey".', 'secure-login-collector' )
			);
		}

		$option_name  = 'seculoco_wrapped_private_key_' . $method;
		$wrapped_data = get_option( $option_name );

		if ( empty( $wrapped_data ) ) {
			return new WP_Error(
				'wrapped_key_not_found',
				/* translators: %s: method name (standard or passkey) */
				sprintf( __( 'Wrapped private key not found for method: %s', 'secure-login-collector' ), $method )
			);
		}

		return $wrapped_data;
	}

	/**
	 * Check if keys exist for a method.
	 *
	 * @param string $method Method: 'standard' or 'passkey'.
	 *
	 * @return bool True if both public and wrapped private keys exist.
	 */
	public function has_keys( $method ) {
		if ( ! in_array( $method, array( 'standard', 'passkey' ), true ) ) {
			return false;
		}

		$public_key   = get_option( 'seculoco_public_key_' . $method );
		$wrapped_key  = get_option( 'seculoco_wrapped_private_key_' . $method );

		return ! empty( $public_key ) && ! empty( $wrapped_key );
	}

	/**
	 * Delete keys for a method.
	 *
	 * Removes both public and wrapped private keys for the specified method.
	 *
	 * @param string $method Method: 'standard' or 'passkey'.
	 *
	 * @return bool True on success.
	 */
	public function delete_keys( $method ) {
		if ( ! in_array( $method, array( 'standard', 'passkey' ), true ) ) {
			return false;
		}

		delete_option( 'seculoco_public_key_' . $method );
		delete_option( 'seculoco_wrapped_private_key_' . $method );

		$this->log_key_operation( 'keys_deleted', $method );

		return true;
	}

	/**
	 * Log key operations for audit trail.
	 *
	 * Records cryptographic operations in an audit log for security monitoring.
	 *
	 * @param string $operation Operation type (e.g., 'key_stored', 'keys_deleted').
	 * @param string $method    Method: 'standard' or 'passkey'.
	 */
	private function log_key_operation( $operation, $method ) {
		$log = get_option( 'seculoco_unified_crypto_log', array() );

		// Keep only last 100 operations.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		$log[] = array(
			'operation' => $operation,
			'method'    => $method,
			'timestamp' => time(),
			'user_id'   => get_current_user_id(),
			'ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		);

		update_option( 'seculoco_unified_crypto_log', $log );
	}

	/**
	 * Get cryptographic operation log.
	 *
	 * Returns the audit log of all cryptographic operations.
	 *
	 * @param int $limit Maximum number of log entries to return (default: 50).
	 *
	 * @return array Array of log entries.
	 */
	public function get_operation_log( $limit = 50 ) {
		$log = get_option( 'seculoco_unified_crypto_log', array() );

		if ( $limit > 0 && count( $log ) > $limit ) {
			$log = array_slice( $log, -$limit );
		}

		return array_reverse( $log ); // Most recent first.
	}

	/**
	 * Get system status for unified crypto.
	 *
	 * Returns status information about available keys and methods.
	 *
	 * @return array Status information.
	 */
	public function get_status() {
		return array(
			'standard' => array(
				'has_public_key'  => ! empty( get_option( 'seculoco_public_key_standard' ) ),
				'has_wrapped_key' => ! empty( get_option( 'seculoco_wrapped_private_key_standard' ) ),
			),
			'passkey'  => array(
				'has_public_key'  => ! empty( get_option( 'seculoco_public_key_passkey' ) ),
				'has_wrapped_key' => ! empty( get_option( 'seculoco_wrapped_private_key_passkey' ) ),
			),
			'openssl'  => array(
				'available' => function_exists( 'openssl_pkey_new' ),
				'version'   => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'Unknown',
			),
		);
	}
}
