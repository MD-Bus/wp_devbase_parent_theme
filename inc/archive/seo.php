<?php

// SEO title and description for post type archives (News, Events, ...).
//
// An archive has no post, so there is no post screen to put a title and description on: the only
// place its settings live is its Archive options page (settings.php), stored under
// `archive-{post_type}`. This adds an "SEO" tab of two fields to that page and hands them to The SEO
// Framework (TSF), which prints every tag.
//
// WHERE THE FIELDS GO. A real tab inside the field group that already targets the page
// (a child theme's Banner / List / Search groups, say), added as local fields whose
// `parent` is that group. Where no local group targets the page, the same two fields get a
// small group of their own called "SEO". Either way the fields are the same, so
// `seo_title` and `seo_description` read the same everywhere, and MCP can set them.
//
// WHAT TSF DOES WITH THEM, and only when a field is filled (empty means TSF decides as before):
//
// - The title, through TSF's `the_seo_framework_title_from_custom_field`. TSF adds the " - Page N"
//   on page 2 onward and its own site-name branding by its own settings, to this title as to any.
// - The description, through `the_seo_framework_custom_field_description`, on the FIRST page only
//   (a copy of it on every page of an archive is what search engines flag).
// - TSF prints the title, description, Open Graph and Twitter tags from them, so a shared link says
//   what the search result says. A value filled here wins over TSF's own "Post Type Archive
//   Settings" for the same archive: use this tab, not both.
//
// THE SITEMAP. TSF's sitemap lists posts only, never an archive. Every archive is added to it through
// `the_seo_framework_sitemap_additional_urls` (see devbase_archive_sitemap_urls()), unless TSF has its type
// disabled, it is noindex, or it has no published posts. This does not depend on the fields above.
//
// Filters: `devbase/archive_seo/enabled` ( bool, $post_type ) switches the fields off for a type;
// `devbase/archive_seo/sitemap` ( bool, $post_type ) leaves its archive out of the sitemap.

/**
 * Adds the SEO fields to an archive's options page. Called once per archive from the loop in
 * settings.php. Safe to call twice for the same type.
 */
function devbase_archive_seo_register_fields( $post_type, $menu_slug ) {
	static $done = array();

	// Only The SEO Framework reads these fields: while it is off (the default; Miscellaneous > SEO) there is no SEO tab.
	if ( devbase_tsf_disabled() || isset( $done[ $post_type ] ) || ! function_exists( 'acf_add_local_field' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$done[ $post_type ] = true;

	$fields = array(
		array(
			'key'       => "field_devbase_archive_seo_note_{$post_type}",
			'label'     => '',
			'name'      => '',
			'type'      => 'message',
			'message'   => __( 'Use this tab for this archive\'s search title and description. The same settings in The SEO Framework\'s own screens are not needed: a value filled here is the one that is used.', 'devbase_parent_theme' ),
			'new_lines' => 'wpautop',
			'esc_html'  => 0,
		),
		array(
			'key'          => "field_devbase_archive_seo_title_{$post_type}",
			'label'        => __( 'Search Title', 'devbase_parent_theme' ),
			'name'         => 'seo_title',
			'type'         => 'text',
			'maxlength'    => 70,
			'instructions' => __( 'The headline in search results and on the browser tab. About 50 to 60 characters. Put the main phrase people would search for first. Leave empty to use the default.', 'devbase_parent_theme' ),
		),
		array(
			'key'          => "field_devbase_archive_seo_description_{$post_type}",
			'label'        => __( 'Search Description', 'devbase_parent_theme' ),
			'name'         => 'seo_description',
			'type'         => 'textarea',
			'rows'         => 3,
			'new_lines'    => '',
			'maxlength'    => 170,
			'instructions' => __( 'The short text under the headline in search results. About 120 to 155 characters: say what the visitor will find here and why to click. It shows on the first page of the list only. Leave empty to use the default.', 'devbase_parent_theme' ),
		),
	);

	// A real tab in the group that already holds this page's fields, if there is a local one.
	if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_is_local_field_group' ) ) {
		foreach ( (array) acf_get_field_groups( array( 'options_page' => $menu_slug ) ) as $group ) {
			if ( ! empty( $group['key'] ) && acf_is_local_field_group( $group['key'] ) ) {
				acf_add_local_field( array(
					'key'        => "field_devbase_archive_seo_tab_{$post_type}",
					'label'      => __( 'SEO', 'devbase_parent_theme' ),
					'name'       => '',
					'type'       => 'tab',
					'placement'  => 'top',
					'parent'     => $group['key'],
					'menu_order' => 900,
				) );

				foreach ( $fields as $i => $field ) {
					acf_add_local_field( $field + array(
						'parent'     => $group['key'],
						'menu_order' => 901 + $i,
					) );
				}

				return;
			}
		}
	}

	acf_add_local_field_group( array(
		'key'      => "group_devbase_archive_seo_{$post_type}",
		'title'    => __( 'SEO', 'devbase_parent_theme' ),
		'fields'   => $fields,
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $menu_slug ) ) ),
	) );
}

