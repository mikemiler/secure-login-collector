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
		const fileName = config.exportFileName || 'secure-login-free-public-key.pem';

		if (!ajaxUrl || !nonce) {
			return;
		}

		$('#initialize-free-keys').on('click', function () {
			const $button = $(this);

			$button.prop('disabled', true).text(getString(strings, 'initializing', 'Initializing...'));

			$.post(ajaxUrl, {
				action: 'seculoco_initialize_free_keys',
				nonce: nonce,
			})
				.done(function (response) {
					if (response && response.success) {
						window.alert(getString(strings, 'initSuccess', 'Free RSA keys initialized successfully!'));
						window.location.reload();
						return;
					}

					const message = response && response.data ? response.data : '';
					window.alert(getString(strings, 'initFailedPrefix', 'Failed to initialize keys: ') + message);
					$button.prop('disabled', false).text(getString(strings, 'initButtonLabel', 'Initialize Free Keys Now'));
				})
				.fail(function () {
					window.alert(getString(strings, 'networkError', 'Network error occurred.'));
					$button.prop('disabled', false).text(getString(strings, 'initButtonLabel', 'Initialize Free Keys Now'));
				});
		});

		$('#export-free-public-key, #export-pro-public-key').on('click', function () {
			const keyType = $(this).is('#export-pro-public-key') ? 'pro' : 'free';

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

						anchor.href = url;
						anchor.download = keyType === 'pro' ? 'secure-login-pro-public-key.pem' : fileName;
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
			const defaultText = config.defaultFrontendText || '';

			const applyTextareaState = function () {
				const selected = $textTypeRadios.filter(':checked').val() || 'default';

				if (selected === 'default') {
					$frontendTextarea.prop('disabled', true).css('background-color', '#f1f1f1');

					const currentVal = ($frontendTextarea.val() || '').trim();
					if (currentVal === '' || currentVal === defaultText) {
						$frontendTextarea.val(defaultText);
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
