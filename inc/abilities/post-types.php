<?php

// devbase/list-post-types and devbase/get-post-type-schema — the two
// discovery abilities an MCP client uses before it can do anything with
// devbase/list-posts, get-post, create-post, update-post (posts.php):
// first learn which post types exist, then learn what fields each one
// has. See inc/abilities/helpers.php for devbase_abilities_content_post_types()
// (the shared exclusion/validation list) and devbase_abilities_rest_dispatch()
// (why schema discovery goes through /wp/v2/types instead of hand-rolling
// SCF field-group resolution).

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'devbase/list-post-types', array(
		'label'               => __( 'List Post Types', 'devbase_parent_theme' ),
		'description'         => __( 'Lists every content post type on this site (name, label, whether it supports hierarchy, its REST base). Excludes internal/system post types.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'name'          => array( 'type' => 'string' ),
					'label'         => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'rest_base'     => array( 'type' => 'string' ),
					'hierarchical'  => array( 'type' => 'boolean' ),
				),
			),
		),
		'execute_callback'    => function () {
			$results = array();

			foreach ( devbase_abilities_content_post_types() as $post_type ) {
				$results[] = array(
					'name'         => $post_type->name,
					'label'        => $post_type->label,
					'description'  => $post_type->description,
					'rest_base'    => ! empty( $post_type->rest_base ) ? $post_type->rest_base : $post_type->name,
					'hierarchical' => (bool) $post_type->hierarchical,
				);
			}

			return $results;
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array( 'public' => true ),
	) );

	wp_register_ability( 'devbase/get-post-type-schema', array(
		'label'               => __( 'Get Post Type Schema', 'devbase_parent_theme' ),
		'description'         => __( 'Describes a post type: its core WordPress fields (supports, taxonomies) plus every custom field group and field generically attached to it. Only catches field groups scoped to the post type as a whole — a field group scoped to one specific post (e.g. a single page) will not appear here even though it\'s real; devbase/get-post on that specific post will still show it correctly.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'The post type name, from devbase/list-post-types.', 'devbase_parent_theme' ),
				),
			),
			'required'   => array( 'post_type' ),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			$post_types = devbase_abilities_content_post_types();

			if ( ! isset( $post_types[ $input['post_type'] ] ) ) {
				return new WP_Error( 'devbase_unknown_post_type', __( 'Unknown or unsupported post type.', 'devbase_parent_theme' ) );
			}

			list( $data, $error ) = devbase_abilities_rest_dispatch( 'GET', '/wp/v2/types/' . $input['post_type'] );

			if ( $error ) {
				return $error;
			}

			// Deliberately NOT relying on SCF's own 'scf_field_groups' REST
			// field here (SCF registers it correctly, but WordPress's own
			// REST field-inclusion filtering silently drops it from the
			// /wp/v2/types response — confirmed via a sibling field
			// registered the same way, 'scf_post_id', which does come
			// through; an upstream SCF/core REST bug, not anything in this
			// theme). Calling the same underlying resolution SCF's own
			// callback uses (acf_get_field_groups()+acf_get_fields(),
			// filtered to show_in_rest groups — the exact logic in
			// vendor/secure-custom-fields/includes/rest-api/class-acf-rest-types-endpoint.php's
			// get_scf_fields()) directly avoids the broken REST layer
			// entirely.
			$field_groups = array();

			if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
				foreach ( acf_get_field_groups( array( 'post_type' => $input['post_type'] ) ) as $field_group ) {
					if ( empty( $field_group['show_in_rest'] ) ) {
						continue;
					}

					$field_groups[] = array(
						'title'  => $field_group['title'],
						'fields' => devbase_abilities_shape_fields( (array) acf_get_fields( $field_group ) ),
					);
				}
			}

			$data['scf_field_groups'] = $field_groups;

			return $data;
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array( 'public' => true ),
	) );
} );
