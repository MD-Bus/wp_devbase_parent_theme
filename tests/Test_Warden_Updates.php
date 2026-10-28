<?php
// Covers inc/updates/warden.php and inc/updates/theme-updates.php.
//
// These tests use a REAL Ed25519 keypair generated with libsodium and sign
// real response bodies with it, rather than stubbing out signature
// verification. That matters: the signature check is the only thing
// standing between this theme and a hostile network handing it a download
// URL, so a test that mocks it away would assert nothing about the
// property that actually matters. The nonce echo and licence-key match are
// exercised the same way — by returning genuinely wrong values and
// asserting the response is rejected.
//
// Network calls are faked with WordPress's own pre_http_request filter,
// the same approach Test_Form_Dispatch.php and Test_Dependency_Updater.php
// use.

class Test_Warden_Updates extends WP_UnitTestCase {

	const LICENCE = 'WRD-7KQ4M-X2PHR-9TCVN-B3WZD';

	private $secret_key;
	private $public_key_base64;
	private $filter = null;
	private $requests = array();

	public function set_up() {
		parent::set_up();

		$keypair                 = sodium_crypto_sign_keypair();
		$this->secret_key        = sodium_crypto_sign_secretkey( $keypair );
		$this->public_key_base64 = base64_encode( sodium_crypto_sign_publickey( $keypair ) );

		update_field( 'warden_base_url', 'https://warden.test', 'option' );
		update_field( 'warden_public_key', $this->public_key_base64, 'option' );
		update_field( 'warden_parent_licence', self::LICENCE, 'option' );
	}

	public function tear_down() {
		if ( null !== $this->filter ) {
			remove_filter( 'pre_http_request', $this->filter, 10 );
			$this->filter = null;
		}

		$this->requests = array();
		delete_option( devbase_warden_state_option( get_template() ) );

		parent::tear_down();
	}

	/**
	 * Fakes Warden. $overrides lets a test corrupt one specific part of an
	 * otherwise-valid signed response, so each rejection path can be tested
	 * in isolation.
	 *
	 * @param array $overrides  Replaces keys in the response body.
	 * @param bool  $sign_with_wrong_key  Sign with a different keypair.
	 */
	private function fake_warden( array $overrides = array(), $sign_with_wrong_key = false, $update = null ) {
		$secret = $this->secret_key;

		if ( $sign_with_wrong_key ) {
			$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		}

		$this->filter = function ( $preempt, $args, $url ) use ( $overrides, $secret, $update ) {
			$this->requests[] = $url;

			// Echo back the nonce we were actually sent, as Warden does.
			$query = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

			$body = array(
				'valid'       => true,
				'status'      => 'active',
				'message'     => '',
				'name'        => 'Test site',
				'app'         => null,
				'expires_at'  => '2027-03-31T23:59:59.999Z',
				'checked_at'  => '2026-10-11T16:17:30.248Z',
				'license_key' => self::LICENCE,
				'domain'      => isset( $query['domain'] ) ? $query['domain'] : '',
				'nonce'       => isset( $query['nonce'] ) ? $query['nonce'] : null,
			);

			if ( null !== $update ) {
				$body['update'] = $update;
			}

			$body = array_merge( $body, $overrides );
			$json = wp_json_encode( $body );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => $json,
				'headers'  => array(
					'x-warden-signature-ed25519' => base64_encode( sodium_crypto_sign_detached( $json, $secret ) ),
					'x-warden-key-id'            => str_repeat( 'a', 16 ),
				),
			);
		};

		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	/**
	 * An `update` block in the shape Warden documents (guide/releases.md): the
	 * availability flag, plus the release nested under `latest`.
	 */
	private static function offer( $version, $download_url, array $extra = array(), $available = true ) {
		return array(
			'current'   => '1.0.0',
			'available' => $available,
			'latest'    => array_merge( array( 'version' => $version, 'download_url' => $download_url ), $extra ),
		);
	}

