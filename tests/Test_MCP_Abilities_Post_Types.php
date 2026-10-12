<?php
// Covers inc/abilities/post-types.php + the shared allow-list logic in
// inc/abilities/helpers.php: devbase/list-post-types and
// devbase/get-post-type-schema. Abilities are invoked the same way a
// real MCP call does — wp_get_ability( $name )->execute( $input ) — which
// runs the real WordPress Abilities API pipeline (input validation,
// permission_callback, then the execute_callback), not just calling the
// underlying PHP functions directly.

class Test_MCP_Abilities_Post_Types extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tear_down() {
		update_field( 'mcp_allowed_post_types', array(), 'option' );
		update_field( 'mcp_allowed_options_pages', array(), 'option' );
		parent::tear_down();
	}

	public function test_list_post_types_is_empty_by_default() {
		$result = wp_get_ability( 'devbase/list-post-types' )->execute();

		$this->assertSame( array(), $result, 'Nothing should be exposed to MCP until explicitly allowed — the opt-in default.' );
	}

	public function test_list_post_types_returns_only_allowed_types() {
		update_field( 'mcp_allowed_post_types', array( 'page' ), 'option' );

		$result = wp_get_ability( 'devbase/list-post-types' )->execute();

		$this->assertCount( 1, $result );
		$this->assertSame( 'page', $result[0]['name'] );
	}

	public function test_registered_form_and_form_data_are_never_listable_even_if_forced_into_the_allow_list() {
		// Simulates a hypothetical bug where these ever ended up in the saved
		// option (they can never actually be selected via the real settings
		// UI, since devbase_abilities_excluded_post_types() keeps them out
		// of the picker's choices) — the hardcoded exclusion in
		// devbase_abilities_content_post_types() must still win.
		update_field( 'mcp_allowed_post_types', array( 'page', 'registered_form', 'form_data' ), 'option' );

		$names = wp_list_pluck( wp_get_ability( 'devbase/list-post-types' )->execute(), 'name' );

		$this->assertContains( 'page', $names );
		$this->assertNotContains( 'registered_form', $names, 'registered_form holds submission PII and must never be MCP-exposed, regardless of the allow-list.' );
		$this->assertNotContains( 'form_data', $names, 'form_data holds submission PII and must never be MCP-exposed, regardless of the allow-list.' );
	}

	public function test_list_post_types_excludes_internal_wordpress_post_types() {
		// Regression test: show_in_rest alone also matches WordPress's own
		// internal site-editor post types (wp_navigation, wp_template,
		// wp_font_family, ...) — caught during manual testing before the
		// public => true filter was added.
		update_field( 'mcp_allowed_post_types', array( 'wp_navigation', 'wp_template', 'page' ), 'option' );

		$names = wp_list_pluck( wp_get_ability( 'devbase/list-post-types' )->execute(), 'name' );

		$this->assertContains( 'page', $names );
		$this->assertNotContains( 'wp_navigation', $names, 'Internal, non-public post types must never be MCP-exposed even if somehow present in the saved option.' );
		$this->assertNotContains( 'wp_template', $names, 'Internal, non-public post types must never be MCP-exposed even if somehow present in the saved option.' );
	}

	public function test_get_post_type_schema_rejects_a_not_allowed_post_type() {
		// 'post' is a real, valid post type — just not opted in.
		$result = wp_get_ability( 'devbase/get-post-type-schema' )->execute( array( 'post_type' => 'post' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_post_type', $result->get_error_code() );
	}

	public function test_get_post_type_schema_includes_nested_sub_fields_and_readable_conditional_logic() {
		update_field( 'mcp_allowed_post_types', array( 'page' ), 'option' );

		$group = acf_import_field_group( array(
			'key'      => 'group_test_schema_nesting',
			'title'    => 'Test Schema Nesting',
			'fields'   => array(
				array(
					'key'   => 'field_test_toggle',
					'name'  => 'test_toggle',
					'label' => 'Toggle',
					'type'  => 'true_false',
				),
				array(
					'key'               => 'field_test_repeater',
					'name'              => 'test_repeater',
					'label'             => 'Repeater',
					'type'              => 'repeater',
					'conditional_logic' => array(
						array(
							array( 'field' => 'field_test_toggle', 'operator' => '==', 'value' => '1' ),
						),
					),
					'sub_fields'        => array(
						array( 'key' => 'field_test_sub', 'name' => 'sub_text', 'label' => 'Sub Text', 'type' => 'text' ),
					),
				),
			),
			'location' => array(
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ),
			),
			'show_in_rest' => 1,
		) );

		$result = wp_get_ability( 'devbase/get-post-type-schema' )->execute( array( 'post_type' => 'page' ) );

		wp_delete_post( $group['ID'], true );

		$this->assertIsArray( $result );
		$fields = $result['scf_field_groups'][0]['fields'];
		$repeater = current( array_filter( $fields, fn( $f ) => 'test_repeater' === $f['name'] ) );

		$this->assertNotFalse( $repeater, 'The repeater field must appear in the schema.' );
		$this->assertSame( array( array( array( 'field' => 'test_toggle', 'operator' => '==', 'value' => '1' ) ) ), $repeater['conditional_on'], 'conditional_logic must be resolved to the readable field name, not the raw internal field key.' );
		$this->assertSame( array( array( 'name' => 'sub_text', 'label' => 'Sub Text', 'type' => 'text' ) ), $repeater['sub_fields'], 'A repeater\'s sub_fields must appear in the schema, not just an opaque "type: repeater".' );
	}
}
