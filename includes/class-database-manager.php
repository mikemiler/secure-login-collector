<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
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
 * Class Seculoco_Database_Manager
 *
 * Handles all database operations for the plugin.
 */
class Seculoco_Database_Manager {

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
		add_action( 'seculoco_cleanup_cron', array( $this, 'cleanup_old_data' ) );
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
            is_expired tinyint(1) DEFAULT 0,
            undecryptable tinyint(1) DEFAULT 0,
            undecryptable_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY retention_until (retention_until),
            KEY is_expired (is_expired),
            KEY undecryptable (undecryptable)
        ) $charset_collate;";

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}


	/**
	 * Schedule cleanup cron job.
	 *
	 * @return void
	 */
	public function schedule_cleanup() {
		if ( ! seculoco_is_auto_delete_enabled() ) {
			$this->clear_scheduled_cleanup();
			return;
		}

		if ( ! wp_next_scheduled( 'seculoco_cleanup_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'seculoco_cleanup_cron' );
		}
	}

	/**
	 * Clear scheduled cleanup cron job.
	 *
	 * @return void
	 */
	public function clear_scheduled_cleanup() {
		wp_clear_scheduled_hook( 'seculoco_cleanup_cron' );
	}

	/**
	 * Clean up expired entries based on retention settings.
	 *
	 * Instead of deleting entire records, this method:
	 * - Clears the encrypted_data field (username, password, notes)
	 * - Marks the entry as expired with is_expired flag
	 * - Preserves all metadata (email, name, login_url, timestamps, IP, user_agent)
	 *
	 * @return int Number of entries expired.
	 */
	public function cleanup_old_data() {
		global $wpdb;

		if ( ! seculoco_is_auto_delete_enabled() ) {
			return 0;
		}

		// Clear encrypted data for entries where retention_until has passed.
		$current_time = current_time( 'mysql' );

		// Only update entries that haven't already been expired (is_expired = 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
		$affected_rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table_name}
				SET encrypted_data = '',
				    is_expired = 1
				WHERE retention_until IS NOT NULL
				AND retention_until < %s
				AND is_expired = 0",
				$current_time
			)
		);

		return false !== $affected_rows ? (int) $affected_rows : 0;
	}

	/**
	 * Get all login data entries.
	 *
	 * @return array List of all entries.
	 */
	public function get_all_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC', $this->table_name ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table_name, $id ) );
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
		$expiration_days = seculoco_get_expiration_days();

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

		if ( ! seculoco_is_auto_delete_enabled() ) {
			return false;
		}

		$expiration_days    = seculoco_get_expiration_days();
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
	 * Check if an entry is expired.
	 *
	 * @param object $entry Database entry object.
	 * @return bool True if entry is expired, false otherwise.
	 */
	public function is_entry_expired( $entry ) {
		// Check the is_expired flag first.
		if ( isset( $entry->is_expired ) && 1 === $entry->is_expired ) {
			return true;
		}

		// If retention_until is null, entry never expires.
		if ( is_null( $entry->retention_until ) ) {
			return false;
		}

		// Check if retention_until has passed.
		$expiration_time = strtotime( $entry->retention_until );
		$current_time    = time();

		return $expiration_time < $current_time;
	}

	/**
	 * Calculate expiration time for display.
	 *
	 * @param string|null $retention_until Retention until date.
	 * @param int         $is_expired      Flag indicating if entry is already expired.
	 * @return string Formatted expiration text.
	 */
	public function calculate_expiration( $retention_until, $is_expired = 0 ) {
		// Check if entry has been marked as expired.
		if ( 1 === $is_expired ) {
			return '<span style="color: red; font-weight: bold;">' . __( 'Expired (data purged)', 'secure-login-collector' ) . '</span>';
		}

		// If retention_until is null, check if expiration is disabled.
		if ( is_null( $retention_until ) ) {
			return __( 'Never expires', 'secure-login-collector' );
		}

		$expiration_time = strtotime( $retention_until );
		$current_time    = time();
		$remaining_time  = $expiration_time - $current_time;

		if ( $remaining_time <= 0 ) {
			return '<span style="color: red;">' . __( 'Expired (pending cleanup)', 'secure-login-collector' ) . '</span>';
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
	 * Get count of expired entries.
	 *
	 * @return int Number of expired entries.
	 */
	public function get_expired_entries_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_expired = 1', $this->table_name ) );
	}

	/**
	 * Permanently delete expired entries from the database.
	 *
	 * This removes the entire record including metadata.
	 * Use with caution - this is irreversible.
	 *
	 * @return int Number of entries deleted.
	 */
	public function delete_expired_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
		$affected_rows = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE is_expired = 1', $this->table_name ) );
		return false !== $affected_rows ? (int) $affected_rows : 0;
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
		if ( ! get_option( SECULOCO_OPTION_ENABLE_NOTIFICATIONS, false ) ) {
			return;
		}

		$notification_email = get_option( SECULOCO_OPTION_NOTIFICATION_EMAIL, get_option( 'admin_email' ) );

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
			// Additional validation - only allow created_at as fallback.
			$order_clause = 'created_at';
		}

		// Validate order parameter.
		$order = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Build final query.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql(). Order clause validated against whitelist above.
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql(). Order clause validated against whitelist above.
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
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
		$query = "SELECT COUNT(*) FROM {$this->table_name} {$where_clause}";

		if ( ! empty( $search_params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $query, $search_params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
			return (int) $wpdb->get_var( $query );
		}
	}

	/**
	 * Mark login data entries as undecryptable after master password reset.
	 *
	 * Handles both FREE-tier (RSA-encrypted) and PRO-tier (passkey-protected) encrypted data.
	 * After master password reset, all encrypted data becomes undecryptable since the
	 * decryption keys are lost.
	 *
	 * @param string $tier Encryption tier to mark: 'all' (default), 'free', or 'pro'.
	 * @return int Number of entries marked as undecryptable.
	 */
	public function mark_login_data_as_undecryptable( $tier = 'all' ) {
		global $wpdb;

		// Validate tier parameter.
		$valid_tiers = array( 'all', 'free', 'pro' );
		if ( ! in_array( $tier, $valid_tiers, true ) ) {
			$tier = 'all';
		}

		// Find all entries that are not yet marked as undecryptable.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, metadata FROM {$this->table_name} WHERE undecryptable = 0"
			)
		);

		$affected_count = 0;
		$current_time   = current_time( 'mysql' );

		foreach ( $entries as $entry ) {
			$metadata = json_decode( $entry->metadata, true );

			// Determine encryption tier of this entry.
			$is_pro_encrypted = isset( $metadata['is_pro_encrypted'] ) && $metadata['is_pro_encrypted'];

			// Determine if this entry should be marked based on tier filter.
			$should_mark = false;

			if ( 'all' === $tier ) {
				// Mark both FREE and PRO tier encrypted entries.
				$should_mark = true;
			} elseif ( 'free' === $tier ) {
				// Mark only FREE-tier RSA-encrypted entries.
				$should_mark = ! $is_pro_encrypted;
			} elseif ( 'pro' === $tier ) {
				// Mark only PRO-tier passkey-protected entries.
				$should_mark = $is_pro_encrypted;
			}

			if ( $should_mark ) {
				// Mark as undecryptable.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
				$result = $wpdb->update(
					$this->table_name,
					array(
						'undecryptable'    => 1,
						'undecryptable_at' => $current_time,
					),
					array( 'id' => $entry->id ),
					array( '%d', '%s' ),
					array( '%d' )
				);

				if ( false !== $result ) {
					++$affected_count;
				}
			}
		}

		return $affected_count;
	}

	/**
	 * Check if a login data entry is undecryptable.
	 *
	 * @param int $login_id Login data entry ID.
	 * @return bool True if undecryptable, false otherwise.
	 */
	public function is_login_data_undecryptable( $login_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT undecryptable FROM {$this->table_name} WHERE id = %d",
				$login_id
			)
		);

		return 1 === intval( $result );
	}

	/**
	 * Get all undecryptable entries for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array List of undecryptable entries.
	 */
	public function get_undecryptable_entries( $user_id = null ) {
		global $wpdb;

		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE user_id = %d AND undecryptable = 1 ORDER BY undecryptable_at DESC",
					$user_id
				)
			);
		} else {
			// Get all undecryptable entries.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
			return $wpdb->get_results(
				"SELECT * FROM {$this->table_name} WHERE undecryptable = 1 ORDER BY undecryptable_at DESC"
			);
		}
	}

	/**
	 * Get count of undecryptable entries.
	 *
	 * @param int $user_id Optional user ID to filter by.
	 * @return int Number of undecryptable entries.
	 */
	public function get_undecryptable_entries_count( $user_id = null ) {
		global $wpdb;

		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND undecryptable = 1",
					$user_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped in constructor via esc_sql().
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE undecryptable = 1"
			);
		}
	}
}
