// The Backups page's buttons: start a backup, download one, refresh the list, and follow the job.
document.addEventListener( 'DOMContentLoaded', function () {
	var root = document.getElementById( 'devbase-backups' );
	var status = document.getElementById( 'devbase-backup-status' );

	if ( ! root || ! status ) {
		return;
	}

	var timer = null;

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

	function show( response ) {
		var message = response && response.data && response.data.message ? response.data.message : devbaseBackups.error;
		status.textContent = message;
		status.style.color = response && response.success ? '' : '#d63638';

		return response && response.success && response.data.active;
	}

	function follow() {
		clearTimeout( timer );
		timer = setTimeout( function () {
			call( 'status' ).then( function ( response ) {
				if ( show( response ) ) {
					follow();
				} else {
					// Finished (or failed): redraw the page so the list and the buttons are current.
					setTimeout( function () { window.location.reload(); }, 1500 );
				}
			} ).catch( follow );
		}, 3000 );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-devbase-backup]' );

		if ( ! button || button.disabled ) {
			return;
		}

		var action = button.getAttribute( 'data-devbase-backup' );
		root.querySelectorAll( '[data-devbase-backup]' ).forEach( function ( b ) { b.disabled = true; } );
		status.textContent = '…';

		call( action, button.getAttribute( 'data-id' ) ).then( function ( response ) {
			if ( 'refresh' === action ) {
				window.location.reload();
				return;
			}

			if ( show( response ) ) {
				follow();
			} else {
				root.querySelectorAll( '[data-devbase-backup]' ).forEach( function ( b ) { b.disabled = false; } );
			}
		} ).catch( function () {
			show( null );
			root.querySelectorAll( '[data-devbase-backup]' ).forEach( function ( b ) { b.disabled = false; } );
		} );
	} );

	if ( '1' === status.getAttribute( 'data-active' ) ) {
		root.querySelectorAll( '[data-devbase-backup]' ).forEach( function ( b ) { b.disabled = true; } );
		follow();
	}
} );
