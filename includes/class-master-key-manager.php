<?php
/**
 * Master Key Manager
 *
 * Manages wrapped keys storage for the plugin.
 * Note: The original MWK (Master Wrapping Key) architecture was replaced with direct passkey wrapping.
 * This class now primarily maintains the database table structure for backward compatibility and
 * provides key deletion functionality for passkey management.
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Master_Key_Manager
 * Handles wrapped keys database operations.
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

		// Create table if needed.
		add_action( 'init', array( $this, 'maybe_create_table' ) );
	}

	/**
	 * Create wrapped keys table if it doesn't exist.
	 * Maintains table structure for backward compatibility with existing installations.
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
	 * Delete a wrapped key.
	 * Used when deleting individual passkeys.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $credential_id Passkey credential ID.
	 * @return bool Success status.
	 */
	public function delete_wrapped_mwk( $user_id, $credential_id ) {
		global $wpdb;

		// Ensure table exists before deleting.
		$this->maybe_create_table();

		return false !== $wpdb->delete(
			$this->table_name,
			array(
				'user_id'               => $user_id,
				'key_type'              => 'mwk',
				'passkey_credential_id' => $credential_id,
			)
		);
	}

	/**
	 * Delete all wrapped MWKs for a user.
	 * Used when deleting all passkeys for a user.
	 *
	 * @param int $user_id User ID.
	 * @return bool Success status.
	 */
	public function delete_all_user_mwks( $user_id ) {
		global $wpdb;

		// Ensure table exists before deleting.
		$this->maybe_create_table();

		return false !== $wpdb->delete(
			$this->table_name,
			array(
				'user_id'  => $user_id,
				'key_type' => 'mwk',
			)
		);
	}

	/**
	 * Unwrap the Master Wrapping Key using a passkey-derived key.
	 * Kept for potential client-side unwrapping operations and backward compatibility.
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
		$iv        = base64_decode( $wrapped_data['iv'] );
		$tag       = base64_decode( $wrapped_data['tag'] );

		// Decrypt MWK.
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
