<?php
/**
 * Simple Account Page
 *
 * @package SecureLoginCollector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display simple account information
 */
function slc_simple_account_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Account & License', 'secure-login-collector' ); ?></h1>
		
		<?php if ( function_exists( 'slc_fs' ) && slc_fs() ) : ?>
			
			<?php if ( slc_fs()->is_registered() ) : ?>
				<div class="card">
					<h2><?php esc_html_e( 'License Status', 'secure-login-collector' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Status', 'secure-login-collector' ); ?></th>
							<td>
								<?php if ( slc_fs()->is_paying() ) : ?>
									<span style="color: #46b450; font-weight: bold;">✓ <?php esc_html_e( 'Pro Version Active', 'secure-login-collector' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232;"><?php esc_html_e( 'Free Version', 'secure-login-collector' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						
						<?php if ( slc_fs()->is_paying() && method_exists( slc_fs(), 'get_user' ) ) : ?>
							<?php $user = slc_fs()->get_user(); ?>
							<?php if ( $user ) : ?>
								<tr>
									<th><?php esc_html_e( 'Licensed To', 'secure-login-collector' ); ?></th>
									<td><?php echo esc_html( $user->email ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endif; ?>
						
						<?php if ( slc_fs()->is_paying() && method_exists( slc_fs(), '_get_license' ) ) : ?>
							<?php $license = slc_fs()->_get_license(); ?>
							<?php if ( $license && isset( $license->expiration ) ) : ?>
								<tr>
									<th><?php esc_html_e( 'Expires', 'secure-login-collector' ); ?></th>
									<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $license->expiration ) ) ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endif; ?>
					</table>
					
					<p>
						<?php if ( slc_fs()->is_not_paying() ) : ?>
							<a href="<?php echo esc_url( slc_fs()->get_upgrade_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Upgrade to Pro', 'secure-login-collector' ); ?></a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=secure-login-collector-pricing' ) ); ?>" class="button"><?php esc_html_e( 'View Pricing', 'secure-login-collector' ); ?></a>
						<?php else : ?>
							<?php if ( method_exists( slc_fs(), 'get_account_url' ) ) : ?>
								<a href="<?php echo esc_url( slc_fs()->get_account_url() ); ?>" class="button" target="_blank"><?php esc_html_e( 'Manage License', 'secure-login-collector' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</p>
				</div>
				
			<?php else : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Please complete the plugin activation to access account features.', 'secure-login-collector' ); ?></p>
					<?php if ( method_exists( slc_fs(), 'get_activation_url' ) ) : ?>
						<p><a href="<?php echo esc_url( slc_fs()->get_activation_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Complete Activation', 'secure-login-collector' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			
		<?php else : ?>
			<div class="notice notice-error">
				<h3><?php esc_html_e( 'Freemius Not Configured', 'secure-login-collector' ); ?></h3>
				<p><?php esc_html_e( 'The licensing system is not properly configured. This could mean:', 'secure-login-collector' ); ?></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'The Freemius SDK is not installed in /vendor/freemius/', 'secure-login-collector' ); ?></li>
					<li><?php esc_html_e( 'There was an error loading the SDK', 'secure-login-collector' ); ?></li>
				</ul>
			</div>
		<?php endif; ?>
		
		<div class="card" style="margin-top: 20px;">
			<h2><?php esc_html_e( 'Plugin Information', 'secure-login-collector' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Version', 'secure-login-collector' ); ?></th>
					<td><?php echo esc_html( SECURE_LOGIN_VERSION ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Pro Features', 'secure-login-collector' ); ?></th>
					<td>
						<?php
						$pro_enabled = ( function_exists( 'slc_fs' ) && slc_fs() && slc_fs()->is_paying() );

						if ( $pro_enabled ) {
							echo '<span style="color: #46b450;">✓ ' . esc_html__( 'Enabled', 'secure-login-collector' ) . '</span>';
						} else {
							echo '<span style="color: #dc3232;">✗ ' . esc_html__( 'Disabled', 'secure-login-collector' ) . '</span>';
						}
						?>
					</td>
				</tr>
			</table>
		</div>
	</div>
	<?php
}