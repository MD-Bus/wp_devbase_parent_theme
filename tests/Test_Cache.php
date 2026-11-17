<?php
// Covers inc/theme-settings/cache/: off means nothing at all happens; what clears every cache (settings saves, menus,
// updates, a new credit) and what clears Cloudflare only (posts, comments); one clear per request; the switch to a
// whole-zone clear past the URL limit and the 100-URL calls; a Cloudflare failure recorded and retried, never
// breaking the save; the page headers; the Cloudflare cache rule (created, updated in place, removed, other rules
// untouched); the connection test; the Twig cache folder; the token as a secret. No request leaves: pre_http_request
// answers for Cloudflare.

class Test_Cache extends WP_UnitTestCase {

	/** @var array[] The requests that would have gone to Cloudflare. */
	private $requests = array();

	/** @var callable|null Answers a request: fn( method, path, body ) => [ status, json ]. */
	private $router;

	/** @var int How many times the fake host was cleared. */
	private $host_purges = 0;

	/** @var string */
	private $twig_dir;

	/** @var string */
	private $request_uri;

	public function set_up() {
		parent::set_up();

		$this->requests    = array();
		$this->host_purges = 0;
		$this->router      = null;
		$this->request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$this->twig_dir    = get_temp_dir() . 'devbase-twig-test-' . wp_generate_password( 6, false );

		add_filter( 'pre_http_request', array( $this, 'fake_cloudflare' ), 10, 3 );
		add_filter( 'devbase/cache/hosts', array( $this, 'fake_host' ) );
		add_filter( 'devbase/cache/twig_dir', array( $this, 'twig_dir' ) );

		devbase_cache_reset_queue();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_cloudflare' ), 10 );
		remove_filter( 'devbase/cache/hosts', array( $this, 'fake_host' ) );
		remove_filter( 'devbase/cache/twig_dir', array( $this, 'twig_dir' ) );
		remove_all_filters( 'devbase/cache/max_urls' );

		foreach ( array( 'cache_enabled', 'cache_triggers', 'cache_twig', 'cache_exclusions', 'cache_host_purge', 'cache_cf_enabled', 'cache_cf_zone_id', 'cache_cf_api_token', 'cache_cf_post_purge', 'cache_cf_page_cache', 'cache_cf_lifetime' ) as $name ) {
			delete_field( $name, 'option' );
		}

		delete_option( DEVBASE_CACHE_STATUS_OPTION );
		wp_clear_scheduled_hook( DEVBASE_CACHE_RETRY_HOOK );
		remove_action( 'shutdown', 'devbase_cache_flush_at_shutdown', 5 );
		devbase_cache_reset_queue();
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		unset( $_SERVER['REQUEST_METHOD'] );
		$_COOKIE = array();

		if ( is_dir( $this->twig_dir ) ) {
			devbase_cache_twig_clear();
			@unlink( $this->twig_dir . '/.htaccess' );
			@unlink( $this->twig_dir . '/index.php' );
			@rmdir( $this->twig_dir );
		}

		parent::tear_down();
	}

	public function twig_dir() {
		return $this->twig_dir;
	}

	public function fake_host( $hosts ) {
		$hosts['fake'] = array(
			'label'  => 'Fake host',
			'kind'   => 'host',
			'detect' => '__return_true',
			'purge'  => function () {
				$this->host_purges++;
			},
		);

		// Nothing real is cleared from a test (and a LiteSpeed check must not depend on the machine running it).
		unset( $hosts['litespeed_server'] );

		return $hosts;
	}

	public function fake_cloudflare( $pre, $args, $url ) {
		if ( 0 !== strpos( (string) $url, DEVBASE_CACHE_CF_API ) ) {
			return $pre;
		}

		$path = (string) preg_replace( '#^' . preg_quote( DEVBASE_CACHE_CF_API, '#' ) . '/zones/[a-f0-9]{32}#', '', (string) $url );
		$body = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;

		$this->requests[] = array( 'method' => $args['method'], 'path' => $path, 'body' => $body, 'auth' => $args['headers']['Authorization'] ?? '' );

		list( $status, $json ) = $this->router ? call_user_func( $this->router, $args['method'], $path, $body ) : array( 200, array( 'success' => true, 'result' => array( 'id' => 'x' ) ) );

		return array( 'response' => array( 'code' => $status, 'message' => '' ), 'body' => wp_json_encode( $json ), 'headers' => array(), 'cookies' => array() );
	}

