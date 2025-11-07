<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Settings Manager Class
 *
 * Handles plugin settings and configuration.
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Settings_Manager
 *
 * Handles all plugin settings and configuration.
 */
class Seculoco_Settings_Manager {

	/**
	 * Encryption handler instance.
	 *
	 * @var Seculoco_Encryption_Service
	 */
	private $encryption_handler;

	/**
	 * Constructor - initializes settings manager.
	 *
	 * @param Seculoco_Encryption_Service $encryption_handler Encryption handler instance.
	 */
	public function __construct( $encryption_handler ) {
		$this->encryption_handler = $encryption_handler;

		// Register hooks.
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Register AJAX handlers for password-based encryption.
		add_action( 'wp_ajax_seculoco_setup_password_encryption', array( $this, 'ajax_setup_password_encryption' ) );
		add_action( 'wp_ajax_seculoco_reset_password_encryption', array( $this, 'ajax_reset_password_encryption' ) );
		add_action( 'wp_ajax_seculoco_check_password_status', array( $this, 'ajax_check_password_status' ) );
	}

	/**
	 * Enqueue admin scripts for settings page.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Load CSS on all Secure Login Collector admin pages.
		// Check if we're on any page that starts with our plugin slug.
		if ( strpos( $hook, 'secure-login-collector' ) === false &&
			'toplevel_page_secure-login-collector' !== $hook ) {
			return;
		}

		// Enqueue modern admin CSS.
		wp_enqueue_style(
			'secure-login-admin-modern-css',
			plugin_dir_url( __FILE__ ) . '../assets/css/admin-modern.css',
			array(),
			'1.0.0'
		);

		// Enqueue jQuery for admin interactions.
		wp_enqueue_script( 'jquery' );

		// Enqueue password setup script on settings page.
		// Remove the hook check to always enqueue on admin pages - WordPress will handle script dependencies.
		wp_enqueue_script(
			'seculoco-password-setup',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin-password-setup.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		// Localize script with AJAX URL and nonce.
		wp_localize_script(
			'seculoco-password-setup',
			'secuLocoPasswordSetup',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'seculoco_password_setup_nonce' ),
				'i18n'    => array(
					'setupPassword'         => __( 'Setup Password', 'secure-login-collector' ),
					'resetPassword'         => __( 'Reset Password', 'secure-login-collector' ),
					'password'              => __( 'Password', 'secure-login-collector' ),
					'confirmPassword'       => __( 'Confirm Password', 'secure-login-collector' ),
					'setupButton'           => __( 'Setup Encryption', 'secure-login-collector' ),
					'resetButton'           => __( 'Reset Encryption', 'secure-login-collector' ),
					'cancel'                => __( 'Cancel', 'secure-login-collector' ),
					'passwordMismatch'      => __( 'Passwords do not match.', 'secure-login-collector' ),
					'passwordTooShort'      => __( 'Password must be at least 8 characters.', 'secure-login-collector' ),
					'setupSuccess'          => __( 'Password-based encryption setup successfully!', 'secure-login-collector' ),
					'resetSuccess'          => __( 'Password-based encryption reset successfully!', 'secure-login-collector' ),
					'setupFailed'           => __( 'Failed to setup password encryption.', 'secure-login-collector' ),
					'resetFailed'           => __( 'Failed to reset password encryption.', 'secure-login-collector' ),
					'resetWarningTitle'     => __( 'Warning: Data Loss', 'secure-login-collector' ),
					'resetWarningMessage'   => __( 'Resetting the password will mark all existing encrypted data as UNDECRYPTABLE. You will not be able to decrypt any previously stored data. Are you sure you want to continue?', 'secure-login-collector' ),
					'typeConfirmToReset'    => __( 'Type "RESET" to confirm:', 'secure-login-collector' ),
					'resetConfirmRequired'  => __( 'Please type "RESET" to confirm.', 'secure-login-collector' ),
					'passwordStrengthWeak'  => __( 'Weak password - consider using a stronger one', 'secure-login-collector' ),
					'passwordStrengthFair'  => __( 'Fair password', 'secure-login-collector' ),
					'passwordStrengthGood'  => __( 'Good password', 'secure-login-collector' ),
					'passwordStrengthStrong' => __( 'Strong password', 'secure-login-collector' ),
					'show'                  => __( 'Show', 'secure-login-collector' ),
					'hide'                  => __( 'Hide', 'secure-login-collector' ),
				),
			)
		);

		// Enqueue zxcvbn for password strength meter.
		wp_enqueue_script( 'zxcvbn-async' );

		wp_enqueue_script(
			'seculoco-key-management',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin-key-management.js',
			array( 'jquery' ),
			defined( 'SECULOCO_VERSION' ) ? SECULOCO_VERSION : '1.0.0',
			true
		);

		wp_localize_script(
			'seculoco-key-management',
			'seculocoKeyManager',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'seculoco_admin_nonce' ),
					'strings'   => array(
						'initializing'        => __( 'Initializing...', 'secure-login-collector' ),
						'initSuccess'         => __( 'Free RSA keys initialized successfully!', 'secure-login-collector' ),
						'initFailedPrefix'    => __( 'Failed to initialize keys: ', 'secure-login-collector' ),
						'networkError'        => __( 'Network error occurred.', 'secure-login-collector' ),
						'initButtonLabel'     => __( 'Initialize Free Keys Now', 'secure-login-collector' ),
						'exportFailedPrefix'  => __( 'Failed to export public key: ', 'secure-login-collector' ),
					),
					'exportFileName' => 'secure-login-free-public-key.pem',
					'defaultFrontendText' => $this->get_default_frontend_text(),
				)
			);
	}

	/**
	 * Add settings submenu.
	 */
	public function add_settings_menu() {
		// Add to the plugin's own menu.
		add_submenu_page(
			'secure-login-collector',
			__( 'Settings', 'secure-login-collector' ),
			__( 'Settings', 'secure-login-collector' ),
			'manage_options',
			'secure-login-collector-settings',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'seculoco_settings',
			'seculoco_notification_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			)
		);
		register_setting(
			'seculoco_settings',
			'seculoco_enable_notifications',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);
		register_setting(
			'seculoco_settings',
			'seculoco_expiration_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			'seculoco_settings',
			'seculoco_frontend_form_text',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_frontend_form_text' ),
			)
		);
		register_setting(
			'seculoco_settings',
			'seculoco_frontend_text_type',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			'seculoco_settings',
			'seculoco_delete_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);

		register_setting(
			'seculoco_settings',
			'seculoco_hide_service_footer',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);

		// Spam Protection settings.
		register_setting(
			'seculoco_settings',
			'seculoco_honeypot_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);

		add_settings_section(
			'seculoco_notification_section',
			__( 'Email Notifications', 'secure-login-collector' ),
			array( $this, 'notification_section_callback' ),
			'seculoco_settings'
		);

		add_settings_section(
			'seculoco_frontend_section',
			__( 'Frontend Customization', 'secure-login-collector' ),
			array( $this, 'frontend_section_callback' ),
			'seculoco_settings'
		);

		add_settings_section(
			'seculoco_expiration_section',
			__( 'Data Expiration', 'secure-login-collector' ),
			array( $this, 'expiration_section_callback' ),
			'seculoco_settings'
		);

		add_settings_section(
			'seculoco_spam_protection_section',
			__( 'Spam Protection Settings', 'secure-login-collector' ),
			array( $this, 'spam_protection_section_callback' ),
			'seculoco_settings'
		);

		// Allow pro version to register its settings.
		do_action( 'seculoco_register_settings' );

		// Add encryption settings section (for all users now).
		add_settings_section(
			'seculoco_encryption_section',
			__( 'Encryption Settings', 'secure-login-collector' ),
			array( $this, 'encryption_section_callback' ),
			'seculoco_settings'
		);

		// Add plugin management section.
		add_settings_section(
			'seculoco_plugin_management_section',
			__( 'Plugin Management', 'secure-login-collector' ),
			array( $this, 'plugin_management_section_callback' ),
			'seculoco_settings'
		);

		add_settings_field(
			'seculoco_delete_on_uninstall',
			__( 'Delete Data on Uninstall', 'secure-login-collector' ),
			array( $this, 'delete_on_uninstall_callback' ),
			'seculoco_settings',
			'seculoco_plugin_management_section'
		);

		add_settings_field(
			'seculoco_enable_notifications',
			__( 'Enable Email Notifications', 'secure-login-collector' ),
			array( $this, 'enable_notifications_callback' ),
			'seculoco_settings',
			'seculoco_notification_section'
		);

		add_settings_field(
			'seculoco_notification_email',
			__( 'Notification Email Address', 'secure-login-collector' ),
			array( $this, 'notification_email_callback' ),
			'seculoco_settings',
			'seculoco_notification_section'
		);

		add_settings_field(
			'seculoco_frontend_text_type',
			__( 'Text Type', 'secure-login-collector' ),
			array( $this, 'frontend_text_type_callback' ),
			'seculoco_settings',
			'seculoco_frontend_section'
		);

		add_settings_field(
			'seculoco_frontend_form_text',
			__( 'Custom Description Text', 'secure-login-collector' ),
			array( $this, 'frontend_form_text_callback' ),
			'seculoco_settings',
			'seculoco_frontend_section'
		);

		add_settings_field(
			'seculoco_hide_service_footer',
			__( 'Hide Branding Footer', 'secure-login-collector' ),
			array( $this, 'hide_service_footer_callback' ),
			'seculoco_settings',
			'seculoco_frontend_section'
		);

		add_settings_field(
			'seculoco_expiration_days',
			__( 'Auto-Delete After (Days)', 'secure-login-collector' ),
			array( $this, 'expiration_days_callback' ),
			'seculoco_settings',
			'seculoco_expiration_section'
		);

		add_settings_field(
			'seculoco_honeypot_enabled',
			__( 'Enable Honeypot Protection', 'secure-login-collector' ),
			array( $this, 'honeypot_enabled_callback' ),
			'seculoco_settings',
			'seculoco_spam_protection_section'
		);

		// Allow pro version to add additional spam protection settings fields.
		do_action( 'seculoco_spam_protection_settings_fields' );
	}

	/**
	 * Notification settings section callback.
	 */
	public function notification_section_callback() {
		echo '<div class="seculoco-card seculoco-card-margin-top">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">';
		echo esc_html__( 'Email Notifications', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Configure email notifications for new login data submissions.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Frontend settings section callback.
	 */
	public function frontend_section_callback() {
		echo '<div class="seculoco-card seculoco-card-margin-top">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">';
		echo esc_html__( 'Frontend Form Settings', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Customize the frontend form appearance and text.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Expiration settings section callback.
	 */
	public function expiration_section_callback() {
		echo '<div class="seculoco-card seculoco-card-margin-top">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">';
		echo esc_html__( 'Data Retention Settings', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Configure automatic deletion of old login data.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Spam protection settings section callback.
	 */
	public function spam_protection_section_callback() {
		echo '<div class="seculoco-card seculoco-card-margin-top">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">';
		echo esc_html__( 'Spam Protection Settings', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Configure honeypot protection to prevent spam submissions and automated bot attacks.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Encryption settings section callback.
	 */
	public function encryption_section_callback() {
		echo '<div class="seculoco-card seculoco-card-margin-top">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">' . esc_html__( 'Encryption Settings', 'secure-login-collector' ) . '</h3>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Manage RSA encryption keys for secure data transmission.', 'secure-login-collector' ) . '</p>';

		// Output all the encryption content inline.
		$this->display_encryption_content();
		// Don't close the card-body div here - handled by the custom renderer.
	}

	/**
	 * Display encryption content inside the card
	 */
	private function display_encryption_content() {
		// Get key status for free version.
		$free_public_key  = get_option( 'seculoco_public_key_free' );
		$free_private_key = get_option( 'seculoco_private_key_free_encrypted' );

		// Check if passkey encryption is active (pro keys).
		$pro_keys_active = get_option( 'seculoco_pro_keys_active', false );

		// If passkey encryption is active, free encryption should be shown as inactive.
		// Otherwise, show actual free encryption status.
		if ( $pro_keys_active ) {
			$free_status = 'inactive';
		} else {
			$free_status = ( $free_public_key && $free_private_key ) ? 'active' : 'needs-init';
		}

		// 3-column layout: Free on left, Password in middle, Pro on right.
		echo '<div class="seculoco-encryption-grid">';

		// ===== LEFT COLUMN: FREE VERSION =====
		echo '<div class="seculoco-encryption-column">';



		// Free RSA Keys detailed status.
		echo '<div class="seculoco-encryption-status-card">';
		echo '<div class="seculoco-encryption-status-header">';
		echo '<div>';
		echo '<strong class="seculoco-encryption-status-title">' . esc_html__( 'Standard RSA Keys', 'secure-login-collector' ) . '</strong>';
		echo '<p class="seculoco-encryption-status-subtitle">' . esc_html__( 'RSA-2048 + AES-256-GCM encryption', 'secure-login-collector' ) . '</p>';
		echo '</div>';

		// Display status based on $free_status variable.
		if ( 'active' === $free_status ) {
			echo '<div class="seculoco-encryption-status-label">';
			echo '<span class="seculoco-encryption-badge-active">' . esc_html__( 'ACTIVE', 'secure-login-collector' ) . '</span>';
			echo '</div>';
		} elseif ( 'inactive' === $free_status ) {
			echo '<div class="seculoco-encryption-status-label">';
			echo '<span class="seculoco-encryption-badge-inactive">' . esc_html__( 'INACTIVE', 'secure-login-collector' ) . '</span>';
			echo '<p class="seculoco-encryption-hint">' . esc_html__( 'Passkey encryption is active', 'secure-login-collector' ) . '</p>';
			echo '</div>';
		} else {
			echo '<div class="seculoco-encryption-status-label">';
			echo '<span class="seculoco-encryption-badge-not-init">' . esc_html__( 'NOT INITIALIZED', 'secure-login-collector' ) . '</span>';
			echo '<p class="seculoco-encryption-hint">' . esc_html__( 'Will be created on first use', 'secure-login-collector' ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';


		// Free export button.
		if ( $free_public_key ) {
			echo '<p>';
			echo '<button type="button" class="button button-secondary" id="export-free-public-key">' . esc_html__( 'Export Public Key', 'secure-login-collector' ) . '</button>';
			echo '</p>';
		}

		echo '</div>'; // Close left column

		// ===== MIDDLE COLUMN: PASSWORD-BASED =====
		echo '<div class="seculoco-encryption-column">';

		// Get password encryption status.
		$password_active = get_option( 'seculoco_password_encryption_active', false );

		// Password-based encryption status card.
		echo '<div class="seculoco-encryption-status-card">';
		echo '<div class="seculoco-encryption-status-header">';
		echo '<div>';
		echo '<strong class="seculoco-encryption-status-title">' . esc_html__( 'Password-Based Encryption', 'secure-login-collector' ) . '</strong>';
		echo '<p class="seculoco-encryption-status-subtitle">' . esc_html__( 'RSA-2048 protected by master password', 'secure-login-collector' ) . '</p>';
		echo '</div>';

		// Display password status.
		if ( $password_active ) {
			echo '<div class="seculoco-encryption-status-label">';
			echo '<span class="seculoco-encryption-badge-active">' . esc_html__( 'ACTIVE', 'secure-login-collector' ) . '</span>';
			echo '</div>';
		} else {
			echo '<div class="seculoco-encryption-status-label">';
			echo '<span class="seculoco-encryption-badge-not-init">' . esc_html__( 'NOT SET', 'secure-login-collector' ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		// Password setup/reset buttons.
		echo '<p>';
		if ( $password_active ) {
			echo '<button type="button" class="seculoco-btn seculoco-btn-danger seculoco-btn-lg seculoco-password-reset-btn">';
			echo '<span>🔄</span> ';
			echo esc_html__( 'Reset Password', 'secure-login-collector' );
			echo '</button>';
		} else {
			echo '<button type="button" class="seculoco-btn seculoco-btn-primary seculoco-btn-lg seculoco-password-setup-btn">';
			echo '<span>🔐</span> ';
			echo esc_html__( 'Setup Password', 'secure-login-collector' );
			echo '</button>';
		}
		echo '</p>';

		// Warning text.
		echo '<div class="seculoco-alert seculoco-alert-warning" style="margin-top: 15px;">';
		echo '<span class="seculoco-alert-icon dashicons dashicons-warning"></span>';
		echo '<div class="seculoco-alert-content">';
		echo '<p class="seculoco-alert-message">' . esc_html__( 'Resetting the password will mark all existing encrypted data as undecryptable.', 'secure-login-collector' ) . '</p>';
		echo '</div>';
		echo '</div>';

		echo '</div>'; // Close middle column

		// ===== RIGHT COLUMN: PRO VERSION =====
		// Allow pro version to add its entire column or show upgrade notice.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_pro_encryption_column().
		echo apply_filters( 'seculoco_encryption_pro_column', $this->get_pro_encryption_column() );

		echo '</div>'; // Close 2-column grid

	}

	/**
	 * Get the default pro encryption column HTML (right column).
	 * Pro version will filter this out and replace with actual pro content.
	 *
	 * @return string HTML for the pro column upgrade notice.
	 */
	private function get_pro_encryption_column() {
		$upgrade_url = function_exists( 'seculoco_fs' ) && seculoco_fs() ? seculoco_fs()->get_upgrade_url() : '#';

		ob_start();
		?>
		<div class="seculoco-encryption-column">
			<!-- Pro status card with upgrade notice -->
			<div class="seculoco-passkey-benefit seculoco-pro-upgrade-card">
				<div class="seculoco-pro-upgrade-header">
					<span class="seculoco-badge seculoco-pro-badge"><?php echo esc_html__( 'PRO ONLY', 'secure-login-collector' ); ?></span>
					<span class="seculoco-pro-status-unavailable"><?php echo esc_html__( 'NOT AVAILABLE', 'secure-login-collector' ); ?></span>
				</div>
				<span class="seculoco-passkey-benefit-icon"></span>
				<div class="seculoco-passkey-benefit-text">
					<div class="seculoco-passkey-benefit-title">
						<?php echo esc_html__( 'Ultra-Secure (Passkey-Protected)', 'secure-login-collector' ); ?>
					</div>
					<div class="seculoco-passkey-benefit-desc">
						<?php echo esc_html__( 'Passkey-protected encryption with WebAuthn/FIDO2. True zero-knowledge - server cannot decrypt without your physical device.', 'secure-login-collector' ); ?>
					</div>
				</div>
			</div>
			<a href="<?php echo esc_url( $upgrade_url ); ?>" class="button button-primary">
				<?php echo esc_html__( 'Upgrade to Pro', 'secure-login-collector' ); ?>
			</a>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Advanced security settings section callback.
	 */
	public function pro_section_callback() {
		echo '<div class="seculoco-card seculoco-card-margin-top">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">';
		echo esc_html__( 'Advanced Security Features', 'secure-login-collector' );
		echo '</h3>';
		echo '<span class="seculoco-badge seculoco-badge-success">ADVANCED</span>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Advanced security settings including passkey authentication for enhanced protection.', 'secure-login-collector' ) . '</p>';

		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Settings field callbacks.
	 */

	/**
	 * Enable notifications field callback.
	 */
	public function enable_notifications_callback() {
		$enabled = get_option( 'seculoco_enable_notifications', false );
		echo '<input type="checkbox" id="seculoco_enable_notifications" name="seculoco_enable_notifications" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="seculoco_enable_notifications"> ' . esc_html__( 'Send email notifications when new login data is received', 'secure-login-collector' ) . '</label>';
	}

	/**
	 * Notification email field callback.
	 */
	public function notification_email_callback() {
		$email = get_option( 'seculoco_notification_email', get_option( 'admin_email' ) );
		echo '<input type="email" id="seculoco_notification_email" name="seculoco_notification_email" value="' . esc_attr( $email ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Email address to receive notifications. Defaults to site admin email.', 'secure-login-collector' ) . '</p>';
	}


	/**
	 * Retrieve the default frontend description text.
	 *
	 * @return string
	 */
	private function get_default_frontend_text() {
		$text  = '<p><strong>' . __( 'What happens to your data:', 'secure-login-collector' ) . '</strong> ' . __( 'Your login data is encrypted in your browser before being sent to our server. We use strong RSA-2048 encryption to ensure maximum security.', 'secure-login-collector' ) . '</p>';
		$text .= '<p><strong>' . __( 'Security & Privacy:', 'secure-login-collector' ) . '</strong> ' . __( 'Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely{EXPIRATION_TEXT}.', 'secure-login-collector' ) . '</p>';

		return $text;
	}

	/**
	 * Frontend form text field callback.
	 */
	public function frontend_form_text_callback() {
		$text      = get_option( 'seculoco_frontend_form_text', '' );
		$text_type = get_option( 'seculoco_frontend_text_type', 'default' );

		// Generate the default text with placeholder for dynamic expiration text.
		$default_text = $this->get_default_frontend_text();

		// If no custom text is set, show the default text.
		$display_text = ! empty( $text ) ? $text : $default_text;
		$is_disabled  = ( 'default' === $text_type ) ? 'disabled' : '';

		echo '<textarea id="seculoco_frontend_form_text" name="seculoco_frontend_form_text" rows="6" class="large-text" style="width: 100%;" ' . esc_attr( $is_disabled ) . '>' . esc_textarea( $display_text ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Custom text to display above the login form. Basic HTML allowed (p, strong, em, br, a). This field is automatically populated with the default text when no custom text is provided. Use {EXPIRATION_TEXT} placeholder for automatic expiration information.', 'secure-login-collector' ) . '</p>';

	}

	/**
	 * Frontend text type field callback.
	 */
	public function frontend_text_type_callback() {
		$text_type = get_option( 'seculoco_frontend_text_type', 'default' );

		echo '<fieldset>';
		echo '<label>';
		echo '<input type="radio" name="seculoco_frontend_text_type" value="default" ' . checked( 'default', $text_type, false ) . '> ';
		echo esc_html__( 'Default (Automatic encryption & expiration details)', 'secure-login-collector' );
		echo '</label><br><br>';

		echo '<label>';
		echo '<input type="radio" name="seculoco_frontend_text_type" value="custom" ' . checked( 'custom', $text_type, false ) . '> ';
		echo esc_html__( 'Custom Text (Use the text below)', 'secure-login-collector' );
		echo '</label>';
		echo '</fieldset>';

		echo '<p class="description">' . esc_html__( 'Choose whether to use the default security information text or your custom text below.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Hide service footer field callback.
	 * Free version: Shows informational text about PRO feature
	 * Pro version: Filters the content to replace with actual setting control
	 */
	public function hide_service_footer_callback() {
		// Free version default content (informational text)
		$content  = '<p class="description" style="color: #666;">';
		$content .= '<span class="seculoco-badge seculoco-pro-badge" style="margin-right: 8px;">' . esc_html__( 'PRO ONLY', 'secure-login-collector' ) . '</span>';
		$content .= esc_html__( 'The Pro version allows you to hide the branding footer on the frontend form. Free version users help support the plugin by displaying this footer.', 'secure-login-collector' );
		$content .= '</p>';

		/**
		 * Filter the service footer setting content.
		 * Pro version can replace the informational text with actual controls.
		 *
		 * @param string $content The default content (informational text for free version).
		 */
		$content = apply_filters( 'seculoco_hide_service_footer_setting_content', $content );

		echo $content;
	}

	/**
	 * Expiration days field callback.
	 */
	public function expiration_days_callback() {
		$days = get_option( 'seculoco_expiration_days', 30 );
		echo '<input type="number" id="seculoco_expiration_days" name="seculoco_expiration_days" value="' . esc_attr( $days ) . '" min="0" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Number of days after which login data will be automatically deleted. Set to 0 to disable automatic deletion (data will be retained until manually deleted).', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Honeypot enabled field callback.
	 */
	public function honeypot_enabled_callback() {
		$enabled = get_option( 'seculoco_honeypot_enabled', true );
		echo '<input type="checkbox" id="seculoco_honeypot_enabled" name="seculoco_honeypot_enabled" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="seculoco_honeypot_enabled"> ' . esc_html__( 'Add hidden field to detect automated bot submissions', 'secure-login-collector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The honeypot technique adds a hidden field to the form that bots typically fill out but humans cannot see. If the field contains data, the submission is rejected.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Plugin management section callback.
	 */
	public function plugin_management_section_callback() {
		echo '<div class="seculoco-card" style="margin-top: 20px;">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">';
		echo esc_html__( 'Plugin Management', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Configure how the plugin handles data when it is uninstalled.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Delete on uninstall field callback.
	 */
	public function delete_on_uninstall_callback() {
		$enabled = get_option( 'seculoco_delete_on_uninstall', false );
		echo '<input type="checkbox" id="seculoco_delete_on_uninstall" name="seculoco_delete_on_uninstall" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="seculoco_delete_on_uninstall"> ' . esc_html__( 'Completely remove all plugin data when uninstalling', 'secure-login-collector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When checked, all login data, encryption keys, settings, and database tables will be permanently deleted when the plugin is uninstalled. This action cannot be undone.', 'secure-login-collector' ) . '</p>';
		echo '<div class="notice notice-warning inline" style="margin-top: 10px;">';
		echo '<p><strong>' . esc_html__( 'Warning:', 'secure-login-collector' ) . '</strong> ' . esc_html__( 'If you enable this option, all encrypted login data will be permanently lost when you uninstall the plugin. Make sure to export any important data before uninstalling.', 'secure-login-collector' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Settings page.
	 */
	public function settings_page() {
		?>
		<div class="wrap seculoco-admin-wrap">
			<h1><?php echo esc_html__( 'Secure Login Collector Settings', 'secure-login-collector' ); ?></h1>
			
			<!-- Shortcode Display Section -->
			<div class="seculoco-card">
				<div class="seculoco-card-header">
					<h3 class="seculoco-card-title">
						
						<?php echo esc_html__( 'Frontend Form Shortcode', 'secure-login-collector' ); ?>
					</h3>
					<span class="seculoco-badge seculoco-badge-info"><?php echo esc_html__( 'Required', 'secure-login-collector' ); ?></span>
				</div>
				<div class="seculoco-card-body">
					<p><?php echo esc_html__( 'Use this shortcode to display the secure login form on any page or post:', 'secure-login-collector' ); ?></p>
					<div style="background: var(--seculoco-bg-light); padding: 12px 16px; border-radius: var(--seculoco-radius-sm); display: inline-block; font-family: 'Monaco', 'Menlo', monospace; font-size: 16px; border: 2px solid var(--seculoco-border); font-weight: 500;">
						[seculoco_form]
					</div>
					<div style="margin-top: 12px;">
						<button type="button" class="button" onclick="navigator.clipboard.writeText('[seculoco_form]'); this.innerHTML = '<?php echo esc_js( __( 'Copied!', 'secure-login-collector' ) ); ?>'; setTimeout(() => { this.innerHTML = '<?php echo esc_js( __( 'Copy Shortcode', 'secure-login-collector' ) ); ?>'; }, 2000);">
							<?php echo esc_html__( 'Copy Shortcode', 'secure-login-collector' ); ?>
						</button>
					</div>
					<p class="seculoco-form-help" style="margin-top: 16px;">
						<?php echo esc_html__( 'Simply paste this shortcode into any page or post where you want clients to submit their login credentials.', 'secure-login-collector' ); ?>
					</p>
				</div>
			</div>
			
			<form method="post" action="options.php" class="seculoco-settings-form">
				<div class="seculoco-settings-sections">
					<?php
					settings_fields( 'seculoco_settings' );

					// Custom rendering of settings sections to properly wrap in cards.
					global $wp_settings_sections, $wp_settings_fields;
					$page = 'seculoco_settings';

					if ( ! isset( $wp_settings_sections[ $page ] ) ) {
						return;
					}

					foreach ( (array) $wp_settings_sections[ $page ] as $section ) {
						// Call the section callback to render the card opening.
						if ( $section['callback'] ) {
							call_user_func( $section['callback'], $section );
						}

						// Render the fields inside the card.
						if ( ! isset( $wp_settings_fields ) || ! isset( $wp_settings_fields[ $page ] ) || ! isset( $wp_settings_fields[ $page ][ $section['id'] ] ) ) {
							echo '</div></div>'; // Close card-body and card if no fields
							continue;
						}

						echo '<table class="form-table" role="presentation">';
						do_settings_fields( $page, $section['id'] );
						echo '</table>';
						echo '</div></div>'; // Close card-body and card
					}

					submit_button();
					?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize boolean values.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return bool Sanitized boolean value.
	 */
	public function sanitize_boolean( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sanitize frontend form text.
	 *
	 * @param string $text The text to sanitize.
	 * @return string Sanitized text.
	 */
	public function sanitize_frontend_form_text( $text ) {
		// Get the text type selection.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WordPress settings API.
		$text_type = isset( $_POST['seculoco_frontend_text_type'] ) ? sanitize_text_field( wp_unslash( $_POST['seculoco_frontend_text_type'] ) ) : 'default';

		// If "default" is selected, don't save any custom text (save empty string).
		if ( 'default' === $text_type ) {
			return '';
		}

		// For custom text, sanitize and allow basic HTML.
		return wp_kses(
			$text,
			array(
				'p'      => array(),
				'strong' => array(),
				'em'     => array(),
				'br'     => array(),
				'a'      => array(
					'href'   => array(),
					'target' => array(),
				),
			)
		);
	}

	/**
	 * AJAX handler: Setup password-based encryption.
	 */
	public function ajax_setup_password_encryption() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'seculoco_password_setup_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'secure-login-collector' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'secure-login-collector' ) ) );
		}

		// Get password from request.
		if ( ! isset( $_POST['password'] ) || empty( $_POST['password'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Password is required.', 'secure-login-collector' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password should not be sanitized.
		$password = wp_unslash( $_POST['password'] );

		// Validate password length.
		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters.', 'secure-login-collector' ) ) );
		}

		try {
			// Verify method exists before calling.
			if ( ! method_exists( $this->encryption_handler, 'initialize_password_keys' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debugging.
				error_log( 'ERROR: initialize_password_keys method not found on ' . get_class( $this->encryption_handler ) );
				wp_send_json_error( array( 'message' => __( 'Encryption handler configuration error. Please check logs.', 'secure-login-collector' ) ) );
				return;
			}

			// Generate password-based keys using encryption handler.
			$result = $this->encryption_handler->initialize_password_keys( $password );

			if ( $result ) {
				// Store password status.
				update_option( 'seculoco_password_encryption_active', true );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debugging.
				error_log( 'SUCCESS: Password encryption initialized' );

				wp_send_json_success( array( 'message' => __( 'Password-based encryption setup successfully!', 'secure-login-collector' ) ) );
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debugging.
				error_log( 'ERROR: initialize_password_keys returned false' );
				wp_send_json_error( array( 'message' => __( 'Failed to initialize password-based keys.', 'secure-login-collector' ) ) );
			}
		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debugging.
			error_log( 'EXCEPTION in password setup: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler: Reset password-based encryption.
	 */
	public function ajax_reset_password_encryption() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'seculoco_password_setup_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'secure-login-collector' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'secure-login-collector' ) ) );
		}

		try {
			$result = $this->encryption_handler->reset_password_keys();

			wp_send_json_success(
				array(
					'message'         => __( 'Password-based encryption reset successfully!', 'secure-login-collector' ),
					'entries_updated' => isset( $result['affected_entries'] ) ? $result['affected_entries'] : false,
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler: Check password encryption status.
	 */
	public function ajax_check_password_status() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'seculoco_password_setup_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'secure-login-collector' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'secure-login-collector' ) ) );
		}

		$is_active = get_option( 'seculoco_password_encryption_active', false );

		wp_send_json_success(
			array(
				'is_active' => (bool) $is_active,
				'status'    => $is_active ? 'active' : 'not_set',
			)
		);
	}
}
