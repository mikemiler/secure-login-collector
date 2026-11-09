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
	 * Maximum length of the raw submission JSON in bytes.
	 */
	const MAX_SUBMISSION_LENGTH = 300000;

	/**
	 * Maximum base64 length of the encrypted payload (256 KB).
	 */
	const MAX_ENCRYPTED_DATA_LENGTH = 262144;

	/**
	 * Maximum length of the name and email fields.
	 */
	const MAX_METADATA_LENGTH = 255;

	/**
	 * Maximum length of the login URL / service name field.
	 */
	const MAX_LOGIN_URL_LENGTH = 2048;

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
	 * Spam protection instance.
	 *
	 * @var Seculoco_Spam_Protection
	 */
	private $spam_protection;

	/**
	 * Constructor - initializes frontend handler.
	 *
	 * @param string                          $table_name         Database table name.
	 * @param Seculoco_Encryption_Service $encryption_handler Encryption handler instance.
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
					'required_fields_error'   => __( 'Please fill in all required fields (Email Address, Name, Login URL, and Login Credentials).', 'secure-login-collector' ),
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
		// Check if encryption is initialized.
		if ( ! seculoco_is_encryption_initialized() ) {
			// Show error message to admins, helpful message to regular users.
			if ( current_user_can( 'manage_options' ) ) {
				ob_start();
				?>
				<div class="seculoco-form-container">
					<div class="seculoco-error-notice">
						<span class="dashicons dashicons-warning"></span>
						<div class="seculoco-error-content">
							<h3><?php echo esc_html__( 'Encryption Not Configured', 'secure-login-collector' ); ?></h3>
							<p>
								<?php
								echo wp_kses_post(
									__( '<strong>Administrator Notice:</strong> The master password has not been set up yet. The form cannot accept submissions until encryption is properly configured.', 'secure-login-collector' )
								);
								?>
							</p>
						</div>
					</div>
				</div>
				<?php
				return ob_get_clean();
			} else {
				// Show generic message to regular users.
				ob_start();
				?>
				<div class="seculoco-form-container">
					<div class="seculoco-info-notice">
						<span class="dashicons dashicons-info"></span>
						<div class="seculoco-info-content">
							<h3><?php echo esc_html__( 'Form Temporarily Unavailable', 'secure-login-collector' ); ?></h3>
							<p><?php echo esc_html__( 'This form is currently being configured. Please check back later.', 'secure-login-collector' ); ?></p>
						</div>
					</div>
				</div>
				<?php
				return ob_get_clean();
			}
		}

		$atts = shortcode_atts(
			array(
				'button_text' => __( 'Submit Securely', 'secure-login-collector' ),
			),
			$atts
		);

		if ( ! $this->has_encryption_keys() ) {
			if ( current_user_can( 'manage_options' ) ) {
				ob_start();
				?>
				<div class="seculoco-form-container seculoco-alert-danger">
					<div class="seculoco-alert seculoco-alert-warning">
						<div class="seculoco-alert-icon dashicons dashicons-warning seculoco-no-keys-icon"></div>
						<div class="seculoco-alert-content">
							<div class="seculoco-alert-title"><strong><?php esc_html_e( 'Finish encryption setup first', 'secure-login-collector' ); ?></strong></div>
							<p><?php esc_html_e( 'Set up encryption in the settings before embedding the secure login form. Only admins see this alert.', 'secure-login-collector' ); ?></p>
						</div>
					</div>
				</div>
				<?php
				return ob_get_clean();
			}

			return sprintf(
				'<div class="seculoco-form-container"><div class="seculoco-alert seculoco-alert-info">%s</div></div>',
				esc_html__( 'Secure login form coming soon.', 'secure-login-collector' )
			);
		}

		ob_start();
		?>
		<div class="seculoco-form-container">
			<div class="seculoco-security-info">
				<img src="<?php echo esc_url( SECULOCO_PLUGIN_URL . 'assets/img/seculoco-secure-data-transfer-300.png' ); ?>" alt="<?php echo esc_attr__( 'Secure Encrypted Data Transmission', 'secure-login-collector' ); ?>" class="seculoco-security-badge-icon" />
				<div class="seculoco-security-info-text">
				<?php
				// Check text type selection.
				$text_type       = get_option( SECULOCO_OPTION_FRONTEND_TEXT_TYPE, 'default' );
				$custom_text     = get_option( SECULOCO_OPTION_FRONTEND_FORM_TEXT, '' );
				$expiration_days = seculoco_get_expiration_days();

				if ( 'custom' === $text_type && ! empty( $custom_text ) ) :
					// Handle placeholder replacement for custom text.
					if ( $expiration_days > 0 ) {
						// translators: %d is the number of days after which data will be deleted.
						$expiration_text = sprintf( __( '%d', 'secure-login-collector' ), $expiration_days );
					} else {
						$expiration_text =  __( 'unlimited', 'secure-login-collector' );
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
								<p><strong><?php echo esc_html__( 'What happens to your data:', 'secure-login-collector' ); ?></strong><br> <?php echo esc_html__( 'Your login details are sealed on your device before they travel to us. Only our team can unlock them with an extra security key, and our zero-knowledge integration guarantees the highest possible protection for your data.', 'secure-login-collector' ); ?></p>
					
				<?php endif; ?>
				</div>
			</div>

			

			<form id="seculoco-frontend-form" class="seculoco-form">
				<?php
				// Allow pro features to start time tracking before form render.
				do_action( 'seculoco_before_form_render' );

				// Generate honeypot field HTML (invisible to humans, catches bots).
				if ( seculoco_is_honeypot_enabled() ) {
					echo $this->spam_protection->generate_honeypot_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in method.
				}
				?>

				<div class="seculoco-form-group">
					<label for="user_name"><?php echo esc_html__( 'Your Name:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<input type="text" id="user_name" name="user_name" maxlength="255" placeholder="<?php echo esc_attr__( 'Your full name', 'secure-login-collector' ); ?>" required>
				</div>

				<div class="seculoco-form-group">
					<label for="email"><?php echo esc_html__( 'Your Email Address:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<input type="email" id="email" name="email" maxlength="255" placeholder="<?php echo esc_attr__( 'your@email.com', 'secure-login-collector' ); ?>" required>
				</div>

				<div class="seculoco-form-group">
					<label for="login_url"><?php echo esc_html__( 'Login URL / Service Name:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<input type="text" id="login_url" name="login_url" maxlength="2048" placeholder="<?php echo esc_attr__( 'https://example.com/login or service name', 'secure-login-collector' ); ?>" required>
					<small class="seculoco-form-help"><?php echo esc_html__( 'Enter the login URL or service name where these credentials are used.', 'secure-login-collector' ); ?></small>
				</div>

				<div class="seculoco-form-group">
					<label for="credentials_data"><?php echo esc_html__( 'Login Credentials:', 'secure-login-collector' ); ?> <span class="seculoco-required">*</span></label>
					<textarea id="credentials_data" name="credentials_data" placeholder="<?php echo esc_attr__( 'Paste all your login credentials here (username, password, notes, backup codes, etc.)', 'secure-login-collector' ); ?>" rows="8" required></textarea>
					<small class="seculoco-form-help"><?php echo esc_html__( 'Enter all your login information here: username, password, security questions, backup codes or any other relevant details. Everything is encrypted securely.', 'secure-login-collector' ); ?></small>
				</div>

				<div id="seculoco-form-message" class="seculoco-form-message" role="alert"></div>

				<div class="seculoco-form-group">
					<button type="submit" class="seculoco-submit-btn"><?php echo esc_html( $atts['button_text'] ); ?></button>
				</div>
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

		// Decode the JSON envelope as-is; every field is validated and sanitized individually below.
		$submission_json = isset( $_POST['submission'] ) ? wp_unslash( $_POST['submission'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_string( $submission_json ) || '' === $submission_json ) {
			wp_send_json_error( __( 'Missing submission data.', 'secure-login-collector' ) );
			return;
		}

		if ( strlen( $submission_json ) > self::MAX_SUBMISSION_LENGTH ) {
			wp_send_json_error( __( 'Submission is too large.', 'secure-login-collector' ) );
			return;
		}

		// Parse submission data.
		$submission = json_decode( $submission_json, true );
		if ( ! is_array( $submission ) ) {
			wp_send_json_error( __( 'Invalid submission format.', 'secure-login-collector' ) );
			return;
		}

		// Validate spam protection (bot detection via timing and hidden fields).
		if ( seculoco_is_honeypot_enabled() ) {
			$spam_result = $this->spam_protection->validate_submission( $_POST );
			if ( is_wp_error( $spam_result ) ) {
				// Log silently but return generic error (don't reveal spam protection mechanism).
				wp_send_json_error( $spam_result->get_error_message() );
				return;
			}
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

		// Validate metadata. Field => array( label, maximum length ).
		$metadata          = is_array( $submission['metadata'] ) ? $submission['metadata'] : array();
		$required_metadata = array(
			'email'     => array( __( 'Email Address', 'secure-login-collector' ), self::MAX_METADATA_LENGTH ),
			'name'      => array( __( 'Name', 'secure-login-collector' ), self::MAX_METADATA_LENGTH ),
			'login_url' => array( __( 'Login URL', 'secure-login-collector' ), self::MAX_LOGIN_URL_LENGTH ),
		);
		foreach ( $required_metadata as $field => $rule ) {
			if ( ! isset( $metadata[ $field ] ) || ! is_string( $metadata[ $field ] ) || '' === trim( $metadata[ $field ] ) ) {
				// translators: %s is the name of the missing metadata field.
				wp_send_json_error( sprintf( __( 'Missing required metadata: %s', 'secure-login-collector' ), $field ) );
				return;
			}

			if ( mb_strlen( $metadata[ $field ] ) > $rule[1] ) {
				// translators: 1: field label, 2: maximum number of characters.
				wp_send_json_error( sprintf( __( '%1$s is too long (maximum %2$d characters).', 'secure-login-collector' ), $rule[0], $rule[1] ) );
				return;
			}
		}

		// Rebuild metadata from known fields only. The login URL field also accepts a plain service name.
		$login_url = trim( $metadata['login_url'] );
		$metadata  = array(
			'email'      => sanitize_email( $metadata['email'] ),
			'name'       => sanitize_text_field( $metadata['name'] ),
			'login_url'  => preg_match( '#^https?://#i', $login_url ) ? esc_url_raw( $login_url ) : sanitize_text_field( $login_url ),
			'created_at' => isset( $metadata['created_at'] ) && is_string( $metadata['created_at'] ) ? substr( sanitize_text_field( $metadata['created_at'] ), 0, 40 ) : current_time( 'c' ),
		);

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
				'encryption_type'  => 'aes-rsa-password-v3',
			),
			$metadata
		);

		// Extract encryption metadata.
		$is_pro_encrypted     = isset( $encryption_metadata['is_pro_encrypted'] ) ? (bool) $encryption_metadata['is_pro_encrypted'] : false;
		$encryption_type      = isset( $encryption_metadata['encryption_type'] ) ? $encryption_metadata['encryption_type'] : 'aes-rsa-password-v3';
		$server_credential_id = isset( $encryption_metadata['credential_id'] ) ? $encryption_metadata['credential_id'] : null;

		// Create encrypted package for storage.
		$encrypted_package = array(
			'encryptedData'   => $this->sanitize_encrypted_field( $submission['encryptedData'], 'encryptedData', 0, self::MAX_ENCRYPTED_DATA_LENGTH ),
			'rsaEncryptedKey' => $this->sanitize_encrypted_field( $submission['rsaEncryptedKey'], 'rsaEncryptedKey', 256 ), // RSA-2048 ciphertext.
			'iv'              => $this->sanitize_encrypted_field( $submission['iv'], 'iv', 12 ), // AES-GCM IV.
			'salt'            => $this->sanitize_encrypted_field( $submission['salt'], 'salt', 32 ), // Random salt from frontend-secure.js.
			'isProEncrypted'  => $is_pro_encrypted, // Server determines this.
			'credentialId'    => $server_credential_id,
			'version'         => 2, // Mark as v2 format.
		);

		// Add encryption metadata (uses values from filter or defaults).
		$metadata['encryption_type']    = $encryption_type;
		$metadata['encryption_version'] = 2;
		$metadata['is_pro_encrypted']   = $is_pro_encrypted;
		if ( $server_credential_id ) {
			$metadata['credential_id'] = $server_credential_id;
		}

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

	/**
	 * Validate and normalise base64-encoded fields received from the frontend.
	 *
	 * Accepts URL-safe alphabet characters, strips whitespace, fixes padding,
	 * and rejects unexpected characters before persisting values.
	 *
	 * @param string $value          Raw field value from the submission payload.
	 * @param string $field          Field name for error messages.
	 * @param int    $expected_bytes Exact decoded length in bytes, 0 to skip the check.
	 * @param int    $max_length     Maximum base64 length, 0 to skip the check.
	 * @return string Normalised base64 string.
	 */
	private function sanitize_encrypted_field( $value, $field, $expected_bytes = 0, $max_length = 0 ) {
		if ( ! is_string( $value ) ) {
			// translators: %s is the name of the encrypted field.
			wp_send_json_error( sprintf( __( 'Invalid encrypted field: %s', 'secure-login-collector' ), $field ) );
		}

		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			// translators: %s is the name of the encrypted field.
			wp_send_json_error( sprintf( __( 'Encrypted field %s cannot be empty.', 'secure-login-collector' ), $field ) );
		}

		if ( $max_length > 0 && strlen( $trimmed ) > $max_length ) {
			// translators: %s is the name of the encrypted field.
			wp_send_json_error( sprintf( __( 'Encrypted field %s is too large.', 'secure-login-collector' ), $field ) );
		}

		$normalized = preg_replace( '/\s+/', '', $trimmed );
		$normalized = strtr( $normalized, '-_', '+/' );

		$padding = strlen( $normalized ) % 4;
		if ( 0 !== $padding ) {
			$normalized .= str_repeat( '=', 4 - $padding );
		}

		if ( preg_match( '/[^A-Za-z0-9\/+=]/', $normalized ) ) {
			// translators: %s is the name of the encrypted field.
			wp_send_json_error( sprintf( __( 'Encrypted field %s contains invalid characters.', 'secure-login-collector' ), $field ) );
		}

		// Decoding validates the client ciphertext; nothing is executed.
		$decoded = base64_decode( $normalized, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			// translators: %s is the name of the encrypted field.
			wp_send_json_error( sprintf( __( 'Encrypted field %s is not valid base64 data.', 'secure-login-collector' ), $field ) );
		}

		if ( $expected_bytes > 0 && strlen( $decoded ) !== $expected_bytes ) {
			// translators: %s is the name of the encrypted field.
			wp_send_json_error( sprintf( __( 'Encrypted field %s has an invalid length.', 'secure-login-collector' ), $field ) );
		}

		return $normalized;
	}

	/**
	 * Determine if any encryption keys exist.
	 *
	 * @return bool
	 */
	private function has_encryption_keys() {
		$has_keys = $this->has_password_keys();
		/**
		 * Filter: seculoco_has_encryption_keys
		 *
		 * Allows premium builds to declare alternate encryption readiness.
		 *
		 * @param bool $has_keys Whether usable encryption keys exist.
		 */
		return (bool) apply_filters( 'seculoco_has_encryption_keys', $has_keys );
	}

	/**
	 * Determine if password-based encryption is configured.
	 *
	 * @return bool
	 */
	private function has_password_keys() {
		if ( function_exists( 'seculoco_has_password_encryption' ) ) {
			return seculoco_has_password_encryption();
		}

		return (bool) get_option( SECULOCO_OPTION_PASSWORD_ENCRYPTION_ACTIVE, false );
	}
}
