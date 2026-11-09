<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Premium extension file naming convention.
/**
 * Premium Admin Interface Extension - Passkey AJAX Handlers
 *
 * Extends base admin interface to add passkey-specific AJAX endpoints.
 * Following KISS principle: Only AJAX handlers for pro passkey features.
 *
 * @fs_premium_only
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Admin_Interface_Premium
 *
 * Premium extension of the admin interface providing passkey-based
 * decryption and authentication functionality.
 */
class Seculoco_Admin_Interface_Premium extends Seculoco_Admin_Interface {


	/**
	 * Database manager instance.
	 *
	 * @var Seculoco_Database_Manager
	 */
	private $database_manager;

	/**
	 * Constructor - registers pro AJAX handlers.
	 *
	 * @param string                          $table_name         Database table name.
	 * @param Seculoco_Encryption_Service $encryption_handler Encryption handler instance.
	 * @param Seculoco_Database_Manager   $database_manager   Database manager instance.
	 */
	public function __construct( $table_name, $encryption_handler, $database_manager ) {

		parent::__construct( $table_name, $encryption_handler, $database_manager );

		$this->database_manager = $database_manager;

		// Register pro passkey AJAX handlers (challenge only; key derivation happens in the browser).
		add_action( 'wp_ajax_seculoco_passkey_challenge', array( $this, 'handle_passkey_challenge' ) );

		// Add password manager export options to bulk actions (PRO feature).
		add_filter( 'seculoco_bulk_actions', array( $this, 'add_password_manager_exports' ) );

		// Extend admin localization/config.
		add_filter( 'seculoco_admin_strings', array( $this, 'extend_admin_strings' ) );
		add_filter( 'seculoco_admin_js_config', array( $this, 'extend_admin_config' ) );
		add_filter( 'seculoco_admin_encryption_info', array( $this, 'extend_encryption_info' ), 10, 3 );

		// Surface pro metadata within list tables and worker routines.
		add_filter( 'seculoco_is_entry_undecryptable', array( $this, 'flag_passkey_undecryptable' ), 10, 2 );
		add_filter( 'seculoco_encryption_method_info', array( $this, 'override_encryption_method_info' ), 10, 2 );
		add_filter( 'seculoco_encryption_status', array( $this, 'extend_encryption_status' ), 10, 2 );
	}

	/**
	 * Merge passkey-specific admin strings.
	 *
	 * @param array $strings Existing strings.
	 * @return array
	 */
	public function extend_admin_strings( $strings ) {
		$strings['requesting_passkey']            = __( 'Requesting passkey...', 'secure-login-collector' );
		$strings['passkey_setup_failed']          = __( 'Passkey authentication setup failed: ', 'secure-login-collector' );
		$strings['network_error_passkey']         = __( 'Network error occurred during passkey setup.', 'secure-login-collector' );
		$strings['pro_no_passkey_continue']       = __( 'Pro version detected but no passkey registered. Continue with traditional decryption?', 'secure-login-collector' );
		$strings['authenticate_with_passkey']     = __( 'Authenticate with passkey...', 'secure-login-collector' );
		$strings['webauthn_not_supported']        = __( 'WebAuthn/Passkeys are not supported in this browser.', 'secure-login-collector' );
		$strings['verifying_passkey']             = __( 'Verifying passkey and decrypting...', 'secure-login-collector' );
		$strings['data_decrypted_passkey']        = __( 'Data decrypted with passkey', 'secure-login-collector' );
		$strings['passkey_decryption_failed']     = __( 'Passkey decryption failed: ', 'secure-login-collector' );
		$strings['network_error_passkey_decrypt'] = __( 'Network error occurred during passkey decryption.', 'secure-login-collector' );
		$strings['passkey_auth_failed']           = __( 'Passkey authentication failed:', 'secure-login-collector' );

		return $strings;
	}

	/**
	 * Inject passkey flags into admin JS config.
	 *
	 * @param array $config Existing config.
	 * @return array
	 */
	public function extend_admin_config( $config ) {
		$config['passkeyRegistered'] = (bool) get_option( SECULOCO_OPTION_PASSKEY_REGISTERED, false );
		return $config;
	}

	/**
	 * Add passkey metadata to encryption info AJAX response.
	 *
	 * @param array $info             Default info.
	 * @param array $encrypted_package Stored package.
	 * @param object $entry            DB row.
	 * @return array
	 */
	public function extend_encryption_info( $info, $encrypted_package, $entry ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$info['isProEncrypted'] = ! empty( $encrypted_package['isProEncrypted'] );
		$info['credentialId']   = $encrypted_package['credentialId'] ?? null;
		return $info;
	}

