<?php

// Post types the content abilities never expose, regardless of
// show_in_rest — registered_form and form_data are submission-log data
// gated to manage_options (see inc/forms/post-types.php), not authored
// content, and shouldn't be reachable through an AI content-editing side
// door even for an admin caller. Filterable so a child theme can extend
// the list without editing this file.
function devbase_abilities_excluded_post_types() {
	return apply_filters( 'devbase_abilities_excluded_post_types', array( 'registered_form', 'form_data' ) );
}

/**
 * The single source of truth every content ability uses to validate an
 * incoming post_type argument: every REST-exposed post type minus the
 * excluded ones above.
 *
 * @return WP_Post_Type[] Keyed by post type name.
 */
function devbase_abilities_content_post_types() {
	// show_in_rest alone also matches WordPress's own internal site-editor
	// post types (wp_navigation, wp_template, wp_font_family, ...) — all of
	// which are REST-exposed but never public. Requiring public => true
	// keeps this to real content types: the built-in post/page/attachment,
	// plus any CPT this theme or a plugin registers normally.
	$post_types = get_post_types( array( 'show_in_rest' => true, 'public' => true ), 'objects' );

	foreach ( devbase_abilities_excluded_post_types() as $excluded ) {
		unset( $post_types[ $excluded ] );
	}

	// Opt-in allow-list — Theme Settings > Miscellaneous > MCP Access (see
	// inc/theme-settings/miscellaneous/settings.php). Nothing is exposed
	// until explicitly checked on there, regardless of show_in_rest/public.
	$allowed = (array) get_field( 'mcp_allowed_post_types', 'option' );

	foreach ( $post_types as $name => $post_type ) {
		if ( ! in_array( $name, $allowed, true ) ) {
			unset( $post_types[ $name ] );
		}
	}

	return $post_types;
}

/**
 * Whether a real, registered options page (see
 * devbase_abilities_is_registered_options_page() for that separate check)
 * has been opted into MCP access via Theme Settings > Miscellaneous > MCP
 * Access. Kept as its own function so "unknown page" and "real page, just
 * not allowed" stay distinguishable, real errors an MCP client can act on
 * differently.
 */
function devbase_abilities_is_options_page_allowed( $menu_slug ) {
	$allowed = (array) get_field( 'mcp_allowed_options_pages', 'option' );

	return in_array( $menu_slug, $allowed, true );
}

/**
 * The REST base for a post type (e.g. 'posts' for 'post') — falls back to
 * the post type name itself, matching how WP core resolves it when a post
 * type doesn't declare a distinct rest_base.
 */
function devbase_abilities_rest_base( $post_type_object ) {
	return ! empty( $post_type_object->rest_base ) ? $post_type_object->rest_base : $post_type_object->name;
}

/**
 * Turns a raw ACF/SCF conditional_logic rule set into a human/AI-readable
 * summary — e.g. "shown only if retention_enabled == 1" — by resolving the
 * field keys it references to field names, using the same sibling-field
 * map the caller already built for the fields at this level.
 *
 * @param array|false $conditional_logic Raw conditional_logic from the field config.
 * @param array       $key_to_name       Field key => field name map for this field's siblings.
 * @return array|null OR-of-AND rule groups, e.g. [[ ['field' => 'x', 'operator' => '==', 'value' => '1'] ]], or null if unconditional.
 */
function devbase_abilities_shape_conditional_logic( $conditional_logic, array $key_to_name ) {
	if ( empty( $conditional_logic ) || ! is_array( $conditional_logic ) ) {
		return null;
	}

	$groups = array();

	foreach ( $conditional_logic as $and_group ) {
		$rules = array();

		foreach ( (array) $and_group as $rule ) {
			$rules[] = array(
				'field'    => $key_to_name[ $rule['field'] ] ?? $rule['field'],
				'operator' => $rule['operator'],
				'value'    => $rule['value'],
			);
		}

		$groups[] = $rules;
	}

	return $groups;
}

