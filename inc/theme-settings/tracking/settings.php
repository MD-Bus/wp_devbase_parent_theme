<?php

// Registers the Tracking sub-page (under Theme Settings) with two tabs —
// Analytics (Google Tag Manager / GA4 container IDs) and Custom Scripts
// (raw head/footer code, for anything analytics can't cover cleanly, e.g.
// Meta Pixel, Hotjar). Actually injecting any of this into the front end
// happens in inject.php, not here — this file only defines the fields.
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Tracking', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Tracking', 'devbase_parent_theme' ),
		'menu_slug'   => 'tracking-settings',
		'parent_slug' => 'theme-settings',
	) );

	acf_add_local_field_group( array(
		'key'    => 'group_devbase_tracking',
		'title'  => __( 'Tracking', 'devbase_parent_theme' ),
		'fields' => array(

			// Tab 1: Analytics.
			array(
				'key'   => 'field_devbase_tracking_analytics_tab',
				'label' => __( 'Analytics', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'         => 'field_devbase_gtm_id',
				'label'       => __( 'Google Tag Manager ID', 'devbase_parent_theme' ),
				'name'        => 'gtm_id',
				'type'        => 'text',
				'instructions' => __( 'e.g. GTM-XXXXXXX. Leave blank if this site doesn\'t use GTM.', 'devbase_parent_theme' ),
				'wrapper'     => array( 'width' => '50' ),
			),
			array(
				'key'         => 'field_devbase_ga4_id',
				'label'       => __( 'Google Analytics (GA4) ID', 'devbase_parent_theme' ),
				'name'        => 'ga4_id',
				'type'        => 'text',
				'instructions' => __( 'e.g. G-XXXXXXXXXX. Only needed if not already loaded through GTM above.', 'devbase_parent_theme' ),
				'wrapper'     => array( 'width' => '50' ),
			),

			// Tab 2: Custom Scripts.
			array(
				'key'   => 'field_devbase_tracking_scripts_tab',
				'label' => __( 'Custom Scripts', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'         => 'field_devbase_header_scripts',
				'label'       => __( 'Header Scripts', 'devbase_parent_theme' ),
				'name'        => 'header_scripts',
				'type'        => 'textarea',
				'instructions' => __( 'Raw HTML/JS, output just before </head>. E.g. Meta Pixel, Hotjar.', 'devbase_parent_theme' ),
				'rows'        => 8,
			),
			array(
				'key'         => 'field_devbase_footer_scripts',
				'label'       => __( 'Footer Scripts', 'devbase_parent_theme' ),
				'name'        => 'footer_scripts',
				'type'        => 'textarea',
				'instructions' => __( 'Raw HTML/JS, output just before </body>.', 'devbase_parent_theme' ),
				'rows'        => 8,
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'tracking-settings',
				),
			),
		),
	) );
} );
