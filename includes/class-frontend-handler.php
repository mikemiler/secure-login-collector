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

		// Register AJAX handlers.
		add_action( 'wp_ajax_save_secure_login_data', array( $this, 'handle_save_login_data' ) );
		add_action( 'wp_ajax_nopriv_save_secure_login_data', array( $this, 'handle_save_login_data' ) );
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

			// Always use RSA encryption on frontend.
			// Server will handle passkey re-encryption if ultra-secure mode is enabled.
			wp_enqueue_script( 'secure-login-frontend', SECURE_LOGIN_PLUGIN_URL . 'assets/js/frontend-ultra-secure.js', array( 'jquery' ), SECURE_LOGIN_VERSION, true );

			// Prepare localization data.
			$localize_data = array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'secure_login_nonce' ),
				'is_pro'  => $this->is_pro_version,
				'strings' => array(
					'required_fields_error' => __( 'Please fill in all required fields (Email Address, Name, Login URL, Username/Email, and Password).', 'secure-login-collector' ),
					'submitting'            => __( 'Submitting...', 'secure-login-collector' ),
					'submit_securely'       => __( 'Submit Securely', 'secure-login-collector' ),
					'success_message'       => __( 'Login data saved securely! Thank you for your submission.', 'secure-login-collector' ),
					'error_prefix'          => __( 'Error saving data: ', 'secure-login-collector' ),
					'unknown_error'         => __( 'Unknown error', 'secure-login-collector' ),
					'network_error'         => __( 'Network error occurred while saving data. Please try again.', 'secure-login-collector' ),
					'encryption_error'      => __( 'Encryption failed. Please try again.', 'secure-login-collector' ),
					'show_password'         => __( 'Show password', 'secure-login-collector' ),
					'hide_password'         => __( 'Hide password', 'secure-login-collector' ),
				),
			);

			// Add public key for RSA encryption.
			$public_key = $this->encryption_handler->get_public_key();
			if ( ! is_wp_error( $public_key ) ) {
				$localize_data['public_key'] = $public_key;
			}

			// Localize script with data.
			wp_localize_script( 'secure-login-frontend', 'secureLoginAjax', $localize_data );
		}
	}

	/**
	 * Handle AJAX request to save encrypted login data.
	 *
	 * @return void
	 */
	public function handle_save_login_data() {
		// Verify nonce for security.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'secure_login_nonce' ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'secure-login-collector' ) );
			return;
		}

		// Sanitize and validate input.
		$encrypted_data = isset( $_POST['encrypted_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['encrypted_data'] ) ) : '';
		$metadata       = isset( $_POST['metadata'] ) ? wp_unslash( $_POST['metadata'] ) : ''; // Don't sanitize JSON data as it corrupts the format.

		if ( empty( $encrypted_data ) || empty( $metadata ) ) {
			wp_send_json_error( __( 'Missing required data.', 'secure-login-collector' ) );
			return;
		}

		// Validate metadata is valid JSON.
		$metadata_array = json_decode( $metadata, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( __( 'Invalid metadata format. JSON Error: ', 'secure-login-collector' ) . json_last_error_msg() );
			return;
		}

		// Validate required metadata fields.
		$required_fields = array( 'email', 'name', 'login_url' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $metadata_array[ $field ] ) || empty( $metadata_array[ $field ] ) ) {
				// translators: %s is the name of the missing field.
				wp_send_json_error( sprintf( __( 'Missing %s in metadata.', 'secure-login-collector' ), $field ) );
				return;
			}
		}

		// Sanitize individual metadata fields after JSON decode.
		$metadata_array['email']                = sanitize_email( $metadata_array['email'] );
		$metadata_array['name']                 = sanitize_text_field( $metadata_array['name'] );
		$metadata_array['login_url']            = sanitize_text_field( $metadata_array['login_url'] );
		$metadata_array['created_at']           = isset( $metadata_array['created_at'] ) ? sanitize_text_field( $metadata_array['created_at'] ) : current_time( 'c' );
		$metadata_array['encryption_key_hint']  = isset( $metadata_array['encryption_key_hint'] ) ? sanitize_text_field( $metadata_array['encryption_key_hint'] ) : '';
		$metadata_array['key_hostname']         = isset( $metadata_array['key_hostname'] ) ? sanitize_text_field( $metadata_array['key_hostname'] ) : '';
		$metadata_array['key_timestamp_suffix'] = isset( $metadata_array['key_timestamp_suffix'] ) ? sanitize_text_field( $metadata_array['key_timestamp_suffix'] ) : '';

		// Re-encode the sanitized metadata.
		$metadata = wp_json_encode( $metadata_array );

		// ULTRA-SECURE MODE: Double-encrypt with passkey-derived encryption if enabled.
		// Instead of decrypt->re-encrypt, we encrypt the already-encrypted RSA data.
		if ( $this->is_pro_version && get_option( 'secure_login_ultra_secure_mode', false ) && get_option( 'secure_login_passkey_registered', false ) ) {
			// Encrypt the RSA-encrypted data with passkey-derived encryption (double encryption).
			$passkey_encrypted_data = $this->encryption_handler->encrypt_with_passkey_key( $encrypted_data, null );

			if ( false !== $passkey_encrypted_data ) {
				// Use double-encrypted data and update metadata.
				$encrypted_data                     = $passkey_encrypted_data;
				$metadata_array['encryption_type']  = 'passkey_derived';
				$metadata_array['inner_encryption'] = 'rsa'; // Track inner encryption method.
				$metadata_array['double_encrypted'] = true; // Flag for double encryption.
				$metadata                           = wp_json_encode( $metadata_array );
			}
		}

		// Prepare data for database insertion.
		$user_agent = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$data = array(
			'encrypted_data' => $encrypted_data,
			'metadata'       => $metadata,
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
		$this->database_manager->send_notification( $metadata_array['email'], $metadata_array['name'] );

		wp_send_json_success( __( 'Login data saved securely.', 'secure-login-collector' ) );
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
}
