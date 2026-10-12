<?php

// Registers a parent "Archive" options page, with one sub-page auto-added
// for every public post type that has an archive. This is how fields get
// attached to a CPT archive: SCF/ACF has no location rule for "post type
// archive", so each archive gets a dedicated options page instead, and a
// field group can target it via location `options_page == archive-{post_type}`.
// Pages start empty — same pattern as inc/theme-settings/theme-settings.php.
//
// Hooked on `wp_loaded`, not `acf/init` like the other options pages in
// this theme: `acf/init` fires from inside WP's own `init` hook at
// priority 5, before a plain `register_post_type()` call on the default
// `init` priority (10) has run — that CPT would exist too late to be
// picked up by get_post_types() below and would silently get no archive
// sub-page. `acf_add_options_page()`/`acf_add_options_sub_page()` just
// queue config into an array that SCF reads on `admin_menu` priority 99
// (see class-acf-admin-options-page.php), so anything before that works —
// `wp_loaded` runs after every `init`-priority post type registration.
add_action( 'wp_loaded', function () {
	if ( ! function_exists( 'acf_add_options_page' ) || ! function_exists( 'acf_add_options_sub_page' ) ) {
		return;
	}

	acf_add_options_page( array(
		'page_title' => __( 'Archive', 'devbase_parent_theme' ),
		'menu_title' => __( 'Archive', 'devbase_parent_theme' ),
		'menu_slug'  => 'archive-settings',
		'icon_url'   => 'dashicons-archive',
	) );

	$post_types = get_post_types( array(
		'public'      => true,
		'has_archive' => true,
	), 'objects' );

	foreach ( $post_types as $post_type ) {
		$menu_slug = 'archive-' . $post_type->name;

		acf_add_options_sub_page( array(
			'page_title'  => $post_type->labels->name,
			'menu_title'  => $post_type->labels->name,
			'menu_slug'   => $menu_slug,
			'parent_slug' => 'archive-settings',
			// Every options page defaults to the SAME shared 'options'
			// data store unless told otherwise — without this, two CPT
			// archives with a same-named field would silently read/write
			// each other's value. Keying storage to the page's own slug
			// is what actually makes get_field('x', 'archive-{post_type}')
			// isolated per archive.
			'post_id'     => $menu_slug,
		) );
	}
} );
