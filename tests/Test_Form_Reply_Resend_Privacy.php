<?php
// Covers three things a form submission gained in 1.40.0: a Reply-To set to the submitter on SMTP
// notifications (inc/forms/dispatch/smtp.php), the Resend link on a submission
// (inc/forms/dispatch/orchestrator.php, inc/forms/form-data-admin-display.php), and WordPress's personal data
// export and erasure (inc/forms/privacy.php).

class Test_Form_Reply_Resend_Privacy extends WP_UnitTestCase {

	/** @var array[] every wp_mail() call, as WordPress passes it to the `wp_mail` filter */
	private $mails = array();

	/** @var string[] webhook URLs sent to */
	private $sent = array();

	public function set_up() {
		parent::set_up();

		$this->mails = array();
		$this->sent  = array();
		$_POST['_devbase_form_nonce'] = wp_create_nonce( 'devbase_form_privacy_form' );
		$_SERVER['REMOTE_ADDR']       = '203.0.113.' . wp_rand( 1, 250 );

		add_filter( 'wp_mail', array( $this, 'capture' ) );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		devbase_forms_dispatch_later();
	}

	public function tear_down() {
		remove_filter( 'wp_mail', array( $this, 'capture' ) );
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		update_field( 'destinations', array(), 'option' );
		devbase_forms_dispatch_later();
		unset( $_POST['_devbase_form_nonce'], $_SERVER['REMOTE_ADDR'], $_GET['devbase_resent'] );

		parent::tear_down();
	}

	public function capture( $atts ) {
		$this->mails[] = $atts;

		return $atts;
	}

