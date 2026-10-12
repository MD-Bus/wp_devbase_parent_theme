<?php

// Prints the Organization JSON-LD block sitewide, built from the fields on
// the Schema Markup sub-page (see settings.php in this folder). Injected
// in wp_head — schema.org/Google have no preference between head/body/
// footer, but head is where every other <script type="application/ld+json">
// on the web lives, and it means the block is present even if a page's
// footer never renders (an early template exit, a fatal in a widget, etc.).
//
// Nothing is printed unless the toggle is on AND both required fields
// (Name, URL) are actually filled — a half-configured toggle should never
// ship broken/empty structured data to search engines.
add_action( 'wp_head', function () {
	if ( ! function_exists( 'get_field' ) ) {
		return;
	}

	if ( ! get_field( 'organization_enabled', 'option' ) ) {
		return;
	}

	$name = trim( (string) get_field( 'organization_name', 'option' ) );
	$url  = trim( (string) get_field( 'organization_url', 'option' ) );
	$url  = '' !== $url ? $url : home_url( '/' );

	if ( '' === $name || '' === $url ) {
		return;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'name'     => $name,
		'url'      => $url,
	);

	$logo = get_field( 'organization_logo', 'option' );
	if ( $logo ) {
		$schema['logo'] = $logo;
	}

	$description = trim( (string) get_field( 'organization_description', 'option' ) );
	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	$slogan = trim( (string) get_field( 'organization_slogan', 'option' ) );
	if ( '' !== $slogan ) {
		$schema['slogan'] = $slogan;
	}

	$founding_date = get_field( 'organization_founding_date', 'option' );
	if ( $founding_date ) {
		$schema['foundingDate'] = $founding_date;
	}

	$knows_about = array();
	foreach ( (array) get_field( 'organization_knows_about', 'option' ) as $row ) {
		$topic = trim( (string) ( $row['topic'] ?? '' ) );
		if ( '' !== $topic ) {
			$knows_about[] = $topic;
		}
	}
	if ( ! empty( $knows_about ) ) {
		$schema['knowsAbout'] = $knows_about;
	}

	$same_as = array();
	foreach ( (array) get_field( 'organization_same_as', 'option' ) as $row ) {
		$row_url = trim( (string) ( $row['url'] ?? '' ) );
		if ( '' !== $row_url ) {
			$same_as[] = $row_url;
		}
	}
	if ( ! empty( $same_as ) ) {
		$schema['sameAs'] = $same_as;
	}

	// Organization schema has one `address` — multiple Locations rows
	// exist for the site's own content needs, but only the row flagged
	// "Main" (or the first row, if none is flagged) represents this
	// Organization. See organization/AGENT.md for why the rest aren't
	// output here.
	$locations = (array) get_field( 'organization_locations', 'option' );
	$main      = null;
	foreach ( $locations as $location ) {
		if ( ! empty( $location['is_main'] ) ) {
			$main = $location;
			break;
		}
	}
	if ( ! $main && ! empty( $locations ) ) {
		$main = $locations[0];
	}

	if ( $main ) {
		$address = array_filter( array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => trim( (string) ( $main['street_address'] ?? '' ) ),
			'addressLocality' => trim( (string) ( $main['address_locality'] ?? '' ) ),
			'addressRegion'   => trim( (string) ( $main['address_region'] ?? '' ) ),
			'postalCode'      => trim( (string) ( $main['postal_code'] ?? '' ) ),
			'addressCountry'  => trim( (string) ( $main['address_country'] ?? '' ) ),
		) );

		// array_filter() on an all-empty address would otherwise leave a
		// bare {"@type": "PostalAddress"} — only keep it if something
		// besides @type actually survived.
		if ( count( $address ) > 1 ) {
			$schema['address'] = $address;
		}
	}

	// Default json_encode() escapes forward slashes ("\/"), which is what
	// keeps a literal "</script>" inside e.g. the description field from
	// being able to close this tag early — deliberately not passing
	// JSON_UNESCAPED_SLASHES here.
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );
