/**
 * Secure Login Collector - Admin JavaScript
 *
 * Merged file containing:
 * - Core admin functionality
 * - Master password setup wizard
 * - Master password reset
 *
 * @package SecureLoginCollector
 * @since 2.0.0
 */

// ====================================================================
// SHARED UTILITIES
// ====================================================================
var SecureLoginUtils = {
	/**
	 * Escape HTML to prevent XSS
	 *
	 * @param {string} text - Text to escape
	 * @return {string} Escaped text
	 */
	escapeHtml: function (text) {
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return text.replace(
			/[&<>"']/g,
			function (m) {
				return map[m];
			}
		);
	},

	/**
	 * Escape attribute values to prevent XSS
	 *
	 * @param {string} text - Text to escape
	 * @return {string} Escaped text
	 */
	escapeAttr: function (text) {
		return this.escapeHtml( text ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
	},

	/**
	 * Convert ArrayBuffer to Base64
	 *
	 * @param {ArrayBuffer} buffer - Buffer to convert
	 * @return {string} Base64 string
	 */
	bufferToBase64: function (buffer) {
		var bytes  = new Uint8Array( buffer );
		var binary = '';
		for (var i = 0; i < bytes.byteLength; i++) {
			binary += String.fromCharCode( bytes[i] );
		}
		return btoa( binary );
	},

	/**
	 * Copy text to clipboard with fallback
	 *
	 * @param {string} text - Text to copy
	 * @param {Element} button - Button element for feedback
	 */
	copyToClipboard: function (text, button) {
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText( text ).then(
				function () {
					SecureLoginUtils.showCopyButtonFeedback( button );
				}
			).catch(
				function () {
					SecureLoginUtils.copyToClipboardFallback( text );
					SecureLoginUtils.showCopyButtonFeedback( button );
				}
			);
		} else {
			SecureLoginUtils.copyToClipboardFallback( text );
			SecureLoginUtils.showCopyButtonFeedback( button );
		}
	},

	/**
	 * Fallback copy function for older browsers
	 *
	 * @param {string} text - Text to copy
	 */
	copyToClipboardFallback: function (text) {
		var textArea       = document.createElement( 'textarea' );
		textArea.value     = text;
		textArea.className = 'seculoco-copy-to-clipboard';
		document.body.appendChild( textArea );
		textArea.focus();
		textArea.select();
		try {
			document.execCommand( 'copy' );
		} catch (err) {
			console.error( 'Failed to copy to clipboard:', err );
		}
		document.body.removeChild( textArea );
	},

	/**
	 * Show copy button feedback
	 *
	 * @param {Element} button - Button element
	 */
	showCopyButtonFeedback: function (button) {
		var originalText   = button.textContent;
		button.textContent = '✓ Copied!';
		button.classList.add( 'seculoco-copy-btn-success' );
		setTimeout(
			function () {
				button.textContent = originalText;
				button.classList.remove( 'seculoco-copy-btn-success' );
			},
			2000
		);
	}
};

// ====================================================================
// MASTER PASSWORD WIZARD
// ====================================================================
(function ($) {
	'use strict';

	/**
	 * Master Password Wizard
	 */
	var MasterPasswordWizard = {
		/**
		 * Current step in wizard
		 */
		currentStep: 1,

		/**
		 * Total number of steps
		 */
		totalSteps: 5,

		/**
		 * User's master password (stored only during wizard flow)
		 */
		masterPassword: null,

		/**
		 * Generated RSA keypair
		 */
		rsaKeypair: null,

		/**
		 * Initialize wizard
		 */
		init: function () {
			this.bindEvents();
		},

		/**
		 * Bind event listeners
		 */
		bindEvents: function () {
			var self = this;

			// Launch wizard button
			$( document ).on(
				'click',
				'.seculoco-launch-wizard',
				function (e) {
					e.preventDefault();
					self.launch();
				}
			);

			// Wizard navigation
			$( document ).on(
				'click',
				'.seculoco-wizard-next',
				function (e) {
					e.preventDefault();
					self.nextStep();
				}
			);

			$( document ).on(
				'click',
				'.seculoco-wizard-back',
				function (e) {
					e.preventDefault();
					self.previousStep();
				}
			);

			$( document ).on(
				'click',
				'.seculoco-wizard-cancel',
				function (e) {
					e.preventDefault();
					self.cancel();
				}
			);

			// Password input validation
			$( document ).on(
				'input',
				'#seculoco-wizard-password',
				function () {
					self.validatePassword();
				}
			);

			$( document ).on(
				'input',
				'#seculoco-wizard-password-confirm',
				function () {
					self.validatePasswordConfirm();
				}
			);

			// Warning checkbox
			$( document ).on(
				'change',
				'#seculoco-wizard-warning-checkbox',
				function () {
					self.validateWarningAcceptance();
				}
			);
		},

		/**
		 * Launch wizard
		 */
		launch: function () {
			this.currentStep = 1;
			this.renderWizard();
			this.showStep( 1 );
		},

		/**
		 * Render wizard modal
		 */
		renderWizard: function () {
			var strings = seculocoWizard.strings;

			var modalHTML = '<div class="seculoco-wizard-overlay">' +
				'<div class="seculoco-wizard-modal">' +
					'<div class="seculoco-wizard-header">' +
						'<h2>' + strings.setupRequired + '</h2>' +
						'<div class="seculoco-wizard-progress">' +
							'<div class="seculoco-wizard-progress-bar" data-step="1"></div>' +
						'</div>' +
						'<div class="seculoco-wizard-step-indicator">' +
							strings.step + ' <span class="seculoco-wizard-current-step">1</span> ' + strings.of + ' ' + this.totalSteps +
						'</div>' +
					'</div>' +
					'<div class="seculoco-wizard-body">' +
						this.renderSteps() +
					'</div>' +
					'<div class="seculoco-wizard-footer">' +
						'<button class="button seculoco-wizard-cancel">' + strings.cancel + '</button>' +
						'<div class="seculoco-wizard-nav">' +
							'<button class="button seculoco-wizard-back seculoco-hidden">' + strings.back + '</button>' +
							'<button class="button button-primary seculoco-wizard-next">' + strings.next + '</button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';

			// Remove existing wizard if present
			$( '.seculoco-wizard-overlay' ).remove();

			// Append wizard to body
			$( 'body' ).append( modalHTML );
		},

		/**
		 * Render all wizard steps
		 *
		 * @return {string} HTML for all steps
		 */
		renderSteps: function () {
			return this.renderStep1() +
				this.renderStep2() +
				this.renderStep3() +
				this.renderStep4() +
				this.renderStep5();
		},

		/**
		 * Render step 1: Explanation
		 *
		 * @return {string} Step HTML
		 */
		renderStep1: function () {
			var strings = seculocoWizard.strings;

			return '<div class="seculoco-wizard-step" data-step="1">' +
				'<div class="seculoco-wizard-step-content">' +
					'<div class="seculoco-wizard-icon">🔐</div>' +
					'<h3>' + strings.step1_title + '</h3>' +
					'<div class="seculoco-wizard-explanation">' +
						'<p>' + strings.step1_intro + '</p>' +
						'<div class="seculoco-alert seculoco-alert-info">' +
							'<span class="seculoco-alert-icon">ℹ️</span>' +
							'<div class="seculoco-alert-content">' +
								'<div class="seculoco-alert-title">' + strings.step1_how_it_works + '</div>' +
								'<div class="seculoco-alert-message">' +
									'<ul>' +
										'<li>' + strings.step1_point1 + '</li>' +
										'<li>' + strings.step1_point2 + '</li>' +
										'<li>' + strings.step1_point3 + '</li>' +
										'<li>' + strings.step1_point4 + '</li>' +
									'</ul>' +
								'</div>' +
							'</div>' +
						'</div>' +
						'<div class="seculoco-alert seculoco-alert-warning">' +
							'<span class="seculoco-alert-icon">⚠️</span>' +
							'<div class="seculoco-alert-content">' +
								'<div class="seculoco-alert-title">' + strings.important + '</div>' +
								'<div class="seculoco-alert-message">' + strings.step1_warning + '</div>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';
		},

		/**
		 * Render step 2: Password input
		 *
		 * @return {string} Step HTML
		 */
		renderStep2: function () {
			var strings = seculocoWizard.strings;
			var minLen  = seculocoWizard.minPasswordLength;

			return '<div class="seculoco-wizard-step seculoco-hidden" data-step="2">' +
				'<div class="seculoco-wizard-step-content">' +
					'<div class="seculoco-wizard-icon">🔑</div>' +
					'<h3>' + strings.step2_title + '</h3>' +
					'<div class="seculoco-form-group">' +
						'<label class="seculoco-form-label" for="seculoco-wizard-password">' +
							strings.step2_label +
						'</label>' +
						'<div class="seculoco-password-input-wrapper">' +
							'<input type="password" id="seculoco-wizard-password" class="seculoco-form-input" ' +
								'placeholder="' + strings.step2_placeholder + '" autocomplete="new-password" />' +
							'<button type="button" class="seculoco-password-toggle" data-target="seculoco-wizard-password">👁️</button>' +
						'</div>' +
						'<div class="seculoco-form-help">' + strings.step2_help.replace( '{min}', minLen ) + '</div>' +
					'</div>' +
					'<div class="seculoco-password-strength">' +
						'<div class="seculoco-password-strength-label">' + strings.passwordStrength + '</div>' +
						'<div class="seculoco-password-strength-bar">' +
							'<div class="seculoco-password-strength-fill" data-strength="0"></div>' +
						'</div>' +
						'<div class="seculoco-password-strength-text">' + strings.enterPassword + '</div>' +
					'</div>' +
					'<div class="seculoco-password-requirements">' +
						'<h4>' + strings.passwordRecommendations + '</h4>' +
						'<ul>' +
							'<li data-requirement="length">' +
								'<span class="seculoco-requirement-icon">💡</span>' +
								strings.req_length.replace( '{min}', minLen ) +
							'</li>' +
							'<li data-requirement="uppercase">' +
								'<span class="seculoco-requirement-icon">💡</span>' +
								strings.req_uppercase +
							'</li>' +
							'<li data-requirement="lowercase">' +
								'<span class="seculoco-requirement-icon">💡</span>' +
								strings.req_lowercase +
							'</li>' +
							'<li data-requirement="number">' +
								'<span class="seculoco-requirement-icon">💡</span>' +
								strings.req_number +
							'</li>' +
							'<li data-requirement="special">' +
								'<span class="seculoco-requirement-icon">💡</span>' +
								strings.req_special +
							'</li>' +
						'</ul>' +
						'<div class="seculoco-alert seculoco-alert-info seculoco-margin-top-12">' +
							'<span class="seculoco-alert-icon">ℹ️</span>' +
							'<div class="seculoco-alert-content">' +
								'<div class="seculoco-alert-message">' + strings.step2_info + '</div>' +
							'</div>' +
						'</div>' +
					'</div>' +
					'<div class="seculoco-alert seculoco-alert-info">' +
						'<span class="seculoco-alert-icon">💡</span>' +
						'<div class="seculoco-alert-content">' +
							'<div class="seculoco-alert-title">' + strings.tip + '</div>' +
							'<div class="seculoco-alert-message">' + strings.step2_tip + '</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';
		},

		/**
		 * Render step 3: Password confirmation
		 *
		 * @return {string} Step HTML
		 */
		renderStep3: function () {
			var strings = seculocoWizard.strings;

			return '<div class="seculoco-wizard-step seculoco-hidden" data-step="3">' +
				'<div class="seculoco-wizard-step-content">' +
					'<div class="seculoco-wizard-icon">✅</div>' +
					'<h3>' + strings.step3_title + '</h3>' +
					'<div class="seculoco-form-group">' +
						'<label class="seculoco-form-label" for="seculoco-wizard-password-confirm">' +
							strings.step3_label +
						'</label>' +
						'<div class="seculoco-password-input-wrapper">' +
							'<input type="password" id="seculoco-wizard-password-confirm" class="seculoco-form-input" ' +
								'placeholder="' + strings.step3_placeholder + '" autocomplete="new-password" />' +
							'<button type="button" class="seculoco-password-toggle" data-target="seculoco-wizard-password-confirm">👁️</button>' +
						'</div>' +
						'<div class="seculoco-password-match-indicator seculoco-hidden"></div>' +
					'</div>' +
					'<div class="seculoco-alert seculoco-alert-warning">' +
						'<span class="seculoco-alert-icon">⚠️</span>' +
						'<div class="seculoco-alert-content">' +
							'<div class="seculoco-alert-title">' + strings.step3_warning_title + '</div>' +
							'<div class="seculoco-alert-message">' + strings.step3_warning + '</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';
		},

		/**
		 * Render step 4: Warning about password loss
		 *
		 * @return {string} Step HTML
		 */
		renderStep4: function () {
			var strings = seculocoWizard.strings;

			return '<div class="seculoco-wizard-step seculoco-hidden" data-step="4">' +
				'<div class="seculoco-wizard-step-content">' +
					'<div class="seculoco-wizard-icon">⚠️</div>' +
					'<h3>' + strings.step4_title + '</h3>' +
					'<div class="seculoco-alert seculoco-alert-danger">' +
						'<span class="seculoco-alert-icon">🚨</span>' +
						'<div class="seculoco-alert-content">' +
							'<div class="seculoco-alert-title">' + strings.step4_danger_title + '</div>' +
							'<div class="seculoco-alert-message">' +
								'<p><strong>' + strings.step4_danger_intro + '</strong></p>' +
								'<ul>' +
									'<li>' + strings.step4_point1 + '</li>' +
									'<li>' + strings.step4_point2 + '</li>' +
									'<li>' + strings.step4_point3 + '</li>' +
								'</ul>' +
							'</div>' +
						'</div>' +
					'</div>' +
					'<div class="seculoco-alert seculoco-alert-info">' +
						'<span class="seculoco-alert-icon">💡</span>' +
						'<div class="seculoco-alert-content">' +
							'<div class="seculoco-alert-title">' + strings.step4_best_practices + '</div>' +
							'<div class="seculoco-alert-message">' +
								'<ul>' +
									'<li>' + strings.step4_practice1 + '</li>' +
									'<li>' + strings.step4_practice2 + '</li>' +
									'<li>' + strings.step4_practice3 + '</li>' +
									'<li>' + strings.step4_practice4 + '</li>' +
								'</ul>' +
							'</div>' +
						'</div>' +
					'</div>' +
					'<div class="seculoco-warning-acknowledgment">' +
						'<label class="seculoco-checkbox-label">' +
							'<input type="checkbox" id="seculoco-wizard-warning-checkbox" />' +
							'<span>' + strings.step4_acknowledgment + '</span>' +
						'</label>' +
					'</div>' +
				'</div>' +
			'</div>';
		},

		/**
		 * Render step 5: Final confirmation
		 *
		 * @return {string} Step HTML
		 */
		renderStep5: function () {
			var strings = seculocoWizard.strings;

			return '<div class="seculoco-wizard-step seculoco-hidden" data-step="5">' +
				'<div class="seculoco-wizard-step-content">' +
					'<div class="seculoco-wizard-icon">🎉</div>' +
					'<h3>' + strings.step5_title + '</h3>' +
					'<div class="seculoco-setup-summary">' +
						'<p>' + strings.step5_intro + '</p>' +
						'<div class="seculoco-alert seculoco-alert-success">' +
							'<span class="seculoco-alert-icon">✅</span>' +
							'<div class="seculoco-alert-content">' +
								'<div class="seculoco-alert-title">' + strings.step5_what_happens + '</div>' +
								'<div class="seculoco-alert-message">' +
									'<ol>' +
										'<li>' + strings.step5_point1 + '</li>' +
										'<li>' + strings.step5_point2 + '</li>' +
										'<li>' + strings.step5_point3 + '</li>' +
										'<li>' + strings.step5_point4 + '</li>' +
									'</ol>' +
								'</div>' +
							'</div>' +
						'</div>' +
						'<div class="seculoco-setup-reminder">' +
							'<strong>' + strings.remember + '</strong> ' + strings.step5_reminder +
						'</div>' +
					'</div>' +
					'<div class="seculoco-setup-progress seculoco-hidden">' +
						'<div class="seculoco-progress-bar">' +
							'<div class="seculoco-progress-fill"></div>' +
						'</div>' +
						'<div class="seculoco-progress-text">' + strings.initializing + '</div>' +
					'</div>' +
				'</div>' +
			'</div>';
		},

		/**
		 * Show specific step
		 *
		 * @param {number} step - Step number to show
		 */
		showStep: function (step) {
			this.currentStep = step;

			// Hide all steps
			$( '.seculoco-wizard-step' ).addClass( 'seculoco-hidden' );

			// Show current step
			$( '.seculoco-wizard-step[data-step="' + step + '"]' ).removeClass( 'seculoco-hidden' );

			// Update progress bar via data attribute
			$( '.seculoco-wizard-progress-bar' ).attr( 'data-step', step );
			$( '.seculoco-wizard-current-step' ).text( step );

			// Update navigation buttons
			this.updateNavigation();
		},

		/**
		 * Update navigation button states
		 */
		updateNavigation: function () {
			var strings  = seculocoWizard.strings;
			var $backBtn = $( '.seculoco-wizard-back' );
			var $nextBtn = $( '.seculoco-wizard-next' );

			// Show/hide back button
			if (this.currentStep === 1) {
				$backBtn.addClass( 'seculoco-hidden' );
			} else {
				$backBtn.removeClass( 'seculoco-hidden' );
			}

			// Update next button text and state
			if (this.currentStep === this.totalSteps) {
				$nextBtn.text( strings.completeSetup );
			} else {
				$nextBtn.text( strings.next );
			}

			// Disable next button based on step validation
			this.validateCurrentStep();
		},

		/**
		 * Validate current step
		 */
		validateCurrentStep: function () {
			var $nextBtn = $( '.seculoco-wizard-next' );
			var isValid  = true;

			switch (this.currentStep) {
				case 1:
					isValid = true;
					break;
				case 2:
					isValid = this.isPasswordValid();
					break;
				case 3:
					isValid = this.doPasswordsMatch();
					break;
				case 4:
					isValid = $( '#seculoco-wizard-warning-checkbox' ).is( ':checked' );
					break;
				case 5:
					isValid = true;
					break;
			}

			$nextBtn.prop( 'disabled', ! isValid );
		},

		/**
		 * Go to next step
		 */
		nextStep: function () {
			var self = this;

			if (this.currentStep === this.totalSteps) {
				this.completeSetup();
			} else {
				if (this.currentStep === 2) {
					this.masterPassword = $( '#seculoco-wizard-password' ).val();
				}
				this.showStep( this.currentStep + 1 );
			}
		},

		/**
		 * Go to previous step
		 */
		previousStep: function () {
			if (this.currentStep > 1) {
				this.showStep( this.currentStep - 1 );
			}
		},

		/**
		 * Cancel wizard
		 */
		cancel: function () {
			var strings = seculocoWizard.strings;

			if (confirm( strings.cancelConfirm )) {
				$( '.seculoco-wizard-overlay' ).remove();
				this.masterPassword = null;
				this.rsaKeypair     = null;
			}
		},

		/**
		 * Validate password
		 */
		validatePassword: function () {
			var password     = $( '#seculoco-wizard-password' ).val();
			var requirements = this.checkPasswordRequirements( password );
			var strength     = this.calculatePasswordStrength( password, requirements );

			// Update requirement indicators
			var reqKeys = Object.keys( requirements );
			for (var i = 0; i < reqKeys.length; i++) {
				var req   = reqKeys[i];
				var $item = $( '[data-requirement="' + req + '"]' );
				var $icon = $item.find( '.seculoco-requirement-icon' );

				if (requirements[req]) {
					$icon.text( '✅' );
					$item.addClass( 'seculoco-requirement-met' );
				} else {
					$icon.text( '💡' );
					$item.removeClass( 'seculoco-requirement-met' );
				}
			}

			// Update strength bar
			var $strengthFill = $( '.seculoco-password-strength-fill' );
			var $strengthText = $( '.seculoco-password-strength-text' );

			$strengthFill.attr( 'data-strength', strength.level );
			$strengthText.text( strength.text );

			this.validateCurrentStep();
		},

		/**
		 * Validate password confirmation
		 */
		validatePasswordConfirm: function () {
			var strings    = seculocoWizard.strings;
			var password   = this.masterPassword || $( '#seculoco-wizard-password' ).val();
			var confirm    = $( '#seculoco-wizard-password-confirm' ).val();
			var $indicator = $( '.seculoco-password-match-indicator' );

			if ( ! confirm) {
				$indicator.addClass( 'seculoco-hidden' );
				this.validateCurrentStep();
				return;
			}

			if (password === confirm) {
				$indicator
					.removeClass( 'seculoco-hidden seculoco-password-mismatch' )
					.addClass( 'seculoco-password-match' )
					.text( strings.passwordsMatch );
			} else {
				$indicator
					.removeClass( 'seculoco-hidden seculoco-password-match' )
					.addClass( 'seculoco-password-mismatch' )
					.text( strings.passwordsMismatch );
			}

			this.validateCurrentStep();
		},

		/**
		 * Validate warning acceptance
		 */
		validateWarningAcceptance: function () {
			this.validateCurrentStep();
		},

		/**
		 * Check password requirements
		 *
		 * @param {string} password - Password to check
		 * @return {Object} Requirements check results
		 */
		checkPasswordRequirements: function (password) {
			return {
				length: password.length >= seculocoWizard.minPasswordLength,
				uppercase: /[A-Z]/.test( password ),
				lowercase: /[a-z]/.test( password ),
				number: /[0-9]/.test( password ),
				special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test( password )
			};
		},

		/**
		 * Calculate password strength
		 *
		 * @param {string} password - Password to check
		 * @param {Object} requirements - Requirements check results
		 * @return {Object} Strength information
		 */
		calculatePasswordStrength: function (password, requirements) {
			var strings = seculocoWizard.strings;

			if ( ! password) {
				return { level: 0, percentage: 0, text: strings.enterPassword };
			}

			var metRequirements = 0;
			var reqKeys         = Object.keys( requirements );
			for (var i = 0; i < reqKeys.length; i++) {
				if (requirements[reqKeys[i]]) {
					metRequirements++;
				}
			}

			var lengthBonus = Math.min( password.length - seculocoWizard.minPasswordLength, 8 ) * 2;
			var score       = (metRequirements * 20) + lengthBonus;

			if (score < 40) {
				return { level: 1, percentage: score, text: strings.strengthWeak };
			} else if (score < 70) {
				return { level: 2, percentage: score, text: strings.strengthFair };
			} else if (score < 90) {
				return { level: 3, percentage: score, text: strings.strengthGood };
			} else {
				return { level: 4, percentage: 100, text: strings.strengthStrong };
			}
		},

		/**
		 * Check if password is valid
		 *
		 * @return {boolean} True if valid
		 */
		isPasswordValid: function () {
			var password     = $( '#seculoco-wizard-password' ).val();
			var requirements = this.checkPasswordRequirements( password );
			var strength     = this.calculatePasswordStrength( password, requirements );
			return strength.percentage >= 40;
		},

		/**
		 * Check if passwords match
		 *
		 * @return {boolean} True if they match
		 */
		doPasswordsMatch: function () {
			var password = this.masterPassword || $( '#seculoco-wizard-password' ).val();
			var confirm  = $( '#seculoco-wizard-password-confirm' ).val();
			return password && confirm && password === confirm;
		},

		/**
		 * Complete setup
		 */
		completeSetup: function () {
			var self               = this;
			var strings            = seculocoWizard.strings;
			var $progressContainer = $( '.seculoco-setup-progress' );
			var $progressText      = $( '.seculoco-progress-text' );
			var $progressFill      = $( '.seculoco-progress-fill' );

			// Show progress
			$progressContainer.removeClass( 'seculoco-hidden' );
			$( '.seculoco-setup-summary, .seculoco-setup-reminder' ).addClass( 'seculoco-hidden' );
			$( '.seculoco-wizard-footer button' ).prop( 'disabled', true );

			// Step 1: Derive wrapping key
			$progressText.text( strings.deriving_key );
			$progressFill.attr( 'data-progress', '20' );

			var salt = crypto.getRandomValues( new Uint8Array( 32 ) );

			this.derivePasswordWrappingKey( this.masterPassword, salt )
				.then(
					function (wrappingKey) {
						// Step 2: Generate RSA keypair
						$progressText.text( strings.generating_rsa );
						$progressFill.attr( 'data-progress', '40' );

						return crypto.subtle.generateKey(
							{
								name: 'RSA-OAEP',
								modulusLength: 4096,
								publicExponent: new Uint8Array( [1, 0, 1] ),
								hash: 'SHA-256'
							},
							true,
							['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']
						).then(
							function (keypair) {
								self.rsaKeypair = keypair;
								return wrappingKey;
							}
						);
					}
				)
				.then(
					function (wrappingKey) {
						// Step 3: Wrap private key
						$progressText.text( strings.wrapping_key );
						$progressFill.attr( 'data-progress', '60' );

						return crypto.subtle.exportKey( 'pkcs8', self.rsaKeypair.privateKey )
						.then(
							function (privateKeyBuffer) {
								var iv = crypto.getRandomValues( new Uint8Array( 12 ) );

								return crypto.subtle.encrypt(
									{ name: 'AES-GCM', iv: iv, tagLength: 128 },
									wrappingKey,
									privateKeyBuffer
								).then(
									function (encrypted) {
										var encryptedArray = new Uint8Array( encrypted );
										var ciphertext     = encryptedArray.slice( 0, -16 );
										var tag            = encryptedArray.slice( -16 );

										return crypto.subtle.exportKey( 'jwk', self.rsaKeypair.publicKey )
										.then(
											function (publicKeyJWK) {
												return { ciphertext: ciphertext, tag: tag, iv: iv, publicKeyJWK: publicKeyJWK };
											}
										);
									}
								);
							}
						);
					}
				)
				.then(
					function (keyData) {
						// Step 4: Save to server
						$progressText.text( strings.saving_to_server );
						$progressFill.attr( 'data-progress', '80' );

						var saveData = {
							action: 'seculoco_setup_master_password',
							nonce: seculocoWizard.nonce,
							wrapped_private_key: SecureLoginUtils.bufferToBase64( keyData.ciphertext.buffer ),
							public_key_jwk: JSON.stringify( keyData.publicKeyJWK ),
							master_password_salt: SecureLoginUtils.bufferToBase64( salt ),
							key_wrapping_iv: SecureLoginUtils.bufferToBase64( keyData.iv ),
							key_wrapping_tag: SecureLoginUtils.bufferToBase64( keyData.tag.buffer )
						};

						return $.ajax(
							{
								url: seculocoWizard.ajaxurl,
								type: 'POST',
								data: saveData
							}
						);
					}
				)
				.then(
					function (response) {
						if ( ! response.success) {
							throw new Error( response.data || strings.setupFailed );
						}

						// Step 5: Complete
						$progressText.text( strings.setupComplete );
						$progressFill.attr( 'data-progress', '100' );

						setTimeout(
							function () {
								self.showSuccess( response.data.redirect );
							},
							1000
						);
					}
				)
				.catch(
					function (error) {
						console.error( 'Setup error:', error );
						alert( strings.setupFailed + ' ' + error.message );
						$( '.seculoco-wizard-footer button' ).prop( 'disabled', false );
						$progressContainer.addClass( 'seculoco-hidden' );
						$( '.seculoco-setup-summary, .seculoco-setup-reminder' ).removeClass( 'seculoco-hidden' );
					}
				);
		},

		/**
		 * Show success message and redirect
		 *
		 * @param {string} redirectUrl - URL to redirect to
		 */
		showSuccess: function (redirectUrl) {
			var strings = seculocoWizard.strings;

			$( '.seculoco-wizard-body' ).html(
				'<div class="seculoco-wizard-success">' +
					'<div class="seculoco-wizard-icon">✅</div>' +
					'<h3>' + strings.setupCompleteTitle + '</h3>' +
					'<p>' + strings.setupCompleteMessage + '</p>' +
					'<p>' + strings.redirecting + '</p>' +
				'</div>'
			);

			setTimeout(
				function () {
					window.location.href = redirectUrl;
				},
				2000
			);
		},

		/**
		 * Derive password wrapping key using PBKDF2
		 *
		 * @param {string} password - Master password
		 * @param {Uint8Array} salt - Salt for key derivation
		 * @return {Promise<CryptoKey>} Derived wrapping key
		 */
		derivePasswordWrappingKey: function (password, salt) {
			var encoder        = new TextEncoder();
			var passwordBuffer = encoder.encode( password );

			return crypto.subtle.importKey(
				'raw',
				passwordBuffer,
				'PBKDF2',
				false,
				['deriveKey']
			).then(
				function (keyMaterial) {
					return crypto.subtle.deriveKey(
						{
							name: 'PBKDF2',
							salt: salt,
							iterations: 600000,
							hash: 'SHA-256'
						},
						keyMaterial,
						{ name: 'AES-GCM', length: 256 },
						false,
						['encrypt', 'decrypt']
					);
				}
			);
		}
	};

	// Password toggle handler
	$( document ).on(
		'click',
		'.seculoco-password-toggle',
		function (e) {
			e.preventDefault();
			var targetId    = $( this ).data( 'target' );
			var $input      = $( '#' + targetId );
			var currentType = $input.attr( 'type' );

			if (currentType === 'password') {
				$input.attr( 'type', 'text' );
				$( this ).text( '🙈' );
			} else {
				$input.attr( 'type', 'password' );
				$( this ).text( '👁️' );
			}
		}
	);

	// Initialize on document ready
	$( document ).ready(
		function () {
			MasterPasswordWizard.init();
		}
	);

})( jQuery );

// ====================================================================
// MASTER PASSWORD RESET
// ====================================================================
jQuery( document ).ready(
	function ($) {
		'use strict';

		// Only execute if reset data is available
		if (typeof secureLoginMasterPasswordData === 'undefined') {
			return;
		}

		var ajaxUrl          = secureLoginMasterPasswordData.ajaxUrl;
		var nonce            = secureLoginMasterPasswordData.nonce;
		var hasEncryptedData = secureLoginMasterPasswordData.hasEncryptedData;
		var strings          = secureLoginMasterPasswordData.strings;

		/**
		 * Show warning modal before reset
		 *
		 * @param {Function} callback - Function to call on confirmation
		 */
		function showResetWarningModal(callback) {
			var modalHtml = '<div id="seculoco-reset-warning-modal" class="seculoco-modal-overlay">' +
			'<div class="seculoco-modal-container seculoco-modal-warning">' +
				'<div class="seculoco-modal-header">' +
					'<h2>⚠️ ' + (hasEncryptedData ? strings.warningDataLoss : strings.resetMasterPassword) + '</h2>' +
				'</div>' +
				'<div class="seculoco-modal-content">';

			if (hasEncryptedData) {
				modalHtml += '<div class="seculoco-alert seculoco-alert-danger" style="margin-bottom: 20px;">' +
					'<div class="seculoco-alert-title">⚠️ ' + strings.criticalWarning + '</div>' +
					'<div class="seculoco-alert-message">' +
						'<p><strong>' + strings.actionDestroy + '</strong></p>' +
						'<ul style="margin: 10px 0; padding-left: 20px;">' +
							'<li>' + strings.lostForever + '</li>' +
							'<li>' + strings.needRecollect + '</li>' +
							'<li>' + strings.cannotUndo + '</li>' +
							'<li>' + strings.noRecovery + '</li>' +
						'</ul>' +
					'</div>' +
				'</div>' +
				'<div class="seculoco-field-group">' +
					'<label>' +
						'<input type="checkbox" id="seculoco-confirm-reset" />' +
						'<strong>' + strings.understandLoss + '</strong>' +
					'</label>' +
				'</div>';
			} else {
				modalHtml += '<p>' + strings.aboutToReset + '</p>' +
				'<p>' + strings.safeNoData + '</p>';
			}

			modalHtml += '</div>' +
			'<div class="seculoco-modal-footer">';

			if (hasEncryptedData) {
				modalHtml += '<button type="button" class="button button-secondary" id="seculoco-cancel-reset">' + strings.cancel + '</button>' +
				'<button type="button" class="button button-danger" id="seculoco-confirm-reset-btn" disabled>' + strings.understandReset + '</button>';
			} else {
				modalHtml += '<button type="button" class="button button-secondary" id="seculoco-cancel-reset">' + strings.cancel + '</button>' +
				'<button type="button" class="button button-primary" id="seculoco-confirm-reset-btn">' + strings.resetMasterPassword + '</button>';
			}

			modalHtml += '</div></div></div>';

			$( 'body' ).append( modalHtml );

			var $modal      = $( '#seculoco-reset-warning-modal' );
			var $confirmBtn = $( '#seculoco-confirm-reset-btn' );
			var $checkbox   = $( '#seculoco-confirm-reset' );

			// Enable confirm button when checkbox is checked
			if (hasEncryptedData) {
				$checkbox.on(
					'change',
					function () {
						$confirmBtn.prop( 'disabled', ! $( this ).is( ':checked' ) );
					}
				);
			}

			// Handle cancel
			$( '#seculoco-cancel-reset' ).on(
				'click',
				function () {
					$modal.remove();
				}
			);

			// Handle confirm
			$confirmBtn.on(
				'click',
				function () {
					$modal.remove();
					callback();
				}
			);

			// Escape key closes modal
			$( document ).on(
				'keydown.seculoco-reset-modal',
				function (e) {
					if (e.which === 27) {
						$( document ).off( 'keydown.seculoco-reset-modal' );
						$modal.remove();
					}
				}
			);
		}

		/**
		 * Handle master password reset
		 */
		$( '#reset-master-password-btn' ).on(
			'click',
			function (e) {
				e.preventDefault();

				var $button = $( this );

				showResetWarningModal(
					function () {
						$button.prop( 'disabled', true ).text( strings.resetting );

						$.ajax(
							{
								url: ajaxUrl,
								type: 'POST',
								data: {
									action: 'seculoco_reset_master_password',
									nonce: nonce
								},
								success: function (response) {
									if (response.success) {
										alert( strings.resetSuccess );
										setTimeout(
											function () {
												window.location.reload();
											},
											1500
										);
									} else {
										alert( strings.resetFailed + ' ' + (response.data || strings.unknownError) );
										$button.prop( 'disabled', false ).text( strings.resetButton );
									}
								},
								error: function (xhr, status, error) {
									console.error( 'Reset error:', error );
									alert( strings.networkError );
									$button.prop( 'disabled', false ).text( strings.resetButton );
								}
							}
						);
					}
				);
			}
		);
	}
);

// ====================================================================
// CORE ADMIN FUNCTIONALITY
// ====================================================================
jQuery( document ).ready(
	function ($) {
		'use strict';

		// Escape HTML to prevent XSS
		function escapeHtml(text) {
			return SecureLoginUtils.escapeHtml( text );
		}

		// Escape attribute values
		function escapeAttr(text) {
			return SecureLoginUtils.escapeAttr( text );
		}

		// Fallback copy function
		function copyToClipboardFallback(text) {
			SecureLoginUtils.copyToClipboardFallback( text );
		}

		// Show copy button feedback
		function showCopyButtonFeedback(button) {
			SecureLoginUtils.showCopyButtonFeedback( button );
		}

		// Event delegation for dynamically created copy buttons
		$( document ).on(
			'click',
			'.copy-field-btn',
			function () {
				var button = this;
				var data   = $( this ).attr( 'data-copy-value' );

				// Try modern clipboard API first
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText( data ).then(
						function () {
							showCopyButtonFeedback( button );
						}
					).catch(
						function () {
							copyToClipboardFallback( data );
							showCopyButtonFeedback( button );
						}
					);
				} else {
					copyToClipboardFallback( data );
					showCopyButtonFeedback( button );
				}
			}
		);

		// Edit functionality
		$( '.edit-btn' ).on(
			'click',
			function () {
				var button = $( this );
				var id     = button.data( 'id' );
				var row    = button.closest( 'tr' );

				// Hide edit button, show save/cancel buttons
				button.hide();
				row.find( '.save-btn, .cancel-btn' ).show();

				// Make fields editable
				row.find( '.editable-field' ).each(
					function () {
						var field        = $( this );
						var currentValue = field.text().trim();
						var input        = $( '<input type="text" class="edit-input" value="' + escapeHtml( currentValue ) + '">' );
						field.addClass( 'editing' ).html( input );
					}
				);
			}
		);

		// Cancel edit
		$( '.cancel-btn' ).on(
			'click',
			function () {
				var button = $( this );
				var id     = button.data( 'id' );
				var row    = button.closest( 'tr' );

				// Restore original values and hide save/cancel buttons
				row.find( '.editable-field' ).each(
					function () {
						var field         = $( this );
						var originalValue = field.find( '.edit-input' ).val();
						field.removeClass( 'editing' ).text( originalValue );
					}
				);

				row.find( '.save-btn, .cancel-btn' ).hide();
				row.find( '.edit-btn' ).show();
			}
		);

		// Save edit
		$( '.save-btn' ).on(
			'click',
			function () {
				var button = $( this );
				var id     = button.data( 'id' );
				var row    = button.closest( 'tr' );

				// Collect new values
				var newData = {};
				row.find( '.editable-field' ).each(
					function () {
						var field          = $( this );
						var fieldName      = field.data( 'field' );
						var newValue       = field.find( '.edit-input' ).val().trim();
						newData[fieldName] = newValue;
					}
				);

				button.prop( 'disabled', true );

				$.ajax(
					{
						url: seculocoAjax.ajaxurl,
						type: 'POST',
						data: {
							action: 'seculoco_update_metadata',
							update_id: id,
							metadata: newData,
							nonce: seculocoAjax.nonce
						},
						success: function (response) {
							if (response.success) {
								// Update display with new values
								row.find( '.editable-field' ).each(
									function () {
										var field     = $( this );
										var fieldName = field.data( 'field' );
										field.removeClass( 'editing' ).text( newData[fieldName] );
									}
								);

								row.find( '.save-btn, .cancel-btn' ).hide();
								row.find( '.edit-btn' ).show();
							} else {
								alert( seculocoAjax.strings.save_failed + (response.data || seculocoAjax.strings.unknown_error) );
							}
							button.prop( 'disabled', false );
						},
						error: function () {
							alert( seculocoAjax.strings.network_error_save );
							button.prop( 'disabled', false );
						}
					}
				);
			}
		);

		// Hide decrypted data
		$( '.hide-decrypted' ).on(
			'click',
			function () {
				var button       = $( this );
				var id           = button.data( 'id' );
				var decryptedRow = $( '#decrypted-row-' + id );
				var decryptBtn   = $( '.decrypt-btn-v2[data-id="' + id + '"]' );

				decryptedRow.removeData( 'decrypted-data' );
				decryptedRow.hide();
				decryptBtn.prop( 'disabled', false ).removeClass( 'button-success' );
				decryptBtn.html( '<span class="dashicons dashicons-unlock"></span>' );
			}
		);

		// Extend functionality
		$( '.extend-btn' ).on(
			'click',
			function () {
				var button = $( this );
				var id     = button.data( 'id' );

				if ( ! confirm( seculocoAjax.strings.confirm_extend_retention )) {
					return;
				}

				button.prop( 'disabled', true ).html( '<span class="dashicons dashicons-update-alt spin"></span>' );

				$.ajax(
					{
						url: seculocoAjax.ajaxurl,
						type: 'POST',
						data: {
							action: 'seculoco_extend_entry',
							extend_id: id,
							nonce: seculocoAjax.nonce
						},
						success: function (response) {
							if (response.success) {
								alert( response.data.message || seculocoAjax.strings.retention_extended );
								location.reload();
							} else {
								alert( 'Extend failed: ' + (response.data || 'Unknown error') );
							}
							button.prop( 'disabled', false ).html( '<span class="dashicons dashicons-update"></span>' );
						},
						error: function () {
							alert( 'Network error occurred during extension.' );
							button.prop( 'disabled', false ).html( '<span class="dashicons dashicons-update"></span>' );
						}
					}
				);
			}
		);

		// Delete functionality
		$( '.delete-btn' ).on(
			'click',
			function () {
				var button = $( this );
				var id     = button.data( 'id' );

				if ( ! confirm( 'Are you sure you want to delete this login data?' )) {
					return;
				}

				button.prop( 'disabled', true ).html( '<span class="dashicons dashicons-trash spin"></span>' );

				$.ajax(
					{
						url: seculocoAjax.ajaxurl,
						type: 'POST',
						data: {
							action: 'seculoco_delete_entry',
							delete_id: id,
							nonce: seculocoAjax.nonce
						},
						success: function (response) {
							if (response.success) {
								location.reload();
							} else {
								alert( 'Delete failed: ' + (response.data || 'Unknown error') );
								button.prop( 'disabled', false ).html( '<span class="dashicons dashicons-trash"></span>' );
							}
						},
						error: function () {
							alert( 'Network error occurred during deletion.' );
							button.prop( 'disabled', false ).text( 'Delete' );
						}
					}
				);
			}
		);

		// Handle fix passkey flag button
		$( document ).on(
			'click',
			'#fix-passkey-flag-btn',
			function () {
				var button     = $( this );
				var resultSpan = $( '#fix-passkey-flag-result' );

				button.prop( 'disabled', true ).html( '<span class="dashicons dashicons-admin-tools spin"></span>' );
				resultSpan.html( '<span style="color: #666;">Processing...</span>' );

				$.ajax(
					{
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'seculoco_fix_passkey_flag',
							nonce: seculocoAjax.nonce
						},
						success: function (response) {
							if (response.success) {
								resultSpan.html( '<span style="color: #4CAF50;">✅ ' + response.data + '</span>' );
								setTimeout(
									function () {
										button.closest( '.notice' ).fadeOut();
									},
									3000
								);
							} else {
								resultSpan.html( '<span style="color: #f44336;">❌ Error: ' + response.data + '</span>' );
							}
						},
						error: function () {
							resultSpan.html( '<span style="color: #f44336;">❌ Network error occurred</span>' );
						},
						complete: function () {
							button.prop( 'disabled', false ).html( '<span class="dashicons dashicons-admin-tools"></span> Fix' );
						}
					}
				);
			}
		);
	}
);

// ====================================================================
// GLOBAL FUNCTIONS (PASSWORD MANAGER EXPORT)
// ====================================================================
function copyLoginData(button) {
	var loginData = button.getAttribute( 'data-login' );
	navigator.clipboard.writeText( loginData ).then(
		function () {
			var originalText   = button.textContent;
			button.textContent = 'Copied!';
			setTimeout(
				function () {
					button.textContent = originalText;
				},
				2000
			);
		}
	).catch(
		function () {
			alert( 'Failed to copy to clipboard' );
		}
	);
}
