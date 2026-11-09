<?php

// Merges this site's Organization Details (settings.php) into the entity TSF's JSON-LD graph holds for who the site
// represents: an Organization (the default) or a Person. TSF prints the graph; this changes the entity it already built, so
// there is one such entity on the page, never two.
//
// WHICH TYPE. The select on the page (`organization_type`). TSF's own switch for it lives on its "Presence" tab, which the
// theme hides (inc/dependencies/tsf/tsf.php), so the select drives TSF's `knowledge_type` through its
// `the_seo_framework_get_options` filter, at read time: nothing is written to TSF's settings. Only when the select was actually
// saved: a site that never touched it (or runs its own TSF with its own choice) keeps TSF's. Extras are merged only into the
// node of the type the page says; a Person node on a site whose page says Organization is left alone.
//
// TWO KINDS OF KEY. The few things TSF has its own setting for are OVERRIDES: a value filled on our page replaces TSF's, an
// empty one leaves TSF's (Organization: name, url, logo, sameAs. Person: name, url, image, sameAs). Everything else is something
// TSF never prints, so it is only ever ADDED: a key TSF already printed is never replaced.
//
// Hook: `the_seo_framework_schema_graph_data` (TSF's last filter on the graph). TSF puts the entity either as a top-level node,
// or INLINE inside whatever refers to it (the WebSite's `publisher`) when only one thing refers to it. Both shapes are patched, at
// any depth.

/**
 * One saved Organization Details value, read BY FIELD KEY ('field_devbase_schema_' . $key).
 *
 * Asked by name, ACF follows the `_options_{name}` reference row saved next to the value, and returns the
 * raw stored string (a repeater's row count) when that row names a key this theme no longer has: a site
 * saved before the theme was renamed still has `field_boostway_...` there. The key finds the field
 * directly, so the saved value is read as what it is, and nothing in the database has to change.
 */
function devbase_schema_field( $key ) {
	return get_field( 'field_devbase_schema_' . $key, 'option' );
}

/** Kept for the Organization fields' original helper name: `organization_` + $key. */
function devbase_schema_organization_field( $key ) {
	return devbase_schema_field( 'organization_' . $key );
}

/**
 * The type the page says the site represents: 'organization' (also when it was never saved) or 'person'. Read from the raw
 * option on purpose: TSF asks for its settings early, before ACF has registered the fields, and a select's value needs no
 * reference to be read.
 */
function devbase_schema_site_type() {
	return 'person' === get_option( 'options_organization_type' ) ? 'person' : 'organization';
}

/**
 * Whether the page's select was actually saved ('organization' or 'person'), as opposed to never touched.
 */
function devbase_schema_site_type_is_saved() {
	return in_array( get_option( 'options_organization_type' ), array( 'organization', 'person' ), true );
}

/** An image field's saved URL as a schema.org ImageObject, or null when empty. */
function devbase_schema_image_object( $url ) {
	$url = is_string( $url ) ? trim( $url ) : '';

	return '' === $url ? null : array(
		'@type'      => 'ImageObject',
		'url'        => $url,
		'contentUrl' => $url,
	);
}

/**
 * What an Organization and a Person share: name, url, profile links, description, areas of expertise. Only those filled.
 *
 * @return array<string, mixed>
 */
function devbase_schema_shared_extras() {
	if ( ! function_exists( 'get_field' ) ) {
		return array();
	}

	$extras = array();

	$name = trim( (string) devbase_schema_organization_field( 'name' ) );
	if ( '' !== $name ) {
		$extras['name'] = $name;
	}

	$url = trim( (string) devbase_schema_organization_field( 'url' ) );
	if ( '' !== $url ) {
		$extras['url'] = $url;
	}

	$same_as = array();
	foreach ( (array) devbase_schema_organization_field( 'same_as' ) as $row ) {
		$link = trim( (string) ( is_array( $row ) ? ( $row['url'] ?? '' ) : '' ) );
		if ( '' !== $link ) {
			$same_as[] = $link;
		}
	}
	if ( $same_as ) {
		$extras['sameAs'] = $same_as;
	}

	$description = trim( (string) devbase_schema_organization_field( 'description' ) );
	if ( '' !== $description ) {
		$extras['description'] = $description;
	}

	$knows_about = array();
	foreach ( (array) devbase_schema_organization_field( 'knows_about' ) as $row ) {
		$topic = trim( (string) ( is_array( $row ) ? ( $row['topic'] ?? '' ) : '' ) );
		if ( '' !== $topic ) {
			$knows_about[] = $topic;
		}
	}
	if ( $knows_about ) {
		$extras['knowsAbout'] = $knows_about;
	}

	return $extras;
}

