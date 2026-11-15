<?php
// Covers inc/theme-settings/smtp/api.php and api-brevo.php: the Email API route (Brevo first). Every mail the site
// sends goes through it once it is on (wp_mail() itself, a form destination, the test button), it fires WordPress's own
// wp_mail_succeeded / wp_mail_failed so the failure log keeps working, only one way of sending is on at a time, and the
// API key is a secret like the SMTP password. No request leaves: pre_http_request answers for Brevo.

class Test_Mail_API extends WP_UnitTestCase {

	/** @var array[] The requests that would have gone to Brevo. */
	private $requests = array();

	/** @var array What the fake Brevo answers. */
	private $answer;

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->answer   = array( 'response' => array( 'code' => 201, 'message' => 'Created' ), 'body' => '{"messageId":"<1@smtp-relay.mailin.fr>"}', 'headers' => array(), 'cookies' => array() );

		add_filter( 'pre_http_request', array( $this, 'fake_brevo' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_brevo' ), 10 );

		foreach ( array( 'smtp_enabled', 'smtp_host', 'mail_api_enabled', 'mail_api_provider', 'mail_api_from_email', 'mail_api_from_name', 'brevo_api_key' ) as $name ) {
			delete_field( $name, 'option' ); // Also clears SCF's value cache, which delete_option() would not.
		}

		delete_option( DEVBASE_MAIL_LOG_OPTION );
		delete_option( 'devbase_smtp_last_test' );
		unset( $_POST['acf'] );

		parent::tear_down();
	}

	public function fake_brevo( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'api.brevo.com' ) ) {
			return $pre;
		}

		$this->requests[] = array( 'url' => $url, 'args' => $args, 'body' => json_decode( (string) $args['body'], true ) );

