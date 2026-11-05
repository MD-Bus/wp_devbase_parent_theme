// Keeps the Credit and Management tabs out of sight on this page. CSS hides them from the start (see
// settings.php); this script only adds or removes the class that shows them. Out of sight, not locked:
// their fields stay in the form, so saving the page keeps their values.
//
// Press L twice quickly, or R twice quickly, to show them; C twice quickly to hide them again. They are
// hidden on every page load.
( function () {
	var HIDDEN = ':is([data-key="field_devbase_misc_credit_tab"], [data-key="field_devbase_misc_management_tab"])';
	var OPEN = 'devbase-hidden-tabs-open';
	var GAP = 400; // ms between the two presses

	var lastKey = '';
	var lastAt = 0;

	function isTyping( target ) {
		if ( target.isContentEditable ) {
			return true;
		}

		return target.matches( 'textarea, select, input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit])' );
	}

	// SCF reopens the tab used last, and a hidden tab must not stay open with its fields showing.
	function leaveHiddenTab() {
		var item = document.querySelector( '.acf-tab-group li.active:has(> ' + HIDDEN + ')' );
		var first = item ? item.parentNode.querySelector( 'li:not(:has(> ' + HIDDEN + ')) .acf-tab-button' ) : null;

		if ( first ) {
			first.click();
		}
	}

	function setOpen( open ) {
		document.body.classList.toggle( OPEN, open );

		if ( ! open ) {
			leaveHiddenTab();
		}
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.repeat || event.ctrlKey || event.metaKey || event.altKey || isTyping( event.target ) ) {
			return;
		}

		var key = event.key.toLowerCase();
		var twice = key === lastKey && event.timeStamp - lastAt <= GAP;

		lastKey = twice ? '' : key;
		lastAt = event.timeStamp;

		if ( twice && ( 'l' === key || 'r' === key ) ) {
			setOpen( true );
		} else if ( twice && 'c' === key ) {
			setOpen( false );
		}
	} );

	acf.addAction( 'ready', leaveHiddenTab );
}() );