/**
 * The Organization's extra properties from the saved fields, only those that are filled.
 *
 * @return array<string, mixed>
 */
function devbase_schema_organization_extras() {
	if ( ! function_exists( 'get_field' ) ) {
		return array();
	}

	$extras = devbase_schema_shared_extras();

	// The field returns a URL (PNG or JPG, never SVG: Google's Knowledge Panel does not read it).
	$logo = devbase_schema_image_object( devbase_schema_organization_field( 'logo' ) );
	if ( $logo ) {
		$extras['logo'] = $logo;
	}

	$slogan = trim( (string) devbase_schema_organization_field( 'slogan' ) );
	if ( '' !== $slogan ) {
		$extras['slogan'] = $slogan;
	}

	$founding_date = trim( (string) devbase_schema_organization_field( 'founding_date' ) );
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $founding_date, $m ) ) {
		$founding_date = "{$m[1]}-{$m[2]}-{$m[3]}"; // schema.org wants ISO 8601; some values were saved as Ymd.
	}
	if ( '' !== $founding_date ) {
		$extras['foundingDate'] = $founding_date;
	}

	// Organization schema has one `address`: the row flagged "Main", or the first row when none is.
	$locations = array_values( (array) devbase_schema_organization_field( 'locations' ) );
	$main      = $locations[0] ?? null;
	foreach ( $locations as $location ) {
		if ( ! empty( $location['is_main'] ) ) {
			$main = $location;
			break;
		}
	}

	if ( is_array( $main ) ) {
		$address = array_filter( array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => trim( (string) ( $main['street_address'] ?? '' ) ),
			'addressLocality' => trim( (string) ( $main['address_locality'] ?? '' ) ),
			'addressRegion'   => trim( (string) ( $main['address_region'] ?? '' ) ),
			'postalCode'      => trim( (string) ( $main['postal_code'] ?? '' ) ),
			'addressCountry'  => trim( (string) ( $main['address_country'] ?? '' ) ),
		) );

		// An all-empty address would leave a bare {"@type": "PostalAddress"}.
		if ( count( $address ) > 1 ) {
			$extras['address'] = $address;
		}
	}

	return $extras;
}

/**
 * The Person's extra properties from the saved fields, only those that are filled.
 *
 * @return array<string, mixed>
 */
function devbase_schema_person_extras() {
	if ( ! function_exists( 'get_field' ) ) {
		return array();
	}

	$extras = devbase_schema_shared_extras();

	$image = devbase_schema_image_object( devbase_schema_field( 'person_image' ) );
	if ( $image ) {
		$extras['image'] = $image;
	}

	$job_title = trim( (string) devbase_schema_field( 'person_job_title' ) );
	if ( '' !== $job_title ) {
		$extras['jobTitle'] = $job_title;
	}

	return $extras;
}

/**
 * The keys a filled value on our page REPLACES in TSF's entity (it has a setting of its own for each), by schema type.
 * Every other key is only added when TSF did not print it.
 */
function devbase_schema_override_keys( $type ) {
	return 'Person' === $type ? array( 'name', 'url', 'image', 'sameAs' ) : array( 'name', 'url', 'logo', 'sameAs' );
}

/** Kept for the Organization-only name this had before Person existed. */
function devbase_schema_organization_override_keys() {
	return devbase_schema_override_keys( 'Organization' );
}

/**
 * Whether a graph node is TSF's entity for the site's own Organization or Person (stand-alone or inline), and which one:
 * 'Organization', 'Person' or null. A post author is also a Person, with `#/schema/Person/{hash}` as its id: only the
 * site's own (an id that ends at `#/schema/Person`) counts.
 */
