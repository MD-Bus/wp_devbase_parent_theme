<?php

// SEO title and description for post type archives (News, Events, ...).
//
// An archive has no post, so no SEO plugin can attach a title and description to it:
// the only place its settings live is its Archive options page (settings.php), stored
// under `archive-{post_type}`. This adds an "SEO" tab of two fields to that page and prints
// them on the real archive URL.
//
// WHERE THE FIELDS GO. A real tab inside the field group that already targets the page
// (a child theme's Banner / List / Search groups, say), added as local fields whose
// `parent` is that group. Where no local group targets the page, the same two fields get a
// small group of their own called "SEO". Either way the fields are the same, so
// `seo_title` and `seo_description` read the same everywhere, and MCP can set them.
//
// WHAT IT PRINTS, and only when a field is filled (empty means the SEO plugin, or WordPress,
// decides as before):
//
// - The document title, through `pre_get_document_title` at the last priority. On page 2 and
//   after, " – Page N" is added, so paged archives do not share one title.
// - The meta description, on the FIRST page only (a copy of it on every page of an archive is
//   what search engines flag). It does not matter whether an SEO plugin is active: wp_head is
//   buffered for archive requests, and an existing description tag is REPLACED, or one is
//   added if there is none, so there is never a second tag. The Open Graph and Twitter
//   title and description tags are replaced too, when something has printed them, so a shared
//   link says what the search result says. Nothing is added to those.
//
// Filters: `devbase/archive_seo/enabled` ( bool, $post_type ) switches it off for a type.

/**
 * Adds the SEO fields to an archive's options page. Called once per archive from the loop in
 * settings.php. Safe to call twice for the same type.
 */
function devbase_archive_seo_register_fields( $post_type, $menu_slug ) {
	static $done = array();

	if ( isset( $done[ $post_type ] ) || ! function_exists( 'acf_add_local_field' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$done[ $post_type ] = true;

	$fields = array(
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
 * The SEO values for the archive being viewed, or null when this is not an archive with
 * something filled in (or the filter switched it off).
 *
 * @return array|null { title: string, description: string, paged: int }
 */
function devbase_archive_seo_current() {
	if ( is_admin() || ! function_exists( 'get_field' ) || ! is_post_type_archive() ) {
		return null;
	}

	$post_type = get_query_var( 'post_type' );
	$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;

	if ( ! is_string( $post_type ) || '' === $post_type ) {
		return null;
	}

	$object = get_post_type_object( $post_type );

	if ( ! $object || ! $object->public || ! $object->has_archive || ! apply_filters( 'devbase/archive_seo/enabled', true, $post_type ) ) {
		return null;
	}

	$title       = trim( (string) get_field( 'seo_title', 'archive-' . $post_type ) );
	$description = trim( (string) get_field( 'seo_description', 'archive-' . $post_type ) );

	if ( '' === $title && '' === $description ) {
		return null;
	}

	return array(
		'title'       => $title,
		'description' => $description,
		'paged'       => max( 1, (int) get_query_var( 'paged' ) ),
	);
}

/**
 * The title to print: the field, with " – Page N" after the first page.
 */
function devbase_archive_seo_title( array $seo ) {
	if ( $seo['paged'] > 1 ) {
		/* translators: 1: an archive's search title, 2: the page number of the list. */
		return sprintf( __( '%1$s – Page %2$d', 'devbase_parent_theme' ), $seo['title'], $seo['paged'] );
	}

	return $seo['title'];
}

add_filter( 'pre_get_document_title', function ( $title ) {
	$seo = devbase_archive_seo_current();

	return ( $seo && '' !== $seo['title'] ) ? devbase_archive_seo_title( $seo ) : $title;
}, PHP_INT_MAX );

/**
 * Sets the content of an existing <meta> tag (matched by name= or property=). Returns the
 * changed HTML, and whether a tag was found. Never adds a tag.
 */
function devbase_archive_seo_replace_meta( $html, $attribute, $name, $content, &$found = null ) {
	$pattern = '#<meta\s+[^>]*?\b' . $attribute . '\s*=\s*(["\'])' . preg_quote( $name, '#' ) . '\1[^>]*>#i';
	$tag     = '<meta ' . $attribute . '="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '" />';
	$count   = 0;
	$html    = preg_replace_callback( $pattern, function () use ( $tag ) {
		return $tag;
	}, $html, 1, $count );
	$found   = $count > 0;

	return $html;
}

/**
 * The printed <head>, with this archive's title and description in it.
 */
function devbase_archive_seo_rewrite_head( $html, array $seo ) {
	if ( '' !== $seo['title'] ) {
		$title = devbase_archive_seo_title( $seo );

		$html = preg_replace_callback( '#<title\b[^>]*>.*?</title>#is', function () use ( $title ) {
			return '<title>' . esc_html( $title ) . '</title>';
		}, $html, 1 );

		$html = devbase_archive_seo_replace_meta( $html, 'property', 'og:title', $title );
		$html = devbase_archive_seo_replace_meta( $html, 'name', 'twitter:title', $title );
	}

	// The description is for the first page only; later pages keep whatever was printed.
	if ( '' !== $seo['description'] && 1 === $seo['paged'] ) {
		$found = false;
		$html  = devbase_archive_seo_replace_meta( $html, 'name', 'description', $seo['description'], $found );

		if ( ! $found ) {
			$html .= '<meta name="description" content="' . esc_attr( $seo['description'] ) . '" />' . "\n";
		}

		$html = devbase_archive_seo_replace_meta( $html, 'property', 'og:description', $seo['description'] );
		$html = devbase_archive_seo_replace_meta( $html, 'name', 'twitter:description', $seo['description'] );
	}

	return $html;
}

// Buffer everything printed in wp_head on an archive that has SEO values, then rewrite it.
// Opened before anything else prints and closed after everything has.
add_action( 'wp_head', function () {
	unset( $GLOBALS['devbase_archive_seo_level'] );

	if ( devbase_archive_seo_current() ) {
		ob_start();
		$GLOBALS['devbase_archive_seo_level'] = ob_get_level();
	}
}, 0 );

add_action( 'wp_head', function () {
	if ( empty( $GLOBALS['devbase_archive_seo_level'] ) ) {
		return;
	}

	$level = $GLOBALS['devbase_archive_seo_level'];
	unset( $GLOBALS['devbase_archive_seo_level'] );

	// Anything left open inside wp_head is closed first, so ours is the buffer read.
	while ( ob_get_level() > $level ) {
		ob_end_flush();
	}

	$html = (string) ob_get_clean();
	$seo  = devbase_archive_seo_current();

	echo $seo ? devbase_archive_seo_rewrite_head( $html, $seo ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}, PHP_INT_MAX );
