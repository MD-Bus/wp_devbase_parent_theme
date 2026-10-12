<?php

// Contributes the Breadcrumb tab to the merged Schema Markup field group
// (see inc/theme-settings/schema/settings.php for how tabs are assembled
// — this file registers a filter contribution, not its own field group,
// so it renders as a tab instead of a separate stacked box). Just an
// on/off switch — a BreadcrumbList has nothing for an admin to fill in,
// it's computed entirely from the current page's position in WordPress's
// own hierarchy at render time. See inject.php in this folder.
add_filter( 'devbase/schema/tabs', function ( $tabs ) {
	$tabs[] = array(
		'label'  => __( 'Breadcrumb', 'devbase_parent_theme' ),
		'fields' => array(
			array(
				'key'           => 'field_devbase_schema_breadcrumb_enabled',
				'label'         => __( 'Enable Breadcrumb Schema', 'devbase_parent_theme' ),
				'name'          => 'breadcrumb_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
				'instructions'  => __( 'Outputs a BreadcrumbList based on this page\'s position in the site (parent pages, CPT archive, taxonomy term). No visible breadcrumb trail is added to the page itself — this is structured data only.', 'devbase_parent_theme' ),
			),
		),
	);

	return $tabs;
} );