		return $this->answer;
	}

	private function brevo_on( $key = 'xkeysib-test-key' ) {
		update_field( 'mail_api_enabled', 1, 'option' );
		update_field( 'mail_api_provider', 'brevo', 'option' );
		update_field( 'mail_api_from_email', 'hello@nonprofit.test', 'option' );
		update_field( 'mail_api_from_name', 'The Nonprofit', 'option' );
		update_field( 'brevo_api_key', $key, 'option' );
	}

	public function test_off_by_default_wp_mail_is_left_alone() {
		$this->assertSame( '', devbase_mail_api_active_provider() );
		$this->assertNull( devbase_mail_api_pre_wp_mail( null, array( 'to' => 'a@b.test' ) ) );
		$this->assertSame( 'mail', devbase_mail_active_route() );
	}

	public function test_brevo_is_registered_and_offered_in_the_service_choice() {
		$this->assertArrayHasKey( 'brevo', devbase_mail_api_providers() );

		$fields   = devbase_mail_api_fields();
		$provider = array_values( array_filter( $fields, function ( $f ) {
			return 'field_devbase_mail_api_provider' === $f['key'];
		} ) )[0];

		$this->assertSame( 'Brevo', $provider['choices']['brevo'] );

		$key = array_values( array_filter( $fields, function ( $f ) {
			return 'field_devbase_brevo_api_key' === $f['key'];
		} ) )[0];

		// Shown only once the switch is on AND Brevo is the chosen service.
		$this->assertSame( 'field_devbase_mail_api_enabled', $key['conditional_logic'][0][0]['field'] );
		$this->assertSame( 'brevo', $key['conditional_logic'][0][1]['value'] );
	}

	public function test_the_page_has_the_smtp_tab_first_then_the_email_api_tab_with_its_banner() {
		$group = acf_get_local_field_group( 'group_devbase_notifications_smtp' );
		$this->assertNotEmpty( $group );

		$keys = array_column( acf_get_fields( 'group_devbase_notifications_smtp' ), 'key' );

		$this->assertSame( 'field_devbase_smtp_tab', $keys[0] );
		$this->assertLessThan( array_search( 'field_devbase_mail_api_tab', $keys, true ), array_search( 'field_devbase_smtp_test', $keys, true ) );
		$this->assertSame( array_search( 'field_devbase_mail_api_tab', $keys, true ) + 1, array_search( 'field_devbase_mail_api_banner', $keys, true ), 'The banner opens the Email API tab.' );
	}

	public function test_an_incomplete_setup_does_not_take_over_mail() {
		$this->brevo_on( '' );
		$this->assertSame( '', devbase_mail_api_active_provider(), 'No key: WordPress keeps sending its own way.' );

		$this->brevo_on();
		update_field( 'mail_api_provider', '', 'option' );
		$this->assertSame( '', devbase_mail_api_active_provider(), 'No service chosen.' );
	}

	public function test_wp_mail_goes_through_brevo_with_the_saved_sender() {
		$this->brevo_on();
		$sent = array();
		add_action( 'wp_mail_succeeded', function ( $data ) use ( &$sent ) {
			$sent[] = $data;
		} );

		$this->assertTrue( wp_mail(
			'Office <office@nonprofit.test>, boss@nonprofit.test',
			'Hello',
			'<p>Hi</p>',
			array(
				'Content-Type: text/html; charset=UTF-8',
				'From: Someone Else <other@elsewhere.test>',
				'Cc: cc@nonprofit.test',
				'Bcc: Hidden <bcc@nonprofit.test>',
				'Reply-To: Visitor <visitor@example.test>',
				'X-Campaign: spring',
			)
		) );

		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];

		$this->assertSame( 'https://api.brevo.com/v3/smtp/email', $request['url'] );
		$this->assertSame( 'xkeysib-test-key', $request['args']['headers']['api-key'] );
		$this->assertSame( 15, $request['args']['timeout'] );

		$body = $request['body'];
		$this->assertSame( array( 'email' => 'hello@nonprofit.test', 'name' => 'The Nonprofit' ), $body['sender'], 'The verified sender, not the From header.' );
		$this->assertSame( array( array( 'email' => 'office@nonprofit.test', 'name' => 'Office' ), array( 'email' => 'boss@nonprofit.test' ) ), $body['to'] );
		$this->assertSame( array( array( 'email' => 'cc@nonprofit.test' ) ), $body['cc'] );
		$this->assertSame( array( array( 'email' => 'bcc@nonprofit.test', 'name' => 'Hidden' ) ), $body['bcc'] );
		$this->assertSame( array( 'email' => 'visitor@example.test', 'name' => 'Visitor' ), $body['replyTo'] );
		$this->assertSame( 'Hello', $body['subject'] );
		$this->assertSame( '<p>Hi</p>', $body['htmlContent'] );
		$this->assertArrayNotHasKey( 'textContent', $body );
		$this->assertSame( array( 'X-Campaign' => 'spring' ), $body['headers'] );

		$this->assertCount( 1, $sent, 'wp_mail_succeeded fires, as it does for SMTP.' );
		$this->assertSame( array( 'office@nonprofit.test', 'boss@nonprofit.test' ), $sent[0]['to'] );
	}

	public function test_plain_text_mail_and_attachments() {
		$this->brevo_on();
		$file = wp_tempnam( 'invoice.pdf' );
		file_put_contents( $file, 'PDFDATA' );

		$this->assertTrue( wp_mail( 'office@nonprofit.test', 'Reset', "Click <https://site.test/reset>\n", '', array( 'invoice.pdf' => $file, '/no/such/file' ) ) );

		$body = $this->requests[0]['body'];
		$this->assertSame( "Click <https://site.test/reset>\n", $body['textContent'], 'Plain text stays plain (password resets).' );
		$this->assertArrayNotHasKey( 'htmlContent', $body );
		$this->assertSame( array( array( 'name' => 'invoice.pdf', 'content' => base64_encode( 'PDFDATA' ) ) ), $body['attachment'], 'A missing file is left out, as wp_mail() does.' );

		unlink( $file );
	}

	public function test_html_set_through_the_content_type_filter_as_woocommerce_does() {
		$this->brevo_on();

		// WooCommerce: the filter on during its send, and its header with "\r\n" line ends.
		$type = function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $type );
		wp_mail( 'customer@example.test', 'Your order', '<h1>Thanks</h1>', "Content-Type: text/html\r\nReply-to: Shop <shop@nonprofit.test>\r\n" );
		remove_filter( 'wp_mail_content_type', $type );

		$this->assertSame( '<h1>Thanks</h1>', $this->requests[0]['body']['htmlContent'] );
		$this->assertSame( array( 'email' => 'shop@nonprofit.test', 'name' => 'Shop' ), $this->requests[0]['body']['replyTo'] );

		// WooCommerce's "Multipart" email type: the body is HTML.
		$multipart = function () {
			return 'multipart/alternative';
		};
		add_filter( 'wp_mail_content_type', $multipart );
		wp_mail( 'customer@example.test', 'Your order', '<h1>Thanks</h1>' );
		remove_filter( 'wp_mail_content_type', $multipart );

		$this->assertSame( '<h1>Thanks</h1>', $this->requests[1]['body']['htmlContent'] );
		$this->assertArrayNotHasKey( 'textContent', $this->requests[1]['body'] );
	}

	public function test_the_site_name_is_the_sender_name_when_none_is_saved() {
		$this->brevo_on();
		update_field( 'mail_api_from_name', '', 'option' );

		wp_mail( 'office@nonprofit.test', 'Hi', 'Body' );

		$this->assertSame( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $this->requests[0]['body']['sender']['name'] );
	}

	public function test_brevo_refusing_is_a_failed_mail_with_its_reason_in_the_failure_log() {
		$this->brevo_on();
		$this->answer = array( 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'body' => '{"code":"unauthorized","message":"Key not found"}', 'headers' => array(), 'cookies' => array() );

		$this->assertFalse( wp_mail( 'office@nonprofit.test', 'Hi', 'Body' ) );

		$expected = sprintf( __( 'Brevo refused the email: %1$s (%2$s)', 'devbase_parent_theme' ), 'Key not found', 'unauthorized, HTTP 401' );
		$this->assertSame( $expected, devbase_mail_log()['failures'][0]['error'] );
		$this->assertSame( 'office@nonprofit.test', devbase_mail_log()['failures'][0]['to'] );
	}

	public function test_brevo_unreachable_is_a_failed_mail() {
		$this->brevo_on();
		remove_filter( 'pre_http_request', array( $this, 'fake_brevo' ), 10 );
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		} );

		$this->assertFalse( wp_mail( 'office@nonprofit.test', 'Hi', 'Body' ) );
		$this->assertSame( sprintf( __( 'Brevo could not be reached: %s', 'devbase_parent_theme' ), 'cURL error 28: Operation timed out' ), devbase_mail_log()['failures'][0]['error'] );
	}

	public function test_smtp_on_means_the_api_stays_out_of_the_way() {
		$this->brevo_on();
		update_field( 'smtp_enabled', 1, 'option' );
		update_field( 'smtp_host', 'smtp.example.test', 'option' );

		$this->assertSame( '', devbase_mail_api_active_provider() );
		$this->assertNull( devbase_mail_api_pre_wp_mail( null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm' ) ) );
		$this->assertSame( 'smtp', devbase_mail_active_route() );
	}

	public function test_another_filters_answer_is_respected() {
		$this->brevo_on();
		$this->assertFalse( devbase_mail_api_pre_wp_mail( false, array( 'to' => 'a@b.test' ) ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_a_form_destination_goes_through_brevo_with_the_visitors_reply_to() {
		$this->brevo_on();

		$result = devbase_forms_dispatch_smtp(
			array( 'label' => 'Office', 'to_email' => 'office@nonprofit.test' ),
			'contact',
			array(
				'name'  => array( 'aria_label' => 'Name', 'value' => 'Ana' ),
				'email' => array( 'aria_label' => 'Email', 'value' => 'ana@example.test' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'email' => 'ana@example.test' ), $this->requests[0]['body']['replyTo'] );
		$this->assertStringContainsString( 'Ana', $this->requests[0]['body']['htmlContent'] );
	}

	public function test_only_one_way_of_sending_can_be_turned_on() {
		$posted = array( 'field_devbase_smtp_enabled' => '1', 'field_devbase_mail_api_enabled' => '1' );

		// SMTP already on: turning the API on is refused, SMTP is not blamed.
		update_field( 'smtp_enabled', 1, 'option' );
		$this->assertNotSame( '', devbase_mail_route_conflict( 'api', '1', $posted ) );
		$this->assertSame( '', devbase_mail_route_conflict( 'smtp', '1', $posted ) );

		// API already on: turning SMTP on is refused.
		update_field( 'smtp_enabled', 0, 'option' );
		update_field( 'mail_api_enabled', 1, 'option' );
		$this->assertNotSame( '', devbase_mail_route_conflict( 'smtp', '1', $posted ) );
		$this->assertSame( '', devbase_mail_route_conflict( 'api', '1', $posted ) );

		// Switching from one to the other in one save is fine.
		$this->assertSame( '', devbase_mail_route_conflict( 'smtp', '1', array( 'field_devbase_smtp_enabled' => '1', 'field_devbase_mail_api_enabled' => '0' ) ) );

		// Through SCF's own validation, as a save runs it.
		$_POST['acf'] = $posted;
		$field        = acf_get_field( 'field_devbase_smtp_enabled' );
		$this->assertIsString( apply_filters( 'acf/validate_value/key=field_devbase_smtp_enabled', true, '1', $field, 'acf[field_devbase_smtp_enabled]' ) );
	}

	public function test_a_brevo_smtp_key_is_refused_with_a_clear_reason() {
		$field = acf_get_field( 'field_devbase_brevo_api_key' );

		$this->assertIsString( apply_filters( 'acf/validate_value/key=field_devbase_brevo_api_key', true, 'xsmtpsib-abc', $field, 'acf[field_devbase_brevo_api_key]' ) );
		$this->assertTrue( apply_filters( 'acf/validate_value/key=field_devbase_brevo_api_key', true, 'xkeysib-abc', $field, 'acf[field_devbase_brevo_api_key]' ) );
	}

	public function test_the_api_key_is_a_secret_and_out_of_mcps_reach() {
		$this->assertContains( 'field_devbase_brevo_api_key', devbase_settings_secret_keys() );
		$this->assertContains( 'brevo_api_key', devbase_abilities_protected_option_fields() );
		$this->assertArrayNotHasKey( 'field_devbase_brevo_api_key', devbase_settings_filter_submission( array( 'field_devbase_brevo_api_key' => '' ) ), 'Saving with the field empty keeps the stored key.' );

		$this->brevo_on();
		$prepared = apply_filters( 'acf/prepare_field', array( 'key' => 'field_devbase_brevo_api_key', 'name' => 'brevo_api_key', 'type' => 'password', 'required' => 1, 'value' => 'xkeysib-test-key' ) );
		$this->assertSame( '', $prepared['value'], 'The key is never drawn back into the page.' );
	}

	public function test_system_status_names_the_api_route() {
		$this->brevo_on();

		$this->assertSame(
			sprintf( __( 'Email is sent through the %s API — not tested yet. Use "Send Test Email" on Email > Email API.', 'devbase_parent_theme' ), 'Brevo' ),
			devbase_smtp_last_test_message()
		);

		$time = time();
		update_option( 'devbase_smtp_last_test', array( 'time' => $time, 'route' => 'api', 'success' => true, 'message' => '' ), false );
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );

		$this->assertSame( sprintf( __( 'Email API (%1$s) last tested successfully: %2$s.', 'devbase_parent_theme' ), 'Brevo', $when ), devbase_smtp_last_test_message() );
	}
}
