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

	// --- Options pages with their OWN storage (the Archive pages) -----------
	//
	// inc/archive/settings.php gives every archive page a post_id equal to its
	// menu slug, so two archives with a same-named field don't share a value.
	// The options abilities used to read and write the shared 'option' store
	// regardless, so a value written through MCP to an archive page landed
	// where no template reads it, and the archives overwrote each other there.
	// These two pages reproduce that shape with a same-named field.

	private function register_own_storage_page( $slug ) {
		acf_add_options_sub_page( array(
			'page_title'  => $slug,
			'menu_title'  => $slug,
			'menu_slug'   => $slug,
			'parent_slug' => 'theme-settings',
			'post_id'     => $slug,
		) );

		acf_add_local_field_group( array(
			'key'      => 'group_' . $slug,
			'title'    => $slug,
			'fields'   => array(
				array(
					'key'  => 'field_' . $slug . '_title',
					'name' => 'hero_title',
					'type' => 'text',
				),
			),
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $slug ) ) ),
		) );
	}

	private function run_own_storage_pages() {
		$this->register_own_storage_page( 'test-archive-a' );
		$this->register_own_storage_page( 'test-archive-b' );
		update_field( 'mcp_allowed_options_pages', array( 'test-archive-a', 'test-archive-b' ), 'option' );
	}

	public function test_update_options_writes_to_the_pages_own_storage_not_the_shared_one() {
		$this->run_own_storage_pages();

		$result = wp_get_ability( 'devbase/update-options' )->execute( array(
			'menu_slug' => 'test-archive-a',
			'fields'    => array( 'hero_title' => 'Alpha' ),
		) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Alpha', get_field( 'hero_title', 'test-archive-a' ), 'The page\'s own storage (what its templates read) must hold the value.' );
		$this->assertEmpty( get_field( 'hero_title', 'option' ), 'The shared store must not receive it.' );

		update_field( 'hero_title', '', 'test-archive-a' );
	}

	public function test_two_pages_with_a_same_named_field_do_not_overwrite_each_other() {
		$this->run_own_storage_pages();

		foreach ( array( 'test-archive-a' => 'Alpha', 'test-archive-b' => 'Beta' ) as $slug => $value ) {
			wp_get_ability( 'devbase/update-options' )->execute( array( 'menu_slug' => $slug, 'fields' => array( 'hero_title' => $value ) ) );
		}

		$this->assertSame( 'Alpha', get_field( 'hero_title', 'test-archive-a' ) );
		$this->assertSame( 'Beta', get_field( 'hero_title', 'test-archive-b' ) );

		update_field( 'hero_title', '', 'test-archive-a' );
		update_field( 'hero_title', '', 'test-archive-b' );
	}

	public function test_get_options_reads_each_pages_own_storage() {
		$this->run_own_storage_pages();
		// Written by field KEY, the way the admin saves them, so each value has its reference.
		update_field( 'field_test-archive-a_title', 'Alpha', 'test-archive-a' );
		update_field( 'field_test-archive-b_title', 'Beta', 'test-archive-b' );

		$a = wp_get_ability( 'devbase/get-options' )->execute( array( 'menu_slug' => 'test-archive-a' ) );
		$b = wp_get_ability( 'devbase/get-options' )->execute( array( 'menu_slug' => 'test-archive-b' ) );

		$this->assertSame( 'Alpha', $a['hero_title'] );
		$this->assertSame( 'Beta', $b['hero_title'] );

		update_field( 'hero_title', '', 'test-archive-a' );
		update_field( 'hero_title', '', 'test-archive-b' );
	}

	public function test_a_page_without_its_own_post_id_still_uses_the_shared_store() {
		// ACF's default storage name for a page with no post_id is 'options', the same
		// store as 'option' (ACF treats the two as one), so either spelling is correct.
		$this->assertContains( devbase_abilities_options_page_post_id( 'miscellaneous-settings' ), array( 'option', 'options' ) );
		$this->assertContains( devbase_abilities_options_page_post_id( 'no-such-page' ), array( 'option', 'options' ) );
		$this->run_own_storage_pages();
		$this->assertSame( 'test-archive-a', devbase_abilities_options_page_post_id( 'test-archive-a' ) );
	}
}
