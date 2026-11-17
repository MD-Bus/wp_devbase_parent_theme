<?php
// Covers inc/guide/guide.php: the Guide section (Help group, readable by Editors), this theme's chapters and their
// templates and screenshots, who sees which chapter, the child's chapters landing on the "This site" tab, a tab shown only
// when it has a chapter for the reader, the open tab, the guide's addresses, the screenshot lookup (child first), the
// translated template names, and the page itself.

class Test_Guide extends WP_UnitTestCase {

	/** @var string[] Files a test created, removed afterwards. */
	private $files = array();

	public function tear_down() {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		unset( $_GET['tab'], $_GET['section'], $_GET['page'] );
		remove_all_filters( 'devbase/guide/chapters' );
		remove_all_filters( 'devbase/guide/tabs' );
		parent::tear_down();
	}

	private function as_role( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function add_child_chapter( $id = 'news', array $extra = array() ) {
		add_filter( 'devbase/guide/chapters', function ( $chapters ) use ( $id, $extra ) {
			$chapters[ $id ] = array_merge( array(
				'title'  => 'News articles',
				'render' => function () {
					echo '<p>Child chapter body.</p>';
				},
			), $extra );
			return $chapters;
		} );
	}

	// --- the section ---------------------------------------------------------------------------

	public function test_the_guide_is_a_section_of_the_help_group_that_editors_can_open() {
		$this->as_role( 'editor' );

		$sections = devbase_app_sections();

		$this->assertArrayHasKey( DEVBASE_GUIDE_SECTION, $sections );
		$this->assertSame( 'help', $sections[ DEVBASE_GUIDE_SECTION ]['group'] );
		$this->assertSame( 'edit_posts', $sections[ DEVBASE_GUIDE_SECTION ]['capability'] );
		$this->assertArrayHasKey( 'help', devbase_app_groups() );
	}

	public function test_a_subscriber_does_not_get_the_guide() {
		$this->as_role( 'subscriber' );

		$this->assertArrayNotHasKey( DEVBASE_GUIDE_SECTION, devbase_app_sections() );
	}

	// --- this theme's chapters -----------------------------------------------------------------

	public function test_every_theme_chapter_has_its_template_and_every_screenshot_it_names_exists() {
		$views = get_template_directory() . '/views/';

		foreach ( devbase_guide_theme_chapters() as $id => $chapter ) {
			$file = $views . 'devbase-guide/theme/' . $id . '.twig';
			$this->assertFileExists( $file, "Chapter {$id} has no template." );

			preg_match_all( "/shot\\(\\s*'([a-z0-9-]+)'/", (string) file_get_contents( $file ), $m );

			foreach ( $m[1] as $name ) {
				$this->assertNotSame( '', devbase_guide_shot( $name ), "{$id}: screenshot {$name} is missing from assets/guide." );
			}
		}
	}

	public function test_an_administrator_gets_every_theme_chapter_on_the_theme_tab() {
		$this->as_role( 'administrator' );
		add_filter( 'devbase/tsf/enabled', '__return_true' );

		$ids = array_keys( devbase_guide_chapters( 'theme' ) );

		$this->assertSame( array_keys( devbase_guide_theme_chapters() ), $ids );
		remove_filter( 'devbase/tsf/enabled', '__return_true' );
	}

	public function test_an_editor_gets_only_the_chapters_for_their_role() {
		$this->as_role( 'editor' );
		add_filter( 'devbase/tsf/enabled', '__return_true' );

		$this->assertSame( array( 'settings', 'images', 'seo', 'trouble' ), array_keys( devbase_guide_chapters( 'theme' ) ) );
		remove_filter( 'devbase/tsf/enabled', '__return_true' );
	}

	public function test_the_seo_chapter_follows_the_seo_switch() {
		$this->as_role( 'administrator' );
		add_filter( 'devbase/tsf/enabled', '__return_false' );

		$this->assertArrayNotHasKey( 'seo', devbase_guide_chapters( 'theme' ) );
		remove_filter( 'devbase/tsf/enabled', '__return_false' );
	}

	// --- a child's chapters and the tabs -------------------------------------------------------

	public function test_without_a_child_chapter_there_is_only_the_theme_tab() {
		$this->as_role( 'administrator' );

		$this->assertSame( array( 'theme' ), array_keys( devbase_guide_visible_tabs() ) );
		$this->assertSame( 'theme', devbase_guide_current_tab() );
	}

	public function test_a_child_chapter_lands_on_this_site_which_then_opens_first() {
		$this->as_role( 'administrator' );
		$this->add_child_chapter();

		$all = devbase_guide_all_chapters();

		$this->assertSame( 'site', $all['news']['tab'] );
		$this->assertSame( array( 'site', 'theme' ), array_keys( devbase_guide_visible_tabs() ) );
		$this->assertSame( 'site', devbase_guide_current_tab() );
		$this->assertSame( array( 'news' ), array_keys( devbase_guide_chapters( 'site' ) ) );
	}

	public function test_the_tab_asked_for_opens_and_an_unknown_one_falls_back_to_the_first() {
		$this->as_role( 'administrator' );
		$this->add_child_chapter();

		$_GET['tab'] = 'theme';
		$this->assertSame( 'theme', devbase_guide_current_tab() );

		$_GET['tab'] = 'nope';
		$this->assertSame( 'site', devbase_guide_current_tab() );
	}

	public function test_a_child_chapter_the_reader_may_not_see_hides_its_tab() {
		$this->as_role( 'editor' );
		$this->add_child_chapter( 'orders', array( 'capability' => 'manage_options' ) );

		$this->assertSame( array( 'theme' ), array_keys( devbase_guide_visible_tabs() ) );
	}

	public function test_when_false_leaves_a_chapter_out() {
		$this->as_role( 'administrator' );
		$this->add_child_chapter( 'shop', array( 'when' => '__return_false' ) );

		$this->assertArrayNotHasKey( 'shop', devbase_guide_chapters() );
	}

	public function test_a_chapter_with_no_title_no_body_or_an_unknown_tab_is_dropped() {
		$this->as_role( 'administrator' );

		add_filter( 'devbase/guide/chapters', function ( $chapters ) {
			$chapters['untitled'] = array( 'render' => '__return_null' );
			$chapters['empty']    = array( 'title' => 'Empty' );
			$chapters['lost']     = array( 'title' => 'Lost', 'render' => '__return_null', 'tab' => 'nowhere' );
			$chapters['junk']     = 'not an array';
			return $chapters;
		} );

		$all = devbase_guide_all_chapters();

		foreach ( array( 'untitled', 'empty', 'lost', 'junk' ) as $id ) {
			$this->assertArrayNotHasKey( $id, $all, $id );
		}
	}

	public function test_a_child_can_put_a_chapter_on_the_theme_tab_after_another() {
		$this->as_role( 'administrator' );
		$this->add_child_chapter( 'shop-mail', array( 'tab' => 'theme', 'after' => 'email' ) );

		$ids = array_keys( devbase_guide_chapters( 'theme' ) );

		$this->assertSame( array_search( 'email', $ids, true ) + 1, array_search( 'shop-mail', $ids, true ) );
	}

	public function test_a_child_can_rename_the_tabs() {
		$this->as_role( 'administrator' );
		$this->add_child_chapter();

		add_filter( 'devbase/guide/tabs', function ( $tabs ) {
			$tabs['site'] = 'Our website';
			return $tabs;
		} );

		$this->assertSame( 'Our website', devbase_guide_visible_tabs()['site'] );
	}

	// --- addresses, screenshots, translations --------------------------------------------------

	public function test_the_url_of_a_chapter_opens_its_tab_scrolled_to_it() {
		$this->add_child_chapter();

		$url = devbase_guide_url( 'email' );
		$this->assertStringContainsString( 'section=guide', $url );
		$this->assertStringContainsString( 'tab=theme', $url );
		$this->assertStringEndsWith( '#email', $url );

		$this->assertStringContainsString( 'tab=site', devbase_guide_url( 'news' ) );
	}

	public function test_a_screenshot_is_looked_for_in_the_child_first_then_here() {
		$this->assertStringContainsString( get_template_directory_uri() . '/assets/guide/email.jpg', devbase_guide_shot( 'email' ) );
		$this->assertSame( '', devbase_guide_shot( 'no-such-picture' ) );
		$this->assertSame( '', devbase_guide_shot_html( 'no-such-picture', 'Caption' ) );

		$html = devbase_guide_shot_html( 'email', 'The <b>Email</b> page' );
		$this->assertStringContainsString( '<figure class="guide-shot">', $html );
		$this->assertStringContainsString( 'The &lt;b&gt;Email&lt;/b&gt; page', $html );

		// A child theme's own picture of the same name wins.
		$dir = get_stylesheet_directory() . '/assets/guide';

		if ( get_stylesheet_directory() !== get_template_directory() ) {
			wp_mkdir_p( $dir );
			$this->files[] = $dir . '/email.png';
			file_put_contents( $dir . '/email.png', 'x' );

			$this->assertStringContainsString( get_stylesheet_directory_uri() . '/assets/guide/email.png', devbase_guide_shot( 'email' ) );
		}
	}

	public function test_a_name_cannot_leave_the_screenshot_folder() {
		$this->assertSame( '', devbase_guide_shot( '../../style' ) );
	}

	public function test_the_template_names_try_the_readers_language_first() {
		$english = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $english, 'locale', 'en_US' );
		wp_set_current_user( $english );

		$this->assertSame( array( 'devbase-guide/theme/email.twig' ), devbase_guide_template_names( 'devbase-guide/theme/email.twig' ) );

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user, 'locale', 'fr_FR' );
		wp_set_current_user( $user );

