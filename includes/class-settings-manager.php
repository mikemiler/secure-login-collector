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
 * Class Secure_Login_Settings_Manager
 *
 * Handles all plugin settings and configuration.
 */
class Secure_Login_Settings_Manager {

	/**
	 * Whether pro version is enabled.
	 * Note: All features are now available to all users without license restrictions.
	 *
	 * @var bool
	 */
	private $is_pro_version;

	/**
	 * Encryption handler instance.
	 *
	 * @var Secure_Login_Encryption_Handler
	 */
	private $encryption_handler;

	/**
	 * Constructor - initializes settings manager.
	 *
	 * @param bool                            $is_pro_version     Whether pro version is enabled.
	 * @param Secure_Login_Encryption_Handler $encryption_handler Encryption handler instance.
	 */
	public function __construct( $is_pro_version, $encryption_handler ) {
		$this->is_pro_version     = $is_pro_version;
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

		// Register placeholder scripts for inline functionality.
		wp_register_script( 'seculoco-key-management', '', array( 'jquery' ), '1.0.0', true );
		wp_enqueue_script( 'seculoco-key-management' );

		wp_register_script( 'seculoco-textarea-toggle', '', array( 'jquery' ), '1.0.0', true );
		wp_enqueue_script( 'seculoco-textarea-toggle' );
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
			'secure_login_settings',
			'secure_login_notification_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			)
		);
		register_setting(
			'secure_login_settings',
			'secure_login_enable_notifications',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);
		register_setting(
			'secure_login_settings',
			'secure_login_expiration_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			'secure_login_settings',
			'secure_login_ultra_secure_mode',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);
		register_setting(
			'secure_login_settings',
			'secure_login_frontend_form_text',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_frontend_form_text' ),
			)
		);
		register_setting(
			'secure_login_settings',
			'secure_login_frontend_text_type',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			'secure_login_settings',
			'secure_login_delete_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);

		add_settings_section(
			'secure_login_notification_section',
			__( 'Email Notifications', 'secure-login-collector' ),
			array( $this, 'notification_section_callback' ),
			'secure_login_settings'
		);

		add_settings_section(
			'secure_login_frontend_section',
			__( 'Frontend Customization', 'secure-login-collector' ),
			array( $this, 'frontend_section_callback' ),
			'secure_login_settings'
		);

		add_settings_section(
			'secure_login_expiration_section',
			__( 'Data Expiration', 'secure-login-collector' ),
			array( $this, 'expiration_section_callback' ),
			'secure_login_settings'
		);

		// Add advanced security settings section (Pro version only).
		if ( $this->is_pro_version ) {
			add_settings_section(
				'secure_login_pro_section',
				__( 'Advanced Security Settings', 'secure-login-collector' ),
				array( $this, 'pro_section_callback' ),
				'secure_login_settings'
			);

			add_settings_field(
				'secure_login_ultra_secure_mode',
				__( 'Ultra-Secure Mode', 'secure-login-collector' ),
				array( $this, 'ultra_secure_mode_callback' ),
				'secure_login_settings',
				'secure_login_pro_section'
			);
		}

		// Add encryption settings section (for all users now).
		add_settings_section(
			'secure_login_encryption_section',
			__( 'Encryption Settings', 'secure-login-collector' ),
			array( $this, 'encryption_section_callback' ),
			'secure_login_settings'
		);

		// Add plugin management section.
		add_settings_section(
			'secure_login_plugin_management_section',
			__( 'Plugin Management', 'secure-login-collector' ),
			array( $this, 'plugin_management_section_callback' ),
			'secure_login_settings'
		);

		add_settings_field(
			'secure_login_delete_on_uninstall',
			__( 'Delete Data on Uninstall', 'secure-login-collector' ),
			array( $this, 'delete_on_uninstall_callback' ),
			'secure_login_settings',
			'secure_login_plugin_management_section'
		);

		add_settings_field(
			'secure_login_enable_notifications',
			__( 'Enable Email Notifications', 'secure-login-collector' ),
			array( $this, 'enable_notifications_callback' ),
			'secure_login_settings',
			'secure_login_notification_section'
		);

		add_settings_field(
			'secure_login_notification_email',
			__( 'Notification Email Address', 'secure-login-collector' ),
			array( $this, 'notification_email_callback' ),
			'secure_login_settings',
			'secure_login_notification_section'
		);

		add_settings_field(
			'secure_login_frontend_text_type',
			__( 'Text Type', 'secure-login-collector' ),
			array( $this, 'frontend_text_type_callback' ),
			'secure_login_settings',
			'secure_login_frontend_section'
		);

		add_settings_field(
			'secure_login_frontend_form_text',
			__( 'Custom Description Text', 'secure-login-collector' ),
			array( $this, 'frontend_form_text_callback' ),
			'secure_login_settings',
			'secure_login_frontend_section'
		);

		add_settings_field(
			'secure_login_expiration_days',
			__( 'Auto-Delete After (Days)', 'secure-login-collector' ),
			array( $this, 'expiration_days_callback' ),
			'secure_login_settings',
			'secure_login_expiration_section'
		);
	}

	/**
	 * Notification settings section callback.
	 */
	public function notification_section_callback() {
		echo '<div class="slc-card" style="margin-top: 20px;">';
		echo '<div class="slc-card-header">';
		echo '<h3 class="slc-card-title">';
		echo esc_html__( 'Email Notifications', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="slc-card-body">';
		echo '<p>' . esc_html__( 'Configure email notifications for new login data submissions.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Frontend settings section callback.
	 */
	public function frontend_section_callback() {
		echo '<div class="slc-card" style="margin-top: 20px;">';
		echo '<div class="slc-card-header">';
		echo '<h3 class="slc-card-title">';
		echo esc_html__( 'Frontend Form Settings', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="slc-card-body">';
		echo '<p>' . esc_html__( 'Customize the frontend form appearance and text.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Expiration settings section callback.
	 */
	public function expiration_section_callback() {
		echo '<div class="slc-card" style="margin-top: 20px;">';
		echo '<div class="slc-card-header">';
		echo '<h3 class="slc-card-title">';
		echo esc_html__( 'Data Retention Settings', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="slc-card-body">';
		echo '<p>' . esc_html__( 'Configure automatic deletion of old login data.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Encryption settings section callback.
	 */
	public function encryption_section_callback() {
		echo '<div class="slc-card" style="margin-top: 20px;">';
		echo '<div class="slc-card-header">';
		echo '<h3 class="slc-card-title">' . esc_html__( 'Encryption Settings', 'secure-login-collector' ) . '</h3>';
		echo '</div>';
		echo '<div class="slc-card-body">';
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

			// Export free public key
			$('#export-free-public-key').on('click', function() {
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'seculoco_export_public_key',
						key_type: 'free',
						nonce: '" . esc_js( $nonce ) . "'
					},
					success: function(response) {
						if (response.success) {
							var blob = new Blob([response.data.public_key], {type: 'text/plain'});
							var url = window.URL.createObjectURL(blob);
							var a = document.createElement('a');
							a.href = url;
							a.download = 'secure-login-free-public-key.pem';
							a.click();
							window.URL.revokeObjectURL(url);
						} else {
							alert('" . esc_js( __( 'Failed to export public key:', 'secure-login-collector' ) ) . "' + response.data);
						}
					}
				});
			});

			// Export pro public key
			$('#export-pro-public-key').on('click', function() {
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'seculoco_export_public_key',
						key_type: 'pro',
						nonce: '" . esc_js( $nonce ) . "'
					},
					success: function(response) {
						if (response.success) {
							var blob = new Blob([response.data.public_key], {type: 'text/plain'});
							var url = window.URL.createObjectURL(blob);
							var a = document.createElement('a');
							a.href = url;
							a.download = 'secure-login-pro-public-key.pem';
							a.click();
							window.URL.revokeObjectURL(url);
						} else {
							alert('" . esc_js( __( 'Failed to export public key:', 'secure-login-collector' ) ) . "' + response.data);
						}
					}
				});
			});
		});
		";

		wp_add_inline_script( 'seculoco-key-management', $script );
	}

	/**
	 * Display encryption content inside the card
	 */
	private function display_encryption_content() {
		echo '<div class="slc-passkey-benefits">';

		// RSA-2048 (Free version).
		echo '<div class="slc-passkey-benefit" style="border-color: var(--slc-info);">';
		echo '<span class="slc-passkey-benefit-icon"></span>';
		echo '<div class="slc-passkey-benefit-text">';
		echo '<div class="slc-passkey-benefit-title">';
		echo '<span class="slc-badge slc-badge-info">SECURE</span> ';
		echo esc_html__( 'RSA-2048 + AES-256-GCM', 'secure-login-collector' );
		echo '</div>';
		echo '<div class="slc-passkey-benefit-desc">' . esc_html__( 'Industry-standard RSA encryption with 2048-bit keys and AES-256-GCM for data encryption. Secure for most use cases.', 'secure-login-collector' ) . '</div>';
		echo '</div>';
		echo '</div>'; // Close slc-passkey-benefit

		// Ultra-Secure (Pro version only).
		if ( ! $this->is_pro_version ) {
			echo '<div class="slc-passkey-benefit" style="border-color: #ccc; opacity: 0.7;">';
			echo '<span class="slc-passkey-benefit-icon"></span>';
			echo '<div class="slc-passkey-benefit-text">';
			echo '<div class="slc-passkey-benefit-title">';
			echo '<span class="slc-badge" style="background: #ccc; color: #666;">PRO ONLY</span> ';
			echo esc_html__( 'Ultra-Secure (Passkey-Protected)', 'secure-login-collector' );
			echo '</div>';
			echo '<div class="slc-passkey-benefit-desc">' . esc_html__( 'Passkey-protected encryption with WebAuthn/FIDO2. True zero-knowledge - server cannot decrypt without your physical device.', 'secure-login-collector' ) . '</div>';
			echo '<div style="margin-top: 8px;"><a href="#" class="button button-secondary">' . esc_html__( 'Upgrade to Pro', 'secure-login-collector' ) . '</a></div>';
			echo '</div>';
			echo '</div>'; // Close slc-passkey-benefit
		} else {
			echo '<div class="slc-passkey-benefit" style="border-color: var(--slc-success);">';
			echo '<span class="slc-passkey-benefit-icon"></span>';
			echo '<div class="slc-passkey-benefit-text">';
			echo '<div class="slc-passkey-benefit-title">';
			echo '<span class="slc-badge slc-badge-success">ULTRA-SECURE</span> ';
			echo esc_html__( 'Passkey-Protected', 'secure-login-collector' );
			echo '</div>';
			echo '<div class="slc-passkey-benefit-desc">' . esc_html__( 'Wraps private keys with passkey authentication. True zero-knowledge - server cannot decrypt without your physical device.', 'secure-login-collector' ) . '</div>';
			echo '</div>';
			echo '</div>'; // Close slc-passkey-benefit
		}

		echo '</div>'; // Close slc-passkey-benefits

		// Display key status.
		$free_public_key  = get_option( 'secure_login_public_key_free' );
		$free_private_key = get_option( 'secure_login_private_key_free_encrypted' );

		// Display RSA Keys Status.
		echo '<div class="rsa-keys-status" style="margin: 20px 0;">';
		echo '<h4 style="margin-bottom: 15px;">' . esc_html__( 'RSA Keys Status', 'secure-login-collector' ) . '</h4>';

		// Free RSA Keys Status.
		echo '<div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
		echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
		echo '<div>';
		echo '<strong style="font-size: 14px;">' . esc_html__( 'Standard RSA Keys', 'secure-login-collector' ) . '</strong>';
		echo '<p style="margin: 5px 0 0; color: #666; font-size: 12px;">' . esc_html__( 'RSA-2048 + AES-256-GCM encryption', 'secure-login-collector' ) . '</p>';
		echo '</div>';

		if ( $free_public_key && $free_private_key ) {
			echo '<div style="text-align: right;">';
			echo '<span style="background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: 600;">' . esc_html__( 'ACTIVE', 'secure-login-collector' ) . '</span>';
			echo '</div>';
		} else {
			echo '<div style="text-align: right;">';
			echo '<span style="background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: 600;">' . esc_html__( 'NOT INITIALIZED', 'secure-login-collector' ) . '</span>';
			echo '<p style="margin: 5px 0 0; font-size: 11px; color: #666;">' . esc_html__( 'Will be created on first use', 'secure-login-collector' ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		// Pro version keys status (only show if pro is active).
		if ( $this->is_pro_version ) {
			$pro_public_key     = get_option( 'secure_login_public_key_pro' );
			$pro_private_key    = get_option( 'secure_login_wrapped_private_key_pro' );
			$pro_keys_active    = get_option( 'secure_login_pro_keys_active', false );
			$passkey_registered = get_option( 'secure_login_passkey_registered', false );

			echo '<div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">';
			echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
			echo '<div>';
			echo '<strong style="font-size: 14px;">' . esc_html__( 'Ultra-Secure RSA Keys', 'secure-login-collector' ) . '</strong>';
			echo '<p style="margin: 5px 0 0; color: #666; font-size: 12px;">' . esc_html__( 'Passkey-protected RSA-2048 for ultra-secure encryption', 'secure-login-collector' ) . '</p>';
			echo '</div>';

			if ( $pro_public_key && $pro_private_key && $pro_keys_active ) {
				echo '<div style="text-align: right;">';
				echo '<span style="background: #d1ecf1; color: #0c5460; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: 600;">' . esc_html__( 'ACTIVE', 'secure-login-collector' ) . '</span>';
				echo '<p style="margin: 5px 0 0; font-size: 11px; color: #666;">' . esc_html__( 'Using passkey protection', 'secure-login-collector' ) . '</p>';
				echo '</div>';
			} elseif ( $passkey_registered ) {
				echo '<div style="text-align: right;">';
				echo '<span style="background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: 600;">' . esc_html__( 'NEEDS INITIALIZATION', 'secure-login-collector' ) . '</span>';
				echo '<p style="margin: 5px 0 0; font-size: 11px; color: #666;">' . esc_html__( 'Passkey registered but keys not initialized', 'secure-login-collector' ) . '</p>';
				echo '</div>';
			} else {
				echo '<div style="text-align: right;">';
				echo '<span style="background: #f8f9fa; color: #6c757d; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: 600;">' . esc_html__( 'NOT AVAILABLE', 'secure-login-collector' ) . '</span>';
				echo '<p style="margin: 5px 0 0; font-size: 11px; color: #666;">' . esc_html__( 'Register passkey to enable', 'secure-login-collector' ) . '</p>';
				echo '</div>';
			}
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';

		// Key management section.
		echo '<div class="notice notice-info inline">';
		echo '<p><strong>' . esc_html__( 'Key Management:', 'secure-login-collector' ) . '</strong></p>';

		// Show different messages based on key status.
		if ( ! $free_public_key ) {
			echo '<p>' . esc_html__( 'RSA keys will be automatically initialized on first form submission.', 'secure-login-collector' ) . '</p>';
			echo '<p><button type="button" class="button button-primary" id="initialize-free-keys">' . esc_html__( 'Initialize Keys Now', 'secure-login-collector' ) . '</button></p>';
		} else {
			echo '<p>' . esc_html__( 'RSA keys are active and ready for use.', 'secure-login-collector' ) . '</p>';
		}

		// Pro version key management messages.
		if ( $this->is_pro_version ) {
			$pro_public_key     = get_option( 'secure_login_public_key_pro' );
			$passkey_registered = get_option( 'secure_login_passkey_registered', false );

			if ( ! $pro_public_key && $passkey_registered ) {
				echo '<p>' . esc_html__( 'Ultra-secure RSA keys need to be initialized with your passkey.', 'secure-login-collector' ) . '</p>';
			} elseif ( ! $passkey_registered ) {
				echo '<p>' . esc_html__( 'To enable ultra-secure encryption, register a passkey in the Pro settings.', 'secure-login-collector' ) . '</p>';
			} elseif ( $pro_public_key ) {
				echo '<p>' . esc_html__( 'Ultra-secure RSA keys are active and ready for use.', 'secure-login-collector' ) . '</p>';
			}
		}

		echo '</div>';

		// Export buttons based on available keys.
		echo '<p>';
		if ( $free_public_key ) {
			echo '<button type="button" class="button button-secondary" id="export-free-public-key">' . esc_html__( 'Export Public Key', 'secure-login-collector' ) . '</button> ';
		}
		if ( $this->is_pro_version ) {
			$pro_public_key = get_option( 'secure_login_public_key_pro' );
			if ( $pro_public_key ) {
				echo '<button type="button" class="button button-secondary" id="export-pro-public-key">' . esc_html__( 'Export Pro Public Key', 'secure-login-collector' ) . '</button>';
			}
		}
		echo '</p>';

		// Passkey Management Section (Pro version only).
		if ( $this->is_pro_version ) {
			if ( class_exists( 'Passkey_Manager__premium_only' ) ) {
				$passkey_manager = new Passkey_Manager__premium_only();
				$passkey_manager->render_passkey_section();
			}
		} else {
			// Show upgrade message for passkey features.
			echo '<div class="notice notice-warning inline" style="margin-top: 20px;">';
			echo '<p><strong>' . esc_html__( 'Want Ultra-Secure Encryption?', 'secure-login-collector' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Upgrade to Pro to enable passkey-protected encryption with WebAuthn/FIDO2 authentication for true zero-knowledge security.', 'secure-login-collector' ) . '</p>';
			echo '<p><a href="#" class="button button-primary">' . esc_html__( 'Upgrade to Pro Version', 'secure-login-collector' ) . '</a></p>';
			echo '</div>';
		}

		// Add JavaScript for key management via wp_add_inline_script.
		$this->add_key_management_inline_script();
	}

	/**
	 * Advanced security settings section callback.
	 */
	public function pro_section_callback() {
		echo '<div class="slc-card" style="margin-top: 20px;">';
		echo '<div class="slc-card-header">';
		echo '<h3 class="slc-card-title">';
		echo esc_html__( 'Advanced Security Features', 'secure-login-collector' );
		echo '</h3>';
		echo '<span class="slc-badge slc-badge-success">ADVANCED</span>';
		echo '</div>';
		echo '<div class="slc-card-body">';
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
		$enabled = get_option( 'secure_login_enable_notifications', false );
		echo '<input type="checkbox" id="secure_login_enable_notifications" name="secure_login_enable_notifications" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="secure_login_enable_notifications"> ' . esc_html__( 'Send email notifications when new login data is received', 'secure-login-collector' ) . '</label>';
	}

	/**
	 * Notification email field callback.
	 */
	public function notification_email_callback() {
		$email = get_option( 'secure_login_notification_email', get_option( 'admin_email' ) );
		echo '<input type="email" id="secure_login_notification_email" name="secure_login_notification_email" value="' . esc_attr( $email ) . '" class="regular-text" />';
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
			var originalText = $('#secure_login_frontend_form_text').val();

			function toggleTextarea() {
				var selectedType = $('input[name=\"secure_login_frontend_text_type\"]:checked').val();
				var textarea = $('#secure_login_frontend_form_text');

				if (selectedType === 'default') {
					textarea.prop('disabled', true).css('background-color', '#f1f1f1');
					if (textarea.val() === '' || textarea.val() === defaultText) {
						textarea.val(defaultText);
					}
				} else {
					textarea.prop('disabled', false).css('background-color', '#fff');
				}
			}

			toggleTextarea();
			$('input[name=\"secure_login_frontend_text_type\"]').on('change', toggleTextarea);
		});
		";

		wp_add_inline_script( 'seculoco-textarea-toggle', $script );
	}

	/**
	 * Frontend form text field callback.
	 */
	public function frontend_form_text_callback() {
		$text      = get_option( 'secure_login_frontend_form_text', '' );
		$text_type = get_option( 'secure_login_frontend_text_type', 'default' );

		// Generate the default text with placeholder for dynamic expiration text.
		$default_text  = '<p><strong>' . __( 'What happens to your data:', 'secure-login-collector' ) . '</strong> ' . __( 'Your login data is encrypted in your browser before being sent to our server. We use strong RSA-2048 encryption to ensure maximum security.', 'secure-login-collector' ) . '</p>';
		$default_text .= '<p><strong>' . __( 'Security & Privacy:', 'secure-login-collector' ) . '</strong> ' . __( 'Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely{EXPIRATION_TEXT}.', 'secure-login-collector' ) . '</p>';

		// If no custom text is set, show the default text.
		$display_text = ! empty( $text ) ? $text : $default_text;
		$is_disabled  = ( 'default' === $text_type ) ? 'disabled' : '';

		echo '<textarea id="secure_login_frontend_form_text" name="secure_login_frontend_form_text" rows="6" class="large-text" style="width: 100%;" ' . esc_attr( $is_disabled ) . '>' . esc_textarea( $display_text ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Custom text to display above the login form. Basic HTML allowed (p, strong, em, br, a). This field is automatically populated with the default text when no custom text is provided. Use {EXPIRATION_TEXT} placeholder for automatic expiration information.', 'secure-login-collector' ) . '</p>';

		// Add JavaScript for radio button interaction via wp_add_inline_script.
		$this->add_textarea_toggle_inline_script( $default_text );
	}

	/**
	 * Frontend text type field callback.
	 */
	public function frontend_text_type_callback() {
		$text_type = get_option( 'secure_login_frontend_text_type', 'default' );

		echo '<fieldset>';
		echo '<label>';
		echo '<input type="radio" name="secure_login_frontend_text_type" value="default" ' . checked( 'default', $text_type, false ) . '> ';
		echo esc_html__( 'Default (Automatic encryption & expiration details)', 'secure-login-collector' );
		echo '</label><br><br>';

		echo '<label>';
		echo '<input type="radio" name="secure_login_frontend_text_type" value="custom" ' . checked( 'custom', $text_type, false ) . '> ';
		echo esc_html__( 'Custom Text (Use the text below)', 'secure-login-collector' );
		echo '</label>';
		echo '</fieldset>';

		echo '<p class="description">' . esc_html__( 'Choose whether to use the default security information text or your custom text below.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Expiration days field callback.
	 */
	public function expiration_days_callback() {
		$days = get_option( 'secure_login_expiration_days', 30 );
		echo '<input type="number" id="secure_login_expiration_days" name="secure_login_expiration_days" value="' . esc_attr( $days ) . '" min="0" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Number of days after which login data will be automatically deleted. Set to 0 to disable automatic deletion (data will be retained until manually deleted).', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Ultra secure mode field callback.
	 */
	public function ultra_secure_mode_callback() {
		$enabled = get_option( 'secure_login_ultra_secure_mode', false );
		echo '<input type="checkbox" id="secure_login_ultra_secure_mode" name="secure_login_ultra_secure_mode" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="secure_login_ultra_secure_mode"> ' . esc_html__( 'Enable passkey-protected encryption (zero-knowledge)', 'secure-login-collector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, the RSA private key is wrapped with passkey authentication. This provides true zero-knowledge - the server cannot decrypt data without your physical passkey device. Requires passkey registration.', 'secure-login-collector' ) . '</p>';

		$passkey_registered = get_option( 'secure_login_passkey_registered', false );
		if ( ! $passkey_registered ) {
			echo '<div class="slc-alert slc-alert-warning" style="margin-top: 12px;">';
			echo '<span class="slc-alert-icon"></span>';
			echo '<div class="slc-alert-content">';
			echo '<div class="slc-alert-message">';
			echo '<strong>' . esc_html__( 'Warning:', 'secure-login-collector' ) . '</strong> ' . esc_html__( 'You must register a passkey before enabling ultra-secure mode.', 'secure-login-collector' );
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}
	}

	/**
	 * Plugin management section callback.
	 */
	public function plugin_management_section_callback() {
		echo '<div class="slc-card" style="margin-top: 20px;">';
		echo '<div class="slc-card-header">';
		echo '<h3 class="slc-card-title">';
		echo esc_html__( 'Plugin Management', 'secure-login-collector' );
		echo '</h3>';
		echo '</div>';
		echo '<div class="slc-card-body">';
		echo '<p>' . esc_html__( 'Configure how the plugin handles data when it is uninstalled.', 'secure-login-collector' ) . '</p>';
		// Don't close the card-body div here - let the form-table be inside it.
	}

	/**
	 * Delete on uninstall field callback.
	 */
	public function delete_on_uninstall_callback() {
		$enabled = get_option( 'secure_login_delete_on_uninstall', false );
		echo '<input type="checkbox" id="secure_login_delete_on_uninstall" name="secure_login_delete_on_uninstall" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="secure_login_delete_on_uninstall"> ' . esc_html__( 'Completely remove all plugin data when uninstalling', 'secure-login-collector' ) . '</label>';
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
		<div class="wrap slc-admin-wrap">
			<h1><?php echo esc_html__( 'Secure Login Collector Settings', 'secure-login-collector' ); ?></h1>
			
			<!-- Shortcode Display Section -->
			<div class="slc-card">
				<div class="slc-card-header">
					<h3 class="slc-card-title">
						
						<?php echo esc_html__( 'Frontend Form Shortcode', 'secure-login-collector' ); ?>
					</h3>
					<span class="slc-badge slc-badge-info"><?php echo esc_html__( 'Required', 'secure-login-collector' ); ?></span>
				</div>
				<div class="slc-card-body">
					<p><?php echo esc_html__( 'Use this shortcode to display the secure login form on any page or post:', 'secure-login-collector' ); ?></p>
					<div style="background: var(--slc-bg-light); padding: 12px 16px; border-radius: var(--slc-radius-sm); display: inline-block; font-family: 'Monaco', 'Menlo', monospace; font-size: 16px; border: 2px solid var(--slc-border); font-weight: 500;">
						[secure_login_form]
					</div>
					<div style="margin-top: 12px;">
						<button type="button" class="button" onclick="navigator.clipboard.writeText('[secure_login_form]'); this.innerHTML = '<?php echo esc_js( __( 'Copied!', 'secure-login-collector' ) ); ?>'; setTimeout(() => { this.innerHTML = '<?php echo esc_js( __( 'Copy Shortcode', 'secure-login-collector' ) ); ?>'; }, 2000);">
							<?php echo esc_html__( 'Copy Shortcode', 'secure-login-collector' ); ?>
						</button>
					</div>
					<p class="slc-form-help" style="margin-top: 16px;">
						<?php echo esc_html__( 'Simply paste this shortcode into any page or post where you want clients to submit their login credentials.', 'secure-login-collector' ); ?>
					</p>
				</div>
			</div>
			
			<form method="post" action="options.php" class="slc-settings-form">
				<div class="slc-settings-sections">
					<?php
					settings_fields( 'secure_login_settings' );

					// Custom rendering of settings sections to properly wrap in cards.
					global $wp_settings_sections, $wp_settings_fields;
					$page = 'secure_login_settings';

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
		$text_type = isset( $_POST['secure_login_frontend_text_type'] ) ? sanitize_text_field( wp_unslash( $_POST['secure_login_frontend_text_type'] ) ) : 'default';

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
