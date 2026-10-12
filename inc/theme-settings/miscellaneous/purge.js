document.addEventListener( 'DOMContentLoaded', function () {
	var button = document.getElementById( 'devbase-retention-purge-now' );
	var result = document.getElementById( 'devbase-retention-purge-result' );

	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		button.classList.add( 'updating-message' );
		result.textContent = devbaseRetentionPurge.runningText;
		result.style.color = '';

		var formData = new FormData();
		formData.append( 'action', 'devbase_retention_purge' );
		formData.append( '_wpnonce', devbaseRetentionPurge.nonce );

		fetch( devbaseRetentionPurge.ajaxUrl, {
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
				result.textContent = devbaseRetentionPurge.errorText;
				result.style.color = '#d63638';
			} )
			.finally( function () {
				button.disabled = false;
				button.classList.remove( 'updating-message' );
			} );
	} );
} );
