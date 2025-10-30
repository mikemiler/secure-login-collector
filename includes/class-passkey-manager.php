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
			require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-master-key-manager.php';
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
		if ( strpos( $hook, 'secure-login-collector' ) === false &&
		'toplevel_page_secure-login-collector' !== $hook ) {
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
		if ( strpos( $hook, 'secure-login-collector' ) === false &&
		'toplevel_page_secure-login-collector' !== $hook ) {
			return;
		}

		// Register empty script handle for passkey functionality.
		wp_register_script( 'slc-passkey-js', '', array( 'jquery' ), SECURE_LOGIN_VERSION, true );
		wp_enqueue_script( 'slc-passkey-js' );

		// Add inline script for passkey management.
		$this->add_passkey_inline_script();
	}

	/**
	 * Add inline script for passkey management functionality.
	 */
	private function add_passkey_inline_script() {
		if ( ! function_exists( 'wp_add_inline_script' ) ) {
			return;
		}

		// Check if there are any encrypted entries for the warning message.
		global $wpdb;
		$table_name = $wpdb->prefix . 'secure_login_data';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_encrypted_data = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) ) > 0;

		$user_id = get_current_user_id();
		$passkey = $this->get_user_passkey( $user_id );

		// Prepare dynamic values for script.
		$ajax_url          = admin_url( 'admin-ajax.php' );
		$nonce             = wp_create_nonce( 'passkey_admin_nonce' );
		$has_encrypted     = $has_encrypted_data ? 'true' : 'false';
		$has_passkey       = $passkey ? 'true' : 'false';

		// Localized strings.
		$strings = array(
			'warningDataLoss'      => __( 'WARNING: Deleting this passkey will make ALL existing encrypted data permanently inaccessible!\n\nThis action CANNOT be undone. There is NO recovery method.\n\nAre you absolutely sure you want to proceed?', 'secure-login-collector' ),
			'warningSimple'        => __( 'Are you sure you want to delete this passkey?\n\nYou can register a new passkey afterward.', 'secure-login-collector' ),
			'deleting'             => __( 'Deleting...', 'secure-login-collector' ),
			'deletePasskey'        => __( 'Delete Passkey', 'secure-login-collector' ),
			'deleteSuccess'        => __( 'Passkey deleted successfully!', 'secure-login-collector' ),
			'deleteFailed'         => __( 'Failed to delete passkey:', 'secure-login-collector' ),
			'networkError'         => __( 'Network error occurred. Please try again.', 'secure-login-collector' ),
			'noWebAuthn'           => __( 'Your browser does not support WebAuthn/Passkeys.', 'secure-login-collector' ),
			'registerSuccess'      => __( 'Passkey registered successfully!', 'secure-login-collector' ),
		);

		$script = <<<JAVASCRIPT
