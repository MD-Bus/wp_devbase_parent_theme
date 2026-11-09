<?php

// The "Organization Details" sub-page (under Theme Settings): everything about who the site represents, in one
// place. A site is an ORGANIZATION (the default) or a PERSON: a select picks which, and each type shows only its own
// fields. Name, URL, logo (a person: photo) and profile links, plus what The SEO Framework (TSF) has no field for at
// all (description, slogan, founding date, areas of expertise, locations, a person's job title). organization.php merges all of it into
// TSF's Organization entity in its JSON-LD graph: a filled field here WINS over TSF's own Schema setting,
// an empty one leaves TSF's value.
//
// The page slug (`schema-settings`) and every field name and key are the ones the old Schema Markup
// page used, so values a site already saved keep working with no migration and no database change. The old
// "Enable Organization Schema" switch is the one field not brought back: TSF's own knowledge-graph switch
// (SEO > Settings > Schema) is the master switch.
add_action( 'acf/init', function () {
	// Only The SEO Framework reads this page: with it switched off (Miscellaneous > Management) the page is not registered.
	if ( devbase_tsf_disabled() || ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	// Which fields belong to which type. The select defaults to Organization, and so does a site that never saved it.
	$for_organization = array( array( array( 'field' => 'field_devbase_schema_organization_type', 'operator' => '!=', 'value' => 'person' ) ) );
	$for_person       = array( array( array( 'field' => 'field_devbase_schema_organization_type', 'operator' => '==', 'value' => 'person' ) ) );

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Organization Details', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Organization Details', 'devbase_parent_theme' ),
		'menu_slug'   => 'schema-settings',
		'capability'  => 'manage_options',
		'parent_slug' => 'theme-settings',
	) );

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_schema',
		'title'    => __( 'Organization Details', 'devbase_parent_theme' ),
		'fields'   => array(
			array(
				'key'       => 'field_devbase_schema_organization_notice',
				'label'     => __( 'Before you start', 'devbase_parent_theme' ),
				'name'      => '',
				'type'      => 'message',
				'message'   => __( 'Who this website represents in the search-engine structured data is set here. What you fill in replaces what the SEO plugin\'s own Schema settings say for the same thing; leave a field empty to keep the SEO plugin\'s value.', 'devbase_parent_theme' ),
				'new_lines' => 'wpautop',
				'esc_html'  => 0,
			),
			array(
				'key'           => 'field_devbase_schema_organization_type',
				'label'         => __( 'This website represents', 'devbase_parent_theme' ),
				'name'          => 'organization_type',
				'type'          => 'select',
				'choices'       => array(
					'organization' => __( 'An organization (a business, a club, a foundation)', 'devbase_parent_theme' ),
					'person'       => __( 'A person', 'devbase_parent_theme' ),
				),
				'default_value' => 'organization',
				'allow_null'    => 0,
				'return_format' => 'value',
			),
			array(
				'key'     => 'field_devbase_schema_organization_name',
				'label'   => __( 'Name', 'devbase_parent_theme' ),
				'name'    => 'organization_name',
				'type'    => 'text',
				'wrapper' => array( 'width' => '50' ),
			),
			array(
				'key'          => 'field_devbase_schema_organization_url',
				'label'        => __( 'URL', 'devbase_parent_theme' ),
				'name'         => 'organization_url',
				'type'         => 'url',
				'placeholder'  => home_url( '/' ),
				'instructions' => __( 'Leave blank to use the site\'s home URL.', 'devbase_parent_theme' ),
				'wrapper'      => array( 'width' => '50' ),
			),
			array(
				'key'           => 'field_devbase_schema_organization_logo',
				'conditional_logic' => $for_organization,
				'label'         => __( 'Logo', 'devbase_parent_theme' ),
				'name'          => 'organization_logo',
				'type'          => 'image',
				'return_format' => 'url',
				'mime_types'    => 'jpg,jpeg,png',
				'instructions'  => __( 'PNG or JPG only: search engines such as Google\'s Knowledge Panel do not support SVG. Leave empty to keep the SEO plugin\'s logo.', 'devbase_parent_theme' ),
			),
			array(
				'key'               => 'field_devbase_schema_person_image',
				'label'             => __( 'Photo', 'devbase_parent_theme' ),
				'name'              => 'person_image',
				'type'              => 'image',
				'return_format'     => 'url',
				'mime_types'        => 'jpg,jpeg,png',
				'instructions'      => __( 'PNG or JPG. Leave empty to keep the SEO plugin\'s value.', 'devbase_parent_theme' ),
				'conditional_logic' => $for_person,
			),
			array(
				'key'               => 'field_devbase_schema_person_job_title',
				'label'             => __( 'Job Title', 'devbase_parent_theme' ),
				'name'              => 'person_job_title',
				'type'              => 'text',
				'instructions'      => __( 'Optional. Maps to schema.org jobTitle.', 'devbase_parent_theme' ),
				'conditional_logic' => $for_person,
			),
			array(
				'key'          => 'field_devbase_schema_organization_description',
				'label'        => __( 'Description', 'devbase_parent_theme' ),
				'name'         => 'organization_description',
				'type'         => 'textarea',
				'rows'         => 3,
				'instructions' => __( 'One or two sentences describing the business or the person.', 'devbase_parent_theme' ),
			),
			array(
				'key'          => 'field_devbase_schema_organization_slogan',
				'conditional_logic' => $for_organization,
				'label'        => __( 'Slogan', 'devbase_parent_theme' ),
				'name'         => 'organization_slogan',
				'type'         => 'text',
				'instructions' => __( 'Optional. A short tagline.', 'devbase_parent_theme' ),
			),
			array(
				'key'            => 'field_devbase_schema_organization_founding_date',
				'conditional_logic' => $for_organization,
				'label'          => __( 'Founding Date', 'devbase_parent_theme' ),
				'name'           => 'organization_founding_date',
				'type'           => 'date_picker',
				'display_format' => 'F j, Y',
				'return_format'  => 'Y-m-d',
				'instructions'   => __( 'Optional. Leave empty to omit from schema.', 'devbase_parent_theme' ),
			),
			array(
				'key'          => 'field_devbase_schema_organization_knows_about',
				'label'        => __( 'Areas of Expertise', 'devbase_parent_theme' ),
				'name'         => 'organization_knows_about',
				'type'         => 'repeater',
				'layout'       => 'table',
				'button_label' => __( 'Add Topic', 'devbase_parent_theme' ),
				'instructions' => __( 'Optional. Topics/skills the business is known for, e.g. "WordPress Development", "SEO". Maps to schema.org knowsAbout.', 'devbase_parent_theme' ),
				'sub_fields'   => array(
					array(
						'key'   => 'field_devbase_schema_organization_knows_about_topic',
						'label' => __( 'Topic', 'devbase_parent_theme' ),
						'name'  => 'topic',
						'type'  => 'text',
					),
				),
			),

			array(
				'key'          => 'field_devbase_schema_organization_same_as',
				'label'        => __( 'Social / Profile Links', 'devbase_parent_theme' ),
				'name'         => 'organization_same_as',
				'type'         => 'repeater',
				'layout'       => 'table',
				'button_label' => __( 'Add Link', 'devbase_parent_theme' ),
				'instructions' => __( 'Optional. Links to official profiles (LinkedIn, Instagram, GitHub, etc.) that represent this same business. Maps to schema.org sameAs. Filled here, this list replaces the SEO plugin\'s profile links.', 'devbase_parent_theme' ),
				'sub_fields'   => array(
					array(
						'key'   => 'field_devbase_schema_organization_same_as_url',
						'label' => __( 'URL', 'devbase_parent_theme' ),
						'name'  => 'url',
						'type'  => 'url',
					),
				),
			),

			// Locations: a repeater, because a business can have more than one. Only the row flagged
			// "Main" (or the first row, when none is) becomes the Organization's schema `address`.
			array(
				'key'          => 'field_devbase_schema_organization_locations',
				'conditional_logic' => $for_organization,
				'label'        => __( 'Locations', 'devbase_parent_theme' ),
				'name'         => 'organization_locations',
				'type'         => 'repeater',
				'layout'       => 'block',
				'button_label' => __( 'Add Location', 'devbase_parent_theme' ),
				'sub_fields'   => array(
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
} );
