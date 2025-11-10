<?php
/**
 * Admin Interface Class
 *
 * Handles admin interface operations including:
 * - Admin pages and menus
 * - Data viewing and management
 * - AJAX handlers for admin operations
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Admin_Interface
 *
 * Handles the admin interface for the secure login collector plugin.
 */
class Seculoco_Admin_Interface {



	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Encryption handler instance.
	 *
	 * @var Seculoco_Encryption_Service
	 */
	private $encryption_handler;

	/**
	 * Database manager instance.
	 *
	 * @var Seculoco_Database_Manager
	 */
	private $database_manager;

	/**
	 * List table instance.
	 *
	 * @var Seculoco_List_Table
	 */
	private $list_table;

	/**
	 * Constructor - initializes admin interface.
	 *
	 * @param string                      $table_name         Database table name.
	 * @param Seculoco_Encryption_Service $encryption_handler Encryption handler instance.
	 * @param Seculoco_Database_Manager   $database_manager   Database manager instance.
	 */
	public function __construct( $table_name, $encryption_handler, $database_manager ) {
		$this->table_name         = $table_name;
		$this->encryption_handler = $encryption_handler;
		$this->database_manager   = $database_manager;

		// Register hooks.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Register AJAX handlers.
		// Decryption is handled client-side only - server never decrypts.
		add_action( 'wp_ajax_seculoco_get_encrypted_entry', array( $this, 'handle_get_encrypted_entry' ) );
		add_action( 'wp_ajax_seculoco_get_encryption_info', array( $this, 'handle_get_encryption_info' ) );
		add_action( 'wp_ajax_seculoco_delete_entry', array( $this, 'handle_delete_ajax' ) );
		add_action( 'wp_ajax_seculoco_extend_entry', array( $this, 'handle_extend_ajax' ) );
		add_action( 'wp_ajax_seculoco_update_metadata', array( $this, 'handle_update_metadata_ajax' ) );
		add_action( 'wp_ajax_seculoco_bulk_export', array( $this, 'handle_bulk_export_ajax' ) );

		// Add screen option for items per page.
		add_action( 'load-toplevel_page_secure-login-collector', array( $this, 'add_screen_options' ) );
	}

	/**
	 * Add screen options for the admin page.
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'secure-login-collector' ),
				'default' => 20,
				'option'  => 'login_entries_per_page',
			)
		);

		// Initialize the list table.
		$this->list_table = new Seculoco_List_Table(
			$this->table_name,
			$this->database_manager,
			$this->encryption_handler
		);
	}

	/**
	 * Add admin menu for viewing collected data.
	 */
	public function add_admin_menu() {
		$menu_title = __( 'Login Data', 'secure-login-collector' );

		add_menu_page(
			__( 'Secure Login Data', 'secure-login-collector' ),
			$menu_title,
			'manage_options',
			'secure-login-collector',
			array( $this, 'admin_page' ),
			'dashicons-lock',
			30
		);
	}

