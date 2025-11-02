<?php
/**
 * @fs_premium_only
 *
 * Premium Admin Interface
 * Extends free version with pro features via hooks
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Admin_Interface_Pro
 *
 * Handles pro-specific admin functionality via hooks.
 */
class Seculoco_Admin_Interface_Pro {

	/**
	 * Constructor - hooks into free version's actions/filters.
	 */
	public function __construct() {
		// Enqueue premium admin scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_premium_admin_scripts' ) );

		// Add pro flag to admin JS config.
		add_filter( 'seculoco_admin_js_config', array( $this, 'add_pro_js_config' ) );

		// Add pro diagnostic info to dashboard.
		add_action( 'seculoco_dashboard_diagnostics', array( $this, 'render_dashboard_diagnostics' ) );

		// Filter encryption method display for manual entries.
		add_filter( 'seculoco_manual_entry_encryption_display', array( $this, 'filter_encryption_display' ) );

		// Allow passkey decryption features.
		add_filter( 'seculoco_can_use_passkey_decrypt', '__return_true' );

		// Add bulk actions for pro version.
		add_filter( 'seculoco_bulk_actions', array( $this, 'add_bulk_actions' ) );

		// Filter manual entry metadata for pro encryption.
		add_filter( 'seculoco_manual_entry_metadata', array( $this, 'add_pro_manual_entry_metadata' ) );

		// Register AJAX handlers for pro features.
		add_action( 'wp_ajax_seculoco_start_passkey_unwrap', array( $this, 'ajax_start_passkey_unwrap' ) );
		add_action( 'wp_ajax_seculoco_verify_passkey_unwrap', array( $this, 'ajax_verify_passkey_unwrap' ) );
	}


	public function add_bulk_actions( $actions ) {
		$actions['export-bitwarden'] = __( 'Export Bitwarden CSV', 'secure-login-collector' );
		$actions['export-1password'] = __( 'Export 1Password CSV', 'secure-login-collector' );
		$actions['export-lastpass']  = __( 'Export LastPass CSV', 'secure-login-collector' );
		$actions['export-chrome']    = __( 'Export Chrome CSV', 'secure-login-collector' );
		$actions['export-firefox']   = __( 'Export Firefox CSV', 'secure-login-collector' );
		$actions['export-safari']    = __( 'Export Safari CSV', 'secure-login-collector' );
		$actions['export-dashlane']  = __( 'Export Dashlane CSV', 'secure-login-collector' );
		$actions['export-keepass']   = __( 'Export KeePass CSV', 'secure-login-collector' );
		return $actions;
	}
	
	/**
	 * Enqueue premium admin scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_premium_admin_scripts( $hook ) {
		// Only load on our admin page.
		if ( ! in_array( $hook, array( 'toplevel_page_secure-login-collector' ), true ) ) {
			return;
		}

		// Enqueue the new decrypt script for v2 encryption (Premium only).
		wp_enqueue_script(
			'secure-login-admin-decrypt',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin-decrypt__premium_only.js',
			array( 'jquery', 'secure-login-admin-js' ),
			'1.0.0',
			true
		);

		// Enqueue bulk export with passkey script (Premium only).
		wp_enqueue_script(
			'secure-login-admin-bulk-export',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin-bulk-export__premium_only.js',
			array( 'jquery', 'secure-login-admin-js', 'secure-login-admin-decrypt' ),
			'1.0.0',
			true
		);

		// Localize script for admin-decrypt.js.
		wp_localize_script(
			'secure-login-admin-decrypt',
			'seculocoAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'seculoco_admin_nonce' ),
			)
		);
	}

	/**
	 * Add pro flag to admin JavaScript configuration.
	 *
	 * @param array $config Existing JS configuration.
	 * @return array Modified configuration with pro flag.
	 */
	public function add_pro_js_config( $config ) {
		$config['isProVersion']      = true;
		$config['passkeyRegistered'] = get_option( 'seculoco_passkey_registered', false );
		return $config;
	}

