<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Spam Protection Class
 *
 * Implements advanced bot protection using:
 * - Dynamic honeypot fields (rotated daily)
 * - Silent failure mechanism
 *
 * @package SecureLoginCollector
 * @since   1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Spam_Protection
 *
 * Provides bot protection through honeypot fields.
 *
 * @since 1.0.0
 */
class Seculoco_Spam_Protection {

	/**
	 * Transient key prefix for honeypot field name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const FIELD_NAME_TRANSIENT = 'seculoco_honeypot_field_name';

	/**
	 * Transient key prefix for honeypot class name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CLASS_NAME_TRANSIENT = 'seculoco_honeypot_class_name';

	/**
	 * Option key for honeypot settings.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SETTINGS_KEY = 'seculoco_spam_protection_settings';

	/**
	 * Default minimum time threshold (seconds).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_MIN_TIME = 2;

	/**
	 * Transient expiration for field name (24 hours).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const FIELD_NAME_EXPIRATION = DAY_IN_SECONDS;

	/**
	 * Constructor - Initialize spam protection.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Initialize settings if not exists.
		$this->initialize_settings();
	}

	/**
	 * Initialize default settings.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function initialize_settings() {
		$settings = get_option( self::SETTINGS_KEY );

		if ( false === $settings ) {
			$default_settings = array(
				'enabled'             => true,
				'min_time_threshold'  => self::DEFAULT_MIN_TIME,
				'log_blocked_attempts' => true,
			);
			update_option( self::SETTINGS_KEY, $default_settings );
		}
	}

	/**
	 * Get or generate honeypot field name.
	 *
	 * Field name is rotated daily to prevent bot learning.
	 * Uses WordPress transients for automatic expiration.
	 *
	 * @since 1.0.0
	 * @return string Random honeypot field name.
	 */
	public function get_honeypot_field_name() {
		$field_name = get_transient( self::FIELD_NAME_TRANSIENT );

		if ( false === $field_name ) {
			// Generate new random field name using WordPress function.
			// Use alphanumeric only to avoid potential issues with field names.
			$field_name = 'field_' . wp_generate_password( 12, false, false );

			// Store for 24 hours.
			set_transient( self::FIELD_NAME_TRANSIENT, $field_name, self::FIELD_NAME_EXPIRATION );
		}

		return $field_name;
	}

	/**
	 * Get or generate honeypot class name.
	 *
	 * Class name is rotated daily to prevent bot detection.
	 * Uses realistic class names that appear legitimate.
	 * Uses WordPress transients for automatic expiration.
	 *
	 * @since 1.0.0
	 * @return string Random honeypot class name.
	 */
	public function get_honeypot_class_name() {
		$class_name = get_transient( self::CLASS_NAME_TRANSIENT );

		if ( false === $class_name ) {
			// Pool of realistic class names that look like legitimate form fields.
			$class_patterns = array(
				'form-control',
				'form-input-text',
				'input-field',
				'text-input',
				'user-input',
				'field-group',
				'field-wrapper-box',
				'user-field-item',
				'input-text-box',
				'form-field-input',
				'text-field-wrapper',
				'input-group-field',
				'user-data-field',
				'form-text-input',
				'field-input-text',
				'input-wrapper-box',
				'text-input-field',
				'form-group-input',
				'field-text-box',
				'input-field-wrapper',
			);

			// Select random class name from pool.
			$class_name = $class_patterns[ array_rand( $class_patterns ) ];

			// Store for 24 hours (rotates daily).
			set_transient( self::CLASS_NAME_TRANSIENT, $class_name, self::FIELD_NAME_EXPIRATION );
		}

		return $class_name;
	}

