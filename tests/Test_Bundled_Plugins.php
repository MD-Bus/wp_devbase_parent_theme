<?php
// Covers inc/bundled-plugins/: installing a plugin from a zip inside the theme
// into wp-content/plugins/ for a child that requires it, never downgrading,
// removing it again (files only), and the locks that leave a theme release as
// the only thing that can change it. Uses a tiny fixture plugin and zip, not
// WooCommerce; the last test checks the real WooCommerce zip against its lock.

class Test_Bundled_Plugins extends WP_UnitTestCase {

	private $zip_dir;
	private $required = array();
	private $loaded   = false; // does the plugin count as running in this request?

	public function set_up() {
		parent::set_up();

		$this->zip_dir  = trailingslashit( get_temp_dir() ) . 'devbase-bundled-test-' . wp_generate_password( 6, false ) . '/';
		$this->required = array();
		$this->loaded   = false;
		devbase_bundled_plugins_deferred(); // drop anything a previous test queued
		wp_mkdir_p( $this->zip_dir );

		add_filter( 'devbase/bundled_plugins', array( $this, 'fixture_registry' ) );
		add_filter( 'devbase/required_plugins', array( $this, 'fixture_required' ) );
		add_filter( 'devbase/bundled_plugin_is_loaded', array( $this, 'fixture_loaded' ) );

		delete_option( DEVBASE_BUNDLED_PLUGINS_MANAGED );
		delete_option( DEVBASE_BUNDLED_PLUGINS_ERRORS );
		delete_option( 'devbase_bundled_plugins_lock' );
		delete_transient( 'devbase_bundled_plugin_failed_fixtureplug' );
	}

	public function tear_down() {
		remove_filter( 'devbase/bundled_plugins', array( $this, 'fixture_registry' ) );
		remove_filter( 'devbase/required_plugins', array( $this, 'fixture_required' ) );
		remove_filter( 'devbase/bundled_plugin_is_loaded', array( $this, 'fixture_loaded' ) );
		devbase_bundled_plugins_deferred();
		devbase_bundled_plugins_unlocked( false );

		$this->delete_dir( WP_PLUGIN_DIR . '/fixtureplug' );
		$this->delete_dir( $this->zip_dir );
		update_option( 'active_plugins', array() );

		parent::tear_down();
	}

	public function fixture_registry( $plugins ) {
		return array(
			'fixtureplug' => array(
				'name'    => 'Fixture Plugin',
				'file'    => 'fixtureplug/fixtureplug.php',
				'zip'     => $this->zip_dir . 'fixtureplug.zip',
				'version' => $this->zip_version,
			),
		);
	}

	public function fixture_loaded() {
		return $this->loaded;
	}

	public function fixture_required() {
		return $this->required;
	}

	private $zip_version = '2.0.0';

