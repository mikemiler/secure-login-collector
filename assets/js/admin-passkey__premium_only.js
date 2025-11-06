/**
 * Passkey Management - Pro Feature
 * Handles passkey registration and deletion for WebAuthn/FIDO2 authentication
 *
 * @package SecureLoginCollector
 * @fs_premium_only
 */

/* global jQuery, secureLoginPasskeyData */

jQuery( document ).ready(
	function ($) {
		'use strict';

		// Get localized data from PHP
		var ajaxUrl          = secureLoginPasskeyData.ajaxUrl;
		var nonce            = secureLoginPasskeyData.nonce;
		var hasEncryptedData = secureLoginPasskeyData.hasEncryptedData;
		var strings          = secureLoginPasskeyData.strings;

		/**
		 * Show warning modal before passkey deletion
		 */
		function showPasskeyDeleteModal(callback) {
			// Create modal HTML
			var modalHtml = `
				<div id="seculoco-passkey-delete-modal" class="seculoco-modal-overlay">
					<div class="seculoco-modal-container seculoco-modal-warning">
						<div class="seculoco-modal-header">
							<h2>⚠️ ${hasEncryptedData ? 'Warning: Data Loss!' : 'Delete Passkey'}</h2>
						</div>
						<div class="seculoco-modal-content">
							${hasEncryptedData ? `
								<div class="seculoco-alert seculoco-alert-danger">
									<div class="seculoco-alert-title">⚠️ CRITICAL WARNING</div>
									<div class="seculoco-alert-message">
										<p><strong>This action will permanently destroy ALL encrypted login data!</strong></p>
										<ul>
											<li>All encrypted passwords will be lost forever</li>
											<li>You will need to re-collect login credentials</li>
											<li>This action CANNOT be undone</li>
											<li>There is NO recovery method</li>
										</ul>
									</div>
								</div>
								<div class="seculoco-field-group">
									<label>
										<input type="checkbox" id="seculoco-confirm-passkey-delete" />
										<strong>I understand that all encrypted data will be permanently lost</strong>
									</label>
								</div>
							` : `
								<p>You are about to delete this passkey.</p>
								<p>Since you have no encrypted data stored, this is safe to do. You can register a new passkey afterward.</p>
							`}
						</div>
						<div class="seculoco-modal-footer">
							${hasEncryptedData ? `
								<button type="button" class="button button-secondary" id="seculoco-cancel-passkey-delete">Cancel</button>
								<button type="button" class="button button-danger" id="seculoco-confirm-passkey-delete-btn" disabled>I Understand, Delete Anyway</button>
							` : `
								<button type="button" class="button button-secondary" id="seculoco-cancel-passkey-delete">Cancel</button>
								<button type="button" class="button button-primary" id="seculoco-confirm-passkey-delete-btn">Delete Passkey</button>
							`}
						</div>
					</div>
				</div>
			`;

			$('body').append(modalHtml);

			var $modal = $('#seculoco-passkey-delete-modal');
			var $confirmBtn = $('#seculoco-confirm-passkey-delete-btn');
			var $checkbox = $('#seculoco-confirm-passkey-delete');

			// Enable confirm button when checkbox is checked (only if there's encrypted data)
			if (hasEncryptedData) {
				$checkbox.on('change', function() {
					$confirmBtn.prop('disabled', !$(this).is(':checked'));
				});
			}

			// Handle cancel
			$('#seculoco-cancel-passkey-delete').on('click', function() {
				$modal.remove();
			});

			// Handle confirm
			$confirmBtn.on('click', function() {
				$modal.remove();
				callback();
			});

			// Escape key closes modal
			$(document).on('keydown.seculoco-passkey-delete-modal', function(e) {
				if (e.which === 27) {
					$(document).off('keydown.seculoco-passkey-delete-modal');
					$modal.remove();
				}
			});
		}

		/**
		 * Handle passkey deletion
		 */
		$( '#delete-passkey-btn' ).on(
			'click',
			function (e) {
				e.preventDefault();

				var $button      = $( this );
				var credentialId = $button.data( 'credential-id' );

				if ( ! credentialId) {
					$( '#passkey-status-message' ).html( '<div class="notice notice-error inline"><p>No credential ID found. Please refresh the page and try again.</p></div>' );
					return;
				}

				// Show modal instead of browser confirm
				showPasskeyDeleteModal(function() {
					$button.prop( 'disabled', true ).text( strings.deleting );

					$.ajax(
						{
							url: ajaxUrl,
							type: 'POST',
							data: {
								action: 'seculoco_passkey_delete',
								nonce: nonce,
								credential_id: credentialId
							},
							success: function (response) {
								if (response.success) {
									$( '#passkey-status-message' ).html( '<div class="notice notice-success inline"><p>' + strings.deleteSuccess + '</p></div>' );
									setTimeout(
										function () {
											window.location.reload();
										},
										1500
									);
								} else {
									$( '#passkey-status-message' ).html( '<div class="notice notice-error inline"><p>' + strings.deleteFailed + ' ' + (response.data || 'Unknown error') + '</p></div>' );
									$button.prop( 'disabled', false ).text( strings.deletePasskey );
								}
							},
							error: function (xhr, status, error) {
								console.error( 'Delete error:', error );
								$( '#passkey-status-message' ).html( '<div class="notice notice-error inline"><p>' + strings.networkError + '</p></div>' );
								$button.prop( 'disabled', false ).text( strings.deletePasskey );
							}
						}
					);
				});
			}
		);

		/**
		 * Handle passkey registration
		 */
		$( '#register-passkey-btn' ).on(
			'click',
			async function (e) {
				e.preventDefault();

				var $button        = $( this );
				var $spinner       = $( '.passkey-registration-form .spinner' );
				var $statusMessage = $( '#passkey-status-message' );

				// Clear any previous messages
				$statusMessage.empty();

				// Check WebAuthn support
				if ( ! window.PublicKeyCredential) {
					$statusMessage.html( '<div class="notice notice-error inline"><p>' + strings.noWebAuthn + '</p></div>' );
					return;
				}

				// Get selected authenticator type (auto, platform, or security-key)
				// Default to 'auto' to allow password managers to work
				var authenticatorType = $( 'input[name="authenticator_type"]:checked' ).val() || 'auto';

				// Detect device and browser information
				var deviceInfo = detectDeviceInfo();

				$button.prop( 'disabled', true );
				$spinner.addClass( 'is-active' );

				try {
					// Start registration to get challenge
					const startResponse = await $.ajax(
						{
							url: ajaxUrl,
							type: 'POST',
							data: {
								action: 'seculoco_passkey_start_registration',
								nonce: nonce,
								authenticator_type: authenticatorType
							}
						}
					);
console.log(startResponse);
					if ( ! startResponse.success) {
						throw new Error( startResponse.data || 'Failed to start registration' );
					}

					const options = startResponse.data;

					// Convert base64 strings to ArrayBuffers
					options.challenge = base64ToArrayBuffer( options.challenge );
					options.user.id   = base64ToArrayBuffer( options.user.id );

					// Create credential
					const credential = await navigator.credentials.create(
						{
							publicKey: options
						}
					);

					// Initialize the zero-knowledge setup
					const initResponse = await $.ajax(
						{
							url: ajaxUrl,
							type: 'POST',
							data: {
								action: 'seculoco_passkey_init_setup',
								nonce: nonce,
								credential_id: arrayBufferToBase64( credential.rawId )
							}
						}
					);

					// Check initialization response
					if ( ! initResponse.success) {
						if (typeof initResponse.data === 'string' && initResponse.data.includes( 'Already initialized' )) {
							console.warn( 'Keys already initialized, continuing with registration' );
						} else {
							throw new Error( initResponse.data || 'Failed to initialize encryption' );
						}
					}

					// Complete passkey registration with auto-generated name
					let publicKeyData;
					if (credential.response.publicKey) {
						publicKeyData = arrayBufferToBase64( credential.response.publicKey );
					} else if (credential.response.getPublicKey) {
						publicKeyData = arrayBufferToBase64( credential.response.getPublicKey() );
					} else {
						publicKeyData = 'not_available';
					}

					const completeResponse = await $.ajax(
						{
							url: ajaxUrl,
							type: 'POST',
							data: {
								action: 'seculoco_passkey_complete_registration',
								nonce: nonce,
								name: 'Passkey ' + new Date().toLocaleDateString(),
								credential_id: arrayBufferToBase64( credential.rawId ),
								public_key: publicKeyData,
								client_data: arrayBufferToBase64( credential.response.clientDataJSON ),
								attestation: arrayBufferToBase64( credential.response.attestationObject ),
								device_info: JSON.stringify( deviceInfo )
							}
						}
					);

					if ( ! completeResponse.success) {
						throw new Error( completeResponse.data || 'Failed to complete registration' );
					}

					$statusMessage.html( '<div class="notice notice-success inline"><p>' + strings.registerSuccess + '</p></div>' );

					// Reload page after success
					setTimeout(
						function () {
							window.location.reload();
						},
						1500
					);

				} catch (error) {

					console.error( 'Registration error:', error );
					$statusMessage.html( '<div class="notice notice-error inline"><p>' + error.message + '</p></div>' );
					$button.prop( 'disabled', false );
					$spinner.removeClass( 'is-active' );
				}
			}
		);

		/**
		 * Helper function: Convert base64 to ArrayBuffer
		 *
		 * @param {string} base64 Base64 encoded string
		 * @return {ArrayBuffer} Decoded array buffer
		 */
		function base64ToArrayBuffer(base64) {
			const binaryString = atob( base64 );
			const bytes        = new Uint8Array( binaryString.length );
			for (let i = 0; i < binaryString.length; i++) {
				bytes[i] = binaryString.charCodeAt( i );
			}
			return bytes.buffer;
		}

		/**
		 * Helper function: Convert ArrayBuffer to base64
		 *
		 * @param {ArrayBuffer} buffer Array buffer to encode
		 * @return {string} Base64 encoded string
		 */
		function arrayBufferToBase64(buffer) {
			const bytes = new Uint8Array( buffer );
			let binary  = '';
			for (let i = 0; i < bytes.length; i++) {
				binary += String.fromCharCode( bytes[i] );
			}
			return btoa( binary );
		}

		/**
		 * Detect device and browser information
		 *
		 * @return {Object} Device and browser information
		 */
		function detectDeviceInfo() {
			var ua   = navigator.userAgent;
			var info = {
				browser: 'Unknown Browser',
				platform: 'Unknown Platform',
				type: 'device'
			};

			// Detect browser
			if (ua.indexOf( 'Edg' ) > -1) {
				info.browser = 'Edge';
			} else if (ua.indexOf( 'Chrome' ) > -1) {
				info.browser = 'Chrome';
			} else if (ua.indexOf( 'Safari' ) > -1) {
				info.browser = 'Safari';
			} else if (ua.indexOf( 'Firefox' ) > -1) {
				info.browser = 'Firefox';
			} else if (ua.indexOf( 'MSIE' ) > -1 || ua.indexOf( 'Trident' ) > -1) {
				info.browser = 'Internet Explorer';
			}

			// Detect platform and password manager hints
			if (ua.indexOf( '1Password' ) > -1) {
				info.type     = 'password_manager';
				info.platform = '1Password';
			} else if (ua.indexOf( 'Bitwarden' ) > -1) {
				info.type     = 'password_manager';
				info.platform = 'Bitwarden';
			} else if (ua.indexOf( 'Dashlane' ) > -1) {
				info.type     = 'password_manager';
				info.platform = 'Dashlane';
			} else if (ua.indexOf( 'Mac' ) > -1 || ua.indexOf( 'iPhone' ) > -1 || ua.indexOf( 'iPad' ) > -1) {
				if (ua.indexOf( 'iPhone' ) > -1) {
					info.platform = 'iPhone';
				} else if (ua.indexOf( 'iPad' ) > -1) {
					info.platform = 'iPad';
				} else {
					info.platform = 'macOS';
				}
			} else if (ua.indexOf( 'Windows' ) > -1) {
				info.platform = 'Windows';
			} else if (ua.indexOf( 'Android' ) > -1) {
				info.platform = 'Android';
			} else if (ua.indexOf( 'Linux' ) > -1) {
				info.platform = 'Linux';
			}

			return info;
		}
	}
);
