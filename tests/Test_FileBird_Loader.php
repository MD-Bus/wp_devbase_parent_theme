<?php
// Covers inc/dependencies/filebird/: FileBird loaded from vendor/, which a plugin is not built for, its switch, and what
// the theme takes away (review request, feedback form, YayMail adverts). Assertions on FileBird's own source are contracts
// the loader relies on: when a Dependabot pull request moves FileBird and one fails, the loader needs a look before release.
// That FileBird is really not loaded when the switch is off is checked live: the loader runs once while the theme loads.

class Test_FileBird_Loader extends WP_UnitTestCase {

	const SRC = __DIR__ . '/../vendor/filebird/';

	private function source( $relative ) {
		return (string) file_get_contents( self::SRC . $relative );
	}

	// --- It runs ---------------------------------------------------------------

	public function test_filebird_is_running_from_vendor() {
		$this->assertTrue( defined( 'NJFB_VERSION' ) );
		$this->assertSame( realpath( self::SRC ) . '/', realpath( NJFB_PLUGIN_PATH ) . '/' );
		$this->assertSame( get_template_directory_uri() . '/vendor/filebird/', NJFB_PLUGIN_URL, 'Its assets resolve to vendor/, not the plugins folder.' );
	}

	public function test_it_was_started_by_the_loader_exactly_once() {
		$this->assertFalse( has_action( 'plugins_loaded', 'FileBird\init' ), 'Its own start-up hook is taken off after the loader runs it.' );
		$this->assertTrue( class_exists( 'FileBird\Classes\Core', false ), 'init() ran: its classes are loaded.' );
		$this->assertNotFalse( has_filter( 'ajax_query_attachments_args' ), 'The media library filter is in place.' );
	}

	public function test_its_tables_exist_and_its_version_is_recorded() {
		global $wpdb;

		$this->assertSame( NJFB_VERSION, get_option( 'fbv_version' ), 'Its first run creates the tables and records the version.' );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fbv WHERE 1 = 0" ) );
		$this->assertSame( '', $wpdb->last_error );
		$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fbv_attachment_folder" );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_a_folder_and_a_file_in_it_work_through_filebirds_own_model() {
		$folder = FileBird\Model\Folder::newOrGet( 'Loader test', 0 );
		$file   = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg', 'file' => 'loader-test.jpg' ) );

		FileBird\Model\Folder::setFoldersForPosts( array( $file ), (int) $folder['id'] );

		$this->assertSame( array( (int) $folder['id'] ), array_map( 'intval', FileBird\Model\Folder::getFolderFromPostId( $file ) ? wp_list_pluck( FileBird\Model\Folder::getFolderFromPostId( $file ), 'folder_id' ) : array() ) );
	}

	// --- The switch -------------------------------------------------------------

	public function test_it_is_off_unless_the_switch_was_turned_on() {
		$saved = $GLOBALS['wp_filter']['devbase/filebird/enabled'] ?? null;
		remove_all_filters( 'devbase/filebird/enabled' );

		try {
			delete_option( 'options_enable_filebird' );
			$this->assertFalse( devbase_filebird_enabled(), 'Never saved: off.' );
			update_option( 'options_enable_filebird', '1' );
			$this->assertTrue( devbase_filebird_enabled() );
			add_filter( 'devbase/filebird/enabled', '__return_false' );
			$this->assertFalse( devbase_filebird_enabled(), 'The filter has the last word.' );
		} finally {
			remove_all_filters( 'devbase/filebird/enabled' );
			if ( $saved ) {
				$GLOBALS['wp_filter']['devbase/filebird/enabled'] = $saved;
			}
		}
	}

	public function test_the_switch_is_on_the_media_tab_and_off_by_default() {
		$field = acf_get_field( 'field_devbase_enable_filebird' );

		$this->assertIsArray( $field );
		$this->assertSame( 'enable_filebird', $field['name'] );
		$this->assertSame( 0, $field['default_value'] );
		$this->assertContains( 'enable_filebird', devbase_settings_autoload_fields(), 'Read on every request, so autoloaded.' );
	}

