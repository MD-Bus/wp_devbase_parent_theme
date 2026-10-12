<?php

// devbase/list-posts, get-post, create-post, update-post — the actual
// content operations, generic across every post type returned by
// devbase_abilities_content_post_types() (inc/abilities/helpers.php).
// Each dispatches to WP core's own REST posts-controller via
// devbase_abilities_rest_dispatch() rather than calling
// wp_insert_post()/update_field() directly, reusing its validation,
// capability checks, and SCF's already-wired 'acf' REST field for actual
// field values (see helpers.php's docblock for why).

/**
 * Shared shape for the create/update input schema — used by both
 * devbase/create-post and devbase/update-post below.
 */
function devbase_abilities_post_write_properties() {
	return array(
		'title'   => array(
			'type'        => 'string',
			'description' => __( 'The post title.', 'devbase_parent_theme' ),
		),
		'content' => array(
			'type'        => 'string',
			'description' => __( 'The post content (HTML).', 'devbase_parent_theme' ),
		),
		'status'  => array(
			'type'        => 'string',
			'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
			'description' => __( 'Defaults to draft — never auto-published.', 'devbase_parent_theme' ),
		),
		'fields'  => array(
			'type'        => 'object',
			'description' => __( 'Custom field values, keyed by field name — see devbase/get-post-type-schema for the available fields.', 'devbase_parent_theme' ),
		),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'devbase/list-posts', array(
		'label'               => __( 'List Posts', 'devbase_parent_theme' ),
		'description'         => __( 'Lists posts of a given post type, with optional status/search filtering and pagination.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string', 'description' => __( 'e.g. publish, draft, any. Defaults to publish.', 'devbase_parent_theme' ) ),
				'search'    => array( 'type' => 'string' ),
				'per_page'  => array( 'type' => 'integer' ),
				'page'      => array( 'type' => 'integer' ),
			),
			'required'   => array( 'post_type' ),
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'execute_callback'    => function ( $input ) {
			$post_types = devbase_abilities_content_post_types();

			if ( ! isset( $post_types[ $input['post_type'] ] ) ) {
				return new WP_Error( 'devbase_unknown_post_type', __( 'Unknown or unsupported post type.', 'devbase_parent_theme' ) );
			}

			$params = array();

			foreach ( array( 'status', 'search', 'per_page', 'page' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$params[ $key ] = $input[ $key ];
				}
			}

			$rest_base = devbase_abilities_rest_base( $post_types[ $input['post_type'] ] );

			list( $data, $error ) = devbase_abilities_rest_dispatch( 'GET', "/wp/v2/{$rest_base}", $params );

			if ( $error ) {
				return $error;
			}

			$results = array();

			foreach ( $data as $post ) {
				$results[] = array(
					'id'      => $post['id'],
					'title'   => $post['title']['rendered'] ?? '',
					'status'  => $post['status'] ?? '',
					'date'    => $post['date'] ?? '',
					'link'    => $post['link'] ?? '',
					'excerpt' => $post['excerpt']['rendered'] ?? '',
				);
			}

			return $results;
		},
		'permission_callback' => function ( $input ) {
			$post_types = devbase_abilities_content_post_types();

			// Defer to execute_callback for an unknown/not-allowed post
			// type rather than denying here — a generic "permission
			// denied" is a worse error than the specific
			// devbase_unknown_post_type one execute_callback gives, and
			// no actual capability is granted either way (execute_callback
			// still rejects before doing anything).
			if ( empty( $input['post_type'] ) || ! isset( $post_types[ $input['post_type'] ] ) ) {
				return true;
			}

			return current_user_can( $post_types[ $input['post_type'] ]->cap->edit_posts );
		},
		'meta'                => array( 'public' => true ),
	) );

	wp_register_ability( 'devbase/get-post', array(
		'label'               => __( 'Get Post', 'devbase_parent_theme' ),
		'description'         => __( 'Gets a single post by ID: core fields (title, content, status) plus its real custom field values.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string' ),
				'id'        => array( 'type' => 'integer' ),
			),
			'required'   => array( 'post_type', 'id' ),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			$post_types = devbase_abilities_content_post_types();

			if ( ! isset( $post_types[ $input['post_type'] ] ) ) {
				return new WP_Error( 'devbase_unknown_post_type', __( 'Unknown or unsupported post type.', 'devbase_parent_theme' ) );
			}

			$rest_base = devbase_abilities_rest_base( $post_types[ $input['post_type'] ] );

			list( $data, $error ) = devbase_abilities_rest_dispatch( 'GET', "/wp/v2/{$rest_base}/{$input['id']}" );

			if ( $error ) {
				return $error;
			}

			return $data;
		},
		'permission_callback' => function ( $input ) {
			if ( empty( $input['id'] ) ) {
				return false;
			}

			// read_post, not edit_post — this is a read operation, and using
			// the edit capability would wrongly deny a user (e.g. an Editor
			// role viewing a colleague's private post) who can legitimately
			// read a post but not edit it.
			return current_user_can( 'read_post', $input['id'] );
		},
		'meta'                => array( 'public' => true ),
	) );

	wp_register_ability( 'devbase/create-post', array(
		'label'               => __( 'Create Post', 'devbase_parent_theme' ),
		'description'         => __( 'Creates a new post of the given type. Defaults to draft status — never auto-published. Use devbase/get-post-type-schema first to know which custom fields are available.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array_merge(
				array( 'post_type' => array( 'type' => 'string' ) ),
				devbase_abilities_post_write_properties()
			),
			'required'   => array( 'post_type' ),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			$post_types = devbase_abilities_content_post_types();

			if ( ! isset( $post_types[ $input['post_type'] ] ) ) {
				return new WP_Error( 'devbase_unknown_post_type', __( 'Unknown or unsupported post type.', 'devbase_parent_theme' ) );
			}

			if ( ! empty( $input['fields'] ) ) {
				$unknown = array_diff( array_keys( $input['fields'] ), devbase_abilities_post_field_names( $input['post_type'] ) );

				if ( ! empty( $unknown ) ) {
					return new WP_Error(
						'devbase_unknown_field',
						sprintf(
							/* translators: %s: comma-separated list of field names that don't belong to this post type. */
							__( 'These fields do not belong to this post type: %s', 'devbase_parent_theme' ),
							implode( ', ', $unknown )
						)
					);
				}
			}

			$params = array( 'status' => $input['status'] ?? 'draft' );

			if ( isset( $input['title'] ) ) {
				$params['title'] = $input['title'];
			}

			if ( isset( $input['content'] ) ) {
				$params['content'] = $input['content'];
			}

			if ( ! empty( $input['fields'] ) ) {
				$params['acf'] = $input['fields'];
			}

			$rest_base = devbase_abilities_rest_base( $post_types[ $input['post_type'] ] );

			list( $data, $error ) = devbase_abilities_rest_dispatch( 'POST', "/wp/v2/{$rest_base}", $params );

			if ( $error ) {
				return $error;
			}

			return $data;
		},
		'permission_callback' => function ( $input ) {
			$post_types = devbase_abilities_content_post_types();

			// Same reasoning as devbase/list-posts above: defer to
			// execute_callback's specific devbase_unknown_post_type error
			// rather than a generic denial here.
			if ( empty( $input['post_type'] ) || ! isset( $post_types[ $input['post_type'] ] ) ) {
				return true;
			}

			return current_user_can( $post_types[ $input['post_type'] ]->cap->create_posts );
		},
		'meta'                => array(
			'public'      => true,
			'annotations' => array( 'destructive' => false, 'idempotent' => false ),
		),
	) );

	wp_register_ability( 'devbase/update-post', array(
		'label'               => __( 'Update Post', 'devbase_parent_theme' ),
		'description'         => __( 'Updates an existing post. Only the properties provided are changed.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'post_type' => array( 'type' => 'string' ),
					'id'        => array( 'type' => 'integer' ),
				),
				devbase_abilities_post_write_properties()
			),
			'required'   => array( 'post_type', 'id' ),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			$post_types = devbase_abilities_content_post_types();

			if ( ! isset( $post_types[ $input['post_type'] ] ) ) {
				return new WP_Error( 'devbase_unknown_post_type', __( 'Unknown or unsupported post type.', 'devbase_parent_theme' ) );
			}

			if ( ! empty( $input['fields'] ) ) {
				// Resolved against this specific post (not just the post
				// type generically) so a field scoped to only this one post
				// via a per-post location rule is correctly recognized —
				// see devbase/get-post-type-schema's description for why
				// that distinction matters.
				$unknown = array_diff( array_keys( $input['fields'] ), devbase_abilities_post_field_names( $input['post_type'], $input['id'] ) );

				if ( ! empty( $unknown ) ) {
					return new WP_Error(
						'devbase_unknown_field',
						sprintf(
							/* translators: %s: comma-separated list of field names that don't belong to this post. */
							__( 'These fields do not belong to this post: %s', 'devbase_parent_theme' ),
							implode( ', ', $unknown )
						)
					);
				}
			}

			$params = array();

			foreach ( array( 'title', 'content', 'status' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$params[ $key ] = $input[ $key ];
				}
			}

			if ( ! empty( $input['fields'] ) ) {
				$params['acf'] = $input['fields'];
			}

			$rest_base = devbase_abilities_rest_base( $post_types[ $input['post_type'] ] );

			list( $data, $error ) = devbase_abilities_rest_dispatch( 'PUT', "/wp/v2/{$rest_base}/{$input['id']}", $params );

			if ( $error ) {
				return $error;
			}

			return $data;
		},
		'permission_callback' => function ( $input ) {
			if ( empty( $input['id'] ) ) {
				return false;
			}

			return current_user_can( 'edit_post', $input['id'] );
		},
		'meta'                => array(
			'public'      => true,
			'annotations' => array( 'destructive' => true, 'idempotent' => true ),
		),
	) );
} );