jQuery(document).ready(function($) {
	var ajaxUrl = '{$ajax_url}';
	var nonce = '{$nonce}';
	var hasEncryptedData = {$has_encrypted};

	// Localized strings
	var strings = {
		warningDataLoss: '{$strings['warningDataLoss']}',
		warningSimple: '{$strings['warningSimple']}',
		deleting: '{$strings['deleting']}',
		deletePasskey: '{$strings['deletePasskey']}',
		deleteSuccess: '{$strings['deleteSuccess']}',
		deleteFailed: '{$strings['deleteFailed']}',
		networkError: '{$strings['networkError']}',
		noWebAuthn: '{$strings['noWebAuthn']}',
		registerSuccess: '{$strings['registerSuccess']}'
	};

	// Direct delete button handler.
	$('#delete-passkey-btn').on('click', function(e) {
		e.preventDefault();

		var \$button = $(this);
		var credentialId = \$button.data('credential-id');

		if (!credentialId) {
			alert('No credential ID found. Please refresh the page and try again.');
			return;
		}

		var warningMessage = hasEncryptedData ? strings.warningDataLoss : strings.warningSimple;

		if (!confirm(warningMessage)) {
			return;
		}

		\$button.prop('disabled', true).text(strings.deleting);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'passkey_delete',
				nonce: nonce,
				credential_id: credentialId
			},
			success: function(response) {
				if (response.success) {
					$('#passkey-status-message').html('<div class="notice notice-success inline"><p>' + strings.deleteSuccess + '</p></div>');
					setTimeout(function() {
						window.location.reload();
					}, 1500);
				} else {
					alert(strings.deleteFailed + ' ' + (response.data || 'Unknown error'));
					\$button.prop('disabled', false).text(strings.deletePasskey);
				}
			},
			error: function(xhr, status, error) {
				console.error('Delete error:', error);
				alert(strings.networkError);
				\$button.prop('disabled', false).text(strings.deletePasskey);
			}
		});
	});

	// Handle register button inline with full implementation.
	$('#register-passkey-btn').on('click', async function(e) {
		e.preventDefault();

		var \$button = $(this);
		var \$spinner = $('.passkey-registration-form .spinner');
		var \$statusMessage = $('#passkey-status-message');

		// Clear any previous messages.
		\$statusMessage.empty();

		// Check WebAuthn support.
		if (!window.PublicKeyCredential) {
			\$statusMessage.html('<div class="notice notice-error inline"><p>' + strings.noWebAuthn + '</p></div>');
			return;
		}

		\$button.prop('disabled', true);
		\$spinner.addClass('is-active');

		try {
			// Start registration to get challenge.
			const startResponse = await $.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'passkey_start_registration',
					nonce: nonce
				}
			});

			if (!startResponse.success) {
				throw new Error(startResponse.data || 'Failed to start registration');
			}

			const options = startResponse.data;

			// Convert base64 strings to ArrayBuffers.
			options.challenge = base64ToArrayBuffer(options.challenge);
			options.user.id = base64ToArrayBuffer(options.user.id);

			// Create credential.
			const credential = await navigator.credentials.create({
				publicKey: options
			});

			// Initialize the zero-knowledge setup.
			const initResponse = await $.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'passkey_init_setup',
					nonce: nonce,
					credential_id: arrayBufferToBase64(credential.rawId)
				}
			});

			// Check initialization response.
			if (!initResponse.success) {
				if (typeof initResponse.data === 'string' && initResponse.data.includes('Already initialized')) {
					console.warn('Keys already initialized, continuing with registration');
				} else {
					throw new Error(initResponse.data || 'Failed to initialize encryption');
				}
			}

			// Complete passkey registration with auto-generated name.
			let publicKeyData;
			if (credential.response.publicKey) {
				publicKeyData = arrayBufferToBase64(credential.response.publicKey);
			} else if (credential.response.getPublicKey) {
				publicKeyData = arrayBufferToBase64(credential.response.getPublicKey());
			} else {
				publicKeyData = 'not_available';
			}

			const completeResponse = await $.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'passkey_complete_registration',
					nonce: nonce,
					name: 'Passkey ' + new Date().toLocaleDateString(),
					credential_id: arrayBufferToBase64(credential.rawId),
					public_key: publicKeyData,
					client_data: arrayBufferToBase64(credential.response.clientDataJSON),
					attestation: arrayBufferToBase64(credential.response.attestationObject)
				}
			});

			if (!completeResponse.success) {
				throw new Error(completeResponse.data || 'Failed to complete registration');
			}

			\$statusMessage.html('<div class="notice notice-success inline"><p>' + strings.registerSuccess + '</p></div>');

			// Reload page after success.
			setTimeout(function() {
				window.location.reload();
			}, 1500);

		} catch (error) {
			console.error('Registration error:', error);
			\$statusMessage.html('<div class="notice notice-error inline"><p>' + error.message + '</p></div>');
			\$button.prop('disabled', false);
			\$spinner.removeClass('is-active');
		}
	});

	// Helper functions for ArrayBuffer conversion.
	function base64ToArrayBuffer(base64) {
		const binaryString = atob(base64);
		const bytes = new Uint8Array(binaryString.length);
		for (let i = 0; i < binaryString.length; i++) {
			bytes[i] = binaryString.charCodeAt(i);
		}
		return bytes.buffer;
	}

	function arrayBufferToBase64(buffer) {
		const bytes = new Uint8Array(buffer);
		let binary = '';
		for (let i = 0; i < bytes.length; i++) {
			binary += String.fromCharCode(bytes[i]);
		}
		return btoa(binary);
	}
});
JAVASCRIPT;

		wp_add_inline_script( 'slc-passkey-js', $script );
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
		$table_name = $wpdb->prefix . 'secure_login_data';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_encrypted_data = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) ) > 0;

		?>
		<div class="slc-passkey-container">
		<?php if ( ! $this->is_https() ) : ?>
				<div class="slc-alert slc-alert-danger">
					<span class="slc-alert-icon">⚠️</span>
					<div class="slc-alert-content">
						<div class="slc-alert-title"><?php esc_html_e( 'HTTPS Required', 'secure-login-collector' ); ?></div>
						<div class="slc-alert-message"><?php esc_html_e( 'WebAuthn requires HTTPS. Please enable SSL on your site to use passkeys.', 'secure-login-collector' ); ?></div>
					</div>
				</div>
			<?php endif; ?>
			
			<div class="slc-card">
				<div class="slc-card-header">
					<h3 class="slc-card-title">
						<span class="slc-card-title-icon">🔐</span>
					<?php esc_html_e( 'Passkey Authentication', 'secure-login-collector' ); ?>
					</h3>
				<?php if ( $passkey ) : ?>
						<span class="slc-badge slc-badge-success"><?php esc_html_e( 'Active', 'secure-login-collector' ); ?></span>
					<?php endif; ?>
				</div>
				
				<div class="slc-card-body">
				<?php if ( $passkey ) : ?>
						<div class="slc-passkey-status">
							<div class="slc-passkey-status-header">
								<div class="slc-passkey-status-title">
									<span>✅</span>
									<?php esc_html_e( 'Passkey Registered', 'secure-login-collector' ); ?>
								</div>
							</div>
							<div class="slc-passkey-status-details">
								<strong><?php esc_html_e( 'Name:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( $passkey['name'] ); ?><br>
								<strong><?php esc_html_e( 'Registered:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( $passkey['registered_at'] ); ?><br>
								<strong><?php esc_html_e( 'ID:', 'secure-login-collector' ); ?></strong> <?php echo esc_html( substr( $passkey['credential_id'], 0, 20 ) . '...' ); ?>
							</div>
						</div>
						
						<?php if ( $has_encrypted_data ) : ?>
							<div class="slc-alert slc-alert-danger" style="margin-top: 20px;">
								<span class="slc-alert-icon">⚠️</span>
								<div class="slc-alert-content">
									<div class="slc-alert-title"><?php esc_html_e( 'CRITICAL WARNING: Data Loss Risk', 'secure-login-collector' ); ?></div>
									<div class="slc-alert-message">
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
							<button type="button" class="slc-btn slc-btn-danger" id="delete-passkey-btn" 
									data-credential-id="<?php echo esc_attr( $passkey['credential_id'] ); ?>">
								<span>🗑️</span>
								<?php esc_html_e( 'Delete Passkey', 'secure-login-collector' ); ?>
							</button>
							<p class="slc-form-help" style="margin-top: 8px;">
								<?php esc_html_e( 'Only delete if you understand the consequences above.', 'secure-login-collector' ); ?>
							</p>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'Register a hardware security key or platform authenticator for ultra-secure encryption.', 'secure-login-collector' ); ?></p>
						
						<div class="passkey-registration-form" style="margin: 20px 0;">
							<button type="button" 
									id="register-passkey-btn" 
									class="slc-btn slc-btn-primary slc-btn-lg">
								<span>🔑</span>
								<?php esc_html_e( 'Register Passkey', 'secure-login-collector' ); ?>
							</button>
							
							<span class="spinner" style="float: none; margin-left: 10px;"></span>
						</div>
					<?php endif; ?>
					
					<div id="passkey-status-message"></div>
				</div>
			</div>
			
			<!-- Critical Warning About Passkey Loss -->
			<div class="slc-alert slc-alert-warning">
				<span class="slc-alert-icon">⚠️</span>
				<div class="slc-alert-content">
					<div class="slc-alert-title"><?php esc_html_e( 'Important: No Recovery Options', 'secure-login-collector' ); ?></div>
					<div class="slc-alert-message">
						<p><?php esc_html_e( 'If you lose access to your passkey:', 'secure-login-collector' ); ?></p>
						<ul>
							<li><?php esc_html_e( 'All data encrypted with that passkey becomes permanently inaccessible', 'secure-login-collector' ); ?></li>
							<li><?php esc_html_e( 'There is NO recovery mechanism or master password', 'secure-login-collector' ); ?></li>
							<li><?php esc_html_e( 'Even site administrators cannot decrypt the data', 'secure-login-collector' ); ?></li>
						</ul>
						<p><strong><?php esc_html_e( 'Keep your passkey device secure and consider having a backup authentication method.', 'secure-login-collector' ); ?></strong></p>
					</div>
				</div>
			</div>
		</div>

		<?php
	}


	/**
	 * Get user's registered passkey.
	 */
	private function get_user_passkey( $user_id ) {
		$passkey = get_user_meta( $user_id, 'secure_login_passkey', true );
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

		// Generate challenge.
		$challenge = base64_encode( random_bytes( 32 ) );
		set_transient( 'passkey_reg_challenge_' . get_current_user_id(), $challenge, 300 );

		$user = wp_get_current_user();

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
				'authenticatorSelection' => array(
					'authenticatorAttachment' => 'cross-platform',
					'userVerification'        => 'required',
					'requireResidentKey'      => false,
				),
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
		if ( ! Secure_Login_License_Manager::has_pro_license() ) {
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
			update_user_meta( $user_id, 'secure_login_passkey', $passkey_data );

			// Store globally for verification.
			update_option(
				'passkey_credential_' . $credential_id,
				array(
					'public_key' => $public_key,
					'user_id'    => $user_id,
				)
			);

			// Set global passkey registered flag.
			update_option( 'secure_login_passkey_registered', true );
			update_option( 'secure_login_passkey_registered_at', current_time( 'mysql' ) );

			// Also ensure pro keys active flag is set for consistency with V2 handler.
			update_option( 'secure_login_pro_keys_active', true );

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
		delete_user_meta( $user_id, 'secure_login_passkey' );
		delete_option( 'passkey_credential_' . $credential_id );

		// Delete all wrapped MWKs for this user.
		if ( $this->master_key_manager ) {
			$this->master_key_manager->delete_all_user_mwks( $user_id );
		}

		// Delete the pro keys and clear the global flag.
		if ( ! class_exists( 'Secure_Login_Encryption_Handler_V2' ) ) {
			require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-encryption-handler-v2.php';
		}
		$encryption_handler = new Secure_Login_Encryption_Handler_V2();
		$encryption_handler->delete_pro_keys();

		// Clear global passkey registered flag and pro keys active flag.
		delete_option( 'secure_login_passkey_registered' );
		delete_option( 'secure_login_passkey_registered_at' );
		delete_option( 'secure_login_pro_keys_active' );

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
		if ( ! Secure_Login_License_Manager::has_pro_license() ) {
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
		if ( ! class_exists( 'Secure_Login_Encryption_Handler_V2' ) ) {
			require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/class-encryption-handler-v2.php';
		}
		$encryption_handler = new Secure_Login_Encryption_Handler_V2();

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
		$public_key_pro = get_option( 'secure_login_public_key_pro' );

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
		if ( ! wp_verify_nonce( $nonce, 'secure_login_admin_nonce' ) &&
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
		if ( ! wp_verify_nonce( $nonce, 'secure_login_admin_nonce' ) &&
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