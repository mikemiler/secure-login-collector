<?php
/**
 * Dual-Key Encryption Handler
 * 
 * Implements separate RSA keypairs for free and pro versions:
 * - Free: Standard RSA keypair (no passkey required)
 * - Pro: Separate RSA keypair (created with first passkey, deleted with last)
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Secure_Login_Encryption_Handler_V2 {

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
		add_action( 'wp_ajax_slc_get_wrapped_private_key', array( $this, 'handle_get_wrapped_private_key' ) );
		add_action( 'wp_ajax_slc_initialize_free_keys', array( $this, 'handle_initialize_free_keys' ) );
		add_action( 'wp_ajax_slc_initialize_pro_keys', array( $this, 'handle_initialize_pro_keys' ) );
		add_action( 'wp_ajax_slc_delete_pro_keys', array( $this, 'handle_delete_pro_keys' ) );
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
			'private_key_bits' => 2048,
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
	 * Initialize free version keys (standard RSA without passkey).
	 *
	 * @return array|WP_Error Result of key initialization.
	 */
	public function initialize_free_keys() {
		// Check if free keys already exist
		$public_key_free = get_option( 'secure_login_public_key_free' );
		$private_key_free_encrypted = get_option( 'secure_login_private_key_free_encrypted' );
		
		if ( $public_key_free && $private_key_free_encrypted ) {
			return array( 'status' => 'already_initialized', 'type' => 'free' );
		}

		// Generate new RSA keypair for free version
		$keypair = $this->generate_rsa_keypair();
		if ( is_wp_error( $keypair ) ) {
			return $keypair;
		}

		// Store public key (publicly accessible)
		update_option( 'secure_login_public_key_free', $keypair['public'] );

		// Encrypt private key with WordPress salts for free version
		$encrypted_private = $this->encrypt_with_wp_salts( $keypair['private'] );
		update_option( 'secure_login_private_key_free_encrypted', $encrypted_private );

		// Log initialization
		$this->log_key_operation( 'free_keys_initialized' );

		return array( 
			'status' => 'success', 
			'type' => 'free',
			'message' => 'Free version keys initialized'
		);
	}

	/**
	 * Initialize pro version keys with passkey wrapping.
	 * Called when first passkey is registered.
	 *
	 * @param string $passkey_derived_key Key derived from passkey authentication.
	 * @return array|WP_Error Result of key initialization.
	 */
	public function initialize_pro_keys( $passkey_derived_key ) {
		// Check if pro keys already exist
		$public_key_pro = get_option( 'secure_login_public_key_pro' );
		$wrapped_key_pro = get_option( 'secure_login_wrapped_private_key_pro' );
		
		if ( $public_key_pro && $wrapped_key_pro ) {
			return array( 'status' => 'already_initialized', 'type' => 'pro' );
		}

		// Generate new RSA keypair for pro version
		$keypair = $this->generate_rsa_keypair();
		if ( is_wp_error( $keypair ) ) {
			return $keypair;
		}

		// Store public key (publicly accessible)
		update_option( 'secure_login_public_key_pro', $keypair['public'] );

		// Wrap private key with passkey-derived key
		$wrapped = $this->wrap_private_key( $keypair['private'], $passkey_derived_key );
		if ( is_wp_error( $wrapped ) ) {
			// Clean up on failure
			delete_option( 'secure_login_public_key_pro' );
			return $wrapped;
		}

		update_option( 'secure_login_wrapped_private_key_pro', $wrapped );

		// Mark pro version as active
		update_option( 'secure_login_pro_keys_active', true );

		// Log initialization
		$this->log_key_operation( 'pro_keys_initialized' );

		return array( 
			'status' => 'success', 
			'type' => 'pro',
			'message' => 'Pro version keys initialized with passkey'
		);
	}

	/**
	 * Delete pro version keys.
	 * Called when last passkey is deleted.
	 *
	 * @return array Result of deletion.
	 */
	public function delete_pro_keys() {
		// Delete pro keys
		delete_option( 'secure_login_public_key_pro' );
		delete_option( 'secure_login_wrapped_private_key_pro' );
		delete_option( 'secure_login_pro_keys_active' );

		// Log deletion
		$this->log_key_operation( 'pro_keys_deleted' );

		return array( 
			'status' => 'success',
			'message' => 'Pro version keys deleted'
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

		return array(
			'encrypted' => base64_encode( $encrypted ),
			'iv'        => base64_encode( $iv ),
			'tag'       => base64_encode( $tag ),
			'algorithm' => 'AES-256-GCM',
			'wrapped_at' => time(),
			'version'   => '2.0', // Version 2.0 for dual-key system
		);
	}

	/**
	 * Encrypt with WordPress salts (for free version).
	 *
	 * @param string $data Data to encrypt.
	 * @return array Encrypted data with IV.
	 */
	private function encrypt_with_wp_salts( $data ) {
		$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		$iv = random_bytes( 16 );
		
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
	 * Decrypt with WordPress salts (for free version).
	 *
	 * @param array $encrypted_data Encrypted data with IV.
	 * @return string|false Decrypted data or false.
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
	 * Returns appropriate public key based on available keys.
	 */
	public function handle_get_public_key() {
		// Check if pro keys are active and available
		$pro_active = get_option( 'secure_login_pro_keys_active', false );
		$public_key_pro = get_option( 'secure_login_public_key_pro' );
		
		// Use pro key if available and active
		if ( $pro_active && $public_key_pro ) {
			wp_send_json_success( array(
				'public_key' => $public_key_pro,
				'algorithm'  => 'RSA-OAEP',
				'key_size'   => 2048,
				'type'       => 'pro',
			) );
			return;
		}

		// Otherwise use free key
		$public_key_free = get_option( 'secure_login_public_key_free' );
		
		// Initialize free keys if not exists
		if ( ! $public_key_free ) {
			$result = $this->initialize_free_keys();
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( 'Failed to initialize encryption keys: ' . $result->get_error_message() );
				return;
			}
			$public_key_free = get_option( 'secure_login_public_key_free' );
		}

		wp_send_json_success( array(
			'public_key' => $public_key_free,
			'algorithm'  => 'RSA-OAEP',
			'key_size'   => 2048,
			'type'       => 'free',
		) );
	}

	/**
	 * Handle request for wrapped private key (admin only).
	 * Returns the appropriate private key based on the entry.
	 */
	public function handle_get_wrapped_private_key() {
		// Check admin permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Verify nonce
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'secure_login_admin_nonce' ) && 
		     ! wp_verify_nonce( $nonce, 'slc_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		// Get the entry ID to determine which key was used
		$entry_id = intval( $_POST['entry_id'] ?? 0 );
		
		if ( $entry_id ) {
			// Check which key was used for this entry
			global $wpdb;
			$table = $wpdb->prefix . 'secure_login_data';
			$entry = $wpdb->get_row( $wpdb->prepare( 
				"SELECT metadata FROM $table WHERE id = %d",
				$entry_id
			) );
			
			if ( $entry ) {
				$metadata = json_decode( $entry->metadata, true );
				$is_pro = isset( $metadata['is_pro_encrypted'] ) && $metadata['is_pro_encrypted'];
				
				if ( $is_pro ) {
					// Return pro wrapped key
					$wrapped_key = get_option( 'secure_login_wrapped_private_key_pro' );
					if ( $wrapped_key ) {
						$this->log_key_access( get_current_user_id(), 'pro' );
						wp_send_json_success( array(
							'wrapped_key' => $wrapped_key,
							'type' => 'pro',
							'message' => 'Use passkey to unwrap and decrypt',
						) );
						return;
					}
				}
			}
		}

		// Default to free key
		$encrypted_key = get_option( 'secure_login_private_key_free_encrypted' );
		
		if ( ! $encrypted_key ) {
			wp_send_json_error( 'No private key available' );
			return;
		}

		// For free version, decrypt with WP salts and return
		$private_key = $this->decrypt_with_wp_salts( $encrypted_key );
		if ( ! $private_key ) {
			wp_send_json_error( 'Failed to decrypt private key' );
			return;
		}

		$this->log_key_access( get_current_user_id(), 'free' );

		// Return as a "wrapped" format for consistency
		wp_send_json_success( array(
			'private_key' => base64_encode( $private_key ),
			'type' => 'free',
			'message' => 'Free version key - no passkey required',
		) );
	}

	/**
	 * Handle AJAX to initialize free keys.
	 */
	public function handle_initialize_free_keys() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		$result = $this->initialize_free_keys();
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX to initialize pro keys.
	 */
	public function handle_initialize_pro_keys() {
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

		$result = $this->initialize_pro_keys( $passkey_key );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX to delete pro keys.
	 */
	public function handle_delete_pro_keys() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		$result = $this->delete_pro_keys();
		wp_send_json_success( $result );
	}

	/**
	 * Log key access for audit trail.
	 *
	 * @param int    $user_id User accessing the key.
	 * @param string $type    Type of key accessed ('free' or 'pro').
	 */
	private function log_key_access( $user_id, $type = 'unknown' ) {
		$log = get_option( 'secure_login_key_access_log', array() );
		
		// Keep only last 100 entries
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		$log[] = array(
			'user_id'   => $user_id,
			'timestamp' => time(),
			'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
			'action'    => 'key_retrieved',
			'type'      => $type,
		);

		update_option( 'secure_login_key_access_log', $log );
	}

	/**
	 * Log key operations for audit.
	 *
	 * @param string $operation Operation performed.
	 */
	private function log_key_operation( $operation ) {
		$log = get_option( 'secure_login_key_operations_log', array() );
		
		// Keep only last 50 operations
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		$log[] = array(
			'operation' => $operation,
			'timestamp' => time(),
			'user_id'   => get_current_user_id(),
		);

		update_option( 'secure_login_key_operations_log', $log );
	}

	/**
	 * Get the appropriate public key based on context.
	 *
	 * @param bool $prefer_pro Whether to prefer pro key if available.
	 * @return string|WP_Error Public key or error.
	 */
	public function get_public_key( $prefer_pro = true ) {
		if ( $prefer_pro ) {
			$pro_active = get_option( 'secure_login_pro_keys_active', false );
			$public_key_pro = get_option( 'secure_login_public_key_pro' );
			
			if ( $pro_active && $public_key_pro ) {
				return $public_key_pro;
			}
		}

		$public_key_free = get_option( 'secure_login_public_key_free' );
		
		if ( ! $public_key_free ) {
			// Try to initialize free keys if admin
			if ( current_user_can( 'manage_options' ) ) {
				$result = $this->initialize_free_keys();
				if ( ! is_wp_error( $result ) ) {
					return get_option( 'secure_login_public_key_free' );
				}
			}
			return new WP_Error( 'no_public_key', 'Public key not initialized' );
		}
		
		return $public_key_free;
	}

	/**
	 * Check if pro keys are active.
	 *
	 * @return bool True if pro keys are active.
	 */
	public static function is_pro_active() {
		return (bool) get_option( 'secure_login_pro_keys_active', false );
	}

	/**
	 * Get system status for both key types.
	 *
	 * @return array Status information.
	 */
	public static function get_status() {
		return array(
			'free' => array(
				'has_public_key' => ! empty( get_option( 'secure_login_public_key_free' ) ),
				'has_private_key' => ! empty( get_option( 'secure_login_private_key_free_encrypted' ) ),
			),
			'pro' => array(
				'active' => self::is_pro_active(),
				'has_public_key' => ! empty( get_option( 'secure_login_public_key_pro' ) ),
				'has_wrapped_key' => ! empty( get_option( 'secure_login_wrapped_private_key_pro' ) ),
			),
		);
	}

	/**
	 * Migrate from old single-key system to dual-key system.
	 *
	 * @return array Migration result.
	 */
	public function migrate_from_single_key() {
		// Check for old keys
		$old_public = get_option( 'secure_login_public_key' );
		$old_wrapped = get_option( 'secure_login_wrapped_private_key' );
		$old_encrypted = get_option( 'secure_login_private_key_encrypted' );
		
		if ( ! $old_public ) {
			return array( 'status' => 'nothing_to_migrate' );
		}

		$migrated = array();

		// If we have wrapped key, it becomes pro key
		if ( $old_wrapped ) {
			update_option( 'secure_login_public_key_pro', $old_public );
			update_option( 'secure_login_wrapped_private_key_pro', $old_wrapped );
			update_option( 'secure_login_pro_keys_active', true );
			$migrated[] = 'pro';
			
			// Also initialize free keys for fallback
			$this->initialize_free_keys();
			$migrated[] = 'free';
		} 
		// If we only have encrypted key, it becomes free key
		elseif ( $old_encrypted ) {
			update_option( 'secure_login_public_key_free', $old_public );
			update_option( 'secure_login_private_key_free_encrypted', $old_encrypted );
			$migrated[] = 'free';
		}

		// Clean up old keys after successful migration
		if ( ! empty( $migrated ) ) {
			delete_option( 'secure_login_public_key' );
			delete_option( 'secure_login_wrapped_private_key' );
			delete_option( 'secure_login_private_key_encrypted' );
			
			$this->log_key_operation( 'migrated_to_dual_key' );
		}

		return array( 
			'status' => 'migrated',
			'migrated' => $migrated,
		);
	}
}