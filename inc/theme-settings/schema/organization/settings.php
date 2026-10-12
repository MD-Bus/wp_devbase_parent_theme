<?php

// Contributes the Organization tab to the merged Schema Markup field
// group (see inc/theme-settings/schema/settings.php for how tabs are
// assembled — this file registers a filter contribution, not its own
// field group, so it renders as a tab instead of a separate stacked box):
// an enable toggle and the fields needed to build a schema.org
// Organization object. inject.php in this folder reads these fields and
// prints the actual JSON-LD sitewide.
//
// Required fields: same mechanism as SMTP/Security — ACF skips required
// validation on a field currently hidden by conditional logic, so marking
// Name/URL as required only blocks saving while the toggle is on.
add_filter( 'devbase/schema/tabs', function ( $tabs ) {
	$enabled_condition = array(
		array(
			array(
				'field'    => 'field_devbase_schema_organization_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	$tabs[] = array(
		'label'  => __( 'Organization', 'devbase_parent_theme' ),
		'fields' => array(
			array(
				'key'           => 'field_devbase_schema_organization_enabled',
				'label'         => __( 'Enable Organization Schema', 'devbase_parent_theme' ),
				'name'          => 'organization_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
			),

			// Row: identity — name and canonical URL.
			array(
				'key'               => 'field_devbase_schema_organization_name',
				'label'             => __( 'Organization Name', 'devbase_parent_theme' ),
				'name'              => 'organization_name',
				'type'              => 'text',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_schema_organization_url',
				'label'             => __( 'URL', 'devbase_parent_theme' ),
				'name'              => 'organization_url',
				'type'              => 'url',
				'required'          => 1,
				'placeholder'       => home_url( '/' ),
				'instructions'      => __( 'Leave blank to use the site\'s home URL.', 'devbase_parent_theme' ),
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $enabled_condition,
			),

			array(
				'key'               => 'field_devbase_schema_organization_description',
				'label'             => __( 'Description', 'devbase_parent_theme' ),
				'name'              => 'organization_description',
				'type'              => 'textarea',
				'rows'              => 3,
				'instructions'      => __( 'One or two sentences describing the business.', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_schema_organization_logo',
				'label'             => __( 'Logo', 'devbase_parent_theme' ),
				'name'              => 'organization_logo',
				'type'              => 'image',
				'return_format'     => 'url',
				'mime_types'        => 'jpg,jpeg,png',
				'instructions'      => __( 'PNG or JPG only — schema consumers like Google\'s Knowledge Panel don\'t support SVG. Leave empty to omit the logo from schema entirely.', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_schema_organization_slogan',
				'label'             => __( 'Slogan', 'devbase_parent_theme' ),
				'name'              => 'organization_slogan',
				'type'              => 'text',
				'instructions'      => __( 'Optional. A short tagline.', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_schema_organization_founding_date',
				'label'             => __( 'Founding Date', 'devbase_parent_theme' ),
				'name'              => 'organization_founding_date',
				'type'              => 'date_picker',
				'display_format'    => 'F j, Y',
				'return_format'     => 'Y-m-d',
				'instructions'      => __( 'Optional. Leave empty to omit from schema.', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_schema_organization_knows_about',
				'label'             => __( 'Areas of Expertise', 'devbase_parent_theme' ),
				'name'              => 'organization_knows_about',
				'type'              => 'repeater',
				'layout'            => 'table',
				'button_label'      => __( 'Add Topic', 'devbase_parent_theme' ),
				'instructions'      => __( 'Optional. Topics/skills the business is known for, e.g. "WordPress Development", "SEO". Maps to schema.org knowsAbout.', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
				'sub_fields'        => array(
					array(
						'key'   => 'field_devbase_schema_organization_knows_about_topic',
						'label' => __( 'Topic', 'devbase_parent_theme' ),
						'name'  => 'topic',
						'type'  => 'text',
					),
				),
			),
			array(
				'key'               => 'field_devbase_schema_organization_same_as',
				'label'             => __( 'Social / Profile Links', 'devbase_parent_theme' ),
				'name'              => 'organization_same_as',
				'type'              => 'repeater',
				'layout'            => 'table',
				'button_label'      => __( 'Add Link', 'devbase_parent_theme' ),
				'instructions'      => __( 'Optional. Links to official profiles (LinkedIn, Instagram, GitHub, etc.) that represent this same business. Maps to schema.org sameAs.', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
				'sub_fields'        => array(
					array(
						'key'   => 'field_devbase_schema_organization_same_as_url',
						'label' => __( 'URL', 'devbase_parent_theme' ),
						'name'  => 'url',
						'type'  => 'url',
					),
				),
			),

			// Locations — a repeater, not a single group: a business can
			// have more than one, and only the one flagged "Main" becomes
			// this Organization's schema `address`. See organization/AGENT.md
			// for why the others aren't (yet) reflected in the JSON-LD.
			array(
				'key'               => 'field_devbase_schema_organization_locations',
				'label'             => __( 'Locations', 'devbase_parent_theme' ),
				'name'              => 'organization_locations',
				'type'              => 'repeater',
				'layout'            => 'block',
				'button_label'      => __( 'Add Location', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
				'sub_fields'        => array(
					array(
						'key'          => 'field_devbase_schema_organization_location_label',
						'label'        => __( 'Label', 'devbase_parent_theme' ),
						'name'         => 'label',
						'type'         => 'text',
						'instructions' => __( 'Admin-only, for telling multiple locations apart (e.g. "Head Office").', 'devbase_parent_theme' ),
						'wrapper'      => array( 'width' => '75' ),
					),
					array(
						'key'           => 'field_devbase_schema_organization_location_main',
						'label'         => __( 'Main', 'devbase_parent_theme' ),
						'name'          => 'is_main',
						'type'          => 'true_false',
						'ui'            => 1,
						'default_value' => 0,
						'wrapper'       => array( 'width' => '25' ),
					),
					array(
						'key'   => 'field_devbase_schema_organization_location_street',
						'label' => __( 'Street Address', 'devbase_parent_theme' ),
						'name'  => 'street_address',
						'type'  => 'text',
					),
					array(
						'key'     => 'field_devbase_schema_organization_location_locality',
						'label'   => __( 'City', 'devbase_parent_theme' ),
						'name'    => 'address_locality',
						'type'    => 'text',
						'wrapper' => array( 'width' => '25' ),
					),
					array(
						'key'     => 'field_devbase_schema_organization_location_region',
						'label'   => __( 'State / Province', 'devbase_parent_theme' ),
						'name'    => 'address_region',
						'type'    => 'text',
						'wrapper' => array( 'width' => '25' ),
					),
					array(
						'key'     => 'field_devbase_schema_organization_location_postal_code',
						'label'   => __( 'Postal Code', 'devbase_parent_theme' ),
						'name'    => 'postal_code',
						'type'    => 'text',
						'wrapper' => array( 'width' => '25' ),
					),
					array(
						'key'          => 'field_devbase_schema_organization_location_country',
						'label'        => __( 'Country', 'devbase_parent_theme' ),
						'name'         => 'address_country',
						'type'         => 'text',
						'maxlength'    => 2,
						'instructions' => __( 'Two-letter country code, e.g. CA, US.', 'devbase_parent_theme' ),
						'wrapper'      => array( 'width' => '25' ),
					),
				),
			),
		),
	);

	return $tabs;
} );
