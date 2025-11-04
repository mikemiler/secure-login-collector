<?php
/**
 * Global functions for Secure Login Collector.
 *
 * @package Secure_Login_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'seculoco_init' ) ) {
	/**
	 * Initialize the plugin.
	 *
	 * This function instantiates the main plugin class after Freemius has loaded.
	 * It ensures proper initialization order and prevents race conditions.
	 *
	 * @return SecureLoginCollector The plugin instance.
	 */
	function seculoco_init() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new SecureLoginCollector();
		}

		return $instance;
	}
}