	private function enable( $cloudflare = false ) {
		update_field( 'cache_enabled', 1, 'option' );

		if ( $cloudflare ) {
			update_field( 'cache_cf_enabled', 1, 'option' );
			update_field( 'cache_cf_zone_id', str_repeat( 'a1', 16 ), 'option' );
			update_field( 'cache_cf_api_token', 'cf-token', 'option' );
		}
	}

	private function purges() {
		return array_values( array_filter( $this->requests, function ( $r ) {
			return '/purge_cache' === $r['path'];
		} ) );
	}

	/* ---------------------------------------------------------------------------------------------------------- */

	public function test_off_by_default_nothing_happens() {
		$this->assertFalse( devbase_cache_enabled() );
		$this->assertFalse( devbase_cache_request_all( 'settings' ) );

		do_action( 'acf/save_post', 'options' );
		do_action( 'wp_update_nav_menu', 1 );
		do_action( 'devbase/credit/changed', array() );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		devbase_cache_flush();

		$this->assertSame( 0, $this->host_purges );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'none', devbase_cache_page_decision( array() ) );
		$this->assertSame( '', devbase_cache_twig_dir() );
		$this->assertFalse( is_dir( $this->twig_dir ) );
		$this->assertArrayNotHasKey( 'cache', devbase_heartbeat_report() );
		$this->assertSame( array(), devbase_cache_get_status() );
	}

	public function test_off_also_ignores_cloudflare_settings_left_behind() {
		$this->enable( true );
		update_field( 'cache_enabled', 0, 'option' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		devbase_cache_flush();

		$this->assertFalse( devbase_cache_cf_active() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_settings_save_clears_host_and_cloudflare_once() {
		$this->enable( true );

		do_action( 'acf/save_post', 'options' );
		do_action( 'acf/save_post', 'options' );
		do_action( 'wp_update_nav_menu', 1 );

		$this->assertSame( 1, $this->host_purges, 'host cleared once per request, at the time of the change' );
		$this->assertSame( array(), $this->requests, 'Cloudflare waits for the end of the request' );

		$results = devbase_cache_flush();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( array( 'purge_everything' => true ), $this->requests[0]['body'] );
		$this->assertSame( 'Bearer cf-token', $this->requests[0]['auth'] );
		$this->assertTrue( $results['fake']['ok'] );
		$this->assertTrue( $results['cloudflare']['ok'] );

		$status = devbase_cache_get_status();
		$this->assertSame( array( 'settings', 'menus' ), $status['last']['reasons'] );
		$this->assertArrayNotHasKey( 'error', $status );
	}

	public function test_post_save_and_other_stores_do_not_count_as_settings() {
		$this->enable();

		do_action( 'acf/save_post', 123 );
		do_action( 'acf/save_post', 'term_4' );

		$this->assertSame( 0, $this->host_purges );
	}

	public function test_unticked_trigger_does_nothing() {
		$this->enable();
		update_field( 'cache_triggers', array( 'updates' ), 'option' );

		do_action( 'acf/save_post', 'options' );
		do_action( 'devbase/credit/changed', array() );
		$this->assertSame( 0, $this->host_purges );

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		do_action( 'upgrader_process_complete', null, array( 'action' => 'update', 'type' => 'theme' ) );
		$this->assertSame( 1, $this->host_purges );
	}

	public function test_host_switch_off_leaves_host_alone() {
		$this->enable();
		update_field( 'cache_host_purge', 0, 'option' );

		devbase_cache_request_all( 'settings' );

		$this->assertSame( 0, $this->host_purges );
	}

	public function test_credit_change_fires_and_clears() {
		$this->enable();

		do_action( 'devbase/credit/changed', array() );

		$this->assertSame( 1, $this->host_purges );
	}

	public function test_widgets_change_clears() {
		$this->enable();

		update_option( 'widget_text', array( 2 => array( 'text' => 'new ' . wp_rand() ) ) );

		$this->assertSame( 1, $this->host_purges );
	}

	public function test_published_post_clears_its_pages_on_cloudflare_only() {
		$this->enable( true );
		$cat  = self::factory()->category->create( array( 'slug' => 'news' ) );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $cat ) ) );

		devbase_cache_flush();

		$this->assertSame( 0, $this->host_purges, 'hosts clear their own post pages' );
		$purges = $this->purges();
		$this->assertCount( 1, $purges );

		$files = $purges[0]['body']['files'];
		$this->assertContains( get_permalink( $post ), $files );
		$this->assertContains( home_url( '/' ), $files );
		$this->assertContains( get_term_link( $cat ), $files );
		$this->assertContains( get_feed_link(), $files );
	}

	public function test_draft_changes_nothing_and_unpublish_does() {
		$this->enable( true );

		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Still a draft' ) );
		devbase_cache_flush();
		$this->assertSame( array(), $this->requests );

		wp_update_post( array( 'ID' => $post, 'post_status' => 'publish' ) );
		devbase_cache_flush();
		$this->assertCount( 1, $this->purges() );

		wp_trash_post( $post );
		devbase_cache_flush();
		$this->assertCount( 2, $this->purges() );
	}

	public function test_slug_change_also_clears_the_old_address() {
		$this->enable( true );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'old-name' ) );
		$old  = get_permalink( $post );
		devbase_cache_flush();

		wp_update_post( array( 'ID' => $post, 'post_name' => 'new-name' ) );
		devbase_cache_flush();

		$files = $this->purges()[1]['body']['files'];
		$this->assertContains( $old, $files );
		$this->assertContains( get_permalink( $post ), $files );
	}

	public function test_post_mode_everything_and_none() {
		$this->enable( true );

		update_field( 'cache_cf_post_purge', 'everything', 'option' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		devbase_cache_flush();
		$this->assertSame( array( 'purge_everything' => true ), $this->purges()[0]['body'] );
		$this->assertSame( 0, $this->host_purges );

		update_field( 'cache_cf_post_purge', 'none', 'option' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		devbase_cache_flush();
		$this->assertCount( 1, $this->purges() );
	}

	public function test_approved_comment_clears_its_post() {
		$this->enable( true );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		devbase_cache_flush();

		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => 0 ) );
		devbase_cache_flush();
		$this->assertCount( 1, $this->purges(), 'a held comment shows nowhere' );

		wp_set_comment_status( $comment, 'approve' );
		devbase_cache_flush();
		$this->assertCount( 2, $this->purges() );
		$this->assertContains( get_permalink( $post ), $this->purges()[1]['body']['files'] );
	}

	public function test_many_urls_in_one_request_clear_the_whole_zone() {
		$this->enable( true );
		add_filter( 'devbase/cache/max_urls', function () {
			return 3;
		} );

		devbase_cache_request_urls( array( 'https://a.test/1', 'https://a.test/2', 'https://a.test/3', 'https://a.test/4' ), 'post' );
		devbase_cache_flush();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( array( 'purge_everything' => true ), $this->requests[0]['body'] );
	}

	public function test_urls_go_in_calls_of_100() {
		$this->enable( true );
		$urls = array();

		for ( $i = 0; $i < 150; $i++ ) {
			$urls[] = 'https://a.test/p' . $i;
		}

		$this->assertTrue( devbase_cache_cf_purge_urls( $urls )['ok'] );
		$this->assertCount( 2, $this->requests );
		$this->assertCount( 100, $this->requests[0]['body']['files'] );
		$this->assertCount( 50, $this->requests[1]['body']['files'] );
	}

	public function test_cloudflare_failure_is_recorded_and_retried() {
		$this->enable( true );
		$this->router = function () {
			return array( 403, array( 'success' => false, 'errors' => array( array( 'code' => 10000, 'message' => 'Authentication error' ) ) ) );
		};

		devbase_cache_request_all( 'settings' );
		$results = devbase_cache_flush();

		$this->assertFalse( $results['cloudflare']['ok'] );
		$this->assertTrue( $results['fake']['ok'], 'the host was still cleared' );

		$status = devbase_cache_get_status();
		$this->assertStringContainsString( 'Authentication error (10000)', $status['error']['message'] );
		$this->assertNotFalse( wp_next_scheduled( DEVBASE_CACHE_RETRY_HOOK ) );

		// The retry clears the whole zone.
		$this->router = null;
		do_action( DEVBASE_CACHE_RETRY_HOOK );
		$this->assertSame( array( 'purge_everything' => true ), end( $this->requests )['body'] );
		$this->assertSame( array( 'retry' ), devbase_cache_get_status()['last']['reasons'] );
	}

	public function test_network_error_is_not_fatal() {
		$this->enable( true );
		$this->router = null;
		remove_filter( 'pre_http_request', array( $this, 'fake_cloudflare' ), 10 );
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			return 0 === strpos( (string) $url, DEVBASE_CACHE_CF_API ) ? new WP_Error( 'http_request_failed', 'Operation timed out' ) : $pre;
		}, 10, 3 );

		devbase_cache_request_all( 'settings' );
		$results = devbase_cache_flush();

		$this->assertFalse( $results['cloudflare']['ok'] );
		$this->assertSame( 'Operation timed out', $results['cloudflare']['message'] );
	}

	public function test_failing_host_is_reported_not_thrown() {
		$this->enable();
		add_filter( 'devbase/cache/hosts', function ( $hosts ) {
			$hosts['broken'] = array(
				'label'  => 'Broken',
				'detect' => '__return_true',
				'purge'  => function () {
					throw new RuntimeException( 'nope' );
				},
			);

			return $hosts;
		}, 20 );

		devbase_cache_request_all( 'settings' );
		$results = devbase_cache_flush();

		$this->assertFalse( $results['broken']['ok'] );
		$this->assertSame( 'nope', $results['broken']['message'] );
		$this->assertStringContainsString( 'Broken: nope', devbase_cache_get_status()['error']['message'] );
		remove_all_filters( 'devbase/cache/hosts', 20 );
	}

	public function test_clear_all_now_includes_object_cache() {
		$this->enable( true );

		$results = devbase_cache_clear_all_now();

		$this->assertArrayHasKey( 'object', $results );
		$this->assertArrayHasKey( 'twig', $results );
		$this->assertTrue( $results['cloudflare']['ok'] );
		$this->assertSame( 1, $this->host_purges );
	}

	/* ---------------------------------------------------------------------------------------------------------- */

	private function request( $path ) {
		$_SERVER['REQUEST_URI']    = $path;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->go_to( home_url( $path ) );
	}

	public function test_excluded_paths() {
		$this->enable();
		update_field( 'cache_exclusions', array(
			array( 'path' => '/members/', 'match' => 'prefix' ),
			array( 'path' => 'https://example.org/quote', 'match' => 'exact' ),
		), 'option' );

		$this->assertTrue( devbase_cache_path_excluded( '/members' ) );
		$this->assertTrue( devbase_cache_path_excluded( '/members/area/' ) );
		$this->assertTrue( devbase_cache_path_excluded( '/Members/' ) );
		$this->assertFalse( devbase_cache_path_excluded( '/membership/' ) );
		$this->assertTrue( devbase_cache_path_excluded( '/quote/' ) );
		$this->assertFalse( devbase_cache_path_excluded( '/quote/sent/' ) );

		$this->request( '/members/area/' );
		$this->assertSame( 'exclude', devbase_cache_page_decision( array() ) );
	}

	public function test_page_headers_only_with_cloudflare_page_caching() {
		$this->enable( true );
		$this->request( '/' );

		$this->assertSame( 'none', devbase_cache_page_decision( array() ), 'Cloudflare on, page caching off: the page is left as it was' );

		update_field( 'cache_cf_page_cache', 1, 'option' );
		$this->assertSame( 'cache', devbase_cache_page_decision( array() ) );
		$this->assertSame( DEVBASE_CACHE_DEFAULT_LIFETIME, devbase_cache_cf_lifetime() );

		update_field( 'cache_cf_lifetime', '86400', 'option' );
		$this->assertSame( 86400, devbase_cache_cf_lifetime() );

		$this->assertSame( 'none', devbase_cache_page_decision( array( 'Cache-Control: no-store' ) ), 'someone else decided' );
	}

	public function test_private_visitors_are_never_cached() {
		$this->enable( true );
		update_field( 'cache_cf_page_cache', 1, 'option' );
		$this->request( '/' );

		$_COOKIE = array( 'woocommerce_items_in_cart' => '1' );
		$this->assertSame( 'private', devbase_cache_page_decision( array() ) );

		$_COOKIE = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'private', devbase_cache_page_decision( array() ) );
		wp_set_current_user( 0 );
	}

	public function test_post_requests_and_searches_are_left_alone() {
		$this->enable( true );
		update_field( 'cache_cf_page_cache', 1, 'option' );

		$this->request( '/?s=hello' );
		$this->assertSame( 'none', devbase_cache_page_decision( array() ) );

		$this->request( '/' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( 'none', devbase_cache_page_decision( array() ) );
	}

	/* ---------------------------------------------------------------------------------------------------------- */

	public function test_rule_definition_only_caches_what_the_site_marks() {
		$rule = devbase_cache_cf_rule_definition();

		$this->assertSame( DEVBASE_CACHE_CF_RULE_REF, $rule['ref'] );
		$this->assertSame( 'set_cache_settings', $rule['action'] );
		$this->assertSame( 'bypass_by_default', $rule['action_parameters']['edge_ttl']['mode'] );
		$this->assertStringContainsString( 'http.host eq "' . wp_parse_url( home_url(), PHP_URL_HOST ) . '"', $rule['expression'] );
		$this->assertStringContainsString( 'wordpress_logged_in_', $rule['expression'] );
		$this->assertStringContainsString( '/wp-admin', $rule['expression'] );
	}

	public function test_rule_created_when_the_zone_has_no_cache_rules() {
		$this->enable( true );
		$created = false;
		$this->router = function ( $method, $path ) use ( &$created ) {
			if ( 'GET' === $method && false !== strpos( $path, 'entrypoint' ) ) {
				return $created
					? array( 200, array( 'success' => true, 'result' => array( 'id' => 'rs1', 'rules' => array( array( 'id' => 'r1', 'ref' => DEVBASE_CACHE_CF_RULE_REF ) ) ) ) )
					: array( 404, array( 'success' => false, 'errors' => array( array( 'code' => 10003, 'message' => 'not found' ) ) ) );
			}

			$created = true;

			return array( 200, array( 'success' => true, 'result' => array( 'id' => 'rs1' ) ) );
		};

		$result = devbase_cache_cf_rule_install();

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'PUT', $this->requests[1]['method'] );
		$this->assertSame( '/rulesets/phases/http_request_cache_settings/entrypoint', $this->requests[1]['path'] );
		$this->assertSame( DEVBASE_CACHE_CF_RULE_REF, $this->requests[1]['body']['rules'][0]['ref'] );
		$this->assertSame( 'r1', devbase_cache_get_status()['rule']['id'] );
	}

	public function test_rule_added_beside_other_rules_then_updated_in_place() {
		$this->enable( true );
		$ours = false;
		$this->router = function ( $method, $path ) use ( &$ours ) {
			if ( 'GET' === $method ) {
				$rules = array( array( 'id' => 'theirs', 'ref' => 'someone_else' ) );

				if ( $ours ) {
					$rules[] = array( 'id' => 'r9', 'ref' => DEVBASE_CACHE_CF_RULE_REF );
				}

				return array( 200, array( 'success' => true, 'result' => array( 'id' => 'rs1', 'rules' => $rules ) ) );
			}

			$ours = true;

			return array( 200, array( 'success' => true, 'result' => array() ) );
		};

		$this->assertTrue( devbase_cache_cf_rule_install()['ok'] );
		$this->assertSame( 'POST', $this->requests[1]['method'] );
		$this->assertSame( '/rulesets/rs1/rules', $this->requests[1]['path'] );

		$this->requests = array();
		$this->assertSame( 'Cache rule updated.', devbase_cache_cf_rule_install()['message'] );
		$this->assertSame( 'PATCH', $this->requests[1]['method'] );
		$this->assertSame( '/rulesets/rs1/rules/r9', $this->requests[1]['path'] );

		$this->requests = array();
		$this->assertTrue( devbase_cache_cf_rule_remove()['ok'] );
		$this->assertSame( 'DELETE', $this->requests[1]['method'] );
		$this->assertSame( '/rulesets/rs1/rules/r9', $this->requests[1]['path'] );
		$this->assertCount( 2, $this->requests, 'the other rule is never touched' );
		$this->assertSame( array(), devbase_cache_get_status()['rule'] );
	}

	public function test_connection_test_checks_the_zone_is_this_site() {
		$this->enable( true );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$this->router = function ( $method, $path ) {
			return '' === $path
				? array( 200, array( 'success' => true, 'result' => array( 'name' => 'another-site.com' ) ) )
				: array( 200, array( 'success' => true, 'result' => array() ) );
		};
		$result = devbase_cache_cf_test();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'another-site.com', $result['message'] );

		$this->router = function ( $method, $path ) use ( $host ) {
			return '' === $path
				? array( 200, array( 'success' => true, 'result' => array( 'name' => $host ) ) )
				: array( 200, array( 'success' => true, 'result' => array() ) );
		};
		$this->assertTrue( devbase_cache_cf_test()['ok'] );

		$this->assertTrue( devbase_cache_cf_host_in_zone( 'www.example.com', 'example.com' ) );
		$this->assertFalse( devbase_cache_cf_host_in_zone( 'badexample.com', 'example.com' ) );
	}

	public function test_missing_credentials_send_nothing() {
		$this->enable();
		update_field( 'cache_cf_enabled', 1, 'option' );
		update_field( 'cache_cf_zone_id', 'not-a-zone', 'option' );
		update_field( 'cache_cf_api_token', 'tok', 'option' );

		$this->assertFalse( devbase_cache_cf_active() );
		$this->assertFalse( devbase_cache_cf_request( 'GET', '' )['ok'] );
		$this->assertSame( array(), $this->requests );
	}

	/* ---------------------------------------------------------------------------------------------------------- */

	public function test_token_is_a_secret_and_not_autoloaded() {
		$this->assertContains( 'field_devbase_cache_cf_api_token', devbase_settings_secret_keys() );

		$kept = devbase_settings_filter_submission( array( 'field_devbase_cache_cf_api_token' => '', 'field_devbase_cache_cf_zone_id' => 'x' ) );
		$this->assertArrayNotHasKey( 'field_devbase_cache_cf_api_token', $kept, 'an empty submission keeps the stored token' );

		$fields = devbase_settings_autoload_fields();
		$this->assertContains( 'cache_enabled', $fields );
		$this->assertContains( 'cache_exclusions', $fields );
		$this->assertNotContains( 'cache_cf_api_token', $fields );
	}

	public function test_twig_cache_folder() {
		$this->enable();

		$dir = devbase_cache_twig_dir();
		$this->assertSame( $this->twig_dir, $dir );
		$this->assertFileExists( $dir . '/.htaccess' );

		$options = apply_filters( 'timber/twig/environment/options', array( 'cache' => false ) );
		$this->assertSame( $dir, $options['cache'] );
		$this->assertTrue( $options['auto_reload'] );

		mkdir( $dir . '/ab' );
		file_put_contents( $dir . '/ab/compiled.php', '<?php' );
		$this->assertTrue( devbase_cache_twig_clear() );
		$this->assertFileDoesNotExist( $dir . '/ab/compiled.php' );
		$this->assertFileExists( $dir . '/index.php' );

		update_field( 'cache_twig', 0, 'option' );
		$this->assertFalse( apply_filters( 'timber/twig/environment/options', array( 'cache' => false ) )['cache'] );
	}

	public function test_health_report_names_caches_while_on() {
		$this->enable( true );

		$report = devbase_heartbeat_report();

		$this->assertContains( 'fake', $report['cache']['hosts'] );
		$this->assertTrue( $report['cache']['cloudflare'] );
		$this->assertFalse( $report['cache']['page_cache'] );
	}

	public function test_page_shows_in_the_settings_app() {
		$sections = devbase_app_all_sections();

		$this->assertArrayHasKey( 'cache-settings', $sections );
		$this->assertSame( 'settings', $sections['cache-settings']['group'] );
		$this->assertSame( array( 'group_devbase_cache' ), $sections['cache-settings']['groups'] );
	}

	public function test_status_and_warnings_render() {
		$this->enable( true );
		update_field( 'cache_cf_page_cache', 1, 'option' );
		devbase_cache_request_all( 'settings' );
		devbase_cache_flush();

		$html = devbase_cache_status_html();

		$this->assertStringContainsString( 'Fake host', $html );
		$this->assertStringContainsString( 'On, pages cached', $html );
		$this->assertStringContainsString( 'settings', $html );
	}
}
