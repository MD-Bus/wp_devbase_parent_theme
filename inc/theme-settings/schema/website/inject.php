<?php

// Prints the WebSite JSON-LD block sitewide. Unlike Organization, almost
// nothing here comes from an admin field — name, url, and description are
// all derived from WordPress itself (or from Organization's own data, if
// that's enabled), which is the whole point of Website being one of the
// "always need it, build it automatically" schema types.
add_action( 'wp_head', function () {
	if ( ! function_exists( 'get_field' ) ) {
		return;
	}

	if ( ! get_field( 'website_enabled', 'option' ) ) {
		return;
	}

	$name = get_bloginfo( 'name' );
	if ( '' === trim( (string) $name ) ) {
		return;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@type'    => 'WebSite',
		'name'     => $name,
		'url'      => home_url( '/' ),
	);

	// Reuse Organization's description rather than asking for it twice —
	// falls back to the site tagline if Organization isn't enabled/filled.
	$description = '';
	if ( get_field( 'organization_enabled', 'option' ) ) {
		$description = trim( (string) get_field( 'organization_description', 'option' ) );
	}
	if ( '' === $description ) {
		$description = trim( (string) get_bloginfo( 'description' ) );
	}
	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	// A WebSite's publisher is the Organization behind it — link the two
	// rather than duplicating Organization's fields here.
	if ( get_field( 'organization_enabled', 'option' ) ) {
		$org_name = trim( (string) get_field( 'organization_name', 'option' ) );
		if ( '' !== $org_name ) {
			$publisher = array(
				'@type' => 'Organization',
				'name'  => $org_name,
			);

			$org_url = trim( (string) get_field( 'organization_url', 'option' ) );
			if ( '' !== $org_url ) {
				$publisher['url'] = $org_url;
			}

			$org_logo = get_field( 'organization_logo', 'option' );
			if ( $org_logo ) {
				$publisher['logo'] = $org_logo;
			}

			$schema['publisher'] = $publisher;
		}
	}

	if ( get_field( 'website_search_enabled', 'option' ) ) {
		$schema['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		);
	}

	// Default json_encode() escapes forward slashes ("\/"), which is what
	// keeps a literal "</script>" inside e.g. the site tagline from being
	// able to close this tag early — deliberately not passing
	// JSON_UNESCAPED_SLASHES here (same reasoning as Organization's inject.php).
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );
