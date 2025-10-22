<?php

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
function slc_fs_custom_connect_message(
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
if ( function_exists( 'slc_fs' ) ) {
	slc_fs()->add_filter( 'connect_message', 'slc_fs_custom_connect_message', 10, 6 );
}

/**
 * Add custom icon for Freemius
 */
function slc_fs_custom_icon() {
	return SECURE_LOGIN_PLUGIN_DIR . 'assets/icon-256x256.png';
}
if ( function_exists( 'slc_fs' ) ) {
	slc_fs()->add_filter( 'plugin_icon', 'slc_fs_custom_icon' );
}

/**
 * Hook into plugin uninstall
 */
function slc_fs_uninstall_cleanup() {
	// Clean up Freemius data
	if ( ! slc_fs()->is_clone() ) {
		slc_fs()->remove_database_option();
	}

	// Your existing uninstall code
	global $wpdb;

	// Delete custom table
	// SECURITY FIX: Using $wpdb->prepare() with %i placeholder for table names (WordPress 6.2+)
	$table_name = $wpdb->prefix . 'secure_login_data';
	$wpdb->query(
		$wpdb->prepare(
			"DROP TABLE IF EXISTS %i",
			$table_name
		)
	);

	// Delete all plugin options
	$options = array(
		'secure_login_public_key',
		'secure_login_private_key_encrypted',
		'secure_login_keys_generated',
		'secure_login_key_version',
		'secure_login_passkey_credential_id',
		'secure_login_passkey_public_key',
		'secure_login_passkey_registered',
		'secure_login_passkey_user_id',
		'secure_login_passkey_registered_at',
		'secure_login_passkey_salt',
		'secure_login_notification_enabled',
		'secure_login_notification_email',
		'secure_login_ultra_secure_mode',
		'secure_login_expiration_days',
		'secure_login_form_intro_text',
		'secure_login_form_footer_text',
		'secure_login_form_success_message',
		'secure_login_form_button_text',
		'secure_login_export_format',
		'secure_login_auto_export_format',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Delete transients
	// SECURITY FIX: Using $wpdb->prepare() to prevent SQL injection
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM %i WHERE option_name LIKE %s",
			$wpdb->options,
			'_transient_secure_login_%'
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM %i WHERE option_name LIKE %s",
			$wpdb->options,
			'_transient_timeout_secure_login_%'
		)
	);
}
if ( function_exists( 'slc_fs' ) ) {
	slc_fs()->add_action( 'after_uninstall', 'slc_fs_uninstall_cleanup' );
}

/**
 * Add custom pricing page
 */
function slc_fs_custom_pricing_page() {
	// Use freemium pricing for now.
	// TODO: Enable custom pricing page when ready.
	/* Commented out for future use
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Secure Login Collector Pro', 'secure-login-collector' ); ?></h1>

		<div class="slc-pricing-wrapper">
			<div class="slc-pricing-card free">
				<h2><?php esc_html_e( 'Free Version', 'secure-login-collector' ); ?></h2>
				<ul>
					<li>✅ <?php esc_html_e( 'RSA-2048 Encryption', 'secure-login-collector' ); ?></li>
					<li>✅ <?php esc_html_e( 'Email Notifications', 'secure-login-collector' ); ?></li>
					<li>✅ <?php esc_html_e( 'Auto-deletion', 'secure-login-collector' ); ?></li>
					<li>✅ <?php esc_html_e( 'Export to Password Managers', 'secure-login-collector' ); ?></li>
				</ul>
			</div>

			<div class="slc-pricing-card pro">
				<h2><?php esc_html_e( 'Pro Version', 'secure-login-collector' ); ?></h2>
				<p class="price">$49/year</p>
				<ul>
					<li>✅ <?php esc_html_e( 'Everything in Free', 'secure-login-collector' ); ?></li>
					<li>⭐ <?php esc_html_e( 'Passkey-Derived Encryption', 'secure-login-collector' ); ?></li>
					<li>⭐ <?php esc_html_e( 'Ultra-Secure Double Encryption', 'secure-login-collector' ); ?></li>
					<li>⭐ <?php esc_html_e( 'WebAuthn Hardware Security', 'secure-login-collector' ); ?></li>
					<li>⭐ <?php esc_html_e( 'Zero-Knowledge Architecture', 'secure-login-collector' ); ?></li>
					<li>⭐ <?php esc_html_e( 'Priority Support', 'secure-login-collector' ); ?></li>
				</ul>

				<?php
				if ( function_exists( 'slc_fs' ) ) {
					// Debug information
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						echo '<!-- Debug: is_registered = ' . ( slc_fs()->is_registered() ? 'true' : 'false' ) . ' -->';
						echo '<!-- Debug: is_anonymous = ' . ( slc_fs()->is_anonymous() ? 'true' : 'false' ) . ' -->';
						echo '<!-- Debug: is_pending_activation = ' . ( slc_fs()->is_pending_activation() ? 'true' : 'false' ) . ' -->';
					}

					// Check if Freemius is properly set up
					if ( ! slc_fs()->is_registered() || slc_fs()->is_anonymous() ) {
						echo '<p>' . esc_html__( 'Please complete the Freemius opt-in process first.', 'secure-login-collector' ) . '</p>';

						// Show opt-in button if available
						if ( method_exists( slc_fs(), 'get_activation_url' ) ) {
							// Use onclick to trigger Freemius opt-in dialog
							echo '<button type="button" class="button button-primary" onclick="if(typeof slc_fs !== \'undefined\' && slc_fs.opt_in) { slc_fs.opt_in(); } else { window.location.href = \'' . esc_url( slc_fs()->get_activation_url() ) . '\'; }">' .
								esc_html__( 'Complete Activation', 'secure-login-collector' ) . '</button>';
						} else {
							echo '<a href="' . esc_url( admin_url( 'admin.php?page=secure-login-collector' ) ) . '" class="button button-primary">' .
								esc_html__( 'Go to Plugin Settings', 'secure-login-collector' ) . '</a>';
						}
					} else {
						// Try different methods to get the upgrade URL
						$upgrade_url = slc_fs()->get_upgrade_url();

						if ( ! $upgrade_url && method_exists( slc_fs(), 'checkout_url' ) ) {
							// Try checkout URL as fallback
							$upgrade_url = slc_fs()->checkout_url();
						}

						if ( $upgrade_url ) {
							echo '<a href="' . esc_url( $upgrade_url ) . '" class="button button-primary button-hero">' .
								esc_html__( 'Upgrade to Pro', 'secure-login-collector' ) . '</a>';
							echo '<p style="margin-top: 10px; font-size: 12px; color: #666;">' .
								esc_html__( 'Secure checkout powered by Freemius', 'secure-login-collector' ) . '</p>';
						} else {
							// Manual checkout link
							echo '<p>' . esc_html__( 'Automatic upgrade URL not available.', 'secure-login-collector' ) . '</p>';
							echo '<a href="https://checkout.freemius.com/mode/dialog/plugin/19897/plan/33427/" class="button button-primary button-hero" target="_blank">' .
								esc_html__( 'Upgrade to Pro', 'secure-login-collector' ) . '</a>';
						}
					}
				} else {
					echo '<p>' . esc_html__( 'Freemius SDK not loaded. Please install the SDK.', 'secure-login-collector' ) . '</p>';
				}
				?>
			</div>
		</div>

		<style>
			.slc-pricing-wrapper {
				display: flex;
				gap: 30px;
				margin-top: 30px;
			}

			.slc-pricing-card {
				flex: 1;
				border: 2px solid #ddd;
				border-radius: 8px;
				padding: 30px;
				background: #fff;
			}

			.slc-pricing-card.pro {
				border-color: #2271b1;
				box-shadow: 0 4px 12px rgba(34, 113, 177, 0.15);
			}

			.slc-pricing-card h2 {
				margin-top: 0;
				color: #2271b1;
			}

			.slc-pricing-card .price {
				font-size: 32px;
				font-weight: bold;
				color: #2271b1;
				margin: 20px 0;
			}

			.slc-pricing-card ul {
				list-style: none;
				padding: 0;
			}

			.slc-pricing-card li {
				padding: 8px 0;
				font-size: 16px;
			}

			.slc-pricing-card .button-hero {
				width: 100%;
				margin-top: 20px;
				font-size: 18px;
				padding: 12px 24px;
				height: auto;
			}
		</style>
	</div>
	<?php
	*/
	return '';
}

