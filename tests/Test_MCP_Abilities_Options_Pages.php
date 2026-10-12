<?php
// Covers inc/abilities/options-pages.php: devbase/list-option-pages,
// get-option-page-schema, get-options, update-options. Abilities are
// invoked the same way a real MCP call does —
// wp_get_ability( $name )->execute( $input ) — running the real
// Abilities API pipeline (input validation, permission_callback,
// execute_callback).

class Test_MCP_Abilities_Options_Pages extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tear_down() {
		update_field( 'mcp_allowed_post_types', array(), 'option' );
		update_field( 'mcp_allowed_options_pages', array(), 'option' );
		update_field( 'retention_enabled', 0, 'option' );
		update_field( 'retention_policies', array(), 'option' );
		parent::tear_down();
	}

	public function test_list_option_pages_is_empty_by_default() {
		$result = wp_get_ability( 'devbase/list-option-pages' )->execute();

		$this->assertSame( array(), $result, 'Nothing should be exposed to MCP until explicitly allowed — the opt-in default.' );
	}

	public function test_list_option_pages_returns_only_allowed_pages() {
		update_field( 'mcp_allowed_options_pages', array( 'miscellaneous-settings' ), 'option' );

		$result = wp_get_ability( 'devbase/list-option-pages' )->execute();

		$slugs = wp_list_pluck( $result, 'menu_slug' );
		$this->assertContains( 'miscellaneous-settings', $slugs );
		$this->assertNotContains( 'smtp-settings', $slugs, 'A not-allowed page must not be listed.' );
	}

	public function test_get_options_distinguishes_unknown_from_not_allowed() {
		$unknown = wp_get_ability( 'devbase/get-options' )->execute( array( 'menu_slug' => 'this-page-does-not-exist' ) );
		$this->assertWPError( $unknown );
		$this->assertSame( 'devbase_unknown_options_page', $unknown->get_error_code() );

		// Real, registered page — just not opted in.
		$not_allowed = wp_get_ability( 'devbase/get-options' )->execute( array( 'menu_slug' => 'miscellaneous-settings' ) );
		$this->assertWPError( $not_allowed );
		$this->assertSame( 'devbase_options_page_not_allowed', $not_allowed->get_error_code(), 'A real but not-opted-in page must get a different, more specific error than an unknown one.' );
	}

	public function test_update_options_rejects_an_unknown_field_name() {
		update_field( 'mcp_allowed_options_pages', array( 'miscellaneous-settings' ), 'option' );

		$result = wp_get_ability( 'devbase/update-options' )->execute( array(
			'menu_slug' => 'miscellaneous-settings',
			'fields'    => array( 'totally_bogus_field' => 'x' ),
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_field', $result->get_error_code() );
	}

	public function test_update_options_round_trips_a_real_field() {
		update_field( 'mcp_allowed_options_pages', array( 'miscellaneous-settings' ), 'option' );

		$result = wp_get_ability( 'devbase/update-options' )->execute( array(
			'menu_slug' => 'miscellaneous-settings',
			'fields'    => array( 'retention_enabled' => true ),
		) );

		$this->assertIsArray( $result );
		$this->assertTrue( (bool) $result['retention_enabled'] );
		$this->assertTrue( (bool) get_field( 'retention_enabled', 'option' ), 'The value must actually persist, not just echo back in the response.' );
	}

	public function test_allow_list_fields_can_never_be_read_or_written_through_mcp_even_on_an_allowed_page() {
		// Regression test for a confirmed-exploitable self-escalation bug:
		// mcp_allowed_post_types/mcp_allowed_options_pages live as regular
		// ACF fields on the Miscellaneous page. Once that page is allowed,
		// they used to be readable/writable like any other field — meaning
		// an MCP client could call update-options and rewrite its own
		// allow-list to grant itself access to anything. Confirmed
		// exploitable during manual testing (expanded ['page'] to
		// ['page', 'post', 'attachment'] via a real update-options call).
		update_field( 'mcp_allowed_options_pages', array( 'miscellaneous-settings' ), 'option' );
		update_field( 'mcp_allowed_post_types', array( 'page' ), 'option' );

		$schema = wp_get_ability( 'devbase/get-option-page-schema' )->execute( array( 'menu_slug' => 'miscellaneous-settings' ) );
		$schema_names = wp_list_pluck( $schema, 'name' );
		$this->assertNotContains( 'mcp_allowed_post_types', $schema_names, 'The allow-list must never appear in the schema, even for an allowed page.' );
		$this->assertNotContains( 'mcp_allowed_options_pages', $schema_names, 'The allow-list must never appear in the schema, even for an allowed page.' );

		$values = wp_get_ability( 'devbase/get-options' )->execute( array( 'menu_slug' => 'miscellaneous-settings' ) );
		$this->assertArrayNotHasKey( 'mcp_allowed_post_types', $values, 'The allow-list must never be readable through MCP, even for an allowed page.' );
		$this->assertArrayNotHasKey( 'mcp_allowed_options_pages', $values, 'The allow-list must never be readable through MCP, even for an allowed page.' );

		$attempt = wp_get_ability( 'devbase/update-options' )->execute( array(
			'menu_slug' => 'miscellaneous-settings',
			'fields'    => array( 'mcp_allowed_post_types' => array( 'page', 'post', 'attachment' ) ),
		) );
		$this->assertWPError( $attempt, 'Attempting to write the allow-list through MCP must be rejected, not silently succeed.' );
		$this->assertSame( array( 'page' ), get_field( 'mcp_allowed_post_types', 'option' ), 'The real allow-list must be completely unchanged after a rejected write attempt.' );
	}
}
