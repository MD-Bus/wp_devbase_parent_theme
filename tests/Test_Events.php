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
		devbase_events_state( 'reset' );

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
		devbase_events_state( 'reset' );

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

	// --- Warnings and notices ---------------------------------------------------------

	/** What PHP would do: the handler is called with an error; this returns what it answers. */
	private function warn( $errno, $message, $file = '/var/www/wp-content/plugins/shop/cart.php', $line = 10 ) {
		return devbase_events_handler( $errno, $message, $file, $line );
	}

	public function test_php_error_numbers_map_to_the_levels_brand_relay_knows() {
		$this->assertSame( 'warning', devbase_events_level( E_WARNING ) );
		$this->assertSame( 'warning', devbase_events_level( E_USER_WARNING ) );
		$this->assertSame( 'notice', devbase_events_level( E_NOTICE ) );
		$this->assertSame( 'notice', devbase_events_level( E_DEPRECATED ) );
		$this->assertSame( 'notice', devbase_events_level( E_USER_DEPRECATED ) );
		$this->assertSame( 'error', devbase_events_level( E_RECOVERABLE_ERROR ) );
		$this->assertNull( devbase_events_level( E_ERROR ), 'Fatals have their own path.' );
		$this->assertNull( devbase_events_level( E_PARSE ) );
	}

	public function test_warnings_notices_and_deprecations_are_queued_once_at_the_end_of_the_request() {
		$this->warn( E_WARNING, 'Undefined array key "x"' );
		$this->warn( E_NOTICE, 'Undefined variable $y', '/var/www/wp-content/themes/t/a.php', 5 );
		$this->warn( E_DEPRECATED, 'Function foo() is deprecated', '/var/www/wp-content/plugins/old/b.php', 7 );

		$this->assertCount( 0, $this->queue(), 'Nothing is written while the request runs.' );

		$this->assertTrue( devbase_events_flush() );

		$queue = $this->queue();
		$this->assertCount( 3, $queue );
		$this->assertSame( array( 'warning', 'notice', 'notice' ), array_column( $queue, 'level' ) );
		$this->assertSame( 'deprecated', $queue[2]['type'] );
		$this->assertSame( 'wp-content/plugins/shop/cart.php', $queue[0]['file'] );
		$this->assertSame( 'shop', $queue[0]['context']['plugin'] );
		$this->assertFalse( devbase_events_flush(), 'Flushed once: the request has nothing left.' );
	}

	public function test_a_deprecation_from_a_bundled_plugin_is_not_kept_but_its_warnings_are() {
		$dir = WP_PLUGIN_DIR . '/wpvivid-backuprestore/vendor/guzzlehttp/promises/src/Promise.php';
		$this->warn( E_DEPRECATED, 'Implicitly marking parameter $waitFn as nullable is deprecated', $dir, 22 );
		$this->warn( E_USER_DEPRECATED, 'Old thing', WP_PLUGIN_DIR . '/woocommerce/includes/a.php', 3 );
		$this->warn( E_WARNING, 'Undefined array key "x"', $dir, 30 );
		$this->warn( E_DEPRECATED, 'Function foo() is deprecated', WP_PLUGIN_DIR . '/some-client-plugin/b.php', 7 );

		$this->assertTrue( devbase_events_flush() );

		$queue = $this->queue();
		$this->assertCount( 2, $queue, 'Only the bundled plugins\' deprecations are left out.' );
		$this->assertSame( array( 'warning', 'notice' ), array_column( $queue, 'level' ) );
	}

	// --- Failures that are not PHP errors: mail, forms ------------------------------------

	public function test_a_silent_failure_is_queued_as_an_error_of_its_own_type() {
		$this->assertTrue( devbase_events_report( 'mail', 'A mail failed to send', array( 'reason' => 'SMTP connect() failed for jane@example.com' ) ) );

		$queue = $this->queue();
		$this->assertCount( 1, $queue );
		$this->assertSame( 'mail', $queue[0]['type'] );
		$this->assertSame( 'error', $queue[0]['level'] );
		$this->assertSame( 'A mail failed to send', $queue[0]['message'] );
		$this->assertSame( 'SMTP connect() failed for [email]', $queue[0]['context']['reason'], 'No address leaves the site.' );
	}

	public function test_a_silent_failure_is_not_queued_when_the_plan_lacks_error_tracking() {
		$this->plan( array( 'health' ) );

		$this->assertFalse( devbase_events_report( 'mail', 'A mail failed to send' ) );
		$this->assertCount( 0, $this->queue() );
	}

	public function test_a_failed_wp_mail_is_an_error_for_brand_relay() {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.', array( 'to' => array( 'client@example.com' ) ) ) );

		$queue = $this->queue();
		$this->assertCount( 1, $queue );
		$this->assertSame( 'mail', $queue[0]['type'] );
		$this->assertStringNotContainsString( 'client@example.com', wp_json_encode( $queue ), 'The recipient is not sent.' );
	}

	public function test_the_same_silent_failure_is_one_event_not_one_per_occurrence() {
		devbase_events_report( 'form', 'Form delivery failed: Contact (smtp)', array( 'form' => 'Contact' ) );
		devbase_events_report( 'form', 'Form delivery failed: Contact (smtp)', array( 'form' => 'Contact' ) );
		devbase_events_report( 'form', 'Form delivery failed: Quote (smtp)', array( 'form' => 'Quote' ) );

		$this->assertCount( 2, $this->queue(), 'One per form and kind of destination.' );
	}

	public function test_a_submission_with_nowhere_to_go_is_an_error_for_brand_relay() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'form_data', 'post_status' => 'publish' ) );
		update_post_meta( $post_id, 'form_name', 'Contact' );
		update_post_meta( $post_id, 'content', wp_json_encode( array( 'name' => 'Jane' ) ) );
		add_filter( 'acf/load_value/name=destinations', '__return_empty_array' );

		devbase_forms_dispatch( $post_id );

		remove_filter( 'acf/load_value/name=destinations', '__return_empty_array' );
		$queue = $this->queue();
		$this->assertCount( 1, $queue );
		$this->assertSame( 'form', $queue[0]['type'] );
		$this->assertSame( 'Form delivery failed: Contact (no destination)', $queue[0]['message'] );
		$this->assertStringNotContainsString( 'Jane', wp_json_encode( $queue ), 'Nothing the visitor typed is sent.' );
	}

	public function test_the_same_place_in_one_request_is_one_event_with_a_count() {
		foreach ( range( 1, 500 ) as $i ) {
			$this->warn( E_WARNING, "Undefined array key $i" );
		}

		devbase_events_flush();

		$this->assertCount( 1, $this->queue() );
		$this->assertSame( 500, $this->queue()[0]['count'] );
	}

	public function test_one_request_notes_a_limited_number_of_places() {
		foreach ( range( 1, 60 ) as $i ) {
			$this->warn( E_WARNING, 'Notice here', '/var/www/wp-content/plugins/p/f.php', $i );
		}

		devbase_events_flush();

		$this->assertCount( DEVBASE_EVENTS_REQUEST_MAX, $this->queue() );
	}

	public function test_an_error_silenced_with_the_at_operator_is_left_alone() {
		$old = error_reporting( 4437 ); // What PHP 8 leaves for a statement written with @.
		$this->warn( E_WARNING, 'file_get_contents failed' );
		error_reporting( $old );

		$this->assertFalse( devbase_events_flush() );
		$this->assertCount( 0, $this->queue() );
	}

	public function test_the_handler_changes_nothing_for_the_site() {
		$this->assertFalse( $this->warn( E_WARNING, 'x' ), 'No earlier handler: false, so PHP carries on as it always did.' );

		devbase_events_state( 'previous', function ( $no, $str ) {
			return "handled: $str";
		} );
		$this->assertSame( 'handled: y', $this->warn( E_NOTICE, 'y' ), 'An earlier handler is called and its answer returned.' );

		devbase_events_state( 'previous', function () {
			return true;
		} );
		$this->assertTrue( $this->warn( E_DEPRECATED, 'z' ) );
	}

	public function test_nothing_is_queued_when_the_plan_lacks_error_tracking() {
		$this->warn( E_WARNING, 'x' );
		$this->plan( array( 'health' ) );

		$this->assertFalse( devbase_events_flush() );
		$this->assertCount( 0, $this->queue() );
	}

	public function test_the_handler_is_only_installed_while_the_plan_has_error_tracking() {
		$this->plan( array( 'health' ) );
		$this->assertFalse( devbase_events_install(), 'A plan without it costs nothing: no handler.' );

		$this->plan( array( 'events' ) );
		$this->assertTrue( devbase_events_install() );
		$this->assertTrue( devbase_events_install(), 'Twice is the same as once.' );

		// The handler must not have become its own "earlier handler": that would call itself without end.
		$this->assertNotSame( 'devbase_events_handler', devbase_events_state( 'previous' ) );

		// The earlier handler here is the test suite's own, which raises a warning as an exception: reaching it once,
		// and getting its answer back, is the chain working (a loop would run out of memory instead).
		try {
			$this->warn( E_WARNING, 'reached the earlier handler' );
			$this->fail( 'The earlier handler was not called.' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'reached the earlier handler', $e->getMessage() );
		}

		restore_error_handler();
		restore_error_handler();
	}

	public function test_an_error_queued_a_moment_ago_does_not_write_the_database_again() {
		$this->warn( E_WARNING, 'Undefined array key "a"' );
		devbase_events_flush();
		$first = get_option( DEVBASE_EVENTS_QUEUE );

		$this->warn( E_WARNING, 'Undefined array key "a"' );
		devbase_events_flush();

		$this->assertSame( $first, get_option( DEVBASE_EVENTS_QUEUE ), 'Within a minute: left as it is.' );

		$queue = $first;
		foreach ( $queue as &$row ) {
			$row['at'] = time() - 3600;
		}
		unset( $row );
		update_option( DEVBASE_EVENTS_QUEUE, $queue, false );

		$this->warn( E_WARNING, 'Undefined array key "a"' );
		devbase_events_flush();

		$this->assertSame( 2, $this->queue()[0]['count'], 'An hour later it counts up.' );
	}

	public function test_a_full_queue_drops_notices_before_a_fatal() {
		$this->crash( 'The one fatal', '/a/wp-content/plugins/p/fatal.php', 1 );

		foreach ( range( 1, DEVBASE_EVENTS_QUEUE_MAX + 30 ) as $i ) {
			$this->warn( E_NOTICE, 'Noise', '/var/www/wp-content/plugins/p/n.php', $i );
			devbase_events_flush();
		}

		$queue = $this->queue();
		$this->assertCount( DEVBASE_EVENTS_QUEUE_MAX, $queue );
		$this->assertContains( 'fatal', array_column( $queue, 'level' ), 'The fatal is still there.' );
	}

	public function test_a_fatal_is_sent_first_whatever_the_queue_holds() {
		foreach ( range( 1, 60 ) as $i ) {
			$this->warn( E_NOTICE, 'Noise', '/var/www/wp-content/plugins/p/n.php', $i );

			if ( 0 === $i % 20 ) {
				devbase_events_flush(); // Three requests' worth.
			}
		}
		$this->crash( 'Late fatal', '/a/wp-content/plugins/p/late.php', 99 );

		devbase_events_run();

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertCount( DEVBASE_EVENTS_BATCH_MAX, $body['events'] );
		$this->assertSame( 'fatal', $body['events'][0]['level'], 'The most severe goes first, though it came last.' );
	}

	public function test_an_event_is_sent_with_the_time_it_happened_as_an_iso_string() {
		$this->crash();
		$queue = $this->queue();
		$then  = time() - 7200;
		foreach ( get_option( DEVBASE_EVENTS_QUEUE ) as $key => $row ) {
			update_option( DEVBASE_EVENTS_QUEUE, array( $key => array_merge( $row, array( 'at' => $then ) ) ), false );
		}

		devbase_events_run();

		$sent = json_decode( $this->requests[0]['args']['body'], true )['events'][0]['at'];
		$this->assertSame( gmdate( 'c', $then ), $sent, 'Brand Relay reads only a string; a number would date it by when it arrived.' );
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
