/**
 * Passkey Management - Pro Feature
 *
 * Handles passkey registration and reset and provides the shared PRF-based key
 * wrapping helpers (also used by admin-decrypt__premium_only.js).
 *
 * All key material stays in the browser: the Pro RSA keypair is generated here,
 * the private key is wrapped with AES-256-GCM under an HKDF key derived from the
 * WebAuthn PRF extension output of the registered passkey.
 *
 * @package SecureLoginCollector
 * @fs_premium_only
 */

/* global jQuery, secureLoginPasskeyData */

/**
 * Shared passkey crypto helpers.
 */
window.seculocoPasskeyCrypto = (function () {
	'use strict';

	var WRAP_VERSION = 3;

	function config() {
		return (typeof secureLoginPasskeyData !== 'undefined') ? secureLoginPasskeyData : {};
	}

	function strings() {
		return config().strings || {};
	}

	function kdfInfo() {
		return config().kdfInfo || 'seculoco-pro-wrap-v1';
	}

	function b64ToBytes(b64) {
		var binary = window.atob(String(b64 || '').replace(/\s+/g, ''));
		var bytes = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) {
			bytes[i] = binary.charCodeAt(i);
		}
		return bytes;
	}

	function bytesToB64(bytes) {
		var arr = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
		var binary = '';
		for (var i = 0; i < arr.length; i++) {
			binary += String.fromCharCode(arr[i]);
		}
		return window.btoa(binary);
	}

	function randomBytes(length) {
		return crypto.getRandomValues(new Uint8Array(length));
	}

	function derToPem(der, label) {
		var lines = bytesToB64(der).match(/.{1,64}/g) || [];
		return '-----BEGIN ' + label + '-----\n' + lines.join('\n') + '\n-----END ' + label + '-----\n';
	}

	function pemToDer(pem) {
		var body = String(pem)
			.replace(/-----BEGIN [A-Z ]+-----/, '')
			.replace(/-----END [A-Z ]+-----/, '')
			.replace(/\s+/g, '');
		return b64ToBytes(body);
	}

	function prfError(message) {
		var error = new Error(message);
		error.code = 'prf_unsupported';
		return error;
	}

	/**
	 * Whether a stored blob uses the current PRF wrapping format.
	 */
	function isPrfWrapped(wrapped) {
		return !!wrapped && Number(wrapped.version) === WRAP_VERSION && wrapped.method === 'prf';
	}

	/**
	 * Run a WebAuthn assertion with the PRF extension and return the PRF output.
	 *
	 * @param {string}     credentialIdB64 Credential id (base64).
	 * @param {Uint8Array} saltBytes       PRF eval input (32 bytes).
	 * @param {Uint8Array} challengeBytes  Challenge bytes.
	 * @return {Promise<{assertion: PublicKeyCredential, prfOutput: Uint8Array}>}
	 */
	async function getPrfAssertion(credentialIdB64, saltBytes, challengeBytes) {
		var assertion = await navigator.credentials.get({
			publicKey: {
				challenge: challengeBytes,
				allowCredentials: [{ type: 'public-key', id: b64ToBytes(credentialIdB64) }],
				userVerification: 'required',
				timeout: 60000,
				extensions: { prf: { eval: { first: saltBytes } } }
			}
		});

		var results = assertion.getClientExtensionResults ? assertion.getClientExtensionResults() : {};
		var first = results && results.prf && results.prf.results && results.prf.results.first;

		if (!first) {
			throw prfError(strings().prfOutputMissing || 'PRF output missing.');
		}

		return { assertion: assertion, prfOutput: new Uint8Array(first) };
	}

	/**
	 * Derive the AES-256-GCM wrapping key from the PRF output (HKDF-SHA256).
	 *
	 * @param {Uint8Array} prfOutput PRF output.
	 * @param {string}     [info]    HKDF info stored in the blob; defaults to the configured value.
	 * @return {Promise<CryptoKey>}
	 */
	async function deriveWrappingKey(prfOutput, info) {
		var baseKey = await crypto.subtle.importKey('raw', prfOutput, 'HKDF', false, ['deriveKey']);
		return crypto.subtle.deriveKey(
			{ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: new TextEncoder().encode(info || kdfInfo()) },
			baseKey,
			{ name: 'AES-GCM', length: 256 },
			false,
			['encrypt', 'decrypt']
		);
	}

	/**
	 * Wrap a PEM private key into the version 3 blob.
	 */
	async function wrapPem(pem, wrappingKey, saltBytes, credentialIdB64) {
		var iv = randomBytes(12);
		var encrypted = new Uint8Array(await crypto.subtle.encrypt(
			{ name: 'AES-GCM', iv: iv, tagLength: 128 },
			wrappingKey,
			new TextEncoder().encode(pem)
		));

		return {
			version: WRAP_VERSION,
			method: 'prf',
			algorithm: 'aes-256-gcm',
			kdf: 'HKDF-SHA256',
			kdf_info: kdfInfo(),
			prf_salt: bytesToB64(saltBytes),
			credential_id: credentialIdB64,
			ciphertext: bytesToB64(encrypted.slice(0, encrypted.length - 16)),
			iv: bytesToB64(iv),
			tag: bytesToB64(encrypted.slice(encrypted.length - 16))
		};
	}

	/**
	 * Unwrap a version 3 blob to the PEM text.
	 */
	async function unwrapToPem(wrapped, unwrappingKey) {
		var iv = b64ToBytes(wrapped.iv);
		var tag = b64ToBytes(wrapped.tag);
		var ciphertext = b64ToBytes(wrapped.ciphertext);
		var combined = new Uint8Array(ciphertext.length + tag.length);
		combined.set(ciphertext, 0);
		combined.set(tag, ciphertext.length);

		var decrypted = await crypto.subtle.decrypt(
			{ name: 'AES-GCM', iv: iv, tagLength: tag.length * 8 },
			unwrappingKey,
			combined
		);

		return new TextDecoder().decode(decrypted);
	}

	async function importPrivateKey(pem) {
		return crypto.subtle.importKey('pkcs8', pemToDer(pem), { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['decrypt']);
	}

	/**
	 * Generate the Pro RSA-OAEP keypair in the browser.
	 */
	async function generateProKeypair() {
		var keypair = await crypto.subtle.generateKey(
			{ name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
			true,
			['encrypt', 'decrypt']
		);
		var pkcs8 = new Uint8Array(await crypto.subtle.exportKey('pkcs8', keypair.privateKey));
		var spki = new Uint8Array(await crypto.subtle.exportKey('spki', keypair.publicKey));

		return {
			privatePem: derToPem(pkcs8, 'PRIVATE KEY'),
			publicPem: derToPem(spki, 'PUBLIC KEY')
		};
	}

	return {
		WRAP_VERSION: WRAP_VERSION,
		strings: strings,
		b64ToBytes: b64ToBytes,
		bytesToB64: bytesToB64,
		randomBytes: randomBytes,
		isPrfWrapped: isPrfWrapped,
		getPrfAssertion: getPrfAssertion,
		deriveWrappingKey: deriveWrappingKey,
		wrapPem: wrapPem,
		unwrapToPem: unwrapToPem,
		importPrivateKey: importPrivateKey,
		generateProKeypair: generateProKeypair
	};
})();

jQuery(document).ready(function($) {
	'use strict';

	if (typeof secureLoginPasskeyData === 'undefined') {
		return;
	}

	var cryptoHelpers = window.seculocoPasskeyCrypto;
	var ajaxUrl = secureLoginPasskeyData.ajaxUrl;
	var nonce = secureLoginPasskeyData.nonce;
	var hasEncryptedData = secureLoginPasskeyData.hasEncryptedData;
	var secureContextAllowed = !!secureLoginPasskeyData.secureContextAllowed;
	var strings = secureLoginPasskeyData.strings;

	if (!secureContextAllowed) {
		$('.seculoco-passkey-register-btn').prop('disabled', true).addClass('is-disabled');
	}

	/**
	 * POST to admin-ajax and resolve with the JSON response; reject on failure.
	 */
	function ajaxPost(action, data) {
		return $.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: $.extend({ action: action, nonce: nonce }, data || {})
		}).then(function(response) {
			if (!response || !response.success) {
				var message = response && response.data;
				if (message && typeof message === 'object') {
					message = message.message;
				}
				throw new Error(message || strings.networkError);
			}
			return response;
		}, function() {
			throw new Error(strings.networkError);
		});
	}

	function errorMessage(error) {
		return (error && error.message) ? error.message : strings.networkError;
	}

	function noticeHtml(type, message) {
		return $('<div class="notice inline"><p></p></div>')
			.addClass('notice-' + type)
			.find('p').text(message).end();
	}

	/**
	 * Create a passkey that supports PRF and obtain its PRF output.
	 * Nothing is written on the server until the registration is completed.
	 */
	async function registerPrfPasskey(authenticatorType) {
		var startResponse = await ajaxPost('seculoco_passkey_start_registration', { authenticator_type: authenticatorType });
		var options = startResponse.data;

		options.challenge = cryptoHelpers.b64ToBytes(options.challenge);
		options.user.id = cryptoHelpers.b64ToBytes(options.user.id);
		options.extensions = { prf: {} };

		var credential = await navigator.credentials.create({ publicKey: options });
		var created = credential.getClientExtensionResults ? credential.getClientExtensionResults() : {};

		if (!created.prf || created.prf.enabled !== true) {
			var error = new Error(strings.prfUnsupported);
			error.code = 'prf_unsupported';
			throw error;
		}

		var credentialIdB64 = cryptoHelpers.bytesToB64(new Uint8Array(credential.rawId));
		var salt = cryptoHelpers.randomBytes(32);

		// Second ceremony: evaluate the PRF for this credential (the server does not verify this assertion).
		var prf = await cryptoHelpers.getPrfAssertion(credentialIdB64, salt, cryptoHelpers.randomBytes(32));

		var publicKeyData = 'not_available';
		if (credential.response.getPublicKey) {
			var publicKey = credential.response.getPublicKey();
			if (publicKey) {
				publicKeyData = cryptoHelpers.bytesToB64(new Uint8Array(publicKey));
			}
		}

		return {
			credential: credential,
			credentialIdB64: credentialIdB64,
			salt: salt,
			prfOutput: prf.prfOutput,
			publicKeyData: publicKeyData,
			deviceInfo: detectDeviceInfo()
		};
	}

	/**
	 * Handle passkey reset via confirmation modal
	 */
	$(document).on('click', '#delete-passkey-btn', function(e) {
		e.preventDefault();

		var credentialId = $(this).data('credential-id');

		if (!credentialId) {
			alert('No credential ID found. Please refresh the page and try again.');
			return;
		}

		openResetModal(credentialId);
	});

	function openResetModal(credentialId) {
		var warningMessage = hasEncryptedData ? strings.warningDataLoss : strings.warningSimple;
		var modalHtml = `
			<div class="seculoco-password-modal-backdrop"></div>
			<div class="seculoco-password-modal">
				<div class="seculoco-password-modal-header">
					<h2>${strings.resetModalTitle || strings.deletePasskey}</h2>
				</div>
				<div class="seculoco-password-modal-body">
					<div class="seculoco-alert seculoco-alert-danger">
						<span class="seculoco-alert-icon dashicons dashicons-warning"></span>
						<div class="seculoco-alert-content">
							<div class="seculoco-alert-message">${warningMessage.replace(/\n/g, '<br>')}</div>
						</div>
					</div>
					<div class="seculoco-password-form-group">
						<label class="seculoco-password-label">${strings.typeConfirmToReset}</label>
						<input type="text" class="seculoco-password-input seculoco-passkey-reset-confirm" placeholder="RESET" />
					</div>
					<div class="seculoco-password-error"></div>
				</div>
				<div class="seculoco-password-modal-footer">
					<button type="button" class="button button-secondary seculoco-passkey-modal-cancel">${strings.cancel}</button>
					<button type="button" class="button button-danger seculoco-passkey-modal-submit">${strings.deletePasskey}</button>
				</div>
			</div>
		`;

		$('body').append(modalHtml);

		$('.seculoco-passkey-modal-cancel').on('click', closeModal);
		$('.seculoco-password-modal-backdrop').on('click', closeModal);
		$('.seculoco-passkey-modal-submit').on('click', function() {
			submitReset(credentialId);
		});
		$('.seculoco-passkey-reset-confirm').on('keypress', function(event) {
			if (event.which === 13) {
				submitReset(credentialId);
			}
		});
	}

	function closeModal() {
		$('.seculoco-password-modal').remove();
		$('.seculoco-password-modal-backdrop').remove();
	}

	function submitReset(credentialId) {
		var $confirmInput = $('.seculoco-passkey-reset-confirm');
		var $error = $('.seculoco-password-error');
		var $submit = $('.seculoco-passkey-modal-submit');

		if ($confirmInput.val().trim().toUpperCase() !== 'RESET') {
			$error.text(strings.resetConfirmRequired);
			return;
		}

		$error.text('');
		$submit.prop('disabled', true).text(strings.deleting);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'seculoco_passkey_delete',
				nonce: nonce,
				credential_id: credentialId
			},
			success: function(response) {
				if (response.success) {
					closeModal();
					var $statusArea = $('.seculoco-passkey-status');
					if ($statusArea.length) {
						$statusArea.empty().append(noticeHtml('success', strings.deleteSuccess));
					}
					setTimeout(function() {
						window.location.reload();
					}, 1500);
				} else {
					$submit.prop('disabled', false).text(strings.deletePasskey);
					alert(strings.deleteFailed + ' ' + (response.data || 'Unknown error'));
				}
			},
			error: function(xhr, status, error) {
				console.error('Reset error:', error);
				$submit.prop('disabled', false).text(strings.deletePasskey);
				alert(strings.networkError);
			}
		});
	}

	/**
	 * Handle passkey registration.
	 */
	$(document).on('click', '.seculoco-passkey-register-btn', async function(e) {
		e.preventDefault();

		var $button = $(this);
		var $spinner = $('.passkey-registration-form .spinner');
		var $statusMessage = $('.seculoco-passkey-status');

		$statusMessage.empty();

		if (!window.PublicKeyCredential) {
			$statusMessage.append(noticeHtml('error', strings.noWebAuthn));
			return;
		}

		var explicitType = $button.data('authenticator-type');
		var authenticatorType = $('input[name="authenticator_type"]:checked').val() || explicitType || 'cross-platform';

		$button.prop('disabled', true);
		$spinner.addClass('is-active');

		if (!secureContextAllowed || !window.isSecureContext) {
			$statusMessage.append(noticeHtml('error', strings.httpsRequired));
			$button.prop('disabled', false);
			$spinner.removeClass('is-active');
			return;
		}

		try {
			var registration = await registerPrfPasskey(authenticatorType);
			var keypair = await cryptoHelpers.generateProKeypair();
			var wrappingKey = await cryptoHelpers.deriveWrappingKey(registration.prfOutput);
			var wrapped = await cryptoHelpers.wrapPem(keypair.privatePem, wrappingKey, registration.salt, registration.credentialIdB64);

			// Self-test before anything is stored.
			var roundTrip = await cryptoHelpers.unwrapToPem(wrapped, wrappingKey);
			if (roundTrip !== keypair.privatePem) {
				throw new Error(strings.cryptoSelfTestFailed);
			}

			await ajaxPost('seculoco_passkey_complete_registration', {
				name: 'Passkey ' + new Date().toLocaleDateString(),
				credential_id: registration.credentialIdB64,
				public_key: registration.publicKeyData,
				client_data: cryptoHelpers.bytesToB64(new Uint8Array(registration.credential.response.clientDataJSON)),
				attestation: cryptoHelpers.bytesToB64(new Uint8Array(registration.credential.response.attestationObject)),
				device_info: JSON.stringify(registration.deviceInfo),
				public_key_pro: keypair.publicPem,
				wrapped_key: JSON.stringify(wrapped)
			});

			$statusMessage.append(noticeHtml('success', strings.registerSuccess));

			setTimeout(function() {
				window.location.reload();
			}, 1500);
		} catch (error) {
			console.error('Registration error:', error);
			$statusMessage.append(noticeHtml('error', errorMessage(error)));
			$button.prop('disabled', false);
			$spinner.removeClass('is-active');
		}
	});

	/**
	 * Detect device and browser information
	 *
	 * @return {Object} Device and browser information
	 */
	function detectDeviceInfo() {
		var ua = navigator.userAgent;
		var info = {
			browser: 'Unknown Browser',
			platform: 'Unknown Platform',
			type: 'device'
		};

		// Detect browser
		if (ua.indexOf('Edg') > -1) {
			info.browser = 'Edge';
		} else if (ua.indexOf('Chrome') > -1) {
			info.browser = 'Chrome';
		} else if (ua.indexOf('Safari') > -1) {
			info.browser = 'Safari';
		} else if (ua.indexOf('Firefox') > -1) {
			info.browser = 'Firefox';
		} else if (ua.indexOf('MSIE') > -1 || ua.indexOf('Trident') > -1) {
			info.browser = 'Internet Explorer';
		}

		// Detect platform and password manager hints
		if (ua.indexOf('1Password') > -1) {
			info.type = 'password_manager';
			info.platform = '1Password';
		} else if (ua.indexOf('Bitwarden') > -1) {
			info.type = 'password_manager';
			info.platform = 'Bitwarden';
		} else if (ua.indexOf('Dashlane') > -1) {
			info.type = 'password_manager';
			info.platform = 'Dashlane';
		} else if (ua.indexOf('Mac') > -1 || ua.indexOf('iPhone') > -1 || ua.indexOf('iPad') > -1) {
			if (ua.indexOf('iPhone') > -1) {
				info.platform = 'iPhone';
			} else if (ua.indexOf('iPad') > -1) {
				info.platform = 'iPad';
			} else {
				info.platform = 'macOS';
			}
		} else if (ua.indexOf('Windows') > -1) {
			info.platform = 'Windows';
		} else if (ua.indexOf('Android') > -1) {
			info.platform = 'Android';
		} else if (ua.indexOf('Linux') > -1) {
			info.platform = 'Linux';
		}

		return info;
	}
});
