<?php
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Free Spam Protection Placeholder
 *
 * The full honeypot + anti-bot implementation is available in the premium build.
 * This lightweight stub keeps the free plugin self-contained while providing
 * extension points for the premium add-on via filters.
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:enable WordPress.Files.FileName.InvalidClassFileName

/**
 * Minimal spam protection scaffold for the free plugin.
 *
 * Premium builds hook into the exposed filters to inject the real honeypot/rate
 * limiting behavior. The free build intentionally returns no markup so users
 * are not blocked by hidden fields they cannot control.
 */
class Seculoco_Spam_Protection {

	/**
	 * Output placeholder markup (empty in free version).
	 *
	 * @return string
	 */
	public function generate_honeypot_html() {
		/**
		 * Filter: seculoco_honeypot_html
		 *
		 * Premium builds can return their own markup for the honeypot field.
		 *
		 * @param string $html Current honeypot HTML (default empty string).
		 */
		return apply_filters( 'seculoco_honeypot_html', '' );
	}

	/**
	 * Validate submission (always passes in free build).
	 *
	 * @param array $post_data Raw POST payload.
	 * @return true|WP_Error
	 */
	public function validate_submission( $post_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		/**
		 * Filter: seculoco_honeypot_validate
		 *
		 * Allows premium code to perform additional validation. Should return
		 * true on success or WP_Error on failure.
		 *
		 * @param bool|WP_Error $result    Current validation state. Defaults to true.
		 * @param array         $post_data Form data for further inspection.
		 */
		$result = apply_filters( 'seculoco_honeypot_validate', true, $post_data );

		return $result instanceof WP_Error ? $result : true;
	}
}
