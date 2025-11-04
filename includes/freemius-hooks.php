<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Freemius Hooks and Customizations
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customize Freemius opt-in message
 */
function seculoco_fs_custom_connect_message(
	$message,
	$user_first_name,
	$plugin_title,
	$user_login,
	$site_link,
	$freemius_link
) {
	/* translators: %1$s: user first name, %2$s: plugin title */
	return sprintf(
		__( 'Hey %1$s! Please help us improve %2$s by sharing some basic WordPress environment info. This helps us ensure compatibility and provide better support.', 'secure-login-collector' ),
		$user_first_name,
		'<b>' . $plugin_title . '</b>'
	);
}
if ( function_exists( 'seculoco_fs' ) ) {
	seculoco_fs()->add_filter( 'connect_message', 'seculoco_fs_custom_connect_message', 10, 6 );
}

/**
 * Note: Uninstall cleanup is now handled in includes/freemius-uninstall.php
 * That file is included in the main plugin file and properly registers
 * the uninstall handler with Freemius via the 'seculoco_fs_loaded' action.
 */

/**
 * Customize trial message
 */
function seculoco_fs_trial_promotion_message( $message ) {
	return sprintf(
		__( 'Hey there! Want to try the Pro version of Secure Login Collector? Start your 14-day free trial and experience passkey-derived encryption!', 'secure-login-collector' ),
		'<b>' . seculoco_fs()->get_plugin_name() . '</b>'
	);
}
if ( function_exists( 'seculoco_fs' ) ) {
	seculoco_fs()->add_filter( 'trial_promotion_message', 'seculoco_fs_trial_promotion_message' );
}

/**
 * Add admin notices for pro features
 */
function seculoco_fs_admin_notices() {
	if ( ! function_exists( 'seculoco_fs' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameter for page detection.
	if ( ! seculoco_fs()->is_paying() && isset( $_GET['page'] ) && 'secure-login-collector-account' === $_GET['page'] ) {
		$passkey_registered = get_option( 'seculoco_passkey_registered', false );

		if ( $passkey_registered ) {
			?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						/* translators: %s: upgrade link */
						esc_html__( 'You have a passkey registered but need the Pro version to use passkey encryption. %s', 'secure-login-collector' ),
						'<a href="' . esc_url( seculoco_fs()->get_upgrade_url() ) . '">' . esc_html__( 'Upgrade Now', 'secure-login-collector' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}
}
add_action( 'admin_notices', 'seculoco_fs_admin_notices' );

/**
 * Override plugin action links
 */
function seculoco_fs_plugin_action_links( $links ) {
	if ( function_exists( 'seculoco_fs' ) && seculoco_fs()->is_not_paying() ) {
		// Use Freemius's proper upgrade URL.
		$upgrade_url = seculoco_fs()->get_upgrade_url();

		// If no upgrade URL available, use account page.
		if ( empty( $upgrade_url ) ) {
			$upgrade_url = seculoco_fs()->get_account_url();
		}

		$links['go-pro'] = '<a href="' . esc_url( $upgrade_url ) . '" style="color: #2271b1; font-weight: bold;">' .
			esc_html__( 'Go Pro', 'secure-login-collector' ) . '</a>';
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( SECULOCO_PLUGIN_DIR . 'secure-login-collector.php' ), 'seculoco_fs_plugin_action_links' );
