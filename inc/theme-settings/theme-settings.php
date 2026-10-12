<?php

// Registers an empty SCF options page under Settings > Theme Settings.
// No fields yet — this just creates the page itself. Add fields later via
// an SCF field group with its location set to this options page
// (menu_slug: theme-settings), same as any other ACF/SCF field group.
//
// Title is wrapped in __() so it can vary by site language (see
// inc/i18n.php for how translations are loaded) — e.g. a French
// translation file could map this to "Réglages du thème" without any
// code change here.
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		return;
	}

	$icon_path = get_template_directory() . '/assets/icons/devbase-b-icon.svg';
	$icon_url  = '';

	// WP admin menu icons take an inline data: URI (not a plain file URL) so
	// the browser doesn't need an extra request just for a tiny sidebar icon.
	if ( file_exists( $icon_path ) ) {
		$icon_url = 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon_path ) );
	}

	acf_add_options_page( array(
		'page_title' => __( 'Theme Settings', 'devbase_parent_theme' ),
		'menu_title' => __( 'Theme Settings', 'devbase_parent_theme' ),
		'menu_slug'  => 'theme-settings',
		'icon_url'   => $icon_url,
	) );
} );
