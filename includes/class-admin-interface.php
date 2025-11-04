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

// Include WP_List_Table if not already included.
if ( ! class_exists( 'WP_List_Table' ) ) {
	include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Secure Login List Table Class extending WP_List_Table
 *
 * Custom list table for displaying secure login data with WordPress admin styling.
 */
class Seculoco_List_Table extends WP_List_Table {


	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Database manager instance.
	 *
	 * @var Seculoco_Database_Manager
	 */
	private $database_manager;

	/**
	 * Encryption handler instance.
	 *
	 * @var Seculoco_Encryption_Handler_V2
	 */
	private $encryption_handler;

	/**
	 * Constructor - initializes list table.
	 *
	 * @param string                         $table_name         Database table name.
	 * @param Seculoco_Database_Manager      $database_manager   Database manager instance.
	 * @param Seculoco_Encryption_Handler_V2 $encryption_handler Encryption handler instance.
	 */
	public function __construct( $table_name, $database_manager, $encryption_handler ) {
		$this->table_name         = $table_name;
		$this->database_manager   = $database_manager;
		$this->encryption_handler = $encryption_handler;

		parent::__construct(
			array(
				'singular' => 'login_entry',
				'plural'   => 'login_entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define table columns.
	 *
	 * @return array Column definitions.
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'email'      => __( 'Email Address', 'secure-login-collector' ),
			'name'       => __( 'Name', 'secure-login-collector' ),
			'login_url'  => __( 'Login URL', 'secure-login-collector' ),
			'created_at' => __( 'Date', 'secure-login-collector' ),
			'encryption' => __( 'Encryption Method', 'secure-login-collector' ),
			'expires'    => __( 'Expires In', 'secure-login-collector' ),
			'actions'    => __( 'Actions', 'secure-login-collector' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array Sortable column definitions.
	 */
	public function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'name'       => array( 'name', false ),
			'login_url'  => array( 'login_url', false ),
			'created_at' => array( 'created_at', false ),
			'encryption' => array( 'encryption', false ),
			'expires'    => array( 'expires', false ),
		);
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array Bulk action definitions.
	 */
	public function get_bulk_actions() {

		$bulk_actions = array(
			'delete' => __( 'Delete', 'secure-login-collector' ),
		);
		return apply_filters( 'seculoco_bulk_actions', $bulk_actions );
	}

	/**
	 * Render checkbox column.
	 *
	 * @param object $item Row data.
	 * @return string Checkbox HTML.
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="login_entries[]" value="%s" class="select-entry" data-id="%s" />',
			$item->id,
			$item->id
		);
	}

	/**
	 * Render email column.
	 *
	 * @param object $item Row data.
	 * @return string Email column HTML.
	 */
	public function column_email( $item ) {
		$metadata = $this->parse_metadata( $item->metadata );
		$email    = $metadata['email'] ?? __( 'N/A', 'secure-login-collector' );

		return sprintf(
			'<span class="editable-field" data-field="email" data-id="%s">%s</span>',
			$item->id,
			esc_html( $email )
		);
	}

	/**
	 * Render name column.
	 *
	 * @param object $item Row data.
	 * @return string Name column HTML.
	 */
	public function column_name( $item ) {
		$metadata = $this->parse_metadata( $item->metadata );
		$name     = $metadata['name'] ?? __( 'N/A', 'secure-login-collector' );

		return sprintf(
			'<span class="editable-field" data-field="name" data-id="%s">%s</span>',
			$item->id,
			esc_html( $name )
		);
	}

	/**
	 * Render login URL column.
	 *
	 * @param object $item Row data.
	 * @return string Login URL column HTML.
	 */
	public function column_login_url( $item ) {
		$metadata  = $this->parse_metadata( $item->metadata );
		$login_url = $metadata['login_url'] ?? $metadata['service_name'] ?? __( 'Not provided', 'secure-login-collector' );

		return sprintf(
			'<span class="editable-field" data-field="login_url" data-id="%s">%s</span>',
			$item->id,
			esc_html( $login_url )
		);
	}

	/**
	 * Render created date column.
	 *
	 * @param object $item Row data.
	 * @return string Date column HTML.
	 */
	public function column_created_at( $item ) {
		return esc_html( gmdate( 'M j, Y g:i A', strtotime( $item->created_at ) ) );
	}

	/**
	 * Render encryption method column.
	 *
	 * @param object $item Row data.
	 * @return string Encryption method column HTML.
	 */
	public function column_encryption( $item ) {
		$metadata        = json_decode( $item->metadata, true );
		$encryption_type = isset( $metadata['encryption_type'] ) ? $metadata['encryption_type'] : 'rsa';
		$encryption_info = $this->get_encryption_method_info( $encryption_type );

		return sprintf(
			'<span class="encryption-method %s" title="%s">%s</span>',
			esc_attr( $encryption_info['class'] ),
			esc_attr( $encryption_info['description'] ),
			$encryption_info['name']
		);
	}

	/**
	 * Render expires column.
	 *
	 * @param object $item Row data.
	 * @return string Expires column HTML.
	 */
	public function column_expires( $item ) {
		$is_expired = isset( $item->is_expired ) ? $item->is_expired : 0;
		return $this->database_manager->calculate_expiration( $item->retention_until, $is_expired );
	}

	/**
	 * Render actions column.
	 *
	 * @param object $item Row data.
	 * @return string Actions column HTML.
	 */
	public function column_actions( $item ) {
		$metadata         = json_decode( $item->metadata, true );
		$encryption_type  = isset( $metadata['encryption_type'] ) ? $metadata['encryption_type'] : 'rsa';
		$hostname         = isset( $metadata['key_hostname'] ) ? $metadata['key_hostname'] : '';
		$timestamp_suffix = isset( $metadata['key_timestamp_suffix'] ) ? $metadata['key_timestamp_suffix'] : '';
		$is_expired       = isset( $item->is_expired ) && 1 === $item->is_expired;

		// Check if entry is undecryptable (passkey-encrypted but passkey was deleted).
		// Use database flag as primary indicator (more reliable than checking options).
		$is_undecryptable = false;

		if ( isset( $item->undecryptable ) && 1 === (int) $item->undecryptable ) {
			$is_undecryptable = true;
		} else {
			// Fallback: Check encrypted_data for legacy entries not yet marked.
			$encrypted_data = json_decode( $item->encrypted_data, true );
			if ( is_array( $encrypted_data ) && ! empty( $encrypted_data['credentialId'] ) && $encrypted_data['isProEncrypted'] ) {
				// Entry was encrypted with a passkey. Check if that passkey still exists.
				$credential_id = $encrypted_data['credentialId'];
				$passkey_data  = get_option( 'passkey_credential_' . $credential_id, false );
				if ( ! $passkey_data ) {
					$is_undecryptable = true;
				}
			}
		}

		$actions = array();

		// Decrypt button (disabled for expired or undecryptable entries).
		if ( $is_expired ) {
			// Show disabled decrypt button for expired entries.
			$actions[] = sprintf(
				'<button type="button" class="button button-expired" disabled title="%s"><span class="dashicons dashicons-unlock"></span></button>',
				esc_attr__( 'Data has been purged (expired)', 'secure-login-collector' )
			);
		} elseif ( $is_undecryptable ) {
			// Show disabled decrypt button with undecryptable indicator.

			$actions[] = sprintf(
				'<button type="button" class="button decrypt-btn-v2" data-id="%s" data-undecryptable="true" disabled title="%s"><span class="dashicons dashicons-lock"></span></button>',
				$item->id,
				esc_attr__( 'Cannot decrypt: passkey was deleted', 'secure-login-collector' )
			);
		} else {
			// Normal decrypt button.
			$actions[] = sprintf(
				'<button type="button" class="button decrypt-btn-v2" data-id="%s" data-hostname="%s" data-timestamp="%s" data-encryption-type="%s" title="%s"><span class="dashicons dashicons-unlock"></span></button>',
				$item->id,
				esc_attr( $hostname ),
				esc_attr( $timestamp_suffix ),
				esc_attr( $encryption_type ),
				esc_attr__( 'Decrypt data', 'secure-login-collector' )
			);
		}

		// Extend button (only for non-expired entries if expiration is enabled) with icon.
		$expiration_days = get_option( 'seculoco_expiration_days', 30 );
		if ( $expiration_days > 0 && ! $is_expired && ! $is_undecryptable ) {
			$actions[] = sprintf(
				'<button type="button" class="button button-secondary extend-btn" data-id="%s" title="%s"><span class="dashicons dashicons-calendar-alt"></span></button>',
				$item->id,
				esc_attr__( 'Extend retention period', 'secure-login-collector' )
			);
		} else {
			$actions[] = sprintf(
				'<button type="button" class="button button-secondary extend-btn" title="%s" style="opacity: 0.7" disabled><span class="dashicons dashicons-calendar-alt"></span></button>',
				$item->id,
				esc_attr__( 'Cannot extend retention period', 'secure-login-collector' )
			);
		}

		// Edit button with icon (disabled for expired entries).
		if ( ! $is_expired ) {
			$actions[] = sprintf(
				'<button type="button" class="button edit-btn" data-id="%s" title="%s"><span class="dashicons dashicons-edit"></span></button>',
				$item->id,
				esc_attr__( 'Edit entry', 'secure-login-collector' )
			);

			// Save/Cancel buttons (hidden by default) with icons.
			$actions[] = sprintf(
				'<button type="button" class="button button-primary save-btn" data-id="%s" title="%s" style="display: none;"><span class="dashicons dashicons-yes"></span></button>',
				$item->id,
				esc_attr__( 'Save changes', 'secure-login-collector' )
			);

			$actions[] = sprintf(
				'<button type="button" class="button cancel-btn" data-id="%s" title="%s" style="display: none;"><span class="dashicons dashicons-no"></span></button>',
				$item->id,
				esc_attr__( 'Cancel editing', 'secure-login-collector' )
			);
		}

		// Delete button with icon.
		$actions[] = sprintf(
			'<button type="button" class="button button-secondary delete-btn" data-id="%s" title="%s"><span class="dashicons dashicons-trash" style="color: #d63384;"></span></button>',
			$item->id,
			esc_attr__( 'Delete entry', 'secure-login-collector' )
		);

		return implode( ' ', $actions );
	}

	/**
	 * Get encryption method info.
	 *
	 * @param string $encryption_type The encryption type.
	 * @return array Encryption method information.
	 */
	private function get_encryption_method_info( $encryption_type ) {
		switch ( $encryption_type ) {
			case 'aes-rsa-v2':
				return array(
					'name'        => __( 'Secure', 'secure-login-collector' ),
					'class'       => 'encryption-rsa',
					'description' => __( 'AES-256-GCM encryption with RSA key protection.', 'secure-login-collector' ),
				);
			case 'aes-rsa-passkey-v2':
				return array(
					'name'        => __( 'Ultra-Secure', 'secure-login-collector' ),
					'class'       => 'encryption-ultra-secure',
					'description' => __( 'AES-256-GCM + RSA with passkey authentication required for decryption.', 'secure-login-collector' ),
				);
			case 'rsa_passkey_protected':
				return array(
					'name'        => __( 'Ultra-Secure', 'secure-login-collector' ),
					'class'       => 'encryption-ultra-secure',
					'description' => __( 'Passkey-derived encryption for maximum security.', 'secure-login-collector' ),
				);
			case 'rsa':
				return array(
					'name'        => __( 'RSA-2048', 'secure-login-collector' ),
					'class'       => 'encryption-rsa',
					'description' => __( 'Industry-standard RSA encryption.', 'secure-login-collector' ),
				);
			default:
				return array(
					'name'        => __( 'RSA-2048', 'secure-login-collector' ),
					'class'       => 'encryption-rsa',
					'description' => __( 'Industry-standard RSA encryption.', 'secure-login-collector' ),
				);
		}
	}

	/**
	 * Prepare table items.
	 */
	public function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Handle bulk actions.
		$this->process_bulk_action();

		// Get search term.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table search/sort parameters.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// Get sorting parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table search/sort parameters.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table search/sort parameters.
		$order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'desc';

		// Get pagination parameters.
		$per_page     = $this->get_items_per_page( 'login_entries_per_page', 20 );
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Get data.
		$data        = $this->database_manager->get_entries_for_list_table( $search, $orderby, $order, $per_page, $offset );
		$total_items = $this->database_manager->get_total_entries( $search );

		$this->items = $data;

		// Set pagination.
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Parse metadata JSON for display.
	 * In v2 format, metadata is stored as plain JSON (not encrypted).
	 *
	 * @param string $metadata_json The metadata JSON string.
	 * @return array Parsed metadata array.
	 */
	private function parse_metadata( $metadata_json ) {
		$metadata = json_decode( $metadata_json, true );

		if ( ! $metadata ) {
			return array();
		}

		// V2 format: metadata is stored in plain text.
		if ( isset( $metadata['encryption_version'] ) && 2 === $metadata['encryption_version'] ) {
			return $metadata;
		}

		// Legacy format: may have encrypted fields.
		if ( isset( $metadata['metadata_encrypted'] ) && $metadata['metadata_encrypted'] && isset( $metadata['encrypted_fields'] ) ) {
			// Decrypt legacy encrypted fields.
			$key = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY ), 0, 32 );

			foreach ( $metadata['encrypted_fields'] as $field => $encrypted_value ) {
				$data = base64_decode( $encrypted_value );
				if ( strlen( $data ) > 16 ) {
					$iv        = substr( $data, 0, 16 );
					$encrypted = substr( $data, 16 );
					$decrypted = openssl_decrypt(
						$encrypted,
						'aes-256-cbc',
						$key,
						OPENSSL_RAW_DATA,
						$iv
					);
					if ( false !== $decrypted ) {
						$metadata[ $field ] = $decrypted;
					}
				}
			}

			unset( $metadata['encrypted_fields'] );
		}

		return $metadata;
	}

	/**
	 * Process bulk actions.
	 */
	public function process_bulk_action() {
		$action = $this->current_action();

		if ( ! $action ) {
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'bulk-login_entries' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'secure-login-collector' ) );
		}

		$ids = isset( $_POST['login_entries'] ) ? array_map( 'intval', wp_unslash( $_POST['login_entries'] ) ) : array();

		if ( empty( $ids ) ) {
			return;
		}

		if ( 'delete' === $action ) {
			foreach ( $ids as $id ) {
				$this->database_manager->delete_entry( $id );
			}

			/* translators: %d: number of entries deleted */
			$message = sprintf(
				_n( '%d entry deleted.', '%d entries deleted.', count( $ids ), 'secure-login-collector' ),
				count( $ids )
			);

			add_settings_error( 'seculoco_bulk', 'bulk_delete', $message, 'updated' );
		} elseif ( strpos( $action, 'export-' ) === 0 ) {
			// Handle CSV exports via AJAX (will be processed by JavaScript).
			$manager = str_replace( 'export-', '', $action );

			// Store export request in transient for AJAX processing.
			set_transient(
				'seculoco_bulk_export_' . get_current_user_id(),
				array(
					'manager' => $manager,
					'ids'     => $ids,
				),
				300
			);

			/* translators: %d: number of entries prepared for export */
			$message = sprintf(
				__( 'Bulk export initiated for %d entries. Please wait...', 'secure-login-collector' ),
				count( $ids )
			);

			add_settings_error( 'seculoco_bulk', 'bulk_export', $message, 'updated' );
		}
	}

	/**
	 * Display the search box.
	 *
	 * @param string $text     The search button text.
	 * @param string $input_id The search input ID.
	 */
	public function search_box( $text, $input_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are read-only GET parameters used for display/filtering, not form submissions.
		$search_term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		if ( empty( $search_term ) && ! $this->has_items() ) {
			return;
		}

		$input_id = $input_id . '-search-input';

		if ( ! empty( $_GET['orderby'] ) ) {
			echo '<input type="hidden" name="orderby" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) ) . '" />';
		}
		if ( ! empty( $_GET['order'] ) ) {
			echo '<input type="hidden" name="order" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) . '" />';
		}
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ); ?>" placeholder="<?php echo esc_attr__( 'Search in email, name, login URL...', 'secure-login-collector' ); ?>" />
			<?php submit_button( $text, '', '', false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Override the single row display to add decrypted data row.
	 *
	 * @param object $item Row data.
	 */
	public function single_row( $item ) {
		echo '<tr id="row-' . esc_attr( $item->id ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';

		// Add hidden row for decrypted data.
		echo '<tr id="decrypted-row-' . esc_attr( $item->id ) . '" class="decrypted-data-row" style="display: none;">';
		echo '<td colspan="' . count( $this->get_columns() ) . '">';
		echo '<div class="decrypted-content">';
		echo '<h4>' . esc_html__( 'Decrypted Data:', 'secure-login-collector' ) . '</h4>';
		echo '<div class="decrypted-json"></div>';
		echo '<div style="margin-top: 10px;">';
		echo '<button type="button" class="button button-primary export-to-password-manager" data-id="' . esc_attr( $item->id ) . '" title="' . esc_attr__( 'Export to Password Manager', 'secure-login-collector' ) . '"> ' . esc_html__( 'Export to Password Manager', 'secure-login-collector' ) . '</button>';
		echo '<button type="button" class="button hide-decrypted" data-id="' . esc_attr( $item->id ) . '" title="' . esc_attr__( 'Hide decrypted data', 'secure-login-collector' ) . '"><span class="dashicons dashicons-hidden"></span></button>';
		echo '</div>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';
	}
}

/**
 * Class Seculoco_Admin_Interface
 *
 * Handles the admin interface for the secure login collector plugin.
 */
// phpcs:ignore WordPress.Files.OneObjectStructurePerFile.MultipleFound -- List table class included for functionality.
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
	 * @var Seculoco_Encryption_Handler_V2
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
	 * @param string                         $table_name         Database table name.
	 * @param Seculoco_Encryption_Handler_V2 $encryption_handler Encryption handler instance.
	 * @param Seculoco_Database_Manager      $database_manager   Database manager instance.
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
				'not_provided'                  => __( 'Not provided', 'secure-login-collector' ),
				'saving'                        => __( 'Saving...', 'secure-login-collector' ),
				'save_failed'                   => __( 'Save failed: ', 'secure-login-collector' ),
				'unknown_error'                 => __( 'Unknown error', 'secure-login-collector' ),
				'network_error_save'            => __( 'Network error occurred during save.', 'secure-login-collector' ),
				'requesting_passkey'            => __( 'Requesting passkey...', 'secure-login-collector' ),
				'passkey_setup_failed'          => __( 'Passkey authentication setup failed: ', 'secure-login-collector' ),
				'decrypt_data'                  => __( 'Decrypt data', 'secure-login-collector' ),
				'network_error_passkey'         => __( 'Network error occurred during passkey setup.', 'secure-login-collector' ),
				'pro_no_passkey_continue'       => __( 'Pro version detected but no passkey registered. Continue with traditional decryption?', 'secure-login-collector' ),
				'enter_encryption_key'          => __( 'This entry was created before automatic key reconstruction was available. Please enter the encryption key manually:', 'secure-login-collector' ),
				'decrypting'                    => __( 'Decrypting...', 'secure-login-collector' ),
				'data_decrypted'                => __( 'Data decrypted', 'secure-login-collector' ),
				'decryption_failed'             => __( 'Decryption failed: ', 'secure-login-collector' ),
				'network_error_decryption'      => __( 'Network error occurred during decryption.', 'secure-login-collector' ),
				'error_processing_decryption'   => __( 'Error processing decryption: ', 'secure-login-collector' ),
				'authenticate_with_passkey'     => __( 'Authenticate with passkey...', 'secure-login-collector' ),
				'webauthn_not_supported'        => __( 'WebAuthn/Passkeys are not supported in this browser.', 'secure-login-collector' ),
				'verifying_passkey'             => __( 'Verifying passkey and decrypting...', 'secure-login-collector' ),
				'data_decrypted_passkey'        => __( 'Data decrypted with passkey', 'secure-login-collector' ),
				'passkey_decryption_failed'     => __( 'Passkey decryption failed: ', 'secure-login-collector' ),
				'network_error_passkey_decrypt' => __( 'Network error occurred during passkey decryption.', 'secure-login-collector' ),
				'passkey_auth_failed'           => __( 'Passkey authentication failed:', 'secure-login-collector' ),
				'confirm_extend_retention'      => __( 'Are you sure you want to extend the retention period for this entry?', 'secure-login-collector' ),
				'extending'                     => __( 'Extending...', 'secure-login-collector' ),
				'retention_extended'            => __( 'Retention period extended successfully.', 'secure-login-collector' ),
			),
		);

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
			'passkeyRegistered' => get_option( 'seculoco_passkey_registered', false ),
			'currentUserId'     => get_current_user_id(),
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
			$encryption_type = $metadata['encryption_type'] ?? 'aes-rsa-v2';

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
		$expiration_days        = get_option( 'seculoco_expiration_days', 30 );
		/* translators: %d: number of days retention period extended */
		wp_send_json_success(
			array(
				'message'        => sprintf( __( 'Retention period extended by %d days.', 'secure-login-collector' ), $expiration_days ),
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
	 * This is used to check if passkey authentication is needed before decryption.
	 *
	 * @return void
	 */
	public function handle_get_encryption_info() {
		// Check permissions and nonce.
		if (
			! current_user_can( 'manage_options' ) ||
			! isset( $_POST['nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'seculoco_nonce' )
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

		// Return encryption info.
		wp_send_json_success(
			array(
				'isProEncrypted' => $encrypted_package['isProEncrypted'] ?? false,
				'credentialId'   => $encrypted_package['credentialId'] ?? null,
			)
		);
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
		if ( ! wp_verify_nonce( $nonce, 'seculoco_admin_nonce' ) &&
			! wp_verify_nonce( $nonce, 'seculoco_nonce' ) ) {
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

		// Return encrypted package for client-side decryption.
		// Note: The frontend sends camelCase, we return snake_case for consistency with JS expectations.
		wp_send_json_success(
			array(
				'encrypted_data'    => $encrypted_data['encryptedData'] ?? '',
				'encrypted_aes_key' => $encrypted_data['rsaEncryptedKey'] ?? '', // Frontend uses rsaEncryptedKey, not encryptedAESKey.
				'iv'                => $encrypted_data['iv'] ?? '',
				'version'           => $encrypted_data['version'] ?? 'v2',
				'metadata'          => json_decode( $entry->metadata, true ),
			)
		);
	}
}
