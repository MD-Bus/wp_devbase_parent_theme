<?php
// Covers the bundled plugins tied to a Brand Relay plan service (WPvivid Backup): installed and
// activated only while the service is in force; deactivated and deleted at once when it goes off
// (no grace period: that is Brand Relay's to give); never while mid-task; a copy that was already on the site is adopted but
// never removed; the Pro edition and a copy under another folder name are never doubled.
// Uses a fixture plugin and zip, not WPvivid; the last test checks the real zip against its lock.

class Test_Bundled_Service_Plugins extends WP_UnitTestCase {

	private $zip_dir;
	private $busy = false;

	public function set_up() {
		parent::set_up();

		$this->zip_dir = trailingslashit( get_temp_dir() ) . 'devbase-svc-test-' . wp_generate_password( 6, false ) . '/';
		$this->busy    = false;
		wp_mkdir_p( $this->zip_dir );
		devbase_bundled_plugins_deferred();

		$this->make_zip( '2.0.0' );
		add_filter( 'devbase/bundled_plugins', array( $this, 'registry' ) );
		add_filter( 'devbase/bundled_plugin_is_loaded', '__return_false' );
		add_filter( 'devbase/bundled_plugin_busy', array( $this, 'is_busy' ) );

		foreach ( array( DEVBASE_BUNDLED_PLUGINS_MANAGED, DEVBASE_BUNDLED_PLUGINS_ERRORS, 'devbase_bundled_plugins_lock' ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'devbase_bundled_elsewhere_svcplug' );
		delete_transient( 'devbase_bundled_plugin_failed_svcplug' );
		update_option( 'active_plugins', array() );
		update_field( 'warden_licence_key', 'WRD-TEST-KEY', 'option' );
		update_option( devbase_warden_state_option( get_template() ), array( 'response' => array( 'valid' => true, 'status' => 'active' ), 'succeeded_at' => time(), 'checked_at' => time() ), false );
	}

	public function tear_down() {
		remove_filter( 'devbase/bundled_plugins', array( $this, 'registry' ) );
		remove_filter( 'devbase/bundled_plugin_is_loaded', '__return_false' );
		remove_filter( 'devbase/bundled_plugin_busy', array( $this, 'is_busy' ) );
		devbase_bundled_plugins_deferred();
		devbase_bundled_plugins_unlocked( false );

		foreach ( array( 'svcplug', 'svcplug-copy', 'svcplug-pro' ) as $dir ) {
			$this->delete_dir( WP_PLUGIN_DIR . '/' . $dir );
		}
		$this->delete_dir( $this->zip_dir );
		update_field( 'warden_licence_key', '', 'option' );
		delete_option( devbase_warden_state_option( get_template() ) );
		delete_option( 'devbase_relay_plan' );
		update_option( 'active_plugins', array() );

		parent::tear_down();
	}

	public function registry() {
		return array(
			'svcplug' => array(
				'name'        => 'Service Plugin',
				'file'        => 'svcplug/svcplug.php',
				'zip'         => $this->zip_dir . 'svcplug.zip',
				'version'     => '2.0.0',
				'service'     => 'backup',
				'text_domain' => 'svcplug-domain',
				'conflicts'   => array( 'svcplug-pro/svcplug-pro.php' ),
			),
		);
	}

	public function is_busy( $busy ) {
		return $this->busy ? true : $busy;
	}

	private function plan( $services ) {
		devbase_relay_store_plan( array( 'licence' => null === $services ? null : array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => $services, 'limits' => array() ) ) );
	}

