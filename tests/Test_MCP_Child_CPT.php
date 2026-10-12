<?php
// Does MCP actually WORK on a post type registered by a child theme — not
// just appear as a choice under Theme Settings > Miscellaneous > MCP Access?
//
// Those are two different claims, and only the first was ever verified
// (tests/Test_Child_CPT_Integration.php). This file covers the second: the
// full content cycle — list-post-types, get-post-type-schema, create-post,
// get-post, update-post, list-posts — against a CPT the base theme has
// never heard of.
//
// It matters because the content abilities don't touch post types
// directly: inc/abilities/posts.php builds a WP_REST_Request and dispatches
// it through rest_do_request(), reusing core's posts controller and SCF's
// own field read/write logic (see inc/abilities/AGENT.md for why). So a
// client CPT only works through MCP if it's genuinely REST-exposed —
// show_in_rest plus a resolvable rest_base — and nothing in the base theme
// can guarantee that on the child's behalf.
//
// Registered on 'init' at priority 10, exactly as the child starter's
// inc/content/post-types.php does it. Name kept under WordPress's 20-char
// cap; a longer one is refused via _doing_it_wrong() and never registers.

class Test_MCP_Child_CPT extends WP_UnitTestCase {

	const CPT = 'bw_client_svc';

	public function set_up() {
		parent::set_up();

		register_post_type( self::CPT, array(
			'labels'       => array( 'name' => 'Client Services', 'singular_name' => 'Client Service' ),
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'excerpt' ),
		) );

