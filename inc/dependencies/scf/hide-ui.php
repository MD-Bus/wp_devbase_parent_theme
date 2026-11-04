<?php

// Hides Secure Custom Fields' own screens: the Custom Fields menu with its field groups, post types,
// taxonomies, options pages and tools. The switch is "Hide Secure Custom Fields" (Theme Settings >
// Miscellaneous > Management), ON by default, so a client never changes the site's structure by accident.
//
// This uses SCF's own setting, `acf/settings/show_admin`, which is also how ACF documents hiding its
// interface. It closes the screens as well as hiding the menu (they are not reachable by address),
// so editing a field group means switching this off first. Nothing the theme built is affected:
// field groups, options pages (Theme Settings), blocks and the abilities all keep working.

/** Whether SCF's screens are hidden (a filter can decide it in code). */
function devbase_scf_ui_hidden() {
	return (bool) apply_filters( 'devbase/scf/hide_ui', devbase_switch_on( 'hide_scf_ui' ) );
}

add_filter( 'acf/settings/show_admin', function ( $show ) {
	return devbase_scf_ui_hidden() ? false : $show;
} );
