<?php
// Covers inc/maintenance/events.php: error tracking. What is kept from a fatal error
// and how it is scrubbed, when it is collected (only while the plan has the service),
// the queue, and sending it when Brand Relay's signed ping names `events`. Brand Relay
// is faked with pre_http_request.

class Test_Events extends WP_UnitTestCase {

	private $filter   = null;
	private $requests = array();
	private $events   = array( 200, array( 'v' => 1, 'ok' => true, 'accepted' => 1, 'dropped' => 0, 'new_issues' => 1, 'stored' => 1 ) );
	private $fatal    = null;

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
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( ++$n % 250 + 1 );

		$this->requests = array();
		$this->events   = array( 200, array( 'v' => 1, 'ok' => true, 'accepted' => 1, 'dropped' => 0, 'new_issues' => 1, 'stored' => 1 ) );
		$this->fatal    = null;
		devbase_events_captured( false );

		foreach ( array( DEVBASE_EVENTS_QUEUE, DEVBASE_EVENTS_STATE, DEVBASE_MAINTENANCE_STATE, DEVBASE_MAINTENANCE_LOCK, DEVBASE_RELAY_PLAN_OPTION, DEVBASE_RELAY_STATE_OPTION ) as $option ) {
			delete_option( $option );
		}

		$this->plan( array( 'events', 'health' ) );

		add_filter( 'devbase/events/last_error', array( $this, 'last_error' ) );
		$this->filter = function ( $preempt, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'args' => $args );

			if ( substr( $url, -strlen( '/v1/site/events' ) ) === '/v1/site/events' ) {
				if ( is_wp_error( $this->events ) ) {
					return $this->events;
				}

				return array( 'response' => array( 'code' => $this->events[0] ), 'body' => wp_json_encode( $this->events[1] ), 'headers' => array() );
			}

