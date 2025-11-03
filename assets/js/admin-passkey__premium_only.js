/**
 * Passkey Management - Pro Feature
 * Handles passkey registration and deletion for WebAuthn/FIDO2 authentication
 *
 * @package SecureLoginCollector
 * @fs_premium_only
 */

/* global jQuery, secureLoginPasskeyData */

jQuery(document).ready(function($) {
	'use strict';

	// Get localized data from PHP
	var ajaxUrl = secureLoginPasskeyData.ajaxUrl;
	var nonce = secureLoginPasskeyData.nonce;
	var hasEncryptedData = secureLoginPasskeyData.hasEncryptedData;
	var strings = secureLoginPasskeyData.strings;

	/**
	 * Handle passkey deletion
	 */
	$('#delete-passkey-btn').on('click', function(e) {
		e.preventDefault();

		var $button = $(this);
		var credentialId = $button.data('credential-id');

		if (!credentialId) {
			alert('No credential ID found. Please refresh the page and try again.');
			return;
		}

		var warningMessage = hasEncryptedData ? strings.warningDataLoss : strings.warningSimple;

		if (!confirm(warningMessage)) {
			return;
		}

		$button.prop('disabled', true).text(strings.deleting);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'passkey_delete',
				nonce: nonce,
				credential_id: credentialId
			},
			success: function(response) {
				if (response.success) {
					$('#passkey-status-message').html('<div class="notice notice-success inline"><p>' + strings.deleteSuccess + '</p></div>');
					setTimeout(function() {
						window.location.reload();
					}, 1500);
				} else {
					alert(strings.deleteFailed + ' ' + (response.data || 'Unknown error'));
					$button.prop('disabled', false).text(strings.deletePasskey);
				}
			},
			error: function(xhr, status, error) {
				console.error('Delete error:', error);
				alert(strings.networkError);
				$button.prop('disabled', false).text(strings.deletePasskey);
			}
		});
	});

	/**
	 * Handle passkey registration
	 */
	$('#register-passkey-btn').on('click', async function(e) {
		console.log('register-passkey-btn clicked');
		e.preventDefault();

		var $button = $(this);
		var $spinner = $('.passkey-registration-form .spinner');
		var $statusMessage = $('#passkey-status-message');

		// Clear any previous messages
		$statusMessage.empty();

		// Check WebAuthn support
		if (!window.PublicKeyCredential) {
			$statusMessage.html('<div class="notice notice-error inline"><p>' + strings.noWebAuthn + '</p></div>');
			return;
		}

		// Get selected authenticator type
		var authenticatorType = $('input[name="authenticator_type"]:checked').val() || 'auto';

		$button.prop('disabled', true);
		$spinner.addClass('is-active');

		try {
			// Start registration to get challenge
			const startResponse = await $.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'passkey_start_registration',
					nonce: nonce,
					authenticator_type: authenticatorType
				}
			});

			if (!startResponse.success) {
				throw new Error(startResponse.data || 'Failed to start registration');
			}

			const options = startResponse.data;

			// Convert base64 strings to ArrayBuffers
			options.challenge = base64ToArrayBuffer(options.challenge);
			options.user.id = base64ToArrayBuffer(options.user.id);

			// Create credential
			const credential = await navigator.credentials.create({
				publicKey: options
			});

			// Initialize the zero-knowledge setup
			const initResponse = await $.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'passkey_init_setup',
					nonce: nonce,
					credential_id: arrayBufferToBase64(credential.rawId)
				}
			});

			// Check initialization response
			if (!initResponse.success) {
				if (typeof initResponse.data === 'string' && initResponse.data.includes('Already initialized')) {
					console.warn('Keys already initialized, continuing with registration');
				} else {
					throw new Error(initResponse.data || 'Failed to initialize encryption');
				}
			}

			// Complete passkey registration with auto-generated name
			let publicKeyData;
			if (credential.response.publicKey) {
				publicKeyData = arrayBufferToBase64(credential.response.publicKey);
			} else if (credential.response.getPublicKey) {
				publicKeyData = arrayBufferToBase64(credential.response.getPublicKey());
			} else {
				publicKeyData = 'not_available';
			}

			const completeResponse = await $.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: {
					action: 'passkey_complete_registration',
					nonce: nonce,
					name: 'Passkey ' + new Date().toLocaleDateString(),
					credential_id: arrayBufferToBase64(credential.rawId),
					public_key: publicKeyData,
					client_data: arrayBufferToBase64(credential.response.clientDataJSON),
					attestation: arrayBufferToBase64(credential.response.attestationObject)
				}
			});

			if (!completeResponse.success) {
				throw new Error(completeResponse.data || 'Failed to complete registration');
			}

			$statusMessage.html('<div class="notice notice-success inline"><p>' + strings.registerSuccess + '</p></div>');

			// Reload page after success
			setTimeout(function() {
				window.location.reload();
			}, 1500);

		} catch (error) {
			console.error('Registration error:', error);
			$statusMessage.html('<div class="notice notice-error inline"><p>' + error.message + '</p></div>');
			$button.prop('disabled', false);
			$spinner.removeClass('is-active');
		}
	});

	/**
	 * Helper function: Convert base64 to ArrayBuffer
	 *
	 * @param {string} base64 Base64 encoded string
	 * @return {ArrayBuffer} Decoded array buffer
	 */
	function base64ToArrayBuffer(base64) {
		const binaryString = atob(base64);
		const bytes = new Uint8Array(binaryString.length);
		for (let i = 0; i < binaryString.length; i++) {
			bytes[i] = binaryString.charCodeAt(i);
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
		const bytes = new Uint8Array(buffer);
		let binary = '';
		for (let i = 0; i < bytes.length; i++) {
			binary += String.fromCharCode(bytes[i]);
		}
		return btoa(binary);
	}
});
