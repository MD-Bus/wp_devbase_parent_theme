<?php
// Covers inc/updates/rollout.php: a new release waits for this site's share of the rollout window, Warden is
// asked again before it is offered (so a withdrawn release is not installed from a cached answer), an urgent
// release does not wait, and a version this site rolled back is never offered again.
//
// Warden is faked with real Ed25519 signatures, as in Test_Warden_Updates.

class Test_Update_Rollout extends WP_UnitTestCase {

	const LICENCE = 'WRD-7KQ4M-X2PHR-9TCVN-B3WZD';

	private $secret_key;

	/** @var array|null The release the fake Warden offers (update.latest), or null for none. */
	private $release = null;

	/** @var int How many times Warden was asked. */
	private $asked = 0;

	public function set_up() {
		parent::set_up();

		$keypair          = sodium_crypto_sign_keypair();
		$this->secret_key = sodium_crypto_sign_secretkey( $keypair );
		$this->asked      = 0;

		update_field( 'warden_base_url', 'https://warden.test', 'option' );
		update_field( 'warden_public_key', base64_encode( sodium_crypto_sign_publickey( $keypair ) ), 'option' );
		update_field( 'warden_licence_key', self::LICENCE, 'option' );

		add_filter( 'pre_http_request', array( $this, 'warden' ), 10, 3 );
		add_filter( 'devbase/updates/rollout_window', array( $this, 'six_hours' ), 20 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'warden' ), 10 );
		remove_filter( 'devbase/updates/rollout_window', array( $this, 'six_hours' ), 20 );
		delete_option( devbase_warden_state_option( get_template() ) );
		delete_option( DEVBASE_UPDATE_ROLLOUT_OPTION );
		delete_option( DEVBASE_UPDATE_ROLLBACK_OPTION );
		wp_clear_scheduled_hook( DEVBASE_UPDATE_ROLLOUT_HOOK, array( get_template() ) );

