<?php
/**
 * @fs_premium_only
 *
 * Premium feature: WebAuthn/FIDO2 Passkey Authentication Manager
 * This file is only included in the premium version.
 *
 * @package SecureLoginCollector
 */

// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
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
 * Supports a single passkey for ultra-secure encryption.
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
		// Initialize Master Key Manager.
		if ( ! class_exists( 'Master_Key_Manager' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-master-key-manager__premium_only.php';
		}
		$this->master_key_manager = new Master_Key_Manager();

		// Enqueue admin styles and scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_passkey_start_registration', array( $this, 'handle_start_registration' ) );
		add_action( 'wp_ajax_passkey_complete_registration', array( $this, 'handle_complete_registration' ) );
		add_action( 'wp_ajax_passkey_delete', array( $this, 'handle_delete_passkey' ) );
		add_action( 'wp_ajax_passkey_get_status', array( $this, 'handle_get_passkey_status' ) );
		add_action( 'wp_ajax_passkey_init_setup', array( $this, 'handle_init_setup' ) );
		add_action( 'wp_ajax_get_current_user_id', array( $this, 'handle_get_current_user_id' ) );
		add_action( 'wp_ajax_derive_passkey_unwrapping_key', array( $this, 'handle_derive_passkey_unwrapping_key' ) );
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_styles( $hook ) {
		// Load CSS on all Secure Login Collector admin pages.
		// Check for: toplevel_page_secure-login-collector, login-data_page_secure-login-collector-settings.
		if ( strpos( $hook, 'secure-login-collector' ) === false ) {
			return;
		}

		// Enqueue modern admin CSS if not already enqueued.
		if ( ! wp_style_is( 'secure-login-admin-modern-css', 'enqueued' ) ) {
			wp_enqueue_style(
				'secure-login-admin-modern-css',
				plugin_dir_url( __FILE__ ) . '../assets/css/admin-modern.css',
				array(),
				filemtime( plugin_dir_path( __FILE__ ) . '../assets/css/admin-modern.css' )
			);
		}
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Load JS on all Secure Login Collector admin pages.
		// Check for: toplevel_page_secure-login-collector, login-data_page_secure-login-collector-settings.
		if ( strpos( $hook, 'secure-login-collector' ) === false ) {
			return;
		}

		// Only load passkey scripts for pro license users.
		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			return;
		}

		// Enqueue passkey management script.
		wp_enqueue_script(
			'slc-passkey-pro-js',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin-passkey__premium_only.js',
			array( 'jquery' ),
			filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/admin-passkey__premium_only.js' ),
			true
		);

		// Localize script data.
		$this->localize_passkey_script();
	}

	/**
	 * Localize passkey script data.
	 * Passes PHP data to JavaScript in a safe, escaped manner.
	 */
	private function localize_passkey_script() {
		// Check if there are any encrypted entries for the warning message.
		global $wpdb;
		$table_name = $wpdb->prefix . 'seculoco_data';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_encrypted_data = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) ) > 0;

		// Prepare localized data.
		$script_data = array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'passkey_admin_nonce' ),
			'hasEncryptedData' => $has_encrypted_data,
			'strings'          => array(
				'warningDataLoss'  => __( 'WARNING: Deleting this passkey will make ALL existing encrypted data permanently inaccessible! This action CANNOT be undone. There is NO recovery method. Are you absolutely sure you want to proceed?', 'secure-login-collector' ),
				'warningSimple'    => __( 'Are you sure you want to delete this passkey?\n\nYou can register a new passkey afterward.', 'secure-login-collector' ),
				'deleting'         => __( 'Deleting...', 'secure-login-collector' ),
				'deletePasskey'    => __( 'Delete Passkey', 'secure-login-collector' ),
				'deleteSuccess'    => __( 'Passkey deleted successfully!', 'secure-login-collector' ),
				'deleteFailed'     => __( 'Failed to delete passkey:', 'secure-login-collector' ),
				'networkError'     => __( 'Network error occurred. Please try again.', 'secure-login-collector' ),
				'noWebAuthn'       => __( 'Your browser does not support WebAuthn/Passkeys.', 'secure-login-collector' ),
				'registerSuccess'  => __( 'Passkey registered successfully!', 'secure-login-collector' ),
			),
		);

		// Use wp_localize_script for proper escaping.
		wp_localize_script( 'slc-passkey-pro-js', 'secureLoginPasskeyData', $script_data );
	}

	/**
	 * Render passkey section for settings page.
	 * Called from settings manager.
	 */
	public function render_passkey_section() {
		$user_id = get_current_user_id();
		$passkey = $this->get_user_passkey( $user_id );

		// Check if there are any encrypted entries.
		global $wpdb;
		$table_name = $wpdb->prefix . 'seculoco_data';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_encrypted_data = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) ) > 0;

		?>
		<div class="seculoco-passkey-container">
		<?php if ( ! $this->is_https() ) : ?>
				<div class="seculoco-alert seculoco-alert-danger">
					<span class="seculoco-alert-icon">⚠️</span>
					<div class="seculoco-alert-content">
						<div class="seculoco-alert-title"><?php esc_html_e( 'HTTPS Required', 'secure-login-collector' ); ?></div>
						<div class="seculoco-alert-message"><?php esc_html_e( 'WebAuthn requires HTTPS. Please enable SSL on your site to use passkeys.', 'secure-login-collector' ); ?></div>
					</div>
				</div>
			<?php endif; ?>
			
			<div class="seculoco-card">
				<div class="seculoco-card-header">
					<h3 class="seculoco-card-title">
						<span class="seculoco-card-title-icon">🔐</span>
					<?php esc_html_e( 'Passkey Authentication', 'secure-login-collector' ); ?>
					</h3>
				<?php if ( $passkey ) : ?>
						<span class="seculoco-badge slc-badge-success"><?php esc_html_e( 'Active', 'secure-login-collector' ); ?></span>
					<?php endif; ?>
				</div>
				
				<div class="seculoco-card-body">
				<?php if ( $passkey ) : ?>
						<div class="seculoco-passkey-status">
							<div class="seculoco-passkey-status-header">
								<div class="seculoco-passkey-status-title">
									<span>✅</span>
									<?php esc_html_e( 'Passkey Registered', 'secure-login-collector' ); ?>
								</div>
							</div>
							<div class="seculoco-passkey-status-details">
								<strong><?php esc_html_e( 'Name:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( $passkey['name'] ); ?><br>
								<strong><?php esc_html_e( 'Registered:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( $passkey['registered_at'] ); ?><br>
								<strong><?php esc_html_e( 'ID:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( substr( $passkey['credential_id'], 0, 20 ) . '...' ); ?>
							</div>
						</div>
						
						<?php if ( $has_encrypted_data ) : ?>
							<div class="seculoco-alert seculoco-alert-danger" style="margin-top: 20px;">
								<span class="seculoco-alert-icon">⚠️</span>
								<div class="seculoco-alert-content">
									<div class="seculoco-alert-title"><?php esc_html_e( 'CRITICAL WARNING: Data Loss Risk', 'secure-login-collector' ); ?></div>
									<div class="seculoco-alert-message">
										<p><strong><?php esc_html_e( 'Deleting this passkey will permanently prevent decryption of:', 'secure-login-collector' ); ?></strong></p>
										<ul>
											<li><?php esc_html_e( 'All existing login data encrypted with this passkey', 'secure-login-collector' ); ?></li>
											<li><?php esc_html_e( 'Any future data encrypted before registering a new passkey', 'secure-login-collector' ); ?></li>
										</ul>
										<p><strong><?php esc_html_e( 'This action CANNOT be undone. There is NO recovery method.', 'secure-login-collector' ); ?></strong></p>
									</div>
								</div>
							</div>
						<?php endif; ?>
						
						<div style="margin-top: 20px;">
							<button type="button" class="seculoco-btn slc-btn-danger" id="delete-passkey-btn" 
									data-credential-id="<?php echo esc_attr( $passkey['credential_id'] ); ?>">
								<span>🗑️</span>
								<?php esc_html_e( 'Delete Passkey', 'secure-login-collector' ); ?>
							</button>
							<p class="seculoco-form-help" style="margin-top: 8px;">
								<?php esc_html_e( 'Only delete if you understand the consequences above.', 'secure-login-collector' ); ?>
							</p>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'Register a hardware security key or platform authenticator for ultra-secure encryption.', 'secure-login-collector' ); ?></p>

						<div class="passkey-registration-form" style="margin: 20px 0;">
							<div class="seculoco-form-group" style="margin-bottom: 15px;">
								<label class="seculoco-form-label">
									<?php esc_html_e( 'Select Authenticator Type:', 'secure-login-collector' ); ?>
								</label>
								<div class="seculoco-radio-group" style="margin-top: 8px;">
									<label style="display: block; margin-bottom: 8px;">
										<input type="radio" name="authenticator_type" value="platform" checked>
										<strong><?php esc_html_e( 'Platform Authenticator', 'secure-login-collector' ); ?></strong>
										<span class="seculoco-form-help" style="display: block; margin-left: 20px;">
											<?php esc_html_e( 'Use Touch ID, Face ID, or Windows Hello built into your device', 'secure-login-collector' ); ?>
										</span>
									</label>
									<label style="display: block; margin-bottom: 8px;">
										<input type="radio" name="authenticator_type" value="cross-platform">
										<strong><?php esc_html_e( 'Security Key', 'secure-login-collector' ); ?></strong>
										<span class="seculoco-form-help" style="display: block; margin-left: 20px;">
											<?php esc_html_e( 'Use a physical security key like YubiKey or similar device', 'secure-login-collector' ); ?>
										</span>
									</label>
									<label style="display: block; margin-bottom: 8px;">
										<input type="radio" name="authenticator_type" value="auto">
										<strong><?php esc_html_e( 'Auto-Detect', 'secure-login-collector' ); ?></strong>
										<span class="seculoco-form-help" style="display: block; margin-left: 20px;">
											<?php esc_html_e( 'Let browser choose (includes password managers)', 'secure-login-collector' ); ?>
										</span>
									</label>
								</div>
							</div>

							<button type="button"
									id="register-passkey-btn"
									class="seculoco-btn slc-btn-primary slc-btn-lg">
								<span>🔑</span>
								<?php esc_html_e( 'Register Passkey', 'secure-login-collector' ); ?>
							</button>

							<span class="spinner" style="float: none; margin-left: 10px;"></span>
						</div>
					<?php endif; ?>
					
					<div id="passkey-status-message"></div>
				</div>
			</div>			
		</div>

		<?php
	}


	/**
	 * Get user's registered passkey.
	 */
	private function get_user_passkey( $user_id ) {
		$passkey = get_user_meta( $user_id, 'seculoco_passkey', true );
		return is_array( $passkey ) ? $passkey : null;
	}

	/**
	 * Check if user has a registered passkey.
	 */
	private function has_passkey( $user_id ) {
		$passkey = $this->get_user_passkey( $user_id );
		return ! empty( $passkey );
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

		// Get authenticator type from request (platform, cross-platform, or auto).
		$authenticator_type = isset( $_POST['authenticator_type'] ) ? sanitize_text_field( wp_unslash( $_POST['authenticator_type'] ) ) : 'auto';

		// Generate challenge.
		$challenge = base64_encode( random_bytes( 32 ) );
		set_transient( 'passkey_reg_challenge_' . get_current_user_id(), $challenge, 300 );

		$user = wp_get_current_user();

		// Build authenticatorSelection based on user choice.
		$authenticator_selection = array(
			'userVerification'   => 'required',  // Always require biometric/PIN verification
			'requireResidentKey' => false,
		);

		// Set authenticatorAttachment based on user choice.
		if ( 'platform' === $authenticator_type ) {
			// Platform authenticators: Touch ID, Face ID, Windows Hello
			$authenticator_selection['authenticatorAttachment'] = 'platform';
		} elseif ( 'cross-platform' === $authenticator_type ) {
			// Cross-platform authenticators: YubiKey, security keys
			$authenticator_selection['authenticatorAttachment'] = 'cross-platform';
		}
		// If 'auto', omit authenticatorAttachment to allow any type including password managers

		wp_send_json_success(
			array(
				'challenge'              => $challenge,
				'rp'                     => array(
					'name' => get_bloginfo( 'name' ),
					'id'   => parse_url( home_url(), PHP_URL_HOST ),
				),
				'user'                   => array(
					'id'          => base64_encode( (string) $user->ID ),
					'name'        => $user->user_login,
					'displayName' => $user->display_name,
				),
				'pubKeyCredParams'       => array(
					array(
						'alg'  => -7,
						'type' => 'public-key',
					),  // ES256
					array(
						'alg'  => -257,
						'type' => 'public-key',
					), // RS256
				),
				'authenticatorSelection' => $authenticator_selection,
				'timeout'                => 60000,
			)
		);
	}

	/**
	 * Handle complete registration AJAX.
	 */
	public function handle_complete_registration() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Verify pro license.
		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			wp_send_json_error( __( 'Pro license required for passkey encryption.', 'secure-login-collector' ) );
			return;
		}

		$name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Binary data validated as base64 below.
		$public_key = wp_unslash( $_POST['public_key'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data decoded and validated below.
		$client_data = wp_unslash( $_POST['client_data'] ?? '' );

		// Auto-generate name if not provided.
		if ( empty( $name ) ) {
			$name = 'Passkey ' . date( 'Y-m-d H:i' );
		}

		if ( empty( $credential_id ) ) {
			wp_send_json_error( 'Missing required registration data (credential_id)' );
		}

		// Public key might not be available in all browsers.
		if ( empty( $public_key ) || $public_key === 'not_available' ) {
			// Use a placeholder - the actual public key is in the attestation object.
			// For our purposes, we mainly need the credential_id for identification.
			$public_key = 'stored_in_attestation';
		}

		// Verify challenge.
		$expected_challenge = get_transient( 'passkey_reg_challenge_' . get_current_user_id() );
		if ( ! $expected_challenge ) {
			wp_send_json_error( 'Registration challenge expired or not set. Please try again.' );
		}

		$client_data_array = json_decode( base64_decode( $client_data ), true );
		if ( ! $client_data_array ) {
			wp_send_json_error( 'Invalid client data format' );
		}

		// Validate origin.
		$expected_origin = home_url();
		$received_origin = $client_data_array['origin'] ?? '';
		if ( $received_origin !== $expected_origin ) {
			wp_send_json_error( 'Origin verification failed. Expected: ' . $expected_origin . ', Received: ' . $received_origin );
		}

		// Validate type.
		$received_type = $client_data_array['type'] ?? '';
		if ( $received_type !== 'webauthn.create' ) {
			wp_send_json_error( 'Invalid WebAuthn operation type. Expected: webauthn.create, Received: ' . $received_type );
		}

		// Convert base64url to base64 for comparison (WebAuthn uses base64url).
		$received_challenge = $client_data_array['challenge'] ?? '';

		// Normalize both challenges to base64url format for comparison.
		// Remove padding from expected challenge and convert to base64url.
		$expected_challenge_url = rtrim( strtr( $expected_challenge, '+/', '-_' ), '=' );

		// The received challenge is already in base64url format.
		// Compare the normalized values.
		if ( $received_challenge !== $expected_challenge_url ) {
			// Also try with the original base64 format in case of compatibility issues.
			$received_as_base64 = strtr( $received_challenge, '-_', '+/' );
			// Add padding if needed.
			$pad = strlen( $received_as_base64 ) % 4;
			if ( $pad ) {
				$received_as_base64 .= str_repeat( '=', 4 - $pad );
			}

			if ( $received_as_base64 !== $expected_challenge ) {
				wp_send_json_error( 'Challenge verification failed' );
			}
		}

		// Basic attestation validation.
		// Note: Full attestation validation requires CBOR parsing.
		// For production use, consider implementing a WebAuthn library.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Binary attestation data validated as base64 below.
		$attestation = wp_unslash( $_POST['attestation'] ?? '' );
		if ( ! empty( $attestation ) ) {
			// Verify attestation object is valid base64.
			$attestation_decoded = base64_decode( $attestation, true );
			if ( false === $attestation_decoded ) {
				wp_send_json_error( 'Invalid attestation object format' );
			}

			// Log that attestation was received (full validation would require CBOR parser).

			// TODO: For enhanced security, implement full attestation validation.

			$user_id = get_current_user_id();

			// Check if user already has a passkey.
			if ( $this->has_passkey( $user_id ) ) {
				wp_send_json_error( 'A passkey is already registered. Please delete it first to register a new one.' );
				return;
			}

			// Store passkey metadata (single passkey).
			// Note: The wrapping key derivation happens in passkey_init_setup handler.
			$passkey_data = array(
				'name'          => $name,
				'credential_id' => $credential_id,
				'public_key'    => $public_key,
				'registered_at' => current_time( 'mysql' ),
				'last_used'     => null,
			);
			update_user_meta( $user_id, 'seculoco_passkey', $passkey_data );

			// Store globally for verification.
			update_option(
				'passkey_credential_' . $credential_id,
				array(
					'public_key' => $public_key,
					'user_id'    => $user_id,
				)
			);

			// Set global passkey registered flag.
			update_option( 'seculoco_passkey_registered', true );
			update_option( 'seculoco_passkey_registered_at', current_time( 'mysql' ) );

			// Also ensure pro keys active flag is set for consistency with V2 handler.
			update_option( 'seculoco_pro_keys_active', true );

			// Clear challenge.
			delete_transient( 'passkey_reg_challenge_' . $user_id );

			wp_send_json_success(
				array(
					'message' => 'Passkey registered successfully',
				)
			);
		}
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
		$passkey = $this->get_user_passkey( $user_id );

		// Verify this is the correct passkey.
		if ( empty( $passkey ) || $passkey['credential_id'] !== $credential_id ) {
			wp_send_json_error( 'Passkey not found or mismatch' );
			return;
		}

		// Delete the passkey.
		delete_user_meta( $user_id, 'seculoco_passkey' );
		delete_option( 'passkey_credential_' . $credential_id );

		// Delete all wrapped MWKs for this user.
		if ( $this->master_key_manager ) {
			$this->master_key_manager->delete_all_user_mwks( $user_id );
		}

		// Delete the pro keys and clear the global flag.
		if ( ! class_exists( 'Seculoco_Encryption_Handler_V2' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-encryption-handler-v2.php';
		}
		$encryption_handler = new Seculoco_Encryption_Handler_V2();
		$encryption_handler->delete_pro_keys();

		// Clear global passkey registered flag and pro keys active flag.
		delete_option( 'seculoco_passkey_registered' );
		delete_option( 'seculoco_passkey_registered_at' );
		delete_option( 'seculoco_pro_keys_active' );

		wp_send_json_success(
			array(
				'message' => 'Passkey deleted and encryption keys removed',
				'success' => true,
			)
		);
	}

	/**
	 * Handle get passkey status AJAX.
	 */
	public function handle_get_passkey_status() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$user_id = get_current_user_id();
		$passkey = $this->get_user_passkey( $user_id );

		wp_send_json_success(
			array(
				'has_passkey' => ! empty( $passkey ),
				'passkey'     => $passkey,
			)
		);
	}

	/**
	 * Handle initial setup - create RSA keys.
	 */
	public function handle_init_setup() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Verify pro license.
		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			wp_send_json_error( __( 'Pro license required to initialize pro encryption.', 'secure-login-collector' ) );
			return;
		}

		$user_id = get_current_user_id();

		// Check if passkey already exists.
		if ( $this->has_passkey( $user_id ) ) {
			wp_send_json_error( 'A passkey is already registered.' );
		}

		// Get passkey credential data from registration.
		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		if ( empty( $credential_id ) ) {
			wp_send_json_error( 'Missing credential ID' );
		}

		// Step 1: Initialize encryption handler V2 for dual-key system.
		if ( ! class_exists( 'Seculoco_Encryption_Handler_V2' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-encryption-handler-v2.php';
		}
		$encryption_handler = new Seculoco_Encryption_Handler_V2();

		// Step 2: Ensure free keys exist first.
		$free_result = $encryption_handler->initialize_free_keys();
		if ( is_wp_error( $free_result ) ) {
			wp_send_json_error( 'Failed to initialize free keys: ' . $free_result->get_error_message() );
		}

		// Step 3: Derive key from passkey for wrapping.
		$passkey_derived_key = $this->derive_wrapping_key( $credential_id, $user_id );

		// Step 4: Initialize PRO keys with passkey wrapping.
		$pro_result = $encryption_handler->initialize_pro_keys( $passkey_derived_key );

		if ( is_wp_error( $pro_result ) ) {
			wp_send_json_error( 'Failed to initialize pro keys: ' . $pro_result->get_error_message() );
		}

		// The passkey-derived key directly wraps the PRO private key.

		// Get the pro public key for response.
		$public_key_pro = get_option( 'seculoco_public_key_pro' );

		wp_send_json_success(
			array(
				'message'     => 'Passkey-wrapped PRO encryption initialized successfully',
				'public_key'  => $public_key_pro,
				'free_status' => $free_result,
				'pro_status'  => $pro_result,
			)
		);
	}

	/**
	 * Derive a wrapping key from passkey credentials.
	 *
	 * @param string $credential_id Passkey credential ID.
	 * @param int    $user_id       User ID.
	 * @return string Derived wrapping key.
	 */
	private function derive_wrapping_key( $credential_id, $user_id ) {
		// Use credential ID + user ID + salt for key derivation.
		// This is deterministic but unique per passkey.
		$salt         = wp_salt( 'auth' ) . 'passkey_wrap';
		$key_material = $credential_id . '|' . $user_id . '|' . $salt;

		// Derive 256-bit key using PBKDF2.
		return hash_pbkdf2( 'sha256', $key_material, $salt, 100000, 32, true );
	}

	/**
	 * Handle AJAX request to get current user ID.
	 */
	public function handle_get_current_user_id() {
		// Accept both admin nonces.
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) &&
			! wp_verify_nonce( $nonce, 'passkey_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		wp_send_json_success(
			array(
				'user_id' => get_current_user_id(),
			)
		);
	}

	/**
	 * Handle AJAX request to derive passkey unwrapping key.
	 */
	public function handle_derive_passkey_unwrapping_key() {
		// Accept both admin nonces.
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) &&
			! wp_verify_nonce( $nonce, 'passkey_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token' );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		$user_id       = intval( $_POST['user_id'] ?? 0 );

		if ( empty( $credential_id ) || ! $user_id ) {
			wp_send_json_error( 'Missing credential ID or user ID' );
		}

		// Derive the same key as server-side wrapping.
		$key = $this->derive_wrapping_key( $credential_id, $user_id );

		wp_send_json_success(
			array(
				'key' => base64_encode( $key ),
			)
		);
	}
}