function devbase_schema_site_entity_type( $node ) {
	$type = is_array( $node ) ? ( $node['@type'] ?? null ) : null;

	if ( 'Organization' === $type ) {
		return ( ! isset( $node['@id'] ) || false !== strpos( (string) $node['@id'], '#/schema/Organization' ) ) ? 'Organization' : null;
	}

	if ( 'Person' === $type ) {
		$id  = (string) ( $node['@id'] ?? '' );
		$end = '#/schema/Person';

		return ( '' !== $id && substr( $id, -strlen( $end ) ) === $end ) ? 'Person' : null;
	}

	return null;
}

/** Whether a graph node is TSF's Organization entity (stand-alone or inline). */
function devbase_schema_is_organization_node( $node ) {
	return 'Organization' === devbase_schema_site_entity_type( $node );
}

/**
 * Merges the extras into every node of the page's type (see devbase_schema_site_entity_type()), at any depth.
 *
 * @param array $graph  The graph, or part of it.
 * @param array $extras The properties for the entity the page says the site represents.
 * @param string $type  'Organization' or 'Person': the entity those extras are for.
 */
function devbase_schema_merge_site_entity( array $graph, array $extras, $type = 'Organization' ) {
	foreach ( $graph as $key => $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}

		if ( $type === devbase_schema_site_entity_type( $node ) ) {
			$overrides = array_intersect_key( $extras, array_flip( devbase_schema_override_keys( $type ) ) );
			$node      = array_merge( $node, $overrides ) + $extras; // Ours replace TSF's for the few it has a setting for; the rest only fill gaps.
		}

		$graph[ $key ] = devbase_schema_merge_site_entity( $node, $extras, $type );
	}

	return $graph;
}

/** The merge as it was named when only an Organization existed. */
function devbase_schema_merge_organization( array $graph, array $extras ) {
	return devbase_schema_merge_site_entity( $graph, $extras, 'Organization' );
}

if ( ! devbase_tsf_disabled() ) :

add_filter( 'the_seo_framework_schema_graph_data', function ( $graph ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}

	$person = 'person' === devbase_schema_site_type();
	$extras = $person ? devbase_schema_person_extras() : devbase_schema_organization_extras();

	return $extras ? devbase_schema_merge_site_entity( $graph, $extras, $person ? 'Person' : 'Organization' ) : $graph;
}, 20 );

// Makes TSF represent what the page's select says. TSF reads its settings through this filter once per request; the value is
// changed in what it returns, never saved. Only when the select was saved, so a site that never opened the page, or one that
// runs its own TSF with its own choice, keeps TSF's.
add_filter( 'the_seo_framework_get_options', function ( $options ) {
	if ( is_array( $options ) && devbase_schema_site_type_is_saved() ) {
		$options['knowledge_type'] = devbase_schema_site_type();
	}

	return $options;
} );

// A pointer at the top of TSF's own Schema settings box (SEO > Settings > Schema). Output only: TSF's form and its saving are
// untouched. The hook is TSF's `the_seo_framework_schema_metabox_before`. With the theme's own TSF its "Presence" tab is hidden
// (inc/dependencies/tsf/tsf.php), so the pointer says where the organization or person is set; on a site's OWN TSF the tab is
// still there and the pointer says a value on our page replaces the ones below.
add_action( 'the_seo_framework_schema_metabox_before', function () {
	$hidden = false !== has_filter( 'the_seo_framework_schema_settings_tabs', 'devbase_tsf_hide_presence_tab' );

	printf(
		'<p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p>',
		esc_html__( 'Organization:', 'devbase_parent_theme' ),
		$hidden
			? esc_html__( 'whether this website represents an organization or a person, its name, URL, logo and profile links are set on the theme\'s Organization Details page.', 'devbase_parent_theme' )
			: esc_html__( 'the name, URL, logo and profile links filled in on the theme\'s Organization Details page replace the ones below; leave them empty there to use these.', 'devbase_parent_theme' ),
		esc_url( admin_url( 'admin.php?page=schema-settings' ) ),
		esc_html__( 'Open Organization Details', 'devbase_parent_theme' )
	);
} );

endif; // devbase_tsf_disabled(): none of the three hooks above is registered while TSF is switched off.
