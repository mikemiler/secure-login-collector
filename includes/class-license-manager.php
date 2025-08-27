<?php
/**
 * License Manager Class
 *
 * Handles Pro version licensing and activation
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Secure_Login_License_Manager
 *
 * Manages Pro version licensing
 */
class Secure_Login_License_Manager {

	/**
	 * License status transient key
	 */
	const LICENSE_TRANSIENT = 'secure_login_license_status';

	/**
	 * Check if Pro version is active
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		// Check transient first for performance
		$cached_status = get_transient( self::LICENSE_TRANSIENT );
		if ( false !== $cached_status ) {
			return 'active' === $cached_status;
		}

		// Verify license
		$license_key = get_option( 'secure_login_license_key' );
		if ( ! $license_key ) {
			return false;
		}

		$status = self::verify_license( $license_key );

		// Cache for 12 hours
		set_transient( self::LICENSE_TRANSIENT, $status, 12 * HOUR_IN_SECONDS );

		return 'active' === $status;
	}

	/**
	 * Activate license
	 *
	 * @param string $license_key License key to activate
	 * @return array Response with status and message
	 */
	public static function activate_license( $license_key ) {
		// Sanitize
		$license_key = sanitize_text_field( $license_key );

		// Basic validation
		if ( strlen( $license_key ) !== 32 ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid license key format', 'secure-login-collector' ),
			);
		}

		// Call activation API
		$response = wp_remote_post(
			'https://your-licensing-server.com/wp-json/license/v1/activate',
			array(
				'body'    => array(
					'license_key' => $license_key,
					'site_url'    => home_url(),
					'plugin'      => 'secure-login-collector',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not connect to licensing server', 'secure-login-collector' ),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['success'] ) ) {
			// Save license
			update_option( 'secure_login_license_key', $license_key );
			update_option( 'secure_login_license_email', $body['email'] ?? '' );

			// Clear cache
			delete_transient( self::LICENSE_TRANSIENT );

			return array(
				'success' => true,
				'message' => __( 'License activated successfully!', 'secure-login-collector' ),
			);
		}

		return array(
			'success' => false,
			'message' => $body['message'] ?? __( 'License activation failed', 'secure-login-collector' ),
		);
	}

	/**
	 * Deactivate license
	 *
	 * @return bool
	 */
	public static function deactivate_license() {
		$license_key = get_option( 'secure_login_license_key' );

		if ( $license_key ) {
			// Call deactivation API
			wp_remote_post(
				'https://your-licensing-server.com/wp-json/license/v1/deactivate',
				array(
					'body' => array(
						'license_key' => $license_key,
						'site_url'    => home_url(),
					),
				)
			);
		}

		// Clean up
		delete_option( 'secure_login_license_key' );
		delete_option( 'secure_login_license_email' );
		delete_transient( self::LICENSE_TRANSIENT );

		return true;
	}

	/**
	 * Verify license status
	 *
	 * @param string $license_key License key to verify
	 * @return string Status: 'active', 'expired', 'invalid'
	 */
	private static function verify_license( $license_key ) {
		$response = wp_remote_get(
			add_query_arg(
				array(
					'license_key' => $license_key,
					'site_url'    => home_url(),
				),
				'https://your-licensing-server.com/wp-json/license/v1/verify'
			),
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return 'invalid';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return $body['status'] ?? 'invalid';
	}

	/**
	 * AJAX handler for license activation
	 */
	public static function ajax_activate_license() {
		check_ajax_referer( 'secure_login_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
		}

		$license_key = sanitize_text_field( $_POST['license_key'] ?? '' );

		if ( empty( $license_key ) ) {
			wp_send_json_error( __( 'Please enter a license key', 'secure-login-collector' ) );
		}

		$result = self::activate_license( $license_key );

		if ( $result['success'] ) {
			wp_send_json_success( $result['message'] );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}
}

// Register AJAX handlers
add_action( 'wp_ajax_secure_login_activate_license', array( 'Secure_Login_License_Manager', 'ajax_activate_license' ) );
