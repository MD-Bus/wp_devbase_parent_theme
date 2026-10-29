<?php
// Covers inc/archive/seo.php: the SEO tab on an archive's options page and the title and
// description it prints on the archive's own URL.

class Test_Archive_SEO extends WP_UnitTestCase {

	const TYPE = 'devtest_seo';

	public function set_up() {
		parent::set_up();

		register_post_type( self::TYPE, array( 'public' => true, 'has_archive' => true, 'label' => 'Dev Test' ) );

		// More than one page of posts, so page 2 is a real page and not a 404.
		self::factory()->post->create_many( 12, array( 'post_type' => self::TYPE ) );

		devbase_archive_seo_register_fields( self::TYPE, 'archive-' . self::TYPE );
	}

	public function tear_down() {
		foreach ( array( 'seo_title', 'seo_description' ) as $name ) {
			delete_option( 'archive-' . self::TYPE . '_' . $name );
			delete_option( '_archive-' . self::TYPE . '_' . $name );
		}

		remove_all_actions( 'wp_head', 5 );
		unregister_post_type( self::TYPE );
		parent::tear_down();
	}

	private function set_seo( $title, $description ) {
		update_field( 'field_devbase_archive_seo_title_' . self::TYPE, $title, 'archive-' . self::TYPE );
		update_field( 'field_devbase_archive_seo_description_' . self::TYPE, $description, 'archive-' . self::TYPE );
	}

	private function go_archive( $paged = 1 ) {
		$this->go_to( home_url( '/?post_type=' . self::TYPE . ( $paged > 1 ? '&paged=' . $paged : '' ) ) );
	}

	/** What a theme with title-tag support prints first in wp_head: <title> from wp_get_document_title(). */
	private function print_title_tag() {
		add_action( 'wp_head', function () {
			echo '<title>' . esc_html( wp_get_document_title() ) . '</title>' . "\n";
		}, 1 );
	}

	private function head() {
		ob_start();
		do_action( 'wp_head' );

		return ob_get_clean();
	}

	// --- The fields -----------------------------------------------------------

	public function test_with_no_group_for_the_page_the_fields_get_a_small_group_of_their_own() {
		$title = acf_get_field( 'field_devbase_archive_seo_title_' . self::TYPE );

		$this->assertIsArray( $title );
		$this->assertSame( 'seo_title', $title['name'] );
		$this->assertSame( 'group_devbase_archive_seo_' . self::TYPE, $title['parent'] );
		$this->assertIsArray( acf_get_field( 'field_devbase_archive_seo_description_' . self::TYPE ) );
	}