			if ( substr( $url, -strlen( '/v1/site' ) ) === '/v1/site' ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'v' => 1, 'params' => array(), 'licence' => $this->licence ) ), 'headers' => array() );
			}

			return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
		devbase_tests_rebuild_rest_server();
	}

	public function tear_down() {
		remove_filter( 'devbase/events/last_error', array( $this, 'last_error' ) );
		remove_filter( 'pre_http_request', $this->filter, 10 );

		foreach ( array( DEVBASE_EVENTS_QUEUE, DEVBASE_EVENTS_STATE, DEVBASE_MAINTENANCE_STATE, DEVBASE_MAINTENANCE_LOCK, DEVBASE_RELAY_PLAN_OPTION, DEVBASE_RELAY_STATE_OPTION ) as $option ) {
			delete_option( $option );
		}
		devbase_events_captured( false );

		parent::tear_down();
	}

	public function last_error( $real ) {
		return null === $this->fatal ? $real : $this->fatal;
	}

	private $licence = null;

	/** The saved plan, and what /v1/site answers, list these services as in force. */
	private function plan( $services ) {
		$this->licence = null === $services ? null : array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => $services, 'limits' => array() );
		devbase_relay_store_plan( array( 'licence' => $this->licence ) );
	}

	private function crash( $message = 'Call to undefined function foo()', $file = '/var/www/html/wp-content/plugins/shop/cart.php', $line = 42, $type = E_ERROR ) {
		$this->fatal = array( 'type' => $type, 'message' => $message, 'file' => $file, 'line' => $line );
		devbase_events_captured( false );

		return devbase_events_capture();
	}

	private function queue() {
		return array_values( (array) get_option( DEVBASE_EVENTS_QUEUE, array() ) );
	}

	private function ping( $body ) {
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 12 ) );
		$origin    = 'https://relay.test';

		$request = new WP_REST_Request( 'POST', '/devbase/v1/sync' );
		$request->set_body( $body );
		$request->set_header( 'origin', $origin );
		$request->set_header( 'x-relay-timestamp', $timestamp );
		$request->set_header( 'x-relay-nonce', $nonce );
		$request->set_header( 'x-relay-signature', hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $origin . '.' . $body, devbase_relay_ping_secret() ) );

		return rest_get_server()->dispatch( $request );
	}

	// --- Scrubbing -------------------------------------------------------------

	public function test_paths_are_cut_down_to_wp_content_onwards() {
		$this->assertSame( 'wp-content/plugins/shop/cart.php', devbase_events_relative_path( '/home/user/public_html/wp-content/plugins/shop/cart.php' ) );
		$this->assertSame( 'wp-includes/load.php', devbase_events_relative_path( 'C:\\inetpub\\site\\wp-includes\\load.php' ) );
		$this->assertSame( 'thing.php', devbase_events_relative_path( '/opt/other/place/thing.php' ), 'Outside WordPress: just the file name.' );
	}

	public function test_a_message_is_scrubbed_before_it_is_kept() {
		$this->assertSame( 'Uncaught Error: boom in wp-content/plugins/shop/cart.php:42', devbase_events_scrub( "Uncaught Error: boom in /home/u/public_html/wp-content/plugins/shop/cart.php:42\nStack trace:\n#0 /home/u/x.php(3): foo('secret')" ), 'First line only, paths cut.' );
		$this->assertSame( 'Uncaught Error: boom in wp-content/mu-plugins/x.php:5', devbase_events_scrub( 'Uncaught Error: boom in /Users/me/Desktop/Web Design/Wordpress/site/app/public/wp-content/mu-plugins/x.php:5' ), 'A folder with a space in its name does not leak the layout.' );
		$this->assertSame( 'Failed in wp-includes/load.php', devbase_events_scrub( 'Failed in C:\\Program Files\\Local Sites\\site\\wp-includes\\load.php' ) );
		$this->assertStringNotContainsString( 'jane@example.com', devbase_events_scrub( 'Mail to jane@example.com failed' ) );
		$this->assertStringContainsString( '[email]', devbase_events_scrub( 'Mail to jane@example.com failed' ) );
		$this->assertSame( 'Fetch https://api.test/v1/x failed', devbase_events_scrub( 'Fetch https://api.test/v1/x?token=abc123&k=1 failed' ), 'No query strings.' );
		$this->assertStringContainsString( '[token]', devbase_events_scrub( 'Bad key Zq8vK2mXr9TbL4wYc7NdE1hGa5Fs given' ) );
		$this->assertSame( 'Plain words stay', devbase_events_scrub( 'Plain words stay' ) );
		$this->assertLessThanOrEqual( 500, strlen( devbase_events_scrub( str_repeat( 'a ', 600 ) ) ), 'Capped at 500 characters.' );
		$this->assertSame( 'See https://example.com/a/b for details', devbase_events_scrub( 'See https://example.com/a/b for details' ), 'A URL is not a path.' );
	}

	public function test_an_event_has_the_fields_brand_relay_reads_and_no_request_data() {
		$before                 = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/checkout/?order=1234&token=secret';
		$event = devbase_events_build( array( 'type' => E_ERROR, 'message' => 'Call to undefined function foo()', 'file' => '/var/www/html/wp-content/plugins/shop/cart.php', 'line' => 42 ) );

		$this->assertSame( 'php', $event['type'] );
		$this->assertSame( 'fatal', $event['level'] );
		$this->assertSame( 'wp-content/plugins/shop/cart.php', $event['file'] );
		$this->assertSame( 42, $event['line'] );
		$this->assertSame( '/checkout/', $event['url'], 'The path only: no query string.' );
		$this->assertSame( 1, $event['count'] );
		$this->assertSame( 'shop', $event['context']['plugin'] );
		$this->assertSame( get_bloginfo( 'version' ), $event['context']['wp'] );
		$this->assertLessThanOrEqual( 20, count( $event['context'] ) );
		null === $before ? $_SERVER['REQUEST_URI'] = '' : $_SERVER['REQUEST_URI'] = $before;
	}

	public function test_an_uncaught_exception_is_typed_as_one() {
		$event = devbase_events_build( array( 'type' => E_ERROR, 'message' => "Uncaught Exception: nope in /x/wp-content/themes/mine/a.php:7\nStack trace:\n#0 {main}", 'file' => '/x/wp-content/themes/mine/a.php', 'line' => 7 ) );

		$this->assertSame( 'exception', $event['type'] );
		$this->assertSame( 'mine', $event['context']['theme'] );
	}

	// --- Collecting --------------------------------------------------------------

	public function test_a_fatal_is_queued_while_the_plan_has_error_tracking() {
		$this->assertTrue( $this->crash() );

		$this->assertCount( 1, $this->queue() );
		$this->assertSame( 'fatal', $this->queue()[0]['level'] );
	}

	public function test_nothing_is_collected_when_the_plan_lacks_error_tracking() {
		$this->plan( array( 'health' ) );

		$this->assertFalse( $this->crash() );
		$this->assertCount( 0, $this->queue(), 'A plan without it costs nothing.' );

		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		$this->assertFalse( $this->crash(), 'No plan known at all: not collected either.' );
	}

	public function test_only_errors_that_end_a_request_are_kept() {
		$this->assertFalse( $this->crash( 'Undefined variable $x', '/a/b.php', 3, E_WARNING ) );
		$this->assertFalse( $this->crash( 'Function deprecated', '/a/b.php', 3, E_DEPRECATED ) );
		$this->assertTrue( $this->crash( 'Parse error', '/a/b.php', 3, E_PARSE ) );
		$this->assertCount( 1, $this->queue() );
	}

	public function test_a_request_is_looked_at_once() {
		$this->crash();

		$this->assertFalse( devbase_events_capture(), 'The second hook for the same request adds nothing.' );
		$this->assertCount( 1, $this->queue() );
	}

	public function test_the_same_error_counts_up_instead_of_filling_the_queue() {
		$this->crash( 'Call to undefined function foo() on row 12' );
		$this->crash( 'Call to undefined function foo() on row 98' );
		$this->crash( 'A different failure' );

		$queue = $this->queue();
		$this->assertCount( 2, $queue );
		$this->assertSame( 2, $queue[0]['count'], 'Same place and message (numbers aside): one event with a count.' );
	}

	public function test_the_queue_never_grows_past_its_cap() {
		foreach ( range( 1, DEVBASE_EVENTS_QUEUE_MAX + 20 ) as $i ) {
			$this->crash( "Failure number-$i-x", '/a/wp-content/plugins/p/file' . $i . '.php', $i );
		}

		$this->assertCount( DEVBASE_EVENTS_QUEUE_MAX, $this->queue() );
	}

	public function test_the_wp_die_handler_filter_collects_and_changes_nothing() {
		$this->fatal = array( 'type' => E_ERROR, 'message' => 'Fatal in the middle of wp_die', 'file' => '/x/wp-content/plugins/p/a.php', 'line' => 1 );
		devbase_events_captured( false );

		apply_filters( 'wp_die_handler', '_default_wp_die_handler' );

		$this->assertCount( 1, $this->queue(), 'WordPress ends the request there, before our shutdown function would run.' );

		// (The test suite swaps the handler for its own afterwards, so ours is called directly.)
		$ours = $GLOBALS['wp_filter']['wp_die_handler']->callbacks[1];
		$this->assertSame( 'the-handler', call_user_func( reset( $ours )['function'], 'the-handler' ), 'The handler is returned as it came.' );
	}

	// --- Sending ----------------------------------------------------------------------

	public function test_the_queued_errors_are_sent_in_one_batch_and_then_leave_the_queue() {
		$this->crash();

		$result = devbase_events_run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertCount( 0, $this->queue(), 'Taken in: off the queue.' );
		$request = $this->requests[0];
		$this->assertStringEndsWith( '/v1/site/events', $request['url'] );
		$this->assertSame( 'Bearer brs_testkey', $request['args']['headers']['Authorization'] );
		$body = json_decode( $request['args']['body'], true );
		$this->assertCount( 1, $body['events'] );
		foreach ( array( 'type', 'level', 'message', 'file', 'line', 'url', 'count', 'at', 'context' ) as $field ) {
			$this->assertArrayHasKey( $field, $body['events'][0] );
		}
	}

	public function test_nothing_queued_sends_an_empty_report() {
		$result = devbase_events_run();

		$this->assertSame( 'clear', $result['status'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( '{"events":[]}', $this->requests[0]['args']['body'] );
		$this->assertNotEmpty( get_option( DEVBASE_EVENTS_STATE )['checked_at'] );
	}

	public function test_a_big_queue_is_sent_fifty_at_a_time() {
		foreach ( range( 1, 70 ) as $i ) {
			$this->crash( "Failure kind-$i-x", '/a/wp-content/plugins/p/file' . $i . '.php', $i );
		}

		devbase_events_run();

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertCount( DEVBASE_EVENTS_BATCH_MAX, $body['events'] );
		$this->assertCount( 20, $this->queue(), 'The rest wait for the next ask.' );
	}

	public function test_a_batch_stays_under_the_size_limit() {
		foreach ( range( 1, 50 ) as $i ) {
			$this->crash( str_repeat( 'x', 480 ) . "-$i-" . chr( 97 + $i % 20 ), '/a/wp-content/plugins/p/file' . $i . '.php', $i );
		}
		$queue = get_option( DEVBASE_EVENTS_QUEUE );
		foreach ( $queue as $k => $event ) {
			$queue[ $k ]['context']['note'] = str_repeat( 'y', 150 );
		}
		update_option( DEVBASE_EVENTS_QUEUE, $queue, false );

		devbase_events_run();

		$this->assertLessThanOrEqual( DEVBASE_EVENTS_BATCH_BYTES, strlen( $this->requests[0]['args']['body'] ) );
	}

	public function test_the_daily_limit_and_a_too_soon_answer_keep_the_queue() {
		$this->crash();

		$this->events = array( 429, array( 'error' => 'daily_limit' ) );
		$result       = devbase_events_run();
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'daily limit reached', $result['detail'] );
		$this->assertCount( 1, $this->queue() );

		$this->events = array( 429, array( 'error' => 'rate_limited' ) );
		$this->assertSame( 'too soon', devbase_events_run()['detail'] );
		$this->assertCount( 1, $this->queue() );
	}

	public function test_a_refusal_for_the_plan_or_the_key_keeps_the_queue() {
		$this->crash();

		$this->events = array( 403, array( 'error' => 'feature_off' ) );
		$this->assertSame( 'not_in_plan', devbase_events_run()['status'] );

		$this->events = array( 401, array( 'error' => 'invalid_key' ) );
		$this->assertSame( 'refused', devbase_events_run()['status'] );

		$this->assertCount( 1, $this->queue() );
	}

	public function test_a_server_error_keeps_the_queue_and_a_batch_that_can_never_be_accepted_is_dropped() {
		$this->crash();

		$this->events = array( 500, array() );
		$this->assertSame( 'HTTP 500', devbase_events_run()['detail'] );
		$this->assertCount( 1, $this->queue() );

		$this->events = new WP_Error( 'http_request_failed', 'timed out' );
		$this->assertSame( 'timed out', devbase_events_run()['detail'] );
		$this->assertCount( 1, $this->queue() );

		$this->events = array( 400, array( 'error' => 'invalid_body' ) );
		devbase_events_run();
		$this->assertCount( 0, $this->queue(), 'It could never be accepted, so it must not block the queue for good.' );
	}

	// --- Through the signed ping ----------------------------------------------------------------

	public function test_a_ping_naming_events_sends_the_queue() {
		$this->crash();

		$ran = $this->ping( '{"run":["events"]}' )->get_data()['ran'];

		$this->assertSame( 'sent', $ran->events );
		$this->assertCount( 0, $this->queue() );
	}

	public function test_a_ping_with_nothing_queued_sends_an_empty_report_and_says_clear() {
		$this->assertSame( 'clear', $this->ping( '{"run":["events"]}' )->get_data()['ran']->events );

		$posts = array_values( array_filter( $this->requests, function ( $r ) { return substr( $r['url'], -15 ) === '/v1/site/events'; } ) );
		$this->assertCount( 1, $posts );
		$this->assertSame( '{"events":[]}', $posts[0]['args']['body'] );
	}

	public function test_an_empty_report_that_is_too_soon_says_skipped() {
		$this->events = array( 429, array( 'error' => 'rate_limited' ) );

		$this->assertSame( 'skipped', $this->ping( '{"run":["events"]}' )->get_data()['ran']->events );
	}

	public function test_a_ping_for_events_when_the_plan_lacks_it_says_so() {
		$this->plan( array( 'health' ) );
		$this->crash( 'x' );

		$this->assertSame( 'not_in_plan', $this->ping( '{"run":["events"]}' )->get_data()['ran']->events );
		$this->assertCount( 0, array_filter( $this->requests, function ( $r ) { return substr( $r['url'], -15 ) === '/v1/site/events'; } ) );
	}

	public function test_a_ping_naming_health_and_events_runs_both() {
		$this->crash();

		$ran = $this->ping( '{"run":["health","events"]}' )->get_data()['ran'];

		$this->assertSame( 'sent', $ran->health );
		$this->assertSame( 'sent', $ran->events );
	}

	// --- What the developer sees ----------------------------------------------------------------

	public function test_the_status_line_shows_the_queue_and_the_last_send() {
		$this->crash();
		$this->assertStringContainsString( '1', devbase_events_status_html() );

		devbase_events_run();
		$this->assertStringContainsString( esc_html( sprintf( __( 'Last sent %s ago.', 'devbase_parent_theme' ), human_time_diff( time() ) ) ), devbase_events_status_html() );

		$this->plan( array( 'health' ) );
		$this->assertStringContainsString( esc_html__( 'Not included in this site\'s plan, so errors are not collected.', 'devbase_parent_theme' ), devbase_events_status_html() );
	}
}
