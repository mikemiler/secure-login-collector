<?php
/**
 * Master Key Manager
 * 
 * Manages the Master Wrapping Key (MWK) that protects the RSA private key.
 * The MWK is wrapped by each registered passkey, allowing multiple passkeys
 * to unlock the same private key.
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Master_Key_Manager
 * Handles master key generation and wrapping operations.
 */
class Master_Key_Manager {

	/**
	 * Table name for wrapped keys.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'secure_login_wrapped_keys';
		
		// Create table if needed
		add_action( 'init', array( $this, 'maybe_create_table' ) );
	}

	/**
	 * Create wrapped keys table if it doesn't exist.
	 */
	public function maybe_create_table() {
		global $wpdb;
		
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			key_type varchar(50) NOT NULL,
			key_identifier varchar(255) NOT NULL,
			wrapped_data longtext NOT NULL,
			passkey_credential_id varchar(255),
			algorithm varchar(50) DEFAULT 'AES-256-GCM',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			last_used datetime,
			PRIMARY KEY (id),
			UNIQUE KEY unique_identifier (user_id, key_type, key_identifier),
			KEY user_id (user_id),
			KEY passkey_credential_id (passkey_credential_id)
		) $charset_collate;";
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate a new Master Wrapping Key (MWK).
	 *
	 * @return string Base64-encoded MWK (256-bit).
	 */
	public function generate_master_key() {
		// Generate cryptographically secure 256-bit key
		$mwk = random_bytes( 32 );
		return base64_encode( $mwk );
	}

	/**
	 * Wrap the RSA private key with the Master Wrapping Key.
	 *
	 * @param string $private_key The RSA private key to wrap.
	 * @param string $master_key  The Master Wrapping Key.
	 * @return array Wrapped key data with IV and tag.
	 */
	public function wrap_private_key_with_mwk( $private_key, $master_key ) {
		$mwk = base64_decode( $master_key );
		
		// Generate random IV
		$iv = random_bytes( 16 );
		
		// Encrypt with AES-256-GCM for authenticated encryption
		$tag = '';
		$encrypted = openssl_encrypt(
			$private_key,
			'aes-256-gcm',
			$mwk,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		
		if ( false === $encrypted ) {
			return false;
		}
		
		return array(
			'wrapped_key' => base64_encode( $encrypted ),
			'iv'          => base64_encode( $iv ),
			'tag'         => base64_encode( $tag ),
			'algorithm'   => 'AES-256-GCM',
			'version'     => '1.0'
		);
	}

	/**
	 * Unwrap the RSA private key using the Master Wrapping Key.
	 *
	 * @param array  $wrapped_data The wrapped key data.
	 * @param string $master_key   The Master Wrapping Key.
	 * @return string|false The RSA private key or false on failure.
	 */
	public function unwrap_private_key_with_mwk( $wrapped_data, $master_key ) {
		if ( ! isset( $wrapped_data['wrapped_key'], $wrapped_data['iv'], $wrapped_data['tag'] ) ) {
			return false;
		}
		
		$mwk = base64_decode( $master_key );
		$encrypted = base64_decode( $wrapped_data['wrapped_key'] );
		$iv = base64_decode( $wrapped_data['iv'] );
		$tag = base64_decode( $wrapped_data['tag'] );
		
		// Decrypt with AES-256-GCM
		$decrypted = openssl_decrypt(
			$encrypted,
			'aes-256-gcm',
			$mwk,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		
		return $decrypted;
	}

	/**
	 * Store a wrapped key in the database.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $key_type     Type of key (e.g., 'mwk', 'private_key').
	 * @param string $identifier   Unique identifier for the key.
	 * @param array  $wrapped_data Wrapped key data.
	 * @param string $credential_id Optional passkey credential ID.
	 * @return int|false Insert ID or false on failure.
	 */
	public function store_wrapped_key( $user_id, $key_type, $identifier, $wrapped_data, $credential_id = null ) {
		global $wpdb;
		
		// Check if key already exists
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table_name} 
			 WHERE user_id = %d AND key_type = %s AND key_identifier = %s",
			$user_id,
			$key_type,
			$identifier
		) );
		
		$data = array(
			'user_id'              => $user_id,
			'key_type'             => $key_type,
			'key_identifier'       => $identifier,
			'wrapped_data'         => wp_json_encode( $wrapped_data ),
			'passkey_credential_id' => $credential_id,
			'algorithm'            => $wrapped_data['algorithm'] ?? 'AES-256-GCM'
		);
		
