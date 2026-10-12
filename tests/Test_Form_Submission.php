<?php
// Covers devbase_form_log_submission() (inc/helpers/forms/log-submission.php):
// the CSRF nonce gate and the spam gate both run before anything is
// logged, and a successful call round-trips form_name/content correctly
// through form_data postmeta.

class Test_Form_Submission extends WP_UnitTestCase {

	public function tear_down() {
		update_field( 'turnstile_enabled', 0, 'option' );
		update_field( 'recaptcha_enabled', 0, 'option' );
		unset( $_POST['_devbase_form_nonce'] );
		parent::tear_down();
	}

	/**
	 * Sets $_POST['_devbase_form_nonce'] to a real, valid nonce for the
	 * given form name — the same field devbase_form_nonce_field() renders
	 * and devbase_form_log_submission() verifies.
	 */
	private function set_valid_nonce( $form_name ) {
		$_POST['_devbase_form_nonce'] = wp_create_nonce( 'devbase_form_' . $form_name );
	}

	public function test_blocks_submission_with_missing_nonce() {
		// No nonce set at all.
		$result = devbase_form_log_submission( 'devbase_test_form', array(
			'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ),
		) );

		$this->assertFalse( $result );

		$posts = get_posts( array( 'post_type' => 'form_data', 'post_status' => 'any' ) );
		$this->assertEmpty( $posts, 'No form_data post should be created without a valid nonce.' );
	}

	public function test_blocks_submission_with_nonce_for_a_different_form() {
		// A nonce is valid, but for a DIFFERENT form name — proves the
		// action is genuinely namespaced per form, not just "any nonce".
		$this->set_valid_nonce( 'some_other_form' );

		$result = devbase_form_log_submission( 'devbase_test_form', array(
			'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ),
		) );

		$this->assertFalse( $result );
	}

	public function test_blocks_submission_when_spam_gate_fails() {
		$this->set_valid_nonce( 'devbase_test_form' );

		update_field( 'turnstile_enabled', 1, 'option' );
		update_field( 'turnstile_secret_key', 'test-secret', 'option' );
		// No CAPTCHA token submitted — devbase_security_verify_request() should fail.

		$result = devbase_form_log_submission( 'devbase_test_form', array(
			'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ),
		) );

		$this->assertFalse( $result );

		$posts = get_posts( array( 'post_type' => 'form_data', 'post_status' => 'any' ) );
		$this->assertEmpty( $posts, 'No form_data post should be created when the spam gate blocks the submission.' );
	}

	public function test_rejects_empty_form_name_or_content() {
		$this->assertFalse( devbase_form_log_submission( '', array( 'a' => array( 'value' => '1' ) ) ) );

		$this->set_valid_nonce( 'some_form' );
		$this->assertFalse( devbase_form_log_submission( 'some_form', array() ) );
	}

	public function test_content_with_literal_quotes_round_trips_correctly() {
		// Regression test: update_post_meta()/wp_insert_post()'s meta_input
		// both run values through wp_unslash() before storing, which
		// silently strips the \" escapes wp_json_encode() adds around a
		// literal quote in submitted text — corrupting the stored JSON —
		// unless the encoded string is wp_slash()'d first (see log-submission.php).
		$this->set_valid_nonce( 'devbase_test_form' );

		$content = array(
			'message' => array( 'aria_label' => 'Message', 'value' => 'He said "hello" to me' ),
		);

		$post_id = devbase_form_log_submission( 'devbase_test_form', $content );

		$this->assertIsInt( $post_id );
		$decoded = json_decode( get_post_meta( $post_id, 'content', true ), true );
		$this->assertNotNull( $decoded, 'Stored content must still be valid, decodable JSON when a submitted value contains a quote character.' );
		$this->assertSame( $content, $decoded );
	}

	public function test_logs_submission_with_correct_content_when_no_provider_active() {
		$this->set_valid_nonce( 'devbase_test_form' );

		$content = array(
			'email'   => array( 'aria_label' => 'Email Address', 'value' => 'a@b.com' ),
			'message' => array( 'aria_label' => 'Message', 'value' => 'Hello there' ),
		);

		$post_id = devbase_form_log_submission( 'devbase_test_form', $content );

		$this->assertIsInt( $post_id );
		$this->assertSame( 'devbase_test_form', get_post_meta( $post_id, 'form_name', true ) );
		$this->assertSame( $content, json_decode( get_post_meta( $post_id, 'content', true ), true ) );
	}
}
