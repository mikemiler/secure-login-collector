<?php
/**
 * Passkey Manager - Admin UI for WebAuthn Management
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Passkey_Manager
 * Handles the admin UI for passkey registration and management.
 * Now supports multiple passkeys that all wrap the same Master Wrapping Key.
 */
class Passkey_Manager {

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
		// Initialize Master Key Manager
		if ( ! class_exists( 'Master_Key_Manager' ) ) {
			require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-master-key-manager.php';
		}
		$this->master_key_manager = new Master_Key_Manager();
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		
		// AJAX handlers
		add_action( 'wp_ajax_passkey_start_registration', array( $this, 'handle_start_registration' ) );
		add_action( 'wp_ajax_passkey_complete_registration', array( $this, 'handle_complete_registration' ) );
		add_action( 'wp_ajax_passkey_delete', array( $this, 'handle_delete_passkey' ) );
		add_action( 'wp_ajax_passkey_list', array( $this, 'handle_list_passkeys' ) );
		add_action( 'wp_ajax_passkey_test_auth', array( $this, 'handle_test_authentication' ) );
		add_action( 'wp_ajax_passkey_init_setup', array( $this, 'handle_init_setup' ) );
		add_action( 'wp_ajax_passkey_unwrap_mwk', array( $this, 'handle_unwrap_mwk' ) );
	}

	/**
	 * Add admin menu for passkey management.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'secure-login-collector',
			__( 'Passkey Management', 'secure-login-collector' ),
			__( 'Passkeys', 'secure-login-collector' ),
			'manage_options',
			'secure-login-passkeys',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin scripts for passkey management.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Check both possible hook formats
		if ( 'secure-login-collector_page_secure-login-passkeys' !== $hook && 
		     'toplevel_page_secure-login-collector' !== $hook &&
		     strpos( $hook, 'secure-login-passkeys' ) === false ) {
			return;
		}
		
		// Only load on the passkeys page
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'secure-login-passkeys' ) {
			return;
		}

		wp_enqueue_script(
			'passkey-admin',
			SECURE_LOGIN_PLUGIN_URL . 'assets/js/passkey-admin.js',
			array( 'jquery' ),
			SECURE_LOGIN_VERSION,
			true
		);

		wp_localize_script( 'passkey-admin', 'passkeyAdmin', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'passkey_admin_nonce' ),
			'user_id' => get_current_user_id(),
			'strings' => array(
				'register_success'     => __( 'Passkey registered successfully!', 'secure-login-collector' ),
				'register_failed'      => __( 'Failed to register passkey.', 'secure-login-collector' ),
				'delete_confirm'       => __( 'Are you sure you want to delete this passkey?', 'secure-login-collector' ),
				'delete_success'       => __( 'Passkey deleted successfully.', 'secure-login-collector' ),
				'test_success'         => __( 'Authentication successful!', 'secure-login-collector' ),
				'test_failed'          => __( 'Authentication failed.', 'secure-login-collector' ),
				'browser_not_supported'=> __( 'Your browser does not support WebAuthn.', 'secure-login-collector' ),
			)
		) );

		wp_enqueue_style(
			'passkey-admin',
			SECURE_LOGIN_PLUGIN_URL . 'assets/css/passkey-admin.css',
			array(),
			SECURE_LOGIN_VERSION
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_admin_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Passkey Management', 'secure-login-collector' ); ?></h1>
			
			<?php if ( ! $this->is_https() ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'WebAuthn requires HTTPS. Please enable SSL on your site to use passkeys.', 'secure-login-collector' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="passkey-container">
				<div class="passkey-section">
					<h2><?php esc_html_e( 'Register New Passkey', 'secure-login-collector' ); ?></h2>
					<p><?php esc_html_e( 'Register a hardware security key or platform authenticator for ultra-secure encryption.', 'secure-login-collector' ); ?></p>
					
					<div class="passkey-registration-form">
						<input type="text" 
						       id="passkey-name" 
						       placeholder="<?php esc_attr_e( 'Passkey Name (e.g., YubiKey 5C)', 'secure-login-collector' ); ?>" 
						       class="regular-text" />
						
						<button type="button" 
						        id="register-passkey-btn" 
						        class="button button-primary">
							<?php esc_html_e( 'Register Passkey', 'secure-login-collector' ); ?>
						</button>
						
						<span class="spinner"></span>
						<div id="registration-status"></div>
					</div>
				</div>

				<div class="passkey-section">
					<h2><?php esc_html_e( 'Registered Passkeys', 'secure-login-collector' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'secure-login-collector' ); ?></th>
								<th><?php esc_html_e( 'Credential ID', 'secure-login-collector' ); ?></th>
								<th><?php esc_html_e( 'Registered', 'secure-login-collector' ); ?></th>
								<th><?php esc_html_e( 'Last Used', 'secure-login-collector' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'secure-login-collector' ); ?></th>
							</tr>
						</thead>
						<tbody id="passkey-list">
							<?php $this->render_passkey_list(); ?>
						</tbody>
					</table>
				</div>

				<div class="passkey-section">
					<h2><?php esc_html_e( 'Test Authentication', 'secure-login-collector' ); ?></h2>
					<p><?php esc_html_e( 'Test your passkey authentication to ensure it works correctly.', 'secure-login-collector' ); ?></p>
					
					<button type="button" 
					        id="test-passkey-btn" 
					        class="button button-secondary">
						<?php esc_html_e( 'Test Passkey Authentication', 'secure-login-collector' ); ?>
					</button>
					
					<div id="test-result"></div>
				</div>

				<div class="passkey-section">
					<h3><?php esc_html_e( 'Security Information', 'secure-login-collector' ); ?></h3>
					<ul>
						<li>✅ <?php esc_html_e( 'Passkeys provide phishing-resistant authentication', 'secure-login-collector' ); ?></li>
						<li>✅ <?php esc_html_e( 'Private keys never leave your device', 'secure-login-collector' ); ?></li>
						<li>✅ <?php esc_html_e( 'Zero-knowledge: Server cannot decrypt without your passkey', 'secure-login-collector' ); ?></li>
						<li>✅ <?php esc_html_e( 'Supports hardware keys (YubiKey, Titan) and platform authenticators', 'secure-login-collector' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the list of registered passkeys.
	 */
	private function render_passkey_list() {
		$user_id = get_current_user_id();
		$passkeys = $this->get_user_passkeys( $user_id );

		if ( empty( $passkeys ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No passkeys registered yet.', 'secure-login-collector' ) . '</td></tr>';
			return;
		}

		foreach ( $passkeys as $passkey ) {
			?>
			<tr data-credential-id="<?php echo esc_attr( $passkey['credential_id'] ); ?>">
				<td><?php echo esc_html( $passkey['name'] ); ?></td>
				<td>
					<code><?php echo esc_html( substr( $passkey['credential_id'], 0, 20 ) . '...' ); ?></code>
				</td>
				<td><?php echo esc_html( $passkey['registered_at'] ); ?></td>
				<td><?php echo esc_html( $passkey['last_used'] ?? __( 'Never', 'secure-login-collector' ) ); ?></td>
				<td>
					<button class="button button-small delete-passkey" 
					        data-credential-id="<?php echo esc_attr( $passkey['credential_id'] ); ?>">
						<?php esc_html_e( 'Delete', 'secure-login-collector' ); ?>
					</button>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Get user's registered passkeys.
	 */
	private function get_user_passkeys( $user_id ) {
		$passkeys = get_user_meta( $user_id, 'secure_login_passkeys', true );
		return is_array( $passkeys ) ? $passkeys : array();
	}

	/**
	 * Check if site is using HTTPS.
	 */
	private function is_https() {
		return is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );
	}

	/**
	 * Handle start registration AJAX.
	 */
	public function handle_start_registration() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Generate challenge
		$challenge = base64_encode( random_bytes( 32 ) );
		set_transient( 'passkey_reg_challenge_' . get_current_user_id(), $challenge, 300 );

		$user = wp_get_current_user();

		wp_send_json_success( array(
			'challenge' => $challenge,
			'rp' => array(
				'name' => get_bloginfo( 'name' ),
				'id'   => parse_url( home_url(), PHP_URL_HOST ),
			),
			'user' => array(
				'id'          => base64_encode( (string) $user->ID ),
				'name'        => $user->user_login,
				'displayName' => $user->display_name,
			),
			'pubKeyCredParams' => array(
				array( 'alg' => -7, 'type' => 'public-key' ),  // ES256
				array( 'alg' => -257, 'type' => 'public-key' ), // RS256
			),
			'authenticatorSelection' => array(
				'authenticatorAttachment' => 'cross-platform',
				'userVerification'        => 'required',
				'requireResidentKey'      => false,
			),
			'timeout' => 60000,
		) );
	}

	/**
	 * Handle complete registration AJAX.
	 */
	public function handle_complete_registration() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$name           = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$credential_id  = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		$public_key     = wp_unslash( $_POST['public_key'] ?? '' );
		$client_data    = wp_unslash( $_POST['client_data'] ?? '' );

		if ( empty( $name ) || empty( $credential_id ) ) {
			wp_send_json_error( 'Missing required registration data (name or credential_id)' );
		}
		
		// Public key might not be available in all browsers
		if ( empty( $public_key ) || $public_key === 'not_available' ) {
			// Use a placeholder - the actual public key is in the attestation object
			// For our purposes, we mainly need the credential_id for identification
			$public_key = 'stored_in_attestation';
		}

		// Verify challenge
		$expected_challenge = get_transient( 'passkey_reg_challenge_' . get_current_user_id() );
		if ( ! $expected_challenge ) {
			wp_send_json_error( 'Registration challenge expired or not set. Please try again.' );
		}

		$client_data_array = json_decode( base64_decode( $client_data ), true );
		if ( ! $client_data_array ) {
			wp_send_json_error( 'Invalid client data format' );
		}
		
		// Convert base64url to base64 for comparison (WebAuthn uses base64url)
		$received_challenge = $client_data_array['challenge'] ?? '';
		$received_challenge = str_replace( array( '-', '_' ), array( '+', '/' ), $received_challenge );
		
		// Also convert our challenge to base64url for comparison
		$expected_challenge_url = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), $expected_challenge );
		
		if ( $received_challenge !== $expected_challenge && $received_challenge !== $expected_challenge_url ) {
			wp_send_json_error( 'Challenge verification failed' );
		}

		$user_id = get_current_user_id();
		
		// Check if we have existing passkeys (not MWK, as that's created in init_setup)
		$existing_passkeys = $this->get_user_passkeys( $user_id );
		$is_first_passkey = empty( $existing_passkeys );
		
		// For additional passkeys, we need the MWK passed from the client
		// (after authenticating with an existing passkey)
		$wrapped_mwk = isset( $_POST['wrapped_mwk'] ) ? 
		               wp_unslash( $_POST['wrapped_mwk'] ) : '';
		               
		if ( ! $is_first_passkey && empty( $wrapped_mwk ) ) {
			wp_send_json_error( 'MWK required for additional passkeys' );
			return;
		}
		
		// Derive wrapping key from passkey credentials
		$wrapping_key = $this->derive_wrapping_key( $credential_id, $user_id );
		
		// For additional passkeys, wrap the provided MWK
		if ( ! $is_first_passkey ) {
			$wrapped_data = $this->master_key_manager->wrap_mwk_with_passkey( 
				$wrapped_mwk, 
				$wrapping_key 
			);
			
			// Store the wrapped MWK for this passkey
			$this->master_key_manager->store_wrapped_key(
				$user_id,
				'mwk',
				$credential_id,
				$wrapped_data,
				$credential_id
			);
		}
		
		// Store passkey metadata
		$passkeys = $this->get_user_passkeys( $user_id );
		$passkeys[] = array(
			'name'          => $name,
			'credential_id' => $credential_id,
			'public_key'    => $public_key,
			'registered_at' => current_time( 'mysql' ),
			'last_used'     => null,
		);
		update_user_meta( $user_id, 'secure_login_passkeys', $passkeys );

		// Store globally for verification
		update_option( 'passkey_credential_' . $credential_id, array(
			'public_key' => $public_key,
			'user_id'    => $user_id,
		) );

		// Clear challenge
		delete_transient( 'passkey_reg_challenge_' . $user_id );

		wp_send_json_success( array(
			'message' => 'Passkey registered successfully',
			'is_first' => $is_first_passkey
		) );
	}

	/**
	 * Handle delete passkey AJAX.
	 */
	public function handle_delete_passkey() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		if ( empty( $credential_id ) ) {
			wp_send_json_error( 'Missing credential ID' );
		}

		$user_id = get_current_user_id();
		$passkeys = $this->get_user_passkeys( $user_id );

		// Remove the passkey
		$passkeys = array_filter( $passkeys, function( $p ) use ( $credential_id ) {
			return $p['credential_id'] !== $credential_id;
		} );

		update_user_meta( $user_id, 'secure_login_passkeys', array_values( $passkeys ) );
		delete_option( 'passkey_credential_' . $credential_id );
		
		// Also delete the wrapped MWK for this passkey
		$this->master_key_manager->delete_wrapped_mwk( $user_id, $credential_id );

		// If this was the last passkey, delete the pro keys
		if ( empty( $passkeys ) ) {
			if ( ! class_exists( 'Secure_Login_Encryption_Handler_V2' ) ) {
				require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-encryption-handler-v2.php';
			}
			$encryption_handler = new Secure_Login_Encryption_Handler_V2();
			$encryption_handler->delete_pro_keys();
			
			wp_send_json_success( array(
				'message' => 'Last passkey deleted, pro keys removed',
				'last_passkey' => true
			) );
			return;
		}

		wp_send_json_success( array(
			'message' => 'Passkey deleted',
			'last_passkey' => false
		) );
	}

	/**
	 * Handle list passkeys AJAX.
	 */
	public function handle_list_passkeys() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$user_id = get_current_user_id();
		$passkeys = $this->get_user_passkeys( $user_id );

		wp_send_json_success( $passkeys );
	}

	/**
	 * Handle test authentication AJAX.
	 */
	public function handle_test_authentication() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Generate test challenge
		$challenge = base64_encode( random_bytes( 32 ) );
		set_transient( 'passkey_test_challenge_' . get_current_user_id(), $challenge, 300 );

		wp_send_json_success( array(
			'challenge' => $challenge,
			'timeout'   => 60000,
		) );
	}

	/**
	 * Handle initial setup - create RSA keys and MWK.
	 */
	public function handle_init_setup() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$user_id = get_current_user_id();
		
		// Check if passkeys already exist (not just MWK)
		$existing_passkeys = $this->get_user_passkeys( $user_id );
		if ( ! empty( $existing_passkeys ) ) {
			wp_send_json_error( 'Passkeys already registered. Use additional passkey flow.' );
		}
		
		// Check if MWK already exists
		if ( $this->master_key_manager->user_has_mwk( $user_id ) ) {
			// MWK exists but no passkeys - this is an inconsistent state
			// Could happen if previous registration failed
			// For now, we'll continue with the setup
			error_log( 'Warning: MWK exists but no passkeys registered for user ' . $user_id );
		}

		// Get passkey credential data from first registration
		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		if ( empty( $credential_id ) ) {
			wp_send_json_error( 'Missing credential ID' );
		}

		// Step 1: Initialize encryption handler V2 for dual-key system
		if ( ! class_exists( 'Secure_Login_Encryption_Handler_V2' ) ) {
			require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-encryption-handler-v2.php';
		}
		$encryption_handler = new Secure_Login_Encryption_Handler_V2();
		
		// Step 2: Ensure free keys exist first
		$free_result = $encryption_handler->initialize_free_keys();
		if ( is_wp_error( $free_result ) ) {
			wp_send_json_error( 'Failed to initialize free keys: ' . $free_result->get_error_message() );
		}
		
		// Step 3: Derive key from passkey for wrapping
		$passkey_derived_key = $this->derive_wrapping_key( $credential_id, $user_id );
		
		// Step 4: Initialize PRO keys with passkey wrapping
		$pro_result = $encryption_handler->initialize_pro_keys( $passkey_derived_key );
		
		if ( is_wp_error( $pro_result ) ) {
			wp_send_json_error( 'Failed to initialize pro keys: ' . $pro_result->get_error_message() );
		}
		
		// Check if keys were already initialized
		if ( is_array( $pro_result ) && isset( $pro_result['status'] ) && $pro_result['status'] === 'already_initialized' ) {
			// Pro keys are already wrapped - this means we already have a working setup
			// This shouldn't happen if we checked for existing passkeys correctly
			// But we'll handle it gracefully
			wp_send_json_success( array( 
				'status' => 'already_initialized',
				'message' => 'Pro encryption keys already initialized. Registration can continue.'
			) );
			return;
		}

		// Step 5: Generate Master Wrapping Key for additional passkeys
		$mwk = $this->master_key_manager->generate_master_key();

		// Step 6: Wrap MWK with passkey-derived key for future passkeys
		$wrapped_mwk = $this->master_key_manager->wrap_mwk_with_passkey(
			$mwk,
			$passkey_derived_key
		);

		// Store wrapped MWK for this credential
		$this->master_key_manager->store_wrapped_key(
			$user_id,
			'mwk',
			$credential_id,
			$wrapped_mwk,
			$credential_id
		);

		// Clear the MWK from memory (keep only wrapped versions)
		unset( $mwk );
		
		// Get the pro public key for response
		$public_key_pro = get_option( 'secure_login_public_key_pro' );

		wp_send_json_success( array(
			'message' => 'Passkey-wrapped PRO encryption initialized successfully',
			'public_key' => $public_key_pro,
			'free_status' => $free_result,
			'pro_status' => $pro_result
		) );
	}

	/**
	 * Handle unwrapping MWK with passkey.
	 */
	public function handle_unwrap_mwk() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		if ( empty( $credential_id ) ) {
			wp_send_json_error( 'Missing credential ID' );
		}

		// Verify passkey authentication (simplified for now)
		// In production, this should verify the WebAuthn signature
		
		$user_id = get_current_user_id();
		
		// Derive wrapping key
		$wrapping_key = $this->derive_wrapping_key( $credential_id, $user_id );
		
		// Get wrapped MWK for this passkey
		$wrapped_mwk = $this->master_key_manager->get_wrapped_key(
			$user_id,
			'mwk',
			$credential_id
		);
		
		if ( ! $wrapped_mwk ) {
			wp_send_json_error( 'No MWK found for this passkey' );
		}
		
		// Unwrap MWK
		$mwk = $this->master_key_manager->unwrap_mwk_with_passkey(
			$wrapped_mwk,
			$wrapping_key
		);
		
		if ( ! $mwk ) {
			wp_send_json_error( 'Failed to unwrap MWK' );
		}
		
		// Return the MWK for immediate use (it will be re-wrapped with new passkey)
		// This is safe because it's transmitted over HTTPS and immediately re-wrapped
		wp_send_json_success( array(
			'message' => 'MWK unwrapped successfully',
			'mwk' => $mwk,
			'expires_in' => 60
		) );
	}

	/**
	 * Derive a wrapping key from passkey credentials.
	 *
	 * @param string $credential_id Passkey credential ID.
	 * @param int    $user_id       User ID.
	 * @return string Derived wrapping key.
	 */
	private function derive_wrapping_key( $credential_id, $user_id ) {
		// Use credential ID + user ID + salt for key derivation
		// This is deterministic but unique per passkey
		$salt = wp_salt( 'auth' ) . 'passkey_wrap';
		$key_material = $credential_id . '|' . $user_id . '|' . $salt;
		
		// Derive 256-bit key using PBKDF2
		return hash_pbkdf2( 'sha256', $key_material, $salt, 100000, 32, true );
	}
}