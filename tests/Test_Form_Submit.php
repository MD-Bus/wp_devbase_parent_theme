<?php
// Covers the shared form steps added in 1.9.0: devbase_form_submit() (submit.php),
// devbase_form_rate_limited() (rate-limit.php), devbase_form_fields() (fields.php)
// and the $data argument of the pipeline. Above all, the fail-closed rule: with no
// valid nonce, or with a CAPTCHA provider on and no good token, nothing is stored.

class Test_Form_Submit extends WP_UnitTestCase {

	/** Calls to a CAPTCHA provider's verify endpoint during a test. */
	private $captcha_calls = 0;

	public function set_up() {
		parent::set_up();
		$this->captcha_calls = 0;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	public function tear_down() {
		update_field( 'turnstile_enabled', 0, 'option' );
		update_field( 'recaptcha_enabled', 0, 'option' );
		unset( $_POST['_devbase_form_nonce'], $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function nonce( $form = 'devbase_test_form' ) {
		return wp_create_nonce( 'devbase_form_' . $form );
	}

	private function args( array $data, array $extra = array() ) {
		return array_merge( array(
			'data'     => $data,
			'fields'   => array(
				'name'  => 'Name',
				'email' => array( 'label' => 'Email', 'sanitize' => 'sanitize_email' ),
			),
			'validate' => function ( $values ) {
				$errors = array();
				if ( mb_strlen( $values['name'] ) < 2 ) {
					$errors['name'] = 'Please enter your name.';
				}
				if ( ! is_email( $values['email'] ) ) {
					$errors['email'] = 'Enter a valid email address.';
				}
				return $errors;
			},
		), $extra );
	}

	private function good( array $more = array() ) {
		return array_merge( array( '_devbase_form_nonce' => $this->nonce(), 'name' => 'Ada Lovelace', 'email' => 'ada@example.org' ), $more );
	}

	private function stored() {
		return get_posts( array( 'post_type' => 'form_data', 'post_status' => 'any', 'numberposts' => -1 ) );
	}

	/** Turns Turnstile on and answers its verify endpoint with $success. */
	private function turnstile( $success ) {
		update_field( 'turnstile_enabled', 1, 'option' );
		update_field( 'turnstile_secret_key', 'test-secret', 'option' );

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $success ) {
			if ( false === strpos( $url, 'challenges.cloudflare.com' ) ) {
				return $pre;
			}
			++$this->captcha_calls;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => $success ) ), 'headers' => array(), 'cookies' => array() );
		}, 10, 3 );
	}

	public function test_valid_submission_is_stored_and_answered() {
		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good(), array(
			'success' => function ( $values ) { return array( 'name' => strtok( $values['name'], ' ' ) ); },
		) ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'name' => 'Ada' ), $result['data'] );

		$posts = $this->stored();
		$this->assertCount( 1, $posts );
		$this->assertSame( 'devbase_test_form', get_post_meta( $posts[0]->ID, 'form_name', true ) );
		$this->assertSame(
			array( 'name' => array( 'aria_label' => 'Name', 'value' => 'Ada Lovelace' ), 'email' => array( 'aria_label' => 'Email', 'value' => 'ada@example.org' ) ),
			json_decode( get_post_meta( $posts[0]->ID, 'content', true ), true )
		);
	}

	public function test_only_the_listed_fields_are_stored() {
		devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( 'is_admin' => '1' ) ) ) );

		$content = json_decode( get_post_meta( $this->stored()[0]->ID, 'content', true ), true );
		$this->assertSame( array( 'name', 'email' ), array_keys( $content ) );
	}

	public function test_honeypot_answers_success_and_stores_nothing() {
		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( 'website' => 'http://spam.example' ) ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertEmpty( $this->stored() );
	}

	public function test_missing_or_foreign_nonce_is_refused() {
		foreach ( array( '', 'not-a-nonce', $this->nonce( 'some_other_form' ) ) as $nonce ) {
			$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( '_devbase_form_nonce' => $nonce ) ) ) );

			$this->assertSame( 403, $result['status'] );
			$this->assertSame( 'blocked', $result['data']['code'] );
		}

		$this->assertEmpty( $this->stored() );
	}

	public function test_invalid_fields_are_all_reported() {
		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( 'name' => 'A', 'email' => 'nope' ) ) ) );

		$this->assertSame( 422, $result['status'] );
		$this->assertSame( 'invalid', $result['data']['code'] );
		$this->assertSame( 'name', $result['data']['field'] );
		$this->assertSame( array( 'name', 'email' ), wp_list_pluck( $result['data']['errors'], 'field' ) );
		$this->assertEmpty( $this->stored() );
	}

	public function test_validation_does_not_spend_the_captcha_token() {
		$this->turnstile( true );

		devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( 'email' => 'nope', 'cf-turnstile-response' => 'token' ) ) ) );

		$this->assertSame( 0, $this->captcha_calls, 'A submission sent back for a typo must not use up its one-time token.' );
	}

	public function test_captcha_on_without_token_is_refused() {
		$this->turnstile( true );

		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good() ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertEmpty( $this->stored() );
	}

	public function test_captcha_on_with_rejected_token_is_refused() {
		$this->turnstile( false );

		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( 'cf-turnstile-response' => 'token' ) ) ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertSame( 1, $this->captcha_calls );
		$this->assertEmpty( $this->stored() );
	}

	public function test_captcha_on_with_good_token_is_stored() {
		$this->turnstile( true );

		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( 'cf-turnstile-response' => 'token' ) ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->stored() );
	}

	public function test_rate_limit_refuses_the_sixth_request_and_counts_every_request() {
		// Invalid ones count too, so guessing costs the same as submitting.
		for ( $i = 0; $i < 3; $i++ ) {
			devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( '_devbase_form_nonce' => '' ) ) ) );
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$this->assertTrue( devbase_form_submit( 'devbase_test_form', $this->args( $this->good() ) )['success'] );
		}

		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good() ) );

		$this->assertSame( 429, $result['status'] );
		$this->assertSame( 'rate_limited', $result['data']['code'] );
		$this->assertCount( 2, $this->stored() );
	}

	public function test_rate_limit_is_per_form_and_per_visitor() {
		for ( $i = 0; $i < 5; $i++ ) {
			devbase_form_rate_limited( 'devbase_test_form' );
		}
		$this->assertTrue( devbase_form_rate_limited( 'devbase_test_form' ) );
		$this->assertFalse( devbase_form_rate_limited( 'newsletter' ), 'Another form has its own count.' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->assertFalse( devbase_form_rate_limited( 'devbase_test_form' ), 'Another visitor has its own count.' );
	}

	public function test_rate_limit_window_ends() {
		for ( $i = 0; $i < 6; $i++ ) {
			devbase_form_rate_limited( 'devbase_test_form' );
		}

		// Move the window's start back past its end.
		$key = 'devbase_form_rl_' . md5( 'devbase_test_form|203.0.113.7' );
		set_transient( $key, array( 'start' => time() - 11 * MINUTE_IN_SECONDS, 'count' => 5 ), MINUTE_IN_SECONDS );

		$this->assertFalse( devbase_form_rate_limited( 'devbase_test_form' ) );
	}

	public function test_rate_limit_filter_changes_or_disables_it() {
		$limit = function ( $limit, $form ) { return 'devbase_test_form' === $form ? array( 'max' => 1, 'window' => 60 ) : $limit; };
		add_filter( 'devbase/forms/rate_limit', $limit, 10, 2 );

		$this->assertFalse( devbase_form_rate_limited( 'devbase_test_form' ) );
		$this->assertTrue( devbase_form_rate_limited( 'devbase_test_form' ) );

		remove_filter( 'devbase/forms/rate_limit', $limit, 10 );
		add_filter( 'devbase/forms/rate_limit', function () { return array( 'max' => 0 ); } );

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertFalse( devbase_form_rate_limited( 'newsletter' ) );
		}
	}

	public function test_log_submission_applies_the_rate_limit_too() {
		$_POST['_devbase_form_nonce'] = $this->nonce();
		$content = array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertIsInt( devbase_form_log_submission( 'devbase_test_form', $content ) );
		}

		$this->assertFalse( devbase_form_log_submission( 'devbase_test_form', $content ) );
	}

	public function test_log_submission_reads_the_data_it_is_given() {
		// $_POST holds no nonce; the data passed in does.
		$content = array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) );

		$this->assertFalse( devbase_form_log_submission( 'devbase_test_form', $content ) );
		$this->assertIsInt( devbase_form_log_submission( 'devbase_test_form', $content, array( '_devbase_form_nonce' => $this->nonce() ) ) );
	}

	public function test_before_log_can_accept_without_storing_and_after_log_gets_the_post() {
		$result = devbase_form_submit( 'devbase_test_form', $this->args( $this->good(), array( 'before_log' => '__return_false' ) ) );
		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $this->stored() );

		$seen = null;
		devbase_form_submit( 'devbase_test_form', $this->args( $this->good(), array(
			'after_log' => function ( $post_id ) use ( &$seen ) { $seen = $post_id; },
		) ) );
		$this->assertSame( $this->stored()[0]->ID, $seen );
	}

	public function test_before_log_runs_only_after_the_captcha() {
		$this->turnstile( false );
		$ran = false;

		devbase_form_submit( 'devbase_test_form', $this->args( $this->good( array( 'cf-turnstile-response' => 'x' ) ), array(
			'before_log' => function () use ( &$ran ) { $ran = true; },
		) ) );

		$this->assertFalse( $ran );
	}

	public function test_fields_print_a_verifiable_nonce_the_honeypot_and_the_action() {
		$html = devbase_form_fields( 'devbase_test_form', array( 'action' => 'my_action' ) );

		$this->assertStringContainsString( '<input type="hidden" name="action" value="my_action">', $html );
		$this->assertStringContainsString( 'name="website"', $html );
		$this->assertStringNotContainsString( 'id="_devbase_form_nonce"', $html );

		$this->assertSame( 1, preg_match( '/name="_devbase_form_nonce" value="([^"]+)"/', $html, $m ) );
		$this->assertTrue( devbase_form_nonce_valid( 'devbase_test_form', array( '_devbase_form_nonce' => $m[1] ) ) );
		$this->assertFalse( devbase_form_nonce_valid( 'newsletter', array( '_devbase_form_nonce' => $m[1] ) ) );

		$this->assertTrue( wp_script_is( 'devbase-forms', 'enqueued' ) );
	}

	public function test_fields_include_the_active_captcha() {
		update_field( 'turnstile_enabled', 1, 'option' );
		update_field( 'turnstile_site_key', 'site-key', 'option' );

		$this->assertStringContainsString( 'class="cf-turnstile" data-sitekey="site-key"', devbase_form_fields( 'devbase_test_form' ) );
		$this->assertStringNotContainsString( 'cf-turnstile', devbase_form_fields( 'devbase_test_form', array( 'captcha' => false ) ) );
	}

	public function test_send_replies_with_json_and_the_status() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', function () { return function () { throw new Exception( 'sent' ); }; } );

		ob_start();
		try {
			devbase_form_send( 'devbase_test_form', $this->args( $this->good( array( 'email' => 'nope' ) ) ) );
		} catch ( Exception $e ) {
			$this->assertSame( 'sent', $e->getMessage() );
		}
		$reply = json_decode( ob_get_clean(), true );

		$this->assertFalse( $reply['success'] );
		$this->assertSame( 'email', $reply['data']['field'] );
	}
}
