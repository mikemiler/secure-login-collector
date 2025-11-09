<?php
/**
 * Unified Cryptography Module (Free)
 *
 * Provides RSA key generation plus password-based key wrapping for the free build.
 * Premium editions override the class via the `seculoco_unified_crypto_class` filter
 * to add passkey wrapping and other advanced crypto features.
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Secure_Login_Collector_Unified_Crypto {

	const PBKDF2_ITERATIONS = 100000;
	const SALT_LENGTH       = 32;
	const IV_LENGTH         = 12;
	const WRAPPING_KEY_LEN  = 32;

	/**
	 * Generate RSA-2048 keypair.
	 *
	 * @return array|WP_Error
	 */
	public function generate_keypair() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return new WP_Error(
				'openssl_missing',
				__( 'OpenSSL PHP extension is required for key generation.', 'secure-login-collector' )
			);
		}

		$config  = array(
			'digest_alg'       => 'sha256',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);
		$keypair = openssl_pkey_new( $config );

		if ( false === $keypair ) {
			return new WP_Error(
				'keypair_generation_failed',
				__( 'Failed to generate RSA keypair. Check OpenSSL configuration.', 'secure-login-collector' )
			);
		}

		$exported = openssl_pkey_export( $keypair, $private_key );
		if ( false === $exported ) {
			return new WP_Error(
				'private_key_export_failed',
				__( 'Failed to export private key.', 'secure-login-collector' )
			);
		}

		$details = openssl_pkey_get_details( $keypair );
		if ( ! $details || empty( $details['key'] ) ) {
			return new WP_Error(
				'public_key_export_failed',
				__( 'Failed to export public key.', 'secure-login-collector' )
			);
		}

		return array(
			'public'  => $details['key'],
			'private' => $private_key,
		);
	}

	/**
	 * Wrap private key with password-derived key.
	 *
	 * @param string $private_key  RSA private key.
	 * @param string $method       Wrapping method (only 'password' supported).
	 * @param string $password     Password used for key derivation.
	 * @return array|WP_Error
	 */
	public function wrap_private_key( $private_key, $method, $password ) {
		if ( 'password' !== $method ) {
			return new WP_Error(
				'invalid_method',
				__( 'Only password-based key wrapping is available in the free version.', 'secure-login-collector' )
			);
		}

		if ( empty( $private_key ) || empty( $password ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Private key and password are required for wrapping.', 'secure-login-collector' )
			);
		}

		try {
			$salt = random_bytes( self::SALT_LENGTH );
			$iv   = random_bytes( self::IV_LENGTH );
		} catch ( Exception $e ) {
			return new WP_Error(
				'random_bytes_failed',
				__( 'Failed to generate cryptographic salt/IV.', 'secure-login-collector' )
			);
		}

		$key = hash_pbkdf2(
			'sha256',
			$password,
			$salt,
			self::PBKDF2_ITERATIONS,
			self::WRAPPING_KEY_LEN,
			true
		);

		if ( false === $key ) {
			return new WP_Error(
				'key_derivation_failed',
				__( 'Failed to derive wrapping key with PBKDF2.', 'secure-login-collector' )
			);
		}

		$tag     = '';
		$cipher  = openssl_encrypt(
			$private_key,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $cipher ) {
			return new WP_Error(
				'encryption_failed',
				__( 'Failed to encrypt private key with AES-256-GCM.', 'secure-login-collector' )
			);
		}

		return array(
			'method'         => 'password',
			'encrypted_data' => base64_encode( $cipher ),
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
	 * Unwrap private key (helper for reference/JS parity).
	 *
	 * @param array  $wrapped_data Wrapped payload.
	 * @param string $method       Method (password only).
	 * @param string $password     Password used during wrapping.
	 * @return string|WP_Error
	 */
	public function unwrap_private_key( $wrapped_data, $method, $password ) {
		if ( 'password' !== $method ) {
			return new WP_Error(
				'invalid_method',
				__( 'Only password-based key wrapping is available in the free version.', 'secure-login-collector' )
			);
		}

		if ( ! is_array( $wrapped_data ) || empty( $password ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Wrapped data and password are required for unwrapping.', 'secure-login-collector' )
			);
		}

		$encrypted = base64_decode( $wrapped_data['encrypted_data'] ?? '' );
		$iv        = base64_decode( $wrapped_data['iv'] ?? '' );
		$salt      = base64_decode( $wrapped_data['salt'] ?? '' );
		$tag       = base64_decode( $wrapped_data['tag'] ?? '' );

		if ( false === $encrypted || false === $iv || false === $salt || false === $tag ) {
			return new WP_Error(
				'invalid_wrapped_data',
				__( 'Wrapped data is corrupted or invalid.', 'secure-login-collector' )
			);
		}

		$key = hash_pbkdf2(
			'sha256',
			$password,
			$salt,
			self::PBKDF2_ITERATIONS,
			self::WRAPPING_KEY_LEN,
			true
		);

		if ( false === $key ) {
			return new WP_Error(
				'key_derivation_failed',
				__( 'Failed to derive wrapping key for unwrapping.', 'secure-login-collector' )
			);
		}

		$private_key = openssl_decrypt(
			$encrypted,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $private_key ) {
			return new WP_Error(
				'decryption_failed',
				__( 'Failed to decrypt private key. Incorrect password or corrupted data.', 'secure-login-collector' )
			);
		}

		return $private_key;
	}

	/**
	 * Store wrapped key + public key for the standard method.
	 *
	 * @param string $method       Method identifier (standard only).
	 * @param array  $wrapped_data Wrapped key data.
	 * @param string $public_key   Public key.
	 * @return bool|WP_Error
	 */
	public function store_wrapped_key( $method, $wrapped_data, $public_key ) {
		if ( 'standard' !== $method ) {
			return new WP_Error(
				'invalid_method',
				__( 'Only the standard encryption method is available in the free version.', 'secure-login-collector' )
			);
		}

		if ( ! is_array( $wrapped_data ) || empty( $public_key ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'Wrapped data and public key are required for storage.', 'secure-login-collector' )
			);
		}

		$wrapped_key_option = SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD;
		$public_key_option  = SECULOCO_OPTION_PUBLIC_KEY_STANDARD;

		$wrapped_updated = update_option( $wrapped_key_option, $wrapped_data );
		$public_updated  = update_option( $public_key_option, $public_key );

		if ( false === $wrapped_updated || false === $public_updated ) {
			return new WP_Error(
				'storage_failed',
				__( 'Failed to store wrapped/private keys in the database.', 'secure-login-collector' )
			);
		}

		$this->log_key_operation( 'key_stored', $method );

		return true;
	}

	/**
	 * Retrieve public key for the standard method.
	 *
	 * @param string $method Method identifier.
	 * @return string|WP_Error
	 */
	public function get_public_key( $method ) {
		if ( 'standard' !== $method ) {
			return new WP_Error(
				'invalid_method',
				__( 'Only the standard encryption method is available in the free version.', 'secure-login-collector' )
			);
		}

		$public_key = get_option( SECULOCO_OPTION_PUBLIC_KEY_STANDARD );
		if ( empty( $public_key ) ) {
			return new WP_Error(
				'public_key_not_found',
				__( 'Public key not found.', 'secure-login-collector' )
			);
		}

		return $public_key;
	}

	/**
	 * Retrieve wrapped key for the standard method.
	 *
	 * @param string $method Method identifier.
	 * @return array|WP_Error
	 */
	public function get_wrapped_key( $method ) {
		if ( 'standard' !== $method ) {
			return new WP_Error(
				'invalid_method',
				__( 'Only the standard encryption method is available in the free version.', 'secure-login-collector' )
			);
		}

		$data = get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD );
		if ( empty( $data ) ) {
			return new WP_Error(
				'wrapped_key_not_found',
				__( 'Wrapped private key not found.', 'secure-login-collector' )
			);
		}

		return $data;
	}

	/**
	 * Check if keys exist for the standard method.
	 *
	 * @param string $method Method identifier.
	 * @return bool
	 */
	public function has_keys( $method ) {
		if ( 'standard' !== $method ) {
			return false;
		}

		$public_key  = get_option( SECULOCO_OPTION_PUBLIC_KEY_STANDARD );
		$wrapped_key = get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD );

		return ! empty( $public_key ) && ! empty( $wrapped_key );
	}

	/**
	 * Delete keys for the standard method.
	 *
	 * @param string $method Method identifier.
	 * @return bool
	 */
	public function delete_keys( $method ) {
		if ( 'standard' !== $method ) {
			return false;
		}

		delete_option( SECULOCO_OPTION_PUBLIC_KEY_STANDARD );
		delete_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD );

		$this->log_key_operation( 'keys_deleted', $method );

		return true;
	}

	/**
	 * Record key operations for auditing.
	 *
	 * @param string $operation Operation label.
	 * @param string $method    Method identifier.
	 */
	private function log_key_operation( $operation, $method ) {
		$log = get_option( SECULOCO_OPTION_UNIFIED_CRYPTO_LOG, array() );

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

		update_option( SECULOCO_OPTION_UNIFIED_CRYPTO_LOG, $log );
	}

	/**
	 * Retrieve recent key operations.
	 *
	 * @param int $limit Number of entries to return.
	 * @return array
	 */
	public function get_operation_log( $limit = 50 ) {
		$log = get_option( SECULOCO_OPTION_UNIFIED_CRYPTO_LOG, array() );

		if ( $limit > 0 && count( $log ) > $limit ) {
			$log = array_slice( $log, -$limit );
		}

		return array_reverse( $log );
	}

	/**
	 * Get current crypto status.
	 *
	 * @return array
	 */
	public function get_status() {
		return array(
			'standard' => array(
				'has_public_key'  => $this->has_keys( 'standard' ),
				'has_wrapped_key' => ! empty( get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD ) ),
			),
			'openssl'  => array(
				'available' => function_exists( 'openssl_pkey_new' ),
				'version'   => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'Unknown',
			),
		);
	}
}
