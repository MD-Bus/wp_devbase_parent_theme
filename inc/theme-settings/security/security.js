/**
 * Security Settings admin page: when one provider's enable toggle is
 * switched on, immediately switch the other one off in the browser. This
 * is UX only — the actual guarantee that only one provider can be saved
 * active is enforced server-side in settings.php via acf/validate_save_post,
 * which runs regardless of what this script does.
 */
( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var turnstileField = document.querySelector( '[data-name="turnstile_enabled"] input[type="checkbox"]' );
		var recaptchaField = document.querySelector( '[data-name="recaptcha_enabled"] input[type="checkbox"]' );

		if ( ! turnstileField || ! recaptchaField ) {
			return;
		}

		turnstileField.addEventListener( 'change', function () {
			if ( turnstileField.checked && recaptchaField.checked ) {
				recaptchaField.click();
			}
		} );

		recaptchaField.addEventListener( 'change', function () {
			if ( recaptchaField.checked && turnstileField.checked ) {
				turnstileField.click();
			}
		} );
	} );
} )();
