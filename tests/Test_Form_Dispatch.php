<?php
// Covers inc/forms/dispatch/orchestrator.php + dispatch/webhook.php: the
// _devbase_dispatched double-fire guard, a destination row whose
// "Applies To" doesn't include the submitted form being skipped, and the
// webhook sender's JSON body/header shape — verified against WordPress's
// own pre_http_request filter (fakes the network call before
// wp_remote_post() hits it), the same approach smtp/test.php already uses
// for wp_mail_failed.
//
// Dispatch matches purely on form_name string against each destination
// row's `apply_to` array (inc/theme-settings/destinations/), so these
// tests don't need a registered_form post to exist at all — that CPT only
// feeds the "Applies To" picker's choices in wp-admin, not the matching
// logic itself.

class Test_Form_Dispatch extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Every devbase_form_log_submission() call in this file is for
		// 'dispatch_test_form' — set the matching valid nonce once here
		// rather than repeating it in each test.
		$_POST['_devbase_form_nonce'] = wp_create_nonce( 'devbase_form_dispatch_test_form' );
	}

	public function tear_down() {
		update_field( 'destinations', array(), 'option' );
		unset( $_POST['_devbase_form_nonce'] );
		parent::tear_down();
	}

	public function test_dispatch_skips_when_no_destination_applies() {
		update_field( 'destinations', array(
			array( 'label' => 'Wrong Form', 'type' => 'webhook', 'url' => 'https://example.test/hook', 'apply_to' => array( 'some_other_form' ) ),
		), 'option' );

		$post_id = devbase_form_log_submission( 'dispatch_test_form', array(
			'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ),
		) );

		$this->assertIsInt( $post_id );
		$this->assertTrue( (bool) get_post_meta( $post_id, '_devbase_dispatched', true ) );

		$log = json_decode( get_post_meta( $post_id, '_devbase_dispatch_log', true ), true );
		$this->assertFalse( $log[0]['success'] );
		$this->assertStringContainsString( 'No destination applies', $log[0]['message'] );
	}

	public function test_dispatch_only_fires_once_per_post() {
		$post_id = self::factory()->post->create( array(
			'post_type'  => 'form_data',
			'meta_input' => array(
				'form_name' => 'dispatch_test_form',
				'content'   => wp_json_encode( array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) ) ),
			),
		) );

		$this->assertTrue( (bool) get_post_meta( $post_id, '_devbase_dispatched', true ), 'save_post_form_data should have dispatched (and flagged) on insert.' );

		// Manually re-saving shouldn't dispatch a second time.
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Edited' ) );

		$this->assertTrue( (bool) get_post_meta( $post_id, '_devbase_dispatched', true ) );
	}

	public function test_webhook_dispatch_sends_expected_json_body_once() {
		update_field( 'destinations', array(
			array( 'label' => 'Test', 'type' => 'webhook', 'url' => 'https://example.test/hook', 'auth_value' => '', 'auth_header_name' => '', 'apply_to' => array( 'dispatch_test_form' ) ),
		), 'option' );

		$captured = array();
		$filter   = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured[] = array( 'url' => $url, 'body' => $args['body'], 'headers' => $args['headers'] );
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$content = array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) );
		$post_id = devbase_form_log_submission( 'dispatch_test_form', $content );

		// Re-saving should NOT call the webhook again, thanks to the
		// _devbase_dispatched guard.
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Edited Again' ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertCount( 1, $captured, 'Webhook should fire exactly once per submission, not again on manual re-save.' );
		$this->assertSame( 'https://example.test/hook', $captured[0]['url'] );

		$body = json_decode( $captured[0]['body'], true );
		$this->assertSame( 'dispatch_test_form', $body['form_name'] );
		$this->assertSame( $content, $body['data'] );

		$log = json_decode( get_post_meta( $post_id, '_devbase_dispatch_log', true ), true );
		$this->assertTrue( $log[0]['success'], 'Dispatch log should record the successful send for the admin Submission meta box.' );
	}

	public function test_webhook_dispatch_sends_configured_auth_header() {
		update_field( 'destinations', array(
			array( 'label' => 'Authed', 'type' => 'webhook', 'url' => 'https://example.test/hook', 'auth_value' => 'sekret-token', 'auth_header_name' => 'X-API-Key', 'apply_to' => array( 'dispatch_test_form' ) ),
		), 'option' );

		$captured = null;
		$filter   = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = $args['headers'];
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		devbase_form_log_submission( 'dispatch_test_form', array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( 'sekret-token', $captured['X-API-Key'] ?? null, 'The configured header name should carry the Password/Key value.' );
	}

	public function test_destination_only_dispatches_to_forms_it_applies_to() {
		// Two destinations, each applying to a different form — submitting
		// for "dispatch_test_form" must only fire the one that lists it.
		update_field( 'destinations', array(
			array( 'label' => 'For Other Form', 'type' => 'webhook', 'url' => 'https://example.test/other', 'apply_to' => array( 'some_other_form' ) ),
			array( 'label' => 'For This Form', 'type' => 'webhook', 'url' => 'https://example.test/mine', 'apply_to' => array( 'dispatch_test_form' ) ),
		), 'option' );

		$captured = array();
		$filter   = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured[] = $url;
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		devbase_form_log_submission( 'dispatch_test_form', array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( array( 'https://example.test/mine' ), $captured );
	}
}