/**
 * Customize trial message
 */
function slc_fs_trial_promotion_message( $message ) {
	return sprintf(
		__( 'Hey there! Want to try the Pro version of Secure Login Collector? Start your 14-day free trial and experience passkey-derived encryption!', 'secure-login-collector' ),
		'<b>' . slc_fs()->get_plugin_name() . '</b>'
	);
}
if ( function_exists( 'slc_fs' ) ) {
	slc_fs()->add_filter( 'trial_promotion_message', 'slc_fs_trial_promotion_message' );
}

/**
 * Add admin notices for pro features
 */
function slc_fs_admin_notices() {
	if ( ! function_exists( 'slc_fs' ) ) {
		return;
	}

	if ( ! slc_fs()->is_paying() && isset( $_GET['page'] ) && 'secure-login-collector-account' === $_GET['page'] ) {
		$passkey_registered = get_option( 'secure_login_passkey_registered', false );

		if ( $passkey_registered ) {
			?>
			<div class="notice notice-info">
				<p>
					<?php
					printf(
						/* translators: %s: upgrade link */
						esc_html__( 'You have a passkey registered but need the Pro version to use passkey encryption. %s', 'secure-login-collector' ),
						'<a href="' . esc_url( slc_fs()->get_upgrade_url() ) . '">' . esc_html__( 'Upgrade Now', 'secure-login-collector' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}
}
add_action( 'admin_notices', 'slc_fs_admin_notices' );

/**
 * Override plugin action links
 */
function slc_fs_plugin_action_links( $links ) {
	if ( function_exists( 'slc_fs' ) && slc_fs()->is_not_paying() ) {
		// Use Freemius's proper upgrade URL
		$upgrade_url = slc_fs()->get_upgrade_url();

		// If no upgrade URL available, use account page
		if ( empty( $upgrade_url ) ) {
			$upgrade_url = slc_fs()->get_account_url();
		}

		$links['go-pro'] = '<a href="' . esc_url( $upgrade_url ) . '" style="color: #2271b1; font-weight: bold;">' .
			esc_html__( 'Go Pro', 'secure-login-collector' ) . '</a>';
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( SECURE_LOGIN_PLUGIN_DIR . 'secure-login-collector.php' ), 'slc_fs_plugin_action_links' );

/**
 * Custom menu items
 */
function slc_fs_custom_menu_items() {
	if ( function_exists( 'slc_fs' ) && slc_fs()->is_not_paying() ) {
		// Add pricing page under our plugin menu
		add_submenu_page(
			'secure-login-collector',
			__( 'Pricing', 'secure-login-collector' ),
			__( 'Pricing', 'secure-login-collector' ),
			'manage_options',
			'secure-login-collector-pricing',
			'slc_fs_custom_pricing_page'
		);
	}
}
add_action( 'admin_menu', 'slc_fs_custom_menu_items', 99 );
