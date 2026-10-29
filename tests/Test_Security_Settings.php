<?php
// Automates what was previously verified by hand via a one-off WP-CLI-style
// script every time inc/theme-settings/security/ changed (see the session
// history in AGENT.md) — the mutual-exclusivity guarantee between Turnstile
// and reCAPTCHA, and the devbase_security_* functions forms call.

class Test_Security_Settings extends WP_UnitTestCase {

	private $http_filter = null;
	private $http_requests = array();

	public function tear_down() {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}

		$this->http_requests = array();
		update_field( 'turnstile_enabled', 0, 'option' );
		update_field( 'recaptcha_enabled', 0, 'option' );
		update_field( 'recaptcha_site_key', '', 'option' );
		update_field( 'recaptcha_secret_key', '', 'option' );
		update_field( 'recaptcha_min_score', '', 'option' );
		parent::tear_down();
	}

	/** Enables reCAPTCHA with test keys and fakes Google's siteverify answer. */
	private function recaptcha_answers( array $answer ) {
		update_field( 'recaptcha_enabled', 1, 'option' );
		update_field( 'recaptcha_site_key', 'test-site-key', 'option' );
		update_field( 'recaptcha_secret_key', 'test-secret', 'option' );

		$this->http_filter = function ( $preempt, $args, $url ) use ( $answer ) {
			$this->http_requests[] = array( 'url' => $url, 'args' => $args );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( $answer ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	public function test_no_provider_active_by_default() {
		$this->assertNull( devbase_security_active_provider() );
		$this->assertSame( '', devbase_security_widget_html() );
		$this->assertTrue( devbase_security_verify( 'anything' ) );
	}

	public function test_turnstile_becomes_active_provider_when_enabled() {
		update_field( 'turnstile_enabled', 1, 'option' );
		update_field( 'turnstile_site_key', '1x00000000000000000000AA', 'option' );

		$this->assertSame( 'turnstile', devbase_security_active_provider() );
		$this->assertStringContainsString( '1x00000000000000000000AA', devbase_security_widget_html() );
	}

	public function test_recaptcha_becomes_active_provider_when_enabled() {
		update_field( 'recaptcha_enabled', 1, 'option' );
		update_field( 'recaptcha_site_key', 'test-site-key', 'option' );

		$this->assertSame( 'recaptcha', devbase_security_active_provider() );
		$this->assertStringContainsString( 'test-site-key', devbase_security_widget_html() );
	}

	// --- reCAPTCHA v3 ------------------------------------------------------

	public function test_recaptcha_widget_is_an_invisible_token_field_not_a_checkbox() {
		update_field( 'recaptcha_enabled', 1, 'option' );
		update_field( 'recaptcha_site_key', 'test-site-key', 'option' );

		$html = devbase_security_widget_html();

		$this->assertStringContainsString( 'type="hidden"', $html );
		$this->assertStringContainsString( 'name="g-recaptcha-response"', $html );
		$this->assertStringContainsString( 'data-sitekey="test-site-key"', $html );
		$this->assertStringContainsString( 'data-action="submit"', $html );
		$this->assertStringNotContainsString( 'g-recaptcha"', str_replace( 'name="g-recaptcha-response"', '', $html ), 'The v2 checkbox box (class g-recaptcha) must not be rendered.' );
	}

	public function test_recaptcha_loads_googles_v3_script_with_the_site_key_and_our_token_script() {
		update_field( 'recaptcha_enabled', 1, 'option' );
		update_field( 'recaptcha_site_key', 'test-site-key', 'option' );
		wp_dequeue_script( 'devbase-recaptcha' );

		devbase_security_widget_html();

		$google = wp_scripts()->registered['devbase-recaptcha'];
		$this->assertSame( 'https://www.google.com/recaptcha/api.js?render=test-site-key', $google->src );
		$this->assertNull( $google->ver, 'A null version means WordPress adds no ?ver= to Google\'s script.' );
		$this->assertTrue( wp_script_is( 'devbase-recaptcha-v3', 'enqueued' ) );
		$this->assertFileExists( get_template_directory() . '/assets/js/recaptcha-v3.js' );
	}

	public function test_a_high_score_for_the_right_action_passes() {
		$this->recaptcha_answers( array( 'success' => true, 'score' => 0.9, 'action' => 'submit' ) );

		$this->assertTrue( devbase_security_verify( 'a-token' ) );
	}

	public function test_a_low_score_is_blocked() {
		$this->recaptcha_answers( array( 'success' => true, 'score' => 0.2, 'action' => 'submit' ) );

		$this->assertFalse( devbase_security_verify( 'a-token' ) );
	}

	public function test_the_minimum_score_setting_moves_the_line() {
		$this->recaptcha_answers( array( 'success' => true, 'score' => 0.6, 'action' => 'submit' ) );

		$this->assertTrue( devbase_security_verify( 'a-token' ), '0.6 passes the default 0.5.' );

		update_field( 'recaptcha_min_score', 0.8, 'option' );
		$this->assertFalse( devbase_security_verify( 'a-token' ), '0.6 fails once the minimum is 0.8.' );

		update_field( 'recaptcha_min_score', 0.3, 'option' );
		$this->assertTrue( devbase_security_verify( 'a-token' ) );
	}

	public function test_the_default_minimum_score_is_half() {
		$this->assertSame( 0.5, devbase_security_recaptcha_min_score() );

		update_field( 'recaptcha_min_score', 'rubbish', 'option' );
		$this->assertSame( 0.5, devbase_security_recaptcha_min_score(), 'A nonsense value falls back to the default.' );

		update_field( 'recaptcha_min_score', 7, 'option' );
		$this->assertSame( 0.5, devbase_security_recaptcha_min_score(), 'A value above 1 falls back to the default.' );
	}

	public function test_a_token_for_a_different_action_is_blocked() {
		$this->recaptcha_answers( array( 'success' => true, 'score' => 0.9, 'action' => 'login' ) );

		$this->assertFalse( devbase_security_verify( 'a-token' ) );
	}

	public function test_an_answer_with_no_score_is_not_a_v3_answer_and_fails() {
		$this->recaptcha_answers( array( 'success' => true ) );

		$this->assertFalse( devbase_security_verify( 'a-token' ), 'A v2 key answers success with no score; v3 must not accept that.' );
	}

	public function test_google_saying_not_successful_is_blocked_whatever_the_score() {
		$this->recaptcha_answers( array( 'success' => false, 'score' => 0.9, 'action' => 'submit', 'error-codes' => array( 'timeout-or-duplicate' ) ) );

		$this->assertFalse( devbase_security_verify( 'a-token' ) );
	}

	public function test_recaptcha_sends_the_secret_and_the_token_to_googles_siteverify() {
		$this->recaptcha_answers( array( 'success' => true, 'score' => 0.9, 'action' => 'submit' ) );

		devbase_security_verify( 'the-token' );

		$this->assertSame( 'https://www.google.com/recaptcha/api/siteverify', $this->http_requests[0]['url'] );
		$this->assertSame( 'test-secret', $this->http_requests[0]['args']['body']['secret'] );
		$this->assertSame( 'the-token', $this->http_requests[0]['args']['body']['response'] );
	}

	public function test_recaptcha_rejects_an_empty_token_without_asking_google() {
		$this->recaptcha_answers( array( 'success' => true, 'score' => 0.9, 'action' => 'submit' ) );

		$this->assertFalse( devbase_security_verify( '' ) );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_the_request_helper_reads_g_recaptcha_response() {
		$this->recaptcha_answers( array( 'success' => true, 'score' => 0.9, 'action' => 'submit' ) );

		$_POST['g-recaptcha-response'] = 'posted-token';
		$ok = devbase_security_verify_request();
		unset( $_POST['g-recaptcha-response'] );

		$this->assertTrue( $ok );
		$this->assertSame( 'posted-token', $this->http_requests[0]['args']['body']['response'] );
	}

	public function test_both_enabled_is_rejected_on_save() {
		$_POST['acf'] = array(
			'field_devbase_turnstile_enabled' => '1',
			'field_devbase_recaptcha_enabled' => '1',
		);

		acf_reset_validation_errors();
		do_action( 'acf/validate_save_post' );
		$errors = acf_get_validation_errors();

		unset( $_POST['acf'] );

		$this->assertNotEmpty( $errors, 'Saving both providers enabled at once should be blocked.' );
	}

	public function test_verify_fails_without_active_secret() {
		update_field( 'turnstile_enabled', 1, 'option' );
		// Deliberately no secret key set.

		$this->assertFalse( devbase_security_verify( 'some-token' ) );
	}
}
