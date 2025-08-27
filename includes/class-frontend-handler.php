<?php
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
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Secure_Login_Frontend_Handler
 *
 * Handles all frontend-related functionality.
 */
class Secure_Login_Frontend_Handler {

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Whether pro version is enabled.
	 *
	 * @var bool
	 */
	private $is_pro_version;

	/**
	 * Encryption handler instance.
	 *
	 * @var Secure_Login_Encryption_Handler
	 */
	private $encryption_handler;

	/**
	 * Database manager instance.
	 *
	 * @var Secure_Login_Database_Manager
	 */
	private $database_manager;

	/**
	 * Constructor - initializes frontend handler.
	 *
	 * @param string                          $table_name         Database table name.
	 * @param bool                            $is_pro_version     Whether pro version is enabled.
	 * @param Secure_Login_Encryption_Handler $encryption_handler Encryption handler instance.
	 * @param Secure_Login_Database_Manager   $database_manager   Database manager instance.
	 */
	public function __construct( $table_name, $is_pro_version, $encryption_handler, $database_manager ) {
		$this->table_name         = $table_name;
		$this->is_pro_version     = $is_pro_version;
		$this->encryption_handler = $encryption_handler;
		$this->database_manager   = $database_manager;

		// Register hooks.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
		add_shortcode( 'secure_login_form', array( $this, 'frontend_form_shortcode' ) );

		// Register v2 AJAX handlers for new encryption format (only v2 supported now).
		add_action( 'wp_ajax_save_secure_login_data_v2', array( $this, 'handle_save_login_data_v2' ) );
		add_action( 'wp_ajax_nopriv_save_secure_login_data_v2', array( $this, 'handle_save_login_data_v2' ) );

		// Add handler for public key retrieval (delegates to encryption handler)
		add_action( 'wp_ajax_slc_get_public_key', array( $this->encryption_handler, 'handle_get_public_key' ) );
		add_action( 'wp_ajax_nopriv_slc_get_public_key', array( $this->encryption_handler, 'handle_get_public_key' ) );
	}

