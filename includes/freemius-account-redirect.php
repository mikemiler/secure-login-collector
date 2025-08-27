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
function slc_handle_freemius_account_redirect() {
	// Check if we're on the Freemius-generated account page
	// Freemius uses the pattern: {slug}-account
	if ( ! isset( $_GET['page'] ) || 'secure-login-collector-account' !== $_GET['page'] ) {
		return;
	}

	// Check permissions
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'secure-login-collector' ) );
	}

	// Don't render via admin_init - let the page callback handle it
}
// add_action( 'admin_init', 'slc_handle_freemius_account_redirect', 1 ); // Disabled - let Freemius handle redirects

/**
 * Alternative: Handle via direct page callback
 */
function slc_freemius_account_page() {
	if ( ! function_exists( 'slc_fs' ) || ! slc_fs() ) {
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

	// Check if user has completed opt-in
	if ( ! slc_fs()->is_registered() ) {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Account Setup Required', 'secure-login-collector' ); ?></h1>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Please complete the plugin setup process first.', 'secure-login-collector' ); ?></p>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=secure-login-collector' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Plugin Dashboard', 'secure-login-collector' ); ?></a></p>
			</div>
			
			<?php if ( method_exists( slc_fs(), 'connect_url' ) ) : ?>
				<h2><?php esc_html_e( 'Quick Setup', 'secure-login-collector' ); ?></h2>
				<p><?php esc_html_e( 'Click below to complete the setup:', 'secure-login-collector' ); ?></p>
				<a href="<?php echo esc_url( slc_fs()->get_activation_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Complete Setup', 'secure-login-collector' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return;
	}

	// Try to render account page
	try {
		slc_fs()->_account_page_render();
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
				<?php if ( slc_fs()->is_not_paying() ) : ?>
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
function slc_fix_freemius_urls() {
	if ( ! isset( $_GET['page'] ) ) {
		return;
	}

	// Redirect old URLs to new ones
	$redirects = array(
		'secure-login-data'     => 'secure-login-collector',
		'secure-login-settings' => 'secure-login-collector-settings',
		'secure-login-pricing'  => 'secure-login-collector-pricing',
		'secure-login-debug'    => 'secure-login-collector-debug',
	);

	if ( isset( $redirects[ $_GET['page'] ] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $redirects[ $_GET['page'] ] ) );
		exit;
	}
}
add_action( 'admin_init', 'slc_fix_freemius_urls', 0 );