	/**
	 * Enqueue admin scripts and localize AJAX data.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on our admin page.
		if ( ! in_array( $hook, array( 'toplevel_page_secure-login-collector' ), true ) ) {
			return;
		}

		// Enqueue modern admin CSS (includes all necessary styles).
		wp_enqueue_style(
			'secure-login-admin-modern-css',
			plugin_dir_url( __FILE__ ) . '../assets/css/admin-modern.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . '../assets/css/admin-modern.css' )
		);

		// Enqueue JavaScript file.
		wp_enqueue_script(
			'secure-login-admin-js',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		// Enqueue base decryption framework (loaded for everyone).
		wp_enqueue_script(
			'seculoco-admin-decrypt',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin-decrypt.js',
			array( 'jquery' ),
			filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/admin-decrypt.js' ),
			true
		);

		// Localize script with AJAX data.
		$ajax_data = array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'seculoco_nonce' ),
			'strings' => array(
				'not_provided'                => __( 'Not provided', 'secure-login-collector' ),
				'saving'                      => __( 'Saving...', 'secure-login-collector' ),
				'save_failed'                 => __( 'Save failed: ', 'secure-login-collector' ),
				'unknown_error'               => __( 'Unknown error', 'secure-login-collector' ),
				'network_error_save'          => __( 'Network error occurred during save.', 'secure-login-collector' ),
				'decrypt_data'                => __( 'Decrypt data', 'secure-login-collector' ),
				'enter_encryption_key'        => __( 'This entry was created before automatic key reconstruction was available. Please enter the encryption key manually:', 'secure-login-collector' ),
				'decrypting'                  => __( 'Decrypting...', 'secure-login-collector' ),
				'data_decrypted'              => __( 'Data decrypted', 'secure-login-collector' ),
				'decryption_failed'           => __( 'Decryption failed: ', 'secure-login-collector' ),
				'network_error_decryption'    => __( 'Network error occurred during decryption.', 'secure-login-collector' ),
				'error_processing_decryption' => __( 'Error processing decryption: ', 'secure-login-collector' ),
				'confirm_extend_retention'    => __( 'Are you sure you want to extend the retention period for this entry?', 'secure-login-collector' ),
				'extending'                   => __( 'Extending...', 'secure-login-collector' ),
				'retention_extended'          => __( 'Retention period extended successfully.', 'secure-login-collector' ),
			),
		);

		$ajax_data['strings'] = apply_filters( 'seculoco_admin_strings', $ajax_data['strings'] );

		// Primary localization: seculocoAjax (standard variable name)
		wp_localize_script( 'secure-login-admin-js', 'seculocoAjax', $ajax_data );

		// Backward compatibility: Also provide as seculocoAdmin during transition period
		// Both variables contain identical data to support legacy premium scripts
		wp_localize_script( 'secure-login-admin-js', 'seculocoAdmin', $ajax_data );

		// CRITICAL: Also localize for the decrypt script (needed for AJAX calls in admin-decrypt.js)
		// Provide both variable names for maximum compatibility
		wp_localize_script( 'seculoco-admin-decrypt', 'seculocoAjax', $ajax_data );
		wp_localize_script( 'seculoco-admin-decrypt', 'seculocoAdmin', $ajax_data );

		// Localize script with configuration data.
		$admin_config = array(
			'currentUserId' => get_current_user_id(),
		);

		// Allow pro version to modify config.
		$admin_config = apply_filters( 'seculoco_admin_js_config', $admin_config );

		wp_localize_script(
			'secure-login-admin-js',
			'seculocoConfig',
			$admin_config
		);

		// Localize script with translatable messages.
		wp_localize_script(
			'secure-login-admin-js',
			'seculocoMessages',
			array(
				'noDecryptedData'                     => __( 'No decrypted data available. Please decrypt the data first.', 'secure-login-collector' ),
				'saving'                              => __( 'Saving...', 'secure-login-collector' ),
				'unknownError'                        => __( 'Unknown error', 'secure-login-collector' ),
				'networkError'                        => __( 'Network error occurred while saving data.', 'secure-login-collector' ),
				'bulkDecryptWithPasskey'              => __( 'Bulk Decrypt with Passkey', 'secure-login-collector' ),
				'authenticateWithPasskeyToDecryptAll' => __( 'Authenticate with Passkey to Decrypt All', 'secure-login-collector' ),
				'bulkDecryptionCompleted'             => __( 'Bulk decryption completed. CSV file downloaded.', 'secure-login-collector' ),
			)
		);

		// Add inline script to make nonce available globally (kept for backward compatibility).
		wp_add_inline_script( 'secure-login-admin-js', 'window.secureLoginNonce = "' . wp_create_nonce( 'seculoco_nonce' ) . '";' );
	}

	/**
	 * Admin page for viewing collected login data using WP_List_Table.
	 */
	public function admin_page() {
		// Initialize list table if not already done.
		if ( ! $this->list_table ) {
			$this->list_table = new Seculoco_List_Table(
				$this->table_name,
				$this->database_manager,
				$this->encryption_handler
			);
		}

		// Prepare table items.
		$this->list_table->prepare_items();

		// Display admin notices.
		settings_errors( 'seculoco_bulk' );

		?>
		<div class="wrap seculoco-admin-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Secure Login Data', 'secure-login-collector' ); ?></h1>
			<hr class="wp-header-end">

			

		<?php
		// Allow pro version to show diagnostic info and fix button.
		do_action( 'seculoco_dashboard_diagnostics' );
		?>

			<div class="seculoco-card">
				<div class="seculoco-card-body">
					<form method="post" class="secure-login-admin-table">
         <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameter for page navigation. ?>
						<input type="hidden" name="page" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) ); ?>" />
		<?php
		$this->list_table->search_box( __( 'Search entries', 'secure-login-collector' ), 'secure-login-entries' );
		$this->list_table->display();
		?>
					</form>
				</div>
			</div>

		<?php
	}


	// AJAX Handlers.

	/**
	 * Handle bulk export AJAX request.
	 */
	public function handle_bulk_export_ajax() {
		// Verify nonce for security.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'seculoco_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		// Check if user has admin capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		// Get entry IDs and manager - either from POST (new method) or transient (legacy).
		if ( isset( $_POST['entry_ids'] ) && isset( $_POST['manager'] ) ) {
			// New method: entry IDs passed directly from JavaScript.
			$ids     = isset( $_POST['entry_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['entry_ids'] ) ) : array();
			$manager = isset( $_POST['manager'] ) ? sanitize_text_field( wp_unslash( $_POST['manager'] ) ) : '';
		} else {
			// Legacy method: read from transient (created by form submission).
			$export_data = get_transient( 'seculoco_bulk_export_' . get_current_user_id() );

			if ( ! $export_data ) {
				wp_send_json_error( __( 'Export request not found or expired.', 'secure-login-collector' ) );
				return;
			}

			$manager = $export_data['manager'];
			$ids     = $export_data['ids'];

			// Clean up the transient.
			delete_transient( 'seculoco_bulk_export_' . get_current_user_id() );
		}

		// Collect export data.
		$csv_data = array();

		foreach ( $ids as $id ) {
			$row = $this->database_manager->get_entry( $id );
			if ( ! $row ) {
				continue;
			}

			$metadata        = json_decode( $row->metadata, true );
			$encryption_type = $metadata['encryption_type'] ?? 'aes-rsa-password-v3';

			// CRITICAL: Server NEVER decrypts. Always return encrypted packages for client-side decryption.
			// This maintains zero-knowledge architecture for both FREE and PRO entries.
			$csv_data[] = array(
				'id'                => $id,
				'name'              => $metadata['name'] ?? 'Unknown',
				'website'           => $metadata['login_url'] ?? $metadata['service_name'] ?? '',
				'encrypted_data'    => $row->encrypted_data,
				'encrypted_aes_key' => $row->encrypted_aes_key ?? null,
				'iv'                => $row->iv ?? null,
				'encryption_type'   => $encryption_type,
			);
		}

		wp_send_json_success(
			array(
				'manager' => $manager,
				'data'    => $csv_data,
				/* translators: %d: number of entries */
				'message' => sprintf( __( 'Bulk export prepared for %d entries.', 'secure-login-collector' ), count( $csv_data ) ),
			)
		);
	}


	/**
	 * Handle delete AJAX request.
	 */
	public function handle_delete_ajax() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'seculoco_nonce' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		$delete_id = intval( wp_unslash( $_POST['delete_id'] ?? 0 ) );
		if ( empty( $delete_id ) ) {
			wp_send_json_error( __( 'Missing required data.', 'secure-login-collector' ) );
			return;
		}

		$result = $this->database_manager->delete_entry( $delete_id );
		if ( false === $result ) {
			wp_send_json_error( __( 'Failed to delete data.', 'secure-login-collector' ) );
			return;
		}

		wp_send_json_success( __( 'Data deleted successfully.', 'secure-login-collector' ) );
	}

	/**
	 * Handle extend retention AJAX request.
	 */
	public function handle_extend_ajax() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'seculoco_nonce' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		$extend_id = intval( wp_unslash( $_POST['extend_id'] ?? 0 ) );
		if ( empty( $extend_id ) ) {
			wp_send_json_error( __( 'Missing required data.', 'secure-login-collector' ) );
			return;
		}

		$result = $this->database_manager->extend_retention( $extend_id );
		if ( false === $result ) {
			wp_send_json_error( __( 'Failed to extend retention.', 'secure-login-collector' ) );
			return;
		}

		// Get updated expiration display.
		$row                    = $this->database_manager->get_entry( $extend_id );
		$is_expired             = isset( $row->is_expired ) ? $row->is_expired : 0;
		$new_expiration_display = $this->database_manager->calculate_expiration( $row->retention_until, $is_expired );
		$expiration_days        = seculoco_get_expiration_days();
		wp_send_json_success(
			array(
				/* translators: 1: Number of days the retention period was extended. */
				'message'        => sprintf( __( 'Retention period extended by %1$d days.', 'secure-login-collector' ), $expiration_days ),
				'new_expiration' => $new_expiration_display,
			)
		);
	}

	/**
	}
		/**
	 * Handle update metadata AJAX request.
	 */
	public function handle_update_metadata_ajax() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'seculoco_nonce' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		$update_id = intval( wp_unslash( $_POST['update_id'] ?? 0 ) );
     // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in the loop below after validation.
		$new_metadata = wp_unslash( $_POST['metadata'] ?? array() );

		if ( empty( $update_id ) || empty( $new_metadata ) ) {
			wp_send_json_error( __( 'Missing required data.', 'secure-login-collector' ) );
			return;
		}

		// Get current metadata and update only the specified fields.
		$row = $this->database_manager->get_entry( $update_id );
		if ( ! $row ) {
			wp_send_json_error( __( 'Entry not found.', 'secure-login-collector' ) );
			return;
		}

		$current_metadata = json_decode( $row->metadata, true );

		// Update only the provided fields.
		foreach ( $new_metadata as $field => $value ) {
			$current_metadata[ $field ] = sanitize_text_field( $value );
		}

		$updated_metadata = wp_json_encode( $current_metadata );

		$result = $this->database_manager->update_entry_metadata( $update_id, $updated_metadata );

		if ( false === $result ) {
			wp_send_json_error( __( 'Failed to update metadata.', 'secure-login-collector' ) );
			return;
		}

		wp_send_json_success( __( 'Metadata updated successfully.', 'secure-login-collector' ) );
	}


	/**
	 * Handle AJAX request to get encryption info for an entry.
	 *
	 * @return void
	 */
	public function handle_get_encryption_info() {
		// Check permissions and nonce.
		if ( ! current_user_can( 'manage_options' )
			|| ! isset( $_POST['nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'seculoco_nonce' )
		) {
			wp_send_json_error( __( 'Invalid security token or insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			wp_send_json_error( __( 'Invalid entry ID.', 'secure-login-collector' ) );
			return;
		}

		// Get the encrypted data from database.
		$entry = $this->database_manager->get_entry( $entry_id );
		if ( ! $entry ) {
			wp_send_json_error( __( 'Entry not found.', 'secure-login-collector' ) );
			return;
		}

		// Parse the encrypted package.
		$encrypted_package = json_decode( $entry->encrypted_data, true );
		if ( ! $encrypted_package || ! isset( $encrypted_package['version'] ) || 2 !== $encrypted_package['version'] ) {
			wp_send_json_error( __( 'Invalid encryption format.', 'secure-login-collector' ) );
			return;
		}

		$metadata = json_decode( $entry->metadata, true );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$encryption_info = array(
			'encryptionType' => isset( $metadata['encryption_type'] ) ? $metadata['encryption_type'] : 'aes-rsa-password-v3',
		);

		/**
		 * Filter: seculoco_admin_encryption_info
		 *
		 * Allows premium builds to expose additional encryption metadata (e.g. passkey identifiers).
		 *
		 * @param array $encryption_info   Default encryption info for free entries.
		 * @param array $encrypted_package Raw encrypted payload stored in the database.
		 * @param object $entry            Database row for the entry.
		 */
		$encryption_info = apply_filters( 'seculoco_admin_encryption_info', $encryption_info, $encrypted_package, $entry );

		// Return encryption info.
		wp_send_json_success( $encryption_info );
	}

	/**
	 * Handle getting encrypted entry for client-side decryption.
	 */
	public function handle_get_encrypted_entry() {
		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'secure-login-collector' ) );
			return;
		}

		// Verify nonce - accept both admin nonces (from admin.js and admin-decrypt.js).
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' )
			&& ! wp_verify_nonce( $nonce, 'seculoco_nonce' )
		) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		$entry_id = intval( $_POST['id'] ?? 0 );
		if ( ! $entry_id ) {
			wp_send_json_error( __( 'Invalid entry ID.', 'secure-login-collector' ) );
			return;
		}

		// Get encrypted data from database.
		$entry = $this->database_manager->get_entry( $entry_id );
		if ( ! $entry ) {
			wp_send_json_error( __( 'Entry not found.', 'secure-login-collector' ) );
			return;
		}

		// Parse encrypted data (entry is an object, not array).
		$encrypted_data = json_decode( $entry->encrypted_data, true );
		if ( ! $encrypted_data ) {
			wp_send_json_error( __( 'Invalid encrypted data format.', 'secure-login-collector' ) );
			return;
		}

		$metadata = json_decode( $entry->metadata, true );

		// Return encrypted package for client-side decryption.
		// Note: The frontend sends camelCase, we return snake_case for consistency with JS expectations.
		wp_send_json_success(
			array(
				'encrypted_data'    => $encrypted_data['encryptedData'] ?? '',
				'encrypted_aes_key' => $encrypted_data['rsaEncryptedKey'] ?? '', // Frontend uses rsaEncryptedKey, not encryptedAESKey.
				'iv'                => $encrypted_data['iv'] ?? '',
				'salt'              => $encrypted_data['salt'] ?? '',
				'version'           => $encrypted_data['version'] ?? 'v2',
				'encryption_type'   => $metadata['encryption_type'] ?? 'aes-rsa-password-v3',
				'is_pro_encrypted'  => $metadata['is_pro_encrypted'] ?? false,
				'metadata'          => $metadata,
			)
		);
	}
}