		parent::tear_down();
	}

	public function six_hours() {
		return 6 * HOUR_IN_SECONDS;
	}

	public function warden( $pre, $args, $url ) {
		// Anything else (wordpress.org, which wp_update_themes() asks) gets an empty answer: no network in the suite.
		if ( 0 !== strpos( $url, 'https://warden.test/' ) ) {
			return array( 'response' => array( 'code' => 200, 'message' => '' ), 'body' => '{"themes":{},"no_update":{},"translations":[]}', 'headers' => array() );
		}

		$this->asked++;
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$body = array(
			'valid'       => true,
			'status'      => 'active',
			'license_key' => self::LICENCE,
			'nonce'       => $query['nonce'] ?? '',
			'tag'         => $query['tag'] ?? '',
		);

		if ( null !== $this->release ) {
			$body['update'] = array( 'available' => true, 'latest' => $this->release );
		}

		$json = wp_json_encode( $body );

		return array(
			'response' => array( 'code' => 200, 'message' => '' ),
			'body'     => $json,
			'headers'  => array( 'x-warden-signature-ed25519' => base64_encode( sodium_crypto_sign_detached( $json, $this->secret_key ) ) ),
		);
	}

	private function release( $version = '99.0.0', $uploaded_ago = 0, array $extra = array() ) {
		$this->release = array_merge( array(
			'version'      => $version,
			'download_url' => 'https://warden.test/download/x/' . $version,
			'uploaded_at'  => gmdate( 'c', time() - $uploaded_ago ),
		), $extra );

		return $this->release;
	}

	private function offered() {
		$transient = devbase_inject_theme_updates( new stdClass() );

		return $transient->response[ get_template() ]['new_version'] ?? null;
	}

	// --- the wait ----------------------------------------------------------------------------------

	public function test_a_release_published_a_moment_ago_waits_and_a_wake_up_is_scheduled() {
		$release = $this->release( '99.0.0', 0 );

		// Unless this site's share happens to be under a minute, it waits.
		$due = devbase_update_rollout_due_at( get_template(), $release );
		if ( $due <= time() ) {
			$this->markTestSkipped( 'This site address falls in the first seconds of the window.' );
		}

		$this->assertNull( $this->offered() );
		$this->assertSame( $due + MINUTE_IN_SECONDS, wp_next_scheduled( DEVBASE_UPDATE_ROLLOUT_HOOK, array( get_template() ) ) );
	}

	public function test_the_wait_is_inside_the_window_and_the_same_on_every_check() {
		$release = $this->release( '99.0.0', 0 );
		$start   = strtotime( $release['uploaded_at'] );

		$due = devbase_update_rollout_due_at( get_template(), $release );

		$this->assertGreaterThanOrEqual( $start, $due );
		$this->assertLessThan( $start + 6 * HOUR_IN_SECONDS, $due );
		$this->assertSame( $due, devbase_update_rollout_due_at( get_template(), $release ) );
	}

	public function test_sites_are_spread_over_the_window() {
		$release = $this->release( '99.0.0', 0 );
		$offsets = array();

		foreach ( range( 1, 40 ) as $n ) {
			add_filter( 'home_url', $home = function () use ( $n ) {
				return "https://site{$n}.test/";
			} );
			$offsets[] = devbase_update_rollout_due_at( get_template(), $release ) - strtotime( $release['uploaded_at'] );
			remove_filter( 'home_url', $home );
		}

		$this->assertGreaterThan( 30, count( array_unique( $offsets ) ), 'Forty sites do not all install at once.' );
		$this->assertLessThan( 3 * HOUR_IN_SECONDS, min( $offsets ) );
		$this->assertGreaterThan( 3 * HOUR_IN_SECONDS, max( $offsets ) );
	}

	public function test_a_release_published_longer_ago_than_the_window_is_offered() {
		$this->release( '99.0.0', 7 * HOUR_IN_SECONDS );

		$this->assertSame( '99.0.0', $this->offered() );
	}

	public function test_no_window_means_no_wait() {
		remove_filter( 'devbase/updates/rollout_window', array( $this, 'six_hours' ), 20 );
		$this->release( '99.0.0', 0 );

		$this->assertSame( '99.0.0', $this->offered() );
	}

	public function test_an_urgent_release_does_not_wait() {
		$this->release( '99.0.0', 0, array( 'notes' => 'Fixes the contact form. [urgent]' ) );
		$this->assertSame( '99.0.0', $this->offered() );

		$this->assertTrue( devbase_update_release_urgent( array( 'urgent' => true ) ) );
		$this->assertFalse( devbase_update_release_urgent( array( 'notes' => 'Not so urgent.' ) ), 'Only the marker, not the word.' );
	}

	public function test_a_publication_time_far_ahead_is_not_believed() {
		$release = $this->release( '99.0.0', -30 * DAY_IN_SECONDS );

		$due = devbase_update_rollout_due_at( get_template(), $release );

		$this->assertLessThan( time() + 6 * HOUR_IN_SECONDS + 5, $due, 'It counts from when this site first saw the release instead.' );
	}

	public function test_without_a_publication_time_it_counts_from_first_seen() {
		$release = $this->release( '99.0.0', 0 );
		unset( $release['uploaded_at'] );

		update_option( DEVBASE_UPDATE_ROLLOUT_OPTION, array( get_template() => array( 'version' => '99.0.0', 'seen_at' => time() - 7 * HOUR_IN_SECONDS, 'rechecked_at' => 0 ) ) );

		$this->assertLessThan( time(), devbase_update_rollout_due_at( get_template(), $release ) );
	}

	// --- asked again before installing ----------------------------------------------------------------

	private function answer_received_before_the_due_time() {
		$state                 = devbase_warden_get_state( get_template() );
		$state['succeeded_at'] = time() - 8 * HOUR_IN_SECONDS;
		$state['checked_at']   = time() - 8 * HOUR_IN_SECONDS;
		update_option( devbase_warden_state_option( get_template() ), $state, false );
	}

	public function test_warden_is_asked_again_when_the_wait_is_over() {
		$this->release( '99.0.0', 7 * HOUR_IN_SECONDS );
		devbase_warden_check( get_template(), self::LICENCE, devbase_update_installed_version( get_template() ), true );
		$this->answer_received_before_the_due_time();
		$this->asked = 0;

		$this->assertSame( '99.0.0', $this->offered() );
		$this->assertSame( 1, $this->asked, 'One fresh answer before the release is offered.' );

		$this->offered();
		$this->assertSame( 1, $this->asked, 'Once, not on every check.' );
	}

	public function test_a_release_withdrawn_during_the_wait_is_not_installed() {
		$this->release( '99.0.0', 7 * HOUR_IN_SECONDS );
		devbase_warden_check( get_template(), self::LICENCE, devbase_update_installed_version( get_template() ), true );
		$this->answer_received_before_the_due_time();

		$this->release = null; // Withdrawn in Warden.

		$this->assertNull( $this->offered() );
	}

	public function test_a_warden_that_cannot_be_reached_then_leaves_the_cached_answer_standing() {
		$this->release( '99.0.0', 7 * HOUR_IN_SECONDS );
		devbase_warden_check( get_template(), self::LICENCE, devbase_update_installed_version( get_template() ), true );
		$this->answer_received_before_the_due_time();

		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'timed out' );
		}, 5 );

		$this->assertSame( '99.0.0', $this->offered() );
	}

	// --- a version this site rolled back --------------------------------------------------------------

	public function test_a_rolled_back_version_is_never_offered_again_but_the_next_one_is() {
		update_option( DEVBASE_UPDATE_ROLLBACK_OPTION, array( 'bad' => array( get_template() => array( '99.0.0' ) ) ) );

		$this->release( '99.0.0', 7 * HOUR_IN_SECONDS );
		$this->assertNull( $this->offered() );

		delete_option( devbase_warden_state_option( get_template() ) ); // past Warden's 12-hour cache
		$this->release( '99.0.1', 7 * HOUR_IN_SECONDS );
		$this->assertSame( '99.0.1', $this->offered() );
	}

	// --- shown on the Licence status tab ----------------------------------------------------------

	public function test_the_status_tab_says_when_the_site_installs_it() {
		$release = $this->release( '99.0.0', 0 );
		$due     = devbase_update_rollout_due_at( get_template(), $release );
		if ( $due <= time() ) {
			$this->markTestSkipped( 'This site address falls in the first seconds of the window.' );
		}
		devbase_warden_check( get_template(), self::LICENCE, devbase_update_installed_version( get_template() ), true );

		$row = devbase_licence_status_row( get_template(), self::LICENCE );
		$this->assertStringContainsString( esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $due ) ), $row );
		$this->assertStringContainsString( '99.0.0', $row );
	}

	public function test_the_due_event_refreshes_the_update_list() {
		add_filter( 'devbase/updates/rollout_runs_auto_update', '__return_false' );
		$this->release( '99.0.0', 7 * HOUR_IN_SECONDS );
		// A list checked a moment ago, without the release: what the event finds at the due time.
		set_site_transient( 'update_themes', (object) array( 'last_checked' => time(), 'response' => array() ) );
		delete_option( devbase_warden_state_option( get_template() ) );

		do_action( DEVBASE_UPDATE_ROLLOUT_HOOK, get_template() );

		$transient = get_site_transient( 'update_themes' );
		$this->assertSame( '99.0.0', $transient->response[ get_template() ]['new_version'] ?? null );
	}
}
