<?php
/**
 * Freemius Initialization Check
 *
 * This file ensures Freemius is properly initialized
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Freemius is properly loaded and handle errors gracefully
 */
function slc_check_freemius_loaded() {
	if ( ! function_exists( 'slc_fs' ) ) {
		add_action( 'admin_notices', 'slc_freemius_not_loaded_notice' );
		return false;
	}

	try {
		// Test if Freemius object is accessible.
		$fs = slc_fs();
		if ( ! is_object( $fs ) ) {
			add_action( 'admin_notices', 'slc_freemius_init_error_notice' );
			return false;
		}
	} catch ( Exception $e ) {
		add_action( 'admin_notices', 'slc_freemius_init_error_notice' );
		return false;
	}

	return true;
}

/**
 * Admin notice when Freemius SDK is not loaded
 */
function slc_freemius_not_loaded_notice() {
	if ( current_user_can( 'manage_options' ) ) {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Secure Login Collector:', 'secure-login-collector' ); ?></strong>
				<?php esc_html_e( 'Freemius SDK is not loaded. Please ensure the /vendor/freemius/ directory exists with the SDK files.', 'secure-login-collector' ); ?>
			</p>
		</div>
		<?php
	}
}

/**
 * Admin notice when Freemius initialization fails
 */
function slc_freemius_init_error_notice() {
	if ( current_user_can( 'manage_options' ) ) {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Secure Login Collector:', 'secure-login-collector' ); ?></strong>
				<?php esc_html_e( 'Freemius initialization failed. Please check your configuration.', 'secure-login-collector' ); ?>
			</p>
		</div>
		<?php
	}
}

// Run the check on admin_init.
add_action( 'admin_init', 'slc_check_freemius_loaded' );

/**
 * Safe wrapper for Freemius functions
 */
function slc_fs_safe() {
	if ( function_exists( 'slc_fs' ) ) {
		try {
			return slc_fs();
		} catch ( Exception $e ) {
			return null;
		}
	}
	return null;
}