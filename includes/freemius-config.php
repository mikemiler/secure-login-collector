<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Freemius Configuration
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize Freemius SDK
 *
 * @return Freemius
 */
function seculoco_fs() {
	global $seculoco_fs;

	if ( ! isset( $seculoco_fs ) ) {
		// Include Freemius SDK.
		$freemius_sdk = dirname( __DIR__ ) . '/vendor/freemius/start.php';

		if ( ! file_exists( $freemius_sdk ) ) {
			// SDK not found - return dummy object to prevent errors.
			return null;
		}

		require_once $freemius_sdk;

		/**
		 * Check if we should simulate free version for testing.
		 * Add this to wp-config.php for local testing:
		 * define( 'SECULOCO_SIMULATE_FREE_VERSION', true );
		 */
		$is_premium_version = ! defined( 'SECULOCO_SIMULATE_FREE_VERSION' ) || ! SECULOCO_SIMULATE_FREE_VERSION;

		$seculoco_fs = fs_dynamic_init(
			array(
				'id'                  => '19897',
                'slug'                => 'secure-login-collector',
                'premium_slug'        => 'secure-login-collector-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_f21b15938db645fdeb2d1dadb9ac4',
                'is_premium'          => $is_premium_version,
                'premium_suffix'      => 'Pro',
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
				// Enable WordPress.org compliance mode - required for plugins hosted on WordPress.org.
				'is_org_compliant'    => true,
				// Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
				'menu'                => array(
					'slug'        => 'secure-login-collector',
					'first-path'  => 'admin.php?page=secure-login-collector',
					'account'     => true,
					'pricing'     => true,
					'contact'     => false,
					'support'     => false,
					'affiliation' => false,
				),
			)
		);
	}

	return $seculoco_fs;
}

// Init Freemius.
seculoco_fs();

// Signal that SDK was initiated.
do_action( 'seculoco_fs_loaded' );

/**
 * Customize Freemius strings
 */
function seculoco_fs_custom_strings( $strings ) {
	$strings['free'] = __( 'Free Version', 'secure-login-collector' );
	$strings['pro']  = __( 'Pro Version', 'secure-login-collector' );

	return $strings;
}
if ( function_exists( 'seculoco_fs' ) && seculoco_fs() ) {
	seculoco_fs()->add_filter( 'plugin_strings', 'seculoco_fs_custom_strings' );
}

/**
 * Add custom license activation success message
 */
function seculoco_fs_license_activation_message( $hook ) {
	// Only load on Freemius pages.
	if ( strpos( $hook, 'secure-login-collector' ) === false ) {
		return;
	}

	// Enqueue jQuery.
	wp_enqueue_script( 'jquery' );

	// Add inline script for license activation.
	$script = "
	jQuery(document).ready(function($) {
		$(document).on('fs_license_activated', function() {
			alert('" . esc_js( __( 'Pro version activated! Passkey encryption is now available.', 'secure-login-collector' ) ) . "');
			// Reload page to show pro features.
			window.location.reload();
		});
	});
	";

	wp_add_inline_script( 'jquery', $script );
}
add_action( 'admin_enqueue_scripts', 'seculoco_fs_license_activation_message' );


/**
 * Hide Freemius admin notices for non-admin users
 */
function seculoco_fs_hide_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) && function_exists( 'seculoco_fs' ) && seculoco_fs() ) {
		seculoco_fs()->add_filter( 'show_admin_notices', '__return_false' );
	}
}
add_action( 'init', 'seculoco_fs_hide_admin_notices' );
