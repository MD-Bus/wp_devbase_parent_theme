<?php
// Covers the contract a client child theme depends on most: register a post
// type, and the base theme's existing features pick it up with no further
// wiring.
//
// There are three of those features and they're in three different folders,
// which is exactly why this is one test file rather than additions to each:
// what's being tested is the SEAM, not any one of them.
//
//   inc/archive/settings.php    — an Archive options sub-page per CPT
//   inc/archive/link-query.php  — the archive in the internal link picker
//   inc/abilities/helpers.php   — offered to MCP, but not exposed by default
//
// The timing assertion is the valuable one. inc/archive/ deliberately runs
// on 'wp_loaded' rather than 'acf/init', because acf/init fires inside
// 'init' at priority 5 — before a CPT registered the ordinary way on 'init'
// at priority 10 exists. Get that wrong and a client's archive options page
// silently never appears. See inc/archive/AGENT.md.

class Test_Child_CPT_Integration extends WP_UnitTestCase {

	// Max 20 characters — WordPress refuses anything longer, and does it as
	// a _doing_it_wrong() notice rather than an exception, so a too-long name
	// simply never registers and every assertion downstream fails confusingly.
	const CPT = 'bw_test_service';

	public function set_up() {
		parent::set_up();

		// Registered on 'init' at the default priority 10 — the same way a
		// child theme's inc/content/post-types.php does it, and the case
		// the parent's hook timing has to accommodate.
		register_post_type( self::CPT, array(
			'labels'       => array( 'name' => 'Test Services', 'singular_name' => 'Test Service' ),
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor' ),
			'rewrite'      => array( 'slug' => 'devbase-test-services' ),
		) );
	}

	public function tear_down() {
		unregister_post_type( self::CPT );
		parent::tear_down();
	}

	public function test_an_archive_options_page_is_created_for_it() {
		// inc/archive/settings.php registers the sub-pages on 'wp_loaded'
		// and acf_add_options_sub_page() only queues them into an array
		// acf_get_options_pages() reads back — so firing wp_loaded is both
		// necessary and sufficient here.
		//
		// Deliberately NOT firing 'acf/init' as well: SCF already ran it
		// during bootstrap, and a second run re-registers its 'acf/field'
		// block binding source, which WordPress reports via
		// _doing_it_wrong() and wp-phpunit converts into a test failure.
		// Harness artifact, not a bug.
		do_action( 'wp_loaded' );

		$pages = acf_get_options_pages();

		$this->assertArrayHasKey(
			'archive-' . self::CPT,
			$pages,
			'A CPT registered on init:10 must still get an archive page — this is why inc/archive/ uses wp_loaded, not acf/init.'
		);
	}

	public function test_that_archive_page_has_isolated_storage() {
		do_action( 'wp_loaded' );

		$pages = acf_get_options_pages();
		$page  = $pages[ 'archive-' . self::CPT ];

		// Without an explicit post_id, every options page shares the single
		// 'options' store, so two CPT archives with a same-named field would
		// silently read and write the same value.
		$this->assertSame(
			'archive-' . self::CPT,
			$page['post_id'],
			'Each CPT archive needs its own storage key, or same-named fields collide across archives.'
		);
	}

	public function test_its_archive_is_injected_into_the_internal_link_picker() {
		// An archive isn't a row in wp_posts, so core's own link search can
		// never find it — inc/archive/link-query.php appends it.
		$results = apply_filters( 'wp_link_query', array(), array( 's' => 'Test Services', 'offset' => 0 ) );

		// Matched on the title rather than the URL: this test environment
		// uses plain permalinks, so get_post_type_archive_link() returns
		// ?post_type=... instead of the rewrite slug. The title and the
		// 'Archive' info column are what the picker actually shows.
		$found = null;
		foreach ( (array) $results as $result ) {
			if ( 'Test Services' === ( $result['title'] ?? '' ) ) {
				$found = $result;
			}
		}

		$this->assertNotNull( $found, 'The CPT archive should appear in link-picker results.' );
		$this->assertSame( __( 'Archive', 'devbase_parent_theme' ), $found['info'] );
		$this->assertNotEmpty( $found['permalink'] );
		$this->assertLessThan(
			0,
			$found['ID'],
			'A synthetic result must use a negative ID so it can never collide with a real post.'
		);
	}

	public function test_it_is_not_injected_past_the_first_page_of_results() {
		$titles = function ( $offset ) {
			$results = apply_filters( 'wp_link_query', array(), array( 's' => 'Test Services', 'offset' => $offset ) );
			return wp_list_pluck( (array) $results, 'title' );
		};

		// Asserting BOTH halves, so this can't pass vacuously just because
		// the filter returned nothing at all.
		$this->assertContains( 'Test Services', $titles( 0 ), 'Page 1 should include the archive.' );
		$this->assertNotContains(
			'Test Services',
			$titles( 10 ),
			'Archives must only be appended to page 1, or they repeat on every paginated page.'
		);
	}

	public function test_it_is_offered_to_mcp_but_not_exposed_until_opted_in() {
		$field = apply_filters(
			'acf/load_field/key=field_devbase_mcp_allowed_post_types',
			array( 'key' => 'field_devbase_mcp_allowed_post_types', 'choices' => array() )
		);

		$this->assertArrayHasKey(
			self::CPT,
			$field['choices'],
			'A new CPT should be selectable under Theme Settings > Miscellaneous > MCP Access.'
		);

		update_field( 'mcp_allowed_post_types', array(), 'option' );

		$this->assertArrayNotHasKey(
			self::CPT,
			devbase_abilities_content_post_types(),
			'Nothing may be MCP-readable or MCP-writable until an admin explicitly opts it in.'
		);
	}

	public function test_opting_it_in_exposes_it() {
		update_field( 'mcp_allowed_post_types', array( self::CPT ), 'option' );

		$this->assertArrayHasKey( self::CPT, devbase_abilities_content_post_types() );

		update_field( 'mcp_allowed_post_types', array(), 'option' );
	}
}
