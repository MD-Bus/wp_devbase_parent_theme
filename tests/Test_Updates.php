<?php
// Covers inc/maintenance/updates.php: remote updates, step 1 (the inventory). What is sent
// (core and every plugin with the update WordPress offers, bundled plugins flagged), when
// WordPress's own update check is refreshed first, and the ping that runs it only while the
// plan has the `updates` service. Brand Relay and api.wordpress.org are faked with pre_http_request.

class Test_Updates extends WP_UnitTestCase {

	private $filter   = null;
	private $requests = array();
	private $answer   = array( 200, array( 'v' => 1, 'ok' => true, 'plugins' => 0, 'dropped' => 0, 'updates' => 0, 'new_updates' => 0, 'apply' => array() ) );
	private $licence  = null;
	private $refresh  = null;
	private $upgrade  = null;
	private $answers  = array();

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
		foreach ( array( DEVBASE_MAINTENANCE_STATE, DEVBASE_MAINTENANCE_LOCK, DEVBASE_RELAY_PLAN_OPTION, DEVBASE_RELAY_STATE_OPTION ) as $option ) {
			delete_option( $option );
		}
		$this->plan( array( 'updates' ) );

		// Two installed plugins (this install's plugins folder is empty by design), through get_plugins()'s own cache.
		devbase_updates_load_admin();
		// Every header get_plugins() fills, as it does for a real plugin.
		$headers = array_fill_keys( array( 'Name', 'PluginURI', 'Version', 'Description', 'Author', 'AuthorURI', 'TextDomain', 'DomainPath', 'Network', 'RequiresWP', 'RequiresPHP', 'UpdateURI', 'RequiresPlugins', 'Title', 'AuthorName' ), '' );
		wp_cache_set( 'plugins', array( '' => array(
			'shop/shop.php' => array_merge( $headers, array( 'Name' => 'Shop <em>Pro</em>', 'Version' => '2.0' ) ),
			'hello.php'     => array_merge( $headers, array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ) ),
		) ), 'plugins' );

		// A fresh update check by default: nothing is asked of api.wordpress.org.
		$this->offers( array(), null );
		$this->refresh = function () {
			return false;
		};
		add_filter( 'devbase/updates/refresh', $this->refresh );

		$this->filter = function ( $preempt, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'args' => $args );

			if ( substr( $url, -strlen( '/v1/site/updates' ) ) === '/v1/site/updates' ) {
				if ( $this->answers ) {
					$next = array_shift( $this->answers );

					return array( 'response' => array( 'code' => $next[0] ), 'body' => wp_json_encode( $next[1] ), 'headers' => array() );
				}
				if ( is_wp_error( $this->answer ) ) {
					return $this->answer;
				}

				return array( 'response' => array( 'code' => $this->answer[0] ), 'body' => wp_json_encode( $this->answer[1] ), 'headers' => array() );
			}

			if ( substr( $url, -strlen( '/v1/site' ) ) === '/v1/site' ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'v' => 1, 'params' => array(), 'licence' => $this->licence ) ), 'headers' => array() );
			}

			// api.wordpress.org: an empty answer is enough; the transients are set by each test.
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
		devbase_tests_rebuild_rest_server();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', $this->filter, 10 );
		remove_filter( 'devbase/updates/refresh', $this->refresh );
		wp_cache_delete( 'plugins', 'plugins' );
		update_option( 'active_plugins', array() );
		delete_option( DEVBASE_UPDATES_RESULTS );
		devbase_updates_handed( array() );
		remove_action( 'shutdown', 'devbase_updates_after_answer', 1000 );
		if ( $this->upgrade ) {
			remove_filter( 'devbase/updates/upgrade', $this->upgrade );
		}
		if ( class_exists( 'WP_Upgrader' ) ) {
			WP_Upgrader::release_lock( 'auto_updater' );
		}
		remove_all_filters( 'devbase/updates/installed_version' );
		remove_all_filters( 'automatic_updater_disabled' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_core' );

		foreach ( array( DEVBASE_MAINTENANCE_STATE, DEVBASE_MAINTENANCE_LOCK, DEVBASE_RELAY_PLAN_OPTION, DEVBASE_RELAY_STATE_OPTION ) as $option ) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	/** The saved plan, and what /v1/site answers, list these services as in force. */
	private function plan( $services ) {
		$this->licence = null === $services ? null : array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => $services, 'limits' => array() );
		devbase_relay_store_plan( array( 'licence' => $this->licence ) );
	}

	/**
	 * What WordPress's own update checks say: plugin file => offered version, and the core version offered (or null).
	 *
	 * @param array<string,string> $plugins
	 */
	private function offers( array $plugins, $core, $checked = null ) {
		$checked = null === $checked ? time() : $checked;
		$response = array();
		foreach ( $plugins as $file => $version ) {
			$response[ $file ] = (object) array( 'plugin' => $file, 'new_version' => $version, 'package' => 'https://downloads.wordpress.org/x.zip' );
		}
		set_site_transient( 'update_plugins', (object) array( 'last_checked' => $checked, 'response' => $response, 'checked' => array() ) );

		$updates = array();
		if ( null !== $core ) {
			$updates[] = (object) array( 'response' => 'upgrade', 'current' => $core, 'version' => $core );
		}
		$updates[] = (object) array( 'response' => 'latest', 'current' => get_bloginfo( 'version' ), 'version' => get_bloginfo( 'version' ) );
		set_site_transient( 'update_core', (object) array( 'last_checked' => $checked, 'updates' => $updates, 'version_checked' => get_bloginfo( 'version' ) ) );
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

	private function sent() {
		$sent = array_values( array_filter( $this->requests, function ( $r ) {
			return substr( $r['url'], -strlen( '/v1/site/updates' ) ) === '/v1/site/updates';
		} ) );

		return $sent ? json_decode( $sent[0]['args']['body'], true ) : null;
	}

	private function plugin( array $inventory, $file ) {
		foreach ( $inventory['plugins'] as $plugin ) {
			if ( $plugin['file'] === $file ) {
				return $plugin;
			}
		}

		return null;
	}

	// --- The inventory -----------------------------------------------------------

	public function test_the_inventory_lists_core_and_every_installed_plugin_with_what_brand_relay_reads() {
		devbase_updates_load_admin();
		$installed = get_plugins();
		$this->assertCount( 2, $installed );

		$inventory = devbase_updates_inventory();

		$this->assertSame( array( 'version' => get_bloginfo( 'version' ) ), $inventory['core'], 'No core update offered: none sent.' );
		$this->assertCount( count( $installed ), $inventory['plugins'] );
		foreach ( $inventory['plugins'] as $plugin ) {
			$this->assertSame( array( 'file', 'name', 'version', 'active', 'bundled' ), array_keys( $plugin ), 'No update key without an offer.' );
			$this->assertArrayHasKey( $plugin['file'], $installed );
			$this->assertSame( $installed[ $plugin['file'] ]['Version'], $plugin['version'] );
			$this->assertIsBool( $plugin['active'] );
		}
		$this->assertSame( 'Shop Pro', $this->plugin( $inventory, 'shop/shop.php' )['name'], 'No markup in a name.' );
	}

	public function test_the_updates_wordpress_offers_are_sent_and_older_or_equal_offers_are_not() {
		devbase_updates_load_admin();
		$files   = array_keys( get_plugins() );
		$first   = $files[0];
		$current = get_plugins()[ $first ]['Version'];
		$this->offers( array( $first => '999.0.0', 'not-installed/x.php' => '2.0' ), '999.1' );

		$inventory = devbase_updates_inventory();

		$this->assertSame( array( 'version' => '999.0.0' ), $this->plugin( $inventory, $first )['update'] );
		$this->assertNull( $this->plugin( $inventory, 'not-installed/x.php' ), 'Only installed plugins are listed.' );
		$this->assertSame( array( 'version' => '999.1' ), $inventory['core']['update'] );

		$this->offers( array( $first => $current ), get_bloginfo( 'version' ) );
		$inventory = devbase_updates_inventory();
		$this->assertArrayNotHasKey( 'update', $this->plugin( $inventory, $first ), 'The same version is no update.' );
		$this->assertArrayNotHasKey( 'update', $inventory['core'] );
	}

	public function test_an_active_plugin_says_so() {
		devbase_updates_load_admin();
		$file = array_keys( get_plugins() )[0];
		update_option( 'active_plugins', array( $file ) );

		$this->assertTrue( $this->plugin( devbase_updates_inventory(), $file )['active'] );

		update_option( 'active_plugins', array() );
		$this->assertFalse( $this->plugin( devbase_updates_inventory(), $file )['active'] );
	}

	public function test_a_plugin_the_theme_bundles_is_flagged_as_bundled() {
		devbase_updates_load_admin();
		$file  = array_keys( get_plugins() )[0];
		$extra = function ( $list ) use ( $file ) {
			$list['test-bundled'] = array( 'name' => 'Test', 'file' => $file, 'zip' => '/nowhere.zip', 'version' => '1.0' );

			return $list;
		};
		add_filter( 'devbase/bundled_plugins', $extra );

		$this->assertTrue( $this->plugin( devbase_updates_inventory(), $file )['bundled'] );
		$this->assertContains( 'woocommerce/woocommerce.php', devbase_updates_bundled_files() );

		remove_filter( 'devbase/bundled_plugins', $extra );
	}

	public function test_an_update_check_older_than_twelve_hours_is_refreshed_first_and_a_fresh_one_is_not() {
		remove_filter( 'devbase/updates/refresh', $this->refresh );

		$this->offers( array(), null, time() - HOUR_IN_SECONDS );
		devbase_updates_inventory();
		$asked = array_filter( $this->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'api.wordpress.org' );
		} );
		$this->assertCount( 0, $asked, 'Checked an hour ago: not asked again.' );

		$this->offers( array(), null, time() - 13 * HOUR_IN_SECONDS );
		devbase_updates_inventory();
		$urls = implode( "\n", wp_list_pluck( $this->requests, 'url' ) );
		$this->assertStringContainsString( 'api.wordpress.org/plugins/update-check', $urls );
		$this->assertStringContainsString( 'api.wordpress.org/core/version-check', $urls );

		add_filter( 'devbase/updates/refresh', $this->refresh );
	}

	// --- Sending -------------------------------------------------------------------

	public function test_the_inventory_is_posted_to_brand_relay_with_the_site_key() {
		$result = devbase_updates_run();

		$this->assertSame( 'ok', $result['status'] );
		$request = end( $this->requests );
		$this->assertSame( 'https://relay.test/v1/site/updates', $request['url'] );
		$this->assertSame( 'Bearer brs_testkey', $request['args']['headers']['Authorization'] );
		$this->assertSame( 0, $request['args']['redirection'], 'A redirect is never followed with the key.' );
		$this->assertSame( array( 'core', 'plugins' ), array_keys( $this->sent() ) );
	}

	public function test_what_brand_relay_answers_is_recorded_and_an_apply_entry_without_an_id_is_ignored() {
		devbase_updates_load_admin();
		$file   = array_keys( get_plugins() )[0];
		$before = get_plugins()[ $file ]['Version'];

		$this->answer = array( 200, array( 'ok' => true, 'apply' => array( array( 'kind' => 'plugin', 'item' => $file ) ) ) );
		$this->assertSame( 'ok', devbase_updates_run()['status'] );
		$this->assertSame( $before, get_plugins()[ $file ]['Version'], 'No id: not a request Brand Relay made, nothing is applied.' );
		$this->assertFalse( has_action( 'shutdown', 'devbase_updates_after_answer' ) );

		$this->answer = array( 401, array( 'status' => 'unavailable' ) );
		$this->assertSame( 'refused', devbase_updates_run()['status'] );
		$this->answer = array( 403, array( 'error' => 'feature_off' ) );
		$this->assertSame( 'not_in_plan', devbase_updates_run()['status'] );
		$this->answer = array( 429, array( 'error' => 'too_soon' ) );
		$this->assertSame( 'idle', devbase_updates_run()['status'] );
		$this->answer = array( 500, array() );
		$this->assertSame( array( 'status' => 'error', 'detail' => 'HTTP 500' ), devbase_updates_run() );
		$this->answer = new WP_Error( 'http_request_failed', 'timed out' );
		$this->assertSame( array( 'status' => 'error', 'detail' => 'timed out' ), devbase_updates_run() );
	}

	// --- The ping ------------------------------------------------------------------

	public function test_a_ping_naming_updates_sends_the_inventory() {
		$ran = $this->ping( '{"run":["updates"]}' )->get_data()['ran'];

		$this->assertSame( 'sent', $ran->updates );
		$this->assertNotNull( $this->sent() );
	}

	public function test_a_ping_for_updates_when_the_plan_lacks_it_says_so_and_sends_nothing() {
		$this->plan( array( 'health' ) );

		$this->assertSame( 'not_in_plan', $this->ping( '{"run":["updates"]}' )->get_data()['ran']->updates );
		$this->assertNull( $this->sent() );
	}

	public function test_updates_is_a_service_brand_relay_can_name() {
		$this->assertContains( 'updates', devbase_maintenance_known() );
		$this->assertSame( 'devbase_updates_run', devbase_maintenance_services()['updates'] );
	}

	// --- Update now (step 2) -------------------------------------------------------

	/**
	 * Replaces the real upgrade: records what WordPress's updater would be asked, and answers $result. A plugin that
	 * "updates" gets its version raised in get_plugins()'s cache, as a real update would.
	 */
	private function fake_upgrade( $result = true, &$asked = null ) {
		$asked         = array();
		$this->upgrade = function ( $pre, $type, $offer ) use ( $result, &$asked ) {
			$asked[] = array( $type, 'plugin' === $type ? $offer->plugin : $offer->current );
			if ( true === $result && 'plugin' === $type ) {
				$version = $offer->new_version;
				add_filter( 'devbase/updates/installed_version', function () use ( $version ) {
					return $version;
				} );
			}

			return $result;
		};
		add_filter( 'devbase/updates/upgrade', $this->upgrade, 10, 3 );
	}

	private function results_sent( $n ) {
		$sent = array_values( array_filter( $this->requests, function ( $r ) {
			return substr( $r['url'], -strlen( '/v1/site/updates' ) ) === '/v1/site/updates';
		} ) );

		return json_decode( $sent[ $n ]['args']['body'], true )['results'] ?? null;
	}

	public function test_the_apply_list_is_read_strictly_ids_and_names_only() {
		$items = devbase_updates_apply_list( array( 'apply' => array(
			array( 'id' => 'upd_abc12345', 'kind' => 'plugin', 'item' => 'shop/shop.php', 'url' => 'https://evil.test/x.zip', 'version' => '9.9' ),
			array( 'id' => 'upd_abc12346', 'kind' => 'core', 'item' => 'anything' ),
			array( 'id' => 'upd_abc12347', 'kind' => 'plugin', 'item' => '../../wp-config.php' ),
			array( 'id' => 'upd_abc12348', 'kind' => 'theme', 'item' => 'twentytwentyfive' ),
			array( 'id' => 'nope', 'kind' => 'plugin', 'item' => 'shop/shop.php' ),
			'x',
		) ) );

		$this->assertSame( array(
			array( 'id' => 'upd_abc12345', 'kind' => 'plugin', 'item' => 'shop/shop.php' ),
			array( 'id' => 'upd_abc12346', 'kind' => 'core', 'item' => 'core' ),
		), $items, 'No URL or version is ever taken from Brand Relay; themes and odd paths are ignored.' );
		$this->assertSame( array(), devbase_updates_apply_list( array( 'apply' => 'x' ) ) );
	}

	public function test_handed_over_updates_are_applied_after_the_answer_and_reported_at_once() {
		$this->offers( array( 'shop/shop.php' => '2.1' ), null );
		$this->fake_upgrade( true, $asked );
		$this->answers = array(
			array( 200, array( 'ok' => true, 'apply' => array( array( 'id' => 'upd_shop1234', 'kind' => 'plugin', 'item' => 'shop/shop.php' ) ) ) ),
			array( 200, array( 'ok' => true, 'results' => 1, 'apply' => array() ) ),
		);

		$result = devbase_updates_run();

		$this->assertSame( 'started', $result['status'], 'The ping is answered before anything is updated.' );
		$this->assertSame( array(), $asked, 'Nothing updated yet.' );
		$this->assertSame( 1000, has_action( 'shutdown', 'devbase_updates_after_answer' ) );

		devbase_updates_after_answer();

		$this->assertSame( array( array( 'plugin', 'shop/shop.php' ) ), $asked );
		$this->assertSame( array( array( 'id' => 'upd_shop1234', 'ok' => true, 'from' => '2.0', 'to' => '2.1', 'error' => '' ) ), $this->results_sent( 1 ) );
		$this->assertFalse( get_option( DEVBASE_UPDATES_RESULTS ), 'Acknowledged: not kept.' );
	}

	private function sent_body( $n ) {
		$sent = array_values( array_filter( $this->requests, function ( $r ) {
			return substr( $r['url'], -strlen( '/v1/site/updates' ) ) === '/v1/site/updates';
		} ) );

		return json_decode( $sent[ $n ]['args']['body'], true );
	}

	public function test_results_brand_relay_did_not_get_are_kept_and_sent_with_the_next_report() {
		$this->offers( array( 'shop/shop.php' => '2.1' ), null );
		$this->fake_upgrade( true );
		$this->answers = array(
			array( 200, array( 'apply' => array( array( 'id' => 'upd_shop1234', 'kind' => 'plugin', 'item' => 'shop/shop.php' ) ) ) ),
			array( 500, array() ),
		);

		devbase_updates_run();
		devbase_updates_after_answer();

		$this->assertCount( 1, get_option( DEVBASE_UPDATES_RESULTS ) );

		devbase_updates_run();
		$this->assertSame( 'upd_shop1234', $this->results_sent( 2 )[0]['id'] );
		$this->assertFalse( get_option( DEVBASE_UPDATES_RESULTS ) );
	}

	public function test_only_an_installed_non_bundled_plugin_with_an_offer_is_ever_updated() {
		$this->offers( array( 'hello.php' => '1.7.2', 'gone/gone.php' => '3.0' ), null );
		$this->fake_upgrade( true, $asked );
		$bundle = function ( $list ) {
			$list['shop'] = array( 'name' => 'Shop', 'file' => 'shop/shop.php', 'zip' => '/x.zip', 'version' => '2.0' );

			return $list;
		};
		add_filter( 'devbase/bundled_plugins', $bundle );

		$results = devbase_updates_apply( array(
			array( 'id' => 'upd_a1234567', 'kind' => 'plugin', 'item' => 'gone/gone.php' ),
			array( 'id' => 'upd_b1234567', 'kind' => 'plugin', 'item' => 'shop/shop.php' ),
			array( 'id' => 'upd_c1234567', 'kind' => 'plugin', 'item' => 'hello.php' ),
			array( 'id' => 'upd_d1234567', 'kind' => 'core', 'item' => 'core' ),
		) );
		remove_filter( 'devbase/bundled_plugins', $bundle );

		$this->assertSame(
			array( 'The plugin is not installed.', 'The theme updates this plugin itself.', 'WordPress offers no update for this plugin now.', 'WordPress offers no update now.' ),
			wp_list_pluck( $results, 'error' )
		);
		$this->assertSame( array( false, false, false, false ), wp_list_pluck( $results, 'ok' ) );
		$this->assertSame( array(), $asked, 'WordPress was never asked.' );
	}

	public function test_what_wordpress_says_when_it_fails_or_declines_is_what_brand_relay_is_told() {
		$this->offers( array( 'shop/shop.php' => '2.1' ), null );
		$this->fake_upgrade( new WP_Error( 'plugin_update_fatal_error_rollback_successful', "The update for 'shop' contained a fatal error. The previously installed version has been restored." ) );
		$item = array( 'id' => 'upd_a1234567', 'kind' => 'plugin', 'item' => 'shop/shop.php' );

		$this->assertSame( array( array( 'id' => 'upd_a1234567', 'ok' => false, 'from' => '2.0', 'to' => '2.1', 'error' => "The update for 'shop' contained a fatal error. The previously installed version has been restored." ) ), devbase_updates_apply( array( $item ) ) );

		remove_filter( 'devbase/updates/upgrade', $this->upgrade );
		add_filter( 'automatic_updater_disabled', '__return_false' );
		$this->fake_upgrade( false );
		$this->assertStringStartsWith( 'WordPress declined the update', devbase_updates_apply( array( $item ) )[0]['error'] );

		remove_filter( 'devbase/updates/upgrade', $this->upgrade );
		$this->fake_upgrade( array() ); // "Succeeded" (WordPress answers an array of files) but the version did not move.
		$this->assertStringStartsWith( 'WordPress reported success but the plugin is still at', devbase_updates_apply( array( $item ) )[0]['error'] );
	}

	public function test_nothing_runs_while_wordpress_is_already_updating() {
		$this->offers( array( 'shop/shop.php' => '2.1' ), null );
		$this->fake_upgrade( true, $asked );
		WP_Upgrader::create_lock( 'auto_updater' );

		$results = devbase_updates_apply( array( array( 'id' => 'upd_a1234567', 'kind' => 'plugin', 'item' => 'shop/shop.php' ) ) );

		$this->assertSame( 'WordPress is already running updates. Try again in a few minutes.', $results[0]['error'] );
		$this->assertSame( array(), $asked );
	}

	public function test_the_lock_is_released_after_applying() {
		$this->offers( array( 'shop/shop.php' => '2.1' ), null );
		$this->fake_upgrade( true );
		devbase_updates_apply( array( array( 'id' => 'upd_a1234567', 'kind' => 'plugin', 'item' => 'shop/shop.php' ) ) );

		$this->assertTrue( WP_Upgrader::create_lock( 'auto_updater' ), 'Free again.' );
	}

	public function test_core_is_updated_only_to_the_version_wordpress_offers() {
		$this->offers( array(), '999.1' );
		$this->fake_upgrade( '999.1', $asked );

		$result = devbase_updates_apply( array( array( 'id' => 'upd_core1234', 'kind' => 'core', 'item' => 'core' ) ) )[0];

		$this->assertSame( array( array( 'core', '999.1' ) ), $asked );
		$this->assertFalse( $result['ok'], 'The files on disk did not change in a test, and the site says so rather than claim success.' );
		$this->assertSame( '999.1', $result['to'] );
	}

	public function test_a_ping_naming_updates_with_work_to_do_answers_started() {
		$this->offers( array( 'shop/shop.php' => '2.1' ), null );
		$this->fake_upgrade( true );
		$this->answers = array( array( 200, array( 'apply' => array( array( 'id' => 'upd_shop1234', 'kind' => 'plugin', 'item' => 'shop/shop.php' ) ) ) ) );

		$this->assertSame( 'started', $this->ping( '{"run":["updates"]}' )->get_data()['ran']->updates );
	}

	public function test_offers_an_earlier_update_cleared_are_fetched_again_before_the_next_item() {
		$this->offers( array( 'shop/shop.php' => '2.1' ), '999.1' );
		$this->fake_upgrade( true, $asked );
		// What a real plugin update does: WordPress clears its update caches.
		$clear = function ( $pre, $type ) {
			if ( 'plugin' === $type ) {
				delete_site_transient( 'update_core' );
			}

			return $pre;
		};
		add_filter( 'devbase/updates/upgrade', $clear, 20, 2 );
		remove_filter( 'devbase/updates/refresh', $this->refresh );

		devbase_updates_apply( array(
			array( 'id' => 'upd_a1234567', 'kind' => 'plugin', 'item' => 'shop/shop.php' ),
			array( 'id' => 'upd_core1234', 'kind' => 'core', 'item' => 'core' ),
		) );
		remove_filter( 'devbase/updates/upgrade', $clear, 20 );
		add_filter( 'devbase/updates/refresh', $this->refresh );

		$this->assertStringContainsString( 'api.wordpress.org/core/version-check', implode( "\n", wp_list_pluck( $this->requests, 'url' ) ), 'Asked WordPress.org again rather than give up on core.' );
	}
}