	public function test_firing_init_again_does_not_register_its_gallery_block_twice() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'filebird/block-filebird-gallery' ) );
		$this->assertFalse( has_action( 'init', 'filebird_gallery_block_assets' ), 'Swapped for the guarded callback.' );

		do_action( 'init' ); // Would raise "already registered" (an unexpected notice fails the test).

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'filebird/block-filebird-gallery' ) );
	}

	public function test_a_site_running_its_own_filebird_is_detected() {
		$this->assertArrayHasKey( 'filebird', devbase_foreign_dependencies() );
		$this->assertStringContainsString( "defined( 'NJFB_VERSION' )", (string) file_get_contents( get_template_directory() . '/inc/dependencies/foreign.php' ) );
	}

	public function test_the_bundled_version_is_reported() {
		$this->assertStringStartsWith( NJFB_VERSION, devbase_bundled_versions()['FileBird'] );
	}

	public function test_the_filebird_menu_is_hidden_until_the_switch_is_saved_off() {
		global $menu;

		delete_option( 'options_hide_filebird_menu' );
		$this->assertTrue( devbase_filebird_menu_hidden(), 'Never saved: hidden.' );
		$this->assertSame( 1, acf_get_field( 'field_devbase_hide_filebird_menu' )['default_value'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu = array();
		do_action( 'admin_menu' );
		$this->assertNotContains( 'filebird-settings', wp_list_pluck( $menu, 2 ) );

		update_option( 'options_hide_filebird_menu', '0' );
		$this->assertFalse( devbase_filebird_menu_hidden() );
		$menu = array();
		do_action( 'admin_menu' );
		$this->assertContains( 'filebird-settings', wp_list_pluck( $menu, 2 ), 'Saved off: FileBird\'s own menu is back.' );

		delete_option( 'options_hide_filebird_menu' );
	}

	// --- No promotion on a client's site -------------------------------------------

	public function test_no_review_request_and_no_feedback_form() {
		$this->assertSame( '0', get_option( 'fbv_review' ) );

		foreach ( array( 'admin_enqueue_scripts', 'admin_footer' ) as $hook ) {
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					$this->assertFalse( is_array( $function ) && $function[0] instanceof FileBird\Classes\Feedback, $hook );
				}
			}
		}
	}

	public function test_the_yaymail_adverts_register_into_the_empty_registry() {
		$this->assertSame( realpath( get_template_directory() . '/inc/dependencies/filebird/recommended-modules-registry.php' ), realpath( ( new ReflectionClass( 'YayRecommendedModules\Registry' ) )->getFileName() ) );
		$this->assertNull( YayRecommendedModules\Registry::active_version( 'yaymail-wc-settings-banner' ) );
	}

	// --- Contracts on FileBird's source ------------------------------------------------

	public function test_filebird_still_starts_and_names_things_the_way_the_loader_expects() {
		$main = $this->source( 'filebird.php' );

		$this->assertStringContainsString( "add_action( 'plugins_loaded', 'FileBird\\\\init' );", $main, 'The start-up hook the loader runs by hand.' );
		$this->assertStringContainsString( "if ( ! defined( 'NJFB_PLUGIN_URL' ) )", $main, 'The URL constant is only defined when free.' );
		$this->assertStringContainsString( "recommended-modules/loader.php", $main );
		$this->assertStringContainsString( "class_exists(__NAMESPACE__ . '\\\\Registry', false)", $this->source( 'recommended-modules/registry.php' ), 'Its registry yields to one already loaded.' );
		$this->assertStringContainsString( "get_option( 'fbv_review', false )", $this->source( 'includes/Classes/Review.php' ) );
		$this->assertStringContainsString( 'Install::create_tables()', $this->source( 'includes/Plugin.php' ), 'The first run creates the tables.' );
		$this->assertTrue( is_callable( array( 'FileBird\Install', 'create_tables' ) ), 'The loader creates the tables before the first run.' );
	}
}
