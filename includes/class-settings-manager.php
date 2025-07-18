<?php
/**
 * Settings Manager Class
 *
 * Handles plugin settings and configuration.
 *
 * @package SecureLoginCollector
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Secure_Login_Settings_Manager
 *
 * Handles all plugin settings and configuration.
 */
class Secure_Login_Settings_Manager {

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
	 * Constructor - initializes settings manager.
	 *
	 * @param bool                            $is_pro_version     Whether pro version is enabled.
	 * @param Secure_Login_Encryption_Handler $encryption_handler Encryption handler instance.
	 */
	public function __construct( $is_pro_version, $encryption_handler ) {
		$this->is_pro_version     = $is_pro_version;
		$this->encryption_handler = $encryption_handler;

		// Register hooks.
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings submenu.
	 */
	public function add_settings_menu() {
		// Add to the plugin's own menu
		add_submenu_page(
			'secure-login-collector',
			__( 'Settings', 'secure-login-collector' ),
			__( 'Settings', 'secure-login-collector' ),
			'manage_options',
			'secure-login-collector-settings',
			array( $this, 'settings_page' )
		);
		
		// Add custom account page - Disabled to use Freemius account page instead
		// if ( file_exists( SECURE_LOGIN_PLUGIN_DIR . 'includes/account-page-simple.php' ) ) {
		// 	require_once SECURE_LOGIN_PLUGIN_DIR . 'includes/account-page-simple.php';
		// }
		
		// add_submenu_page(
		// 	'secure-login-collector',
		// 	__( 'Account', 'secure-login-collector' ),
		// 	__( 'Account', 'secure-login-collector' ),
		// 	'manage_options',
		// 	'secure-login-collector-account',
		// 	'slc_simple_account_page'
		// );
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting( 'secure_login_settings', 'secure_login_notification_email' );
		register_setting( 'secure_login_settings', 'secure_login_enable_notifications' );
		register_setting( 'secure_login_settings', 'secure_login_expiration_days' );
		register_setting( 'secure_login_settings', 'secure_login_ultra_secure_mode' );
		register_setting( 'secure_login_settings', 'secure_login_frontend_form_text', array( $this, 'sanitize_frontend_form_text' ) );
		register_setting( 'secure_login_settings', 'secure_login_frontend_text_type' );

		add_settings_section(
			'secure_login_notification_section',
			__( 'Email Notifications', 'secure-login-collector' ),
			array( $this, 'notification_section_callback' ),
			'secure_login_settings'
		);

		add_settings_section(
			'secure_login_frontend_section',
			__( 'Frontend Customization', 'secure-login-collector' ),
			array( $this, 'frontend_section_callback' ),
			'secure_login_settings'
		);

		add_settings_section(
			'secure_login_expiration_section',
			__( 'Data Expiration', 'secure-login-collector' ),
			array( $this, 'expiration_section_callback' ),
			'secure_login_settings'
		);

		// Add encryption settings section (for all users now).
		add_settings_section(
			'secure_login_encryption_section',
			__( 'Encryption Settings', 'secure-login-collector' ),
			array( $this, 'encryption_section_callback' ),
			'secure_login_settings'
		);

		// Add pro version settings section.
		if ( $this->is_pro_version ) {
			add_settings_section(
				'secure_login_pro_section',
				__( 'Pro Version Settings', 'secure-login-collector' ),
				array( $this, 'pro_section_callback' ),
				'secure_login_settings'
			);

			add_settings_field(
				'secure_login_ultra_secure_mode',
				__( 'Ultra-Secure Mode', 'secure-login-collector' ),
				array( $this, 'ultra_secure_mode_callback' ),
				'secure_login_settings',
				'secure_login_pro_section'
			);
		}

		add_settings_field(
			'secure_login_enable_notifications',
			__( 'Enable Email Notifications', 'secure-login-collector' ),
			array( $this, 'enable_notifications_callback' ),
			'secure_login_settings',
			'secure_login_notification_section'
		);

		add_settings_field(
			'secure_login_notification_email',
			__( 'Notification Email Address', 'secure-login-collector' ),
			array( $this, 'notification_email_callback' ),
			'secure_login_settings',
			'secure_login_notification_section'
		);

		add_settings_field(
			'secure_login_frontend_text_type',
			__( 'Text Type', 'secure-login-collector' ),
			array( $this, 'frontend_text_type_callback' ),
			'secure_login_settings',
			'secure_login_frontend_section'
		);

		add_settings_field(
			'secure_login_frontend_form_text',
			__( 'Custom Description Text', 'secure-login-collector' ),
			array( $this, 'frontend_form_text_callback' ),
			'secure_login_settings',
			'secure_login_frontend_section'
		);

		add_settings_field(
			'secure_login_expiration_days',
			__( 'Auto-Delete After (Days)', 'secure-login-collector' ),
			array( $this, 'expiration_days_callback' ),
			'secure_login_settings',
			'secure_login_expiration_section'
		);
	}

	/**
	 * Notification settings section callback.
	 */
	public function notification_section_callback() {
		echo '<p>' . esc_html__( 'Configure email notifications for new login data submissions.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Frontend settings section callback.
	 */
	public function frontend_section_callback() {
		echo '<p>' . esc_html__( 'Customize the frontend form appearance and text.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Expiration settings section callback.
	 */
	public function expiration_section_callback() {
		echo '<p>' . esc_html__( 'Configure automatic deletion of old login data.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Encryption settings section callback.
	 */
	public function encryption_section_callback() {
		echo '<p>' . esc_html__( 'Manage RSA encryption keys for secure data transmission.', 'secure-login-collector' ) . '</p>';

		// Display encryption methods information.
		echo '<div class="encryption-methods-info" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin-bottom: 20px;">';
		echo '<h4>' . esc_html__( 'Available Encryption Methods:', 'secure-login-collector' ) . '</h4>';
		echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 10px;">';

		// Ultra-Secure (Passkey-derived).
		if ( $this->is_pro_version ) {
			echo '<div style="background: white; border: 1px solid #4CAF50; border-radius: 4px; padding: 12px;">';
			echo '<div style="display: flex; align-items: center; margin-bottom: 8px;">';
			echo '<span style="background: linear-gradient(135deg, #4CAF50, #45a049); color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 8px;">🔐 ULTRA-SECURE</span>';
			echo '<strong>' . esc_html__( 'Passkey-Derived', 'secure-login-collector' ) . '</strong>';
			echo '</div>';
			echo '<p style="margin: 0; font-size: 13px; color: #666;">' . esc_html__( 'Uses your passkey signature to derive encryption keys. Maximum security - even server compromise cannot decrypt data without your physical device.', 'secure-login-collector' ) . '</p>';
			echo '</div>';
		}

		// RSA-2048.
		echo '<div style="background: white; border: 1px solid #2196F3; border-radius: 4px; padding: 12px;">';
		echo '<div style="display: flex; align-items: center; margin-bottom: 8px;">';
		echo '<span style="background: linear-gradient(135deg, #2196F3, #1976D2); color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 8px;">🔒 SECURE</span>';
		echo '<strong>' . esc_html__( 'RSA-2048', 'secure-login-collector' ) . '</strong>';
		echo '</div>';
		echo '<p style="margin: 0; font-size: 13px; color: #666;">' . esc_html__( 'Industry-standard RSA encryption with 2048-bit keys. Secure for most use cases and available for all users.', 'secure-login-collector' ) . '</p>';
		echo '</div>';

		// XOR (Legacy).
		echo '<div style="background: white; border: 1px solid #FF9800; border-radius: 4px; padding: 12px;">';
		echo '<div style="display: flex; align-items: center; margin-bottom: 8px;">';
		echo '<span style="background: linear-gradient(135deg, #FF9800, #F57C00); color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 8px;">🔓 LEGACY</span>';
		echo '<strong>' . esc_html__( 'XOR (Legacy)', 'secure-login-collector' ) . '</strong>';
		echo '</div>';
		echo '<p style="margin: 0; font-size: 13px; color: #666;">' . esc_html__( 'Simple XOR encryption for backward compatibility. Used by older entries before RSA was implemented.', 'secure-login-collector' ) . '</p>';
		echo '</div>';

		echo '</div>';
		echo '</div>';

		// Display key status.
		$public_key     = get_option( 'secure_login_public_key' );
		$keys_generated = get_option( 'secure_login_keys_generated' );

		if ( $public_key && $keys_generated ) {
			echo '<div class="notice notice-success inline"><p>';
			echo '<strong>' . esc_html__( 'RSA Keys Status:', 'secure-login-collector' ) . '</strong> ';
			echo esc_html__( 'Active', 'secure-login-collector' ) . ' ';
			echo '<em>(' . esc_html__( 'Generated:', 'secure-login-collector' ) . ' ' . esc_html( $keys_generated ) . ')</em>';
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-warning inline"><p>';
			echo '<strong>' . esc_html__( 'RSA Keys Status:', 'secure-login-collector' ) . '</strong> ';
			echo esc_html__( 'Not generated', 'secure-login-collector' );
			echo '</p></div>';
		}

		// Key management buttons.
		echo '<p>';
		echo '<button type="button" class="button button-secondary" id="generate-rsa-keys">' . esc_html__( 'Generate New RSA Keys', 'secure-login-collector' ) . '</button> ';
		echo '<button type="button" class="button button-secondary" id="export-public-key">' . esc_html__( 'Export Public Key', 'secure-login-collector' ) . '</button>';
		echo '</p>';

		// Add JavaScript for key management.
		?>
		<script>
		jQuery(document).ready(function($) {
			$('#generate-rsa-keys').on('click', function() {
				if (!confirm('<?php echo esc_js( __( 'This will generate new RSA keys and invalidate all existing encrypted data. Continue?', 'secure-login-collector' ) ); ?>')) {
					return;
				}
				
				var button = $(this);
				button.prop('disabled', true).text('<?php echo esc_js( __( 'Generating...', 'secure-login-collector' ) ); ?>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'generate_rsa_keys',
						nonce: '<?php echo esc_attr( wp_create_nonce( 'generate_rsa_keys' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							alert('<?php echo esc_js( __( 'RSA keys generated successfully!', 'secure-login-collector' ) ); ?>');
							location.reload();
						} else {
							alert('<?php echo esc_js( __( 'Failed to generate keys:', 'secure-login-collector' ) ); ?> ' + response.data);
						}
						button.prop('disabled', false).text('<?php echo esc_js( __( 'Generate New RSA Keys', 'secure-login-collector' ) ); ?>');
					},
					error: function() {
						alert('<?php echo esc_js( __( 'Network error occurred.', 'secure-login-collector' ) ); ?>');
						button.prop('disabled', false).text('<?php echo esc_js( __( 'Generate New RSA Keys', 'secure-login-collector' ) ); ?>');
					}
				});
			});
			
			$('#export-public-key').on('click', function() {
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'export_public_key',
						nonce: '<?php echo esc_attr( wp_create_nonce( 'export_public_key' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							// Create download
							var blob = new Blob([response.data.public_key], {type: 'text/plain'});
							var url = window.URL.createObjectURL(blob);
							var a = document.createElement('a');
							a.href = url;
							a.download = 'secure-login-public-key.pem';
							a.click();
							window.URL.revokeObjectURL(url);
						} else {
							alert('<?php echo esc_js( __( 'Failed to export public key:', 'secure-login-collector' ) ); ?> ' + response.data);
						}
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Pro version settings section callback.
	 */
	public function pro_section_callback() {
		echo '<p>' . esc_html__( 'Advanced security settings for the pro version including passkey authentication.', 'secure-login-collector' ) . '</p>';

		// Display passkey status if pro version.
		if ( $this->is_pro_version ) {
			$passkey_registered    = get_option( 'secure_login_passkey_registered', false );
			$passkey_user_id       = get_option( 'secure_login_passkey_user_id', 0 );
			$passkey_registered_at = get_option( 'secure_login_passkey_registered_at', '' );

			if ( $passkey_registered ) {
				echo '<div class="notice notice-success inline"><p>';
				echo '<strong>' . esc_html__( 'Passkey Status:', 'secure-login-collector' ) . '</strong> ';
				echo esc_html__( 'Registered', 'secure-login-collector' ) . ' ';
				if ( $passkey_registered_at ) {
					echo '<em>(' . esc_html__( 'Registered:', 'secure-login-collector' ) . ' ' . esc_html( $passkey_registered_at ) . ')</em>';
				}
				echo '</p></div>';

				echo '<p>';
				echo '<button type="button" class="button button-secondary" id="test-passkey-encryption">' . esc_html__( 'Test Passkey Encryption', 'secure-login-collector' ) . '</button> ';
				echo '<button type="button" class="button button-secondary" id="reset-passkey">' . esc_html__( 'Reset Passkey', 'secure-login-collector' ) . '</button>';
				echo '</p>';
			} else {
				echo '<div class="notice notice-warning inline"><p>';
				echo '<strong>' . esc_html__( 'Passkey Status:', 'secure-login-collector' ) . '</strong> ';
				echo esc_html__( 'Not registered', 'secure-login-collector' );
				echo '</p></div>';

				echo '<p>';
				echo '<button type="button" class="button button-primary" id="register-passkey">' . esc_html__( 'Register Passkey', 'secure-login-collector' ) . '</button>';
				echo '</p>';
			}

			// Add JavaScript for passkey management.
			?>
			<script>
			jQuery(document).ready(function($) {
				$('#register-passkey').on('click', function() {
					var button = $(this);
					button.prop('disabled', true).text('<?php echo esc_js( __( 'Registering...', 'secure-login-collector' ) ); ?>');
					
					// Generate challenge
					var challenge = new Uint8Array(32);
					window.crypto.getRandomValues(challenge);
					
					var userId = <?php echo esc_attr( get_current_user_id() ); ?>;
					var userIdBytes = new TextEncoder().encode(userId.toString());
					
					var createCredentialDefaultArgs = {
						publicKey: {
							rp: {
								name: "<?php echo esc_js( get_bloginfo( 'name' ) ); ?>",
								id: "<?php echo esc_js( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>",
							},
							user: {
								id: userIdBytes,
								name: "<?php echo esc_js( wp_get_current_user()->user_login ); ?>",
								displayName: "<?php echo esc_js( wp_get_current_user()->display_name ); ?>"
							},
							pubKeyCredParams: [{alg: -7, type: "public-key"}],
							authenticatorSelection: {
								authenticatorAttachment: "platform",
								userVerification: "required"
							},
							timeout: 60000,
							challenge: challenge
						}
					};
					
					navigator.credentials.create(createCredentialDefaultArgs)
						.then((credential) => {
							// Send credential to server
							button.text('<?php echo esc_js( __( 'Saving registration...', 'secure-login-collector' ) ); ?>');
							
							$.ajax({
								url: ajaxurl,
								type: 'POST',
								data: {
									action: 'register_passkey',
									credential_id: btoa(String.fromCharCode(...new Uint8Array(credential.rawId))),
									public_key: btoa(String.fromCharCode(...new Uint8Array(credential.response.getPublicKey()))),
									nonce: '<?php echo esc_attr( wp_create_nonce( 'register_passkey' ) ); ?>'
								},
								success: function(response) {
									if (response.success) {
										alert('<?php echo esc_js( __( 'Passkey registered successfully!', 'secure-login-collector' ) ); ?>');
										location.reload();
									} else {
										alert('<?php echo esc_js( __( 'Registration failed:', 'secure-login-collector' ) ); ?> ' + response.data);
									}
								},
								error: function() {
									alert('<?php echo esc_js( __( 'Network error occurred during registration.', 'secure-login-collector' ) ); ?>');
								},
								complete: function() {
									button.prop('disabled', false).text('<?php echo esc_js( __( 'Register Passkey', 'secure-login-collector' ) ); ?>');
								}
							});
						})
						.catch((err) => {
							console.error('Passkey creation failed:', err);
							alert('<?php echo esc_js( __( 'Passkey creation failed:', 'secure-login-collector' ) ); ?> ' + err.message);
							button.prop('disabled', false).text('<?php echo esc_js( __( 'Register Passkey', 'secure-login-collector' ) ); ?>');
						});
				});
				
				$('#test-passkey-encryption').on('click', function() {
					var button = $(this);
					button.prop('disabled', true).text('<?php echo esc_js( __( 'Testing...', 'secure-login-collector' ) ); ?>');
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'test_passkey_encryption',
							nonce: '<?php echo esc_attr( wp_create_nonce( 'test_passkey_encryption' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								alert('<?php echo esc_js( __( 'Passkey encryption test passed!', 'secure-login-collector' ) ); ?>');
							} else {
								alert('<?php echo esc_js( __( 'Passkey encryption test failed:', 'secure-login-collector' ) ); ?> ' + response.data);
							}
						},
						error: function() {
							alert('<?php echo esc_js( __( 'Network error occurred during test.', 'secure-login-collector' ) ); ?>');
						},
						complete: function() {
							button.prop('disabled', false).text('<?php echo esc_js( __( 'Test Passkey Encryption', 'secure-login-collector' ) ); ?>');
						}
					});
				});
				
				$('#reset-passkey').on('click', function() {
					if (!confirm('<?php echo esc_js( __( 'This will reset your passkey and invalidate all passkey-encrypted data. Continue?', 'secure-login-collector' ) ); ?>')) {
						return;
					}
					
					var button = $(this);
					button.prop('disabled', true).text('<?php echo esc_js( __( 'Authenticating...', 'secure-login-collector' ) ); ?>');
					
					// Generate challenge for authentication.
					var challenge = new Uint8Array(32);
					window.crypto.getRandomValues(challenge);
					
					var getCredentialDefaultArgs = {
						publicKey: {
							timeout: 60000,
							challenge: challenge,
							userVerification: "required"
						},
					};
					
					navigator.credentials.get(getCredentialDefaultArgs)
						.then((assertion) => {
							// Send authentication data to server for reset authorization.
							button.text('<?php echo esc_js( __( 'Authorizing reset...', 'secure-login-collector' ) ); ?>');
							
							$.ajax({
								url: ajaxurl,
								type: 'POST',
								data: {
									action: 'reset_passkey',
									signature: btoa(String.fromCharCode(...new Uint8Array(assertion.response.signature))),
									authenticator_data: btoa(String.fromCharCode(...new Uint8Array(assertion.response.authenticatorData))),
									nonce: '<?php echo esc_attr( wp_create_nonce( 'reset_passkey' ) ); ?>'
								},
								success: function(response) {
									if (response.success) {
										alert('<?php echo esc_js( __( 'Passkey reset authorized! You can now register a new passkey.', 'secure-login-collector' ) ); ?>');
										location.reload();
									} else {
										alert('<?php echo esc_js( __( 'Reset authorization failed:', 'secure-login-collector' ) ); ?> ' + response.data);
									}
								},
								error: function() {
									alert('<?php echo esc_js( __( 'Network error occurred during reset authorization.', 'secure-login-collector' ) ); ?>');
								},
								complete: function() {
									button.prop('disabled', false).text('<?php echo esc_js( __( 'Reset Passkey', 'secure-login-collector' ) ); ?>');
								}
							});
						})
						.catch((err) => {
							console.error('Passkey authentication for reset failed:', err);
							alert('<?php echo esc_js( __( 'Authentication failed:', 'secure-login-collector' ) ); ?> ' + err.message);
							button.prop('disabled', false).text('<?php echo esc_js( __( 'Reset Passkey', 'secure-login-collector' ) ); ?>');
						});
				});
			});
			</script>
			<?php
		}
	}

	/**
	 * Settings field callbacks.
	 */

	/**
	 * Enable notifications field callback.
	 */
	public function enable_notifications_callback() {
		$enabled = get_option( 'secure_login_enable_notifications', false );
		echo '<input type="checkbox" id="secure_login_enable_notifications" name="secure_login_enable_notifications" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="secure_login_enable_notifications"> ' . esc_html__( 'Send email notifications when new login data is received', 'secure-login-collector' ) . '</label>';
	}

	/**
	 * Notification email field callback.
	 */
	public function notification_email_callback() {
		$email = get_option( 'secure_login_notification_email', get_option( 'admin_email' ) );
		echo '<input type="email" id="secure_login_notification_email" name="secure_login_notification_email" value="' . esc_attr( $email ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Email address to receive notifications. Defaults to site admin email.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Frontend form text field callback.
	 */
	public function frontend_form_text_callback() {
		$text      = get_option( 'secure_login_frontend_form_text', '' );
		$text_type = get_option( 'secure_login_frontend_text_type', 'default' );

		// Generate the default text with placeholder for dynamic expiration text.
		$default_text  = '<p><strong>' . __( 'What happens to your data:', 'secure-login-collector' ) . '</strong> ' . __( 'Your login data is encrypted in your browser before being sent to our server. We use strong RSA-2048 encryption to ensure maximum security.', 'secure-login-collector' ) . '</p>';
		$default_text .= '<p><strong>' . __( 'Security & Privacy:', 'secure-login-collector' ) . '</strong> ' . __( 'Your data is encrypted in your browser before being sent to our server. We store the encrypted data securely{EXPIRATION_TEXT}.', 'secure-login-collector' ) . '</p>';

		// If no custom text is set, show the default text.
		$display_text = ! empty( $text ) ? $text : $default_text;
		$is_disabled  = ( 'default' === $text_type ) ? 'disabled' : '';

		echo '<textarea id="secure_login_frontend_form_text" name="secure_login_frontend_form_text" rows="6" class="large-text" style="width: 100%;" ' . esc_attr( $is_disabled ) . '>' . esc_textarea( $display_text ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Custom text to display above the login form. Basic HTML allowed (p, strong, em, br, a). This field is automatically populated with the default text when no custom text is provided. Use {EXPIRATION_TEXT} placeholder for automatic expiration information.', 'secure-login-collector' ) . '</p>';

		// Add JavaScript for radio button interaction.
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			var defaultText = <?php echo wp_json_encode( $default_text ); ?>;
			var originalText = $('#secure_login_frontend_form_text').val();
			
			function toggleTextarea() {
				var selectedType = $('input[name="secure_login_frontend_text_type"]:checked').val();
				var textarea = $('#secure_login_frontend_form_text');
				
				if (selectedType === 'default') {
					textarea.prop('disabled', true).css('background-color', '#f1f1f1');
					// If current text is empty or is the default text, show default
					if (textarea.val() === '' || textarea.val() === defaultText) {
						textarea.val(defaultText);
					}
				} else {
					textarea.prop('disabled', false).css('background-color', '#fff');
					// If the current text is the default text and we're switching to custom, 
					// keep it so user can edit it
				}
			}
			
			// Initial state
			toggleTextarea();
			
			// Listen for radio button changes
			$('input[name="secure_login_frontend_text_type"]').on('change', toggleTextarea);
		});
		</script>
		<?php
	}

	/**
	 * Frontend text type field callback.
	 */
	public function frontend_text_type_callback() {
		$text_type = get_option( 'secure_login_frontend_text_type', 'default' );

		echo '<fieldset>';
		echo '<label>';
		echo '<input type="radio" name="secure_login_frontend_text_type" value="default" ' . checked( 'default', $text_type, false ) . '> ';
		echo esc_html__( 'Default (Automatic encryption & expiration details)', 'secure-login-collector' );
		echo '</label><br><br>';

		echo '<label>';
		echo '<input type="radio" name="secure_login_frontend_text_type" value="custom" ' . checked( 'custom', $text_type, false ) . '> ';
		echo esc_html__( 'Custom Text (Use the text below)', 'secure-login-collector' );
		echo '</label>';
		echo '</fieldset>';

		echo '<p class="description">' . esc_html__( 'Choose whether to use the default security information text or your custom text below.', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Expiration days field callback.
	 */
	public function expiration_days_callback() {
		$days = get_option( 'secure_login_expiration_days', 30 );
		echo '<input type="number" id="secure_login_expiration_days" name="secure_login_expiration_days" value="' . esc_attr( $days ) . '" min="0" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Number of days after which login data will be automatically deleted. Set to 0 to disable automatic deletion (data will be retained until manually deleted).', 'secure-login-collector' ) . '</p>';
	}

	/**
	 * Ultra secure mode field callback.
	 */
	public function ultra_secure_mode_callback() {
		$enabled = get_option( 'secure_login_ultra_secure_mode', false );
		echo '<input type="checkbox" id="secure_login_ultra_secure_mode" name="secure_login_ultra_secure_mode" value="1" ' . checked( 1, $enabled, false ) . ' />';
		echo '<label for="secure_login_ultra_secure_mode"> ' . esc_html__( 'Enable passkey-derived encryption (maximum security)', 'secure-login-collector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, data is encrypted using keys derived from your passkey signature. This provides maximum security - even if hackers modify the plugin code, they cannot decrypt data without your physical passkey device. Requires passkey registration.', 'secure-login-collector' ) . '</p>';

		$passkey_registered = get_option( 'secure_login_passkey_registered', false );
		if ( ! $passkey_registered ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Warning:', 'secure-login-collector' ) . '</strong> ' . esc_html__( 'You must register a passkey before enabling ultra-secure mode.', 'secure-login-collector' ) . '</p></div>';
		}
	}

	/**
	 * Settings page.
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Secure Login Collector Settings', 'secure-login-collector' ); ?></h1>
			
			<!-- Shortcode Display Section -->
			<div class="notice notice-info" style="margin: 20px 0; padding: 15px;">
				<h2 style="margin-top: 0;"><?php echo esc_html__( 'Frontend Form Shortcode', 'secure-login-collector' ); ?></h2>
				<p><?php echo esc_html__( 'Use this shortcode to display the secure login form on any page or post:', 'secure-login-collector' ); ?></p>
				<div style="background: #f5f5f5; padding: 10px 15px; border-radius: 4px; display: inline-block; font-family: monospace; font-size: 16px; border: 1px solid #ddd;">
					[secure_login_form]
				</div>
				<p style="margin-top: 10px;">
					<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText('[secure_login_form]'); this.textContent = '<?php echo esc_js( __( 'Copied!', 'secure-login-collector' ) ); ?>'; setTimeout(() => { this.textContent = '<?php echo esc_js( __( 'Copy Shortcode', 'secure-login-collector' ) ); ?>'; }, 2000);">
						<?php echo esc_html__( 'Copy Shortcode', 'secure-login-collector' ); ?>
					</button>
				</p>
				<p style="margin-top: 15px; font-style: italic; color: #666;">
					<?php echo esc_html__( 'Simply paste this shortcode into any page or post where you want clients to submit their login credentials.', 'secure-login-collector' ); ?>
				</p>
			</div>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( 'secure_login_settings' );
				do_settings_sections( 'secure_login_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize frontend form text.
	 *
	 * @param string $text The text to sanitize.
	 * @return string Sanitized text.
	 */
	public function sanitize_frontend_form_text( $text ) {
		// Get the text type selection.
		$text_type = isset( $_POST['secure_login_frontend_text_type'] ) ? sanitize_text_field( wp_unslash( $_POST['secure_login_frontend_text_type'] ) ) : 'default';

		// If "default" is selected, don't save any custom text (save empty string).
		if ( 'default' === $text_type ) {
			return '';
		}

		// For custom text, sanitize and allow basic HTML.
		return wp_kses(
			$text,
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
	}
}
