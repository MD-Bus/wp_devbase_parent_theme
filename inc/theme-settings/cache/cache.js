// The Cache page's buttons (ajax.php). Each button names its AJAX action in data-action (and data-op when it has
// one); its result goes in the span right after it.
document.addEventListener( 'click', function ( event ) {
	var button = event.target.closest( '.devbase-cache-action' );

	if ( ! button || typeof devbaseCache === 'undefined' ) {
		return;
	}

	event.preventDefault();

	var result = button.nextElementSibling && button.nextElementSibling.classList.contains( 'devbase-cache-result' ) ? button.nextElementSibling : null;

	button.disabled = true;
	button.classList.add( 'updating-message' );

	if ( result ) {
		result.textContent = devbaseCache.runningText;
		result.style.color = '';
	}

	var formData = new FormData();
	formData.append( 'action', button.getAttribute( 'data-action' ) );
	formData.append( 'op', button.getAttribute( 'data-op' ) || '' );
	formData.append( '_wpnonce', devbaseCache.nonce );

	fetch( devbaseCache.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body: formData,
	} )
		.then( function ( response ) {
			return response.json();
		} )
		.then( function ( response ) {
			if ( result ) {
				result.textContent = response && response.data && response.data.message ? response.data.message : devbaseCache.errorText;
				result.style.color = response && response.success ? '#008a20' : '#d63638';
			}
		} )
		.catch( function () {
			if ( result ) {
				result.textContent = devbaseCache.errorText;
				result.style.color = '#d63638';
			}
		} )
		.finally( function () {
			button.disabled = false;
			button.classList.remove( 'updating-message' );
		} );
} );
