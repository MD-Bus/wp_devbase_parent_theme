<?php

// The Archive sections of the Theme Settings app: one section per post type archive (the options pages inc/archive/settings.php
// generates), so an Editor edits an archive's fields without the separate Archive menu.
//
// Each is a FIELD section (see app.php): it renders the field groups whose location is `options_page == archive-{type}`,
// against that page's OWN store (`post_id` = the page slug, so two archives never share a value), with the capability
// those pages already use (`devbase/archive/capability`, edit_pages by default), not the administrator-only one.
// A page with no field group is left out: there is nothing to edit.

/**
 * The Archive options pages that exist: one `archive-{post_type}` page per public post type with an archive
 * (inc/archive/settings.php). Found by post type, NOT by parent_slug: SCF rewrites a sub-page's parent to the first
 * sub-page's own slug when it promotes it, so `parent_slug === 'archive-settings'` is not reliable.
 *
 * @return array<string,array> Page slug => SCF options page.
 */
function devbase_app_archive_pages() {
	$pages = array();

	if ( ! function_exists( 'acf_get_options_page' ) ) {
		return $pages;
	}

	foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ) ) as $type ) {
		$page = acf_get_options_page( 'archive-' . $type );

		if ( is_array( $page ) ) {
			$pages[ 'archive-' . $type ] = $page;
		}
	}

	return $pages;
}

add_filter( 'devbase/app/sections', function ( $sections ) {
	if ( ! is_array( $sections ) || ! function_exists( 'acf_get_field_groups' ) ) {
		return $sections;
	}

	foreach ( devbase_app_archive_pages() as $slug => $page ) {
		$keys = array();

		foreach ( acf_get_field_groups( array( 'options_page' => $slug ) ) as $group ) {
			$keys[] = $group['key'];
		}

		if ( ! $keys ) {
			continue;
		}

		$sections[ $slug ] = array(
			'title'       => (string) ( $page['page_title'] ?? $slug ),
			'icon'        => 'dashicons-archive',
			'description' => __( 'The text and settings shown on this archive page.', 'devbase_parent_theme' ),
			'group'       => 'content',
			'capability'  => devbase_archive_settings_capability(),
			'groups'      => $keys,
			'post_id'     => (string) ( $page['post_id'] ?? $slug ),
			'classic'     => $slug,
		);
	}

	return $sections;
}, 5 );