	public function test_a_local_group_that_already_targets_the_page_gets_a_real_seo_tab() {
		$page = 'archive-devtest_seo_b';

		acf_add_local_field_group( array(
			'key'      => 'group_test_existing_b',
			'title'    => 'Existing',
			'fields'   => array( array( 'key' => 'field_test_existing_b', 'name' => 'hero_title', 'label' => 'Page Title', 'type' => 'text' ) ),
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $page ) ) ),
		) );

		devbase_archive_seo_register_fields( 'devtest_seo_b', $page );

		$tab   = acf_get_field( 'field_devbase_archive_seo_tab_devtest_seo_b' );
		$title = acf_get_field( 'field_devbase_archive_seo_title_devtest_seo_b' );

		$this->assertSame( 'tab', $tab['type'] );
		$this->assertSame( 'SEO', $tab['label'] );
		$this->assertSame( 'group_test_existing_b', $tab['parent'], 'The tab joins the existing group.' );
		$this->assertSame( 'group_test_existing_b', $title['parent'] );
		$this->assertFalse( acf_get_field_group( 'group_devbase_archive_seo_devtest_seo_b' ), 'No second box is made when a group was joined.' );

		$names = wp_list_pluck( acf_get_fields( 'group_test_existing_b' ), 'name' );
		$this->assertSame( array( 'hero_title', '', 'seo_title', 'seo_description' ), $names, 'The tab and its fields come after the existing ones.' );
	}

	public function test_registering_twice_does_not_add_the_fields_twice() {
		devbase_archive_seo_register_fields( self::TYPE, 'archive-' . self::TYPE );

		$this->assertCount( 2, acf_get_fields( 'group_devbase_archive_seo_' . self::TYPE ) );
	}

	// --- The title ------------------------------------------------------------

	public function test_the_archive_title_is_the_field() {
		$this->set_seo( 'Dolphin & Ocean News | Research, Education & Updates', '' );
		$this->go_archive();

		$this->assertSame( 'Dolphin & Ocean News | Research, Education & Updates', wp_get_document_title() );
	}

	public function test_later_pages_get_a_page_number_in_the_title() {
		$this->set_seo( 'Dolphin News', '' );
		$this->go_archive( 2 );

		$this->assertSame( 'Dolphin News – Page 2', wp_get_document_title() );
	}

	public function test_an_empty_title_leaves_the_default() {
		$this->set_seo( '', 'Only a description.' );
		$this->go_archive();

		$this->assertStringNotContainsString( 'Only a description', wp_get_document_title() );
		$this->assertNotSame( '', wp_get_document_title() );
	}

	public function test_the_title_tag_in_the_head_carries_it() {
		$this->set_seo( 'Dolphin & Co News', '' );
		$this->print_title_tag();
		$this->go_archive();

		$this->assertStringContainsString( '<title>Dolphin &amp; Co News</title>', $this->head() );
	}

	public function test_a_title_tag_printed_by_a_plugin_is_replaced_too() {
		$this->set_seo( 'Dolphin News', '' );
		add_action( 'wp_head', function () {
			echo '<title>Plugin title</title>' . "\n";
		}, 5 );
		$this->go_archive();

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, '<title>' ) );
		$this->assertStringContainsString( '<title>Dolphin News</title>', $head );
	}

	// --- The description ------------------------------------------------------

	public function test_the_description_is_added_when_nothing_else_prints_one() {
		$this->set_seo( '', 'Stories from the water.' );
		$this->go_archive();

		$head = $this->head();

		$this->assertSame( 1, substr_count( $head, 'name="description"' ) );
		$this->assertStringContainsString( '<meta name="description" content="Stories from the water." />', $head );
	}

	public function test_another_plugins_description_is_replaced_not_duplicated() {
		$this->set_seo( '', 'Ours.' );
		add_action( 'wp_head', function () {
			echo '<meta content="Plugin text" name="description">' . "\n";
		}, 5 );
		$this->go_archive();

		$head = $this->head();

		$this->assertSame( 1, substr_count( strtolower( $head ), 'name="description"' ), 'Only one description tag.' );
		$this->assertStringContainsString( 'content="Ours."', $head );
		$this->assertStringNotContainsString( 'Plugin text', $head );
	}

	public function test_share_tags_that_something_printed_follow_the_fields_but_none_are_added() {
		$this->set_seo( 'Share Title', 'Share text.' );
		add_action( 'wp_head', function () {
			echo '<meta property="og:title" content="Old">' . "\n" . '<meta name="twitter:description" content="Old too">' . "\n";
		}, 5 );
		$this->go_archive();

		$head = $this->head();

		$this->assertStringContainsString( '<meta property="og:title" content="Share Title" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:description" content="Share text." />', $head );
		$this->assertStringNotContainsString( 'og:description', $head, 'Nothing is added that nothing printed.' );
		$this->assertStringNotContainsString( 'twitter:title', $head );
	}

	public function test_the_description_shows_on_the_first_page_only() {
		$this->set_seo( 'Dolphin News', 'Stories from the water.' );
		$this->print_title_tag();
		add_action( 'wp_head', function () {
			echo '<meta name="description" content="Plugin text">' . "\n";
		}, 5 );
		$this->go_archive( 2 );

		$head = $this->head();

		$this->assertStringContainsString( 'content="Plugin text"', $head, 'Page 2 keeps what was printed.' );
		$this->assertStringNotContainsString( 'Stories from the water.', $head );
		$this->assertStringContainsString( 'Dolphin News – Page 2', $head );
	}

	public function test_an_empty_description_leaves_another_plugins_alone() {
		$this->set_seo( 'Dolphin News', '' );
		add_action( 'wp_head', function () {
			echo '<meta name="description" content="Plugin text">' . "\n";
		}, 5 );
		$this->go_archive();

		$this->assertStringContainsString( 'content="Plugin text"', $this->head() );
	}

	public function test_quotes_and_angle_brackets_in_the_fields_are_escaped() {
		$this->set_seo( 'A "quoted" <b>title</b>', 'Say "hi" <script>alert(1)</script>' );
		$this->go_archive();

		$head = $this->head();

		$this->assertStringNotContainsString( '<script>alert(1)', $head );
		$this->assertStringNotContainsString( '<b>title</b>', $head );
		$this->assertStringContainsString( '&quot;hi&quot;', $head );
	}

	// --- Where it does NOT apply ----------------------------------------------

	public function test_nothing_changes_when_neither_field_is_filled() {
		$this->go_archive();

		$this->assertNull( devbase_archive_seo_current() );
		$this->assertStringNotContainsString( 'name="description"', $this->head() );
	}

	public function test_a_page_that_is_not_an_archive_is_untouched() {
		$this->set_seo( 'Dolphin News', 'Stories.' );
		$this->go_to( home_url( '/' ) );

		$this->assertNull( devbase_archive_seo_current() );
		$this->assertStringNotContainsString( 'Stories.', $this->head() );
	}

	public function test_it_can_be_switched_off_for_a_post_type() {
		$this->set_seo( 'Dolphin News', 'Stories.' );
		add_filter( 'devbase/archive_seo/enabled', '__return_false' );
		$this->go_archive();

		$this->assertNull( devbase_archive_seo_current() );

		remove_filter( 'devbase/archive_seo/enabled', '__return_false' );
	}

	public function test_output_buffers_are_balanced_after_wp_head() {
		$this->set_seo( 'Dolphin News', 'Stories.' );
		$this->go_archive();

		$before = ob_get_level();
		$this->head();

		$this->assertSame( $before, ob_get_level() );
	}
}