		// REST routes are built once, on 'rest_api_init'. Registering a post
		// type AFTER that has already fired does not retroactively create
		// its route — so every content ability would fail with
		// 'rest_no_route' (404), since they all dispatch through
		// rest_do_request(). In production the ordering is guaranteed
		// (init:10 runs long before rest_api_init); in a test suite it
		// depends on whether an earlier file already triggered the server.
		// Rebuilding it here makes this file pass identically alone and in
		// the full suite — found the hard way: 13/13 in isolation, 6
		// failures in the suite.
		devbase_tests_rebuild_rest_server();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Opt the client CPT in, as an admin would on the MCP Access tab.
		update_field( 'mcp_allowed_post_types', array( self::CPT ), 'option' );
	}

	public function tear_down() {
		update_field( 'mcp_allowed_post_types', array(), 'option' );
		unregister_post_type( self::CPT );

		// Leave a clean slate: the next file gets a server rebuilt without
		// this CPT, rather than inheriting one that still knows about it.
		devbase_tests_reset_rest_server();

		parent::tear_down();
	}

	public function test_list_post_types_includes_the_child_cpt_once_opted_in() {
		$names = wp_list_pluck( wp_get_ability( 'devbase/list-post-types' )->execute(), 'name' );

		$this->assertContains( self::CPT, $names );
	}

	public function test_it_is_invisible_to_mcp_until_opted_in() {
		update_field( 'mcp_allowed_post_types', array(), 'option' );

		$names = wp_list_pluck( wp_get_ability( 'devbase/list-post-types' )->execute(), 'name' );
		$this->assertNotContains( self::CPT, $names );

		// And the content abilities refuse it by name, not just hide it.
		$result = wp_get_ability( 'devbase/list-posts' )->execute( array( 'post_type' => self::CPT ) );
		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_post_type', $result->get_error_code() );
	}

	public function test_get_post_type_schema_works_for_it() {
		$result = wp_get_ability( 'devbase/get-post-type-schema' )->execute( array( 'post_type' => self::CPT ) );

		$this->assertIsArray( $result );

		// Note the key names, which are easy to get backwards: 'slug' is the
		// post type key, 'name' is the human label. (Asserting both so the
		// shape is pinned, since an MCP client reads these.)
		$this->assertSame( self::CPT, $result['slug'] );
		$this->assertSame( 'Client Services', $result['name'] );

		// rest_base is what the content abilities dispatch against — if this
		// were missing, create/get/update would all fail for this type.
		$this->assertSame( self::CPT, $result['rest_base'] );
		$this->assertSame( 'wp/v2', $result['rest_namespace'] );
		$this->assertArrayHasKey( 'scf_field_groups', $result );
	}

	public function test_create_post_really_creates_one_of_that_type() {
		$result = wp_get_ability( 'devbase/create-post' )->execute( array(
			'post_type' => self::CPT,
			'title'     => 'Roof Repair',
		) );

		$this->assertIsArray( $result, 'create-post must succeed for a REST-exposed child CPT.' );
		$this->assertSame( 'draft', $result['status'], 'Never auto-publish.' );

		// Confirmed against the database, not just the ability's own return.
		$post = get_post( $result['id'] );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertSame( self::CPT, $post->post_type );
		$this->assertSame( 'Roof Repair', $post->post_title );
	}

	public function test_get_post_reads_one_back() {
		$id = self::factory()->post->create( array(
			'post_type'   => self::CPT,
			'post_title'  => 'Gutter Cleaning',
			'post_status' => 'publish',
		) );

		$result = wp_get_ability( 'devbase/get-post' )->execute( array(
			'post_type' => self::CPT,
			'id'        => $id,
		) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( self::CPT, $result['type'] );

		// get-post returns WordPress's own REST shape verbatim — a
		// deliberate consequence of dispatching through rest_do_request()
		// rather than reimplementing reads (see inc/abilities/AGENT.md). So
		// title/content/excerpt arrive as {rendered: ...}, NOT flat strings.
		// Pinned here because an MCP client has to know which it gets.
		$this->assertSame( 'Gutter Cleaning', $result['title']['rendered'] );

		// SCF field values ride along on the same response.
		$this->assertArrayHasKey( 'acf', $result );
	}

	public function test_update_post_really_updates_one() {
		$id = self::factory()->post->create( array(
			'post_type'   => self::CPT,
			'post_title'  => 'Before',
			'post_status' => 'publish',
		) );

		$result = wp_get_ability( 'devbase/update-post' )->execute( array(
			'post_type' => self::CPT,
			'id'        => $id,
			'title'     => 'After',
		) );

		$this->assertIsArray( $result );
		$this->assertSame( 'After', get_post( $id )->post_title, 'The change must reach the database.' );
	}

	public function test_list_posts_returns_them() {
		$id = self::factory()->post->create( array(
			'post_type'   => self::CPT,
			'post_title'  => 'Listed Service',
			'post_status' => 'publish',
		) );

		$result = wp_get_ability( 'devbase/list-posts' )->execute( array(
			'post_type' => self::CPT,
			'status'    => 'publish',
		) );

		$this->assertIsArray( $result );
		$this->assertContains( $id, wp_list_pluck( $result, 'id' ) );
	}

	public function test_a_child_cpt_that_is_NOT_rest_exposed_cannot_be_opted_in() {
		// The one real limitation worth pinning down. The abilities go
		// through the REST API, so show_in_rest => false isn't a degraded
		// experience — it's unusable. It is therefore never offered as a
		// choice in the first place, which is the honest behaviour.
		register_post_type( 'bw_private_cpt', array(
			'labels'       => array( 'name' => 'Private Things' ),
			'public'       => true,
			'show_in_rest' => false,
		) );

		update_field( 'mcp_allowed_post_types', array( 'bw_private_cpt' ), 'option' );

		$this->assertArrayNotHasKey(
			'bw_private_cpt',
			devbase_abilities_content_post_types(),
			'A post type with show_in_rest => false must never be MCP-exposed, even if somehow saved into the allow-list.'
		);

		unregister_post_type( 'bw_private_cpt' );
	}

	// --- SCF custom fields on a child CPT --------------------------------
	//
	// The actual use case. A client "Services" CPT isn't just a title and
	// body — it has custom fields, and the point of MCP access is editing
	// those. inc/abilities/ never writes fields itself: it dispatches
	// through SCF's own REST `acf` field, so this verifies that path end to
	// end for a post type the base theme has never heard of.

	private function register_field_group() {
		return acf_import_field_group( array(
			'key'          => 'group_bw_client_svc_fields',
			'title'        => 'Service Fields',
			'fields'       => array(
				array(
					'key'   => 'field_bw_svc_price',
					'name'  => 'service_price',
					'label' => 'Price',
					'type'  => 'text',
				),
				array(
					'key'   => 'field_bw_svc_featured',
					'name'  => 'service_featured',
					'label' => 'Featured',
					'type'  => 'true_false',
				),
			),
			'location'     => array(
				array( array( 'param' => 'post_type', 'operator' => '==', 'value' => self::CPT ) ),
			),
			'show_in_rest' => 1,
		) );
	}

	public function test_the_schema_reports_a_child_cpts_custom_fields() {
		$group = $this->register_field_group();

		$schema = wp_get_ability( 'devbase/get-post-type-schema' )->execute( array( 'post_type' => self::CPT ) );

		wp_delete_post( $group['ID'], true );

		$names = wp_list_pluck( $schema['scf_field_groups'][0]['fields'], 'name' );

		$this->assertContains( 'service_price', $names );
		$this->assertContains( 'service_featured', $names );
	}

	public function test_create_post_can_set_custom_field_values() {
		$group = $this->register_field_group();

		$result = wp_get_ability( 'devbase/create-post' )->execute( array(
			'post_type' => self::CPT,
			'title'     => 'Roof Repair',
			'fields'    => array(
				'service_price'    => '450',
				'service_featured' => true,
			),
		) );

		$this->assertIsArray( $result, 'create-post with fields must succeed for a child CPT.' );

		// Read back through SCF itself, not the ability's own return — the
		// question is whether the value actually landed on the post.
		$this->assertSame( '450', get_field( 'service_price', $result['id'] ) );
		$this->assertTrue( (bool) get_field( 'service_featured', $result['id'] ) );

		wp_delete_post( $group['ID'], true );
	}

	public function test_update_post_can_change_custom_field_values() {
		$group = $this->register_field_group();

		$id = self::factory()->post->create( array(
			'post_type'   => self::CPT,
			'post_title'  => 'Gutter Cleaning',
			'post_status' => 'publish',
		) );
		update_field( 'service_price', '100', $id );

		$result = wp_get_ability( 'devbase/update-post' )->execute( array(
			'post_type' => self::CPT,
			'id'        => $id,
			'fields'    => array( 'service_price' => '175' ),
		) );

		$this->assertIsArray( $result );
		$this->assertSame( '175', get_field( 'service_price', $id ), 'The new field value must reach the database.' );

		wp_delete_post( $group['ID'], true );
	}

	public function test_an_unknown_field_name_is_rejected_rather_than_silently_dropped() {
		// SCF's REST `acf` field silently ignores keys it doesn't recognise,
		// which once meant create/update reported success while doing
		// nothing. inc/abilities/ validates names first — this confirms that
		// still holds for a child CPT's own field group, not just the base
		// theme's.
		$group = $this->register_field_group();

		$result = wp_get_ability( 'devbase/create-post' )->execute( array(
			'post_type' => self::CPT,
			'title'     => 'Bad Fields',
			'fields'    => array( 'not_a_real_field' => 'x' ),
		) );

		wp_delete_post( $group['ID'], true );

		$this->assertWPError( $result, 'An unknown field name must error, never report success.' );
		$this->assertSame( 'devbase_unknown_field', $result->get_error_code() );
	}

	public function test_get_post_returns_a_child_cpts_field_values() {
		$group = $this->register_field_group();

		$id = self::factory()->post->create( array(
			'post_type'   => self::CPT,
			'post_title'  => 'Window Fitting',
			'post_status' => 'publish',
		) );
		update_field( 'service_price', '999', $id );

		$result = wp_get_ability( 'devbase/get-post' )->execute( array(
			'post_type' => self::CPT,
			'id'        => $id,
		) );

		wp_delete_post( $group['ID'], true );

		$this->assertSame( '999', $result['acf']['service_price'] );
	}
}