/**
 * Recursively shapes a list of ACF/SCF field configs (as returned by
 * acf_get_fields()) into the simplified {name, label, type, ...} form the
 * schema abilities return — the single place that understands nested
 * fields (repeater/group sub_fields, flexible_content layouts) and
 * conditional_logic, so devbase/get-post-type-schema and
 * devbase/get-option-page-schema don't each reimplement it. Layout-only
 * field types (tab, message) are dropped — they have no value of their
 * own to read or write.
 *
 * @param array $fields Field configs at this level (siblings of each other).
 * @return array[] Shaped fields.
 */
function devbase_abilities_shape_fields( array $fields ) {
	$key_to_name = array();

	foreach ( $fields as $field ) {
		$key_to_name[ $field['key'] ] = $field['name'];
	}

	$shaped = array();

	foreach ( $fields as $field ) {
		if ( in_array( $field['type'], array( 'tab', 'message' ), true ) ) {
			continue;
		}

		$entry = array(
			'name'  => $field['name'],
			'label' => $field['label'],
			'type'  => $field['type'],
		);

		$conditional = devbase_abilities_shape_conditional_logic( $field['conditional_logic'] ?? false, $key_to_name );

		if ( null !== $conditional ) {
			$entry['conditional_on'] = $conditional;
		}

		// Repeater/group: a flat list of sub_fields, same shape as this level.
		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$entry['sub_fields'] = devbase_abilities_shape_fields( $field['sub_fields'] );
		}

		// Flexible content: named layouts, each with their own sub_fields.
		if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			$entry['layouts'] = array();

			foreach ( $field['layouts'] as $layout ) {
				$entry['layouts'][] = array(
					'name'       => $layout['name'],
					'label'      => $layout['label'],
					'sub_fields' => ! empty( $layout['sub_fields'] )
						? devbase_abilities_shape_fields( $layout['sub_fields'] )
						: array(),
				);
			}
		}

		$shaped[] = $entry;
	}

	return $shaped;
}

/**
 * The top-level field names actually attached to a post type (or, for an
 * existing post, resolved specifically against that post — matching what
 * devbase/get-post would show). Used to reject unknown field names in
 * create-post/update-post up front, since SCF's own REST 'acf' field
 * silently drops unrecognized keys rather than erroring — confirmed via a
 * real create-post call with a bogus field name, which reported success
 * with the field simply absent, no indication anything was wrong.
 *
 * @return string[] Field names.
 */
function devbase_abilities_post_field_names( $post_type, $post_id = null ) {
	if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
		return array();
	}

	$args = $post_id ? array( 'post_id' => $post_id ) : array( 'post_type' => $post_type );
	$names = array();

	foreach ( acf_get_field_groups( $args ) as $field_group ) {
		foreach ( (array) acf_get_fields( $field_group ) as $field ) {
			if ( ! in_array( $field['type'], array( 'tab', 'message' ), true ) ) {
				$names[] = $field['name'];
			}
		}
	}

	return $names;
}

/**
 * Dispatches an internal WP REST API request and returns a plain
 * [$data, $error] pair — used by every content/schema ability below so
 * they reuse WP core's own posts-controller (validation, capability
 * checks, status handling, pagination) and SCF's own already-tested field
 * read/write and schema logic, instead of re-implementing any of it.
 * Runs as the currently authenticated user (already established by the
 * incoming MCP request's Application Password auth), so the dispatched
 * request's own capability checks apply exactly as they would for a
 * direct REST API call.
 *
 * @param string $method 'GET', 'POST', or 'PUT'.
 * @param string $route  REST route, e.g. '/wp/v2/posts' or '/wp/v2/posts/5'.
 * @param array  $params Request params (query params for GET, body params
 *                        for POST/PUT) — WP_REST_Request::set_param()
 *                        places each into the right bucket for the method.
 * @return array{0: mixed, 1: WP_Error|null}
 */
function devbase_abilities_rest_dispatch( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return array( null, $response->as_error() );
	}

	return array( $response->get_data(), null );
}