	/**
	 * Mark passkey-protected entries as undecryptable when the Pro wrapped key is gone.
	 *
	 * The wrapped key survives a passkey switch (same keypair, new passkey), so the
	 * credential id stored in the entry is irrelevant; only the key blob matters.
	 *
	 * @param bool   $state Current state.
	 * @param object $item  List table row.
	 * @return bool
	 */
	public function flag_passkey_undecryptable( $state, $item ) {
		if ( $state ) {
			return true;
		}

		$encrypted_data = json_decode( $item->encrypted_data ?? '', true );
		if ( ! is_array( $encrypted_data ) || empty( $encrypted_data['isProEncrypted'] ) ) {
			return false;
		}

		return empty( get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO ) );
	}

	/**
	 * Override encryption method labels for passkey-protected entries.
	 *
	 * @param array  $info  Default info.
	 * @param string $type  Encryption type.
	 * @return array
	 */
	public function override_encryption_method_info( $info, $type ) {
		if ( in_array( $type, array( 'aes-rsa-passkey-v2', 'rsa_passkey_protected' ), true ) ) {
			return array(
				'name'        => __( 'Passkey Protected', 'secure-login-collector' ),
				'class'       => 'encryption-ultra-secure',
				'description' => __( 'AES-256-GCM + RSA with passkey authentication required for decryption.', 'secure-login-collector' ),
			);
		}

		return $info;
	}

	/**
	 * Include passkey status in encryption status array.
	 *
	 * @param array $status  Current status.
	 * @param Seculoco_Unified_Crypto $unified Crypto handler.
	 * @return array
	 */
	public function extend_encryption_status( $status, $unified ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$status['passkey'] = array(
			'has_public_key'  => ! empty( get_option( SECULOCO_OPTION_PUBLIC_KEY_PRO ) ),
			'has_wrapped_key' => ! empty( get_option( SECULOCO_OPTION_WRAPPED_PRIVATE_KEY_PRO ) ),
			'active'          => (bool) get_option( SECULOCO_OPTION_PRO_KEYS_ACTIVE, false ),
			'registered'      => (bool) get_option( SECULOCO_OPTION_PASSKEY_REGISTERED, false ),
		);

		return $status;
	}

	/**
	 * Enqueue premium admin scripts.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Call parent to load base scripts (admin.js and admin-decrypt.js).
		parent::enqueue_admin_scripts( $hook );

		// Only load on our admin page.
		if ( ! in_array( $hook, array( 'toplevel_page_secure-login-collector' ), true ) ) {
			return;
		}

		// Only load premium scripts for pro license users.
		if ( ! Seculoco_License_Manager::has_pro_license() ) {
			return;
		}

		// Enqueue premium decrypt script (passkey-based decryption plugin).
		// Depends on base admin-decrypt.js (seculoco-admin-decrypt) and on the shared
		// passkey crypto helpers in admin-passkey__premium_only.js (seculoco-passkey-pro-js).
		$decrypt_script_path = plugin_dir_path( __FILE__ ) . '../assets/js/admin-decrypt__premium_only.js';
		if ( file_exists( $decrypt_script_path ) ) {
			wp_enqueue_script(
				'seculoco-admin-decrypt-pro',
				plugin_dir_url( __FILE__ ) . '../assets/js/admin-decrypt__premium_only.js',
				array( 'jquery', 'seculoco-admin-decrypt', 'seculoco-passkey-pro-js' ),
				filemtime( $decrypt_script_path ),
				true
			);

			// Pass feature flags to PRO script.
			wp_localize_script(
				'seculoco-admin-decrypt-pro',
				'seculocoFeatures',
				array(
					'isPro'       => true,
					'hasPasskey'  => true,
					'hasExport'   => true,
					'version'     => 'pro',
					'buildDate'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		// Enqueue premium bulk export script (password manager export).
		$bulk_export_script_path = plugin_dir_path( __FILE__ ) . '../assets/js/admin-bulk-export__premium_only.js';
		if ( file_exists( $bulk_export_script_path ) ) {
			wp_enqueue_script(
				'seculoco-admin-bulk-export',
				plugin_dir_url( __FILE__ ) . '../assets/js/admin-bulk-export__premium_only.js',
				array( 'jquery', 'secure-login-admin-js' ),
				filemtime( $bulk_export_script_path ),
				true
			);
		}
	}

	/**
	 * Add password manager export options to bulk actions dropdown.
	 *
	 * Adds 8 password manager export formats for PRO users only.
	 * Free version users will not see these options.
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array Modified bulk actions with export options.
	 */
	public function add_password_manager_exports( $bulk_actions_free ) {

		// Add export options for 8 major password managers.
		$bulk_actions['export-bitwarden'] = __( 'Export to Bitwarden', 'secure-login-collector' );
		$bulk_actions['export-1password'] = __( 'Export to 1Password', 'secure-login-collector' );
		$bulk_actions['export-lastpass']  = __( 'Export to LastPass', 'secure-login-collector' );
		$bulk_actions['export-chrome']    = __( 'Export to Chrome', 'secure-login-collector' );
		$bulk_actions['export-firefox']   = __( 'Export to Firefox', 'secure-login-collector' );
		$bulk_actions['export-safari']    = __( 'Export to Safari', 'secure-login-collector' );
		$bulk_actions['export-dashlane']  = __( 'Export to Dashlane', 'secure-login-collector' );
		$bulk_actions['export-keepass']   = __( 'Export to KeePass', 'secure-login-collector' );

		return array_merge($bulk_actions, $bulk_actions_free);
	}

	/**
	 * Handle passkey authentication challenge.
	 */
	public function handle_passkey_challenge() {
		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		// Verify nonce - accept both admin nonces (from admin.js and admin-decrypt.js).
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) &&
			! wp_verify_nonce( $nonce, 'seculoco_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		// Generate challenge.
		$challenge = base64_encode( random_bytes( 32 ) );

		// Store challenge in transient (expires in 5 minutes).
		set_transient( 'passkey_challenge_' . get_current_user_id(), $challenge, 300 );

		// Get global passkey (site-wide, not per-user).
		$passkey = get_option( SECULOCO_OPTION_GLOBAL_PASSKEY );

		// Format credential for client.
		$formatted_credentials = array();
		if ( ! empty( $passkey ) && isset( $passkey['credential_id'] ) ) {
			$formatted_credentials[] = array(
				'id'   => $passkey['credential_id'],
				'type' => 'public-key',
			);
		}

		wp_send_json_success(
			array(
				'challenge'        => $challenge,
				'credentials'      => $formatted_credentials,
				'timeout'          => 60000,
				'userVerification' => 'required',
			)
		);
	}
}
