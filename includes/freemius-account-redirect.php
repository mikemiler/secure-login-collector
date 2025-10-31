<?php
/**
 * Freemius Account Page Redirect Handler
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle Freemius account page redirect
 */
function seculoco_handle_freemius_account_redirect() {
	// Check if we're on the Freemius-generated account page.
	// Freemius uses the pattern: {slug}-account.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameter for page routing.
	if ( ! isset( $_GET['page'] ) || 'secure-login-collector-account' !== $_GET['page'] ) {
		return;
	}

	// Check permissions.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'secure-login-collector' ) );
	}

	// Don't render via admin_init - let the page callback handle it.
}
// add_action( 'admin_init', 'seculoco_handle_freemius_account_redirect', 1 ); // Disabled - let Freemius handle redirects.

/**
 * Alternative: Handle via direct page callback
 */
function seculoco_freemius_account_page() {
	if ( ! function_exists( 'seculoco_fs' ) || ! seculoco_fs() ) {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Account', 'secure-login-collector' ); ?></h1>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Freemius is not properly configured. Please ensure the Freemius SDK is installed.', 'secure-login-collector' ); ?></p>
			</div>
		</div>
		<?php
		return;
	}

	// Check if user has completed opt-in.
	if ( ! seculoco_fs()->is_registered() ) {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Account Setup Required', 'secure-login-collector' ); ?></h1>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Please complete the plugin setup process first.', 'secure-login-collector' ); ?></p>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=secure-login-collector' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Plugin Dashboard', 'secure-login-collector' ); ?></a></p>
			</div>
			
			<?php if ( method_exists( seculoco_fs(), 'connect_url' ) ) : ?>
				<h2><?php esc_html_e( 'Quick Setup', 'secure-login-collector' ); ?></h2>
				<p><?php esc_html_e( 'Click below to complete the setup:', 'secure-login-collector' ); ?></p>
				<a href="<?php echo esc_url( seculoco_fs()->get_activation_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Complete Setup', 'secure-login-collector' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return;
	}

	// Try to render account page.
	try {
		seculoco_fs()->_account_page_render();
	} catch ( Exception $e ) {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Account', 'secure-login-collector' ); ?></h1>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Error loading account page:', 'secure-login-collector' ); ?> <?php echo esc_html( $e->getMessage() ); ?></p>
			</div>
			
			<h2><?php esc_html_e( 'Quick Links', 'secure-login-collector' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=secure-login-collector' ) ); ?>" class="button"><?php esc_html_e( 'Plugin Dashboard', 'secure-login-collector' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=secure-login-collector-settings' ) ); ?>" class="button"><?php esc_html_e( 'Settings', 'secure-login-collector' ); ?></a>
				<?php if ( seculoco_fs()->is_not_paying() ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=secure-login-collector-pricing' ) ); ?>" class="button"><?php esc_html_e( 'View Pricing', 'secure-login-collector' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}

/**
 * Fix Freemius page URLs
 */
function seculoco_fix_freemius_urls() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET parameters for URL redirects.
	if ( ! isset( $_GET['page'] ) ) {
		return;
	}

	// Redirect old URLs to new ones.
	$redirects = array(
		'secure-login-data'     => 'secure-login-collector',
		'secure-login-settings' => 'secure-login-collector-settings',
		'secure-login-pricing'  => 'secure-login-collector-pricing',
		'secure-login-debug'    => 'secure-login-collector-debug',
	);

	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	if ( isset( $redirects[ $page ] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $redirects[ $page ] ) );
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
add_action( 'admin_init', 'seculoco_fix_freemius_urls', 0 );