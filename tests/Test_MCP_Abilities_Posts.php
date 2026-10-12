<?php
// Covers inc/abilities/posts.php: devbase/list-posts, get-post,
// create-post, update-post. Abilities are invoked the same way a real MCP
// call does — wp_get_ability( $name )->execute( $input ) — running the
// real Abilities API pipeline (input validation, permission_callback,
// execute_callback), not just calling the underlying PHP functions.

class Test_MCP_Abilities_Posts extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		update_field( 'mcp_allowed_post_types', array( 'page' ), 'option' );
	}

	public function tear_down() {
		update_field( 'mcp_allowed_post_types', array(), 'option' );
		parent::tear_down();
	}

	public function test_list_posts_rejects_a_not_allowed_post_type() {
		$result = wp_get_ability( 'devbase/list-posts' )->execute( array( 'post_type' => 'post' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_post_type', $result->get_error_code() );
	}

	public function test_list_posts_returns_real_posts_of_the_allowed_type() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'A Real Page', 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'devbase/list-posts' )->execute( array( 'post_type' => 'page', 'status' => 'publish' ) );

		$this->assertIsArray( $result );
		$ids = wp_list_pluck( $result, 'id' );
		$this->assertContains( $page_id, $ids );
	}

	public function test_create_post_defaults_to_draft_status() {
		$result = wp_get_ability( 'devbase/create-post' )->execute( array( 'post_type' => 'page', 'title' => 'A New Page' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'], 'create-post must default to draft — never auto-publish.' );
	}

	public function test_create_post_rejects_an_unknown_field_name() {
		// Regression test: this used to silently succeed with the bogus
		// field simply absent from the response, no error at all — same
		// failure shape as a bug independently found in SCF's own
		// scf/create-field ability.
		$result = wp_get_ability( 'devbase/create-post' )->execute( array(
			'post_type' => 'page',
			'title'     => 'Should Not Be Created',
			'fields'    => array( 'totally_bogus_field' => 'x' ),
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_field', $result->get_error_code() );
	}

	public function test_create_and_update_post_round_trip_real_field_values() {
		$group = acf_import_field_group( array(
			'key'      => 'group_test_posts_roundtrip',
			'title'    => 'Test Posts Round Trip',
			'fields'   => array(
				array( 'key' => 'field_test_subtitle', 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text' ),
			),
			'location' => array(
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ),
			),
			'show_in_rest' => 1,
		) );

		$created = wp_get_ability( 'devbase/create-post' )->execute( array(
			'post_type' => 'page',
			'title'     => 'Round Trip Page',
			'fields'    => array( 'subtitle' => 'Original Subtitle' ),
		) );

		$this->assertIsArray( $created );
		$this->assertSame( 'Original Subtitle', $created['acf']['subtitle'] );

		$updated = wp_get_ability( 'devbase/update-post' )->execute( array(
			'post_type' => 'page',
			'id'        => $created['id'],
			'fields'    => array( 'subtitle' => 'Changed Subtitle' ),
		) );

		$this->assertSame( 'Changed Subtitle', $updated['acf']['subtitle'] );

		$fetched = wp_get_ability( 'devbase/get-post' )->execute( array( 'post_type' => 'page', 'id' => $created['id'] ) );
		$this->assertSame( 'Changed Subtitle', $fetched['acf']['subtitle'], 'The updated value must persist and be readable via get-post.' );

		wp_delete_post( $group['ID'], true );
		wp_delete_post( $created['id'], true );
	}

	public function test_update_post_rejects_an_unknown_field_name() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = wp_get_ability( 'devbase/update-post' )->execute( array(
			'post_type' => 'page',
			'id'        => $page_id,
			'fields'    => array( 'totally_bogus_field' => 'x' ),
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_field', $result->get_error_code() );
	}

	public function test_get_post_allows_a_user_who_can_only_read_not_edit() {
		// Regression test: get-post's permission_callback used to check the
		// edit_post capability for what is a read operation — wrongly
		// blocking a user who can legitimately read a post but not edit it.
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => wp_get_current_user()->ID ) );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertTrue( current_user_can( 'read_post', $page_id ), 'Sanity check: a subscriber can read a published page.' );
		$this->assertFalse( current_user_can( 'edit_post', $page_id ), 'Sanity check: a subscriber cannot edit a page they don\'t own.' );

		$ability = wp_get_ability( 'devbase/get-post' );
		$this->assertTrue( $ability->check_permissions( array( 'post_type' => 'page', 'id' => $page_id ) ), 'get-post must use the read capability, not the edit capability, so a read-only user is not wrongly denied.' );
	}
}