	/**
	 * Enqueue frontend scripts for the shortcode form.
	 *
	 * @return void
	 */
	public function enqueue_frontend_scripts() {
		// Only enqueue if shortcode is present on the page.
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'secure_login_form' ) ) {
			wp_enqueue_script( 'jquery' );

			// Enqueue dashicons for password toggle.
			wp_enqueue_style( 'dashicons' );

			// Enqueue frontend CSS.
			wp_enqueue_style(
				'secure-login-frontend-css',
				SECURE_LOGIN_PLUGIN_URL . 'assets/css/frontend.css',
				array( 'dashicons' ),
				SECURE_LOGIN_VERSION
			);

			// Use the new secure frontend script with proper encryption flow.
			wp_enqueue_script( 'secure-login-frontend', SECURE_LOGIN_PLUGIN_URL . 'assets/js/frontend-secure.js', array( 'jquery' ), SECURE_LOGIN_VERSION, true );

			// Prepare localization data.
			$localize_data = array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'secure_login_nonce' ),
				'is_pro'  => $this->is_pro_version,
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

			// Add public key for RSA encryption.
			$public_key = $this->encryption_handler->get_public_key();
			if ( ! is_wp_error( $public_key ) ) {
				$localize_data['public_key'] = $public_key;
			}

			// No need to send passkey info to frontend - clients don't have passkeys

			// Localize script with data.
			wp_localize_script( 'secure-login-frontend', 'secureLoginAjax', $localize_data );
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
				'title'       => __( 'Submit Secure Login Data', 'secure-login-collector' ),
				'button_text' => __( 'Submit Securely', 'secure-login-collector' ),
			),
			$atts
		);

		ob_start();
		?>
		<div class="secure-login-form-container">
			<h3><?php echo esc_html( $atts['title'] ); ?></h3>
			<div class="security-info">
				<?php
				// Check text type selection.
				$text_type       = get_option( 'secure_login_frontend_text_type', 'default' );
				$custom_text     = get_option( 'secure_login_frontend_form_text', '' );
				$expiration_days = get_option( 'secure_login_expiration_days', 30 );

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
			<form id="secure-login-frontend-form" class="secure-login-form">
				<div class="form-group">
					<label for="email"><?php echo esc_html__( 'Email Address:', 'secure-login-collector' ); ?> <span class="required">*</span></label>
					<input type="email" id="email" name="email" placeholder="<?php echo esc_attr__( 'your@email.com', 'secure-login-collector' ); ?>" required>
				</div>
				
				<div class="form-group">
					<label for="user_name"><?php echo esc_html__( 'Name:', 'secure-login-collector' ); ?> <span class="required">*</span></label>
					<input type="text" id="user_name" name="user_name" placeholder="<?php echo esc_attr__( 'Your full name', 'secure-login-collector' ); ?>" required>
				</div>
				
				<div class="form-group">
					<label for="login_url"><?php echo esc_html__( 'Login URL:', 'secure-login-collector' ); ?> <span class="required">*</span></label>
					<input type="text" id="login_url" name="login_url" placeholder="<?php echo esc_attr__( 'https://example.com/login or service name', 'secure-login-collector' ); ?>" required>
					<small class="form-help"><?php echo esc_html__( 'Enter the login URL or service name where these credentials are used.', 'secure-login-collector' ); ?></small>
				</div>
				
				<div class="form-group">
					<label for="username_email"><?php echo esc_html__( 'Username/Email:', 'secure-login-collector' ); ?> <span class="required">*</span></label>
					<input type="text" id="username_email" name="username_email" placeholder="<?php echo esc_attr__( 'Username or email for login', 'secure-login-collector' ); ?>" required>
					<small class="form-help"><?php echo esc_html__( 'The username or email address used to log into this service.', 'secure-login-collector' ); ?></small>
				</div>
				
				<div class="form-group">
					<label for="password"><?php echo esc_html__( 'Password:', 'secure-login-collector' ); ?> <span class="required">*</span></label>
					<div class="password-field-wrapper">
						<input type="password" id="password" name="password" placeholder="<?php echo esc_attr__( 'Enter the password', 'secure-login-collector' ); ?>" required>
						<button type="button" class="password-toggle-btn" aria-label="<?php echo esc_attr__( 'Toggle password visibility', 'secure-login-collector' ); ?>">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
						</button>
					</div>
					<small class="form-help"><?php echo esc_html__( 'The password for this account.', 'secure-login-collector' ); ?></small>
				</div>
				
				<div class="form-group">
					<label for="additional_notes"><?php echo esc_html__( 'Additional Notes:', 'secure-login-collector' ); ?></label>
					<textarea id="additional_notes" name="additional_notes" placeholder="<?php echo esc_attr__( 'Any additional information, security questions, backup codes, etc. (optional)', 'secure-login-collector' ); ?>" rows="4"></textarea>
					<small class="form-help"><?php echo esc_html__( 'Optional: Any additional information like security questions, backup codes, or special instructions.', 'secure-login-collector' ); ?></small>
				</div>
				
				<div class="form-group">
					<button type="submit" class="secure-submit-btn"><?php echo esc_html( $atts['button_text'] ); ?></button>
				</div>
				
				<div id="form-message" class="form-message" style="display: none;"></div>
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
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'secure_login_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		// Get submission data.
		$submission_json = isset( $_POST['submission'] ) ? wp_unslash( $_POST['submission'] ) : '';

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

		// Check if Pro version with pro keys is available on the server
		// Use same logic as V2 encryption handler for consistency
		$is_pro_encrypted     = false;
		$server_credential_id = null;

		if ( $this->is_pro_version && get_option( 'secure_login_pro_keys_active', false ) ) {
			// Mark as Pro encrypted - data will be encrypted with pro public key
			// The passkey decryption happens on the admin side during decryption
			$is_pro_encrypted     = true;
			$server_credential_id = get_option( 'secure_login_passkey_credential_id' );
		}

		// Create encrypted package for storage.
		$encrypted_package = array(
			'encryptedData'   => sanitize_text_field( $submission['encryptedData'] ),
			'rsaEncryptedKey' => sanitize_text_field( $submission['rsaEncryptedKey'] ), // Store as-is from client
			'iv'              => sanitize_text_field( $submission['iv'] ),
			'salt'            => sanitize_text_field( $submission['salt'] ),
			'isProEncrypted'  => $is_pro_encrypted, // Server determines this
			'credentialId'    => $server_credential_id, // Server's passkey credential ID
			'version'         => 2, // Mark as v2 format.
		);

		// Add encryption metadata.
		$metadata['encryption_type']    = $is_pro_encrypted ? 'aes-rsa-passkey-v2' : 'aes-rsa-v2';
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

		wp_send_json_success( __( 'Login data saved securely with enhanced encryption.', 'secure-login-collector' ) );
	}
}
