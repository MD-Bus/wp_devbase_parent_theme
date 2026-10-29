<?php
// Covers inc/updates/relay-sync.php.
//
// Brand Relay is faked with WordPress's pre_http_request filter, the same
// approach Test_Warden_Updates.php uses. The DEVBASE_RELAY_* constants are
// defined once for the whole run (PHP constants can't be undefined), so
// nothing else in the suite should depend on Brand Relay being unconfigured.

class Test_Relay_Sync extends WP_UnitTestCase {

	private $filter = null;
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

		delete_option( DEVBASE_RELAY_STATE_OPTION );
		update_field( 'warden_parent_licence', 'WRD-OLD', 'option' );

		// Every ping now counts against its IP; give each test a fresh one.
		static $n = 0;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( ++$n % 250 + 1 );
	}

	public function tear_down() {
		$this->unfake_relay();
		$this->requests = array();
		delete_option( DEVBASE_RELAY_STATE_OPTION );

		parent::tear_down();
	}

	private function unfake_relay() {
		if ( null !== $this->filter ) {
			remove_filter( 'pre_http_request', $this->filter, 10 );
			$this->filter = null;
		}
	}

	private function fake_relay( $code, $body ) {
		$this->unfake_relay();

		$this->filter = function ( $preempt, $args, $url ) use ( $code, $body ) {
			// A sync ends with wp_update_themes(), which calls wordpress.org
			// and (through our filter) Warden. Neither is under test here, so
			// give them harmless canned answers instead of Brand Relay's body.
			if ( 0 !== strpos( $url, 'https://relay.test' ) ) {
				return array(
					'response' => array( 'code' => false !== strpos( $url, 'api.wordpress.org' ) ? 200 : 404 ),
					'body'     => wp_json_encode( array( 'themes' => array(), 'no_update' => array(), 'translations' => array() ) ),
					'headers'  => array(),
				);
			}

			$this->requests[] = array( 'url' => $url, 'args' => $args );

			return array(
				'response' => array( 'code' => $code ),
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	private function ping_request( $timestamp = null, $nonce = null, $secret = null, $body = '', $origin = 'https://relay.test' ) {
		$secret    = null === $secret ? devbase_relay_ping_secret() : $secret;
		$timestamp = null === $timestamp ? (string) time() : (string) $timestamp;
		$nonce     = null === $nonce ? bin2hex( random_bytes( 12 ) ) : $nonce;

		$request = new WP_REST_Request( 'POST', '/devbase/v1/sync' );
		$request->set_body( $body );
		$request->set_header( 'x-relay-timestamp', $timestamp );
		$request->set_header( 'x-relay-nonce', $nonce );
		if ( null !== $origin ) {
			$request->set_header( 'origin', $origin );
		}
		$request->set_header( 'x-relay-signature', hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . rtrim( strtolower( (string) $origin ), '/' ) . '.' . $body, $secret ) );

		return $request;
	}

	// --- Pulling the values ---------------------------------------------------

	public function test_sync_sends_the_site_key_and_stores_a_new_licence() {
		$this->fake_relay( 200, array( 'params' => array( 'warden_parent_licence' => 'WRD-NEW' ) ) );

		$result = devbase_relay_sync();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'warden_parent_licence' ), $result['changed'] );
		$this->assertSame( 'WRD-NEW', devbase_warden_parent_licence() );
		$this->assertSame( 'https://relay.test/v1/site', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer brs_testkey', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_a_removed_licence_is_put_back() {
		update_field( 'warden_parent_licence', '', 'option' );
		$this->fake_relay( 200, array( 'params' => array( 'warden_parent_licence' => 'WRD-NEW' ) ) );

		devbase_relay_sync();

		$this->assertSame( 'WRD-NEW', devbase_warden_parent_licence() );
	}

	public function test_a_changed_key_drops_the_cached_warden_answer() {
		update_option( devbase_warden_state_option( get_template() ), array( 'response' => array( 'valid' => true ) ), false );
		$this->fake_relay( 200, array( 'params' => array( 'warden_parent_licence' => 'WRD-NEW' ) ) );

		devbase_relay_sync();

		$this->assertArrayNotHasKey( 'response', devbase_warden_get_state( get_template() ) );
	}

	public function test_an_unchanged_key_changes_nothing() {
		$this->fake_relay( 200, array( 'params' => array( 'warden_parent_licence' => 'WRD-OLD' ) ) );

		$result = devbase_relay_sync();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['changed'] );
	}

	public function test_missing_empty_or_non_string_params_never_clear_a_key() {
		foreach ( array( array(), array( 'warden_parent_licence' => '' ), array( 'warden_parent_licence' => array( 'x' ) ) ) as $params ) {
			$this->fake_relay( 200, array( 'params' => $params ) );

			devbase_relay_sync();

			$this->assertSame( 'WRD-OLD', devbase_warden_parent_licence() );
		}
	}

	public function test_failures_keep_the_current_key_and_record_the_error() {
		foreach ( array( array( 500, '{}' ), array( 401, '{}' ), array( 200, 'not json' ) ) as $case ) {
			$this->fake_relay( $case[0], $case[1] );

			$result = devbase_relay_sync();

			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'WRD-OLD', devbase_warden_parent_licence() );
			$this->assertNotSame( '', devbase_relay_get_state()['last_error'] );
		}
	}

	public function test_the_warden_address_and_key_fill_an_empty_field_but_never_overwrite() {
		$public = base64_encode( str_repeat( 'k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) );
		update_field( 'warden_base_url', '', 'option' );
		update_field( 'warden_public_key', '', 'option' );
		$this->fake_relay( 200, array( 'params' => array( 'warden_base_url' => 'https://warden.test/', 'warden_public_key' => $public ) ) );

		devbase_relay_sync();

		$this->assertSame( 'https://warden.test', devbase_warden_base_url() );
		$this->assertSame( $public, devbase_warden_public_key() );

		$this->fake_relay( 200, array( 'params' => array( 'warden_base_url' => 'https://evil.test', 'warden_public_key' => base64_encode( str_repeat( 'x', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) ) ) ) );

		devbase_relay_sync();

		$this->assertSame( 'https://warden.test', devbase_warden_base_url() );
		$this->assertSame( $public, devbase_warden_public_key() );
	}

	public function test_a_non_https_address_or_malformed_key_is_ignored() {
		update_field( 'warden_base_url', '', 'option' );
		update_field( 'warden_public_key', '', 'option' );
		$this->fake_relay( 200, array( 'params' => array( 'warden_base_url' => 'http://warden.test', 'warden_public_key' => 'not-a-key' ) ) );

		devbase_relay_sync();

		$this->assertSame( '', devbase_warden_base_url() );
		$this->assertSame( '', devbase_warden_public_key() );
	}

	public function test_pings_are_rate_limited_but_cron_is_not() {
		$this->fake_relay( 200, array( 'params' => array() ) );

		$this->assertTrue( devbase_relay_sync( true )['ok'] );
		$this->assertSame( 'rate limited', devbase_relay_sync( true )['error'] );
		$this->assertTrue( devbase_relay_sync( false )['ok'] );
		$this->assertCount( 2, $this->requests );
	}

	// --- The ping -------------------------------------------------------------

	public function test_a_correctly_signed_ping_is_accepted() {
		$this->assertTrue( devbase_relay_verify_ping( $this->ping_request() ) );
	}

	public function test_a_ping_from_another_origin_is_refused_even_when_signed_for_it() {
		$this->assertWPError( devbase_relay_verify_ping( $this->ping_request( null, null, null, '', 'https://evil.test' ) ) );
	}

	public function test_a_ping_without_an_origin_is_refused() {
		$this->assertWPError( devbase_relay_verify_ping( $this->ping_request( null, null, null, '', null ) ) );
	}

	public function test_a_signature_cannot_be_reused_with_a_different_origin() {
		$request = $this->ping_request();
		$request->set_header( 'origin', 'https://relay.test.evil.example' );

		$this->assertWPError( devbase_relay_verify_ping( $request ) );
	}

	public function test_the_origin_ignores_case_and_a_trailing_slash() {
		$this->assertTrue( devbase_relay_verify_ping( $this->ping_request( null, null, null, '', 'HTTPS://Relay.Test/' ) ) );
	}

	public function test_a_wrong_secret_is_refused() {
		$this->assertWPError( devbase_relay_verify_ping( $this->ping_request( null, null, 'other-secret' ) ) );
	}

	public function test_a_tampered_body_is_refused() {
		$request = $this->ping_request( null, null, null, 'a' );
		$request->set_body( 'b' );

		$this->assertWPError( devbase_relay_verify_ping( $request ) );
	}

	public function test_an_old_timestamp_is_refused() {
		$this->assertWPError( devbase_relay_verify_ping( $this->ping_request( time() - HOUR_IN_SECONDS ) ) );
	}

	public function test_a_replayed_ping_is_refused() {
		$nonce = bin2hex( random_bytes( 12 ) );
		$ts    = time();

		$this->assertTrue( devbase_relay_verify_ping( $this->ping_request( $ts, $nonce ) ) );
		$this->assertWPError( devbase_relay_verify_ping( $this->ping_request( $ts, $nonce ) ) );
	}

	public function test_requests_over_the_limit_are_refused_with_429_even_when_unsigned() {
		add_filter( 'devbase/relay/ping_limit', fn() => 3 );

		for ( $i = 0; $i < 3; $i++ ) {
			$result = devbase_relay_verify_ping( new WP_REST_Request( 'POST', '/devbase/v1/sync' ) );
			$this->assertSame( 401, $result->get_error_data()['status'] );
		}

		$result = devbase_relay_verify_ping( $this->ping_request() );

		$this->assertWPError( $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		remove_all_filters( 'devbase/relay/ping_limit' );
	}

	public function test_the_limit_is_per_ip() {
		add_filter( 'devbase/relay/ping_limit', fn() => 1 );

		devbase_relay_verify_ping( new WP_REST_Request( 'POST', '/devbase/v1/sync' ) );
		$this->assertSame( 429, devbase_relay_verify_ping( new WP_REST_Request( 'POST', '/devbase/v1/sync' ) )->get_error_data()['status'] );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 401, devbase_relay_verify_ping( new WP_REST_Request( 'POST', '/devbase/v1/sync' ) )->get_error_data()['status'] );

		remove_all_filters( 'devbase/relay/ping_limit' );
	}

	public function test_a_ping_without_headers_is_refused() {
		$this->assertWPError( devbase_relay_verify_ping( new WP_REST_Request( 'POST', '/devbase/v1/sync' ) ) );
	}
}
