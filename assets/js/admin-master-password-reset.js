/**
 * Master Password Reset Handler
 * Handles the reset of master password with critical warnings
 *
 * @package SecureLoginCollector
 */

/* global jQuery, secureLoginMasterPasswordData */

jQuery( document ).ready(
	function ($) {
		'use strict';

		// Get localized data from PHP
		var ajaxUrl          = secureLoginMasterPasswordData.ajaxUrl;
		var nonce            = secureLoginMasterPasswordData.nonce;
		var hasEncryptedData = secureLoginMasterPasswordData.hasEncryptedData;
		var strings          = secureLoginMasterPasswordData.strings;

		/**
		 * Show warning modal before reset
		 */
		function showResetWarningModal(callback) {
			// Create modal HTML
			var modalHtml = `
				<div id="seculoco-reset-warning-modal" class="seculoco-modal-overlay">
					<div class="seculoco-modal-container seculoco-modal-warning">
						<div class="seculoco-modal-header">
							<h2>⚠️ ${hasEncryptedData ? 'Warning: Data Loss!' : 'Reset Master Password'}</h2>
						</div>
						<div class="seculoco-modal-content">
							${hasEncryptedData ? `
								<div class="seculoco-alert seculoco-alert-danger" style="margin-bottom: 20px;">
									<div class="seculoco-alert-title">⚠️ CRITICAL WARNING</div>
									<div class="seculoco-alert-message">
										<p><strong>This action will permanently destroy ALL encrypted login data!</strong></p>
										<ul style="margin: 10px 0; padding-left: 20px;">
											<li>All encrypted passwords will be lost forever</li>
											<li>You will need to re-collect login credentials</li>
											<li>This action CANNOT be undone</li>
											<li>There is NO recovery method</li>
										</ul>
									</div>
								</div>
								<div class="seculoco-field-group">
									<label>
										<input type="checkbox" id="seculoco-confirm-reset" />
										<strong>I understand that all encrypted data will be permanently lost</strong>
									</label>
								</div>
							` : `
								<p>You are about to reset your master password.</p>
								<p>Since you have no encrypted data stored, this is safe to do. You will need to set up a new master password afterward.</p>
							`}
						</div>
						<div class="seculoco-modal-footer">
							${hasEncryptedData ? `
								<button type="button" class="button button-secondary" id="seculoco-cancel-reset">Cancel</button>
								<button type="button" class="button button-danger" id="seculoco-confirm-reset-btn" disabled>I Understand, Reset Anyway</button>
							` : `
								<button type="button" class="button button-secondary" id="seculoco-cancel-reset">Cancel</button>
								<button type="button" class="button button-primary" id="seculoco-confirm-reset-btn">Reset Master Password</button>
							`}
						</div>
					</div>
				</div>
			`;

			$('body').append(modalHtml);

			var $modal = $('#seculoco-reset-warning-modal');
			var $confirmBtn = $('#seculoco-confirm-reset-btn');
			var $checkbox = $('#seculoco-confirm-reset');

			// Enable confirm button when checkbox is checked (only if there's encrypted data)
			if (hasEncryptedData) {
				$checkbox.on('change', function() {
					$confirmBtn.prop('disabled', !$(this).is(':checked'));
				});
			}

			// Handle cancel
			$('#seculoco-cancel-reset').on('click', function() {
				$modal.remove();
			});

			// Handle confirm
			$confirmBtn.on('click', function() {
				$modal.remove();
				callback();
			});

			// Escape key closes modal
			$(document).on('keydown.seculoco-reset-modal', function(e) {
				if (e.which === 27) {
					$(document).off('keydown.seculoco-reset-modal');
					$modal.remove();
				}
			});
		}

		/**
		 * Handle master password reset
		 */
		$( '#reset-master-password-btn' ).on(
			'click',
			function (e) {
				e.preventDefault();

				var $button = $( this );

				// Show modal instead of browser confirm
				showResetWarningModal(function() {
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
									alert( strings.resetFailed + ' ' + (response.data || 'Unknown error') );
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
				});
			}
		);
	}
);
