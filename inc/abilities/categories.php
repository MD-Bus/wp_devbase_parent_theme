<?php

// Registers this theme's own Abilities API category. Core requires a
// category to exist before any ability referencing it is registered, and
// fires this specifically for that purpose — see wp-includes/abilities-api.php.
add_action( 'wp_abilities_api_categories_init', function () {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category( 'devbase', array(
		'label'       => __( 'Dev Base', 'devbase_parent_theme' ),
		'description' => __( 'Content abilities for this site: listing/reading/creating/updating posts and options pages, and discovering their field schema.', 'devbase_parent_theme' ),
	) );
} );
