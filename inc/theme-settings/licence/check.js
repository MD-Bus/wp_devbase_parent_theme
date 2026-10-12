document.addEventListener( 'DOMContentLoaded', function () {
	var button = document.getElementById( 'devbase-check-updates-now' );
	var result = document.getElementById( 'devbase-check-updates-result' );

	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		button.classList.add( 'updating-message' );
		result.textContent = devbaseCheckUpdates.runningText;
		result.style.color = '';

		var formData = new FormData();
		formData.append( 'action', 'devbase_check_updates' );
		formData.append( '_wpnonce', devbaseCheckUpdates.nonce );

		fetch( devbaseCheckUpdates.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				// The handler returns one line per check, newline-separated.
				// textContent + white-space:pre-line keeps them as separate
				// lines without building HTML from a server string.
				result.textContent = response.data.message;
				result.style.color = response.success ? '#008a20' : '#d63638';
			} )
			.catch( function () {
				result.textContent = devbaseCheckUpdates.errorText;
				result.style.color = '#d63638';
			} )
			.finally( function () {
				button.disabled = false;
				button.classList.remove( 'updating-message' );
			} );
	} );
} );
