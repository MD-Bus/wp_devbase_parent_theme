// Stops the flash of conditional fields. SCF draws every field first and hides the ones whose condition is not met a
// moment later, so a form with an on/off switch (SMTP, Security) showed all its fields briefly. app.css keeps fields
// that have a condition hidden until .is-ready is on the app; this adds it once SCF has applied its conditions.
// The CSS also reveals them after 2s on its own, so a failed script cannot leave a form without fields.
( function () {
	var root = document.querySelector( '.devbase-app' );

	if ( ! root ) {
		return;
	}

	var done = false;

	function ready() {
		if ( done ) {
			return;
		}

		done = true;
		root.classList.add( 'is-ready' );
	}

	if ( window.acf && typeof window.acf.addAction === 'function' ) {
		// Priority 20: after SCF's own ready handlers, which is where conditions are applied.
		window.acf.addAction( 'ready', function () {
			window.setTimeout( ready, 0 );
		}, 20 );
	}

	// If SCF's ready already fired, or never will (a read-only screen has no SCF form), do not wait for it.
	if ( ! root.querySelector( '.acf-field[data-conditions]' ) || document.readyState === 'complete' ) {
		window.setTimeout( ready, 50 );
	}

	window.setTimeout( ready, 1500 );
}() );