		if ( $existing ) {
			// Update existing
			$result = $wpdb->update(
				$this->table_name,
				$data,
				array( 'id' => $existing )
			);
			return $result !== false ? $existing : false;
		} else {
			// Insert new
			$result = $wpdb->insert( $this->table_name, $data );
			return $result !== false ? $wpdb->insert_id : false;
		}
	}

	/**
	 * Retrieve a wrapped key from the database.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $key_type   Type of key.
	 * @param string $identifier Key identifier.
	 * @return array|false Wrapped key data or false if not found.
	 */
	public function get_wrapped_key( $user_id, $key_type, $identifier ) {
		global $wpdb;
		
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table_name} 
			 WHERE user_id = %d AND key_type = %s AND key_identifier = %s",
			$user_id,
			$key_type,
			$identifier
		) );
		
		if ( ! $row ) {
			return false;
		}
		
		// Update last used timestamp
		$wpdb->update(
			$this->table_name,
			array( 'last_used' => current_time( 'mysql' ) ),
			array( 'id' => $row->id )
		);
		
		return json_decode( $row->wrapped_data, true );
	}

	/**
	 * Get all wrapped MWKs for a user (one per passkey).
	 *
	 * @param int $user_id User ID.
	 * @return array Array of wrapped MWKs with credential IDs.
	 */
	public function get_user_wrapped_mwks( $user_id ) {
		global $wpdb;
		
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT passkey_credential_id, wrapped_data, created_at, last_used 
			 FROM {$this->table_name} 
			 WHERE user_id = %d AND key_type = 'mwk' 
			 ORDER BY created_at DESC",
			$user_id
		) );
		
		$wrapped_mwks = array();
		foreach ( $results as $row ) {
			$wrapped_mwks[] = array(
				'credential_id' => $row->passkey_credential_id,
				'wrapped_data'  => json_decode( $row->wrapped_data, true ),
				'created_at'    => $row->created_at,
				'last_used'     => $row->last_used
			);
		}
		
		return $wrapped_mwks;
	}

	/**
	 * Delete a wrapped key.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $credential_id Passkey credential ID.
	 * @return bool Success status.
	 */
	public function delete_wrapped_mwk( $user_id, $credential_id ) {
		global $wpdb;
		
		return false !== $wpdb->delete(
			$this->table_name,
			array(
				'user_id'               => $user_id,
				'key_type'              => 'mwk',
				'passkey_credential_id' => $credential_id
			)
		);
	}

	/**
	 * Check if user has any wrapped MWKs.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if user has at least one wrapped MWK.
	 */
	public function user_has_mwk( $user_id ) {
		global $wpdb;
		
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} 
			 WHERE user_id = %d AND key_type = 'mwk'",
			$user_id
		) );
		
		return $count > 0;
	}

	/**
	 * Wrap the Master Wrapping Key with a passkey-derived key.
	 *
	 * @param string $master_key    The MWK to wrap.
	 * @param string $wrapping_key  Key derived from passkey authentication.
	 * @return array Wrapped MWK data.
	 */
	public function wrap_mwk_with_passkey( $master_key, $wrapping_key ) {
		// Generate random IV
		$iv = random_bytes( 16 );
		
		// Encrypt MWK with passkey-derived key
		$tag = '';
		$encrypted = openssl_encrypt(
			$master_key,
			'aes-256-gcm',
			$wrapping_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		
		if ( false === $encrypted ) {
			return false;
		}
		
		return array(
			'wrapped_mwk' => base64_encode( $encrypted ),
			'iv'          => base64_encode( $iv ),
			'tag'         => base64_encode( $tag ),
			'algorithm'   => 'AES-256-GCM',
			'version'     => '1.0'
		);
	}

	/**
	 * Unwrap the Master Wrapping Key using a passkey-derived key.
	 *
	 * @param array  $wrapped_data  The wrapped MWK data.
	 * @param string $wrapping_key  Key derived from passkey authentication.
	 * @return string|false The MWK or false on failure.
	 */
	public function unwrap_mwk_with_passkey( $wrapped_data, $wrapping_key ) {
		if ( ! isset( $wrapped_data['wrapped_mwk'], $wrapped_data['iv'], $wrapped_data['tag'] ) ) {
			return false;
		}
		
		$encrypted = base64_decode( $wrapped_data['wrapped_mwk'] );
		$iv = base64_decode( $wrapped_data['iv'] );
		$tag = base64_decode( $wrapped_data['tag'] );
		
		// Decrypt MWK
		$decrypted = openssl_decrypt(
			$encrypted,
			'aes-256-gcm',
			$wrapping_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		
		return $decrypted;
	}
}