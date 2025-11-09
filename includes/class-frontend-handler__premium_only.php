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

        // Ensure frontend recognizes passkey readiness.
        add_filter('seculoco_has_encryption_keys', array( $this, 'has_encryption_keys' ));

        // Surface passkey availability for filters relying on seculoco_has_passkey_encryption().
        add_filter('seculoco_has_passkey_encryption', array( $this, 'has_passkey_encryption' ));

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

    /**
     * Ensure encryption is considered ready when passkey mode is active.
     *
     * @param bool $has_keys Existing determination.
     * @return bool
     */
    public function has_encryption_keys( $has_keys )
    {
        if ( $has_keys ) {
            return true;
        }

        return $this->has_passkey_encryption();
    }

    /**
     * Whether passkey encryption is available.
     *
     * @param bool $active Existing determination.
     * @return bool
     */
    public function has_passkey_encryption( $active = false )
    {
        if ( $active ) {
            return true;
        }

        return (bool) get_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, false ) && (bool) get_option( SECULOCO_OPTION_PASSKEY_REGISTERED, false );
    }

}

// Initialize pro frontend handler.
new Seculoco_Frontend_Handler_Pro();
