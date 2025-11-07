/* global seculocoKeyManager */
(function ($) {
	'use strict';

	const getString = function (strings, key, fallback) {
		if (strings && Object.prototype.hasOwnProperty.call(strings, key)) {
			return strings[ key ];
		}

		return fallback;
	};

	$(function () {
		if (typeof seculocoKeyManager === 'undefined') {
			return;
		}

		const config   = seculocoKeyManager;
		const ajaxUrl  = config.ajaxUrl || window.ajaxurl || '';
		const nonce    = config.nonce || '';
		const strings  = config.strings || {};
		const passwordFile = config.passwordExportFileName || 'secure-login-password-public-key.pem';
		const passkeyFile  = config.passkeyExportFileName || 'secure-login-passkey-public-key.pem';
		const defaultFrontendText = config.defaultFrontendText || '';

		if (!ajaxUrl || !nonce) {
			return;
		}

		$(document).on('click', '.seculoco-export-key', function () {
			const keyType = $(this).data('key-type') === 'passkey' ? 'passkey' : 'standard';

			$.post(ajaxUrl, {
				action: 'seculoco_export_public_key',
				key_type: keyType,
				nonce: nonce,
			})
				.done(function (response) {
					if (response && response.success && response.data && response.data.public_key) {
						const blob = new Blob([ response.data.public_key ], { type: 'text/plain' });
						const url = window.URL.createObjectURL(blob);
						const anchor = document.createElement('a');
						const fileName = (keyType === 'passkey') ? passkeyFile : passwordFile;

						anchor.href = url;
						anchor.download = fileName;
						anchor.click();

						window.URL.revokeObjectURL(url);
						return;
					}

					const message = response && response.data ? response.data : '';
					window.alert(getString(strings, 'exportFailedPrefix', 'Failed to export public key: ') + message);
				})
				.fail(function () {
					window.alert(getString(strings, 'networkError', 'Network error occurred.'));
				});
		});

		const $textTypeRadios = $('input[name="seculoco_frontend_text_type"]');
		const $frontendTextarea = $('#seculoco_frontend_form_text');

		if ($textTypeRadios.length && $frontendTextarea.length) {
			const applyTextareaState = function () {
				const selected = $textTypeRadios.filter(':checked').val() || 'default';

				if (selected === 'default') {
					$frontendTextarea.prop('disabled', true).css('background-color', '#f1f1f1');

					if (defaultFrontendText !== '') {
						const currentVal = ($frontendTextarea.val() || '').trim();
						if (currentVal === '' || currentVal === defaultFrontendText) {
							$frontendTextarea.val(defaultFrontendText);
						}
					}
				} else {
					$frontendTextarea.prop('disabled', false).css('background-color', '#fff');
				}
			};

			applyTextareaState();
			$textTypeRadios.on('change', applyTextareaState);
		}
	});
})(jQuery);
