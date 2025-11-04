<?php
/**
 * @fs_premium_only
 *
 * Premium Settings Manager
 * Extends free version with pro features via hooks
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Settings_Manager_Pro
 *
 * Handles pro-specific settings functionality via hooks.
 */
class Seculoco_Settings_Manager_Pro {

	/**
	 * Encryption handler instance.
	 *
	 * @var Seculoco_Encryption_Handler_V2
	 */
	private $encryption_handler;

	/**
	 * Constructor - hooks into free version's actions/filters.
	 */
	public function __construct() {
		// Store encryption handler reference.
		add_action( 'seculoco_encryption_handler_ready', array( $this, 'set_encryption_handler' ) );

		// Register pro settings.
		add_action( 'seculoco_register_settings', array( $this, 'register_pro_settings' ) );

		// Add pro settings sections.
		add_action( 'seculoco_add_settings_sections', array( $this, 'add_pro_settings_sections' ) );

		// Replace free version's pro column with actual pro content.
		add_filter( 'seculoco_encryption_pro_column', array( $this, 'get_pro_encryption_column' ) );

		// Filter service footer setting content to replace with actual control.
		add_filter( 'seculoco_hide_service_footer_setting_content', array( $this, 'replace_service_footer_setting_content' ) );

		// Filter frontend service footer visibility based on Pro setting.
		add_filter( 'seculoco_show_service_footer', array( $this, 'filter_service_footer_visibility' ) );
	}

	/**
	 * Store encryption handler reference when it's ready.
	 *
	 * @param Seculoco_Encryption_Handler_V2 $handler Encryption handler instance.
	 */
	public function set_encryption_handler( $handler ) {
		$this->encryption_handler = $handler;
	}

	/**
	 * Register pro settings.
	 */
	public function register_pro_settings() {
		register_setting(
			'seculoco_settings',
			'seculoco_ultra_secure_mode',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			)
		);
	}

	/**
	 * Add pro settings sections.
	 */
	public function add_pro_settings_sections() {
		// Add advanced security settings section.
		add_settings_section(
			'seculoco_pro_section',
			__( 'Advanced Security Settings', 'secure-login-collector' ),
			array( $this, 'pro_section_callback' ),
			'seculoco_settings'
		);

		add_settings_field(
			'seculoco_ultra_secure_mode',
			__( 'Ultra-Secure Mode', 'secure-login-collector' ),
			array( $this, 'ultra_secure_mode_callback' ),
			'seculoco_settings',
			'seculoco_pro_section'
		);
	}

	/**
	 * Advanced security settings section callback.
	 */
	public function pro_section_callback() {
		echo '<div class="seculoco-card" style="margin-top: 20px;">';
		echo '<div class="seculoco-card-header">';
		echo '<h3 class="seculoco-card-title">';
		echo esc_html__( 'Advanced Security Features', 'secure-login-collector' );
		echo '</h3>';
		echo '<span class="seculoco-badge seculoco-badge-success">PRO</span>';
		echo '</div>';
		echo '<div class="seculoco-card-body">';
		echo '<p>' . esc_html__( 'Advanced security settings including passkey authentication for enhanced protection.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Ultra secure mode field callback.
	 */
	public function ultra_secure_mode_callback() {
		$enabled = get_option( 'seculoco_ultra_secure_mode', false );
		echo '<input type="checkbox" id="seculoco_ultra_secure_mode" name="seculoco_ultra_secure_mode" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="seculoco_ultra_secure_mode"> ' . esc_html__( 'Enable passkey-protected encryption (zero-knowledge)', 'secure-login-collector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, the RSA private key is wrapped with passkey authentication. This provides true zero-knowledge - the server cannot decrypt data without your physical passkey device. Requires passkey registration.', 'secure-login-collector' ) . '</p>';

		$passkey_registered = get_option( 'seculoco_passkey_registered', false );
		if ( ! $passkey_registered ) {
			echo '<div class="seculoco-alert seculoco-alert-warning" style="margin-top: 12px;">';
			echo '<span class="seculoco-alert-icon"></span>';
			echo '<div class="seculoco-alert-content">';
			echo '<div class="seculoco-alert-message">';
			echo '<strong>' . esc_html__( 'Warning:', 'secure-login-collector' ) . '</strong> ' . esc_html__( 'You must register a passkey before enabling ultra-secure mode.', 'secure-login-collector' );
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}
	}

	/**
	 * Get the pro encryption column HTML (right column).
	 * Replaces the upgrade notice with actual pro features.
	 *
	 * @return string HTML for the pro column.
	 */
	public function get_pro_encryption_column() {
		ob_start();
		?>
		<div class="seculoco-encryption-column">
			
			<?php $this->render_pro_key_status(); ?>
			<?php $this->render_pro_export_button(); ?>
			<?php $this->render_passkey_management(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render pro key status box.
	 */
	private function render_pro_key_status() {
		$pro_public_key     = get_option( 'seculoco_public_key_pro' );
		$pro_private_key    = get_option( 'seculoco_wrapped_private_key_pro' );
		$pro_keys_active    = get_option( 'seculoco_pro_keys_active', false );
		$passkey_registered = get_option( 'seculoco_passkey_registered', false );

		echo '<div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
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

	/**
	 * Render pro export button.
	 */
	private function render_pro_export_button() {
		$pro_public_key = get_option( 'seculoco_public_key_pro' );
		if ( $pro_public_key ) {
			echo '<p>';
			echo '<button type="button" class="button button-secondary" id="export-pro-public-key">' . esc_html__( 'Export Pro Public Key', 'secure-login-collector' ) . '</button>';
			echo '</p>';
		}
	}

	/**
	 * Render passkey management section.
	 */
	private function render_passkey_management() {
		if ( class_exists( 'Seculoco_Passkey_Manager' ) ) {
			$passkey_manager = new Seculoco_Passkey_Manager();
			$passkey_manager->render_passkey_section();
		}
	}

	/**
	 * Add pro key management messages.
	 */
	public function add_pro_key_management_messages() {
		$pro_public_key     = get_option( 'seculoco_public_key_pro' );
		$passkey_registered = get_option( 'seculoco_passkey_registered', false );

		if ( ! $pro_public_key && $passkey_registered ) {
			echo '<p>' . esc_html__( 'Ultra-secure RSA keys need to be initialized with your passkey.', 'secure-login-collector' ) . '</p>';
		} elseif ( ! $passkey_registered ) {
			echo '<p>' . esc_html__( 'To enable ultra-secure encryption, register a passkey in the Pro settings.', 'secure-login-collector' ) . '</p>';
		} elseif ( $pro_public_key ) {
			echo '<p>' . esc_html__( 'Ultra-secure RSA keys are active and ready for use.', 'secure-login-collector' ) . '</p>';
		}
	}

	/**
	 * Add pro export button.
	 */
	public function add_pro_export_button() {
		$pro_public_key = get_option( 'seculoco_public_key_pro' );
		if ( $pro_public_key ) {
			echo '<button type="button" class="button button-secondary" id="export-pro-public-key">' . esc_html__( 'Export Pro Public Key', 'secure-login-collector' ) . '</button>';
		}
	}



	/**
	 * Replace the free version's informational text with actual setting control (Pro only).
	 * Hooks into 'seculoco_hide_service_footer_setting_content' filter.
	 *
	 * @param string $content The default free version content (ignored in Pro).
	 * @return string The Pro version HTML with actual checkbox control.
	 */
	public function replace_service_footer_setting_content( $content ) {
		$hidden = get_option( 'seculoco_hide_service_footer', false );

		$pro_content  = '<input type="checkbox" id="seculoco_hide_service_footer" name="seculoco_hide_service_footer" value="1" ' . checked( 1, $hidden, false ) . ' />';
		$pro_content .= '<label for="seculoco_hide_service_footer"> ' . esc_html__( 'Hide branding footer on frontend form', 'secure-login-collector' ) . '</label>';
		$pro_content .= '<p class="description">' . esc_html__( 'When enabled, the branding footer will be hidden from the frontend form.', 'secure-login-collector' ) . '</p>';

		return $pro_content;
	}

	/**
	 * Filter service footer visibility based on Pro setting.
	 * Hooks into 'seculoco_show_service_footer' filter.
	 *
	 * @param bool $show_footer Default visibility (true from free version).
	 * @return bool Whether to show the service footer.
	 */
	public function filter_service_footer_visibility( $show_footer ) {
		// Check if Pro user has enabled the hide setting.
		$hide_footer = get_option( 'seculoco_hide_service_footer', false );

		// If setting is enabled, return false to hide footer.
		if ( $hide_footer ) {
			return false;
		}

		// Otherwise, respect the default (show footer).
		return $show_footer;
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
}

// Initialize pro settings manager.
new Seculoco_Settings_Manager_Pro();
