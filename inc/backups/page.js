// The Backups tab of the Licence page: loads its content the first time the tab is shown, then handles the
// buttons (start a backup, download one, refresh the list) and follows the job in progress.
document.addEventListener( 'DOMContentLoaded', function () {
	var root = document.getElementById( 'devbase-backups-tab' );

	if ( ! root ) {
		return;
	}

	var timer = null;
	var loaded = false;

	function call( action, id ) {
		var data = new FormData();
		data.append( 'action', 'devbase_backups_action' );
		data.append( '_wpnonce', devbaseBackups.nonce );
		data.append( 'do', action );
		if ( id ) {
			data.append( 'id', id );
		}

		return fetch( devbaseBackups.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( r ) {
			return r.json();
		} );
	}

	function buttons() {
		return root.querySelectorAll( '[data-devbase-backup]' );
	}

	function setDisabled( value ) {
		buttons().forEach( function ( b ) { b.disabled = value; } );
	}

	function statusEl() {
		return document.getElementById( 'devbase-backup-status' );
	}

	function show( response ) {
		var status = statusEl();
		var message = response && response.data && response.data.message ? response.data.message : devbaseBackups.error;

		if ( status ) {
			status.textContent = message;
			status.style.color = response && response.success ? '' : '#d63638';
		}

		return response && response.success && response.data.active;
	}

	function follow() {
		clearTimeout( timer );
		timer = setTimeout( function () {
			call( 'status' ).then( function ( response ) {
				if ( show( response ) ) {
					follow();
				} else {
					// Finished (or failed): draw the tab again so the list and the buttons are current.
					setTimeout( load, 1500 );
				}
			} ).catch( follow );
		}, 3000 );
	}

	function load() {
		call( 'render' ).then( function ( response ) {
			root.innerHTML = response && response.success ? response.data.html : '<p class="description">' + devbaseBackups.error + '</p>';

			var status = statusEl();
			if ( status && '1' === status.getAttribute( 'data-active' ) ) {
				setDisabled( true );
				follow();
			}
		} ).catch( function () {
			root.innerHTML = '<p class="description">' + devbaseBackups.error + '</p>';
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-devbase-backup]' );

		if ( ! button || button.disabled ) {
			return;
		}

		var action = button.getAttribute( 'data-devbase-backup' );
		setDisabled( true );
		if ( statusEl() ) {
			statusEl().textContent = '…';
		}

		call( action, button.getAttribute( 'data-id' ) ).then( function ( response ) {
			if ( 'refresh' === action ) {
				load();
				return;
			}

			if ( show( response ) ) {
				follow();
			} else {
				setDisabled( false );
			}
		} ).catch( function () {
			show( null );
			setDisabled( false );
		} );
	} );

	// The tab is hidden until it is chosen: load the first time it can be seen (not on every Licence page load).
	function loadOnce() {
		if ( ! loaded ) {
			loaded = true;
			load();
		}
	}

	if ( 'IntersectionObserver' in window ) {
		var observer = new IntersectionObserver( function ( entries ) {
			if ( entries.some( function ( e ) { return e.isIntersecting; } ) ) {
				observer.disconnect();
				loadOnce();
			}
		} );
		observer.observe( root );
	}

	// Fallback: any click on the page's tabs (or the tab already showing) loads it once the box is visible.
	document.addEventListener( 'click', function () {
		setTimeout( function () {
			if ( root.offsetParent !== null ) {
				loadOnce();
			}
		}, 100 );
	} );
	if ( root.offsetParent !== null ) {
		loadOnce();
	}
} );
