<?php
/**
 * Master Password Setup Wizard
 *
 * Handles initial master password setup for FREE tier users.
 *
 * @package SecureLoginCollector
 * @since 2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Master Password Wizard Class
 *
 * Provides wizard interface for setting up master password.
 */
class Seculoco_Master_Password_Wizard {

	/**
	 * Minimum password length (advisory/recommended, not enforced).
	 * Password validation accepts "fair" strength or better (score >= 40).
	 */
	const MIN_PASSWORD_LENGTH = 12;

	/**
	 * Maximum setup attempts per hour.
	 */
	const MAX_ATTEMPTS_PER_HOUR = 5;

	/**
	 * Rate limit transient prefix.
	 */
	const RATE_LIMIT_PREFIX = 'seculoco_setup_attempts_';

	/**
	 * Constructor - register hooks.
	 */
	public function __construct() {
		// Admin notices for setup requirement.
		add_action( 'admin_notices', array( $this, 'show_setup_notice' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_seculoco_setup_master_password', array( $this, 'ajax_setup_master_password' ) );
		add_action( 'wp_ajax_seculoco_check_setup_status', array( $this, 'ajax_check_setup_status' ) );
		add_action( 'wp_ajax_seculoco_dismiss_setup_notice', array( $this, 'ajax_dismiss_setup_notice' ) );
		add_action( 'wp_ajax_seculoco_reset_master_password', array( $this, 'ajax_reset_master_password' ) );

		// Enqueue wizard scripts on admin pages.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_wizard_scripts' ) );
	}

	/**
	 * Show admin notice for setup requirement.
	 */
	public function show_setup_notice() {
		// Only show to admins.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show on plugin pages.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'secure-login-collector' ) ) {
			return;
		}

		// Check if setup is needed.
		if ( seculoco_is_encryption_initialized() ) {
			return;
		}

		// Check if notice has been dismissed.
		$user_id = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'seculoco_setup_notice_dismissed', true );

		// Show notice every 7 days even if dismissed (persistent reminder).
		if ( $dismissed && ( time() - (int) $dismissed ) < ( 7 * DAY_IN_SECONDS ) ) {
			return;
		}

		?>
		<div class="notice notice-error seculoco-setup-notice is-dismissible" data-dismiss-key="seculoco_setup_notice_dismissed">
			<p>
				<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
				<?php
				echo wp_kses_post(
					__( '<strong>Encryption Setup Required:</strong> Secure Login Collector cannot function without encryption. Please set up your master password to begin collecting login credentials securely.', 'secure-login-collector' )
				);
				?>
			</p>
			<p>
				<button type="button" class="seculoco-btn seculoco-btn-primary seculoco-btn-lg seculoco-launch-wizard" data-wizard-type="setup">
					<span>🔒</span>
					<?php echo esc_html__( 'Start Master Password Wizard', 'secure-login-collector' ); ?>
				</button>
			</p>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('.seculoco-setup-notice').on('click', '.notice-dismiss', function() {
				$.post(ajaxurl, {
					action: 'seculoco_dismiss_setup_notice',
					nonce: '<?php echo esc_js( wp_create_nonce( 'seculoco_dismiss_notice' ) ); ?>'
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Enqueue wizard scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_wizard_scripts( $hook ) {
		// Only load on plugin pages.
		if ( false === strpos( $hook, 'secure-login-collector' ) ) {
			return;
		}

		// Check if setup is needed.
		$encryption_version = get_option( SECULOCO_OPTION_ENCRYPTION_VERSION, '' );
		$has_private_key    = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED, false );

		// Only load if setup needed.

			// Enqueue wizard CSS.
			wp_enqueue_style(
				'seculoco-wizard-css',
				plugin_dir_url( __FILE__ ) . '../assets/css/admin-modern.css',
				array(),
				filemtime( plugin_dir_path( __FILE__ ) . '../assets/css/admin-modern.css' )
			);

			// Enqueue merged admin JavaScript (if not already enqueued).
			if ( ! wp_script_is( 'seculoco-admin-js', 'enqueued' ) ) {
				wp_enqueue_script(
					'seculoco-admin-js',
					plugin_dir_url( __FILE__ ) . '../assets/js/admin.js',
					array( 'jquery' ),
					filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/admin.js' ),
					true
				);
			}

			// Localize script with wizard data.
			wp_localize_script(
				'seculoco-admin-js',
				'seculocoWizard',
				array(
					'ajaxurl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'seculoco_wizard_nonce' ),
					'minPasswordLength' => self::MIN_PASSWORD_LENGTH,
				'strings'           => array(
					// Modal navigation strings.
					'stepIndicatorText'   => __( 'Step', 'secure-login-collector' ),
					'stepIndicatorOf'     => __( 'of', 'secure-login-collector' ),
					'cancelButton'        => __( 'Cancel', 'secure-login-collector' ),
					'backButton'          => __( 'Back', 'secure-login-collector' ),
					'nextButton'          => __( 'Next', 'secure-login-collector' ),
					'completeButton'      => __( 'Complete Setup', 'secure-login-collector' ),
					'doneButton'          => __( 'Done', 'secure-login-collector' ),

					// Step 1: Welcome screen.
					'step1Icon'           => __( '🔐', 'secure-login-collector' ),
					'step1Title'          => __( 'Master Password Setup', 'secure-login-collector' ),
					'step1Intro'          => __( 'Welcome! Let\'s secure your login credentials with end-to-end encryption.', 'secure-login-collector' ),
					'step1Info1'          => __( 'Your master password encrypts all stored login data', 'secure-login-collector' ),
					'step1Info2'          => __( 'Only you will know this password - it\'s never sent to the server', 'secure-login-collector' ),
					'step1Info3'          => __( 'This ensures complete privacy and security for your sensitive data', 'secure-login-collector' ),
					'step1Warning'        => __( '<strong>Important:</strong> If you forget your master password, your encrypted data cannot be recovered. There is no password reset option.', 'secure-login-collector' ),

					// Step 2: Create password.
					'step2Icon'           => __( '🔑', 'secure-login-collector' ),
					'step2Title'          => __( 'Create Your Master Password', 'secure-login-collector' ),
					'step2Label'          => __( 'Master Password', 'secure-login-collector' ),
					'step2Placeholder'    => __( 'Enter a strong password (12+ characters)', 'secure-login-collector' ),
					'step2StrengthLabel'  => __( 'Password Strength:', 'secure-login-collector' ),
					'step2StrengthVeryWeak' => __( 'Very Weak', 'secure-login-collector' ),
					'step2StrengthWeak'   => __( 'Weak', 'secure-login-collector' ),
					'step2StrengthFair'   => __( 'Fair', 'secure-login-collector' ),
					'step2StrengthGood'   => __( 'Good', 'secure-login-collector' ),
					'step2StrengthStrong' => __( 'Strong', 'secure-login-collector' ),
					'step2Requirements'   => __( 'Password Requirements:', 'secure-login-collector' ),
					'step2Req1'           => __( 'At least 12 characters (recommended)', 'secure-login-collector' ),
					'step2Req2'           => __( 'Mix of uppercase and lowercase letters', 'secure-login-collector' ),
					'step2Req3'           => __( 'Include numbers and special characters', 'secure-login-collector' ),
					'step2Req4'           => __( 'Avoid common words or patterns', 'secure-login-collector' ),

					// Step 3: Confirm password.
					'step3Icon'           => __( '✓', 'secure-login-collector' ),
					'step3Title'          => __( 'Confirm Your Master Password', 'secure-login-collector' ),
					'step3Label'          => __( 'Re-enter Master Password', 'secure-login-collector' ),
					'step3Placeholder'    => __( 'Enter your password again', 'secure-login-collector' ),
					'step3MatchIcon'      => __( '✓', 'secure-login-collector' ),
					'step3MatchText'      => __( 'Passwords match!', 'secure-login-collector' ),
					'step3NoMatchIcon'    => __( '✗', 'secure-login-collector' ),
					'step3NoMatchText'    => __( 'Passwords do not match', 'secure-login-collector' ),

					// Step 4: Warning acknowledgment.
					'step4Icon'           => __( '⚠️', 'secure-login-collector' ),
					'step4Title'          => __( 'Important Security Notice', 'secure-login-collector' ),
					'step4Warning'        => __( '<strong>Critical Warning:</strong> Your master password cannot be recovered if lost. There is no "forgot password" option.', 'secure-login-collector' ),
					'step4BestPractices'  => __( 'Best Practices:', 'secure-login-collector' ),
					'step4Practice1'      => __( 'Write down your password and store it in a secure location', 'secure-login-collector' ),
					'step4Practice2'      => __( 'Consider using a password manager', 'secure-login-collector' ),
					'step4Practice3'      => __( 'Never share your master password with anyone', 'secure-login-collector' ),
					'step4Practice4'      => __( 'Make sure you can remember it or have it securely documented', 'secure-login-collector' ),
					'step4AckCheckbox'    => __( 'I understand that losing my master password means permanent loss of access to my encrypted data', 'secure-login-collector' ),

					// Step 5: Final confirmation.
					'step5Icon'           => __( '🛡️', 'secure-login-collector' ),
					'step5Title'          => __( 'Ready to Set Up Encryption', 'secure-login-collector' ),
					'step5Intro'          => __( 'When you click "Complete Setup", the following will happen:', 'secure-login-collector' ),
					'step5Process1'       => __( 'A unique encryption key will be derived from your master password', 'secure-login-collector' ),
					'step5Process2'       => __( 'RSA-4096 keypair will be generated for maximum security', 'secure-login-collector' ),
					'step5Process3'       => __( 'Your private key will be encrypted with your master password', 'secure-login-collector' ),
					'step5Process4'       => __( 'Configuration will be securely saved to your database', 'secure-login-collector' ),
					'step5Reminder'       => __( '<strong>Final Reminder:</strong> Make absolutely certain you have your master password written down or memorized. You will need it every time you access your encrypted login data.', 'secure-login-collector' ),

					// Success screen.
					'successIcon'         => __( '✓', 'secure-login-collector' ),
					'successTitle'        => __( 'Setup Complete!', 'secure-login-collector' ),
					'successMessage'      => __( 'Your master password has been set up successfully. All login credentials will now be encrypted with end-to-end encryption.', 'secure-login-collector' ),
					'successRedirect'     => __( 'Redirecting to dashboard...', 'secure-login-collector' ),

					// Validation messages.
					'setupRequired'       => __( 'Master Password Setup Required', 'secure-login-collector' ),
					'passwordTooShort'    => sprintf(
						/* translators: %d: minimum password length */
						__( 'Password must be at least %d characters long.', 'secure-login-collector' ),
						self::MIN_PASSWORD_LENGTH
					),
					'passwordsDoNotMatch' => __( 'Passwords do not match.', 'secure-login-collector' ),
					'passwordTooWeak'     => __( 'Password is too weak. Please use a stronger password.', 'secure-login-collector' ),
					'mustAcceptWarning'   => __( 'You must acknowledge the warning about password loss.', 'secure-login-collector' ),

					// Progress messages.
					'setupInProgress'     => __( 'Setting up master password...', 'secure-login-collector' ),
					'deriving_key'        => __( 'Deriving encryption key...', 'secure-login-collector' ),
					'generating_rsa'      => __( 'Generating RSA keypair...', 'secure-login-collector' ),
					'wrapping_key'        => __( 'Securing encryption keys...', 'secure-login-collector' ),
					'saving_to_server'    => __( 'Saving configuration...', 'secure-login-collector' ),
					'verifying_setup'     => __( 'Verifying setup...', 'secure-login-collector' ),

					// Status messages.
					'setupComplete'       => __( 'Master password setup complete!', 'secure-login-collector' ),
					'setupFailed'         => __( 'Setup failed: ', 'secure-login-collector' ),
					'rateLimitExceeded'   => __( 'Too many attempts. Please try again later.', 'secure-login-collector' ),
					'networkError'        => __( 'Network error occurred. Please try again.', 'secure-login-collector' ),
				),
				)
			);

	}

	/**
	 * AJAX: Setup master password.
	 */
	public function ajax_setup_master_password() {
		// Verify nonce.
		check_ajax_referer( 'seculoco_wizard_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'secure-login-collector' ) ),
				403
			);
		}

		// Rate limiting.
		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many setup attempts. Please try again in an hour.', 'secure-login-collector' ) ),
				429
			);
		}

		// Get and validate inputs.
		$wrapped_private_key = isset( $_POST['wrapped_private_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wrapped_private_key'] ) ) : '';
		$public_key_jwk      = isset( $_POST['public_key_jwk'] ) ? sanitize_text_field( wp_unslash( $_POST['public_key_jwk'] ) ) : '';
		$master_password_salt = isset( $_POST['master_password_salt'] ) ? sanitize_text_field( wp_unslash( $_POST['master_password_salt'] ) ) : '';
		$key_wrapping_iv     = isset( $_POST['key_wrapping_iv'] ) ? sanitize_text_field( wp_unslash( $_POST['key_wrapping_iv'] ) ) : '';

		// Validate required fields.
		if ( empty( $wrapped_private_key ) || empty( $public_key_jwk ) || empty( $master_password_salt ) || empty( $key_wrapping_iv ) ) {
			$this->increment_rate_limit();
			wp_send_json_error(
				array( 'message' => __( 'Missing required encryption data.', 'secure-login-collector' ) ),
				400
			);
		}

		// Save encryption configuration.
		$save_result = $this->save_encryption_config(
			array(
				'wrapped_private_key'  => $wrapped_private_key,
				'public_key_jwk'       => $public_key_jwk,
				'master_password_salt' => $master_password_salt,
				'key_wrapping_iv'      => $key_wrapping_iv,
			)
		);

		if ( ! $save_result ) {
			$this->increment_rate_limit();
			wp_send_json_error(
				array( 'message' => __( 'Failed to save encryption configuration.', 'secure-login-collector' ) ),
				500
			);
		}

		// Clear rate limit on success.
		$this->clear_rate_limit();

		// Send success response.
		wp_send_json_success(
			array(
				'message' => __( 'Master password setup completed successfully.', 'secure-login-collector' ),
				'redirect' => admin_url( 'admin.php?page=secure-login-collector-settings' ),
			)
		);
	}