	private function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	private function make_zip( $version ) {
		$this->zip_version = $version;

		$zip = new ZipArchive();
		$zip->open( $this->zip_dir . 'fixtureplug.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'fixtureplug/fixtureplug.php', "<?php\n/*\nPlugin Name: Fixture Plugin\nVersion: {$version}\n*/\n" );
		$zip->addFromString( 'fixtureplug/readme.txt', 'x' );
		$zip->close();
	}

	private function install_on_disk( $version ) {
		wp_mkdir_p( WP_PLUGIN_DIR . '/fixtureplug' );
		file_put_contents( WP_PLUGIN_DIR . '/fixtureplug/fixtureplug.php', "<?php\n/*\nPlugin Name: Fixture Plugin\nVersion: {$version}\n*/\n" );
	}

	private function require_it() {
		$this->required = array( 'fixtureplug' );
	}

	// --- opt-in -------------------------------------------------------------

	public function test_nothing_is_required_unless_a_child_says_so() {
		remove_filter( 'devbase/required_plugins', array( $this, 'fixture_required' ) );

		$this->assertSame( array(), devbase_required_plugins() );
		$this->assertSame( array(), devbase_required_plugin_files() );
		$this->assertFalse( devbase_bundled_plugins_pending() );
		$this->assertSame( array(), devbase_sync_bundled_plugins() );
	}

	public function test_woocommerce_is_a_known_plugin_but_not_required_by_default() {
		remove_filter( 'devbase/bundled_plugins', array( $this, 'fixture_registry' ) );
		remove_filter( 'devbase/required_plugins', array( $this, 'fixture_required' ) );

		$this->assertArrayHasKey( 'woocommerce', devbase_bundled_plugins() );
		$this->assertSame( array(), devbase_required_plugins() );
	}

	public function test_unknown_and_invalid_slugs_are_ignored() {
		$this->required = array( 'fixtureplug', 'nope', 42, 'fixtureplug' );

		$this->assertSame( array( 'fixtureplug' ), devbase_required_plugins() );
	}

	// --- install, upgrade, never downgrade -----------------------------------

	public function test_a_required_plugin_is_installed_and_activated() {
		$this->make_zip( '2.0.0' );
		$this->require_it();

		$this->assertTrue( devbase_bundled_plugins_pending() );
		$results = devbase_sync_bundled_plugins();

		$this->assertSame( 'installed', $results['fixtureplug'] );
		$this->assertSame( '2.0.0', devbase_installed_plugin_version( 'fixtureplug' ) );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'fixtureplug/fixtureplug.php' ) );
		$this->assertSame( array( 'fixtureplug' ), get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED ) );
		$this->assertFalse( devbase_bundled_plugins_pending(), 'Nothing left to do once installed.' );
	}

	public function test_a_newer_zip_upgrades_the_installed_copy() {
		$this->install_on_disk( '1.0.0' );
		update_option( 'active_plugins', array( 'fixtureplug/fixtureplug.php' ) );
		$this->make_zip( '2.0.0' );
		$this->require_it();

		$this->assertTrue( devbase_bundled_plugins_pending() );
		$results = devbase_sync_bundled_plugins();

		$this->assertSame( 'upgraded', $results['fixtureplug'] );
		$this->assertSame( '2.0.0', devbase_installed_plugin_version( 'fixtureplug' ) );
		$this->assertFileExists( WP_PLUGIN_DIR . '/fixtureplug/readme.txt' );
	}

	public function test_an_older_zip_never_downgrades() {
		$this->install_on_disk( '3.0.0' );
		update_option( 'active_plugins', array( 'fixtureplug/fixtureplug.php' ) );
		$this->make_zip( '2.0.0' );
		$this->require_it();

		$this->assertFalse( devbase_bundled_plugins_pending() );
		devbase_sync_bundled_plugins();

		$this->assertSame( '3.0.0', devbase_installed_plugin_version( 'fixtureplug' ) );
	}

	public function test_a_deactivated_required_plugin_is_activated_again() {
		$this->install_on_disk( '2.0.0' );
		$this->make_zip( '2.0.0' );
		$this->require_it();

		$this->assertTrue( devbase_bundled_plugins_pending() );
		$this->assertSame( 'activated', devbase_sync_bundled_plugins()['fixtureplug'] );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'fixtureplug/fixtureplug.php' ) );
	}

	public function test_a_missing_zip_is_reported_and_not_retried_on_every_page() {
		$this->require_it();

		$results = devbase_sync_bundled_plugins();

		$this->assertWPError( $results['fixtureplug'] );
		$this->assertNotEmpty( get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS )['fixtureplug'] );
		$this->assertFalse( devbase_bundled_plugins_pending(), 'A failure is retried later, not on the next request.' );
	}

	public function test_a_zip_that_does_not_raise_the_version_is_reported() {
		$this->install_on_disk( '1.0.0' );
		$this->make_zip( '1.0.0' );          // what the zip really holds
		$this->zip_version = '2.0.0';        // what the release claims it holds
		$this->require_it();

		$this->assertWPError( devbase_sync_bundled_plugins()['fixtureplug'] );
	}

	public function test_a_second_sync_cannot_start_while_one_runs() {
		$this->make_zip( '2.0.0' );
		$this->require_it();
		add_option( 'devbase_bundled_plugins_lock', time(), '', false );

		$this->assertSame( array(), devbase_sync_bundled_plugins() );
		$this->assertSame( '', devbase_installed_plugin_version( 'fixtureplug' ) );
	}

	// --- never change a plugin's files while it is running ----------------------------

	public function test_a_loaded_plugin_is_upgraded_at_the_end_of_the_request_not_during_it() {
		$this->install_on_disk( '1.0.0' );
		update_option( 'active_plugins', array( 'fixtureplug/fixtureplug.php' ) );
		$this->make_zip( '2.0.0' );
		$this->require_it();
		$this->loaded = true;

		$results = devbase_sync_bundled_plugins();

		$this->assertSame( 'upgrading', $results['fixtureplug'] );
		$this->assertSame( '1.0.0', devbase_installed_plugin_version( 'fixtureplug' ), 'Files untouched while the plugin is running.' );
		$this->assertNotFalse( get_option( 'devbase_bundled_plugins_lock' ), 'The lock is held until the swap is done.' );

		devbase_bundled_plugins_run_deferred();

		$this->assertSame( '2.0.0', devbase_installed_plugin_version( 'fixtureplug' ) );
		$this->assertFalse( get_option( 'devbase_bundled_plugins_lock' ) );
		$this->assertSame( array( 'fixtureplug' ), get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED ) );
	}

	public function test_a_deferred_upgrade_that_does_not_raise_the_version_is_reported() {
		$this->install_on_disk( '1.0.0' );
		update_option( 'active_plugins', array( 'fixtureplug/fixtureplug.php' ) );
		$this->make_zip( '1.0.0' );
		$this->zip_version = '2.0.0';
		$this->require_it();
		$this->loaded = true;

		devbase_sync_bundled_plugins();
		devbase_bundled_plugins_run_deferred();

		$this->assertNotEmpty( get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS )['fixtureplug'] );
		$this->assertFalse( devbase_bundled_plugins_pending(), 'Not retried on every page.' );
	}

	public function test_a_plugin_that_is_not_loaded_is_removed_at_once() {
		$this->install_on_disk( '1.0.0' );
		update_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array( 'fixtureplug' ) );
		$this->required = array();

		$this->assertSame( 'removed', devbase_sync_bundled_plugins()['fixtureplug'] );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/fixtureplug' );
	}

	// --- removal --------------------------------------------------------------

	public function test_a_plugin_nobody_requires_any_more_is_removed_but_its_data_stays() {
		$this->make_zip( '2.0.0' );
		$this->require_it();
		devbase_sync_bundled_plugins();
		$this->loaded = true; // it was active when this request started
		update_option( 'fixtureplug_orders', array( 1, 2, 3 ) );

		$this->required = array();
		$this->assertTrue( devbase_bundled_plugins_pending() );
		$results = devbase_sync_bundled_plugins();

		$this->assertSame( 'removed', $results['fixtureplug'] );
		$this->assertFalse( devbase_bundled_plugin_is_active( 'fixtureplug/fixtureplug.php' ), 'Deactivated at once.' );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/fixtureplug', 'Loaded in this request: files go at the end of it.' );

		devbase_bundled_plugins_run_deferred();

		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/fixtureplug' );
		$this->assertFalse( devbase_bundled_plugin_is_active( 'fixtureplug/fixtureplug.php' ) );
		$this->assertSame( array(), get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED ) );
		$this->assertSame( array( 1, 2, 3 ), get_option( 'fixtureplug_orders' ), 'Files only: the data stays.' );
	}

	public function test_a_plugin_that_was_never_installed_by_the_theme_is_not_removed() {
		$this->install_on_disk( '1.0.0' );
		$this->required = array();

		$this->assertFalse( devbase_bundled_plugins_pending() );
		devbase_sync_bundled_plugins();

		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/fixtureplug' );
	}

	public function test_switching_to_a_theme_not_built_on_this_parent_removes_it() {
		$this->make_zip( '2.0.0' );
		$this->require_it();
		devbase_sync_bundled_plugins();

		do_action( 'switch_theme', 'Twenty Twenty-Five', wp_get_theme( 'twentytwentyfive' ), wp_get_theme() );
		devbase_bundled_plugins_run_deferred();

		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/fixtureplug' );
	}

	public function test_switching_to_another_child_of_this_parent_leaves_it_to_that_child() {
		$this->make_zip( '2.0.0' );
		$this->require_it();
		devbase_sync_bundled_plugins();

		// Any theme whose Template is this parent (the parent itself reports it).
		do_action( 'switch_theme', 'Another Child', wp_get_theme( 'wp_devbase_parent_theme' ), wp_get_theme() );

		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/fixtureplug' );
	}

	// --- locks ------------------------------------------------------------------

	public function test_wordpress_never_hears_of_an_update_to_a_required_plugin() {
		$this->require_it();
		$transient = (object) array(
			'response'  => array(
				'fixtureplug/fixtureplug.php' => (object) array( 'new_version' => '9.9.9' ),
				'other/other.php'             => (object) array( 'new_version' => '2.0.0' ),
			),
			'no_update' => array( 'fixtureplug/fixtureplug.php' => (object) array() ),
			'checked'   => array( 'fixtureplug/fixtureplug.php' => '2.0.0' ),
		);

		$read = apply_filters( 'site_transient_update_plugins', $transient );
		$set  = apply_filters( 'pre_set_site_transient_update_plugins', clone $transient );

		foreach ( array( $read, $set ) as $value ) {
			$this->assertArrayNotHasKey( 'fixtureplug/fixtureplug.php', $value->response );
			$this->assertArrayNotHasKey( 'fixtureplug/fixtureplug.php', $value->no_update );
			$this->assertArrayHasKey( 'other/other.php', $value->response, 'Other plugins are untouched.' );
		}
	}

	public function test_a_required_plugin_is_never_auto_updated() {
		$this->require_it();

		$locked = apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => 'fixtureplug/fixtureplug.php' ) );
		$other  = apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => 'other/other.php' ) );

		$this->assertFalse( $locked );
		$this->assertTrue( $other );
	}

	public function test_a_required_plugin_cannot_be_deactivated_deleted_or_updated_by_a_user() {
		$this->require_it();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertFalse( user_can( $admin, 'deactivate_plugin', 'fixtureplug/fixtureplug.php' ) );
		$this->assertFalse( user_can( $admin, 'delete_plugin', 'fixtureplug/fixtureplug.php' ) );
		$this->assertFalse( user_can( $admin, 'update_plugin', 'fixtureplug/fixtureplug.php' ) );
		$this->assertTrue( user_can( $admin, 'deactivate_plugin', 'other/other.php' ) );
	}

	public function test_the_plugins_list_offers_no_deactivate_or_delete() {
		$this->require_it();

		$actions = apply_filters( 'plugin_action_links', array( 'deactivate' => 'x', 'delete' => 'y', 'settings' => 'z' ), 'fixtureplug/fixtureplug.php' );

		$this->assertSame( array( 'settings' => 'z' ), $actions );
	}

	public function test_active_plugins_cannot_lose_a_required_plugin() {
		$this->require_it();

		update_option( 'active_plugins', array( 'other/other.php' ) );

		$this->assertContains( 'fixtureplug/fixtureplug.php', get_option( 'active_plugins' ) );
		$this->assertContains( 'other/other.php', get_option( 'active_plugins' ) );
	}

	public function test_the_upgrader_refuses_to_overwrite_a_required_plugin() {
		$this->require_it();

		$by_update = apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'fixtureplug/fixtureplug.php' ) );
		$by_upload = apply_filters( 'upgrader_source_selection', '/tmp/upgrade/fixtureplug/', '/tmp/upgrade', null, array() );
		$other     = apply_filters( 'upgrader_source_selection', '/tmp/upgrade/other/', '/tmp/upgrade', null, array() );

		$this->assertWPError( $by_update );
		$this->assertWPError( $by_upload );
		$this->assertSame( '/tmp/upgrade/other/', $other );
	}

	public function test_nothing_is_locked_when_the_theme_does_not_require_the_plugin() {
		$this->required = array();

		$this->assertFalse( apply_filters( 'auto_update_plugin', false, (object) array( 'plugin' => 'fixtureplug/fixtureplug.php' ) ) );
		$this->assertTrue( apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => 'fixtureplug/fixtureplug.php' ) ) );
		$this->assertNotWPError( apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'fixtureplug/fixtureplug.php' ) ) );
	}

	public function test_the_theme_can_lift_the_locks_for_its_own_removal() {
		$this->require_it();

		devbase_bundled_plugins_unlocked( true );

		$this->assertSame( array(), devbase_required_plugin_files() );
	}

	// --- reporting ----------------------------------------------------------------

	public function test_system_status_lists_a_required_plugin_with_the_version_the_release_ships() {
		$this->require_it();
		$this->zip_version = '2.0.0';

		$this->assertSame( '2.0.0', devbase_bundled_versions()['Fixture Plugin'] );
	}

	// --- the real WooCommerce zip ------------------------------------------------------

	public function test_the_woocommerce_zip_matches_its_lock() {
		remove_filter( 'devbase/bundled_plugins', array( $this, 'fixture_registry' ) );

		$def = devbase_bundled_plugins()['woocommerce'];
		$this->assertFileExists( $def['zip'], 'Run bin/bundle-plugins.sh and commit the zip.' );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $def['zip'] ) );
		$header = $zip->getFromName( 'woocommerce/woocommerce.php' );
		$zip->close();

		$this->assertNotFalse( $header, 'The zip must hold woocommerce/woocommerce.php.' );
		preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $header, $match );
		$this->assertSame( devbase_bundled_plugin_version( 'woocommerce' ), trim( $match[1] ) );
		$this->assertNotSame( '', devbase_bundled_plugin_version( 'woocommerce' ) );
	}
}