	public function http( $pre, $args, $url ) {
		$this->sent[] = $url;

		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => '' ) );
	}

	private function content( $email = 'jane@example.com', $key = 'email' ) {
		return array(
			'name' => array( 'aria_label' => 'Name', 'value' => 'Jane' ),
			$key   => array( 'aria_label' => 'Email', 'value' => $email ),
		);
	}

	private function headers_of( array $mail ) {
		return implode( "\n", (array) $mail['headers'] );
	}

	// --- Reply-To ----------------------------------------------------------------------------------

	public function test_a_notification_replies_to_the_person_who_filled_in_the_form() {
		devbase_forms_dispatch_smtp( array( 'label' => 'Office', 'to_email' => 'office@site.test' ), 'contact', $this->content() );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Reply-To: jane@example.com', $this->headers_of( $this->mails[0] ) );
	}

	public function test_a_field_whose_name_contains_email_is_used_when_there_is_no_email_field() {
		$this->assertSame( 'jane@example.com', devbase_forms_reply_to( $this->content( 'jane@example.com', 'your_email' ) ) );
	}

	public function test_the_field_named_email_wins_over_others() {
		$content = array(
			'work_email' => array( 'aria_label' => 'Work', 'value' => 'work@example.com' ),
			'email'      => array( 'aria_label' => 'Email', 'value' => 'jane@example.com' ),
		);

		$this->assertSame( 'jane@example.com', devbase_forms_reply_to( $content ) );
	}

	public function test_no_reply_to_without_a_valid_address() {
		devbase_forms_dispatch_smtp( array( 'label' => 'Office', 'to_email' => 'office@site.test' ), 'contact', $this->content( 'not an address' ) );

		$this->assertStringNotContainsString( 'Reply-To', $this->headers_of( $this->mails[0] ) );
	}

	public function test_a_value_carrying_a_header_cannot_reach_the_headers() {
		$this->assertSame( '', devbase_forms_reply_to( $this->content( "jane@example.com\r\nBcc: all@example.com" ) ) );

		add_filter( 'devbase/forms/reply_to', function () {
			return "jane@example.com\nBcc: x@example.com";
		} );
		$this->assertSame( '', devbase_forms_reply_to( $this->content() ), 'Even a filter cannot put a line break in.' );
	}

	public function test_the_filter_can_choose_the_address() {
		add_filter( 'devbase/forms/reply_to', function () {
			return 'other@example.com';
		} );

		$this->assertSame( 'other@example.com', devbase_forms_reply_to( $this->content() ) );
	}

	// --- Resend ------------------------------------------------------------------------------------

	private function webhook( $url ) {
		update_field( 'destinations', array(
			array( 'label' => $url, 'type' => 'webhook', 'url' => $url, 'auth_value' => '', 'auth_header_name' => '', 'apply_to' => array( 'privacy_form' ) ),
		), 'option' );
	}

	public function test_resend_sends_again_to_a_destination_that_already_received_it() {
		$this->webhook( 'https://a.test/hook' );
		$post_id = devbase_form_log_submission( 'privacy_form', $this->content() );
		$this->assertSame( array( 'https://a.test/hook' ), $this->sent );

		$this->assertTrue( devbase_forms_resend( $post_id ) );

		$this->assertSame( array( 'https://a.test/hook', 'https://a.test/hook' ), $this->sent );
		$this->assertSame( 'done', get_post_meta( $post_id, '_devbase_dispatched', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, '_devbase_resent_at', true ) );

		$log = json_decode( get_post_meta( $post_id, '_devbase_dispatch_log', true ), true );
		$this->assertSame( 1, $log[0]['attempts'], 'A resend starts from the beginning: the tries of before are cleared.' );
	}

	public function test_resend_gives_a_destination_out_of_tries_another_chance() {
		$this->webhook( 'https://a.test/hook' );
		$post_id = devbase_form_log_submission( 'privacy_form', $this->content() );
		update_post_meta( $post_id, '_devbase_dispatch_log', wp_slash( wp_json_encode( array(
			array( 'success' => false, 'label' => 'x', 'message' => 'failed', 'key' => md5( 'webhook|https://a.test/hook' ), 'attempts' => 3 ),
		) ) ) );
		$this->sent = array();

		devbase_forms_resend( $post_id );

		$this->assertSame( array( 'https://a.test/hook' ), $this->sent );
	}

	public function test_resend_refuses_anything_but_a_submission() {
		$this->assertFalse( devbase_forms_resend( self::factory()->post->create() ) );
	}

	public function test_the_resend_link_is_shown_to_an_administrator_with_a_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = devbase_form_store( 'privacy_form', $this->content() ); // stored directly: the form nonce belongs to the user it was made for

		ob_start();
		devbase_forms_render_submission_meta_box( get_post( $post_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'action=devbase_forms_resend', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertTrue( (bool) wp_verify_nonce( wp_parse_args( wp_parse_url( html_entity_decode( devbase_forms_resend_url( $post_id ) ), PHP_URL_QUERY ) )['_wpnonce'], 'devbase_forms_resend_' . $post_id ) );
	}

	public function test_the_resend_link_is_not_shown_to_an_editor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = devbase_form_store( 'privacy_form', $this->content() ); // stored directly: the form nonce belongs to the user it was made for

		ob_start();
		devbase_forms_render_submission_meta_box( get_post( $post_id ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'devbase_forms_resend', $html );
	}

	public function test_the_resend_handler_refuses_a_user_without_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = devbase_form_store( 'privacy_form', $this->content() ); // stored directly: the form nonce belongs to the user it was made for
		$_GET['post']     = $post_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'devbase_forms_resend_' . $post_id );

		add_filter( 'wp_die_handler', function () {
			return function ( $message ) {
				throw new Exception( 'died: ' . ( is_string( $message ) ? $message : '' ) );
			};
		} );

		try {
			do_action( 'admin_post_devbase_forms_resend' );
			$this->fail( 'The handler must stop.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( esc_html__( 'You are not allowed to resend this submission.', 'devbase_parent_theme' ), $e->getMessage() );
		} finally {
			unset( $_GET['post'], $_REQUEST['_wpnonce'] );
		}

		$this->assertSame( '', get_post_meta( $post_id, '_devbase_resent_at', true ) );
	}

	// --- personal data -----------------------------------------------------------------------------

	public function test_the_exporter_and_eraser_are_registered() {
		$this->assertArrayHasKey( 'devbase-form-submissions', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'devbase-form-submissions', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_the_export_holds_every_submission_with_that_address_and_no_other() {
		$mine   = devbase_form_store( 'privacy_form', $this->content( 'Jane@Example.com' ) );
		$also   = devbase_form_store( 'privacy_form', $this->content( 'jane@example.com', 'contact_email' ) );
		$theirs = devbase_form_store( 'privacy_form', $this->content( 'ajane@example.com' ) );

		$export = devbase_forms_privacy_export( 'jane@example.com', 1 );

		$this->assertTrue( $export['done'] );
		$this->assertSame( array( 'form-submission-' . $mine, 'form-submission-' . $also ), array_column( $export['data'], 'item_id' ), 'Compared without case, and never a longer address that contains it.' );
		$this->assertNotContains( 'form-submission-' . $theirs, array_column( $export['data'], 'item_id' ) );

		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'privacy_form', $fields[ __( 'Form', 'devbase_parent_theme' ) ] );
		$this->assertSame( 'Jane', $fields['Name'] );
	}

	public function test_the_export_pages() {
		for ( $i = 0; $i < DEVBASE_FORMS_PRIVACY_PAGE + 2; $i++ ) {
			devbase_form_store( 'privacy_form', $this->content() );
		}

		$first  = devbase_forms_privacy_export( 'jane@example.com', 1 );
		$second = devbase_forms_privacy_export( 'jane@example.com', 2 );

		$this->assertCount( DEVBASE_FORMS_PRIVACY_PAGE, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 2, $second['data'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_erasure_deletes_that_persons_submissions_only() {
		$mine   = devbase_form_store( 'privacy_form', $this->content() );
		$theirs = devbase_form_store( 'privacy_form', $this->content( 'someone@example.com' ) );

		$erased = devbase_forms_privacy_erase( 'jane@example.com', 1 );

		$this->assertTrue( $erased['items_removed'] );
		$this->assertFalse( $erased['items_retained'] );
		$this->assertTrue( $erased['done'] );
		$this->assertNull( get_post( $mine ) );
		$this->assertNotNull( get_post( $theirs ) );
	}

	public function test_erasure_carries_on_over_pages_until_nothing_is_left() {
		for ( $i = 0; $i < DEVBASE_FORMS_PRIVACY_PAGE + 3; $i++ ) {
			devbase_form_store( 'privacy_form', $this->content() );
		}

		$first = devbase_forms_privacy_erase( 'jane@example.com', 1 );
		$this->assertFalse( $first['done'] );

		$second = devbase_forms_privacy_erase( 'jane@example.com', 2 );
		$this->assertTrue( $second['done'] );
		$this->assertSame( array(), devbase_forms_privacy_find( 'jane@example.com' ) );
	}

	public function test_an_invalid_address_finds_nothing() {
		devbase_form_store( 'privacy_form', $this->content() );

		$this->assertSame( array(), devbase_forms_privacy_find( '' ) );
		$this->assertSame( array(), devbase_forms_privacy_find( 'jane' ) );
	}
}