	/**
	 * AJAX: Check setup status.
	 */
	public function ajax_check_setup_status() {
		// Verify nonce.
		check_ajax_referer( 'seculoco_wizard_nonce', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'secure-login-collector' ) ),
				403
			);
		}

		$encryption_version = get_option( SECULOCO_OPTION_ENCRYPTION_VERSION, '' );
		$has_private_key    = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED, false );

		wp_send_json_success(
			array(
				'setupComplete'     => 'v2' === $encryption_version && $has_private_key,
				'encryptionVersion' => $encryption_version,
				'hasPrivateKey'     => (bool) $has_private_key,
			)
		);
	}

	/**
	 * Save encryption configuration to WordPress options.
	 *
	 * @param array $config Encryption configuration data.
	 * @return bool True on success, false on failure.
	 */
	private function save_encryption_config( $config ) {
		// Validate config.
		if ( empty( $config['wrapped_private_key'] ) || empty( $config['public_key_jwk'] ) ||
			 empty( $config['master_password_salt'] ) || empty( $config['key_wrapping_iv'] ) ) {
			return false;
		}

		// Save to wp_options.
		$result = update_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED, $config['wrapped_private_key'] );
		$result = update_option( SECULOCO_OPTION_PUBLIC_KEY, $config['public_key_jwk'] ) && $result;
		$result = update_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT, $config['master_password_salt'] ) && $result;
		//$result = update_option( SECULOCO_OPTION_KEY_WRAPPING_IV_FREE, $config['key_wrapping_iv'] ) && $result;

		// Set encryption version.
		$result = update_option( SECULOCO_OPTION_ENCRYPTION_VERSION, 'v2' ) && $result;

		// Set setup timestamp.
		$result = update_option( SECULOCO_OPTION_SETUP_TIMESTAMP, current_time( 'mysql' ) ) && $result;

		return $result;
	}

	/**
	 * Check rate limit for setup attempts.
	 *
	 * @return bool True if within rate limit, false if exceeded.
	 */
	private function check_rate_limit() {
		$user_id = get_current_user_id();
		$transient_key = self::RATE_LIMIT_PREFIX . $user_id;
		$attempts = get_transient( $transient_key );

		if ( false === $attempts ) {
			return true;
		}

		return (int) $attempts < self::MAX_ATTEMPTS_PER_HOUR;
	}

	/**
	 * Increment rate limit counter.
	 */
	private function increment_rate_limit() {
		$user_id = get_current_user_id();
		$transient_key = self::RATE_LIMIT_PREFIX . $user_id;
		$attempts = get_transient( $transient_key );

		if ( false === $attempts ) {
			$attempts = 0;
		}

		set_transient( $transient_key, (int) $attempts + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Clear rate limit counter.
	 */
	private function clear_rate_limit() {
		$user_id = get_current_user_id();
		$transient_key = self::RATE_LIMIT_PREFIX . $user_id;
		delete_transient( $transient_key );
	}

	/**
	 * AJAX: Dismiss setup notice.
	 */
	public function ajax_dismiss_setup_notice() {
		// Verify nonce.
		check_ajax_referer( 'seculoco_dismiss_notice', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'secure-login-collector' ) ),
				403
			);
		}

		// Set dismissal timestamp in user meta.
		$user_id = get_current_user_id();
		update_user_meta( $user_id, 'seculoco_setup_notice_dismissed', time() );

		wp_send_json_success(
			array( 'message' => __( 'Notice dismissed.', 'secure-login-collector' ) )
		);
	}

	/**
	 * AJAX: Reset master password by deleting all encryption keys.
	 *
	 * SECURITY WARNING: This operation is irreversible and will mark all
	 * encrypted login data as permanently undecryptable.
	 */
	public function ajax_reset_master_password() {
		// Verify nonce.
		check_ajax_referer( 'seculoco_wizard_nonce', 'nonce' );

		// Check permissions - only administrators can reset master password.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions. Only administrators can reset the master password.', 'secure-login-collector' ) ),
				403
			);
		}

		// Verify encryption is actually initialized.
		$has_private_key = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED, false );
		if ( ! $has_private_key ) {
			wp_send_json_error(
				array( 'message' => __( 'No master password is currently set.', 'secure-login-collector' ) ),
				400
			);
		}

		// CRITICAL: Mark all encrypted data as undecryptable FIRST.
		// This must happen before deleting keys to prevent data corruption.
		global $wpdb;
		$table_name = $wpdb->prefix . 'seculoco_data';

		// Load database manager if not already loaded.
		if ( ! class_exists( 'Seculoco_Database_Manager' ) ) {
			require_once SECULOCO_PLUGIN_DIR . 'includes/class-database-manager.php';
		}

		try {
			// Mark all FREE-tier encrypted login data as undecryptable.
			// This affects RSA-encrypted data that requires the master password for decryption.
			// After master password reset, the decryption keys are lost and data becomes permanently undecryptable.
			$db_manager     = new Seculoco_Database_Manager( $table_name );
			$affected_count = $db_manager->mark_login_data_as_undecryptable( 'free' );

			// Delete ALL encryption-related options (FREE tier).
			$deleted_options = array();

			// Core encryption keys.
			if ( delete_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED ) ) {
				$deleted_options[] = 'wrapped_private_key';
			}
			if ( delete_option( SECULOCO_OPTION_PUBLIC_KEY ) ) {
				$deleted_options[] = 'public_key';
			}
			if ( delete_option( SECULOCO_OPTION_MASTER_PASSWORD_SALT ) ) {
				$deleted_options[] = 'master_password_salt';
			}

			// Encryption metadata.
			if ( delete_option( SECULOCO_OPTION_ENCRYPTION_VERSION ) ) {
				$deleted_options[] = 'encryption_version';
			}
			if ( delete_option( SECULOCO_OPTION_SETUP_TIMESTAMP ) ) {
				$deleted_options[] = 'setup_timestamp';
			}

			// Additional FREE tier options (if they exist).
			delete_option( SECULOCO_OPTION_PUBLIC_KEY_JWK_FREE );
			delete_option( SECULOCO_OPTION_KEY_WRAPPING_IV_FREE );

			// PRO tier options (if they exist - for pro version compatibility).
			if ( defined( 'SECULOCO_OPTION_PUBLIC_KEY_PRO' ) ) {
				delete_option( SECULOCO_OPTION_PUBLIC_KEY_PRO );
			}
			if ( defined( 'SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO' ) ) {
				delete_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO );
			}
			if ( defined( 'SECULOCO_OPTION_PRO_KEYS_ACTIVE' ) ) {
				delete_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE );
			}

			// Passkey-related options (if they exist - for pro version compatibility).
			if ( defined( 'SECULOCO_OPTION_GLOBAL_PASSKEY' ) ) {
				delete_option( SECULOCO_OPTION_GLOBAL_PASSKEY );
			}
			if ( defined( 'SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID' ) ) {
				delete_option( SECULOCO_OPTION_PASSKEY_CREDENTIAL_ID );
			}
			if ( defined( 'SECULOCO_OPTION_PASSKEY_REGISTERED' ) ) {
				delete_option( SECULOCO_OPTION_PASSKEY_REGISTERED );
			}
			if ( defined( 'SECULOCO_OPTION_PASSKEY_REGISTERED_AT' ) ) {
				delete_option( SECULOCO_OPTION_PASSKEY_REGISTERED_AT );
			}
			if ( defined( 'SECULOCO_OPTION_PASSKEY_AAGUID_HASH' ) ) {
				delete_option( SECULOCO_OPTION_PASSKEY_AAGUID_HASH );
			}

			// Clear rate limit on successful reset.
			$this->clear_rate_limit();

			// Build success message with detailed information.
			$message = __( 'Master password has been reset successfully. All encryption keys have been deleted.', 'secure-login-collector' );

			if ( $affected_count > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d is the number of login entries marked as undecryptable */
					_n(
						'%d login entry has been marked as permanently undecryptable.',
						'%d login entries have been marked as permanently undecryptable.',
						$affected_count,
						'secure-login-collector'
					),
					$affected_count
				);
			}

			// Send success response with detailed data.
			wp_send_json_success(
				array(
					'message'             => $message,
					'affected_count'      => $affected_count,
					'deleted_options'     => $deleted_options,
					'encryption_reset'    => true,
					'requires_new_setup'  => true,
					'redirect'            => admin_url( 'admin.php?page=secure-login-collector-settings' ),
				)
			);

		} catch ( Exception $e ) {
			// Log error for debugging.
			error_log( 'Secure Login Collector: Master password reset failed - ' . $e->getMessage() );

			// Return user-friendly error.
			wp_send_json_error(
				array(
					'message' => __( 'Failed to reset master password. Please contact support if this problem persists.', 'secure-login-collector' ),
					'error'   => $e->getMessage(),
				),
				500
			);
		}
	}
}
