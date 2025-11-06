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
	 * @var Seculoco_Encryption_Handler_V2
	 */
	private $encryption_handler;

	/**
	 * Constructor - initializes settings manager.
	 *
	 * @param Seculoco_Encryption_Handler_V2 $encryption_handler Encryption handler instance.
	 */
	public function __construct( $encryption_handler ) {
		$this->encryption_handler = $encryption_handler;

		// Register hooks.
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
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

		// Enqueue jQuery for inline scripts.
		wp_enqueue_script( 'jquery' );

		// Enqueue merged admin script.
		wp_enqueue_script(
			'seculoco-admin-js',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin.js',
			array( 'jquery' ),
			filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/admin.js' ),
			true
		);

		// Localize master password reset script.
		$this->localize_master_password_reset_script();
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
	 * Add key management inline script.
	 */
	private function add_key_management_inline_script() {
		$nonce = wp_create_nonce( 'seculoco_admin_nonce' );

		$script = "
		jQuery(document).ready(function($) {
			// Initialize free keys
			$('#initialize-free-keys').on('click', function() {
				var button = $(this);
				button.prop('disabled', true).text('" . esc_js( __( 'Initializing...', 'secure-login-collector' ) ) . "');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'seculoco_initialize_free_keys',
						nonce: '" . esc_js( $nonce ) . "'
					},
					success: function(response) {
						if (response.success) {
							alert('" . esc_js( __( 'Free RSA keys initialized successfully!', 'secure-login-collector' ) ) . "');
							location.reload();
						} else {
							alert('" . esc_js( __( 'Failed to initialize keys:', 'secure-login-collector' ) ) . "' + response.data);
							button.prop('disabled', false).text('" . esc_js( __( 'Initialize Free Keys Now', 'secure-login-collector' ) ) . "');
						}
					},
					error: function() {
						alert('" . esc_js( __( 'Network error occurred.', 'secure-login-collector' ) ) . "');
						button.prop('disabled', false).text('" . esc_js( __( 'Initialize Free Keys Now', 'secure-login-collector' ) ) . "');
					}
				});
			});
		});
		";

		wp_add_inline_script( 'seculoco-admin-js', $script );
	}

	/**
	 * Localize master password reset script data.
	 */
	private function localize_master_password_reset_script() {
		// Check if there are any encrypted entries for the warning message.
		global $wpdb;
		$table_name = $wpdb->prefix . 'seculoco_data';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_encrypted_data = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) > 0;

		// Prepare localized data.
		$script_data = array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'seculoco_wizard_nonce' ),
			'hasEncryptedData' => $has_encrypted_data,
			'strings'          => array(
				// Legacy strings (kept for backward compatibility)
				'warningDataLoss'              => __( 'WARNING: Resetting your master password will make ALL existing encrypted data permanently inaccessible!\n\nAll encrypted login credentials will be lost forever. This action CANNOT be undone. There is NO recovery method.\n\nAre you absolutely sure you want to proceed?', 'secure-login-collector' ),
				'warningSimple'                => __( 'Are you sure you want to reset your master password?\n\nYou will need to set up a new master password afterward.', 'secure-login-collector' ),
				'resetting'                    => __( 'Resetting...', 'secure-login-collector' ),
				'resetButton'                  => __( 'Reset Master Password', 'secure-login-collector' ),
				'resetSuccess'                 => __( 'Master password reset successfully! Please set up a new master password.', 'secure-login-collector' ),
				'resetFailed'                  => __( 'Failed to reset master password:', 'secure-login-collector' ),
				'networkError'                 => __( 'Network error occurred. Please try again.', 'secure-login-collector' ),
				// Modal strings for enhanced reset dialog
				'modal_warning_title_with_data' => __( 'CRITICAL WARNING: Master Password Reset', 'secure-login-collector' ),
				'modal_warning_title_no_data'   => __( 'Master Password Reset', 'secure-login-collector' ),
				'critical_warning_title'        => __( 'CRITICAL WARNING:', 'secure-login-collector' ),
				'critical_warning_main'         => __( 'Resetting your master password will permanently prevent decryption of all existing encrypted login data.', 'secure-login-collector' ),
				'warning_list_item_1'           => __( 'All encrypted login credentials will become permanently inaccessible', 'secure-login-collector' ),
				'warning_list_item_2'           => __( 'This action CANNOT be undone', 'secure-login-collector' ),
				'warning_list_item_3'           => __( 'There is NO recovery method', 'secure-login-collector' ),
				'warning_list_item_4'           => __( 'You will need to collect new login data from clients', 'secure-login-collector' ),
				'confirmation_checkbox_label'   => __( 'I understand that all encrypted data will be permanently lost', 'secure-login-collector' ),
				'safe_reset_message_1'          => __( 'You can safely reset your master password and start fresh.', 'secure-login-collector' ),
				'safe_reset_message_2'          => __( 'Since you have no encrypted data stored, this is completely safe.', 'secure-login-collector' ),
				'cancel_button'                 => __( 'Cancel', 'secure-login-collector' ),
				'confirm_reset_with_data'       => __( 'Yes, Reset Master Password', 'secure-login-collector' ),
				'confirm_reset_no_data'         => __( 'Reset Master Password', 'secure-login-collector' ),
				'unknown_error'                 => __( 'An unknown error occurred.', 'secure-login-collector' ),
			),
		);

		// Use wp_localize_script for proper escaping.
		wp_localize_script( 'seculoco-admin-js', 'secureLoginMasterPasswordData', $script_data );
	}

	/**
	 * Display encryption content inside the card
	 */
	private function display_encryption_content() {
		// Get key status for free version.
		// Note: Using legacy constants for backward compatibility.
		// The actual option name is 'seculoco_private_key_free' (not _encrypted).
		$free_public_key  = get_option( SECULOCO_OPTION_PUBLIC_KEY );
		$free_private_key = get_option( SECULOCO_OPTION_PRIVATE_KEY_WRAPPED );

		// Check if passkey encryption is active (pro keys).
		$pro_keys_active = get_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, false );

		// If passkey encryption is active, free encryption should be shown as inactive.
		// Otherwise, show actual free encryption status.
		if ( $pro_keys_active ) {
			$free_status = 'inactive';
		} else {
			$free_status = ( $free_public_key && $free_private_key ) ? 'active' : 'needs-init';
		}

		// Check if encryption is initialized (master password setup).
		$is_initialized = seculoco_is_encryption_initialized();
		$setup_date     = get_option( SECULOCO_OPTION_SETUP_TIMESTAMP, '' );

		// Display active encryption method status bar.
		$this->render_active_encryption_status_bar( $free_status, $pro_keys_active );

		// 2-column layout: Free on left, Pro on right.
		echo '<div class="seculoco-encryption-grid">';

		// ===== LEFT COLUMN: FREE VERSION =====
		echo '<div class="seculoco-encryption-column">';

		// Master Password Status Card - displayed prominently at the top.
		echo '<div class="seculoco-card">';

		if ( ! $is_initialized ) {
			// Master password NOT configured - show setup required state.
			echo '<div class="seculoco-passkey-benefit-text">';
			echo '<div class="seculoco-card-title">';
			echo '<span class="seculoco-badge seculoco-badge-warning">' . esc_html__( 'SETUP REQUIRED', 'secure-login-collector' ) . '</span>';
			echo ' ' . esc_html__( 'Master Password', 'secure-login-collector' );
			echo '</div>';
			echo '<div class="seculoco-passkey-benefit-desc">';
			echo esc_html__( 'Configure your master password to secure encryption keys and protect sensitive data.', 'secure-login-collector' );
			echo '<br><button type="button" class="seculoco-btn seculoco-btn-primary seculoco-btn-lg seculoco-launch-wizard seculoco-margin-top-8">';
			echo '<span>🔒</span> ';
			echo esc_html__( 'Start Master Password Wizard', 'secure-login-collector' );
			echo '</button>';
			echo '</div>';
			echo '</div>';
		} else {
			// Master password IS configured - show active state.
			echo '<div class="seculoco-passkey-benefit-text">';
			echo '<div class="seculoco-card-title">';
			echo '<span class="seculoco-badge seculoco-badge-success">' . esc_html__( 'ACTIVE', 'secure-login-collector' ) . '</span>';
			echo ' ' . esc_html__( 'Master Password Protection', 'secure-login-collector' );
			echo '</div>';
			echo '<div class="seculoco-passkey-benefit-desc">';
			if ( $setup_date ) {
				echo '<strong>' . esc_html__( 'Setup Date:', 'secure-login-collector' ) . '</strong> ' . esc_html( $setup_date ) . '<br>';
			}
			echo esc_html__( 'Your encryption keys are securely protected with your master password. All sensitive data is encrypted at rest.', 'secure-login-collector' );
			
			echo '</div>';
			echo '</div>';

			// Master Password Reset Section - always show if encryption is initialized.
			// Check if there are any encrypted entries to customize the warning message.
			global $wpdb;
			$table_name = $wpdb->prefix . 'seculoco_data';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_encrypted_data = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) > 0;

			if ( $has_encrypted_data ) {
				// SEVERE WARNING: Data exists and will be lost.
				echo '<div class="seculoco-alert seculoco-alert-danger seculoco-margin-top-20">';
				echo '<span class="seculoco-alert-icon">⚠️</span>';
				echo '<div class="seculoco-alert-content">';
				echo '<div class="seculoco-alert-title">' . esc_html__( 'CRITICAL WARNING: Master Password Reset', 'secure-login-collector' ) . '</div>';
				echo '<div class="seculoco-alert-message">';
				echo '<p><strong>' . esc_html__( 'Resetting your master password will permanently prevent decryption of all existing encrypted login data.', 'secure-login-collector' ) . '</strong></p>';
				echo '<p><strong>' . esc_html__( 'This action CANNOT be undone. There is NO recovery method.', 'secure-login-collector' ) . '</strong></p>';
				echo '</div>';
				echo '<button type="button" class="seculoco-btn seculoco-btn-danger" id="reset-master-password-btn">';
				echo esc_html__( 'Reset Master Password', 'secure-login-collector' );
				echo '</button>';
				echo '</div>';
				echo '</div>';
			} else {
				// FRIENDLY MESSAGE: No data exists, safe to reset.
				echo '<div class="seculoco-alert seculoco-alert-info seculoco-margin-top-20">';
				echo '<span class="seculoco-alert-icon">ℹ️</span>';
				echo '<div class="seculoco-alert-content">';
				echo '<div class="seculoco-alert-title">' . esc_html__( 'Master Password Reset', 'secure-login-collector' ) . '</div>';
				echo '<div class="seculoco-alert-message">';
				echo '<p>' . esc_html__( 'You can reset your master password and start fresh. Since you have no encrypted data stored, this is completely safe.', 'secure-login-collector' ) . '</p>';
				echo '</div>';
				echo '<button type="button" class="seculoco-btn seculoco-btn-secondary" id="reset-master-password-btn">';
				echo esc_html__( 'Reset Master Password', 'secure-login-collector' );
				echo '</button>';
				echo '</div>';
				echo '</div>';
			}
		}

		echo '</div>'; // Close master password card

		echo '</div>'; // Close left column

		// ===== RIGHT COLUMN: PRO VERSION =====
		// Allow pro version to add its entire column or show upgrade notice.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_pro_encryption_column().
		echo '<div class="seculoco-encryption-column">';

		echo apply_filters( 'seculoco_encryption_pro_column', $this->get_pro_encryption_column() );
		echo '</div>'; // close right column.
		echo '</div>'; // Close 2-column grid

		// Add JavaScript for key management via wp_add_inline_script.
		$this->add_key_management_inline_script();
	}

	/**
	 * Render active encryption method status bar.
	 * Displays which encryption method is currently active for new incoming logins.
	 *
	 * @param string $free_status Free encryption status: 'active', 'inactive', or 'needs-init'.
	 * @param bool   $pro_keys_active Whether pro keys are active.
	 */
	private function render_active_encryption_status_bar( $free_status, $pro_keys_active ) {
		echo '<div class="seculoco-encryption-status-bar">';
		echo '<div class="seculoco-encryption-status-bar-header">';
		echo '<h4 class="seculoco-encryption-status-bar-title">';
		echo '<span class="seculoco-status-icon">🔐</span>';
		echo esc_html__( 'Active Encryption Method for New Logins', 'secure-login-collector' );
		echo '</h4>';
		echo '</div>';
		echo '<div class="seculoco-encryption-status-bar-body">';

		// Determine which encryption is active and display appropriate status.
		if ( $pro_keys_active ) {
			// Pro/Passkey encryption is active.
			echo '<div class="seculoco-encryption-status-item seculoco-encryption-status-item-active">';
			echo '<span class="seculoco-status-dot seculoco-status-dot-active"></span>';
			echo '<div class="seculoco-encryption-status-content">';
			echo '<div class="seculoco-encryption-status-label">';
			echo '<strong>' . esc_html__( 'Passkey Protection', 'secure-login-collector' ) . '</strong>';
			echo ' <span class="seculoco-badge seculoco-badge-success">' . esc_html__( 'ACTIVE', 'secure-login-collector' ) . '</span>';
			echo '</div>';
			echo '<p class="seculoco-encryption-status-desc">';
			echo esc_html__( 'New login data will be encrypted using passkey-protected RSA keys (ultra-secure, zero-knowledge encryption).', 'secure-login-collector' );
			echo '</p>';
			echo '</div>';
			echo '</div>';

			// Show password encryption as inactive.
			echo '<div class="seculoco-encryption-status-item seculoco-encryption-status-item-inactive">';
			echo '<span class="seculoco-status-dot seculoco-status-dot-inactive"></span>';
			echo '<div class="seculoco-encryption-status-content">';
			echo '<div class="seculoco-encryption-status-label">';
			echo '<strong>' . esc_html__( 'Password Encryption', 'secure-login-collector' ) . '</strong>';
			echo ' <span class="seculoco-badge seculoco-badge-inactive">' . esc_html__( 'INACTIVE', 'secure-login-collector' ) . '</span>';
			echo '</div>';
			echo '<p class="seculoco-encryption-status-desc">';
			echo esc_html__( 'Password-based encryption is disabled while passkey protection is active.', 'secure-login-collector' );
			echo '</p>';
			echo '</div>';
			echo '</div>';
		} elseif ( 'active' === $free_status ) {
			// Free/Password encryption is active.
			echo '<div class="seculoco-encryption-status-item seculoco-encryption-status-item-active">';
			echo '<span class="seculoco-status-dot seculoco-status-dot-active"></span>';
			echo '<div class="seculoco-encryption-status-content">';
			echo '<div class="seculoco-encryption-status-label">';
			echo '<strong>' . esc_html__( 'Password Encryption', 'secure-login-collector' ) . '</strong>';
			echo ' <span class="seculoco-badge seculoco-badge-success">' . esc_html__( 'ACTIVE', 'secure-login-collector' ) . '</span>';
			echo '</div>';
			echo '<p class="seculoco-encryption-status-desc">';
			echo esc_html__( 'New login data will be encrypted using master password-protected RSA keys (secure encryption).', 'secure-login-collector' );
			echo '</p>';
			echo '</div>';
			echo '</div>';

			// Show passkey as not available (free version) or available but not active (pro version).
			if ( function_exists( 'seculoco_fs' ) && seculoco_fs()->is_premium() ) {
				// Pro version - passkey available but not active.
				echo '<div class="seculoco-encryption-status-item seculoco-encryption-status-item-inactive">';
				echo '<span class="seculoco-status-dot seculoco-status-dot-inactive"></span>';
				echo '<div class="seculoco-encryption-status-content">';
				echo '<div class="seculoco-encryption-status-label">';
				echo '<strong>' . esc_html__( 'Passkey Protection', 'secure-login-collector' ) . '</strong>';
				echo ' <span class="seculoco-badge seculoco-badge-inactive">' . esc_html__( 'AVAILABLE', 'secure-login-collector' ) . '</span>';
				echo '</div>';
				echo '<p class="seculoco-encryption-status-desc">';
				echo esc_html__( 'Passkey encryption is available but not currently active. Configure passkey protection below to enable ultra-secure encryption.', 'secure-login-collector' );
				echo '</p>';
				echo '</div>';
				echo '</div>';
			} else {
				// Free version - passkey not available.
				echo '<div class="seculoco-encryption-status-item seculoco-encryption-status-item-unavailable">';
				echo '<span class="seculoco-status-dot seculoco-status-dot-inactive"></span>';
				echo '<div class="seculoco-encryption-status-content">';
				echo '<div class="seculoco-encryption-status-label">';
				echo '<strong>' . esc_html__( 'Passkey Protection', 'secure-login-collector' ) . '</strong>';
				echo ' <span class="seculoco-badge seculoco-badge-pro">' . esc_html__( 'PRO ONLY', 'secure-login-collector' ) . '</span>';
				echo '</div>';
				echo '<p class="seculoco-encryption-status-desc">';
				echo esc_html__( 'Upgrade to Pro for ultra-secure passkey-protected encryption with true zero-knowledge security.', 'secure-login-collector' );
				echo '</p>';
				echo '</div>';
				echo '</div>';
			}
		} else {
			// Neither encryption is active - setup required.
			echo '<div class="seculoco-encryption-status-item seculoco-encryption-status-item-warning">';
			echo '<span class="seculoco-status-dot seculoco-status-dot-warning"></span>';
			echo '<div class="seculoco-encryption-status-content">';
			echo '<div class="seculoco-encryption-status-label">';
			echo '<strong>' . esc_html__( 'Encryption Not Active', 'secure-login-collector' ) . '</strong>';
			echo ' <span class="seculoco-badge seculoco-badge-warning">' . esc_html__( 'SETUP REQUIRED', 'secure-login-collector' ) . '</span>';
			echo '</div>';
			echo '<p class="seculoco-encryption-status-desc">';
			echo esc_html__( 'No encryption method is currently active. Configure your master password below to start encrypting login data.', 'secure-login-collector' );
			echo '</p>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>'; // Close status bar body.
		echo '</div>'; // Close status bar.
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
		$enabled = get_option( SECULOCO_OPTION_ENABLE_NOTIFICATIONS, false );
		echo '<input type="checkbox" id="seculoco_enable_notifications" name="seculoco_enable_notifications" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="seculoco_enable_notifications"> ' . esc_html__( 'Send email notifications when new login data is received', 'secure-login-collector' ) . '</label>';
	}

	/**
	 * Notification email field callback.
	 */
	public function notification_email_callback() {
		$email = get_option( SECULOCO_OPTION_NOTIFICATION_EMAIL, get_option( 'admin_email' ) );
		echo '<input type="email" id="seculoco_notification_email" name="seculoco_notification_email" value="' . esc_attr( $email ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Email address to receive notifications. Defaults to site admin email.', 'secure-login-collector' ) . '</p>';
	}


	/**
	 * Add textarea toggle inline script.
	 *
	 * @param string $default_text Default text value.
	 */
	private function add_textarea_toggle_inline_script( $default_text ) {
		$script = "
		jQuery(document).ready(function($) {
			var defaultText = " . wp_json_encode( $default_text ) . ";
			var originalText = $('#seculoco_frontend_form_text').val();

			function toggleTextarea() {
				var selectedType = $('input[name=\"seculoco_frontend_text_type\"]:checked').val();
				var textarea = $('#seculoco_frontend_form_text');

				if (selectedType === 'default') {
					textarea.prop('disabled', true).addClass('seculoco-textarea-disabled');
					if (textarea.val() === '' || textarea.val() === defaultText) {
						textarea.val(defaultText);
					}
				} else {
					textarea.prop('disabled', false).removeClass('seculoco-textarea-disabled');
				}
			}

			toggleTextarea();
			$('input[name=\"seculoco_frontend_text_type\"]').on('change', toggleTextarea);
		});
		";

		wp_add_inline_script( 'seculoco-admin-js', $script );
	}

	/**
	 * Frontend form text field callback.
	 */
	public function frontend_form_text_callback() {
		$text      = get_option( SECULOCO_OPTION_FRONTEND_FORM_TEXT, '' );
		$text_type = get_option( SECULOCO_OPTION_FRONTEND_TEXT_TYPE, 'default' );

		// Generate the default text with placeholder for dynamic expiration text.
		$default_text  = '<p><strong>' . __( 'What happens to your data:', 'secure-login-collector' ) . '</strong> ' . __( 'Your login data is encrypted in your browser before being sent to our server. We use strong RSA-2048 encryption to ensure maximum security.', 'secure-login-collector' ) . '</p>';
		$default_text .= '<p><strong>' . __( 'Security & Privacy:', 'secure-login-collector' ) . '</strong> ' . __( 'Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely{EXPIRATION_TEXT}.', 'secure-login-collector' ) . '</p>';

		// If no custom text is set, show the default text.
		$display_text = ! empty( $text ) ? $text : $default_text;
		$is_disabled  = ( 'default' === $text_type ) ? 'disabled' : '';

		echo '<textarea id="seculoco_frontend_form_text" name="seculoco_frontend_form_text" rows="6" class="large-text seculoco-full-width" ' . esc_attr( $is_disabled ) . '>' . esc_textarea( $display_text ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Custom text to display above the login form. Basic HTML allowed (p, strong, em, br, a). This field is automatically populated with the default text when no custom text is provided. Use {EXPIRATION_TEXT} placeholder for automatic expiration information.', 'secure-login-collector' ) . '</p>';

		// Add JavaScript for radio button interaction via wp_add_inline_script.
		$this->add_textarea_toggle_inline_script( $default_text );
	}

	/**
	 * Frontend text type field callback.
	 */
	public function frontend_text_type_callback() {
		$text_type = get_option( SECULOCO_OPTION_FRONTEND_TEXT_TYPE, 'default' );

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
		$content  = '<p class="description">';
		$content .= '<span class="seculoco-badge seculoco-pro-badge">' . esc_html__( 'PRO ONLY', 'secure-login-collector' ) . '</span>';
		$content .= ' ' . esc_html__( 'The Pro version allows you to hide the branding footer on the frontend form. Free version users help support the plugin by displaying this footer.', 'secure-login-collector' );
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
		$days = get_option( SECULOCO_OPTION_EXPIRATION_DAYS, 30 );
		echo '<input type="number" id="seculoco_expiration_days" name="seculoco_expiration_days" value="' . esc_attr( $days ) . '" min="0" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Number of days after which login data will be automatically deleted. Set to 0 to disable automatic deletion (data will be retained until manually deleted).', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Honeypot enabled field callback.
	 */
	public function honeypot_enabled_callback() {
		$enabled = get_option( SECULOCO_OPTION_HONEYPOT_ENABLED, true );
		echo '<input type="checkbox" id="seculoco_honeypot_enabled" name="seculoco_honeypot_enabled" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="seculoco_honeypot_enabled"> ' . esc_html__( 'Add hidden field to detect automated bot submissions', 'secure-login-collector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The honeypot technique adds a hidden field to the form that bots typically fill out but humans cannot see. If the field contains data, the submission is rejected.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Plugin management section callback.
	 */
	public function plugin_management_section_callback() {
		echo '<div class="seculoco-card seculoco-card-margin-top">';
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
		$enabled = get_option( SECULOCO_OPTION_DELETE_ON_UNINSTALL, false );
		echo '<input type="checkbox" id="seculoco_delete_on_uninstall" name="seculoco_delete_on_uninstall" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="seculoco_delete_on_uninstall"> ' . esc_html__( 'Completely remove all plugin data when uninstalling', 'secure-login-collector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When checked, all login data, encryption keys, settings, and database tables will be permanently deleted when the plugin is uninstalled. This action cannot be undone.', 'secure-login-collector' ) . '</p>';
		echo '<div class="seculoco-alert seculoco-alert-warning seculoco-margin-top-8">';
		echo '<span class="seculoco-alert-icon">⚠️</span>';
		echo '<div class="seculoco-alert-content">';
		echo '<div class="seculoco-alert-title">' . esc_html__( 'Warning', 'secure-login-collector' ) . '</div>';
		echo '<div class="seculoco-alert-message">' . esc_html__( 'If you enable this option, all encrypted login data will be permanently lost when you uninstall the plugin. Make sure to export any important data before uninstalling.', 'secure-login-collector' ) . '</div>';
		echo '</div>';
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
					<div class="seculoco-shortcode-box">
						[seculoco_form]
					</div>
					<div class="seculoco-margin-top-8">
						<button type="button" class="button" onclick="navigator.clipboard.writeText('[seculoco_form]'); this.innerHTML = '<?php echo esc_js( __( 'Copied!', 'secure-login-collector' ) ); ?>'; setTimeout(() => { this.innerHTML = '<?php echo esc_js( __( 'Copy Shortcode', 'secure-login-collector' ) ); ?>'; }, 2000);">
							<?php echo esc_html__( 'Copy Shortcode', 'secure-login-collector' ); ?>
						</button>
					</div>
					<p class="seculoco-form-help seculoco-margin-top-8">
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
}
