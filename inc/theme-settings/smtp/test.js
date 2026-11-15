// The "Send Test Email" buttons on Theme Settings > Email, one per tab (SMTP, Email API). Each posts its tab's route
// (data-route) so the handler can say when the tab's settings are not the ones saved and on.
document.addEventListener( 'DOMContentLoaded', function () {
	document.querySelectorAll( '.devbase-mail-test' ).forEach( function ( button ) {
		var result = button.parentNode.querySelector( '.devbase-mail-test-result' );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.classList.add( 'updating-message' );
			result.textContent = devbaseSmtpTest.sendingText;
			result.style.color = '';

			var formData = new FormData();
			formData.append( 'action', 'devbase_smtp_test' );
			formData.append( '_wpnonce', devbaseSmtpTest.nonce );
			formData.append( 'route', button.getAttribute( 'data-route' ) || 'smtp' );

			fetch( devbaseSmtpTest.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( response ) {
					result.textContent = response.data.message;
					result.style.color = response.success ? '#008a20' : '#d63638';
				} )
				.catch( function () {
					result.textContent = devbaseSmtpTest.errorText;
					result.style.color = '#d63638';
				} )
				.finally( function () {
					button.disabled = false;
					button.classList.remove( 'updating-message' );
				} );
		} );
	} );

	// One way of sending at a time: while one switch is on, the other cannot be turned on. The save refuses it too
	// (api.php); this only saves a round trip. Both on (written by code) leaves both free, for the save to sort out.
	var smtp = document.querySelector( '[data-key="field_devbase_smtp_enabled"] input[type="checkbox"]' );
	var api  = document.querySelector( '[data-key="field_devbase_mail_api_enabled"] input[type="checkbox"]' );

	if ( ! smtp || ! api ) {
		return;
	}

	function sync() {
		var both = smtp.checked && api.checked;

		smtp.disabled = ! both && api.checked;
		api.disabled  = ! both && smtp.checked;

		[ smtp, api ].forEach( function ( input ) {
			var wrap = input.closest( '.acf-switch' ) || input.parentNode;
			wrap.style.opacity = input.disabled ? '0.5' : '';
			wrap.style.cursor  = input.disabled ? 'not-allowed' : '';
		} );
	}

	smtp.addEventListener( 'change', sync );
	api.addEventListener( 'change', sync );
	sync();
} );
