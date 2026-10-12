<?php

// The WP internal link picker (used by the classic editor link dialog and
// by ACF/SCF link fields) only ever searches actual posts — it has no way
// to offer a CPT archive URL, since an archive isn't a row in wp_posts.
// This injects a synthetic result for each public CPT archive that matches
// the current search term, so archive links show up in that picker like
// any other page would.
add_filter( 'wp_link_query', function ( $results, $query ) {
	// Only add on the first page of results — pagenum isn't passed to this
	// filter, but a non-zero offset means we're past page 1.
	if ( ! empty( $query['offset'] ) ) {
		return $results;
	}

	$search = isset( $query['s'] ) ? trim( (string) $query['s'] ) : '';

	$post_types = get_post_types( array(
		'public'      => true,
		'has_archive' => true,
	), 'objects' );

	foreach ( $post_types as $post_type ) {
		$label = $post_type->labels->name;

		if ( '' !== $search && false === stripos( $label, $search ) && false === stripos( $post_type->name, $search ) ) {
			continue;
		}

		$archive_link = get_post_type_archive_link( $post_type->name );

		if ( ! $archive_link ) {
			continue;
		}

		$results[] = array(
			// Negative, stable per post type — real post IDs are always
			// positive, so this can't collide with an actual post.
			'ID'        => -1 * ( crc32( $post_type->name ) % 1000000 ),
			'title'     => $label,
			'permalink' => $archive_link,
			'info'      => __( 'Archive', 'devbase_parent_theme' ),
		);
	}

	return $results;
}, 10, 2 );