	/**
	 * Generate honeypot HTML field.
	 *
	 * Creates a hidden field using inline CSS styles and randomized class names.
	 * Uses CSS positioning rather than display:none to avoid detection by bots.
	 * The class name is randomized daily but appears legitimate.
	 *
	 * @since 1.0.0
	 * @return string HTML for honeypot field.
	 */
	public function generate_honeypot_html() {
		$settings = get_option( self::SETTINGS_KEY );

		// Check if honeypot is enabled.
		if ( ! isset( $settings['enabled'] ) || ! $settings['enabled'] ) {
			return '';
		}

		$field_name = $this->get_honeypot_field_name();
		$class_name = $this->get_honeypot_class_name();

		// Use inline CSS styles to hide the field completely.
		// Position field off-screen but keep it in the DOM and accessible to screen readers.
		// Using !important to ensure styles cannot be overridden.
		$inline_styles = 'position:absolute !important;left:-9999px !important;width:1px !important;height:1px !important;overflow:hidden !important;clip:rect(0,0,0,0) !important;';

		// Use randomized class name that looks legitimate but hide with inline styles.
		$html = '<div class="' . esc_attr( $class_name ) . '" style="' . esc_attr( $inline_styles ) . '">';
		$html .= '<label for="' . esc_attr( $field_name ) . '">' . esc_html__( 'Website', 'secure-login-collector' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( $field_name ) . '" name="' . esc_attr( $field_name ) . '" value="" autocomplete="off" tabindex="-1" />';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Validate form submission against honeypot rules.
	 *
	 * Performs validations:
	 * 1. Honeypot field must exist
	 * 2. Honeypot field must be empty (bot check)
	 *
	 * @since 1.0.0
	 * @param array $post_data $_POST data from form submission.
	 * @return true|WP_Error True on success, WP_Error on validation failure.
	 */
	public function validate_submission( $post_data ) {
		$settings = get_option( self::SETTINGS_KEY );

		// Check if honeypot is enabled.
		if ( ! isset( $settings['enabled'] ) || ! $settings['enabled'] ) {
			return true;
		}

		$field_name = $this->get_honeypot_field_name();

		// Validation 1: Check if honeypot field exists in submission (should always be present).
		if ( ! isset( $post_data[ $field_name ] ) ) {
			// Field is missing entirely - likely bot or tampered form.
			$this->log_blocked_submission( 'honeypot_missing', $post_data );

			// Return generic error - don't reveal it's a honeypot.
			return new WP_Error(
				'validation_failed',
				__( 'Form submission failed validation. Please try again.', 'secure-login-collector' )
			);
		}

		// Validation 2: Check if honeypot field was filled (should be empty for legitimate users).
		if ( '' !== $post_data[ $field_name ] ) {
			// Honeypot field was filled - likely a bot.
			$this->log_blocked_submission( 'honeypot_filled', $post_data );

			// Return generic error - don't reveal it's a honeypot.
			return new WP_Error(
				'validation_failed',
				__( 'Form submission failed validation. Please try again.', 'secure-login-collector' )
			);
		}

		return true;
	}

	/**
	 * Get minimum time threshold from settings.
	 *
	 * @since 1.0.0
	 * @return int Minimum time threshold in seconds.
	 */
	public function get_minimum_time_threshold() {
		$settings = get_option( self::SETTINGS_KEY );

		if ( isset( $settings['min_time_threshold'] ) && is_numeric( $settings['min_time_threshold'] ) ) {
			return absint( $settings['min_time_threshold'] );
		}

		return self::DEFAULT_MIN_TIME;
	}

	/**
	 * Update honeypot settings.
	 *
	 * @since 1.0.0
	 * @param array $new_settings New settings to merge with existing.
	 * @return bool True on success, false on failure.
	 */
	public function update_settings( $new_settings ) {
		$current_settings = get_option( self::SETTINGS_KEY );

		$updated_settings = wp_parse_args( $new_settings, $current_settings );

		// Sanitize settings.
		$updated_settings['enabled'] = ! empty( $updated_settings['enabled'] );
		$updated_settings['log_blocked_attempts'] = ! empty( $updated_settings['log_blocked_attempts'] );

		if ( isset( $updated_settings['min_time_threshold'] ) ) {
			$updated_settings['min_time_threshold'] = absint( $updated_settings['min_time_threshold'] );

			// Ensure minimum threshold is at least 1 second.
			if ( $updated_settings['min_time_threshold'] < 1 ) {
				$updated_settings['min_time_threshold'] = 1;
			}
		}

		return update_option( self::SETTINGS_KEY, $updated_settings );
	}

	/**
	 * Get current honeypot settings.
	 *
	 * @since 1.0.0
	 * @return array Current settings.
	 */
	public function get_settings() {
		return get_option( self::SETTINGS_KEY );
	}

	/**
	 * Log blocked submission for admin review.
	 *
	 * Logs bot attempts for security monitoring and analysis.
	 * Keeps last 100 entries to prevent unlimited growth.
	 *
	 * @since 1.0.0
	 * @param string $reason      Reason for blocking.
	 * @param array  $post_data   Submitted POST data.
	 * @param int    $elapsed_time Optional elapsed time in seconds.
	 * @return void
	 */
	private function log_blocked_submission( $reason, $post_data, $elapsed_time = null ) {
		$settings = get_option( self::SETTINGS_KEY );

		// Check if logging is enabled.
		if ( ! isset( $settings['log_blocked_attempts'] ) || ! $settings['log_blocked_attempts'] ) {
			return;
		}

		$log = get_option( SECULOCO_OPTION_HONEYPOT_LOG, array() );

		// Keep only last 100 entries.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		$log_entry = array(
			'timestamp'    => time(),
			'datetime'     => current_time( 'mysql' ),
			'reason'       => $reason,
			'ip_address'   => $this->get_client_ip(),
			'user_agent'   => $this->get_user_agent(),
			'elapsed_time' => $elapsed_time,
		);

		// Add metadata if available (don't log sensitive fields).
		if ( isset( $post_data['sender_email'] ) ) {
			$log_entry['sender_email'] = sanitize_email( $post_data['sender_email'] );
		}

		$log[] = $log_entry;

		update_option( SECULOCO_OPTION_HONEYPOT_LOG, $log );

		/**
		 * Fires when a submission is blocked by spam protection.
		 *
		 * @since 1.0.0
		 * @param array $log_entry Log entry details.
		 */
		do_action( 'seculoco_honeypot_blocked', $log_entry );
	}

	/**
	 * Get blocked submissions log.
	 *
	 * @since 1.0.0
	 * @param int $limit Optional limit for number of entries to return.
	 * @return array Log entries.
	 */
	public function get_blocked_log( $limit = 50 ) {
		$log = get_option( SECULOCO_OPTION_HONEYPOT_LOG, array() );

		if ( $limit > 0 && count( $log ) > $limit ) {
			$log = array_slice( $log, -$limit );
		}

		// Return in reverse chronological order.
		return array_reverse( $log );
	}

	/**
	 * Clear blocked submissions log.
	 *
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function clear_blocked_log() {
		return delete_option( SECULOCO_OPTION_HONEYPOT_LOG );
	}

	/**
	 * Get statistics about blocked submissions.
	 *
	 * @since 1.0.0
	 * @return array Statistics including total blocks, reasons breakdown, etc.
	 */
	public function get_statistics() {
		$log = get_option( SECULOCO_OPTION_HONEYPOT_LOG, array() );

		$stats = array(
			'total_blocked'  => count( $log ),
			'reasons'        => array(),
			'last_24h'       => 0,
			'last_7d'        => 0,
			'unique_ips'     => array(),
		);

		$now = time();

		foreach ( $log as $entry ) {
			// Count by reason.
			$reason = $entry['reason'];
			if ( ! isset( $stats['reasons'][ $reason ] ) ) {
				$stats['reasons'][ $reason ] = 0;
			}
			$stats['reasons'][ $reason ]++;

			// Count recent blocks.
			$entry_time = $entry['timestamp'];
			if ( $now - $entry_time < DAY_IN_SECONDS ) {
				$stats['last_24h']++;
			}
			if ( $now - $entry_time < ( 7 * DAY_IN_SECONDS ) ) {
				$stats['last_7d']++;
			}

			// Track unique IPs.
			if ( isset( $entry['ip_address'] ) ) {
				$stats['unique_ips'][ $entry['ip_address'] ] = true;
			}
		}

		$stats['unique_ips'] = count( $stats['unique_ips'] );

		return $stats;
	}

	/**
	 * Get client IP address.
	 *
	 * Attempts to get real IP from various proxy headers.
	 *
	 * @since 1.0.0
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // CloudFlare.
			'HTTP_X_FORWARDED_FOR',  // Proxy.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'REMOTE_ADDR',           // Direct connection.
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) && ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

				// Handle comma-separated IPs (X-Forwarded-For).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip_list = explode( ',', $ip );
					$ip = trim( $ip_list[0] );
				}

				// Validate IP address.
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Get user agent string.
	 *
	 * @since 1.0.0
	 * @return string User agent string.
	 */
	private function get_user_agent() {
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		return '';
	}

	/**
	 * Check if spam protection is enabled.
	 *
	 * @since 1.0.0
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled() {
		$settings = get_option( self::SETTINGS_KEY );
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Reset honeypot field name (force regeneration).
	 *
	 * Useful for testing or security purposes.
	 *
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function reset_field_name() {
		return delete_transient( self::FIELD_NAME_TRANSIENT );
	}

	/**
	 * Reset honeypot class name (force regeneration).
	 *
	 * Useful for testing or security purposes.
	 *
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function reset_class_name() {
		return delete_transient( self::CLASS_NAME_TRANSIENT );
	}

	/**
	 * Get system status for admin display.
	 *
	 * @since 1.0.0
	 * @return array Status information.
	 */
	public function get_status() {
		$settings = get_option( self::SETTINGS_KEY );
		$field_name = get_transient( self::FIELD_NAME_TRANSIENT );
		$class_name = get_transient( self::CLASS_NAME_TRANSIENT );
		$stats = $this->get_statistics();

		return array(
			'enabled'              => ! empty( $settings['enabled'] ),
			'min_time_threshold'   => $this->get_minimum_time_threshold(),
			'logging_enabled'      => ! empty( $settings['log_blocked_attempts'] ),
			'current_field_name'   => $field_name ? $field_name : 'Not generated yet',
			'current_class_name'   => $class_name ? $class_name : 'Not generated yet',
			'total_blocked'        => $stats['total_blocked'],
			'blocked_last_24h'     => $stats['last_24h'],
			'blocked_last_7d'      => $stats['last_7d'],
			'unique_ips_blocked'   => $stats['unique_ips'],
		);
	}
}
