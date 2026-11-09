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
 * Key wrapping model (since 2.1.0):
 * - The Pro RSA keypair is generated in the admin browser.
 * - The private key is wrapped with AES-256-GCM under a key derived (HKDF-SHA256)
 *   from the WebAuthn PRF extension output of the registered passkey.
 * - The server only stores the public key and the wrapped blob (format version 3).
 *   It never holds the wrapping key or the private key.
 *
 * Blobs written before 2.1.0 (no version field) cannot be unlocked any more. The admin
 * is told to reset the passkey and register it again; a new keypair is created.
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
	 * Current wrapped-key format version.
	 */
	const WRAP_VERSION = 3;

	/**
	 * Wrapping method identifier stored in the blob.
	 */
	const WRAP_METHOD = 'prf';

	/**
	 * HKDF info string used by the browser when deriving the wrapping key.
	 */
	const KDF_INFO = 'seculoco-pro-wrap-v1';

	/**
	 * Guard so the outdated-setup notice renders once per request.
	 *
	 * @var bool
	 */
	private static $outdated_notice_rendered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {

		// Enqueue admin styles and scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Warn when a pre-2.1.0 passkey setup is found.
		add_action( 'admin_notices', array( $this, 'maybe_show_outdated_notice' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_seculoco_passkey_start_registration', array( $this, 'handle_start_registration' ) );
		add_action( 'wp_ajax_seculoco_passkey_complete_registration', array( $this, 'handle_complete_registration' ) );
		add_action( 'wp_ajax_seculoco_passkey_delete', array( $this, 'handle_delete_passkey' ) );
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_styles( $hook ) {
		// Load CSS on all Secure Login Collector admin pages.
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
		if ( strpos( $hook, 'secure-login-collector' ) === false ) {
			return;
		}

		// Only load passkey scripts for pro license users.
		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			return;
		}

		// Enqueue passkey management script (also provides the shared passkey crypto helpers).
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
			'kdfInfo'              => self::KDF_INFO,
			'wrapVersion'          => self::WRAP_VERSION,
			'strings'              => array(
				'warningDataLoss'      => __( 'WARNING: Resetting this passkey will make ALL existing encrypted data permanently inaccessible! This action CANNOT be undone. There is NO recovery method. Are you absolutely sure you want to proceed?', 'secure-login-collector' ),
				'warningSimple'        => __( 'Are you sure you want to reset this passkey?\n\nYou can register a new passkey afterward.', 'secure-login-collector' ),
				'deleting'             => __( 'Resetting...', 'secure-login-collector' ),
				'deletePasskey'        => __( 'Reset Passkey', 'secure-login-collector' ),
				'deleteSuccess'        => __( 'Passkey reset successfully!', 'secure-login-collector' ),
				'deleteFailed'         => __( 'Failed to reset passkey:', 'secure-login-collector' ),
				'typeConfirmToReset'   => __( 'Type "RESET" to confirm:', 'secure-login-collector' ),
				'resetConfirmRequired' => __( 'Please type "RESET" to confirm.', 'secure-login-collector' ),
				'resetModalTitle'      => __( 'Reset Passkey', 'secure-login-collector' ),
				'cancel'               => __( 'Cancel', 'secure-login-collector' ),
				'networkError'         => __( 'Network error occurred. Please try again.', 'secure-login-collector' ),
				'noWebAuthn'           => __( 'Your browser does not support WebAuthn/Passkeys.', 'secure-login-collector' ),
				'registerSuccess'      => __( 'Passkey registered successfully!', 'secure-login-collector' ),
				'httpsRequired'        => __( 'Passkeys require HTTPS (or http://localhost / 127.0.0.1 while developing). Please enable SSL before registering.', 'secure-login-collector' ),
				'prfUnsupported'       => __( 'This passkey provider does not support the WebAuthn PRF extension, which is required to protect the encryption key. Use a passkey from iCloud Keychain (macOS 15 / iOS 18 or newer), Google Password Manager, 1Password, Bitwarden or a FIDO2 security key such as a YubiKey 5.', 'secure-login-collector' ),
				'prfOutputMissing'     => __( 'The passkey did not return the PRF secret. Please try again with a PRF-capable passkey.', 'secure-login-collector' ),
				'cryptoSelfTestFailed' => __( 'Key wrapping self-test failed. Nothing was saved.', 'secure-login-collector' ),
				'outdatedSetup'        => $this->get_outdated_setup_message(),
			),
		);

		// Use wp_localize_script for proper escaping.
		wp_localize_script( 'seculoco-passkey-pro-js', 'secureLoginPasskeyData', $script_data );
	}

	/**
	 * Message shown for passkey setups created before 2.1.0.
	 *
	 * @return string
	 */
	public static function get_outdated_setup_message() {
		return __( 'The passkey setup is outdated: the key protection changed in version 2.1.0. Reset the passkey in the encryption settings and register it again. Entries from the old setup cannot be decrypted.', 'secure-login-collector' );
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

			<?php if ( $passkey && $this->has_outdated_setup() ) : ?>
				<div class="seculoco-alert seculoco-alert-danger seculoco-passkey-outdated-card">
					<span class="seculoco-alert-icon">⚠️</span>
					<div class="seculoco-alert-content">
						<div class="seculoco-alert-title"><?php esc_html_e( 'Passkey setup outdated', 'secure-login-collector' ); ?></div>
						<div class="seculoco-alert-message"><?php echo esc_html( self::get_outdated_setup_message() ); ?></div>
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
							<strong><?php esc_html_e( 'ID:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( substr( $passkey['credential_id'], 0, 20 ) . '...' ); ?><br>
							<strong><?php esc_html_e( 'Key protection:', 'secure-login-collector' ); ?></strong>
							<?php
							if ( $this->has_outdated_setup() ) {
								esc_html_e( 'Outdated (reset and register again)', 'secure-login-collector' );
							} else {
								esc_html_e( 'Passkey PRF (zero-knowledge)', 'secure-login-collector' );
							}
							?>
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
						<?php else : ?>
							<p class="seculoco-margin-top-20">
								<button type="button" class="seculoco-btn seculoco-btn-danger" id="delete-passkey-btn"
										data-credential-id="<?php echo esc_attr( $passkey['credential_id'] ); ?>">
									<?php esc_html_e( 'Reset Passkey', 'secure-login-collector' ); ?>
								</button>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Register a passkey using your device biometrics or a password manager for ultra-secure encryption.', 'secure-login-collector' ); ?></p>
						<p class="description"><?php esc_html_e( 'The passkey must support the WebAuthn PRF extension (for example iCloud Keychain on macOS 15 / iOS 18 or newer, Google Password Manager, 1Password, Bitwarden, YubiKey 5). The encryption key is generated and protected in your browser; the server never sees it.', 'secure-login-collector' ); ?></p>

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
	 * Show a notice on the plugin pages while a pre-2.1.0 passkey setup is stored.
	 */
	public function maybe_show_outdated_notice() {
		if ( self::$outdated_notice_rendered || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! Seculoco_License_Manager::has_pro_license() || ! $this->has_outdated_setup() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( $screen->id, 'secure-login-collector' ) ) {
			return;
		}

		self::$outdated_notice_rendered = true;
		?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Secure Login Collector: passkey setup outdated.', 'secure-login-collector' ); ?></strong> <?php echo esc_html( self::get_outdated_setup_message() ); ?></p>
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
	 * Based on the stored passkey record, which is what the Reset button removes,
	 * so a stale flag or an orphaned key blob never blocks a new registration.
	 *
	 * @return bool True if passkey is registered.
	 */
	private function has_passkey() {
		return null !== $this->get_global_passkey();
	}

	/**
	 * Whether a stored Pro blob uses the current (2.1.0+) PRF wrapping format.
	 *
	 * @param mixed $wrapped Stored blob.
	 * @return bool
	 */
	public static function is_current_wrapped_key( $wrapped ) {
		return is_array( $wrapped )
			&& self::WRAP_VERSION === (int) ( $wrapped['version'] ?? 0 )
			&& self::WRAP_METHOD === ( $wrapped['method'] ?? '' );
	}

	/**
	 * Whether passkey encryption is fully set up and usable for new submissions.
	 *
	 * A setup from before 2.1.0 is not ready: its private key cannot be unwrapped any
	 * more, so no new data may be encrypted to its public key.
	 *
	 * @return bool
	 */
	public static function is_setup_ready() {
		return (bool) get_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, false )
			&& (bool) get_option( SECULOCO_OPTION_PASSKEY_REGISTERED, false )
			&& self::is_current_wrapped_key( get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO ) );
	}

	/**
	 * Get the stored Pro wrapped private key blob.
	 *
	 * @return array|null
	 */
	private function get_pro_wrapped_key() {
		$wrapped = get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO );
		return is_array( $wrapped ) ? $wrapped : null;
	}

	/**
	 * Whether a registered passkey still uses a Pro blob from before 2.1.0.
	 *
	 * Requires a registered passkey, so the Reset button it asks for is always shown.
	 * Without a passkey a new registration simply replaces the old blob.
	 *
	 * @return bool
	 */
	public function has_outdated_setup() {
		$wrapped = $this->get_pro_wrapped_key();
		return $this->has_passkey() && ! empty( $wrapped['ciphertext'] ) && ! self::is_current_wrapped_key( $wrapped );
	}

	/**
	 * Mark all passkey-protected entries as undecryptable.
	 *
	 * @return int Number of affected entries.
	 */
	private function mark_pro_entries_undecryptable() {
		global $wpdb;
		if ( ! class_exists( 'Seculoco_Database_Manager' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-database-manager.php';
		}
		$db_manager = new Seculoco_Database_Manager( $wpdb->prefix . 'seculoco_data' );
		return (int) $db_manager->mark_login_data_as_undecryptable( 'pro' );
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
	 * Common guard for passkey AJAX handlers: nonce, capability, secure context, license.
	 * Sends a JSON error and exits on failure.
	 */
	private function guard_passkey_request() {
		check_ajax_referer( 'passkey_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		if ( ! $this->is_secure_context_allowed() ) {
			wp_send_json_error( __( 'Passkey registration requires HTTPS or running the site from localhost/127.0.0.1.', 'secure-login-collector' ) );
		}

		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			wp_send_json_error( __( 'Pro license required for passkey encryption.', 'secure-login-collector' ) );
		}
	}

	/**
	 * Handle start registration AJAX.
	 */
	public function handle_start_registration() {
		$this->guard_passkey_request();

		// Get authenticator type from request (platform, cross-platform, or auto).
		$authenticator_type = isset( $_POST['authenticator_type'] ) ? sanitize_text_field( wp_unslash( $_POST['authenticator_type'] ) ) : 'auto';

		// Generate challenge.
		$challenge = base64_encode( random_bytes( 32 ) );
		set_transient( 'passkey_reg_challenge_' . get_current_user_id(), $challenge, 300 );

		$user = wp_get_current_user();

		// Build authenticatorSelection based on user choice.
		$authenticator_selection = array(
			'userVerification'   => 'required',  // Always require biometric/PIN verification (also required for PRF).
			'requireResidentKey' => false,
		);

		// Set authenticatorAttachment based on user choice.
		if ( 'platform' === $authenticator_type ) {
			// Platform authenticators: Touch ID, Face ID, Windows Hello.
			$authenticator_selection['authenticatorAttachment'] = 'platform';
		} elseif ( 'cross-platform' === $authenticator_type ) {
			// Cross-platform authenticators: YubiKey, security keys, password managers.
			$authenticator_selection['authenticatorAttachment'] = 'cross-platform';
		}
		// If 'auto', omit authenticatorAttachment to allow any type including password managers.

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
	 *
	 * The browser sends the WebAuthn ceremony result plus the Pro public key and the
	 * PRF-wrapped private key (format version 3). Nothing may be registered yet.
	 */
	public function handle_complete_registration() {
		$this->guard_passkey_request();

		$name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$credential_id = sanitize_text_field( wp_unslash( $_POST['credential_id'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Binary data validated as base64 below.
		$public_key = wp_unslash( $_POST['public_key'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data decoded and validated below.
		$client_data = wp_unslash( $_POST['client_data'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data decoded and validated below.
		$device_info_raw = wp_unslash( $_POST['device_info'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Binary attestation data validated as base64 below.
		$attestation = wp_unslash( $_POST['attestation'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PEM validated with OpenSSL below.
		$public_key_pro = wp_unslash( $_POST['public_key_pro'] ?? '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validated field by field below.
		$wrapped_raw = wp_unslash( $_POST['wrapped_key'] ?? '' );

		// Auto-generate name if not provided.
		if ( empty( $name ) ) {
			$name = 'Passkey ' . gmdate( 'Y-m-d H:i' );
		}

		if ( empty( $credential_id ) ) {
			wp_send_json_error( __( 'Missing required registration data (credential_id)', 'secure-login-collector' ) );
		}

		if ( $this->has_passkey() ) {
			wp_send_json_error( __( 'A passkey is already registered. Please delete it first to register a new one.', 'secure-login-collector' ) );
		}

		// WebAuthn public key might not be available in all browsers.
		if ( empty( $public_key ) || 'not_available' === $public_key ) {
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
					/* translators: 1: expected origin, 2: received origin */
					__( 'Origin verification failed. Expected: %1$s, Received: %2$s', 'secure-login-collector' ),
					$expected_origin,
					$received_origin
				)
			);
		}

		// Validate type.
		$received_type = $client_data_array['type'] ?? '';
		if ( 'webauthn.create' !== $received_type ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: received WebAuthn operation type */
					__( 'Invalid WebAuthn operation type. Expected: webauthn.create, Received: %s', 'secure-login-collector' ),
					$received_type
				)
			);
		}

		// Compare challenges (WebAuthn uses base64url).
		$received_challenge     = $client_data_array['challenge'] ?? '';
		$expected_challenge_url = rtrim( strtr( $expected_challenge, '+/', '-_' ), '=' );

		if ( $received_challenge !== $expected_challenge_url ) {
			$received_as_base64 = strtr( $received_challenge, '-_', '+/' );
			$pad                = strlen( $received_as_base64 ) % 4;
			if ( $pad ) {
				$received_as_base64 .= str_repeat( '=', 4 - $pad );
			}

			if ( $received_as_base64 !== $expected_challenge ) {
				wp_send_json_error( __( 'Challenge verification failed', 'secure-login-collector' ) );
			}
		}

		// Basic attestation validation (full validation would require a CBOR parser).
		// Format check only, nothing is executed.
		if ( empty( $attestation ) || false === base64_decode( $attestation, true ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			wp_send_json_error( __( 'Invalid attestation object format', 'secure-login-collector' ) );
		}

		// Validate the browser-wrapped private key blob (version 3, bound to this credential).
		$wrapped = $this->validate_wrapped_key( $wrapped_raw, $credential_id );
		if ( is_wp_error( $wrapped ) ) {
			wp_send_json_error( $wrapped->get_error_message() );
		}

		$public_key_pro = $this->validate_rsa_public_key_pem( $public_key_pro );
		if ( is_wp_error( $public_key_pro ) ) {
			wp_send_json_error( $public_key_pro->get_error_message() );
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

		// An orphaned Pro key (no passkey registered) is replaced; entries encrypted to it are lost.
		if ( $this->get_pro_wrapped_key() ) {
			$this->mark_pro_entries_undecryptable();
		}

		// Write order: public key, wrapped blob, passkey metadata, flags.
		update_option( SECULOCO_OPTION_PUBLIC_KEY_PRO, $public_key_pro );
		update_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO, $wrapped );

		update_option(
			SECULOCO_OPTION_GLOBAL_PASSKEY,
			array(
				'name'          => $name,
				'credential_id' => $credential_id,
				'public_key'    => $public_key,
				'registered_at' => current_time( 'mysql' ),
				'registered_by' => $user_id,
				'last_used'     => null,
				'device_info'   => $device_info,
				'version'       => 2,
				'wrap_version'  => self::WRAP_VERSION,
			)
		);

		update_option( SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID, $credential_id );
		update_option( SECULOCO_OPTION_PASSKEY_REGISTERED, true );
		update_option( SECULOCO_OPTION_PASSKEY_REGISTERED_AT, current_time( 'mysql' ) );
		update_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, true );

		// Clear challenge.
		delete_transient( 'passkey_reg_challenge_' . $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Passkey registered successfully', 'secure-login-collector' ),
			)
		);
	}

	/**
	 * Handle delete passkey AJAX.
	 */
	public function handle_delete_passkey() {
		$this->guard_passkey_request();

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
		$affected_count = $this->mark_pro_entries_undecryptable();

		// Delete the global passkey.
		delete_option( SECULOCO_OPTION_GLOBAL_PASSKEY );
		delete_option( SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID );
		delete_option( 'passkey_credential_' . $credential_id );

		// Delete the Pro keys and clear the flags.
		delete_option( SECULOCO_OPTION_PUBLIC_KEY_PRO );
		delete_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO );
		delete_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE );
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
				'message'             => $message,
				'success'             => true,
				'undecryptable_count' => $affected_count,
			)
		);
	}

	/**
	 * Validate a browser-produced version 3 wrapped key blob.
	 *
	 * @param string $raw           JSON string from the request.
	 * @param string $credential_id Credential the blob must be bound to.
	 * @return array|WP_Error Normalised blob (known keys only) or error.
	 */
	private function validate_wrapped_key( $raw, $credential_id ) {
		$error = new WP_Error( 'invalid_wrapped_key', __( 'Invalid wrapped key data.', 'secure-login-collector' ) );

		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return $error;
		}

		if ( (int) ( $data['version'] ?? 0 ) !== self::WRAP_VERSION || self::WRAP_METHOD !== ( $data['method'] ?? '' ) ) {
			return $error;
		}

		if ( ( $data['credential_id'] ?? '' ) !== $credential_id ) {
			return $error;
		}

		$ciphertext = $this->decode_base64_field( $data['ciphertext'] ?? '' );
		$iv         = $this->decode_base64_field( $data['iv'] ?? '', 12 );
		$tag        = $this->decode_base64_field( $data['tag'] ?? '', 16 );
		$prf_salt   = $this->decode_base64_field( $data['prf_salt'] ?? '', 32 );

		// A PKCS#8 PEM of an RSA-2048 key is about 1.7 KB; allow some headroom, reject junk.
		if ( false === $ciphertext || strlen( $ciphertext ) < 512 || strlen( $ciphertext ) > 16384 ) {
			return $error;
		}

		if ( false === $iv || false === $tag || false === $prf_salt ) {
			return $error;
		}

		// Binary key material is stored as base64; nothing is executed.
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array(
			'version'       => self::WRAP_VERSION,
			'method'        => self::WRAP_METHOD,
			'algorithm'     => 'aes-256-gcm',
			'kdf'           => 'HKDF-SHA256',
			'kdf_info'      => self::KDF_INFO,
			'prf_salt'      => base64_encode( $prf_salt ),
			'credential_id' => $credential_id,
			'ciphertext'    => base64_encode( $ciphertext ),
			'iv'            => base64_encode( $iv ),
			'tag'           => base64_encode( $tag ),
		);
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Strictly decode a base64 field, optionally enforcing an exact byte length.
	 *
	 * @param mixed $value          Raw value.
	 * @param int   $expected_bytes Exact decoded length, 0 for any.
	 * @return string|false Decoded bytes or false.
	 */
	private function decode_base64_field( $value, $expected_bytes = 0 ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		// Validates client key material, nothing is executed.
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded || '' === $decoded ) {
			return false;
		}

		if ( $expected_bytes > 0 && strlen( $decoded ) !== $expected_bytes ) {
			return false;
		}

		return $decoded;
	}

	/**
	 * Validate a browser-generated RSA-2048 public key in SPKI PEM form.
	 *
	 * @param string $pem Raw PEM.
	 * @return string|WP_Error Normalised PEM or error.
	 */
	private function validate_rsa_public_key_pem( $pem ) {
		$error = new WP_Error( 'invalid_public_key', __( 'Invalid RSA public key.', 'secure-login-collector' ) );

		$pem = trim( str_replace( "\r", '', (string) $pem ) );

		if ( ! preg_match( '/^-----BEGIN PUBLIC KEY-----\n[A-Za-z0-9+\/=\n]+\n-----END PUBLIC KEY-----$/', $pem ) ) {
			return $error;
		}

		if ( ! function_exists( 'openssl_pkey_get_public' ) ) {
			return new WP_Error( 'openssl_missing', __( 'OpenSSL PHP extension is required for key generation.', 'secure-login-collector' ) );
		}

		$key = openssl_pkey_get_public( $pem );
		if ( false === $key ) {
			return $error;
		}

		$details = openssl_pkey_get_details( $key );
		if ( ! $details || OPENSSL_KEYTYPE_RSA !== $details['type'] || 2048 !== (int) $details['bits'] ) {
			return $error;
		}

		return $pem . "\n";
	}
}
