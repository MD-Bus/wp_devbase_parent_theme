<?php

// Hides Posts and Comments from the wp-admin sidebar menu while this theme
// is active — most of our sites don't use the built-in blog/comments.
// Purely presentational: nothing is stored anywhere, so switching away from
// or deleting this theme restores the default menu automatically, with
// nothing to clean up.
//
// Filterable because this is one of the few parent-theme defaults that is
// genuinely wrong for SOME client sites — a site that really does run a
// blog needs Posts and Comments back. A child theme adjusts the list with
// a plain top-level add_filter() in its own functions.php (that file loads
// before this one, so the filter is always registered in time):
//
//     // Keep the blog on this client's site.
//     add_filter( 'devbase/admin_menu/hidden_items', function ( $items ) {
//         return array_diff( $items, array( 'edit.php', 'edit-comments.php' ) );
//     } );
//
// Returning an empty array hides nothing at all.
function devbase_hidden_admin_menu_items() {
	$items = apply_filters(
		'devbase/admin_menu/hidden_items',
		array(
			'edit.php',          // Posts.
			'edit-comments.php', // Comments.
		)
	);

	return is_array( $items ) ? $items : array();
}

add_action( 'admin_menu', function () {
	foreach ( devbase_hidden_admin_menu_items() as $menu_slug ) {
		remove_menu_page( $menu_slug );
	}
}, 999 );
