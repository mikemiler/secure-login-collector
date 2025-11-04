<?php
/**
 * Premium Spam Protection
 *
 * Includes rate limiting and advanced spam protection features.
 * This file combines both the rate limiter and premium spam protection classes.
 *
 * @package SecureLoginCollector
 * @fs_premium_only
 * @since 1.3.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Rate_Limiter
 *
 * Manages rate limiting for form submissions to prevent spam and abuse.
 *
 * Features:
 * - Track submissions by IP address using WordPress transients
 * - Configurable time windows and maximum attempts
 * - Progressive blocking for repeat offenders
 * - IP whitelisting support
 * - Admin controls for rate limit management
 *
 * @since 1.2.9
 */
class Seculoco_Rate_Limiter {

	/**
	 * Default time window in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_TIME_WINDOW = 3600;

	/**
	 * Default maximum attempts per time window.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ATTEMPTS = 3;

	/**
	 * Base block duration in seconds (1 hour).
	 *
	 * @var int
	 */
	const BASE_BLOCK_DURATION = 3600;

	/**
	 * Maximum block duration in seconds (24 hours).
	 *
	 * @var int
	 */
	const MAX_BLOCK_DURATION = 86400;

	/**
	 * Transient prefix for rate limit data.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'seculoco_rate_limit_';

	/**
	 * Option key for rate limit settings.
	 *
	 * @var string
	 */
	const OPTION_TIME_WINDOW = 'seculoco_rate_limit_time_window';

	/**
	 * Option key for max attempts.
	 *
	 * @var string
	 */
	const OPTION_MAX_ATTEMPTS = 'seculoco_rate_limit_max_attempts';

	/**
	 * Option key for whitelist.
	 *
	 * @var string
	 */
	const OPTION_WHITELIST = 'seculoco_rate_limit_whitelist';

	/**
	 * Option key for enable/disable.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'seculoco_rate_limit_enabled';

	/**
	 * Constructor - initializes rate limiter.
	 *
	 * @since 1.2.9
	 */
	public function __construct() {
		// Rate limiter is initialized and ready to use.
	}

	/**
	 * Check if rate limiting is enabled.
	 *
	 * @since 1.2.9
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Check if an IP address is allowed to submit based on rate limits.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to check.
	 * @return true|WP_Error True if allowed, WP_Error if blocked or rate limited.
	 */
	public function check_rate_limit( $ip_address ) {
		// If rate limiting is disabled, always allow.
		if ( ! $this->is_enabled() ) {
			return true;
		}

		// Validate IP address.
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return new WP_Error(
				'invalid_ip',
				__( 'Invalid IP address provided.', 'secure-login-collector' )
			);
		}

		// Check if IP is whitelisted.
		if ( $this->is_whitelisted( $ip_address ) ) {
			return true;
		}

