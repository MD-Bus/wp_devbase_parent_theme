<?php

// devbase/list-option-pages, get-option-page-schema, get-options,
// update-options — the options-page equivalent of post-types.php/posts.php.
// No REST controller to reuse here (SCF options pages aren't WP objects),
// so these go directly through SCF's PHP API. Every options (sub-)page in
// this theme shares the single 'option' storage context — none of
// inc/theme-settings/*/settings.php pass a custom post_id to
// acf_add_options_sub_page() — so which fields belong to which page is
// resolved by each field group's 'options_page' location rule, not by a
// per-page storage key. See e.g. get_field( 'smtp_enabled', 'option' ) in
// inc/theme-settings/smtp/test.php for the existing convention this reuses.
//
// ONE EXCEPTION, which this file used to get wrong: the Archive pages that
// inc/archive/settings.php creates for each post type with an archive DO pass a
// custom post_id (their own menu slug), precisely so two archives with a
// same-named field (hero_title, ...) don't share one value. Anything reading or
// writing an options page's values must therefore use that page's own
// post_id (devbase_abilities_options_page_post_id() below), not a hardcoded
// 'option'. Writing to 'option' for an archive page saved the value where no
// template reads it, and the three archives overwrote each other there.

/**
 * Resolves the field groups + fields that belong to a given options page,
 * via the same acf_get_field_groups()/acf_get_fields() location-rule
 * resolution SCF's own REST /wp/v2/types extension uses for post types
 * (see inc/abilities/post-types.php) — just scoped by 'options_page'
 * instead of 'post_type'.
 *
 * @return array[] List of ['key' => field key, 'name' => ..., 'label' => ..., 'type' => ...].
 */
function devbase_abilities_options_page_fields( $menu_slug ) {
	$field_groups = acf_get_field_groups( array( 'options_page' => $menu_slug ) );
	$fields       = array();

	foreach ( $field_groups as $field_group ) {
		foreach ( (array) acf_get_fields( $field_group ) as $field ) {
			// Layout-only fields (tabs, messages) have no real value to
			// read or write — skip them.
			if ( in_array( $field['type'], array( 'tab', 'message' ), true ) ) {
				continue;
			}

			// The MCP allow-list controls themselves — never expose these
			// through MCP at all, on any page, regardless of what's
			// otherwise allowed. Without this, opting the Miscellaneous
			// page into MCP access (plausible — that's where retention
			// policy management lives) would let an MCP client rewrite its
			// own allow-list via devbase/update-options and grant itself
			// access to anything. Confirmed exploitable during testing.
			if ( in_array( $field['name'], devbase_abilities_protected_option_fields(), true ) ) {
				continue;
			}

			$fields[] = array(
				'key'   => $field['key'],
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
			);
		}
	}

	return $fields;
}

/**
 * The storage context an options page's values live in: the page's own
 * post_id when it has one (the Archive pages), else the shared 'option' store
 * every other page uses. ACF's default for a page with no explicit post_id is
 * 'options', which is the same store as 'option', so both spellings land in
 * the same place.
 */
function devbase_abilities_options_page_post_id( $menu_slug ) {
	$page = function_exists( 'acf_get_options_page' ) ? acf_get_options_page( $menu_slug ) : null;

	return ( is_array( $page ) && ! empty( $page['post_id'] ) ) ? $page['post_id'] : 'option';
}

/**
 * Whether menu_slug is a real, registered options page — the same
 * "reject an unknown identifier with a clear error" validation
 * devbase_abilities_content_post_types() gives post_type elsewhere,
 * since acf_get_field_groups()/acf_get_fields() silently return nothing
 * for an unrecognized slug rather than erroring, which would otherwise
 * make a typo indistinguishable from "this page genuinely has no fields."
 */
function devbase_abilities_is_registered_options_page( $menu_slug ) {
	foreach ( (array) acf_get_options_pages() as $page ) {
		if ( $page['menu_slug'] === $menu_slug ) {
			return true;
		}
	}

	return false;
}

/**
 * The full nested schema for a given options page's fields — repeater/group
 * sub_fields and conditional_logic included, unlike
 * devbase_abilities_options_page_fields() above (which stays a flat
 * top-level key/name list, since that's all get-options/update-options
 * need: get_field()/update_field() already handle a repeater's full nested
 * structure in one call given just its top-level key). Used only by
 * devbase/get-option-page-schema.
 */