/**
 * The post type an SEO value is asked for. TSF passes its query arguments (`pta` is the post type of
 * an archive) or null when it is working out the current request, which is then the archive being
 * viewed. Empty string when this is not an archive.
 */
function devbase_archive_seo_post_type( $args ) {
	if ( is_array( $args ) ) {
		$post_type = $args['pta'] ?? '';
	} elseif ( ! is_admin() && is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
	} else {
		return '';
	}

	return is_string( $post_type ) ? $post_type : '';
}

/**
 * One saved SEO field of an archive ('seo_title' or 'seo_description'), trimmed. Empty string when it is
 * not filled, the request is not an archive, or the filter switched the archive off.
 */
function devbase_archive_seo_value( $field, $args ) {
	$post_type = devbase_archive_seo_post_type( $args );

	if ( '' === $post_type || ! function_exists( 'get_field' ) ) {
		return '';
	}

	$object = get_post_type_object( $post_type );

	if ( ! $object || ! $object->public || ! $object->has_archive || ! apply_filters( 'devbase/archive_seo/enabled', true, $post_type ) ) {
		return '';
	}

	return trim( (string) get_field( $field, 'archive-' . $post_type ) );
}

if ( ! devbase_tsf_disabled() ) :

add_filter( 'the_seo_framework_title_from_custom_field', function ( $title, $args = null ) {
	$own = devbase_archive_seo_value( 'seo_title', $args );

	return '' !== $own ? $own : $title;
}, 10, 2 );

add_filter( 'the_seo_framework_custom_field_description', function ( $description, $args = null ) {
	$own = devbase_archive_seo_value( 'seo_description', $args );

	// First page only when TSF is working out the current request; later pages keep TSF's own.
	if ( '' === $own || ( ! is_array( $args ) && max( 1, (int) get_query_var( 'paged' ) ) > 1 ) ) {
		return $description;
	}

	return $own;
}, 10, 2 );

/**
 * The post type archives that belong in the sitemap, as url => [ 'lastmod' => GMT datetime ]. TSF's optimized
 * sitemap lists posts only: an archive is not a post, so it never appears there on its own. Included: every
 * public post type with an archive that TSF supports (its type not disabled in TSF's Post Types settings) and
 * that is not noindex (TSF's "Post Type Archive Settings" or its global robots settings), and that has at least
 * one published post (an empty archive is a thin page). `lastmod` is the newest modified date among its posts.
 * `devbase/archive_seo/sitemap` ( bool, $post_type ) leaves one out.
 */
function devbase_archive_sitemap_urls() {
	global $wpdb;

	if ( ! function_exists( 'tsf' ) ) {
		return array();
	}

	$urls = array();

	foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ) ) as $post_type ) {
		if ( ! tsf()->post_type()->is_pta_supported( $post_type ) || ! apply_filters( 'devbase/archive_seo/sitemap', true, $post_type ) ) {
			continue;
		}

		$robots = tsf()->robots()->get_generated_meta( array( 'id' => 0, 'tax' => '', 'pta' => $post_type, 'uid' => 0 ), array( 'noindex' ) );

		if ( ! empty( $robots['noindex'] ) ) {
			continue;
		}

		$lastmod = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );

		$url = tsf()->uri()->get_bare_pta_url( $post_type );

		if ( ! $lastmod || ! $url ) {
			continue;
		}

		$urls[ $url ] = array( 'lastmod' => $lastmod );
	}

	return $urls;
}

add_filter( 'the_seo_framework_sitemap_additional_urls', function ( $urls ) {
	return array_merge( (array) $urls, devbase_archive_sitemap_urls() );
} );

endif; // devbase_tsf_disabled(): none of these filters is registered while TSF is switched off.