		// Check if IP is currently blocked.
		if ( $this->is_blocked( $ip_address ) ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many submissions. Please try again later.', 'secure-login-collector' )
			);
		}

		// Check submission count.
		$submission_count = $this->get_submission_count( $ip_address );
		$max_attempts     = $this->get_max_attempts();

		if ( $submission_count >= $max_attempts ) {
			// Block the IP.
			$this->block_ip( $ip_address );

			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many submissions. Please try again later.', 'secure-login-collector' )
			);
		}

		return true;
	}

	/**
	 * Record a submission attempt for an IP address.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to record.
	 * @return bool True on success, false on failure.
	 */
	public function record_submission( $ip_address ) {
		// If rate limiting is disabled, don't record.
		if ( ! $this->is_enabled() ) {
			return true;
		}

		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return false;
		}

		// Don't record whitelisted IPs.
		if ( $this->is_whitelisted( $ip_address ) ) {
			return true;
		}

		$data       = $this->get_ip_data( $ip_address );
		$time_window = $this->get_time_window();
		$current_time = time();

		// Add new submission timestamp.
		$data['submissions'][] = $current_time;

		// Clean up old submissions outside the time window.
		$data['submissions'] = array_filter(
			$data['submissions'],
			function ( $timestamp ) use ( $current_time, $time_window ) {
				return ( $current_time - $timestamp ) <= $time_window;
			}
		);

		// Reset array keys after filtering.
		$data['submissions'] = array_values( $data['submissions'] );

		// Save updated data.
		return $this->set_ip_data( $ip_address, $data );
	}

	/**
	 * Get the number of submissions for an IP address within the time window.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to check.
	 * @return int Number of submissions.
	 */
	public function get_submission_count( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return 0;
		}

		$data         = $this->get_ip_data( $ip_address );
		$time_window  = $this->get_time_window();
		$current_time = time();

		// Filter submissions to only those within the time window.
		$recent_submissions = array_filter(
			$data['submissions'],
			function ( $timestamp ) use ( $current_time, $time_window ) {
				return ( $current_time - $timestamp ) <= $time_window;
			}
		);

		return count( $recent_submissions );
	}

	/**
	 * Check if an IP address is currently blocked.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to check.
	 * @return bool True if blocked, false otherwise.
	 */
	public function is_blocked( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return false;
		}

		$data = $this->get_ip_data( $ip_address );

		if ( ! isset( $data['blocked_until'] ) || null === $data['blocked_until'] ) {
			return false;
		}

		$current_time = time();

		// Check if block has expired.
		if ( $data['blocked_until'] <= $current_time ) {
			// Block expired, clear it.
			$this->unblock_ip( $ip_address );
			return false;
		}

		return true;
	}

	/**
	 * Block an IP address.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to block.
	 * @return bool True on success, false on failure.
	 */
	private function block_ip( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return false;
		}

		$data = $this->get_ip_data( $ip_address );

		// Increment total blocks.
		$data['total_blocks'] = isset( $data['total_blocks'] ) ? $data['total_blocks'] + 1 : 1;

		// Calculate progressive block duration.
		$block_duration       = $this->calculate_block_duration( $data['total_blocks'] );
		$data['blocked_until'] = time() + $block_duration;

		return $this->set_ip_data( $ip_address, $data );
	}

	/**
	 * Unblock an IP address.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to unblock.
	 * @return bool True on success, false on failure.
	 */
	private function unblock_ip( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return false;
		}

		$data                 = $this->get_ip_data( $ip_address );
		$data['blocked_until'] = null;

		return $this->set_ip_data( $ip_address, $data );
	}

	/**
	 * Get the time window for rate limiting in seconds.
	 *
	 * @since 1.2.9
	 * @return int Time window in seconds.
	 */
	public function get_time_window() {
		$time_window = (int) get_option( self::OPTION_TIME_WINDOW, self::DEFAULT_TIME_WINDOW );
		return max( 60, $time_window ); // Minimum 1 minute.
	}

	/**
	 * Get the maximum number of allowed attempts.
	 *
	 * @since 1.2.9
	 * @return int Maximum attempts.
	 */
	public function get_max_attempts() {
		$max_attempts = (int) get_option( self::OPTION_MAX_ATTEMPTS, self::DEFAULT_MAX_ATTEMPTS );
		return max( 1, $max_attempts ); // Minimum 1 attempt.
	}

	/**
	 * Get the remaining attempts for an IP address.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to check.
	 * @return int Number of remaining attempts.
	 */
	public function get_remaining_attempts( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return 0;
		}

		if ( $this->is_whitelisted( $ip_address ) ) {
			return $this->get_max_attempts(); // Whitelisted IPs always have full attempts.
		}

		$submission_count = $this->get_submission_count( $ip_address );
		$max_attempts     = $this->get_max_attempts();

		return max( 0, $max_attempts - $submission_count );
	}

	/**
	 * Get the block duration in seconds for an IP address.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to check.
	 * @return int Block duration in seconds, or 0 if not blocked.
	 */
	public function get_block_duration( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return 0;
		}

		$data = $this->get_ip_data( $ip_address );

		if ( ! isset( $data['blocked_until'] ) || null === $data['blocked_until'] ) {
			return 0;
		}

		$current_time = time();
		$remaining    = $data['blocked_until'] - $current_time;

		return max( 0, $remaining );
	}

	/**
	 * Calculate progressive block duration based on number of blocks.
	 *
	 * @since 1.2.9
	 * @param int $total_blocks Number of times this IP has been blocked.
	 * @return int Block duration in seconds.
	 */
	private function calculate_block_duration( $total_blocks ) {
		// Progressive blocking: increase duration for repeat offenders.
		// First block: 1 hour
		// Second block: 2 hours
		// Third+ block: 4 hours
		// Maximum: 24 hours.
		$duration = self::BASE_BLOCK_DURATION * min( 4, pow( 2, $total_blocks - 1 ) );
		return min( $duration, self::MAX_BLOCK_DURATION );
	}

	/**
	 * Clear rate limit records for an IP address (admin function).
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to clear.
	 * @return bool True on success, false on failure.
	 */
	public function clear_ip_record( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return false;
		}

		$transient_key = $this->get_transient_key( $ip_address );
		return delete_transient( $transient_key );
	}

	/**
	 * Get rate limit data for an IP address.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address.
	 * @return array Rate limit data structure.
	 */
	private function get_ip_data( $ip_address ) {
		$transient_key = $this->get_transient_key( $ip_address );
		$data          = get_transient( $transient_key );

		if ( false === $data || ! is_array( $data ) ) {
			// Initialize default data structure.
			$data = array(
				'submissions'   => array(),
				'blocked_until' => null,
				'total_blocks'  => 0,
			);
		}

		// Ensure data structure is valid.
		$data = wp_parse_args(
			$data,
			array(
				'submissions'   => array(),
				'blocked_until' => null,
				'total_blocks'  => 0,
			)
		);

		return $data;
	}

	/**
	 * Set rate limit data for an IP address.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address.
	 * @param array  $data       Rate limit data to store.
	 * @return bool True on success, false on failure.
	 */
	private function set_ip_data( $ip_address, $data ) {
		$transient_key = $this->get_transient_key( $ip_address );

		// Set transient to expire after max block duration + time window.
		$expiration = self::MAX_BLOCK_DURATION + $this->get_time_window();

		return set_transient( $transient_key, $data, $expiration );
	}

	/**
	 * Generate a transient key for an IP address.
	 *
	 * Uses SHA-256 hash for privacy and to handle special characters in IPv6.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address.
	 * @return string Transient key.
	 */
	private function get_transient_key( $ip_address ) {
		// Hash the IP address for privacy.
		$ip_hash = hash( 'sha256', $ip_address );
		// WordPress transient keys have max length of 172 characters (191 - 19 for timeout key).
		// Our prefix + hash is well under this limit.
		return self::TRANSIENT_PREFIX . $ip_hash;
	}

	/**
	 * Check if an IP address is whitelisted.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to check.
	 * @return bool True if whitelisted, false otherwise.
	 */
	public function is_whitelisted( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return false;
		}

		$whitelist = $this->get_whitelist();

		if ( empty( $whitelist ) ) {
			return false;
		}

		// Check for exact match or CIDR range match.
		foreach ( $whitelist as $whitelisted_ip ) {
			if ( $this->ip_matches( $ip_address, $whitelisted_ip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the IP whitelist.
	 *
	 * @since 1.2.9
	 * @return array Array of whitelisted IP addresses/ranges.
	 */
	public function get_whitelist() {
		$whitelist = get_option( self::OPTION_WHITELIST, array() );

		if ( ! is_array( $whitelist ) ) {
			return array();
		}

		return array_filter( array_map( 'trim', $whitelist ) );
	}

	/**
	 * Check if an IP address matches a pattern (supports CIDR notation).
	 *
	 * @since 1.2.9
	 * @param string $ip      The IP address to check.
	 * @param string $pattern The pattern to match against (IP or CIDR).
	 * @return bool True if matches, false otherwise.
	 */
	private function ip_matches( $ip, $pattern ) {
		// Exact match.
		if ( $ip === $pattern ) {
			return true;
		}

		// Check for CIDR notation.
		if ( strpos( $pattern, '/' ) === false ) {
			return false;
		}

		// Parse CIDR.
		list( $subnet, $bits ) = explode( '/', $pattern, 2 );

		// Validate CIDR notation.
		if ( ! $this->is_valid_ip( $subnet ) || ! is_numeric( $bits ) ) {
			return false;
		}

		// Check if IPs are same version (IPv4 or IPv6).
		$ip_version     = $this->get_ip_version( $ip );
		$subnet_version = $this->get_ip_version( $subnet );

		if ( $ip_version !== $subnet_version ) {
			return false;
		}

		// IPv4 CIDR matching.
		if ( 4 === $ip_version ) {
			return $this->ipv4_in_range( $ip, $subnet, (int) $bits );
		}

		// IPv6 CIDR matching.
		if ( 6 === $ip_version ) {
			return $this->ipv6_in_range( $ip, $subnet, (int) $bits );
		}

		return false;
	}

	/**
	 * Check if an IPv4 address is within a CIDR range.
	 *
	 * @since 1.2.9
	 * @param string $ip     The IPv4 address to check.
	 * @param string $subnet The subnet address.
	 * @param int    $bits   The CIDR bits.
	 * @return bool True if in range, false otherwise.
	 */
	private function ipv4_in_range( $ip, $subnet, $bits ) {
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask       = -1 << ( 32 - $bits );
		$subnet_long &= $mask; // Apply mask to subnet.

		return ( $ip_long & $mask ) === $subnet_long;
	}

	/**
	 * Check if an IPv6 address is within a CIDR range.
	 *
	 * @since 1.2.9
	 * @param string $ip     The IPv6 address to check.
	 * @param string $subnet The subnet address.
	 * @param int    $bits   The CIDR bits.
	 * @return bool True if in range, false otherwise.
	 */
	private function ipv6_in_range( $ip, $subnet, $bits ) {
		// Convert IPv6 addresses to binary.
		$ip_binary     = inet_pton( $ip );
		$subnet_binary = inet_pton( $subnet );

		if ( false === $ip_binary || false === $subnet_binary ) {
			return false;
		}

		// Calculate the number of bytes and bits to compare.
		$bytes     = (int) floor( $bits / 8 );
		$bits_left = $bits % 8;

		// Compare full bytes.
		for ( $i = 0; $i < $bytes; $i++ ) {
			if ( $ip_binary[ $i ] !== $subnet_binary[ $i ] ) {
				return false;
			}
		}

		// Compare remaining bits if any.
		if ( $bits_left > 0 ) {
			$mask = ~( ( 1 << ( 8 - $bits_left ) ) - 1 );
			if ( ( ord( $ip_binary[ $bytes ] ) & $mask ) !== ( ord( $subnet_binary[ $bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate an IP address (IPv4 or IPv6).
	 *
	 * @since 1.2.9
	 * @param string $ip The IP address to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private function is_valid_ip( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 );
	}

	/**
	 * Get IP version (4 or 6).
	 *
	 * @since 1.2.9
	 * @param string $ip The IP address.
	 * @return int|false 4 for IPv4, 6 for IPv6, false if invalid.
	 */
	private function get_ip_version( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 4;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return 6;
		}

		return false;
	}

	/**
	 * Format a duration in seconds to human-readable format.
	 *
	 * @since 1.2.9
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration.
	 */
	private function format_duration( $seconds ) {
		if ( $seconds < 60 ) {
			return sprintf(
				// translators: %d is the number of seconds.
				_n( '%d second', '%d seconds', $seconds, 'secure-login-collector' ),
				$seconds
			);
		}

		if ( $seconds < 3600 ) {
			$minutes = ceil( $seconds / 60 );
			return sprintf(
				// translators: %d is the number of minutes.
				_n( '%d minute', '%d minutes', $minutes, 'secure-login-collector' ),
				$minutes
			);
		}

		$hours = ceil( $seconds / 3600 );
		return sprintf(
			// translators: %d is the number of hours.
			_n( '%d hour', '%d hours', $hours, 'secure-login-collector' ),
			$hours
		);
	}

	/**
	 * Get comprehensive rate limit status for an IP address.
	 *
	 * Useful for admin displays and debugging.
	 *
	 * @since 1.2.9
	 * @param string $ip_address The IP address to check.
	 * @return array Status information.
	 */
	public function get_ip_status( $ip_address ) {
		if ( empty( $ip_address ) || ! $this->is_valid_ip( $ip_address ) ) {
			return array(
				'valid'       => false,
				'error'       => __( 'Invalid IP address.', 'secure-login-collector' ),
			);
		}

		$data = $this->get_ip_data( $ip_address );

		return array(
			'valid'              => true,
			'ip_address'         => $ip_address,
			'is_whitelisted'     => $this->is_whitelisted( $ip_address ),
			'is_blocked'         => $this->is_blocked( $ip_address ),
			'submission_count'   => $this->get_submission_count( $ip_address ),
			'max_attempts'       => $this->get_max_attempts(),
			'remaining_attempts' => $this->get_remaining_attempts( $ip_address ),
			'block_duration'     => $this->get_block_duration( $ip_address ),
			'total_blocks'       => isset( $data['total_blocks'] ) ? $data['total_blocks'] : 0,
			'time_window'        => $this->get_time_window(),
		);
	}
}

/**
 * Class Seculoco_Spam_Protection_Premium
 *
 * Premium Spam Protection Class
 *
 * Extends free version spam protection with rate limiting capabilities.
 * Hooks into the free version's spam protection system to add rate limiting
 * validation and submission tracking.
 *
 * @since 1.3.0
 */
class Seculoco_Spam_Protection_Premium {

	/**
	 * Transient key prefix for time tracking.
	 *
	 * @since 1.3.0
	 */
	const START_TIME_TRANSIENT = 'seculoco_honeypot_start_';

	/**
	 * Time tracking transient expiration (1 hour).
	 *
	 * @since 1.3.0
	 */
	const START_TIME_EXPIRATION = 3600;

	/**
	 * Rate limiter instance.
	 *
	 * @since  1.3.0
	 * @access private
	 * @var    Seculoco_Rate_Limiter Rate limiting functionality.
	 */
	private $rate_limiter;

	/**
	 * Initialize the premium spam protection class.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		$this->rate_limiter = new Seculoco_Rate_Limiter();
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks and filters.
	 *
	 * Integrates premium rate limiting with the free version's
	 * spam protection system.
	 *
	 * @since 1.3.0
	 */
	private function register_hooks() {
		// Hook into spam protection check filter.
		add_filter( 'seculoco_spam_protection_check', array( $this, 'check_rate_limit' ), 10, 3 );

		// Hook into submission recorded action.
		add_action( 'seculoco_submission_recorded', array( $this, 'record_submission' ), 10, 2 );

		// Start time tracking when form is rendered (pro feature).
		add_action( 'seculoco_before_form_render', array( $this, 'start_time_tracking' ) );
	}

	/**
	 * Check rate limit and minimum time threshold for incoming submission.
	 *
	 * Integrates with the free version's spam protection check filter
	 * to add premium features:
	 * 1. Minimum time threshold validation (pro feature)
	 * 2. Rate limiting validation (pro feature)
	 *
	 * If the free version has already identified spam, this check is skipped.
	 *
	 * @since 1.3.0
	 *
	 * @param bool|WP_Error $spam_check  Current spam check result.
	 * @param array         $post_data   Submitted form data.
	 * @param string        $client_ip   Client IP address.
	 * @return bool|WP_Error True if all checks pass, WP_Error if validation fails.
	 */
	public function check_rate_limit( $spam_check, $post_data, $client_ip ) {
		// If spam was already detected by free version, don't override.
		if ( is_wp_error( $spam_check ) ) {
			return $spam_check;
		}

		// Premium Feature 1: Minimum time threshold validation.
		$start_time = $this->get_start_time( $client_ip );

		if ( false === $start_time ) {
			// No start time found - validation failed.
			return new WP_Error(
				'validation_failed',
				__( 'Form submission failed validation. Please try again.', 'secure-login-collector' )
			);
		}

		$elapsed_time = time() - $start_time;
		$min_time_threshold = get_option( 'seculoco_honeypot_min_time', 2 );

		if ( $elapsed_time < $min_time_threshold ) {
			// Submission too fast - likely a bot (premium feature).
			return new WP_Error(
				'validation_failed',
				__( 'Form submission failed validation. Please try again.', 'secure-login-collector' )
			);
		}

		// Clean up transient after successful validation.
		$transient_key = self::START_TIME_TRANSIENT . md5( $client_ip );
		delete_transient( $transient_key );

		// Premium Feature 2: Rate limiting check.
		$rate_limit_result = $this->rate_limiter->check_rate_limit( $client_ip );

		return $rate_limit_result;
	}

	/**
	 * Record submission for rate limit tracking.
	 *
	 * Called after a submission has been successfully recorded in the database.
	 * Updates rate limit counters for the client IP address.
	 *
	 * @since 1.3.0
	 *
	 * @param string $client_ip     Client IP address.
	 * @param int    $insert_result Database insert result (submission ID or 0 on failure).
	 */
	public function record_submission( $client_ip, $insert_result ) {
		// Only record if submission was successfully saved.
		if ( $insert_result > 0 ) {
			$this->rate_limiter->record_submission( $client_ip );
		}
	}

	/**
	 * Start time tracking for form submission (Premium feature).
	 *
	 * Stores the current timestamp in a transient keyed by IP address
	 * to enable stateless validation.
	 *
	 * @since 1.3.0
	 * @return bool True on success, false on failure.
	 */
	public function start_time_tracking() {
		$ip_address = $this->get_client_ip();
		$transient_key = self::START_TIME_TRANSIENT . md5( $ip_address );
		$start_time = time();
		return set_transient( $transient_key, $start_time, self::START_TIME_EXPIRATION );
	}

	/**
	 * Get start time for form submission time tracking.
	 *
	 * @since 1.3.0
	 * @param string $client_ip Client IP address.
	 * @return int|false Start time timestamp, or false if not found.
	 */
	private function get_start_time( $client_ip ) {
		$transient_key = self::START_TIME_TRANSIENT . md5( $client_ip );
		return get_transient( $transient_key );
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.3.0
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		return SecureLoginCollector::get_client_ip();
	}
}
