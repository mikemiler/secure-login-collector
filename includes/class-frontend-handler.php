<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- Legacy file naming convention.
/**
 * Frontend Handler Class
 *
 * Handles frontend operations including:
 * - Frontend form shortcode
 * - Script enqueuing
 * - Data submission processing
 * - Double encryption for ultra-secure mode
 *
 * @package SecureLoginCollector
 *
 * @phpcs:disable WordPress.Files.FileName.InvalidClassFileName
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seculoco_Frontend_Handler
 *
 * Handles all frontend-related functionality.
 */
class Seculoco_Frontend_Handler {

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
	 * Spam protection instance.
	 *
	 * @var Seculoco_Spam_Protection
	 */
	private $spam_protection;

	/**
	 * Constructor - initializes frontend handler.
	 *
	 * @param string                          $table_name         Database table name.
	 * @param Seculoco_Encryption_Handler_V2 $encryption_handler Encryption handler instance.
	 * @param Seculoco_Database_Manager   $database_manager   Database manager instance.
	 */
	public function __construct( $table_name, $encryption_handler, $database_manager ) {
		$this->table_name         = $table_name;
		$this->encryption_handler = $encryption_handler;
		$this->database_manager   = $database_manager;

		// Initialize security protection systems.
		$this->spam_protection = new Seculoco_Spam_Protection();

		// Register hooks.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
		add_shortcode( 'seculoco_form', array( $this, 'frontend_form_shortcode' ) );

		// Register v2 AJAX handlers for new encryption format (only v2 supported now).
		// Using seculoco_ prefix (WordPress.org compliant, 4+ chars, unique).
		add_action( 'wp_ajax_seculoco_save_entry_v2', array( $this, 'handle_save_login_data_v2' ) );
		add_action( 'wp_ajax_nopriv_seculoco_save_entry_v2', array( $this, 'handle_save_login_data_v2' ) );

		// Add handler for public key retrieval (delegates to encryption handler).
		add_action( 'wp_ajax_seculoco_get_public_key', array( $this->encryption_handler, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_nopriv_seculoco_get_public_key', array( $this->encryption_handler, 'handle_get_public_key' ) );
	}

	/**
	 * Enqueue frontend scripts for the shortcode form.
	 *
	 * @return void
	 */
	public function enqueue_frontend_scripts() {
		// Only enqueue if shortcode is present on the page.
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'seculoco_form' ) ) {
			wp_enqueue_script( 'jquery' );

			// Enqueue dashicons for password toggle.
			wp_enqueue_style( 'dashicons' );

			// Enqueue frontend CSS.
			wp_enqueue_style(
				'secure-login-frontend-css',
				SECULOCO_PLUGIN_URL . 'assets/css/frontend.css',
				array( 'dashicons' ),
				SECULOCO_VERSION
			);

			// Use the new secure frontend script with proper encryption flow.
			wp_enqueue_script( 'secure-login-frontend', SECULOCO_PLUGIN_URL . 'assets/js/frontend-secure.js', array( 'jquery' ), SECULOCO_VERSION, true );

			// Prepare localization data.
			$localize_data = array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'seculoco_nonce' ),
				'is_pro'  => false, // Free version default - pro version filters this.
				'strings' => array(
					'required_fields_error'   => __( 'Please fill in all required fields (Email Address, Name, Login URL, Username/Email, and Password).', 'secure-login-collector' ),
					'submitting'              => __( 'Submitting...', 'secure-login-collector' ),
					'submit_securely'         => __( 'Submit Securely', 'secure-login-collector' ),
					'success_message'         => __( 'Login data saved securely! Thank you for your submission.', 'secure-login-collector' ),
					'error_prefix'            => __( 'Error saving data: ', 'secure-login-collector' ),
					'unknown_error'           => __( 'Unknown error', 'secure-login-collector' ),
					'network_error'           => __( 'Network error occurred while saving data. Please try again.', 'secure-login-collector' ),
					'encryption_error'        => __( 'Encryption failed. Please try again.', 'secure-login-collector' ),
					'show_password'           => __( 'Show password', 'secure-login-collector' ),
					'hide_password'           => __( 'Hide password', 'secure-login-collector' ),
					'encryption_failed'       => __( 'Encryption failed', 'secure-login-collector' ),
					'no_encryption_available' => __( 'No encryption method available', 'secure-login-collector' ),
					'rsa_key_not_available'   => __( 'RSA public key not available. Please contact administrator.', 'secure-login-collector' ),
					'encryption_retry_failed' => __( 'Encryption failed. Please try again or contact administrator.', 'secure-login-collector' ),
				),
			);