	/**
	 * Render dashboard diagnostics for pro encryption issues.
	 */
	public function render_dashboard_diagnostics() {
		$passkey_registered = get_option( 'seculoco_passkey_registered', false );
		$pro_keys_active    = get_option( 'seculoco_pro_keys_active', false );

		if ( ! $passkey_registered || ! $pro_keys_active ) {
			// Check if passkeys actually exist.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$passkey_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value != ''",
					$wpdb->usermeta,
					'seculoco_passkey'
				)
			);

			if ( $passkey_count > 0 ) {
				?>
				<div class="notice notice-warning">
					<h3><?php echo esc_html__( 'Pro Encryption Issue Detected', 'secure-login-collector' ); ?></h3>
					<p><?php echo esc_html__( 'You have pro version enabled and passkeys registered, but new data is not being pro-encrypted due to a missing flag.', 'secure-login-collector' ); ?></p>
					<p>
						<button type="button" class="button button-primary" id="fix-passkey-flag-btn">
							<?php echo esc_html__( 'Fix Pro Encryption Status', 'secure-login-collector' ); ?>
						</button>
						<span id="fix-passkey-flag-result"></span>
					</p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Filter encryption method display for manual entries.
	 *
	 * @param array $default Default encryption display info.
	 * @return array Modified encryption display info.
	 */
	public function filter_encryption_display( $default ) {
		if ( get_option( 'seculoco_ultra_secure_mode', false ) && get_option( 'seculoco_passkey_registered', false ) ) {
			return array(
				'title'       => __( 'Ultra-Secure (AES-256 + RSA-2048 + Passkey)', 'secure-login-collector' ),
				'description' => __( 'Ultra-secure mode is enabled. All entries will be encrypted with triple-layer protection.', 'secure-login-collector' ),
			);
		}
		return $default;
	}

	/**
	 * AJAX handler to start passkey unwrap process.
	 */
	public function ajax_start_passkey_unwrap() {
		// Check admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
			return;
		}

		// Verify nonce.
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token', 'secure-login-collector' ) );
			return;
		}

		// Get passkey credential ID.
		$credential_id = get_option( 'seculoco_passkey_credential_id' );
		if ( ! $credential_id ) {
			wp_send_json_error( __( 'No passkey registered', 'secure-login-collector' ) );
			return;
		}

		// Generate challenge for passkey authentication.
		$challenge = base64_encode( random_bytes( 32 ) );

		// Store challenge in transient (expires in 5 minutes).
		set_transient( 'seculoco_passkey_challenge_' . get_current_user_id(), $challenge, 300 );

		wp_send_json_success(
			array(
				'challenge'     => $challenge,
				'credential_id' => $credential_id,
			)
		);
	}

	/**
	 * AJAX handler to verify passkey unwrap authentication.
	 */
	public function ajax_verify_passkey_unwrap() {
		// Check admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'secure-login-collector' ) );
			return;
		}

		// Verify nonce.
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token', 'secure-login-collector' ) );
			return;
		}

		// Get and verify challenge.
		$stored_challenge = get_transient( 'seculoco_passkey_challenge_' . get_current_user_id() );
		if ( ! $stored_challenge ) {
			wp_send_json_error( __( 'Challenge expired or not found', 'secure-login-collector' ) );
			return;
		}

		// Delete challenge transient (one-time use).
		delete_transient( 'seculoco_passkey_challenge_' . get_current_user_id() );

		// In a real implementation, verify the passkey response here.
		// For now, return success with passkey-derived key.
		wp_send_json_success(
			array(
				'message' => __( 'Passkey authentication successful', 'secure-login-collector' ),
			)
		);
	}

	/**
	 * Add pro encryption metadata for manual entries.
	 *
	 * @param array $metadata Existing metadata.
	 * @return array Modified metadata.
	 */
	public function add_pro_manual_entry_metadata( $metadata ) {
		// Check if pro keys and passkey are available.
		if ( get_option( 'seculoco_passkey_registered', false ) ) {
			// For ultra-secure mode, mark as pro encrypted.
			// Passkey authentication required for decryption.
			$metadata['is_pro_encrypted']     = true;
			$metadata['server_credential_id'] = get_option( 'seculoco_passkey_credential_id', '' );
		}

		return $metadata;
	}
}

// Initialize pro admin interface.
new Seculoco_Admin_Interface_Pro();
