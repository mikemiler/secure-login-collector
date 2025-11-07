/**
 * Password Setup UI for Secure Login Collector
 * Handles password-based encryption setup and reset flows
 */

(function ($) {
	'use strict';

	/**
	 * Password Setup Manager
	 */
	const PasswordSetup = {
		/**
		 * Initialize password setup functionality
		 */
		init: function () {
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function () {

			// Setup password button
			$(document).on('click', '.seculoco-password-setup-btn', function(e) {
				PasswordSetup.openSetupModal.call(PasswordSetup);
			});

			// Reset password button
			$(document).on('click', '.seculoco-password-reset-btn', function(e) {
				PasswordSetup.openResetModal.call(PasswordSetup);
			});

			// Close modal on backdrop click
			$(document).on('click', '.seculoco-password-modal-backdrop', this.closeModal.bind(this));

			// Close modal on cancel button
			$(document).on('click', '.seculoco-password-modal-cancel', this.closeModal.bind(this));

			// Toggle password visibility
			$(document).on('click', '.seculoco-password-toggle', this.togglePasswordVisibility.bind(this));

			// Setup form submission
			$(document).on('click', '.seculoco-password-setup-submit', this.handleSetupSubmit.bind(this));

			// Reset form submission
			$(document).on('click', '.seculoco-password-reset-submit', this.handleResetSubmit.bind(this));

			// Password strength only for password field
			$(document).on('keyup', '.seculoco-password-field', this.updatePasswordStrength.bind(this));

			// Password match indicator for both fields
			$(document).on('keyup', '.seculoco-password-field, .seculoco-password-confirm-field', this.updatePasswordMatch.bind(this));
		},

		/**
		 * Open setup password modal
		 */
		openSetupModal: function () {
			const i18n = secuLocoPasswordSetup.i18n;

			const modalHTML = `
				<div class="seculoco-password-modal-backdrop"></div>
				<div class="seculoco-password-modal">
					<div class="seculoco-password-modal-header">
						<h2>${i18n.setupPassword}</h2>
					</div>
					<div class="seculoco-password-modal-body">
						<div class="seculoco-alert seculoco-alert-warning" style="margin-bottom: 20px;">
							<span class="seculoco-alert-icon dashicons dashicons-warning"></span>
							<div class="seculoco-alert-content">
								<strong style="display: block; margin-bottom: 4px;">Important: Store this password safely!</strong>
								<span style="font-size: 13px;">Losing this password means your encrypted login data cannot be decrypted. Consider using a password manager.</span>
							</div>
						</div>
						<div class="seculoco-password-form-group">
							<label class="seculoco-password-label">${i18n.password}</label>
							<div class="seculoco-password-input-wrapper">
								<input type="password" class="seculoco-password-input seculoco-password-field" placeholder="${i18n.password}" />
								<button type="button" class="seculoco-password-toggle" data-target="password">
									<span class="dashicons dashicons-visibility"></span>
								</button>
							</div>
							<div class="seculoco-password-strength-meter">
								<div class="seculoco-password-strength-bar"></div>
							</div>
							<div class="seculoco-password-strength-text"></div>
						</div>
						<div class="seculoco-password-form-group">
							<label class="seculoco-password-label">${i18n.confirmPassword}</label>
							<div class="seculoco-password-input-wrapper">
								<input type="password" class="seculoco-password-input seculoco-password-confirm-field" placeholder="${i18n.confirmPassword}" />
								<button type="button" class="seculoco-password-toggle" data-target="confirm">
									<span class="dashicons dashicons-visibility"></span>
								</button>
							</div>
							<div class="seculoco-password-match-indicator"></div>
						</div>
						<div class="seculoco-password-error"></div>
					</div>
					<div class="seculoco-password-modal-footer">
						<button type="button" class="button button-secondary seculoco-password-modal-cancel">${i18n.cancel}</button>
						<button type="button" class="button button-primary seculoco-password-setup-submit">${i18n.setupButton}</button>
					</div>
				</div>
			`;

			$('body').append(modalHTML);
		},

		/**
		 * Open reset password modal
		 */
		openResetModal: function () {
			const i18n = secuLocoPasswordSetup.i18n;

			const modalHTML = `
				<div class="seculoco-password-modal-backdrop"></div>
				<div class="seculoco-password-modal">
					<div class="seculoco-password-modal-header">
						<h2>${i18n.resetPassword}</h2>
					</div>
					<div class="seculoco-password-modal-body">
						<div class="seculoco-alert seculoco-alert-danger">
							<span class="seculoco-alert-icon dashicons dashicons-warning"></span>
							<div class="seculoco-alert-content">
								<div class="seculoco-alert-title">${i18n.resetWarningTitle}</div>
								<div class="seculoco-alert-message">${i18n.resetWarningMessage}</div>
							</div>
						</div>
						<div class="seculoco-password-form-group">
							<label class="seculoco-password-label">${i18n.typeConfirmToReset}</label>
							<input type="text" class="seculoco-password-input seculoco-reset-confirm-input" placeholder="RESET" />
						</div>
						<div class="seculoco-password-error"></div>
					</div>
					<div class="seculoco-password-modal-footer">
						<button type="button" class="button button-secondary seculoco-password-modal-cancel">${i18n.cancel}</button>
						<button type="button" class="button button-danger seculoco-password-reset-submit">${i18n.resetButton}</button>
					</div>
				</div>
			`;

			$('body').append(modalHTML);
		},

		/**
		 * Close modal
		 */
		closeModal: function () {
			$('.seculoco-password-modal-backdrop').remove();
			$('.seculoco-password-modal').remove();
		},

		/**
		 * Toggle password visibility
		 */
		togglePasswordVisibility: function (e) {
			const $btn = $(e.currentTarget);
			const target = $btn.data('target');
			const $input = target === 'password' ? $('.seculoco-password-field') : $('.seculoco-password-confirm-field');
			const $icon = $btn.find('.dashicons');

			if ($input.attr('type') === 'password') {
				$input.attr('type', 'text');
				$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
			} else {
				$input.attr('type', 'password');
				$icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
			}
		},

		/**
		 * Update password strength meter
		 */
		updatePasswordStrength: function (e) {
			const password = $(e.currentTarget).val();
			const i18n = secuLocoPasswordSetup.i18n;

			if (!password) {
				$('.seculoco-password-strength-bar').removeClass('seculoco-strength-weak seculoco-strength-fair seculoco-strength-good seculoco-strength-strong');
				$('.seculoco-password-strength-text').text('');
				return;
			}

			// Use zxcvbn if available
			if (typeof zxcvbn !== 'undefined') {
				const result = zxcvbn(password);
				const score = result.score; // 0-4

				$('.seculoco-password-strength-bar')
					.removeClass('seculoco-strength-weak seculoco-strength-fair seculoco-strength-good seculoco-strength-strong')
					.addClass('seculoco-strength-' + this.getStrengthClass(score));

				$('.seculoco-password-strength-text')
					.removeClass('seculoco-strength-weak seculoco-strength-fair seculoco-strength-good seculoco-strength-strong')
					.addClass('seculoco-strength-' + this.getStrengthClass(score))
					.text(this.getStrengthText(score, i18n));
			}
		},

		/**
		 * Update password match indicator
		 */
		updatePasswordMatch: function () {
			const password = $('.seculoco-password-field').val();
			const confirm = $('.seculoco-password-confirm-field').val();
			const $indicator = $('.seculoco-password-match-indicator');

			// Don't show anything if confirm is empty
			if (!confirm) {
				$indicator.html('').removeClass('seculoco-match-success seculoco-match-error');
				return;
			}

			// Check if passwords match
			if (password === confirm) {
				$indicator
					.html('<span style="color: #00a32a;">✓ Passwords match</span>')
					.removeClass('seculoco-match-error')
					.addClass('seculoco-match-success');
			} else {
				$indicator
					.html('<span style="color: #d63638;">✗ Passwords do not match</span>')
					.removeClass('seculoco-match-success')
					.addClass('seculoco-match-error');
			}
		},

		/**
		 * Get strength class from zxcvbn score
		 */
		getStrengthClass: function (score) {
			const classes = ['weak', 'weak', 'fair', 'good', 'strong'];
			return classes[score];
		},

		/**
		 * Get strength text from zxcvbn score
		 */
		getStrengthText: function (score, i18n) {
			const texts = [
				i18n.passwordStrengthWeak,
				i18n.passwordStrengthWeak,
				i18n.passwordStrengthFair,
				i18n.passwordStrengthGood,
				i18n.passwordStrengthStrong
			];
			return texts[score];
		},

		/**
		 * Handle setup form submission
		 */
		handleSetupSubmit: function () {
			const password = $('.seculoco-password-field').val();
			const confirmPassword = $('.seculoco-password-confirm-field').val();
			const i18n = secuLocoPasswordSetup.i18n;

			// Clear previous errors
			$('.seculoco-password-error').text('');

			// Validate password length
			if (password.length < 8) {
				this.showError(i18n.passwordTooShort);
				return;
			}

			// Validate password match
			if (password !== confirmPassword) {
				this.showError(i18n.passwordMismatch);
				return;
			}

			// Disable submit button
			const $submitBtn = $('.seculoco-password-setup-submit');
			$submitBtn.prop('disabled', true).text('Setting up...');

			// Send AJAX request
			$.ajax({
				url: secuLocoPasswordSetup.ajaxUrl,
				type: 'POST',
				data: {
					action: 'seculoco_setup_password_encryption',
					nonce: secuLocoPasswordSetup.nonce,
					password: password
				},

				success: (response) => {
					if (response.success) {
						this.closeModal();
						this.showNotification(i18n.setupSuccess, 'success');
						// Reload page after 1 second
						setTimeout(() => {
							location.reload();
						}, 1000);
					} else {
						this.showError(response.data.message || i18n.setupFailed);
						$submitBtn.prop('disabled', false).text(i18n.setupButton);
					}
				},
				error: () => {
					this.showError(i18n.setupFailed);
					$submitBtn.prop('disabled', false).text(i18n.setupButton);
				}
			});
		},

		/**
		 * Handle reset form submission
		 */
		handleResetSubmit: function () {
			const confirmText = $('.seculoco-reset-confirm-input').val();
			const i18n = secuLocoPasswordSetup.i18n;

			// Clear previous errors
			$('.seculoco-password-error').text('');

			// Validate confirmation
			if (confirmText !== 'RESET') {
				this.showError(i18n.resetConfirmRequired);
				return;
			}

			// Disable submit button
			const $submitBtn = $('.seculoco-password-reset-submit');
			$submitBtn.prop('disabled', true).text('Resetting...');

			// Send AJAX request
			$.ajax({
				url: secuLocoPasswordSetup.ajaxUrl,
				type: 'POST',
				data: {
					action: 'seculoco_reset_password_encryption',
					nonce: secuLocoPasswordSetup.nonce
				},
				success: (response) => {
					if (response.success) {
						this.closeModal();
						this.showNotification(i18n.resetSuccess, 'success');
						// Reload page after 1 second
						setTimeout(() => {
							location.reload();
						}, 1000);
					} else {
						this.showError(response.data.message || i18n.resetFailed);
						$submitBtn.prop('disabled', false).text(i18n.resetButton);
					}
				},
				error: () => {
					this.showError(i18n.resetFailed);
					$submitBtn.prop('disabled', false).text(i18n.resetButton);
				}
			});
		},

		/**
		 * Show error message
		 */
		showError: function (message) {
			$('.seculoco-password-error').addClass('seculoco-password-error-visible').text(message);
		},

		/**
		 * Show notification
		 */
		showNotification: function (message, type) {
			const typeClass = type === 'success' ? 'seculoco-notification-success' : 'seculoco-notification-error';
			const notification = $(`
				<div class="seculoco-notification ${typeClass}">
					${message}
				</div>
			`);

			$('body').append(notification);

			// Auto-remove after 3 seconds
			setTimeout(() => {
				notification.fadeOut(300, function () {
					$(this).remove();
				});
			}, 3000);
		}
	};

	// Initialize on document ready
	$(document).ready(function () {
		PasswordSetup.init();
	});

})(jQuery);