			// Public key is fetched dynamically via AJAX to avoid caching issues.
			// AJAX endpoints are registered in constructor:
			// - wp_ajax_seculoco_get_public_key
			// - wp_ajax_nopriv_seculoco_get_public_key

			// No need to send passkey info to frontend - clients don't have passkeys.

			// Allow pro version to modify JS config.
			$localize_data = apply_filters( 'seculoco_frontend_js_config', $localize_data );

			// Localize script with data.
			wp_localize_script( 'secure-login-frontend', 'seculocoAjax', $localize_data );
		}
	}


	/**
	 * Frontend form shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered shortcode content.
	 */
	public function frontend_form_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'button_text' => __( 'Submit Securely', 'secure-login-collector' ),
			),
			$atts
		);

		ob_start();
		?>
		<div class="seculoco-form-container">
			<div class="seculoco-security-info">
				<img src="<?php echo esc_url( SECULOCO_PLUGIN_URL . 'assets/img/slc-secure-data-transfer-300.png' ); ?>" alt="<?php echo esc_attr__( 'Secure Encrypted Data Transmission', 'secure-login-collector' ); ?>" class="seculoco-security-badge-icon" />
				<div class="seculoco-security-info-text">
				<?php
				// Check text type selection.
				$text_type       = get_option( 'seculoco_frontend_text_type', 'default' );
				$custom_text     = get_option( 'seculoco_frontend_form_text', '' );
				$expiration_days = get_option( 'seculoco_expiration_days', 30 );

				if ( 'custom' === $text_type && ! empty( $custom_text ) ) :
					// Handle placeholder replacement for custom text.
					if ( $expiration_days > 0 ) {
						// translators: %d is the number of days after which data will be deleted.
						$expiration_text = ' ' . sprintf( __( 'for %d days, after which it will be automatically deleted', 'secure-login-collector' ), $expiration_days );
					} else {
						$expiration_text = '. ' . __( 'Auto-deletion is disabled, so data will be retained until manually deleted by the administrator', 'secure-login-collector' );
					}

					// Replace the placeholder with actual expiration text.
					$final_text = str_replace( '{EXPIRATION_TEXT}', $expiration_text, $custom_text );
					?>
					<div>
					<?php
					echo wp_kses(
						$final_text,
						array(
							'p'      => array(),
							'strong' => array(),
							'em'     => array(),
							'br'     => array(),
							'a'      => array(
								'href'   => array(),
								'target' => array(),
							),
						)
					);
					?>
					</div>
				<?php else : ?>
					<p><strong><?php echo esc_html__( 'What happens to your data:', 'secure-login-collector' ); ?></strong> <?php echo esc_html__( 'Your login data is encrypted in your browser before being sent to our server. We use strong RSA-2048 encryption to ensure maximum security.', 'secure-login-collector' ); ?></p>
					<?php if ( $expiration_days > 0 ) : ?>
						<p><strong><?php echo esc_html__( 'Security & Privacy:', 'secure-login-collector' ); ?></strong> 
						<?php
						// translators: %d is the number of days after which data will be deleted.
						printf( esc_html__( 'Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely for %d days, after which it will be automatically deleted.', 'secure-login-collector' ), (int) $expiration_days );
						?>
						</p>
					<?php else : ?>
						<p><strong><?php echo esc_html__( 'Security & Privacy:', 'secure-login-collector' ); ?></strong> <?php echo esc_html__( 'Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely. Auto-deletion is disabled, so data will be retained until manually deleted by the administrator.', 'secure-login-collector' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
				</div>
			</div>

			

			<form id="seculoco-frontend-form" class="seculoco-form">
				<?php
				// Allow pro features to start time tracking before form render.
				do_action( 'seculoco_before_form_render' );

				// Generate honeypot field HTML (invisible to humans, catches bots).
				echo $this->spam_protection->generate_honeypot_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in method.
				?>

				<div class="seculoco-form-group">
					<label for="user_name"><?php echo esc_html__( 'Your Name:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<input type="text" id="user_name" name="user_name" placeholder="<?php echo esc_attr__( 'Your full name', 'secure-login-collector' ); ?>" required>
				</div>

				<div class="seculoco-form-group">
					<label for="email"><?php echo esc_html__( 'Your Email Address:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<input type="email" id="email" name="email" placeholder="<?php echo esc_attr__( 'your@email.com', 'secure-login-collector' ); ?>" required>
				</div>

				<div class="seculoco-form-group">
					<label for="login_url"><?php echo esc_html__( 'Login URL / Service Name:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<input type="text" id="login_url" name="login_url" placeholder="<?php echo esc_attr__( 'https://example.com/login or service name', 'secure-login-collector' ); ?>" required>
					<small class="seculoco-form-help"><?php echo esc_html__( 'Enter the login URL or service name where these credentials are used.', 'secure-login-collector' ); ?></small>
				</div>

				<div class="seculoco-form-group">
					<label for="username_email"><?php echo esc_html__( 'Username/Email:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<input type="text" id="username_email" name="username_email" placeholder="<?php echo esc_attr__( 'Username or email for login', 'secure-login-collector' ); ?>" required>
					<small class="seculoco-form-help"><?php echo esc_html__( 'The username or email address used to log into this service.', 'secure-login-collector' ); ?></small>
				</div>

				<div class="seculoco-form-group">
					<label for="password"><?php echo esc_html__( 'Password:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<div class="seculoco-password-field-wrapper">
						<input type="password" id="password" name="password" placeholder="<?php echo esc_attr__( 'Enter the password', 'secure-login-collector' ); ?>" required>
						<button type="button" class="seculoco-password-toggle-btn" aria-label="<?php echo esc_attr__( 'Toggle password visibility', 'secure-login-collector' ); ?>">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
						</button>
					</div>
					<small class="seculoco-form-help"><?php echo esc_html__( 'The password for this account.', 'secure-login-collector' ); ?></small>
				</div>

				<div class="seculoco-form-group">
					<label for="additional_notes"><?php echo esc_html__( 'Additional Notes:', 'secure-login-collector' ); ?></label>
					<textarea id="additional_notes" name="additional_notes" placeholder="<?php echo esc_attr__( 'Any additional information, security questions, backup codes, etc. (optional)', 'secure-login-collector' ); ?>" rows="4"></textarea>
					<small class="seculoco-form-help"><?php echo esc_html__( 'Optional: Any additional information like security questions, backup codes, or special instructions.', 'secure-login-collector' ); ?></small>
				</div>

				<div class="seculoco-form-group">
					<button type="submit" class="seculoco-submit-btn"><?php echo esc_html( $atts['button_text'] ); ?></button>
				</div>

				<?php
				/**
				 * Show service footer based on filter hook.
				 * Free version: Always returns true (footer always shown)
				 * Pro version: Can hook in to check setting and return false to hide
				 *
				 * @param bool $show_footer Whether to show the service footer. Default true.
				 */
				$show_footer = apply_filters( 'seculoco_show_service_footer', true );

				if ( $show_footer ) :
					?>
					<div style="font-size: 14px; text-align: center;">
						Service provided by <a href="https://wordpress.org/plugins/secure-login-collector" target="_blank"> Secure Login Collector</a>
					</div>
				<?php endif; ?>

			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle AJAX request to save encrypted login data (v2 format).
	 * This handles the new encryption format with AES-GCM + RSA + optional Passkey.
	 *
	 * @return void
	 */
	public function handle_save_login_data_v2() {
		// Verify nonce for security.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'seculoco_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		// Get client IP for spam protection hooks.
		$client_ip = SecureLoginCollector::get_client_ip();

		/**
		 * Allow pro features to add spam protection (rate limiting, etc.).
		 *
		 * Filter: seculoco_spam_protection_check
		 * Allows pro version to implement rate limiting and other spam protection measures.
		 *
		 * @param bool|WP_Error $spam_check True if check passes, WP_Error if spam detected.
		 * @param array         $_POST      The POST data from the submission.
		 * @param string        $client_ip  The client's IP address.
		 *
		 * @return bool|WP_Error True if check passes, WP_Error with error message if spam detected.
		 */
		$spam_check = apply_filters( 'seculoco_spam_protection_check', true, $_POST, $client_ip );
		if ( is_wp_error( $spam_check ) ) {
			wp_send_json_error( $spam_check->get_error_message() );
			return;
		}

		// Get submission data and sanitize before JSON decode.
		$submission_json = isset( $_POST['submission'] ) ? sanitize_textarea_field( wp_unslash( $_POST['submission'] ) ) : '';

		if ( empty( $submission_json ) ) {
			wp_send_json_error( __( 'Missing submission data.', 'secure-login-collector' ) );
			return;
		}

		// Parse submission data.
		$submission = json_decode( $submission_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( __( 'Invalid submission format.', 'secure-login-collector' ) );
			return;
		}

		// Validate spam protection (bot detection via timing and hidden fields).
		$spam_result = $this->spam_protection->validate_submission( $_POST );
		if ( is_wp_error( $spam_result ) ) {
			// Log silently but return generic error (don't reveal spam protection mechanism).
			wp_send_json_error( $spam_result->get_error_message() );
			return;
		}

		// Validate required fields.
		$required_fields = array( 'encryptedData', 'rsaEncryptedKey', 'iv', 'salt', 'metadata' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $submission[ $field ] ) ) {
				// translators: %s is the name of the missing field.
				wp_send_json_error( sprintf( __( 'Missing required field: %s', 'secure-login-collector' ), $field ) );
				return;
			}
		}

		// Validate metadata.
		$metadata          = $submission['metadata'];
		$required_metadata = array( 'email', 'name', 'login_url' );
		foreach ( $required_metadata as $field ) {
			if ( ! isset( $metadata[ $field ] ) || empty( $metadata[ $field ] ) ) {
				// translators: %s is the name of the missing metadata field.
				wp_send_json_error( sprintf( __( 'Missing required metadata: %s', 'secure-login-collector' ), $field ) );
				return;
			}
		}

		// Sanitize metadata.
		$metadata['email']      = sanitize_email( $metadata['email'] );
		$metadata['name']       = sanitize_text_field( $metadata['name'] );
		$metadata['login_url']  = sanitize_text_field( $metadata['login_url'] );
		$metadata['created_at'] = isset( $metadata['created_at'] ) ? sanitize_text_field( $metadata['created_at'] ) : current_time( 'c' );

		/**
		 * AUTO-DETECT ENCRYPTION TYPE SERVER-SIDE.
		 * Ignore any frontend-provided encryption flags (isProEncrypted, credentialId).
		 * Determine encryption type based on current server configuration.
		 *
		 * Filter: seculoco_determine_encryption_type
		 * Allows the pro version to determine the encryption type and provide encryption metadata.
		 *
		 * @param array $encryption_metadata {
		 *     Encryption metadata for the submission.
		 *
		 *     @type bool        $is_pro_encrypted Whether pro encryption is active.
		 *     @type string|null $credential_id    Passkey credential ID for pro encryption.
		 *     @type string      $encryption_type  Encryption type identifier.
		 * }
		 * @param array $metadata Submission metadata including email, name, login_url.
		 *
		 * @return array Modified encryption metadata.
		 */
		$encryption_metadata = apply_filters(
			'seculoco_determine_encryption_type',
			array(
				'is_pro_encrypted' => false,
				'credential_id'    => null,
				'encryption_type'  => 'aes-rsa-v2',
			),
			$metadata
		);

		// Extract encryption metadata.
		$is_pro_encrypted     = isset( $encryption_metadata['is_pro_encrypted'] ) ? (bool) $encryption_metadata['is_pro_encrypted'] : false;
		$server_credential_id = isset( $encryption_metadata['credential_id'] ) ? $encryption_metadata['credential_id'] : null;
		$encryption_type      = isset( $encryption_metadata['encryption_type'] ) ? $encryption_metadata['encryption_type'] : 'aes-rsa-v2';

		// Create encrypted package for storage.
		$encrypted_package = array(
			'encryptedData'   => sanitize_text_field( $submission['encryptedData'] ),
			'rsaEncryptedKey' => sanitize_text_field( $submission['rsaEncryptedKey'] ), // Store as-is from client.
			'iv'              => sanitize_text_field( $submission['iv'] ),
			'salt'            => sanitize_text_field( $submission['salt'] ),
			'isProEncrypted'  => $is_pro_encrypted, // Server determines this.
			'credentialId'    => $server_credential_id, // Server's passkey credential ID.
			'version'         => 2, // Mark as v2 format.
		);

		// Add encryption metadata (uses values from filter or defaults).
		$metadata['encryption_type']    = $encryption_type;
		$metadata['encryption_version'] = 2;
		$metadata['is_pro_encrypted']   = $is_pro_encrypted;

		// Prepare data for database insertion.
		$user_agent = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$data = array(
			'encrypted_data' => wp_json_encode( $encrypted_package ), // Store entire package as JSON.
			'metadata'       => wp_json_encode( $metadata ),
			'user_id'        => 0, // Anonymous frontend submissions.
			'ip_address'     => SecureLoginCollector::get_client_ip(),
			'user_agent'     => $user_agent,
		);

		// Insert into database.
		$result = $this->database_manager->insert_entry( $data );

		if ( false === $result ) {
			wp_send_json_error( __( 'Failed to save data to database.', 'secure-login-collector' ) );
			return;
		}

		// Send email notification if enabled.
		$this->database_manager->send_notification( $metadata['email'], $metadata['name'] );

		/**
		 * Allow pro features to track successful submission (rate limiting, analytics).
		 *
		 * Action: seculoco_submission_recorded
		 * Fired after a submission is successfully saved to the database.
		 *
		 * @param string $client_ip     The client's IP address.
		 * @param int    $insert_result The database insert result (entry ID).
		 */
		do_action( 'seculoco_submission_recorded', $client_ip, $result );

		wp_send_json_success( __( 'Login data saved securely with enhanced encryption.', 'secure-login-collector' ) );
	}
}
