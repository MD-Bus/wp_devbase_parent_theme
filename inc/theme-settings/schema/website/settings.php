<?php

// Contributes the Website tab to the merged Schema Markup field group
// (see inc/theme-settings/schema/settings.php for how tabs are assembled
// — this file registers a filter contribution, not its own field group,
// so it renders as a tab instead of a separate stacked box). This is
// deliberately the smallest possible set of fields — name/url/description
// all already exist as WordPress/Organization data, so the only real
// decisions left for an admin are "on or off" and "sitelinks search box or
// not". See inc/theme-settings/schema/website/inject.php for where the
// rest is actually assembled.
add_filter( 'devbase/schema/tabs', function ( $tabs ) {
	$enabled_condition = array(
		array(
			array(
				'field'    => 'field_devbase_schema_website_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	$tabs[] = array(
		'label'  => __( 'Website', 'devbase_parent_theme' ),
		'fields' => array(
			array(
				'key'           => 'field_devbase_schema_website_enabled',
				'label'         => __( 'Enable Website Schema', 'devbase_parent_theme' ),
				'name'          => 'website_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
			),
			array(
				'key'               => 'field_devbase_schema_website_search',
				'label'             => __( 'Enable Sitelinks Search Box', 'devbase_parent_theme' ),
				'name'              => 'website_search_enabled',
				'type'              => 'true_false',
				'ui'                => 1,
				'default_value'     => 0,
				'instructions'      => __( 'Lets Google show a search box directly in search results, using this site\'s own search (?s=). Only enable if search actually works on this site.', 'devbase_parent_theme' ),
				'conditional_logic' => $enabled_condition,
			),
		),
	);

	return $tabs;
} );
