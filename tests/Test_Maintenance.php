<?php
// Covers inc/maintenance/: the plan snapshot, what a signed Brand Relay ping can ask
// the site to run (settings, health, events, backup, uptime), the checks done before
// anything is sent, and the site health report. Brand Relay is faked with
// pre_http_request; nothing here talks to a real server.

class Test_Maintenance extends WP_UnitTestCase {

	private $filter    = null;
	private $requests  = array();
	private $site      = array();   // what GET /v1/site answers
	private $heartbeat = array( 200, array( 'v' => 1, 'ok' => true, 'stored' => true, 'next_in' => 300 ) );

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
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( ++$n % 250 + 1 ); // each test its own IP for the ping limit

		$this->requests  = array();
		$this->heartbeat = array( 200, array( 'v' => 1, 'ok' => true, 'stored' => true, 'next_in' => 300 ) );
		$this->in_force( array( 'health' ) );

		foreach ( array( DEVBASE_MAINTENANCE_STATE, DEVBASE_MAINTENANCE_LOCK, DEVBASE_RELAY_PLAN_OPTION, DEVBASE_RELAY_STATE_OPTION ) as $option ) {
			delete_option( $option );
		}

		$this->fake();
		devbase_tests_rebuild_rest_server();
	}

	public function tear_down() {
		if ( null !== $this->filter ) {
			remove_filter( 'pre_http_request', $this->filter, 10 );
			$this->filter = null;
		}

		foreach ( array( DEVBASE_MAINTENANCE_STATE, DEVBASE_MAINTENANCE_LOCK, DEVBASE_RELAY_PLAN_OPTION, DEVBASE_RELAY_STATE_OPTION ) as $option ) {
			delete_option( $option );
		}
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );

		parent::tear_down();
	}

	/** What `/v1/site` answers: a subscription listing these services (or none at all). */
	private function in_force( $services, $extra = array() ) {
		$this->site = array(
			'v'      => 1,
			'site'   => array( 'name' => 'Test', 'domain' => 'test.example' ),
			'params' => array(),
			'ttl'    => 3600,
		);

		if ( null !== $services ) {
			$this->site['licence'] = array_merge( array(
				'id'       => 'sub_1',
				'plan'     => 'Pro',
				'status'   => 'active',
				'services' => $services,
				'limits'   => array( 'health' => array( 'interval' => 15 ) ),
			), $extra );
		}
	}

	private function fake() {
		$this->filter = function ( $preempt, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'args' => $args );

			if ( false !== strpos( $url, '/v1/site/heartbeat' ) ) {
				if ( is_wp_error( $this->heartbeat ) ) {
					return $this->heartbeat;
				}

				return array( 'response' => array( 'code' => $this->heartbeat[0] ), 'body' => wp_json_encode( $this->heartbeat[1] ), 'headers' => array() );
			}

			if ( false !== strpos( $url, '/v1/site' ) ) {
				if ( false === $this->site ) {
					return array( 'response' => array( 'code' => 500 ), 'body' => '', 'headers' => array() );
				}

				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $this->site ), 'headers' => array() );
			}

			return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
		};

		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	private function count_requests( $suffix ) {
		return count( array_filter( $this->requests, function ( $r ) use ( $suffix ) {
			return substr( $r['url'], -strlen( $suffix ) ) === $suffix;
		} ) );
	}

	/** Brand Relay's signed ping, with a JSON body, through the REST route. */
	private function ping( $body = '', $sign_body = null ) {
		$sign_body = null === $sign_body ? $body : $sign_body;
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 12 ) );
		$origin    = 'https://relay.test';

		$request = new WP_REST_Request( 'POST', '/devbase/v1/sync' );
		$request->set_body( $body );
		$request->set_header( 'origin', $origin );
		$request->set_header( 'x-relay-timestamp', $timestamp );
		$request->set_header( 'x-relay-nonce', $nonce );
		$request->set_header( 'x-relay-signature', hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $origin . '.' . $sign_body, devbase_relay_ping_secret() ) );

		return rest_get_server()->dispatch( $request );
	}

	// --- The plan --------------------------------------------------------------

	public function test_a_service_is_in_force_only_while_the_plan_says_so() {
		devbase_relay_store_plan( $this->site );
		$this->assertTrue( devbase_relay_service_in_force( 'health' ) );
		$this->assertFalse( devbase_relay_service_in_force( 'events' ), 'Not listed.' );
		$this->assertSame( array( 'interval' => 15 ), devbase_relay_service_limits( 'health' ) );
		$this->assertNull( devbase_relay_service_limits( 'events' ) );

		$this->in_force( array( 'health' ), array( 'status' => 'suspended' ) );
		devbase_relay_store_plan( $this->site );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'Suspended.' );

		$this->in_force( null );
		devbase_relay_store_plan( $this->site );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'No subscription at all.' );
	}

	public function test_the_expiry_and_the_start_are_checked_against_the_clock() {
		$this->in_force( array( 'health' ), array( 'expires_at' => gmdate( 'c', time() - 60 ) ) );
		devbase_relay_store_plan( $this->site );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'Expired a minute ago: not in force even though the answer is fresh.' );

		$this->in_force( array( 'health' ), array( 'starts_at' => gmdate( 'c', time() + 3600 ) ) );
		devbase_relay_store_plan( $this->site );
		$this->assertFalse( devbase_relay_service_in_force( 'health' ), 'Not started yet.' );

		$this->in_force( array( 'health' ), array( 'starts_at' => gmdate( 'c', time() - 3600 ), 'expires_at' => gmdate( 'c', time() + 3600 ) ) );
		devbase_relay_store_plan( $this->site );
		$this->assertTrue( devbase_relay_service_in_force( 'health' ) );
	}

	public function test_every_pull_of_the_site_answer_refreshes_the_plan() {
		$this->assertIsArray( devbase_relay_fetch_site() );
		$this->assertTrue( devbase_relay_service_in_force( 'health' ) );
	}

	// --- What a ping asks for ----------------------------------------------------

	public function test_an_empty_ping_is_the_old_settings_ping() {
		$this->assertSame( array( 'settings' ), devbase_maintenance_requested( '' ) );
		$this->assertSame( array( 'settings' ), devbase_maintenance_requested( 'not json' ) );
		$this->assertSame( array( 'settings' ), devbase_maintenance_requested( '{"other":1}' ) );
	}

	public function test_the_body_names_what_to_run_and_unknown_names_are_ignored() {
		$this->assertSame( array( 'health' ), devbase_maintenance_requested( '{"run":["health"]}' ) );
		$this->assertSame( array( 'settings', 'health', 'events', 'backup', 'uptime' ), devbase_maintenance_requested( '{"run":["settings","health","events","backup","uptime"]}' ) );
		$this->assertSame( array( 'health' ), devbase_maintenance_requested( '{"run":["nope",7,null,"health","health"]}' ), 'Unknown, odd and repeated names are dropped.' );
		$this->assertSame( array(), devbase_maintenance_requested( '{"run":["nope"]}' ), 'Nothing known asked for: nothing runs (not settings).' );
	}

	// --- Running the services ----------------------------------------------------------

	public function test_the_plan_is_pulled_first_and_then_the_report_is_sent() {
		$ran = devbase_maintenance_run( array( 'health' ) );

		$this->assertSame( array( 'health' => 'sent' ), $ran );
		$this->assertSame( 1, $this->count_requests( '/v1/site' ), 'The plan, first.' );
		$this->assertSame( '/v1/site', substr( $this->requests[0]['url'], -8 ) );
		$this->assertSame( 1, $this->count_requests( '/v1/site/heartbeat' ) );

		$request = $this->requests[1]['args'];
		$this->assertSame( 'Bearer brs_testkey', $request['headers']['Authorization'] );
		$body = json_decode( $request['body'], true );
		$this->assertSame( get_bloginfo( 'version' ), $body['wordpress'] );
		$this->assertSame( PHP_VERSION, $body['php'] );
		$this->assertArrayHasKey( 'updates', $body );
		$this->assertIsBool( $body['cron_ok'] );
	}

	public function test_a_plan_pulled_a_moment_ago_is_not_pulled_again() {
		devbase_relay_fetch_site(); // as when the same ping also ran `settings`
		$this->requests = array();

		devbase_maintenance_run( array( 'health' ) );

		$this->assertSame( 0, $this->count_requests( '/v1/site' ), 'Fresh enough: used as it is.' );
		$this->assertSame( 1, $this->count_requests( '/v1/site/heartbeat' ) );
	}

	public function test_a_report_that_came_too_soon_is_a_success_and_the_interval_is_kept() {
		$this->heartbeat = array( 202, array( 'ok' => true, 'stored' => false, 'next_in' => 2700 ) );

		$ran = devbase_maintenance_run( array( 'health' ) );

		$this->assertSame( array( 'health' => 'sent' ), $ran );
		$this->assertSame( 2700, devbase_maintenance_state()['services']['health']['gap'] );
	}

	public function test_nothing_is_sent_when_the_plan_does_not_include_the_service() {
		$this->in_force( array( 'uptime' ) );

		$ran = devbase_maintenance_run( array( 'health' ) );

		$this->assertSame( array( 'health' => 'not_in_plan' ), $ran );
		$this->assertSame( 0, $this->count_requests( '/v1/site/heartbeat' ), 'No sending in the blind.' );
		$this->assertSame( 'not_in_plan', devbase_maintenance_state()['services']['health']['status'] );
	}

	public function test_nothing_is_sent_for_a_site_with_no_subscription_or_an_expired_one() {
		$this->in_force( null );
		$this->assertSame( array( 'health' => 'not_in_plan' ), devbase_maintenance_run( array( 'health' ) ) );

		delete_option( DEVBASE_MAINTENANCE_STATE );
		$this->in_force( array( 'health' ), array( 'expires_at' => gmdate( 'c', time() - 10 ) ) );
		$this->assertSame( array( 'health' => 'not_in_plan' ), devbase_maintenance_run( array( 'health' ) ) );

		$this->assertSame( 0, $this->count_requests( '/v1/site/heartbeat' ) );
	}

	public function test_a_refusal_after_a_plan_that_said_yes_is_recorded_and_the_next_ping_pulls_the_plan_again() {
		$this->heartbeat = array( 403, array( 'error' => 'feature_off' ) );

		$ran = devbase_maintenance_run( array( 'health' ) );

		$this->assertSame( array( 'health' => 'not_in_plan' ), $ran );
		$this->assertSame( 'not_in_plan', devbase_maintenance_state()['services']['health']['status'] );
	}

	public function test_a_refused_key_is_an_error_not_a_crash() {
		$this->heartbeat = array( 401, array( 'error' => 'invalid_key' ) );

		$this->assertSame( array( 'health' => 'error' ), devbase_maintenance_run( array( 'health' ) ) );
		$this->assertSame( 'refused', devbase_maintenance_state()['services']['health']['status'] );
	}

	public function test_network_and_server_errors_are_reported() {
		$this->heartbeat = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		$this->assertSame( array( 'health' => 'error' ), devbase_maintenance_run( array( 'health' ) ) );
		$this->assertStringContainsString( 'timed out', devbase_maintenance_state()['services']['health']['detail'] );

		delete_option( DEVBASE_MAINTENANCE_STATE );
		$this->heartbeat = array( 500, array() );
		devbase_maintenance_run( array( 'health' ) );
		$this->assertSame( 'HTTP 500', devbase_maintenance_state()['services']['health']['detail'] );
	}

	public function test_a_failed_plan_pull_with_nothing_held_sends_nothing() {
		$this->site = false;

		$ran = devbase_maintenance_run( array( 'health' ) );

		$this->assertSame( array( 'health' => 'error' ), $ran );
		$this->assertSame( 'HTTP 500', devbase_maintenance_state()['plan_error'] );
		$this->assertSame( 0, $this->count_requests( '/v1/site/heartbeat' ), 'No plan known: nothing is in force.' );
	}

	public function test_a_failed_plan_pull_keeps_the_last_good_plan() {
		devbase_relay_store_plan( $this->site );
		$state = devbase_relay_plan();
		$state['fetched_at'] = time() - HOUR_IN_SECONDS; // stale, so it will be pulled
		update_option( DEVBASE_RELAY_PLAN_OPTION, $state, false );
		$this->site = false;

		$ran = devbase_maintenance_run( array( 'health' ) );

		$this->assertSame( array( 'health' => 'sent' ), $ran, 'The last good plan still stands.' );
	}

	public function test_services_that_are_not_built_or_not_ours_are_skipped() {
		$this->in_force( array( 'uptime', 'health', 'events', 'backup' ) );

		$ran = devbase_maintenance_run( array( 'health', 'events', 'backup', 'uptime' ) );

		$this->assertSame( array( 'health' => 'sent', 'events' => 'skipped', 'backup' => 'skipped', 'uptime' => 'skipped' ), $ran );
	}

	public function test_a_flood_of_pings_sends_nothing_extra() {
		$this->assertSame( array( 'health' => 'sent' ), devbase_maintenance_run( array( 'health' ) ) );

		$this->assertSame( array( 'health' => 'skipped' ), devbase_maintenance_run( array( 'health' ) ), 'Within the gap: skipped.' );
		$this->assertSame( 1, $this->count_requests( '/v1/site/heartbeat' ) );
	}

	public function test_only_one_run_at_a_time() {
		$this->assertTrue( devbase_maintenance_lock() );
		$this->assertFalse( devbase_maintenance_lock() );
		$this->assertSame( array( 'health' => 'skipped' ), devbase_maintenance_run( array( 'health' ) ) );

		update_option( DEVBASE_MAINTENANCE_LOCK, time() - 5 * MINUTE_IN_SECONDS );
		$this->assertTrue( devbase_maintenance_lock(), 'A lock older than two minutes is stale.' );
	}

	public function test_nothing_runs_without_a_list() {
		$this->assertSame( array(), devbase_maintenance_run( array() ) );
		$this->assertCount( 0, $this->requests );
	}

	// --- The signed ping through the route --------------------------------------------------

	public function test_a_ping_naming_health_pulls_the_plan_and_sends_the_report() {
		$response = $this->ping( '{"run":["health"]}' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'sent', $data['ran']->health );
		$this->assertObjectNotHasProperty( 'settings', $data['ran'], 'Settings were not asked for.' );
		$this->assertSame( 1, $this->count_requests( '/v1/site/heartbeat' ) );
	}

	public function test_an_empty_ping_still_only_syncs_the_settings() {
		$response = $this->ping( '' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $response->get_data()['ran']->settings );
		$this->assertSame( 0, $this->count_requests( '/v1/site/heartbeat' ), 'The old ping never sends a report.' );
	}

	public function test_a_ping_for_settings_and_health_pulls_the_site_answer_only_once() {
		$response = $this->ping( '{"run":["settings","health"]}' );

		$ran = $response->get_data()['ran'];
		$this->assertSame( 'ok', $ran->settings );
		$this->assertSame( 'sent', $ran->health );
		$this->assertSame( 1, $this->count_requests( '/v1/site' ), 'The sync pulled it; the report used that fresh plan.' );
	}

	public function test_all_four_names_are_answered() {
		$this->in_force( array( 'uptime', 'health', 'events', 'backup' ) );

		$ran = $this->ping( '{"run":["settings","health","events","backup","uptime"]}' )->get_data()['ran'];

		$this->assertSame( 'ok', $ran->settings );
		$this->assertSame( 'sent', $ran->health );
		$this->assertSame( 'skipped', $ran->events );
		$this->assertSame( 'skipped', $ran->backup );
		$this->assertSame( 'skipped', $ran->uptime );
	}

	public function test_a_ping_for_a_service_not_in_the_plan_says_so() {
		$this->in_force( array( 'uptime' ) );

		$this->assertSame( 'not_in_plan', $this->ping( '{"run":["health"]}' )->get_data()['ran']->health );
		$this->assertSame( 0, $this->count_requests( '/v1/site/heartbeat' ) );
	}

	public function test_a_body_that_was_changed_after_signing_is_refused() {
		$response = $this->ping( '{"run":["health"]}', '' ); // signed as the empty ping, sent with a body

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, $this->count_requests( '/v1/site/heartbeat' ), 'The body is part of the signature.' );
	}

	// --- The report ------------------------------------------------------------------------

	public function test_the_report_counts_waiting_updates_from_the_saved_checks_without_a_logged_in_user() {
		set_site_transient( 'update_plugins', (object) array( 'response' => array( 'a/a.php' => (object) array(), 'b/b.php' => (object) array() ) ) );
		set_site_transient( 'update_themes', (object) array( 'response' => array( 'x' => array() ) ) );
		wp_set_current_user( 0 );

		$updates = devbase_heartbeat_report()['updates'];

		$this->assertSame( 2, $updates['plugins'] );
		$this->assertSame( 1, $updates['themes'] );
		$this->assertSame( 0, $updates['core'] );
	}

	public function test_the_report_leaves_out_what_it_cannot_read() {
		$report = devbase_heartbeat_report();

		foreach ( $report as $key => $value ) {
			$this->assertNotNull( $value, "$key must not be sent empty." );
		}
		$this->assertArrayNotHasKey( 'last_backup_at', $report, 'No backup to report yet.' );
		$this->assertArrayNotHasKey( 'parent_theme', $report, 'Not running a child theme here.' );
	}

	public function test_a_backup_time_can_be_added_by_a_filter() {
		$when = function () { return '2026-11-01T03:00:00Z'; };
		add_filter( 'devbase/maintenance/last_backup_at', $when );

		$report = devbase_heartbeat_report();

		remove_filter( 'devbase/maintenance/last_backup_at', $when );
		$this->assertSame( '2026-11-01T03:00:00Z', $report['last_backup_at'] );
	}

	public function test_scheduled_tasks_are_unhealthy_when_one_is_over_an_hour_overdue() {
		$this->assertTrue( devbase_heartbeat_cron_ok() );

		wp_schedule_single_event( time() - 2 * HOUR_IN_SECONDS, 'devbase_test_overdue_event' );
		$this->assertFalse( devbase_heartbeat_cron_ok() );
		wp_clear_scheduled_hook( 'devbase_test_overdue_event' );
	}

	// --- What the developer sees ----------------------------------------------------------------

	public function test_the_status_line_reflects_each_outcome() {
		$this->assertStringContainsString( esc_html__( 'No report sent yet: the management server has not asked for one.', 'devbase_parent_theme' ), devbase_heartbeat_status_html() );

		devbase_maintenance_run( array( 'health' ) );
		$this->assertStringContainsString( ' ', devbase_heartbeat_status_html() );
		$this->assertStringNotContainsString( esc_html__( 'No report sent yet: the management server has not asked for one.', 'devbase_parent_theme' ), devbase_heartbeat_status_html() );

		$this->in_force( array( 'uptime' ) );
		delete_option( DEVBASE_MAINTENANCE_STATE );
		delete_option( DEVBASE_RELAY_PLAN_OPTION ); // the plan pulled a second ago would otherwise be reused
		devbase_maintenance_run( array( 'health' ) );
		$this->assertStringContainsString( esc_html__( 'Not included in this site\'s plan, so no reports are sent.', 'devbase_parent_theme' ), devbase_heartbeat_status_html() );

		add_filter( 'devbase/relay/url', '__return_empty_string' );
		$this->assertStringContainsString( esc_html__( 'Site health reports are off: enter the management server address and site access key first.', 'devbase_parent_theme' ), devbase_heartbeat_status_html() );
		remove_filter( 'devbase/relay/url', '__return_empty_string' );
	}
}