	private function make_zip( $version ) {
		$zip = new ZipArchive();
		$zip->open( $this->zip_dir . 'svcplug.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'svcplug/svcplug.php', $this->header( 'Service Plugin', $version, 'svcplug-domain' ) );
		$zip->close();
	}

	private function header( $name, $version, $domain ) {
		return "<?php\n/*\nPlugin Name: {$name}\nVersion: {$version}\nText Domain: {$domain}\n*/\n";
	}

	private function put_plugin( $folder, $file, $version, $domain = 'svcplug-domain' ) {
		wp_mkdir_p( WP_PLUGIN_DIR . '/' . $folder );
		file_put_contents( WP_PLUGIN_DIR . '/' . $folder . '/' . $file, $this->header( 'Existing ' . $folder, $version, $domain ) );
	}

	private function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	private function installed_by_us() {
		$this->plan( array( 'backup' ) );
		$this->assertSame( 'installed', devbase_sync_bundled_plugins()['svcplug'] );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'svcplug/svcplug.php' ) );
	}

	private function service_goes_off() {
		$this->plan( array( 'uptime' ) );
	}

	// --- required only while the service is in force -------------------------------------------

	public function test_it_is_not_required_without_a_plan_or_without_the_service() {
		$this->assertSame( array(), devbase_required_plugins() );

		$this->plan( null );
		$this->assertSame( array(), devbase_required_plugins() );

		$this->plan( array( 'uptime', 'health', 'events' ) );
		$this->assertSame( array(), devbase_required_plugins() );
		$this->assertSame( array(), devbase_sync_bundled_plugins() );
		$this->assertSame( '', devbase_installed_plugin_version( 'svcplug' ), 'A site that never had backups never carries the plugin.' );
	}

	public function test_it_is_installed_and_activated_when_the_plan_includes_the_service() {
		$this->plan( array( 'uptime', 'backup' ) );

		$this->assertSame( array( 'svcplug' ), devbase_required_plugins() );
		$this->assertTrue( devbase_bundled_plugins_pending() );

		$this->assertSame( 'installed', devbase_sync_bundled_plugins()['svcplug'] );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'svcplug/svcplug.php' ) );
		$this->assertSame( array( 'svcplug' ), get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED ) );
	}

	public function test_an_expired_subscription_does_not_count_as_in_force() {
		devbase_relay_store_plan( array( 'licence' => array( 'status' => 'active', 'services' => array( 'backup' ), 'expires_at' => gmdate( 'c', time() - DAY_IN_SECONDS ), 'limits' => array() ) ) );

		$this->assertSame( array(), devbase_required_plugins() );
	}

	public function test_activating_it_announces_itself_so_wpvivid_can_cancel_its_redirect() {
		$seen = array();
		add_action( 'devbase/bundled_plugin_activated', function ( $slug ) use ( &$seen ) {
			$seen[] = $slug;
		} );

		$this->installed_by_us();

		$this->assertSame( array( 'svcplug' ), $seen );
	}

	// --- lapsing (no grace period here: a buffer after expiry is Brand Relay's to give) -------------

	public function test_when_the_service_goes_off_it_is_deactivated_and_removed_at_once() {
		$this->installed_by_us();
		$this->service_goes_off();

		$this->assertTrue( devbase_bundled_plugins_pending() );
		$this->assertSame( 'removed', devbase_sync_bundled_plugins()['svcplug'] );

		$this->assertFalse( devbase_bundled_plugin_is_active( 'svcplug/svcplug.php' ) );
		$this->assertSame( '', devbase_installed_plugin_version( 'svcplug' ) );
		$this->assertSame( array(), (array) get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array() ) );
		$this->assertFalse( devbase_bundled_plugins_pending() );
	}

	public function test_the_plugins_data_is_left_alone_when_it_is_removed() {
		$this->installed_by_us();
		update_option( 'svcplug_settings', 'kept' );
		$this->service_goes_off();
		devbase_sync_bundled_plugins();

		$this->assertSame( 'kept', get_option( 'svcplug_settings' ), 'Files only: its uninstall routine is never run.' );
		delete_option( 'svcplug_settings' );
	}

	public function test_it_comes_back_when_the_service_does() {
		$this->installed_by_us();
		$this->service_goes_off();
		devbase_sync_bundled_plugins();

		$this->plan( array( 'backup' ) );

		$this->assertSame( 'installed', devbase_sync_bundled_plugins()['svcplug'] );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'svcplug/svcplug.php' ) );
	}

	public function test_nothing_is_deactivated_or_deleted_while_it_is_mid_task() {
		$this->installed_by_us();
		$this->service_goes_off();
		$this->busy = true;

		$this->assertSame( 'busy', devbase_sync_bundled_plugins()['svcplug'] );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'svcplug/svcplug.php' ), 'A restore must not be cut off.' );
		$this->assertSame( '2.0.0', devbase_installed_plugin_version( 'svcplug' ) );
		$this->assertTrue( devbase_bundled_plugins_pending(), 'Retried on the next admin page.' );

		$this->busy = false;
		$this->assertSame( 'removed', devbase_sync_bundled_plugins()['svcplug'] );
		$this->assertSame( '', devbase_installed_plugin_version( 'svcplug' ) );
	}

	public function test_a_failed_plan_fetch_keeps_the_last_answer_so_nothing_lapses() {
		$this->installed_by_us();

		// A refused or failed fetch never touches the stored plan (devbase_relay_fetch_site keeps the last good one).
		$this->assertSame( array( 'svcplug' ), devbase_required_plugins() );
		$this->assertFalse( devbase_bundled_plugins_pending() );
	}

	// --- the Check licence button's report -------------------------------------------------------

	public function test_the_check_button_installs_now_and_says_so() {
		$this->plan( array( 'backup' ) );

		$this->assertSame( array( 'Service Plugin: included in this site\'s plan, installed and activated now.' ), devbase_licence_plugin_lines() );
		$this->assertSame( array( 'Service Plugin: included in this site\'s plan, active (version 2.0.0).' ), devbase_licence_plugin_lines() );
	}

	public function test_the_check_button_removes_now_and_says_so() {
		$this->installed_by_us();
		$this->service_goes_off();

		$this->assertSame( array( 'Service Plugin: not included in this site\'s plan, deactivated and removed now.' ), devbase_licence_plugin_lines() );
		$this->assertSame( array( 'Service Plugin: not included in this site\'s plan, so it is not installed.' ), devbase_licence_plugin_lines() );
	}

	public function test_the_check_button_says_a_running_task_blocks_it_and_that_the_clients_own_copy_is_left_alone() {
		$this->installed_by_us();
		$this->service_goes_off();
		$this->busy = true;
		$this->assertStringContainsString( 'a backup or restore is running', devbase_licence_plugin_lines()[0] );
		$this->busy = false;
		devbase_sync_bundled_plugins();

		$this->put_plugin( 'svcplug', 'svcplug.php', '2.0.0' );
		update_option( 'active_plugins', array( 'svcplug/svcplug.php' ) );
		$this->assertStringContainsString( 'was not installed by the theme, so it was left alone', devbase_licence_plugin_lines()[0] );
	}

	// --- a copy that was already on the site ----------------------------------------------------

	public function test_a_copy_that_was_already_there_is_adopted_and_never_removed() {
		$this->put_plugin( 'svcplug', 'svcplug.php', '2.0.0' );
		update_option( 'active_plugins', array( 'svcplug/svcplug.php' ) );
		$this->plan( array( 'backup' ) );

		devbase_sync_bundled_plugins();
		$this->assertSame( array(), (array) get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array() ), 'Not ours, so not in the list of what we may remove.' );

		$this->service_goes_off();
		$this->assertFalse( devbase_bundled_plugins_pending() );
		$this->assertSame( array(), devbase_sync_bundled_plugins() );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'svcplug/svcplug.php' ) );
		$this->assertSame( '2.0.0', devbase_installed_plugin_version( 'svcplug' ) );
	}

	public function test_an_older_copy_is_upgraded_but_a_newer_one_is_never_downgraded() {
		$this->put_plugin( 'svcplug', 'svcplug.php', '1.0.0' );
		$this->plan( array( 'backup' ) );
		devbase_sync_bundled_plugins();
		$this->assertSame( '2.0.0', devbase_installed_plugin_version( 'svcplug' ) );

		$this->put_plugin( 'svcplug', 'svcplug.php', '3.0.0' );
		devbase_sync_bundled_plugins();
		$this->assertSame( '3.0.0', devbase_installed_plugin_version( 'svcplug' ) );
	}

	public function test_a_copy_under_another_folder_name_is_used_and_no_second_copy_is_installed() {
		$this->put_plugin( 'svcplug-copy', 'svcplug-copy.php', '2.0.0' );
		$this->plan( array( 'backup' ) );

		$this->assertSame( array(), devbase_required_plugins() );
		$this->assertSame( array(), devbase_sync_bundled_plugins() );
		$this->assertSame( '', devbase_installed_plugin_version( 'svcplug' ), 'A second copy would redeclare its classes and fatal.' );
		$this->assertEmpty( get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS ), 'Not an error: the existing one is simply used.' );
	}

	public function test_the_pro_edition_blocks_the_install_and_says_so() {
		$this->put_plugin( 'svcplug-pro', 'svcplug-pro.php', '1.0.0', 'svcplug-pro' );
		$this->plan( array( 'backup' ) );

		$this->assertSame( array(), devbase_required_plugins() );
		$this->assertTrue( devbase_bundled_plugins_pending(), 'The notice has to be recorded once.' );

		$results = devbase_sync_bundled_plugins();
		$this->assertWPError( $results['svcplug'] );
		$this->assertStringContainsString( 'svcplug-pro/svcplug-pro.php', get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS )['svcplug'] );
		$this->assertSame( '', devbase_installed_plugin_version( 'svcplug' ) );
		$this->assertFalse( devbase_bundled_plugins_pending(), 'Said once, not on every page.' );
	}

	// --- WPvivid itself -------------------------------------------------------------------------

	public function test_wpvivid_is_registered_as_service_bound_and_its_zip_matches_the_lock() {
		remove_filter( 'devbase/bundled_plugins', array( $this, 'registry' ) );

		$def = devbase_bundled_plugins()['wpvivid-backuprestore'];
		$this->assertSame( 'backup', $def['service'] );
		$this->assertTrue( devbase_bundled_plugin_is_service_bound( $def ) );
		$this->assertNotSame( '', devbase_bundled_plugin_version( 'wpvivid-backuprestore' ) );

		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $def['zip'] ) );
		$header = get_file_data( 'zip://' . $def['zip'] . '#' . $def['file'], array( 'Version' => 'Version' ) );
		$this->assertSame( devbase_bundled_plugin_version( 'wpvivid-backuprestore' ), $header['Version'] );
	}

	public function test_wpvivid_is_busy_while_a_task_runs_or_a_restore_is_under_way() {
		delete_option( 'wpvivid_task_list' );
		$this->assertFalse( devbase_wpvivid_is_busy() );

		update_option( 'wpvivid_task_list', array( 'task1' => array( 'status' => array( 'str' => 'completed' ) ) ) );
		$this->assertFalse( devbase_wpvivid_is_busy() );

		update_option( 'wpvivid_task_list', array( 'task1' => array( 'status' => array( 'str' => 'running' ) ) ) );
		$this->assertTrue( devbase_wpvivid_is_busy() );

		update_option( 'wpvivid_task_list', array( 'task1' => array( 'status' => array( 'str' => 'no_responds' ) ) ) );
		$this->assertTrue( devbase_wpvivid_is_busy() );

		delete_option( 'wpvivid_task_list' );
		$file = WP_CONTENT_DIR . '/wpvividbackups/wpvivid_restoredata';
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, '{}' );
		$this->assertTrue( devbase_wpvivid_is_busy() );

		unlink( $file );
		$this->assertFalse( devbase_wpvivid_is_busy() );
		@rmdir( dirname( $file ) );
	}

	public function test_the_real_wpvivid_zip_unpacks_into_a_valid_plugin_and_is_the_locked_version() {
		remove_filter( 'devbase/bundled_plugins', array( $this, 'registry' ) );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$def = devbase_bundled_plugins()['wpvivid-backuprestore'];

		try {
			$this->assertTrue( devbase_install_bundled_plugin_files( 'wpvivid-backuprestore', $def ) );

			$this->assertSame( devbase_bundled_plugin_version( 'wpvivid-backuprestore' ), devbase_installed_plugin_version( 'wpvivid-backuprestore' ) );
			$this->assertSame( 0, validate_plugin( $def['file'] ), 'WordPress accepts it as a plugin.' );
			$this->assertSame( 'wpvivid-backuprestore', get_plugins()[ $def['file'] ]['TextDomain'], 'The text domain the other-folder check relies on.' );
			$this->assertFileExists( WP_PLUGIN_DIR . '/wpvivid-backuprestore/includes/class-wpvivid-public-interface.php' );
		} finally {
			$this->delete_dir( WP_PLUGIN_DIR . '/wpvivid-backuprestore' );
			wp_clean_plugins_cache( false );
		}
	}
}
