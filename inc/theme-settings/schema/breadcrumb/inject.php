<?php

// Prints a BreadcrumbList JSON-LD block, computed from the current
// request's position in WordPress's own content hierarchy — never from
// admin-entered data. Deliberately covers only the common, unambiguous
// cases (hierarchical pages, a CPT's archive, a taxonomy term); anything
// more specific (category-based trails for blog posts, custom multi-level
// taxonomy chains) is a per-project customization, not a base-theme concern.
add_action( 'wp_head', function () {
	if ( ! function_exists( 'get_field' ) ) {
		return;
	}

	if ( ! get_field( 'breadcrumb_enabled', 'option' ) ) {
		return;
	}

	// No meaningful trail on these: the front page has nothing above it,
	// and search/404 aren't a fixed position in the content hierarchy.
	if ( is_front_page() || is_search() || is_404() ) {
		return;
	}

	$items = array(
		array(
			'name' => __( 'Home', 'devbase_parent_theme' ),
			'url'  => home_url( '/' ),
		),
	);

	if ( is_singular() ) {
		global $post;

		if ( is_post_type_hierarchical( get_post_type( $post ) ) ) {
			// Walk from the top-most ancestor down to (but not including)
			// the current post — get_post_ancestors() returns nearest-first.
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
				$items[] = array(
					'name' => get_the_title( $ancestor_id ),
					'url'  => get_permalink( $ancestor_id ),
				);
			}
		} else {
			$post_type_object = get_post_type_object( get_post_type( $post ) );
			if ( $post_type_object && $post_type_object->has_archive ) {
				$archive_link = get_post_type_archive_link( $post_type_object->name );
				if ( $archive_link ) {
					$items[] = array(
						'name' => $post_type_object->labels->name,
						'url'  => $archive_link,
					);
				}
			}
		}

		// Current page — no 'url', per Google's own guidance: the last
		// item represents the page the trail is already on.
		$items[] = array( 'name' => get_the_title( $post ) );
	} elseif ( is_post_type_archive() ) {
		$items[] = array( 'name' => post_type_archive_title( '', false ) );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$items[] = array( 'name' => $term->name );
		}
	} elseif ( is_home() ) {
		// Blog index when it's not also the front page (a static front
		// page with a separate "posts page" set under Settings > Reading).
		$items[] = array( 'name' => get_the_title( get_option( 'page_for_posts' ) ) );
	}

	// Just "Home" isn't a breadcrumb worth printing.
	if ( count( $items ) < 2 ) {
		return;
	}

	$list_items = array();
	$last_index = count( $items ) - 1;

	foreach ( $items as $index => $item ) {
		$list_item = array(
			'@type'    => 'ListItem',
			'position' => $index + 1,
			'name'     => $item['name'],
		);

		if ( $index !== $last_index && ! empty( $item['url'] ) ) {
			$list_item['item'] = $item['url'];
		}

		$list_items[] = $list_item;
	}

	$schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $list_items,
	);

	// Default json_encode() escapes forward slashes ("\/"), which is what
	// keeps a literal "</script>" inside a page/term title from being able
	// to close this tag early — deliberately not passing
	// JSON_UNESCAPED_SLASHES here (same reasoning as Organization's inject.php).
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );
