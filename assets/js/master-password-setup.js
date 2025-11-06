/**
 * Master Password Setup Wizard
 *
 * Handles initial master password setup for FREE tier users
 * and migration from v1 to v2 encryption.
 *
 * @package SecureLoginCollector
 * @since 2.0.0
 */

(function($) {
	'use strict';

	/**
	 * Master Password Wizard
	 */
	const MasterPasswordWizard = {
		/**
		 * Current step in wizard.
		 */
		currentStep: 1,

		/**
		 * Total number of steps.
		 */
		totalSteps: 5,

		/**
		 * User's master password (stored only during wizard flow).
		 */
		masterPassword: null,

		/**
		 * Generated RSA keypair.
		 */
		rsaKeypair: null,

		/**
		 * Initialize wizard.
		 */
		init() {
			this.bindEvents();
			// this.checkAutoLaunch(); // Disabled - wizard only opens when button clicked
		},

		/**
		 * Bind event listeners.
		 */
		bindEvents() {
			// Launch wizard button.
			$(document).on('click', '.seculoco-launch-wizard', (e) => {
				e.preventDefault();
				this.launch();
			});

			// Wizard navigation.
			$(document).on('click', '.seculoco-wizard-next', (e) => {
				e.preventDefault();
				this.nextStep();
			});

			$(document).on('click', '.seculoco-wizard-back', (e) => {
				e.preventDefault();
				this.previousStep();
			});

			$(document).on('click', '.seculoco-wizard-cancel', (e) => {
				e.preventDefault();
				this.cancel();
			});

			// Password input validation.
			$(document).on('input', '#seculoco-wizard-password', () => {
				this.validatePassword();
			});

			$(document).on('input', '#seculoco-wizard-password-confirm', () => {
				this.validatePasswordConfirm();
			});

			// Warning checkbox.
			$(document).on('change', '#seculoco-wizard-warning-checkbox', () => {
				this.validateWarningAcceptance();
			});
		},

		/**
		 * Check if wizard should auto-launch.
		 */
		checkAutoLaunch() {
			// Auto-launch if setup required and no encryption configured.
			if (!seculocoWizard.hasPrivateKey && !seculocoWizard.encryptionVersion) {
				setTimeout(() => {
					this.launch();
				}, 500);
			}
		},

		/**
		 * Launch wizard.
		 */
		launch() {
			this.currentStep = 1;
			this.renderWizard();
			this.showStep(1);
		},

		/**
		 * Render wizard modal.
		 */
		renderWizard() {
			const title = seculocoWizard.strings.setupRequired;

			const modalHTML = `
				<div class="seculoco-wizard-overlay">
					<div class="seculoco-wizard-modal">
						<div class="seculoco-wizard-header">
							<h2>${title}</h2>
							<div class="seculoco-wizard-progress">
								<div class="seculoco-wizard-progress-bar" data-step="1"></div>
							</div>
							<div class="seculoco-wizard-step-indicator">
								Step <span class="seculoco-wizard-current-step">1</span> of ${this.totalSteps}
							</div>
						</div>
						<div class="seculoco-wizard-body">
							${this.renderSteps()}
						</div>
						<div class="seculoco-wizard-footer">
							<button class="button seculoco-wizard-cancel">Cancel</button>
							<div class="seculoco-wizard-nav">
								<button class="button seculoco-wizard-back seculoco-hidden">Back</button>
								<button class="button button-primary seculoco-wizard-next">Next</button>
							</div>
						</div>
					</div>
				</div>
			`;

			// Remove existing wizard if present.
			$('.seculoco-wizard-overlay').remove();

			// Append wizard to body.
			$('body').append(modalHTML);
		},

		/**
		 * Render all wizard steps.
		 *
		 * @return {string} HTML for all steps.
		 */
		renderSteps() {
			return `
				${this.renderStep1()}
				${this.renderStep2()}
				${this.renderStep3()}
				${this.renderStep4()}
				${this.renderStep5()}
			`;
		},

		/**
		 * Render step 1: Explanation.
		 *
		 * @return {string} Step HTML.
		 */
		renderStep1() {
			return `
				<div class="seculoco-wizard-step" data-step="1">
					<div class="seculoco-wizard-step-content">
						<div class="seculoco-wizard-icon">🔐</div>
						<h3>What is a Master Password?</h3>
						<div class="seculoco-wizard-explanation">
							<p>
								Your <strong>master password</strong> is the key to encrypting and decrypting all
								login credentials stored by this plugin.
							</p>

							<div class="seculoco-alert seculoco-alert-info">
								<span class="seculoco-alert-icon">ℹ️</span>
								<div class="seculoco-alert-content">
									<div class="seculoco-alert-title">How it works:</div>
									<div class="seculoco-alert-message">
										<ul>
											<li>Your master password <strong>never leaves your browser</strong></li>
											<li>It's used to encrypt an RSA private key on your computer</li>
											<li>Only the encrypted key is stored on the server</li>
											<li>Without your master password, data cannot be decrypted</li>
										</ul>
									</div>
								</div>
							</div>

							<div class="seculoco-alert seculoco-alert-warning">
								<span class="seculoco-alert-icon">⚠️</span>
								<div class="seculoco-alert-content">
									<div class="seculoco-alert-title">Important:</div>
									<div class="seculoco-alert-message">
										This is <strong>zero-knowledge encryption</strong>. If you forget your
										master password, your data <strong>cannot be recovered</strong>.
										We recommend using a password manager like 1Password, Bitwarden, or LastPass.
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Render step 2: Password input.
		 *
		 * @return {string} Step HTML.
		 */
		renderStep2() {
			return `
				<div class="seculoco-wizard-step seculoco-hidden" data-step="2">
					<div class="seculoco-wizard-step-content">
						<div class="seculoco-wizard-icon">🔑</div>
						<h3>Create Your Master Password</h3>

						<div class="seculoco-form-group">
							<label class="seculoco-form-label" for="seculoco-wizard-password">
								Master Password
							</label>
							<div class="seculoco-password-input-wrapper">
								<input
									type="password"
									id="seculoco-wizard-password"
									class="seculoco-form-input"
									placeholder="Enter master password"
									autocomplete="new-password" />
								<button
									type="button"
									class="seculoco-password-toggle"
									data-target="seculoco-wizard-password">
									👁️
								</button>
							</div>
							<div class="seculoco-form-help">
								Minimum ${seculocoWizard.minPasswordLength} characters with uppercase, lowercase, number, and special character
							</div>
						</div>

						<div class="seculoco-password-strength">
							<div class="seculoco-password-strength-label">Password Strength:</div>
							<div class="seculoco-password-strength-bar">
								<div class="seculoco-password-strength-fill" data-strength="0"></div>
							</div>
							<div class="seculoco-password-strength-text">Enter a password</div>
						</div>

						<div class="seculoco-password-requirements">
							<h4>Password Recommendations:</h4>
							<ul>
								<li data-requirement="length">
									<span class="seculoco-requirement-icon">💡</span>
									Recommended: At least ${seculocoWizard.minPasswordLength} characters
								</li>
								<li data-requirement="uppercase">
									<span class="seculoco-requirement-icon">💡</span>
									Recommended: At least one uppercase letter (A-Z)
								</li>
								<li data-requirement="lowercase">
									<span class="seculoco-requirement-icon">💡</span>
									Recommended: At least one lowercase letter (a-z)
								</li>
								<li data-requirement="number">
									<span class="seculoco-requirement-icon">💡</span>
									Recommended: At least one number (0-9)
								</li>
								<li data-requirement="special">
									<span class="seculoco-requirement-icon">💡</span>
									Recommended: At least one special character (!@#$%^&*)
								</li>
							</ul>
							<div class="seculoco-alert seculoco-alert-info seculoco-margin-top-12">
								<span class="seculoco-alert-icon">ℹ️</span>
								<div class="seculoco-alert-content">
									<div class="seculoco-alert-message">
										Meeting more recommendations increases security. "Fair" strength or better is required.
									</div>
								</div>
							</div>
						</div>

						<div class="seculoco-alert seculoco-alert-info">
							<span class="seculoco-alert-icon">💡</span>
							<div class="seculoco-alert-content">
								<div class="seculoco-alert-title">Tip:</div>
								<div class="seculoco-alert-message">
									Consider using a password manager to generate and store a strong password.
								</div>
							</div>
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Render step 3: Password confirmation.
		 *
		 * @return {string} Step HTML.
		 */
		renderStep3() {
			return `
				<div class="seculoco-wizard-step seculoco-hidden" data-step="3">
					<div class="seculoco-wizard-step-content">
						<div class="seculoco-wizard-icon">✅</div>
						<h3>Confirm Your Master Password</h3>

						<div class="seculoco-form-group">
							<label class="seculoco-form-label" for="seculoco-wizard-password-confirm">
								Confirm Master Password
							</label>
							<div class="seculoco-password-input-wrapper">
								<input
									type="password"
									id="seculoco-wizard-password-confirm"
									class="seculoco-form-input"
									placeholder="Re-enter master password"
									autocomplete="new-password" />
								<button
									type="button"
									class="seculoco-password-toggle"
									data-target="seculoco-wizard-password-confirm">
									👁️
								</button>
							</div>
							<div class="seculoco-password-match-indicator seculoco-hidden"></div>
						</div>

						<div class="seculoco-alert seculoco-alert-warning">
							<span class="seculoco-alert-icon">⚠️</span>
							<div class="seculoco-alert-content">
								<div class="seculoco-alert-title">Double-check your password:</div>
								<div class="seculoco-alert-message">
									Make sure you've entered your password correctly. You'll need this exact
									password to decrypt your login data.
								</div>
							</div>
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Render step 4: Warning about password loss.
		 *
		 * @return {string} Step HTML.
		 */
		renderStep4() {
			return `
				<div class="seculoco-wizard-step seculoco-hidden" data-step="4">
					<div class="seculoco-wizard-step-content">
						<div class="seculoco-wizard-icon">⚠️</div>
						<h3>Important Warning</h3>

						<div class="seculoco-alert seculoco-alert-danger">
							<span class="seculoco-alert-icon">🚨</span>
							<div class="seculoco-alert-content">
								<div class="seculoco-alert-title">Risk of Data Loss</div>
								<div class="seculoco-alert-message">
									<p><strong>If you forget your master password:</strong></p>
									<ul>
										<li>All encrypted login data will be <strong>permanently lost</strong></li>
										<li>There is <strong>no way to recover</strong> your data</li>
										<li>This is an intentional security feature of zero-knowledge encryption</li>
									</ul>
								</div>
							</div>
						</div>

						<div class="seculoco-alert seculoco-alert-info">
							<span class="seculoco-alert-icon">💡</span>
							<div class="seculoco-alert-content">
								<div class="seculoco-alert-title">Best Practices:</div>
								<div class="seculoco-alert-message">
									<ul>
										<li>Store your password in a password manager (recommended)</li>
										<li>Write it down and keep it in a secure location</li>
										<li>Do NOT share your master password with anyone</li>
										<li>Consider using a passphrase instead of a single word</li>
									</ul>
								</div>
							</div>
						</div>

						<div class="seculoco-warning-acknowledgment">
							<label class="seculoco-checkbox-label">
								<input
									type="checkbox"
									id="seculoco-wizard-warning-checkbox" />
								<span>
									I understand that if I forget my master password, my encrypted data
									will be <strong>permanently lost</strong> and cannot be recovered.
								</span>
							</label>
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Render step 5: Final confirmation.
		 *
		 * @return {string} Step HTML.
		 */
		renderStep5() {
			return `
				<div class="seculoco-wizard-step seculoco-hidden" data-step="5">
					<div class="seculoco-wizard-step-content">
						<div class="seculoco-wizard-icon">🎉</div>
						<h3>Ready to Set Up</h3>

						<div class="seculoco-setup-summary">
							<p>Your master password is ready to be configured.</p>

							<div class="seculoco-alert seculoco-alert-success">
								<span class="seculoco-alert-icon">✅</span>
								<div class="seculoco-alert-content">
									<div class="seculoco-alert-title">What will happen:</div>
									<div class="seculoco-alert-message">
										<ol>
											<li>A secure RSA keypair will be generated in your browser</li>
											<li>Your master password will encrypt the private key</li>
											<li>Only the encrypted key will be stored on the server</li>
											<li>You'll be able to start storing encrypted login data</li>
										</ol>
									</div>
								</div>
							</div>

							<div class="seculoco-setup-reminder">
								<strong>Remember:</strong> Save your master password in a safe place before proceeding.
							</div>
						</div>

						<div class="seculoco-setup-progress seculoco-hidden">
							<div class="seculoco-progress-bar">
								<div class="seculoco-progress-fill"></div>
							</div>
							<div class="seculoco-progress-text">Initializing...</div>
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Show specific step.
		 *
		 * @param {number} step Step number to show.
		 */
		showStep(step) {
			this.currentStep = step;

			// Hide all steps.
			$('.seculoco-wizard-step').addClass('seculoco-hidden');

			// Show current step.
			$(`.seculoco-wizard-step[data-step="${step}"]`).removeClass('seculoco-hidden');

			// Update progress bar via data attribute.
			$('.seculoco-wizard-progress-bar').attr('data-step', step);
			$('.seculoco-wizard-current-step').text(step);

			// Update navigation buttons.
			this.updateNavigation();
		},

		/**
		 * Update navigation button states.
		 */
		updateNavigation() {
			const $backBtn = $('.seculoco-wizard-back');
			const $nextBtn = $('.seculoco-wizard-next');

			// Show/hide back button.
			if (this.currentStep === 1) {
				$backBtn.addClass('seculoco-hidden');
			} else {
				$backBtn.removeClass('seculoco-hidden');
			}

			// Update next button text and state.
			if (this.currentStep === this.totalSteps) {
				$nextBtn.text('Complete Setup');
			} else {
				$nextBtn.text('Next');
			}

			// Disable next button based on step validation.
			this.validateCurrentStep();
		},

		/**
		 * Validate current step.
		 */
		validateCurrentStep() {
			const $nextBtn = $('.seculoco-wizard-next');
			let isValid = true;

			switch (this.currentStep) {
				case 1:
					// Step 1 is always valid (explanation).
					isValid = true;
					break;

				case 2:
					// Step 2: Password must be "fair" strength or better (score >= 40).
					isValid = this.isPasswordValid();
					break;

				case 3:
					// Step 3: Passwords must match.
					isValid = this.doPasswordsMatch();
					break;

				case 4:
					// Step 4: Warning must be accepted.
					isValid = $('#seculoco-wizard-warning-checkbox').is(':checked');
					break;

				case 5:
					// Step 5: Always valid (ready to complete).
					isValid = true;
					break;
			}

			$nextBtn.prop('disabled', !isValid);
		},

		/**
		 * Go to next step.
		 */
		async nextStep() {
			if (this.currentStep === this.totalSteps) {
				// Final step: Complete setup.
				await this.completeSetup();
			} else {
				// Save password on step 2.
				if (this.currentStep === 2) {
					this.masterPassword = $('#seculoco-wizard-password').val();
				}

				// Move to next step.
				this.showStep(this.currentStep + 1);
			}
		},

		/**
		 * Go to previous step.
		 */
		previousStep() {
			if (this.currentStep > 1) {
				this.showStep(this.currentStep - 1);
			}
		},

		/**
		 * Cancel wizard.
		 */
		cancel() {
			if (confirm('Are you sure you want to cancel the setup? You will need to complete this setup to use the plugin.')) {
				$('.seculoco-wizard-overlay').remove();
				this.masterPassword = null;
				this.rsaKeypair = null;
			}
		},

		/**
		 * Validate password.
		 */
		validatePassword() {
			const password = $('#seculoco-wizard-password').val();
			const requirements = this.checkPasswordRequirements(password);
			const strength = this.calculatePasswordStrength(password, requirements);

			// Update requirement indicators (recommendations).
			Object.keys(requirements).forEach(req => {
				const $item = $(`[data-requirement="${req}"]`);
				const $icon = $item.find('.seculoco-requirement-icon');

				if (requirements[req]) {
					$icon.text('✅');
					$item.addClass('seculoco-requirement-met');
				} else {
					$icon.text('💡');
					$item.removeClass('seculoco-requirement-met');
				}
			});

			// Update strength bar.
			const $strengthFill = $('.seculoco-password-strength-fill');
			const $strengthText = $('.seculoco-password-strength-text');

			$strengthFill.attr('data-strength', strength.level);
			$strengthText.text(strength.text);

			// Validate current step.
			this.validateCurrentStep();
		},

		/**
		 * Validate password confirmation.
		 */
		validatePasswordConfirm() {
			const password = this.masterPassword || $('#seculoco-wizard-password').val();
			const confirm = $('#seculoco-wizard-password-confirm').val();
			const $indicator = $('.seculoco-password-match-indicator');

			if (!confirm) {
				$indicator.addClass('seculoco-hidden');
				this.validateCurrentStep();
				return;
			}

			if (password === confirm) {
				$indicator
					.removeClass('seculoco-hidden seculoco-password-mismatch')
					.addClass('seculoco-password-match')
					.text('✅ Passwords match');
			} else {
				$indicator
					.removeClass('seculoco-hidden seculoco-password-match')
					.addClass('seculoco-password-mismatch')
					.text('❌ Passwords do not match');
			}

			this.validateCurrentStep();
		},

		/**
		 * Validate warning acceptance.
		 */
		validateWarningAcceptance() {
			this.validateCurrentStep();
		},

		/**
		 * Check password requirements.
		 *
		 * @param {string} password Password to check.
		 * @return {Object} Requirements check results.
		 */
		checkPasswordRequirements(password) {
			return {
				length: password.length >= seculocoWizard.minPasswordLength,
				uppercase: /[A-Z]/.test(password),
				lowercase: /[a-z]/.test(password),
				number: /[0-9]/.test(password),
				special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
			};
		},

		/**
		 * Calculate password strength.
		 *
		 * @param {string} password Password to check.
		 * @param {Object} requirements Requirements check results.
		 * @return {Object} Strength information.
		 */
		calculatePasswordStrength(password, requirements) {
			if (!password) {
				return { level: 0, percentage: 0, text: 'Enter a password' };
			}

			const metRequirements = Object.values(requirements).filter(Boolean).length;
			const lengthBonus = Math.min(password.length - seculocoWizard.minPasswordLength, 8) * 2;
			const score = (metRequirements * 20) + lengthBonus;

			if (score < 40) {
				return { level: 1, percentage: score, text: 'Weak' };
			} else if (score < 70) {
				return { level: 2, percentage: score, text: 'Fair' };
			} else if (score < 90) {
				return { level: 3, percentage: score, text: 'Good' };
			} else {
				return { level: 4, percentage: 100, text: 'Strong' };
			}
		},

		/**
		 * Check if password is valid.
		 *
		 * Password is valid if strength is "fair" or better (score >= 40).
		 * All 5 criteria are now recommendations, not hard requirements.
		 *
		 * @return {boolean} True if valid.
		 */
		isPasswordValid() {
			const password = $('#seculoco-wizard-password').val();
			const requirements = this.checkPasswordRequirements(password);
			const strength = this.calculatePasswordStrength(password, requirements);

			// Accept "fair" strength or better (score >= 40)
			return strength.percentage >= 40;
		},

		/**
		 * Check if passwords match.
		 *
		 * @return {boolean} True if they match.
		 */
		doPasswordsMatch() {
			const password = this.masterPassword || $('#seculoco-wizard-password').val();
			const confirm = $('#seculoco-wizard-password-confirm').val();
			return password && confirm && password === confirm;
		},

		/**
		 * Complete setup.
		 */
		async completeSetup() {
			const $progressContainer = $('.seculoco-setup-progress');
			const $progressText = $('.seculoco-progress-text');
			const $progressFill = $('.seculoco-progress-fill');

			// Show progress.
			$progressContainer.removeClass('seculoco-hidden');
			$('.seculoco-setup-summary, .seculoco-setup-reminder').addClass('seculoco-hidden');
			$('.seculoco-wizard-footer button').prop('disabled', true);

			try {
				// Step 1: Derive wrapping key.
				$progressText.text(seculocoWizard.strings.deriving_key);
				$progressFill.attr('data-progress', '20');

				const salt = crypto.getRandomValues(new Uint8Array(32));
				const wrappingKey = await this.derivePasswordWrappingKey(this.masterPassword, salt);

				// Step 2: Generate RSA keypair.
				$progressText.text(seculocoWizard.strings.generating_rsa);
				$progressFill.attr('data-progress', '40');

				this.rsaKeypair = await crypto.subtle.generateKey(
					{
						name: 'RSA-OAEP',
						modulusLength: 4096,
						publicExponent: new Uint8Array([1, 0, 1]),
						hash: 'SHA-256'
					},
					true,
					['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']
				);

				// Step 3: Wrap private key.
				$progressText.text(seculocoWizard.strings.wrapping_key);
				$progressFill.attr('data-progress', '60');

				// Export private key to PKCS8 format first.
				const privateKeyBuffer = await crypto.subtle.exportKey('pkcs8', this.rsaKeypair.privateKey);

				// Manually encrypt with AES-GCM to extract the authentication tag.
				const iv = crypto.getRandomValues(new Uint8Array(12));
				const encrypted = await crypto.subtle.encrypt(
					{ name: 'AES-GCM', iv: iv, tagLength: 128 },
					wrappingKey,
					privateKeyBuffer
				);

				// AES-GCM returns: ciphertext + tag (last 16 bytes).
				const encryptedArray = new Uint8Array(encrypted);
				const ciphertext = encryptedArray.slice(0, -16);  // Everything except last 16 bytes.
				const tag = encryptedArray.slice(-16);            // Last 16 bytes.

				// Export public key as JWK.
				const publicKeyJWK = await crypto.subtle.exportKey('jwk', this.rsaKeypair.publicKey);

				// Step 4: Save to server.
				$progressText.text(seculocoWizard.strings.saving_to_server);
				$progressFill.attr('data-progress', '80');

				const saveData = {
					action: 'seculoco_setup_master_password',
					nonce: seculocoWizard.nonce,
					wrapped_private_key: this.bufferToBase64(ciphertext.buffer),
					public_key_jwk: JSON.stringify(publicKeyJWK),
					master_password_salt: this.bufferToBase64(salt),
					key_wrapping_iv: this.bufferToBase64(iv),
					key_wrapping_tag: this.bufferToBase64(tag.buffer)
				};

				const response = await $.ajax({
					url: seculocoWizard.ajaxurl,
					type: 'POST',
					data: saveData
				});


				if (!response.success) {
					throw new Error(response.data || 'Setup failed');
				}

				// Step 5: Complete.
				$progressText.text(seculocoWizard.strings.setupComplete);
				$progressFill.attr('data-progress', '100');

				// Show success and redirect.
				setTimeout(() => {
					this.showSuccess(response.data.redirect);
				}, 1000);

			} catch (error) {
				console.error('Setup error:', error);
				alert(seculocoWizard.strings.setupFailed + error.message);
				$('.seculoco-wizard-footer button').prop('disabled', false);
				$progressContainer.addClass('seculoco-hidden');
				$('.seculoco-setup-summary, .seculoco-setup-reminder').removeClass('seculoco-hidden');
			}
		},

		/**
		 * Show success message and redirect.
		 *
		 * @param {string} redirectUrl URL to redirect to.
		 */
		showSuccess(redirectUrl) {
			$('.seculoco-wizard-body').html(`
				<div class="seculoco-wizard-success">
					<div class="seculoco-wizard-icon">✅</div>
					<h3>Setup Complete!</h3>
					<p>Your master password has been configured successfully.</p>
					<p>Redirecting...</p>
				</div>
			`);

			setTimeout(() => {
				window.location.href = redirectUrl;
			}, 2000);
		},

		/**
		 * Derive password wrapping key using PBKDF2.
		 *
		 * @param {string} password Master password.
		 * @param {Uint8Array} salt Salt for key derivation.
		 * @return {Promise<CryptoKey>} Derived wrapping key.
		 */
		async derivePasswordWrappingKey(password, salt) {
			const encoder = new TextEncoder();
			const passwordBuffer = encoder.encode(password);

			// Import password as key material.
			const keyMaterial = await crypto.subtle.importKey(
				'raw',
				passwordBuffer,
				'PBKDF2',
				false,
				['deriveKey']
			);

			// Derive wrapping key with PBKDF2.
			const wrappingKey = await crypto.subtle.deriveKey(
				{
					name: 'PBKDF2',
					salt: salt,
					iterations: 600000, // OWASP recommendation.
					hash: 'SHA-256'
				},
				keyMaterial,
				{ name: 'AES-GCM', length: 256 },
				false,
				['encrypt', 'decrypt']
			);

			return wrappingKey;
		},

		/**
		 * Convert ArrayBuffer to Base64.
		 *
		 * @param {ArrayBuffer} buffer Buffer to convert.
		 * @return {string} Base64 string.
		 */
		bufferToBase64(buffer) {
			const bytes = new Uint8Array(buffer);
			let binary = '';
			for (let i = 0; i < bytes.byteLength; i++) {
				binary += String.fromCharCode(bytes[i]);
			}
			return btoa(binary);
		}
	};

	// Password toggle handler.
	$(document).on('click', '.seculoco-password-toggle', function(e) {
		e.preventDefault();
		const targetId = $(this).data('target');
		const $input = $('#' + targetId);
		const currentType = $input.attr('type');

		if (currentType === 'password') {
			$input.attr('type', 'text');
			$(this).text('🙈');
		} else {
			$input.attr('type', 'password');
			$(this).text('👁️');
		}
	});

	// Initialize on document ready.
	$(document).ready(function() {
		MasterPasswordWizard.init();
	});

})(jQuery);
