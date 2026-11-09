<?php
// Covers inc/archive/seo.php: the SEO tab on an archive's options page, and the title and description it
// hands to The SEO Framework (through its custom title and description filters) for the archive's URL.

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
		$this->assertSame( array( 'hero_title', '', '', 'seo_title', 'seo_description' ), $names, 'The tab, its note and its fields come after the existing ones.' );
	}

	public function test_registering_twice_does_not_add_the_fields_twice() {
		devbase_archive_seo_register_fields( self::TYPE, 'archive-' . self::TYPE );

		$this->assertCount( 3, acf_get_fields( 'group_devbase_archive_seo_' . self::TYPE ), 'A note and two fields, once.' );
	}

	// --- What The SEO Framework prints --------------------------------------------

	private function tsf_title() {
		return tsf()->title()->get_title();
	}

	private function tsf_description() {
		return tsf()->description()->get_description();
	}

	public function test_the_archive_title_is_the_field() {
		$this->set_seo( 'Dolphin & Ocean News | Research, Education & Updates', '' );
		$this->go_archive();

		$this->assertStringContainsString( 'Dolphin & Ocean News | Research, Education & Updates', $this->tsf_title() );
	}

	public function test_the_title_is_still_the_field_on_later_pages() {
		// The " - Page N" itself is TSF's (checked on the live site); ours only has to still be the title there.
		$this->set_seo( 'Dolphin News', '' );
		$this->go_archive( 2 );

		$this->assertStringContainsString( 'Dolphin News', $this->tsf_title() );
	}

	public function test_an_empty_title_leaves_tsfs_own() {
		$this->set_seo( '', 'Only a description.' );
		$this->go_archive();

		$this->assertStringNotContainsString( 'Only a description', $this->tsf_title() );
		$this->assertNotSame( '', $this->tsf_title() );
	}

	public function test_the_description_is_the_field_on_the_first_page() {
		$this->set_seo( '', 'Everything the research team published about dolphins.' );
		$this->go_archive();

		$this->assertSame( 'Everything the research team published about dolphins.', $this->tsf_description() );
	}

	public function test_the_description_is_for_the_first_page_only() {
		$this->set_seo( '', 'First page only.' );
		$this->go_archive( 2 );

		$this->assertStringNotContainsString( 'First page only', $this->tsf_description() );
	}

	public function test_an_empty_description_leaves_tsfs_own() {
		$this->set_seo( 'A title', '' );
		$this->go_archive();

		$this->assertSame( 'A title', trim( explode( ' - ', $this->tsf_title() )[0] ) );
		$this->assertStringNotContainsString( 'A title', $this->tsf_description() );
	}

	public function test_tsf_asking_for_a_specific_archive_gets_the_fields() {
		$this->set_seo( 'Asked by name', 'Described by name.' );

		// What TSF's own screens and the sitemap do: pass the archive's post type instead of a query.
		$this->assertStringContainsString( 'Asked by name', tsf()->title()->get_title( array( 'pta' => self::TYPE ) ) );
		$this->assertSame( 'Described by name.', tsf()->description()->get_description( array( 'pta' => self::TYPE ) ) );
	}

	public function test_a_page_that_is_not_an_archive_is_untouched() {
		$this->set_seo( 'Archive title', 'Archive description.' );
		$this->go_to( home_url( '/' ) );

		$this->assertStringNotContainsString( 'Archive title', $this->tsf_title() );
		$this->assertStringNotContainsString( 'Archive description', $this->tsf_description() );
	}

	public function test_it_can_be_switched_off_for_a_post_type() {
		$this->set_seo( 'Archive title', 'Archive description.' );
		$this->go_archive();
		add_filter( 'devbase/archive_seo/enabled', '__return_false' );

		$this->assertStringNotContainsString( 'Archive title', $this->tsf_title() );
		$this->assertStringNotContainsString( 'Archive description', $this->tsf_description() );

		remove_filter( 'devbase/archive_seo/enabled', '__return_false' );
	}

	public function test_a_type_without_an_archive_is_ignored() {
		unregister_post_type( self::TYPE );
		register_post_type( self::TYPE, array( 'public' => true, 'has_archive' => false ) );
		$this->set_seo( 'Archive title', '' );

		$this->assertSame( '', devbase_archive_seo_value( 'seo_title', array( 'pta' => self::TYPE ) ) );
	}
}
