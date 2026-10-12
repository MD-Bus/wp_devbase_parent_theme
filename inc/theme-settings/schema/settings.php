<?php

// Registers the "Schema Markup" sub-page itself (under Theme Settings),
// and assembles ONE field group containing every schema type as its own
// tab — same mechanism as Security's Turnstile/reCAPTCHA tabs
// (inc/theme-settings/security/settings.php), just spread across
// subfolders instead of hardcoded in one file. Three separate
// acf_add_local_field_group() calls (one per type) would render as three
// stacked boxes, not tabs on one screen — ACF's tab field only groups
// fields *within* a single field group.
//
// Each type contributes its fields via the 'devbase/schema/tabs' filter
// instead of registering its own field group — see
// inc/theme-settings/schema/organization/settings.php for the contract.
// Contributions must use a plain top-level add_filter() call (not
// wrapped in an acf/init callback) so they're already registered by the
// time this file's own acf/init callback (priority 20, deliberately
// later) collects them.
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Schema Markup', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Schema Markup', 'devbase_parent_theme' ),
		'menu_slug'   => 'schema-settings',
		'parent_slug' => 'theme-settings',
	) );

	/**
	 * Filters the schema type tabs shown on the Schema Markup page.
	 *
	 * Each entry: array( 'label' => string, 'fields' => array of ACF field
	 * definitions for that type ). Don't include a 'tab' field of your
	 * own — one is added automatically ahead of each type's fields.
	 *
	 * @param array $tabs
	 */
	$tabs = apply_filters( 'devbase/schema/tabs', array() );

	$fields = array();
	foreach ( $tabs as $tab ) {
		if ( empty( $tab['label'] ) || empty( $tab['fields'] ) ) {
			continue;
		}

		$fields[] = array(
			'key'   => 'field_devbase_schema_tab_' . sanitize_key( $tab['label'] ),
			'label' => $tab['label'],
			'name'  => '',
			'type'  => 'tab',
		);

		foreach ( $tab['fields'] as $field ) {
			$fields[] = $field;
		}
	}

	if ( empty( $fields ) ) {
		return;
	}

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_schema',
		'title'    => __( 'Schema Markup', 'devbase_parent_theme' ),
		'fields'   => $fields,
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'schema-settings',
				),
			),
		),
	) );
}, 20 );
