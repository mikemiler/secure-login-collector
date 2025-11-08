<?php
/**
 * Premium Frontend Handler for Secure Login Collector.
 *
 * @fs_premium_only
 *
 * Premium Frontend Handler
 * Extends free version with pro features via hooks
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * Class Seculoco_Frontend_Handler_Pro
 *
 * Handles pro-specific frontend functionality via hooks.
 */
class Seculoco_Frontend_Handler_Pro
{

    /**
     * Constructor - hooks into free version's actions/filters.
     */
    public function __construct()
    {
        // Add pro flag to frontend JS config.
        add_filter('seculoco_frontend_js_config', array( $this, 'add_pro_js_config' ));

    }

    /**
     * Add pro flag to frontend JavaScript configuration.
     *
     * @param  array $config Existing JS configuration.
     * @return array Modified configuration with pro flag.
     */
    public function add_pro_js_config( $config )
    {
        $config['is_pro'] = true;
        return $config;
    }

}

// Initialize pro frontend handler.
new Seculoco_Frontend_Handler_Pro();
