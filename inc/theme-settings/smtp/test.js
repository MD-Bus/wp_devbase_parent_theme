document.addEventListener( 'DOMContentLoaded', function () {
	var button = document.getElementById( 'devbase-smtp-test' );
	var result = document.getElementById( 'devbase-smtp-test-result' );

	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		button.classList.add( 'updating-message' );
		result.textContent = devbaseSmtpTest.sendingText;
		result.style.color = '';

		var formData = new FormData();
		formData.append( 'action', 'devbase_smtp_test' );
		formData.append( '_wpnonce', devbaseSmtpTest.nonce );

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
