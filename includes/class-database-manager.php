<?php
/**
 * Database Manager Class
 *
 * Handles all database operations including:
 * - Table creation and upgrades
 * - Data cleanup and retention
 * - Cron job management
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Secure_Login_Database_Manager
 *
 * Handles all database operations for the plugin.
 */
class Secure_Login_Database_Manager {

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor - initializes database manager.
	 *
	 * @param string $table_name Database table name.
	 */
	public function __construct( $table_name ) {
		// Escape table name to prevent SQL injection (table names cannot be prepared).
		$this->table_name = esc_sql( $table_name );

		// Add cron job for automatic cleanup.
		add_action( 'secure_login_cleanup_cron', array( $this, 'cleanup_old_data' ) );
	}

	/**
	 * Create the database table for storing encrypted login data.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		$sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            encrypted_data longtext NOT NULL,
            metadata text NOT NULL,
            user_id bigint(20) NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            retention_until datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY retention_until (retention_until)
        ) $charset_collate;";

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Upgrade database schema if needed.
	 *
	 * @return void
	 */
	public function upgrade_database() {
		global $wpdb;

		// Check if retention_until column exists.
		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$this->table_name} LIKE %s",
				'retention_until'
			)
		);

		if ( empty( $column_exists ) ) {
			// Add retention_until column.
			// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
			$wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN retention_until datetime DEFAULT NULL AFTER created_at" );

			// Set retention_until for existing records based on created_at + expiration days.
			$expiration_days = get_option( 'secure_login_expiration_days', 30 );
			if ( $expiration_days > 0 ) {
				// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$this->table_name} SET retention_until = DATE_ADD(created_at, INTERVAL %d DAY) WHERE retention_until IS NULL",
						$expiration_days
					)
				);
			}
		}
	}

	/**
	 * Schedule cleanup cron job.
	 *
	 * @return void
	 */
	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'secure_login_cleanup_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'secure_login_cleanup_cron' );
		}
	}

	/**
	 * Clear scheduled cleanup cron job.
	 *
	 * @return void
	 */
	public function clear_scheduled_cleanup() {
		wp_clear_scheduled_hook( 'secure_login_cleanup_cron' );
	}

	/**
	 * Clean up expired entries based on retention settings.
	 *
	 * @return void
	 */
	public function cleanup_old_data() {
		global $wpdb;

		// Check if auto-deletion is enabled.
		$expiration_days = get_option( 'secure_login_expiration_days', 30 );
		if ( $expiration_days <= 0 ) {
			// Auto-deletion is disabled, don't delete anything.
			return;
		}

		// Delete entries where retention_until has passed.
		$current_time = current_time( 'mysql' );

		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE retention_until IS NOT NULL AND retention_until < %s",
				$current_time
			)
		);
	}

	/**
	 * Get all login data entries.
	 *
	 * @return array List of all entries.
	 */
	public function get_all_entries() {
		global $wpdb;
		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		return $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY created_at DESC" );
	}

	/**
	 * Get a single entry by ID.
	 *
	 * @param int $id Entry ID.
	 * @return object|null Entry object or null if not found.
	 */
	public function get_entry( $id ) {
		global $wpdb;
		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $id ) );
	}

	/**
	 * Insert new entry into database.
	 *
	 * @param array $data Entry data to insert.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function insert_entry( $data ) {
		global $wpdb;

		$retention_until = null;
		$expiration_days = get_option( 'secure_login_expiration_days', 30 );

		if ( $expiration_days > 0 ) {
			$retention_until = gmdate( 'Y-m-d H:i:s', strtotime( "+{$expiration_days} days" ) );
		}

		$user_agent = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$entry_data = array(
			'encrypted_data'  => $data['encrypted_data'],
			'metadata'        => $data['metadata'],
			'user_id'         => isset( $data['user_id'] ) ? $data['user_id'] : 0,
			'ip_address'      => isset( $data['ip_address'] ) ? $data['ip_address'] : SecureLoginCollector::get_client_ip(),
			'user_agent'      => isset( $data['user_agent'] ) ? $data['user_agent'] : $user_agent,
			'created_at'      => current_time( 'mysql' ),
			'retention_until' => $retention_until,
		);

		return $wpdb->insert( $this->table_name, $entry_data );
	}

	/**
	 * Update entry metadata.
	 *
	 * @param int    $id       Entry ID.
	 * @param string $metadata New metadata.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public function update_entry_metadata( $id, $metadata ) {
		global $wpdb;

		return $wpdb->update(
			$this->table_name,
			array( 'metadata' => $metadata ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete entry by ID.
	 *
	 * @param int $id Entry ID.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete_entry( $id ) {
		global $wpdb;
		return $wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Extend retention period for an entry.
	 *
	 * @param int $id Entry ID.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public function extend_retention( $id ) {
		global $wpdb;

		$expiration_days = get_option( 'secure_login_expiration_days', 30 );
		if ( $expiration_days <= 0 ) {
			return false; // Auto-deletion is disabled.
		}

		$new_retention_until = gmdate( 'Y-m-d H:i:s', strtotime( "+{$expiration_days} days" ) );

		return $wpdb->update(
			$this->table_name,
			array( 'retention_until' => $new_retention_until ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Calculate expiration time for display.
	 *
	 * @param string|null $retention_until Retention until date.
	 * @return string Formatted expiration text.
	 */
	public function calculate_expiration( $retention_until ) {
		// If retention_until is null, check if expiration is disabled.
		if ( is_null( $retention_until ) ) {
			return __( 'Never expires', 'secure-login-collector' );
		}

		$expiration_time = strtotime( $retention_until );
		$current_time    = time();
		$remaining_time  = $expiration_time - $current_time;

		if ( $remaining_time <= 0 ) {
			return '<span style="color: red;">' . __( 'Expired', 'secure-login-collector' ) . '</span>';
		}

		$days  = floor( $remaining_time / 86400 );
		$hours = floor( ( $remaining_time % 86400 ) / 3600 );

		if ( $days > 0 ) {
			// translators: %1$d is the number of days, %2$d is the number of hours.
			return sprintf( __( '%1$d days, %2$d hours', 'secure-login-collector' ), $days, $hours );
		} else {
			// translators: %d is the number of hours.
			return sprintf( __( '%d hours', 'secure-login-collector' ), $hours );
		}
	}

	/**
	 * Send email notification for new login data submission.
	 *
	 * @param string $sender_email Sender's email address.
	 * @param string $sender_name  Sender's name.
	 * @return void
	 */
	public function send_notification( $sender_email, $sender_name = '' ) {
		// Check if notifications are enabled.
		if ( ! get_option( 'secure_login_enable_notifications', false ) ) {
			return;
		}

		$notification_email = get_option( 'secure_login_notification_email', get_option( 'admin_email' ) );

		if ( empty( $notification_email ) || ! is_email( $notification_email ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$site_url  = get_site_url();
		$admin_url = admin_url( 'admin.php?page=secure-login-collector' );

		// translators: %s is the site name.
		$subject = sprintf( __( '[%s] New Secure Login Data Received', 'secure-login-collector' ), $site_name );

		// translators: %1$s is sender email, %2$s is sender name, %3$s is submission time, %4$s is admin URL, %5$s is site name, %6$s is site URL.
		$message = sprintf(
			__( "Hello,\n\nNew secure login data has been submitted to your website.\n\nSender Email: %1\$s\nSender Name: %2\$s\nSubmitted: %3\$s\n\nTo view and decrypt the login data, please visit:\n%4\$s\n\nThis is an automated notification from %5\$s\nWebsite: %6\$s", 'secure-login-collector' ),
			$sender_email,
			! empty( $sender_name ) ? $sender_name : __( 'Not provided', 'secure-login-collector' ),
			current_time( 'Y-m-d H:i:s' ),
			$admin_url,
			$site_name,
			$site_url
		);

		$parsed_url = wp_parse_url( $site_url );
		$host       = isset( $parsed_url['host'] ) ? $parsed_url['host'] : 'localhost';

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . $site_name . ' <noreply@' . $host . '>',
		);

		wp_mail( $notification_email, $subject, $message, $headers );
	}

	/**
	 * Get entries for WP_List_Table with search, sorting, and pagination.
	 *
	 * @param string $search   Search term.
	 * @param string $orderby  Column to order by.
	 * @param string $order    Order direction (ASC/DESC).
	 * @param int    $per_page Number of entries per page.
	 * @param int    $offset   Offset for pagination.
	 * @return array List of entries.
	 */
	public function get_entries_for_list_table( $search = '', $orderby = 'created_at', $order = 'desc', $per_page = 20, $offset = 0 ) {
		global $wpdb;

		$where_conditions = array();
		$search_params    = array();

		// Handle search.
		if ( ! empty( $search ) ) {
			$search_term        = '%' . $wpdb->esc_like( $search ) . '%';
			$where_conditions[] = '(metadata LIKE %s OR created_at LIKE %s)';
			$search_params[]    = $search_term;
			$search_params[]    = $search_term;
		}

		// Build WHERE clause.
		$where_clause = '';
		if ( ! empty( $where_conditions ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where_conditions );
		}

		// Validate orderby parameter.
		$allowed_orderby = array( 'created_at', 'email', 'name', 'login_url', 'encryption', 'expires' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created_at';
		}

		// Handle special sorting for metadata fields.
		$order_clause = '';
		if ( in_array( $orderby, array( 'email', 'name', 'login_url', 'encryption' ), true ) ) {
			// For metadata fields, we need to extract from JSON.
			switch ( $orderby ) {
				case 'email':
					$order_clause = "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.email'))";
					break;
				case 'name':
					$order_clause = "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.name'))";
					break;
				case 'login_url':
					$order_clause = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.login_url')), JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.service_name')))";
					break;
				case 'encryption':
					$order_clause = "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.encryption_type'))";
					break;
			}
		} elseif ( 'expires' === $orderby ) {
			$order_clause = 'retention_until';
		} else {
			// Additional validation - only allow created_at as fallback
			$order_clause = 'created_at';
		}

		// Validate order parameter.
		$order = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Build final query.
		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		$query = "SELECT * FROM {$this->table_name} 
                  {$where_clause} 
                  ORDER BY {$order_clause} {$order} 
                  LIMIT %d OFFSET %d";

		// Prepare parameters.
		$params = array_merge( $search_params, array( $per_page, $offset ) );

		if ( ! empty( $params ) ) {
			return $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		} else {
			// No parameters needed.
			// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
			return $wpdb->get_results( $query );
		}
	}

	/**
	 * Get total number of entries for pagination.
	 *
	 * @param string $search Search term.
	 * @return int Total number of entries.
	 */
	public function get_total_entries( $search = '' ) {
		global $wpdb;

		$where_conditions = array();
		$search_params    = array();

		// Handle search.
		if ( ! empty( $search ) ) {
			$search_term        = '%' . $wpdb->esc_like( $search ) . '%';
			$where_conditions[] = '(metadata LIKE %s OR created_at LIKE %s)';
			$search_params[]    = $search_term;
			$search_params[]    = $search_term;
		}

		// Build WHERE clause.
		$where_clause = '';
		if ( ! empty( $where_conditions ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where_conditions );
		}

		// Build query.
		// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
		$query = "SELECT COUNT(*) FROM {$this->table_name} {$where_clause}";

		if ( ! empty( $search_params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $query, $search_params ) );
		} else {
			// Note: Table names cannot be prepared in WordPress, but this is safe as table name is controlled.
			return (int) $wpdb->get_var( $query );
		}
	}
}