		$this->assertSame(
			array( 'devbase-guide/theme/fr_FR/email.twig', 'devbase-guide/theme/email.twig' ),
			devbase_guide_template_names( 'devbase-guide/theme/email.twig' )
		);
	}

	// --- the page ------------------------------------------------------------------------------

	public function test_the_page_lists_and_draws_the_open_tabs_chapters() {
		$this->as_role( 'administrator' );
		$this->add_child_chapter();

		$_GET['tab'] = 'theme';

		ob_start();
		devbase_guide_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'guide-tabs', $html );
		$this->assertStringContainsString( 'This site', $html );
		$this->assertStringContainsString( 'id="email"', $html );
		$this->assertStringContainsString( 'Send Test Email', $html, 'The Email chapter\'s body is drawn.' );
		$this->assertStringNotContainsString( 'Child chapter body.', $html, 'Only the open tab is drawn.' );

		$_GET['tab'] = 'site';

		ob_start();
		devbase_guide_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Child chapter body.', $html );
		$this->assertStringNotContainsString( 'id="email"', $html );
	}

	public function test_no_tab_bar_when_there_is_only_one_tab() {
		$this->as_role( 'administrator' );

		ob_start();
		devbase_guide_render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'guide-tabs', $html );
		$this->assertStringContainsString( 'id="settings"', $html );
	}

	public function test_an_editor_never_gets_an_admin_chapters_text() {
		$this->as_role( 'editor' );

		ob_start();
		devbase_guide_render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'id="email"', $html );
		$this->assertStringNotContainsString( 'id="cache"', $html );
		$this->assertStringContainsString( 'id="trouble"', $html );
	}

	public function test_no_service_name_reaches_the_guide() {
		$this->as_role( 'administrator' );
		add_filter( 'devbase/tsf/enabled', '__return_true' );

		ob_start();
		devbase_guide_render();
		$html = (string) ob_get_clean();

		remove_filter( 'devbase/tsf/enabled', '__return_true' );

		foreach ( array( 'Warden', 'Brand Relay' ) as $name ) {
			$this->assertStringNotContainsString( $name, $html );
		}
	}
}
