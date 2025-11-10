/* global seculocoKeyManager */
(function ($) {
	'use strict';

	$(
		function () {
			const config              = (typeof seculocoKeyManager !== 'undefined') ? seculocoKeyManager : {};
			const defaultFrontendText = config.defaultFrontendText || '';

			const $textTypeRadios   = $( 'input[name="seculoco_frontend_text_type"]' );
			const $frontendTextarea = $( '#seculoco_frontend_form_text' );

			if ($textTypeRadios.length && $frontendTextarea.length) {
				const applyTextareaState = function () {
					const selected = $textTypeRadios.filter( ':checked' ).val() || 'default';

					if (selected === 'default') {
						$frontendTextarea.prop( 'disabled', true ).css( 'background-color', '#f1f1f1' );

						if (defaultFrontendText !== '') {
							const currentVal = ($frontendTextarea.val() || '').trim();
							if (currentVal === '' || currentVal === defaultFrontendText) {
								$frontendTextarea.val( defaultFrontendText );
							}
						}
					} else {
						$frontendTextarea.prop( 'disabled', false ).css( 'background-color', '#fff' );
					}
				};

				applyTextareaState();
				$textTypeRadios.on( 'change', applyTextareaState );
			}
		}
	);
})( jQuery );
