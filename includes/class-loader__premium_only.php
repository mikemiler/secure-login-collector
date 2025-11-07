<?php
/**
 * Premium loader for Secure Login Collector.
 *
 * Loaded only when premium files are available. Hooks into the free loader
 * lifecycle and conditionally loads premium classes.
 *
 * @package Secure_Login_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register premium loader hook.
 */
function seculoco_register_premium_loader() {
	if ( did_action( 'seculoco_free_loader_ready' ) ) {
		seculoco_load_premium_dependencies();
	} else {
		add_action( 'seculoco_free_loader_ready', 'seculoco_load_premium_dependencies', 5 );
	}
}
seculoco_register_premium_loader();

/**
 * Determine if premium code can be loaded.
 *
 * @return bool
 */
function seculoco_can_load_premium_code() {
	if ( defined( 'SECULOCO_SIMULATE_FREE_VERSION' ) && SECULOCO_SIMULATE_FREE_VERSION ) {
		return false;
	}

	if ( ! function_exists( 'seculoco_fs' ) ) {
		return false;
	}

	$fs = seculoco_fs();
	return (bool) $fs && $fs->can_use_premium_code();
}

/**
 * Load premium-only files when available.
 *
 * @return void
 */
function seculoco_load_premium_dependencies() {
	static $loaded = false;
	static $waiting_for_freemius = false;

	if ( $loaded ) {
		return;
	}

	if ( ! function_exists( 'seculoco_fs' ) ) {
		if ( ! $waiting_for_freemius ) {
			$waiting_for_freemius = true;
			add_action( 'seculoco_fs_loaded', 'seculoco_load_premium_dependencies', 5 );
		}
		return;
	}

	if ( ! seculoco_can_load_premium_code() ) {
		return;
	}

	$loaded = true;

	$premium_files = array(
		'includes/class-passkey-manager__premium_only.php',
		'includes/class-master-key-manager__premium_only.php',
		'includes/class-license-manager__premium_only.php',
		'includes/class-spam-protection__premium_only.php',
		'includes/class-encryption-handler-v2__premium_only.php',
		'includes/class-frontend-handler__premium_only.php',
		'includes/class-admin-interface__premium_only.php',
		'includes/class-settings-manager__premium_only.php',
		'includes/class-upgrade-handler__premium_only.php',
	);

	foreach ( $premium_files as $file ) {
		$path = SECULOCO_PLUGIN_DIR . $file;
		if ( file_exists( $path ) ) {
			include_once $path;
		}
	}

	if ( class_exists( 'Seculoco_Passkey_Manager' ) ) {
		new Seculoco_Passkey_Manager();
	}
}