function devbase_abilities_options_page_schema( $menu_slug ) {
	$field_groups = acf_get_field_groups( array( 'options_page' => $menu_slug ) );
	$fields       = array();

	foreach ( $field_groups as $field_group ) {
		foreach ( (array) acf_get_fields( $field_group ) as $field ) {
			// Same self-referential-control exclusion as
			// devbase_abilities_options_page_fields() — never even show
			// these as available fields, since writing them is blocked too.
			if ( in_array( $field['name'], devbase_abilities_protected_option_fields(), true ) ) {
				continue;
			}

			$fields[] = $field;
		}
	}

	return devbase_abilities_shape_fields( $fields );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'devbase/list-option-pages', array(
		'label'               => __( 'List Option Pages', 'devbase_parent_theme' ),
		'description'         => __( 'Lists every registered options page (theme settings page) on this site.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'menu_slug' => array( 'type' => 'string' ),
					'title'     => array( 'type' => 'string' ),
				),
			),
		),
		'execute_callback'    => function () {
			$pages   = acf_get_options_pages();
			$results = array();

			foreach ( (array) $pages as $page ) {
				if ( ! devbase_abilities_is_options_page_allowed( $page['menu_slug'] ) ) {
					continue;
				}

				$results[] = array(
					'menu_slug' => $page['menu_slug'],
					'title'     => $page['page_title'],
				);
			}

			return $results;
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array( 'public' => true ),
	) );

	wp_register_ability( 'devbase/get-option-page-schema', array(
		'label'               => __( 'Get Option Page Schema', 'devbase_parent_theme' ),
		'description'         => __( 'Describes the fields available on a given options page, from devbase/list-option-pages.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'menu_slug' => array( 'type' => 'string' ) ),
			'required'   => array( 'menu_slug' ),
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'execute_callback'    => function ( $input ) {
			if ( ! devbase_abilities_is_registered_options_page( $input['menu_slug'] ) ) {
				return new WP_Error( 'devbase_unknown_options_page', __( 'Unknown options page.', 'devbase_parent_theme' ) );
			}

			if ( ! devbase_abilities_is_options_page_allowed( $input['menu_slug'] ) ) {
				return new WP_Error( 'devbase_options_page_not_allowed', __( 'This options page is not enabled for MCP access. See Theme Settings > Miscellaneous > MCP Access.', 'devbase_parent_theme' ) );
			}

			return devbase_abilities_options_page_schema( $input['menu_slug'] );
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array( 'public' => true ),
	) );

	wp_register_ability( 'devbase/get-options', array(
		'label'               => __( 'Get Options', 'devbase_parent_theme' ),
		'description'         => __( 'Gets the current saved values for every field on a given options page.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'menu_slug' => array( 'type' => 'string' ) ),
			'required'   => array( 'menu_slug' ),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			if ( ! devbase_abilities_is_registered_options_page( $input['menu_slug'] ) ) {
				return new WP_Error( 'devbase_unknown_options_page', __( 'Unknown options page.', 'devbase_parent_theme' ) );
			}

			if ( ! devbase_abilities_is_options_page_allowed( $input['menu_slug'] ) ) {
				return new WP_Error( 'devbase_options_page_not_allowed', __( 'This options page is not enabled for MCP access. See Theme Settings > Miscellaneous > MCP Access.', 'devbase_parent_theme' ) );
			}

			$page_fields = devbase_abilities_options_page_fields( $input['menu_slug'] );
			$post_id     = devbase_abilities_options_page_post_id( $input['menu_slug'] );
			$values      = array();

			foreach ( $page_fields as $field ) {
				$values[ $field['name'] ] = get_field( $field['key'], $post_id );
			}

			return $values;
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array( 'public' => true ),
	) );

	wp_register_ability( 'devbase/update-options', array(
		'label'               => __( 'Update Options', 'devbase_parent_theme' ),
		'description'         => __( 'Updates one or more field values on a given options page. Only fields that actually belong to that page can be set.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'menu_slug' => array( 'type' => 'string' ),
				'fields'    => array(
					'type'        => 'object',
					'description' => __( 'Field values keyed by field name — see devbase/get-option-page-schema for the available fields.', 'devbase_parent_theme' ),
				),
			),
			'required'   => array( 'menu_slug', 'fields' ),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			if ( ! devbase_abilities_is_registered_options_page( $input['menu_slug'] ) ) {
				return new WP_Error( 'devbase_unknown_options_page', __( 'Unknown options page.', 'devbase_parent_theme' ) );
			}

			if ( ! devbase_abilities_is_options_page_allowed( $input['menu_slug'] ) ) {
				return new WP_Error( 'devbase_options_page_not_allowed', __( 'This options page is not enabled for MCP access. See Theme Settings > Miscellaneous > MCP Access.', 'devbase_parent_theme' ) );
			}

			$page_fields = devbase_abilities_options_page_fields( $input['menu_slug'] );
			$by_name     = array();

			foreach ( $page_fields as $field ) {
				$by_name[ $field['name'] ] = $field['key'];
			}

			$unknown = array_diff( array_keys( $input['fields'] ), array_keys( $by_name ) );

			if ( ! empty( $unknown ) ) {
				return new WP_Error(
					'devbase_unknown_field',
					sprintf(
						/* translators: %s: comma-separated list of field names that don't belong to this options page. */
						__( 'These fields do not belong to this options page: %s', 'devbase_parent_theme' ),
						implode( ', ', $unknown )
					)
				);
			}

			$post_id = devbase_abilities_options_page_post_id( $input['menu_slug'] );

			foreach ( $input['fields'] as $name => $value ) {
				update_field( $by_name[ $name ], $value, $post_id );
			}

			$updated = array();

			foreach ( array_keys( $input['fields'] ) as $name ) {
				$updated[ $name ] = get_field( $by_name[ $name ], $post_id );
			}

			return $updated;
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'meta'                => array(
			'public'      => true,
			'annotations' => array( 'destructive' => true, 'idempotent' => true ),
		),
	) );
} );