	private function check( $force = true ) {
		return devbase_warden_check( get_template(), self::LICENCE, '1.0.0', $force );
	}

	// --- The happy path and what it sends -----------------------------------

	public function test_a_correctly_signed_response_is_accepted() {
		$this->fake_warden();

		$result = $this->check();

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'active', $result['status'] );
	}

	public function test_the_request_uses_wardens_documented_url_shape() {
		$this->fake_warden();
		$this->check();

		$url = $this->requests[0];

		$this->assertStringStartsWith( 'https://warden.test/check/' . rawurlencode( self::LICENCE ), $url );

		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '1.0.0', $query['version'] );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $query['domain'] );
		$this->assertGreaterThanOrEqual( 8, strlen( $query['nonce'] ), 'Warden requires a nonce of 8-64 characters.' );
		$this->assertLessThanOrEqual( 64, strlen( $query['nonce'] ) );
	}

	public function test_each_check_sends_a_fresh_nonce() {
		$this->fake_warden();

		$this->check();
		$this->check();

		$first  = array();
		$second = array();
		parse_str( (string) wp_parse_url( $this->requests[0], PHP_URL_QUERY ), $first );
		parse_str( (string) wp_parse_url( $this->requests[1], PHP_URL_QUERY ), $second );

		$this->assertNotSame( $first['nonce'], $second['nonce'], 'A reused nonce would defeat replay protection.' );
	}

	// --- Every rejection path -----------------------------------------------

	public function test_a_response_signed_with_the_wrong_key_is_rejected() {
		$this->fake_warden( array(), true );

		$this->assertSame( array(), $this->check(), 'An unverifiable response must never be treated as a verdict.' );

		$state = devbase_warden_get_state( get_template() );
		$this->assertSame( 'signature verification failed', $state['last_error'] );
	}

	public function test_a_response_echoing_the_wrong_nonce_is_rejected() {
		$this->fake_warden( array( 'nonce' => 'not-the-nonce-we-sent' ) );

		$this->assertSame( array(), $this->check() );

		$state = devbase_warden_get_state( get_template() );
		$this->assertSame( 'nonce mismatch (possible replay)', $state['last_error'] );
	}

	public function test_a_response_for_a_different_licence_key_is_rejected() {
		$this->fake_warden( array( 'license_key' => 'WRD-AAAAA-AAAAA-AAAAA-AAAAA' ) );

		$this->assertSame( array(), $this->check() );

		$state = devbase_warden_get_state( get_template() );
		$this->assertSame( 'response was for a different licence key', $state['last_error'] );
	}

	public function test_an_unsigned_response_is_rejected() {
		$this->filter = function ( $preempt, $args, $url ) {
			$this->requests[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'valid' => true, 'license_key' => self::LICENCE ) ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );

		$this->assertSame( array(), $this->check(), 'A missing signature is a transport error, not a verdict.' );
	}

	public function test_no_check_is_made_when_the_public_key_is_not_configured() {
		update_field( 'warden_public_key', '', 'option' );
		$this->fake_warden();

		$this->assertSame( array(), $this->check() );
		$this->assertEmpty( $this->requests, 'With no key to verify against, no request should be made at all.' );
	}

	public function test_no_check_is_made_without_a_licence_key() {
		$this->fake_warden();

		$this->assertSame( array(), devbase_warden_check( get_template(), '', '1.0.0', true ) );
		$this->assertEmpty( $this->requests );
	}

	// --- Fail-soft and caching ----------------------------------------------

	public function test_a_later_failure_falls_back_to_the_last_known_good_response() {
		$this->fake_warden();
		$good = $this->check();
		$this->assertTrue( $good['valid'] );

		remove_filter( 'pre_http_request', $this->filter, 10 );

		// Warden is now hard down.
		$this->filter = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Operation timed out' );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );

		$result = $this->check();

		$this->assertTrue( $result['valid'], 'A Warden outage must never make a valid licence look invalid.' );

		$state = devbase_warden_get_state( get_template() );
		$this->assertSame( 'Operation timed out', $state['last_error'], 'The failure should still be recorded for the Status tab.' );
	}

	public function test_a_server_error_falls_back_to_the_last_known_good_response() {
		$this->fake_warden();
		$this->check();

		remove_filter( 'pre_http_request', $this->filter, 10 );
		$this->filter = function ( $preempt, $args, $url ) {
			return array( 'response' => array( 'code' => 503 ), 'body' => '' );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );

		$this->assertTrue( $this->check()['valid'] );
		$this->assertSame( 'HTTP 503', devbase_warden_get_state( get_template() )['last_error'] );
	}

	public function test_the_cache_prevents_a_second_request_inside_the_ttl() {
		$this->fake_warden();

		$this->check( true );
		$this->assertCount( 1, $this->requests );

		// Not forced this time — should be served from cache.
		devbase_warden_check( get_template(), self::LICENCE, '1.0.0' );

		$this->assertCount( 1, $this->requests, 'An update check must not hit Warden on every invocation.' );
	}

	// --- Resolving an update offer ------------------------------------------

	public function test_a_newer_version_is_offered() {
		$response = array(
			'valid'  => true,
			'update' => self::offer( '1.1.0', 'https://warden.test/download/x' ),
		);

		$update = devbase_warden_pending_update( $response, '1.0.0' );

		$this->assertSame( '1.1.0', $update['version'] );
	}

	public function test_the_same_or_an_older_version_is_not_offered() {
		$response = array(
			'valid'  => true,
			'update' => self::offer( '1.0.0', 'https://warden.test/download/x', array(), false ),
		);

		$this->assertNull( devbase_warden_pending_update( $response, '1.0.0' ) );

		// Even if Warden said "available", the local version check still decides.
		$response['update'] = self::offer( '0.9.0', 'https://warden.test/download/x' );
		$this->assertNull(
			devbase_warden_pending_update( $response, '1.0.0' ),
			'A misconfigured release track must never produce a downgrade.'
		);
	}

	public function test_no_update_is_offered_when_the_licence_is_not_valid() {
		$response = array(
			'valid'  => false,
			'status' => 'expired',
			'update' => self::offer( '9.9.9', 'https://warden.test/download/x' ),
		);

		$this->assertNull( devbase_warden_pending_update( $response, '1.0.0' ) );
	}

	public function test_an_update_block_missing_its_download_url_is_ignored() {
		$response = array(
			'valid'  => true,
			'update' => array( 'current' => '1.0.0', 'available' => true, 'latest' => array( 'version' => '9.9.9' ) ),
		);

		$this->assertNull( devbase_warden_pending_update( $response, '1.0.0' ) );
	}

	public function test_a_release_Warden_says_is_not_available_is_not_offered() {
		// `latest` is present even when nothing newer exists; `available` is
		// what says whether to offer it.
		$response = array(
			'valid'  => true,
			'update' => self::offer( '9.9.9', 'https://warden.test/download/x', array(), false ),
		);

		$this->assertNull( devbase_warden_pending_update( $response, '1.0.0' ) );
	}

	public function test_the_old_flat_update_shape_is_not_what_Warden_sends() {
		// Regression: the client once read update.version / update.download_url
		// at the top level, which Warden never sends, so a real release was
		// reported as "Up to date" and never offered.
		$response = array(
			'valid'  => true,
			'update' => array( 'version' => '9.9.9', 'download_url' => 'https://warden.test/download/x' ),
		);

		$this->assertNull( devbase_warden_pending_update( $response, '1.0.0' ) );
	}

	// --- Wiring into WordPress's own update pipeline ------------------------

	public function test_the_parent_theme_is_always_a_track() {
		$this->assertArrayHasKey( get_template(), devbase_update_tracks() );
	}

	public function test_an_available_update_is_injected_into_the_theme_transient() {
		$installed = devbase_update_installed_version( get_template() );

		$this->fake_warden( array(), false, self::offer(
			'99.0.0',
			'https://warden.test/download/abc?domain=example.org',
			array( 'changelog' => 'Fixed things.', 'sha256' => 'deadbeef' )
		) );

		$transient = devbase_inject_theme_updates( new stdClass() );

		$entry = $transient->response[ get_template() ];

		$this->assertSame( '99.0.0', $entry['new_version'] );
		$this->assertSame( 'https://warden.test/download/abc?domain=example.org', $entry['package'] );
		$this->assertSame( get_template(), $entry['theme'] );
		$this->assertNotSame( $installed, $entry['new_version'] );
	}

	public function test_being_up_to_date_lands_in_no_update_so_the_toggle_still_shows() {
		$this->fake_warden(); // No update block at all.

		$transient = devbase_inject_theme_updates( new stdClass() );

		$this->assertArrayNotHasKey( get_template(), (array) ( $transient->response ?? array() ) );
		$this->assertArrayHasKey(
			get_template(),
			$transient->no_update,
			'A theme in neither list gets no auto-update toggle in wp-admin.'
		);
	}

	public function test_auto_updates_are_always_on_for_our_themes() {
		$item = (object) array( 'theme' => get_template() );

		$this->assertTrue( devbase_enable_theme_auto_updates( false, $item ) );
	}

	public function test_auto_updates_cannot_be_switched_off() {
		// The setting was removed; a value left over in the database must not matter.
		update_option( 'options_warden_auto_update', 0 );

		$item = (object) array( 'theme' => get_template() );

		$this->assertTrue( devbase_enable_theme_auto_updates( false, $item ) );
		$this->assertTrue( devbase_enable_theme_auto_updates( true, $item ) );

		delete_option( 'options_warden_auto_update' );
	}

	public function test_auto_update_behaviour_for_other_themes_is_untouched() {
		$item = (object) array( 'theme' => 'twentytwentyfour' );

		// Whatever WordPress already decided must pass straight through.
		$this->assertTrue( devbase_enable_theme_auto_updates( true, $item ) );
		$this->assertFalse( devbase_enable_theme_auto_updates( false, $item ) );
	}

	// --- Licence failure modes: nothing may break the site ----------------
	//
	// The question each of these answers is "does the site keep working,
	// and does it correctly stop being offered updates it isn't entitled
	// to" — not "does it show a nice message".

	private function offered_version() {
		$transient = devbase_inject_theme_updates( new stdClass() );

		return isset( $transient->response[ get_template() ] )
			? $transient->response[ get_template() ]['new_version']
			: null;
	}

	public function test_an_expired_licence_is_offered_nothing() {
		$this->fake_warden(
			array( 'valid' => false, 'status' => 'expired' ),
			false,
			self::offer( '99.0.0', 'https://warden.test/d' )
		);

		$this->assertNull( $this->offered_version(), 'An expired licence must not receive updates.' );
	}

	public function test_a_suspended_licence_is_offered_nothing() {
		$this->fake_warden(
			array( 'valid' => false, 'status' => 'suspended' ),
			false,
			self::offer( '99.0.0', 'https://warden.test/d' )
		);

		$this->assertNull( $this->offered_version() );
	}

	public function test_a_licence_warden_has_never_heard_of_is_offered_nothing() {
		// An unknown key gets an unsigned 404. There is no cached good
		// response to fall back to, so this resolves to "no offer" — and
		// crucially not to an error that breaks the update screen.
		$this->filter = function ( $preempt, $args, $url ) {
			$this->requests[] = $url;
			return array( 'response' => array( 'code' => 404 ), 'body' => 'Not found', 'headers' => array() );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );

		$this->assertNull( $this->offered_version() );
	}

	public function test_a_deleted_licence_stops_being_offered_updates_once_the_window_closes() {
		// The real hole this window exists for, and it was genuinely open
		// before: a valid licence carrying an update offer, then the
		// licence is DELETED from Warden so /check/ returns an unsigned
		// 404. That 404 must not be treated as a revocation (it isn't
		// signed, so it can't be trusted to revoke anything) — but the
		// cached offer must not stand forever either.
		$this->fake_warden( array(), false, self::offer( '99.0.0', 'https://warden.test/d' ) );

		$this->assertSame( '99.0.0', $this->offered_version(), 'baseline: offered while the licence is valid' );

		// Licence deleted — unsigned 404 from here on.
		remove_filter( 'pre_http_request', $this->filter, 10 );
		$this->filter = function () {
			return array( 'response' => array( 'code' => 404 ), 'body' => 'Not found', 'headers' => array() );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );

		// Inside the window, fail-soft still applies: the client is NOT cut
		// off by what might simply be an outage.
		devbase_warden_check( get_template(), self::LICENCE, '1.0.0', true );
		$this->assertSame( '99.0.0', $this->offered_version(), 'A brief outage must not cut a valid client off.' );

		// Backdate the last success past the window.
		$state = devbase_warden_get_state( get_template() );
		$state['succeeded_at'] = time() - ( DEVBASE_WARDEN_OFFER_WINDOW + HOUR_IN_SECONDS );
		update_option( devbase_warden_state_option( get_template() ), $state, false );

		$this->assertNull(
			$this->offered_version(),
			'A cached offer must stop authorising installs once it is older than the offer window.'
		);
	}

	public function test_the_cached_licence_state_is_still_shown_after_the_window_closes() {
		// Bounding the OFFER must not blank the display — "here is what we
		// last knew, and we cannot currently reach Warden" is more useful
		// than nothing, and the Status tab depends on it.
		$this->fake_warden();
		$this->check();

		$state = devbase_warden_get_state( get_template() );
		$state['succeeded_at'] = time() - ( DEVBASE_WARDEN_OFFER_WINDOW + HOUR_IN_SECONDS );
		update_option( devbase_warden_state_option( get_template() ), $state, false );

		$this->assertFalse( devbase_warden_offer_window( get_template() ) );

		$response = devbase_warden_check( get_template(), self::LICENCE, '1.0.0' );
		$this->assertTrue( $response['valid'], 'The cached response must still be readable for display.' );
	}

	public function test_the_offer_window_is_filterable() {
		$this->fake_warden();
		$this->check();

		$state = devbase_warden_get_state( get_template() );
		$state['succeeded_at'] = time() - ( 3 * DAY_IN_SECONDS );
		update_option( devbase_warden_state_option( get_template() ), $state, false );

		$this->assertTrue( devbase_warden_offer_window( get_template() ), '3 days is inside the 7-day default.' );

		$shrink = function () { return DAY_IN_SECONDS; };
		add_filter( 'devbase/updates/offer_window', $shrink );
		$this->assertFalse( devbase_warden_offer_window( get_template() ) );
		remove_filter( 'devbase/updates/offer_window', $shrink );
	}

	public function test_a_failed_check_never_fatals_the_update_transient() {
		// The property that actually matters for "does it break the site":
		// whatever happens, pre_set_site_transient_update_themes must hand
		// back a usable object, because WordPress stores and reads it on
		// every admin page load.
		foreach ( array(
			new WP_Error( 'http_request_failed', 'timeout' ),
			array( 'response' => array( 'code' => 500 ), 'body' => '' ),
			array( 'response' => array( 'code' => 404 ), 'body' => 'Not found', 'headers' => array() ),
			array( 'response' => array( 'code' => 200 ), 'body' => 'not json', 'headers' => array() ),
		) as $bad ) {
			if ( $this->filter ) {
				remove_filter( 'pre_http_request', $this->filter, 10 );
			}
			$this->filter = function () use ( $bad ) { return $bad; };
			add_filter( 'pre_http_request', $this->filter, 10, 3 );

			$transient = devbase_inject_theme_updates( new stdClass() );

			$this->assertIsObject( $transient );
			$this->assertArrayHasKey(
				get_template(),
				$transient->no_update,
				'Even a totally failed check must leave the theme listed, so wp-admin stays coherent.'
			);
		}
	}
}
