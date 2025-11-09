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
 * Class Seculoco_Passkey_Manager
 * Handles the admin UI for passkey registration and management.
 * Supports a single passkey for ultra-secure encryption.
 */
class Seculoco_Passkey_Manager {

	/**
	 * Constructor.
	 */
	public function __construct() {

		// Enqueue admin styles and scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_seculoco_passkey_start_registration', array( $this, 'handle_start_registration' ) );
		add_action( 'wp_ajax_seculoco_passkey_complete_registration', array( $this, 'handle_complete_registration' ) );
		add_action( 'wp_ajax_seculoco_passkey_delete', array( $this, 'handle_delete_passkey' ) );
		add_action( 'wp_ajax_seculoco_passkey_get_status', array( $this, 'handle_get_passkey_status' ) );
		add_action( 'wp_ajax_seculoco_passkey_init_setup', array( $this, 'handle_init_setup' ) );
		add_action( 'wp_ajax_seculoco_get_current_user_id', array( $this, 'handle_get_current_user_id' ) );
		add_action( 'wp_ajax_seculoco_derive_passkey_unwrapping_key', array( $this, 'handle_derive_passkey_unwrapping_key' ) );
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
			'seculoco-passkey-pro-js',
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
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'passkey_admin_nonce' ),
			'hasEncryptedData'     => $has_encrypted_data,
			'secureContextAllowed' => $this->is_secure_context_allowed(),
			'strings'              => array(
				'warningDataLoss'  => __( 'WARNING: Resetting this passkey will make ALL existing encrypted data permanently inaccessible! This action CANNOT be undone. There is NO recovery method. Are you absolutely sure you want to proceed?', 'secure-login-collector' ),
				'warningSimple'    => __( 'Are you sure you want to reset this passkey?\n\nYou can register a new passkey afterward.', 'secure-login-collector' ),
				'deleting'         => __( 'Resetting...', 'secure-login-collector' ),
				'deletePasskey'    => __( 'Reset Passkey', 'secure-login-collector' ),
				'deleteSuccess'    => __( 'Passkey reset successfully!', 'secure-login-collector' ),
				'deleteFailed'     => __( 'Failed to reset passkey:', 'secure-login-collector' ),
				'typeConfirmToReset' => __( 'Type "RESET" to confirm:', 'secure-login-collector' ),
				'resetConfirmRequired' => __( 'Please type "RESET" to confirm.', 'secure-login-collector' ),
				'resetModalTitle'  => __( 'Reset Passkey', 'secure-login-collector' ),
				'cancel'           => __( 'Cancel', 'secure-login-collector' ),
				'networkError'     => __( 'Network error occurred. Please try again.', 'secure-login-collector' ),
				'noWebAuthn'       => __( 'Your browser does not support WebAuthn/Passkeys.', 'secure-login-collector' ),
				'registerSuccess'  => __( 'Passkey registered successfully!', 'secure-login-collector' ),
				'httpsRequired'    => __( 'Passkeys require HTTPS (or http://localhost / 127.0.0.1 while developing). Please enable SSL before registering.', 'secure-login-collector' ),
			),
		);

		// Use wp_localize_script for proper escaping.
		wp_localize_script( 'seculoco-passkey-pro-js', 'secureLoginPasskeyData', $script_data );
	}

	/**
	 * Render passkey section for settings page.
	 * Called from settings manager.
	 */
	public function render_passkey_section() {
		$passkey = $this->get_global_passkey();

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
				<div class="seculoco-card-body">
				<?php if ( $passkey ) : ?>
						
						<div class="seculoco-passkey-status-header">
							<div class="seculoco-passkey-status-title">
								<span>✅</span>
								<?php esc_html_e( 'Passkey Registered', 'secure-login-collector' ); ?>
							</div>
						</div>
						<div class="seculoco-passkey-status-details">
							<strong><?php esc_html_e( 'Name:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( $passkey['name'] ); ?><br>
							<?php
							// Display device information if available.
							if ( ! empty( $passkey['device_info'] ) ) :
								$device_info = $passkey['device_info'];
								$icon        = '🔐';
								$label       = '';

								if ( 'password_manager' === $device_info['type'] ) {
									$icon  = '🔑';
									$label = sprintf(
										/* translators: 1: Password manager name, 2: Browser name */
										esc_html__( 'Registered with: %1$s on %2$s', 'secure-login-collector' ),
										esc_html( $device_info['platform'] ),
										esc_html( $device_info['browser'] )
									);
								} else {
									// Device/platform authentication.
									$platform_icons = array(
										'iPhone'  => '📱',
										'iPad'    => '📱',
										'macOS'   => '💻',
										'Windows' => '🖥️',
										'Android' => '📱',
										'Linux'   => '🐧',
									);
									$icon           = $platform_icons[ $device_info['platform'] ] ?? '🔐';
									$label          = sprintf(
										/* translators: 1: Platform name, 2: Registration date */
										esc_html__( 'Registered with: %1$s on %2$s', 'secure-login-collector' ),
										esc_html( $device_info['platform'] ),
										esc_html( gmdate( 'Y-m-d', strtotime( $passkey['registered_at'] ) ) )
									);
								}
								?>
								<div class="seculoco-device-info-box">
									<span class="seculoco-device-info-icon"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded emoji. ?></span>
									<strong><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above. ?></strong>
									<?php if ( 'device' === $device_info['type'] ) : ?>
										<br><span class="seculoco-device-info-browser">
											<?php
											printf(
												/* translators: %s: Browser name */
												esc_html__( 'Browser: %s', 'secure-login-collector' ),
												esc_html( $device_info['browser'] )
											);
											?>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<strong><?php esc_html_e( 'Registered:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( $passkey['registered_at'] ); ?><br>
							<strong><?php esc_html_e( 'ID:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( substr( $passkey['credential_id'], 0, 20 ) . '...' ); ?>
						</div>
						

						<?php if ( $has_encrypted_data ) : ?>
							<div class="seculoco-alert seculoco-alert-danger seculoco-margin-top-20">
								<span class="seculoco-alert-icon">⚠️</span>
								<div class="seculoco-alert-content">
									<div class="seculoco-alert-title"><?php esc_html_e( 'CRITICAL WARNING: Data Loss Risk', 'secure-login-collector' ); ?></div>
									<div class="seculoco-alert-message">
										<p><strong><?php esc_html_e( 'Deleting this passkey will permanently prevent decryption of all existing login data encrypted with this passkey.', 'secure-login-collector' ); ?></strong></p>
										
										<p><strong><?php esc_html_e( 'This action CANNOT be undone. There is NO recovery method.', 'secure-login-collector' ); ?></strong></p>
									</div>
									<button type="button" class="seculoco-btn seculoco-btn-danger" id="delete-passkey-btn"
											data-credential-id="<?php echo esc_attr( $passkey['credential_id'] ); ?>">
										<?php esc_html_e( 'Reset Passkey', 'secure-login-collector' ); ?>
									</button>
								</div>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Register a passkey using your device biometrics or a password manager for ultra-secure encryption.', 'secure-login-collector' ); ?></p>

						<div class="passkey-registration-form seculoco-passkey-form-container">
							<div class="seculoco-form-group seculoco-passkey-form-group">
								<label class="seculoco-form-label">
									<?php esc_html_e( 'Choose Your Passkey Method:', 'secure-login-collector' ); ?>
								</label>
								<div class="seculoco-passkey-radio-group">
									<label class="seculoco-passkey-radio-label">
										<input type="radio" name="authenticator_type" value="cross-platform" checked>
										<strong><?php esc_html_e( 'Password Manager', 'secure-login-collector' ); ?></strong>
										<span class="seculoco-form-help seculoco-passkey-radio-help">
											<?php esc_html_e( '1Password, Bitwarden, Dashlane, or other password managers', 'secure-login-collector' ); ?>
										</span>
									</label>
									<label class="seculoco-passkey-radio-label">
										<input type="radio" name="authenticator_type" value="platform">
										<strong><?php esc_html_e( 'Device/Platform Biometrics', 'secure-login-collector' ); ?></strong>
										<span class="seculoco-form-help seculoco-passkey-radio-help">
											<?php esc_html_e( 'iPhone Face ID, Android Fingerprint, Windows Hello, Touch ID', 'secure-login-collector' ); ?>
										</span>
									</label>
								</div>

								<div class="seculoco-passkey-tip-box">
									<span class="seculoco-passkey-tip-title">💡 <?php esc_html_e( 'Tip:', 'secure-login-collector' ); ?></span>
									<span class="seculoco-passkey-tip-text">
										<?php esc_html_e( 'If you select "Device/Platform Biometrics" and your password manager popup appears, simply click away from it or press Escape. Your device\'s biometric prompt will appear next.', 'secure-login-collector' ); ?>
									</span>
								</div>
							</div>

								<?php $secure_context_allowed = $this->is_secure_context_allowed(); ?>
								<button type="button"
										id="register-passkey-btn"
										class="seculoco-btn seculoco-btn-primary seculoco-btn-lg seculoco-passkey-register-btn<?php echo $secure_context_allowed ? '' : ' is-disabled'; ?>"
										<?php echo $secure_context_allowed ? '' : 'disabled="disabled" aria-disabled="true"'; ?>
										data-authenticator-type="cross-platform">
									<span>🔑</span>
									<?php esc_html_e( 'Register Passkey', 'secure-login-collector' ); ?>
								</button>

								<?php if ( ! $secure_context_allowed ) : ?>
									<p class="seculoco-form-help seculoco-passkey-warning">
										<?php esc_html_e( 'Passkey registration requires HTTPS or running the site from localhost/127.0.0.1.', 'secure-login-collector' ); ?>
									</p>
								<?php endif; ?>

								<span class="spinner seculoco-spinner-inline"></span>
							</div>
						<?php endif; ?>
					
					<div id="passkey-status-message" class="seculoco-passkey-status"></div>
				</div>
			</div>			
		</div>

		<?php
	}


	/**
	 * Get the global site-wide passkey.
	 *
	 * @return array|null Passkey data or null if not registered.
	 */
	private function get_global_passkey() {
		$passkey = get_option( SECULOCO_OPTION_GLOBAL_PASSKEY );
		return is_array( $passkey ) ? $passkey : null;
	}

	/**
	 * Check if a global passkey is registered for the site.
	 *
	 * @return bool True if passkey is registered.
	 */
	private function has_passkey() {
		return (bool) get_option( SECULOCO_OPTION_PASSKEY_REGISTERED, false );
	}

	/**
	 * Check if site is using HTTPS.
	 */
	private function is_https() {
		return is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );
	}

	/**
	 * Determine if the current host is allowed for insecure WebAuthn use (localhost/loopback).
	 *
	 * @return bool
	 */
	private function is_local_dev_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		if ( empty( $host ) ) {
			return false;
		}

		$host = preg_replace( '/:\\d+$/', '', $host );

		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Check whether the current context meets WebAuthn secure-context requirements.
	 *
	 * @return bool
	 */
	private function is_secure_context_allowed() {
		return $this->is_https() || $this->is_local_dev_host();
	}

	/**
	 * Handle start registration AJAX.
	 */
	public function handle_start_registration() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		if ( ! $this->is_secure_context_allowed() ) {
			wp_send_json_error( __( 'Passkey registration requires HTTPS or running the site from localhost/127.0.0.1.', 'secure-login-collector' ) );
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
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		if ( ! $this->is_secure_context_allowed() ) {
			wp_send_json_error( __( 'Passkey registration requires HTTPS or running the site from localhost/127.0.0.1.', 'secure-login-collector' ) );
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data decoded and validated below.
		$device_info_raw = wp_unslash( $_POST['device_info'] ?? '' );

		// Auto-generate name if not provided.
		if ( empty( $name ) ) {
			$name = 'Passkey ' . date( 'Y-m-d H:i' );
		}

		if ( empty( $credential_id ) ) {
			wp_send_json_error( __( 'Missing required registration data (credential_id)', 'secure-login-collector' ) );
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
			wp_send_json_error( __( 'Registration challenge expired or not set. Please try again.', 'secure-login-collector' ) );
		}

		$client_data_array = json_decode( base64_decode( $client_data ), true );
		if ( ! $client_data_array ) {
			wp_send_json_error( __( 'Invalid client data format', 'secure-login-collector' ) );
		}

		// Validate origin.
		$expected_origin = home_url();
		$received_origin = $client_data_array['origin'] ?? '';
		if ( $received_origin !== $expected_origin ) {
			wp_send_json_error(
				sprintf(
					__( 'Origin verification failed. Expected: %1$s, Received: %2$s', 'secure-login-collector' ),
					$expected_origin,
					$received_origin
				)
			);
		}

		// Validate type.
		$received_type = $client_data_array['type'] ?? '';
		if ( $received_type !== 'webauthn.create' ) {
			wp_send_json_error(
				sprintf(
					__( 'Invalid WebAuthn operation type. Expected: webauthn.create, Received: %s', 'secure-login-collector' ),
					$received_type
				)
			);
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
				wp_send_json_error( __( 'Challenge verification failed', 'secure-login-collector' ) );
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
				wp_send_json_error( __( 'Invalid attestation object format', 'secure-login-collector' ) );
			}

			// Log that attestation was received (full validation would require CBOR parser).

			// TODO: For enhanced security, implement full attestation validation.

			// Check if a global passkey is already registered.
			if ( $this->has_passkey() ) {
				wp_send_json_error( __( 'A passkey is already registered. Please delete it first to register a new one.', 'secure-login-collector' ) );
				return;
			}

			$user_id = get_current_user_id();

			// Parse and sanitize device info.
			$device_info = array();
			if ( ! empty( $device_info_raw ) ) {
				$decoded_info = json_decode( $device_info_raw, true );
				if ( is_array( $decoded_info ) ) {
					$device_info = array(
						'browser'  => sanitize_text_field( $decoded_info['browser'] ?? 'Unknown Browser' ),
						'platform' => sanitize_text_field( $decoded_info['platform'] ?? 'Unknown Platform' ),
						'type'     => sanitize_text_field( $decoded_info['type'] ?? 'device' ),
					);
				}
			}

			// Store global passkey metadata (site-wide, not per-user).
			// Note: The wrapping key derivation happens in passkey_init_setup handler.
			$passkey_data = array(
				'name'          => $name,
				'credential_id' => $credential_id,
				'public_key'    => $public_key,
				'registered_at' => current_time( 'mysql' ),
				'registered_by' => $user_id,
				'last_used'     => null,
				'device_info'   => $device_info,
				'version'       => 2,
			);
			update_option( SECULOCO_OPTION_GLOBAL_PASSKEY, $passkey_data );

			// Store credential ID for quick lookup.
			update_option( SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID, $credential_id );

			// Store globally for verification.
			update_option(
				'passkey_credential_' . $credential_id,
				array(
					'public_key' => $public_key,
					'user_id'    => $user_id,
				)
			);

			// Set global passkey registered flag.
			update_option( SECULOCO_OPTION_PASSKEY_REGISTERED, true );
			update_option( SECULOCO_OPTION_PASSKEY_REGISTERED_AT, current_time( 'mysql' ) );

			// Also ensure pro keys active flag is set for consistency with V2 handler.
			update_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, true );

			// Clear challenge.
			delete_transient( 'passkey_reg_challenge_' . $user_id );

			wp_send_json_success(
				array(
					'message' => __( 'Passkey registered successfully', 'secure-login-collector' ),
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
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		if ( empty( $credential_id ) ) {
			wp_send_json_error( __( 'Missing credential ID', 'secure-login-collector' ) );
		}

		// Get global passkey and verify.
		$passkey = $this->get_global_passkey();

		// Verify this is the correct passkey.
		if ( empty( $passkey ) || $passkey['credential_id'] !== $credential_id ) {
			wp_send_json_error( __( 'Passkey not found or mismatch', 'secure-login-collector' ) );
			return;
		}

		// CRITICAL: Mark all pro-encrypted login data as undecryptable BEFORE deleting keys.
		// This ensures data integrity and proper user notification.
		// Note: Pass 0 as user_id since frontend-submitted data is stored with user_id = 0.
		global $wpdb;
		$table_name = $wpdb->prefix . 'seculoco_data';
		if ( ! class_exists( 'Seculoco_Database_Manager' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-database-manager.php';
		}
		$db_manager     = new Seculoco_Database_Manager( $table_name );
		$affected_count = $db_manager->mark_login_data_as_undecryptable( 'pro' );

		// Delete the global passkey.
		delete_option( SECULOCO_OPTION_GLOBAL_PASSKEY );
		delete_option( SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID );
		delete_option( 'passkey_credential_' . $credential_id );

		// Delete the pro keys and clear the global flag.
		if ( ! class_exists( 'Seculoco_Encryption_Handler_Factory' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-encryption-handler-factory.php';
		}

		$encryption_handler = Seculoco_Encryption_Handler_Factory::get_shared_handler();

		if ( ! $encryption_handler instanceof Seculoco_Encryption_Handler_V2_Premium ) {
			wp_send_json_error( __( 'Premium encryption handler unavailable', 'secure-login-collector' ) );
			return;
		}

		/** @var Seculoco_Encryption_Handler_V2_Premium $premium_handler */
		$premium_handler = $encryption_handler;
		$premium_handler->delete_pro_keys();

		// Clear global passkey registered flag.
		delete_option( SECULOCO_OPTION_PASSKEY_REGISTERED );
		delete_option( SECULOCO_OPTION_PASSKEY_REGISTERED_AT );

		// Prepare success message with undecryptable count.
		$message = __( 'Passkey deleted and encryption keys removed', 'secure-login-collector' );
		if ( $affected_count > 0 ) {
			// translators: %d is the number of login entries marked as undecryptable.
			$message .= '. ' . sprintf( _n( '%d login entry marked as permanently undecryptable.', '%d login entries marked as permanently undecryptable.', $affected_count, 'secure-login-collector' ), $affected_count );
		}

		wp_send_json_success(
			array(
				'message'          => $message,
				'success'          => true,
				'undecryptable_count' => $affected_count,
			)
		);
	}

	/**
	 * Handle get passkey status AJAX.
	 */
	public function handle_get_passkey_status() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		$passkey = $this->get_global_passkey();

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
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		if ( ! $this->is_secure_context_allowed() ) {
			wp_send_json_error( __( 'Passkey registration requires HTTPS or running the site from localhost/127.0.0.1.', 'secure-login-collector' ) );
		}

		// Verify pro license.
		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			wp_send_json_error( __( 'Pro license required to initialize pro encryption.', 'secure-login-collector' ) );
			return;
		}

		// Check if passkey already exists.
		if ( $this->has_passkey() ) {
			wp_send_json_error( __( 'A passkey is already registered.', 'secure-login-collector' ) );
		}

		$user_id = get_current_user_id();

		// Get passkey credential data from registration.
		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		if ( empty( $credential_id ) ) {
			wp_send_json_error( __( 'Missing credential ID', 'secure-login-collector' ) );
		}

		// Step 1: Initialize encryption handler V2 for dual-key system.
		if ( ! class_exists( 'Seculoco_Encryption_Handler_Factory' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-encryption-handler-factory.php';
		}

		$encryption_handler = Seculoco_Encryption_Handler_Factory::get_shared_handler();

		if ( ! $encryption_handler instanceof Seculoco_Encryption_Handler_V2_Premium ) {
			wp_send_json_error( __( 'Premium encryption handler unavailable', 'secure-login-collector' ) );
			return;
		}

		/** @var Seculoco_Encryption_Handler_V2_Premium $premium_handler */
		$premium_handler = $encryption_handler;

		// Step 2: Only touch password-based keys when they already exist.
		if ( $this->has_standard_password_keys() ) {
			$free_result = $premium_handler->initialize_free_keys();
			if ( is_wp_error( $free_result ) ) {
				wp_send_json_error(
					sprintf(
						__( 'Failed to initialize free keys: %s', 'secure-login-collector' ),
						$free_result->get_error_message()
					)
				);
			}
		} else {
			$free_result = array(
				'status'  => 'skipped',
				'type'    => 'standard',
				'message' => __( 'Password-based keys skipped because passkey mode does not require them.', 'secure-login-collector' ),
			);
		}

		// Step 3: Derive key from passkey for wrapping (no user_id - global passkey).
		$passkey_derived_key = $this->derive_wrapping_key( $credential_id );

		// Step 4: Initialize PRO keys with passkey wrapping.
		
		$pro_result = $premium_handler->initialize_pro_keys( $passkey_derived_key );

		// The passkey-derived key directly wraps the PRO private key.

		// Get the pro public key for response.
		$public_key_pro = get_option( SECULOCO_OPTION_PUBLIC_KEY_PRO );

		wp_send_json_success(
			array(
				'message'     => __( 'Passkey-wrapped PRO encryption initialized successfully', 'secure-login-collector' ),
				'public_key'  => $public_key_pro,
				'free_status' => $free_result,
				'pro_status'  => $pro_result,
			)
		);
	}

	/**
	 * Derive a wrapping key from passkey credentials.
	 *
	 * Global passkey storage: No user_id needed since passkey is site-wide.
	 *
	 * @param string $credential_id Passkey credential ID.
	 * @return string Derived wrapping key.
	 */
	private function derive_wrapping_key( $credential_id ) {
		// Use credential ID + salt for key derivation (no user_id - passkey is global).
		// This is deterministic but unique per passkey credential.
		$salt         = wp_salt( 'auth' ) . 'passkey_wrap';
		$key_material = $credential_id . '|' . $salt;

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
			wp_send_json_error( __( 'Invalid security token', 'secure-login-collector' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		wp_send_json_success(
			array(
				'user_id' => get_current_user_id(),
			)
		);
	}

	/**
	 * Handle AJAX request to derive passkey unwrapping key.
	 *
	 * Global passkey storage: No user_id needed.
	 */
	public function handle_derive_passkey_unwrapping_key() {
		// Accept multiple nonce types for compatibility.
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) &&
			! wp_verify_nonce( $nonce, 'seculoco_nonce' ) &&
			! wp_verify_nonce( $nonce, 'passkey_admin_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token', 'secure-login-collector' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );

		if ( empty( $credential_id ) ) {
			wp_send_json_error( __( 'Missing credential ID', 'secure-login-collector' ) );
		}

		// Derive the same key as server-side wrapping (no user_id - global passkey).
		$key = $this->derive_wrapping_key( $credential_id );

		wp_send_json_success(
			array(
				'key' => base64_encode( $key ),
			)
		);
	}

	/**
	 * Determine whether password-based (standard) encryption keys already exist.
	 *
	 * @return bool
	 */
	private function has_standard_password_keys() {
		$public_key_standard  = get_option( SECULOCO_OPTION_PUBLIC_KEY_STANDARD );
		$wrapped_key_standard = get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_STANDARD );

		return ! empty( $public_key_standard ) && ! empty( $wrapped_key_standard );
	}
}
