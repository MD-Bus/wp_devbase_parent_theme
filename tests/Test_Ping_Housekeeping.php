<?php
// Covers what every verified Brand Relay ping does besides what it names (devbase_maintenance_housekeeping(),
// inc/maintenance/maintenance.php): a pending bundled-plugin change is applied, and WordPress's scheduled
// tasks are started. Both used to wait for an administrator's visit or for WP-Cron, which a managed site may
// never get. Also the plan's dates (inc/maintenance/plan.php), and the retention purge that the started
// cron then runs to the end (inc/forms/retention.php).
//
// tests/bootstrap.php switches both housekeeping steps off for the suite; each test here switches on the one
// it checks. The plugin is a fixture zip, never a real one.

class Test_Ping_Housekeeping extends WP_UnitTestCase {

	private $zip_dir;
	private $requests = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( ! defined( 'DEVBASE_RELAY_URL' ) ) {
			define( 'DEVBASE_RELAY_URL', 'https://relay.test' );
		}
		if ( ! defined( 'DEVBASE_RELAY_SITE_KEY' ) ) {
			define( 'DEVBASE_RELAY_SITE_KEY', 'brs_testkey' );
		}
	}

	public function set_up() {
		parent::set_up();

		static $n = 0;
		$_SERVER['REMOTE_ADDR'] = '198.51.101.' . ( ++$n % 250 + 1 ); // each test its own IP for the ping limit

		$this->requests = array();
		$this->zip_dir  = trailingslashit( get_temp_dir() ) . 'devbase-ping-test-' . wp_generate_password( 6, false ) . '/';
		wp_mkdir_p( $this->zip_dir );
		$this->make_zip( '2.0.0' );

		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		add_filter( 'devbase/bundled_plugins', array( $this, 'registry' ) );
		add_filter( 'devbase/bundled_plugin_is_loaded', '__return_false' );
		add_filter( 'devbase/bundled_plugin_busy', '__return_false' );
		add_filter( 'devbase/backup/engine_ready', '__return_true' );

		$this->reset();
		update_field( 'warden_licence_key', 'WRD-TEST-KEY', 'option' );
		update_option( devbase_warden_state_option( get_template() ), array( 'response' => array( 'valid' => true, 'status' => 'active' ), 'succeeded_at' => time(), 'checked_at' => time() ), false );

		devbase_tests_rebuild_rest_server();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		remove_filter( 'devbase/bundled_plugins', array( $this, 'registry' ) );
		remove_filter( 'devbase/bundled_plugin_is_loaded', '__return_false' );
		remove_filter( 'devbase/bundled_plugin_busy', '__return_false' );
		remove_filter( 'devbase/backup/engine_ready', '__return_true' );

		$this->reset();
		$this->delete_dir( WP_PLUGIN_DIR . '/pingplug' );
		$this->delete_dir( $this->zip_dir );
		update_field( 'warden_licence_key', '', 'option' );
		update_field( 'retention_enabled', 0, 'option' );
		update_field( 'retention_policies', array(), 'option' );
		delete_option( devbase_warden_state_option( get_template() ) );
		delete_option( 'devbase_retention_last_run' );
		wp_clear_scheduled_hook( 'devbase_test_due_event' );
		devbase_tests_reset_rest_server();

		parent::tear_down();
	}

	private function reset() {
		foreach ( array( DEVBASE_BUNDLED_PLUGINS_MANAGED, DEVBASE_BUNDLED_PLUGINS_ERRORS, 'devbase_bundled_plugins_lock', DEVBASE_RELAY_PLAN_OPTION, DEVBASE_RELAY_STATE_OPTION, DEVBASE_MAINTENANCE_STATE, DEVBASE_MAINTENANCE_LOCK ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'devbase_bundled_elsewhere_pingplug' );
		delete_transient( 'devbase_bundled_plugin_failed_pingplug' );
		delete_transient( 'doing_cron' );
		update_option( 'active_plugins', array() );
		devbase_bundled_plugins_deferred();
		devbase_bundled_plugins_unlocked( false );
	}

	// --- the fakes -----------------------------------------------------------------------------

	public function http( $pre, $args, $url ) {
		$this->requests[] = $url;

		return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
	}

	public function registry() {
		return array(
			'pingplug' => array(
				'name'        => 'Ping Plugin',
				'file'        => 'pingplug/pingplug.php',
				'zip'         => $this->zip_dir . 'pingplug.zip',
				'version'     => '2.0.0',
				'service'     => 'backup',
				'text_domain' => 'pingplug-domain',
			),
		);
	}

	private function header( $version ) {
		return "<?php\n/*\nPlugin Name: Ping Plugin\nVersion: {$version}\nText Domain: pingplug-domain\n*/\n";
	}

	private function make_zip( $version ) {
		$zip = new ZipArchive();
		$zip->open( $this->zip_dir . 'pingplug.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'pingplug/pingplug.php', $this->header( $version ) );
		$zip->close();
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

	private function plan( array $services, array $extra = array() ) {
		devbase_relay_store_plan( array( 'licence' => array_merge( array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => $services, 'limits' => array() ), $extra ) ) );
	}

	/** Brand Relay's signed ping. `{"run":[]}` names nothing, so only the housekeeping happens. */
	private function ping( $body = '{"run":[]}', $signature = null ) {
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 12 ) );
		$origin    = 'https://relay.test';

		$request = new WP_REST_Request( 'POST', '/devbase/v1/sync' );
		$request->set_body( $body );
		$request->set_header( 'origin', $origin );
		$request->set_header( 'x-relay-timestamp', $timestamp );
		$request->set_header( 'x-relay-nonce', $nonce );
		$request->set_header( 'x-relay-signature', null === $signature ? hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $origin . '.' . $body, devbase_relay_ping_secret() ) : $signature );

		return rest_get_server()->dispatch( $request );
	}

	private function cron_requests() {
		return array_values( array_filter( $this->requests, function ( $url ) {
			return false !== strpos( $url, 'wp-cron.php' );
		} ) );
	}

	// --- scheduled tasks -----------------------------------------------------------------------

	public function test_a_verified_ping_starts_wordpresss_scheduled_tasks_when_one_is_due() {
		remove_filter( 'devbase/maintenance/spawn_cron', '__return_false' );
		wp_schedule_single_event( time() - 60, 'devbase_test_due_event' );

		$this->assertSame( 200, $this->ping()->get_status() );

		$this->assertCount( 1, $this->cron_requests(), 'wp-cron.php is called in a request of its own.' );
		$this->assertStringContainsString( 'doing_wp_cron=', $this->cron_requests()[0] );
	}

	public function test_a_ping_that_is_not_signed_starts_nothing() {
		remove_filter( 'devbase/maintenance/spawn_cron', '__return_false' );
		wp_schedule_single_event( time() - 60, 'devbase_test_due_event' );

		$this->assertSame( 401, $this->ping( '{"run":[]}', str_repeat( '0', 64 ) )->get_status() );

		$this->assertSame( array(), $this->cron_requests() );
	}

	public function test_a_run_that_started_a_moment_ago_is_not_started_again() {
		remove_filter( 'devbase/maintenance/spawn_cron', '__return_false' );
		wp_schedule_single_event( time() - 60, 'devbase_test_due_event' );

		$this->ping();
		$this->ping();

		$this->assertCount( 1, $this->cron_requests(), 'WordPress\'s own lock: a ping flood cannot become a cron flood.' );
	}

	public function test_it_can_be_switched_off() {
		wp_schedule_single_event( time() - 60, 'devbase_test_due_event' );

		// tests/bootstrap.php leaves the filter at false, as a site could.
		$this->assertFalse( devbase_maintenance_spawn_cron() );
		$this->assertSame( array(), $this->cron_requests() );
	}

	// --- bundled plugins -----------------------------------------------------------------------

	public function test_a_ping_applies_the_newer_plugin_a_theme_release_shipped_without_waiting_for_an_administrator() {
		remove_filter( 'devbase/maintenance/sync_plugins_on_ping', '__return_false' );

		// The site runs 1.0.0, installed by the theme; this release's zip carries 2.0.0.
		wp_mkdir_p( WP_PLUGIN_DIR . '/pingplug' );
		file_put_contents( WP_PLUGIN_DIR . '/pingplug/pingplug.php', $this->header( '1.0.0' ) );
		update_option( 'active_plugins', array( 'pingplug/pingplug.php' ) );
		update_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array( 'pingplug' ), false );
		$this->plan( array( 'backup' ) );

		$this->assertTrue( devbase_bundled_plugins_pending() );
		$this->assertSame( 0, get_current_user_id(), 'No one is logged in: this is the site nobody visits.' );

		$this->assertSame( 200, $this->ping()->get_status() );

		$this->assertSame( '2.0.0', devbase_installed_plugin_version( 'pingplug' ) );
		$this->assertFalse( devbase_bundled_plugins_pending() );
		$this->assertTrue( devbase_bundled_plugin_is_active( 'pingplug/pingplug.php' ) );
	}

	public function test_a_ping_with_nothing_pending_touches_no_plugin() {
		remove_filter( 'devbase/maintenance/sync_plugins_on_ping', '__return_false' );
		$this->plan( array( 'health' ) ); // the plugin's service is not in the plan

		$done = devbase_maintenance_housekeeping();

		$this->assertSame( array(), $done['plugins'] );
		$this->assertSame( '', devbase_installed_plugin_version( 'pingplug' ) );
	}

	public function test_the_plugin_step_can_be_switched_off() {
		$this->plan( array( 'backup' ) );
		$this->assertTrue( devbase_bundled_plugins_pending() );

		// tests/bootstrap.php leaves the filter at false.
		$this->assertSame( array(), devbase_maintenance_housekeeping()['plugins'] );
		$this->assertSame( '', devbase_installed_plugin_version( 'pingplug' ) );
	}

	// --- the plan's dates ----------------------------------------------------------------------

	public function test_a_time_from_brand_relay_is_read_as_a_date_as_seconds_or_as_milliseconds() {
		$this->assertSame( 1793823580, devbase_relay_time( 1793823580262 ) );
		$this->assertSame( 1793823580, devbase_relay_time( '1793823580262' ) );
		$this->assertSame( 1793823580, devbase_relay_time( 1793823580 ) );
		$this->assertSame( strtotime( '2027-03-31T23:59:59Z' ), devbase_relay_time( '2027-03-31T23:59:59Z' ) );
		$this->assertFalse( devbase_relay_time( 'next quarter-ish' ) );
		$this->assertFalse( devbase_relay_time( '' ) );
		$this->assertFalse( devbase_relay_time( array( 'x' ) ) );
		$this->assertSame( devbase_relay_time( 1793823580262 ), devbase_backup_time( 1793823580262 ), 'The backup list reads times the same way.' );
	}

	public function test_the_plan_dates_in_the_form_brand_relay_sends_them() {
		// Brand Relay 0.43.0 (app/lib/plans.ts, subscriptionAnswer()) sends the `licence` dates of /v1/site as
		// new Date(ms).toISOString(): ISO 8601, UTC, with milliseconds. An expiry is the last millisecond of its day.
		$this->assertSame( gmmktime( 23, 59, 59, 1, 31, 2027 ), devbase_relay_time( '2027-01-31T23:59:59.999Z' ) );
		$this->assertSame( gmmktime( 0, 0, 0, 11, 10, 2026 ), devbase_relay_time( '2026-11-10T00:00:00.000Z' ) );

		$this->plan( array( 'health' ), array(
			'starts_at'  => gmdate( 'Y-m-d\T00:00:00.000\Z', time() - DAY_IN_SECONDS ),
			'expires_at' => gmdate( 'Y-m-d\T23:59:59.999\Z', time() + DAY_IN_SECONDS ),
		) );
		$this->assertTrue( devbase_relay_service_in_force( 'health' ) );
		$this->assertSame( '', devbase_relay_plan()['problem'] ?? '', 'Nothing unreadable was recorded.' );

		$this->plan( array( 'health' ), array( 'expires_at' => gmdate( 'Y-m-d\T23:59:59.999\Z', time() - 2 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'Ended two days ago.' );
	}

	public function test_an_expiry_in_milliseconds_is_an_expiry() {
		// Read with strtotime() alone, a number was "no date": the plan never ended.
		$this->plan( array( 'health' ), array( 'expires_at' => ( time() - 60 ) * 1000 ) );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'Expired a minute ago.' );

		$this->plan( array( 'health' ), array( 'expires_at' => ( time() + 3600 ) * 1000 ) );
		$this->assertTrue( devbase_relay_service_in_force( 'health' ) );

		$this->plan( array( 'health' ), array( 'starts_at' => ( time() + 3600 ) * 1000 ) );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'Not started yet.' );
	}

	public function test_a_date_that_cannot_be_read_switches_the_service_off_and_says_why() {
		$this->plan( array( 'health' ), array( 'expires_at' => 'next quarter-ish' ) );

		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'Unreadable is not the same as absent.' );
		$this->assertStringContainsString( 'expires_at cannot be read', devbase_relay_plan()['problem'] );

		$this->plan( array( 'health' ), array( 'starts_at' => 'whenever' ) );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ) );
		$this->assertStringContainsString( 'starts_at cannot be read', devbase_relay_plan()['problem'] );
	}

	public function test_a_plan_with_no_dates_is_in_force_and_has_no_problem() {
		$this->plan( array( 'health' ) );

		$this->assertTrue( devbase_relay_service_in_force( 'health' ) );
		$this->assertSame( '', devbase_relay_plan()['problem'] );

		$this->plan( array( 'health' ), array( 'expires_at' => null, 'starts_at' => '' ) );
		$this->assertTrue( devbase_relay_service_in_force( 'health' ), 'Empty means "no date", as before.' );
	}

	// --- the retention purge runs to the end ---------------------------------------------------

	private function old_submissions( $how_many ) {
		$date = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );

		for ( $i = 0; $i < $how_many; $i++ ) {
			self::factory()->post->create( array(
				'post_type'     => 'form_data',
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
				'meta_input'    => array( 'form_name' => 'flooded_form', '_devbase_dispatched' => 'done', 'content' => '{}' ),
			) );
		}
	}

	private function left() {
		return count( get_posts( array( 'post_type' => 'form_data', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	}

	private function retention_on() {
		update_field( 'retention_enabled', 1, 'option' );
		update_field( 'retention_policies', array( array( 'period' => '1_week', 'apply_to' => array( 'flooded_form' ) ) ), 'option' );
		add_filter( 'devbase/forms/retention_batch', function () {
			return 3;
		} );
	}

	public function test_more_old_submissions_than_one_batch_are_all_deleted_in_one_run() {
		$this->retention_on();
		$this->old_submissions( 8 );

		$this->assertSame( 8, devbase_forms_run_retention_purge(), 'One batch of 3 was the whole run before: a flooded form never caught up.' );
		$this->assertSame( 0, $this->left() );
		$this->assertFalse( get_option( 'devbase_retention_last_run' )['unfinished'] );
	}

	public function test_out_of_time_it_stops_says_so_and_the_next_run_carries_on() {
		$this->retention_on();
		$this->old_submissions( 8 );
		$budget = function () {
			return 0;
		};
		add_filter( 'devbase/forms/retention_budget', $budget );

		$this->assertSame( 3, devbase_forms_run_retention_purge(), 'One batch, then the time is up.' );
		$this->assertTrue( get_option( 'devbase_retention_last_run' )['unfinished'] );
		$carries_on = __( 'More old submissions were left than one run could delete; the next run carries on.', 'devbase_parent_theme' );
		$this->assertStringContainsString( $carries_on, devbase_forms_retention_last_run_message() );

		remove_filter( 'devbase/forms/retention_budget', $budget );

		$this->assertSame( 5, devbase_forms_run_retention_purge() );
		$this->assertSame( 0, $this->left() );
		$this->assertStringNotContainsString( $carries_on, devbase_forms_retention_last_run_message() );
	}

	public function test_exactly_one_full_batch_ends_without_looping() {
		$this->retention_on();
		$this->old_submissions( 3 );

		$this->assertSame( 3, devbase_forms_run_retention_purge() );
		$this->assertFalse( get_option( 'devbase_retention_last_run' )['unfinished'] );
	}

	public function test_submissions_that_will_not_delete_do_not_loop_for_ever() {
		$this->retention_on();
		$this->old_submissions( 6 );
		add_filter( 'pre_delete_post', '__return_false' ); // something on the site refuses every delete

		$this->assertSame( 0, devbase_forms_run_retention_purge() );
		$this->assertSame( 6, $this->left() );
	}
}
