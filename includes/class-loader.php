<?php
/**
 * Core loader for Secure Login Collector.
 *
 * Handles including all free plugin dependencies and exposes a hook that
 * allows premium add-ons to load their own classes once the free loader
 * has finished bootstrapping.
 *
 * @package Secure_Login_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seculoco_Loader {


	/**
	 * Boot the loader.
	 *
	 * @return void
	 */
	public static function load() {
		self::load_core_files();
		self::load_free_classes();
		self::load_integrations();
		self::include_file( 'includes/class-loader__premium_only.php' );
		self::fire_pro_loader_hook();
	}

	/**
	 * Include constants and shared helper functions.
	 *
	 * @return void
	 */
	private static function load_core_files() {
		self::include_file( 'includes/constants.php' );
		self::include_file( 'includes/functions.php' );
	}

	/**
	 * Load all free-version class files.
	 *
	 * @return void
	 */
	private static function load_free_classes() {
		$files = array(
			'includes/class-encryption-handler-v2.php',
			'includes/class-encryption-handler-factory.php',
			'includes/class-database-manager.php',
			'includes/class-seculoco-list-table.php',
			'includes/class-admin-interface.php',
			'includes/class-frontend-handler.php',
			'includes/class-spam-protection.php',
			'includes/class-settings-manager.php',
		);

		foreach ( $files as $file ) {
			self::include_file( $file );
		}
	}

	/**
	 * Load integration helpers (Freemius hooks, uninstall handlers, etc).
	 *
	 * @return void
	 */
	private static function load_integrations() {
		self::include_file( 'includes/freemius-hooks.php' );
		self::include_file( 'includes/freemius-uninstall.php' );
	}

	/**
	 * Helper to include a file if it exists.
	 *
	 * @param  string $relative_path Relative path from plugin root.
	 * @return void
	 */
	private static function include_file( $relative_path ) {
		$path = SECULOCO_PLUGIN_DIR . $relative_path;

		if ( file_exists( $path ) ) {
			include_once $path;
		}
	}

	/**
	 * Fire hook that allows premium loaders to include their dependencies.
	 *
	 * @return void
	 */
	public static function fire_pro_loader_hook() {
		/**
		 * Fires after the base (free) loader finishes loading all dependencies.
		 *
		 * Premium add-ons can hook into this action to include their own files.
		 */
		do_action( 'seculoco_free_loader_ready' );
	}
}
