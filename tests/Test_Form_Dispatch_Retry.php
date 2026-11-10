<?php
// Covers what inc/forms/dispatch/orchestrator.php gained in 1.39.0: sending after the visitor has their
// answer (where PHP can), the pending / retry / done state, a failed destination being tried again without
// the others receiving the submission twice, the limit on tries, and the safety net for a request that died
// before sending. Plus the SMTP timeout (inc/theme-settings/smtp/mailer.php), which is what made a slow
// destination dangerous in the first place.

class Test_Form_Dispatch_Retry extends WP_UnitTestCase {

	/** @var array<string, int> url => HTTP status the fake answers (default 200) */
	private $answers = array();

	/** @var string[] every URL a webhook was sent to, in order */
	private $sent = array();

	public function set_up() {
		parent::set_up();

		$this->answers = array();
		$this->sent    = array();
		$_POST['_devbase_form_nonce'] = wp_create_nonce( 'devbase_form_retry_test_form' );
		$_SERVER['REMOTE_ADDR']       = '203.0.113.' . wp_rand( 1, 250 );

		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		devbase_forms_dispatch_later(); // empty the queue
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		update_field( 'destinations', array(), 'option' );
		update_field( 'smtp_enabled', 0, 'option' );
		devbase_forms_dispatch_later();
		unset( $_POST['_devbase_form_nonce'], $_SERVER['REMOTE_ADDR'] );

		parent::tear_down();
	}

	public function http( $pre, $args, $url ) {
		$this->sent[] = $url;

		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $this->answers[ $url ] ?? 200, 'message' => '' ) );
	}

	private function destinations( array $urls ) {
		update_field( 'destinations', array_map( function ( $url ) {
			return array( 'label' => $url, 'type' => 'webhook', 'url' => $url, 'auth_value' => '', 'auth_header_name' => '', 'apply_to' => array( 'retry_test_form' ) );
		}, $urls ), 'option' );
	}

	private function submit() {
		return devbase_form_log_submission( 'retry_test_form', array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) ) );
	}

	private function state( $post_id ) {
		return get_post_meta( $post_id, '_devbase_dispatched', true );
	}

	private function log( $post_id ) {
		return json_decode( get_post_meta( $post_id, '_devbase_dispatch_log', true ), true );
	}

	// --- the state ---------------------------------------------------------------------------------

	public function test_a_submission_every_destination_received_is_done_and_nothing_is_left_scheduled() {
		$this->destinations( array( 'https://a.test/hook', 'https://b.test/hook' ) );

		$post_id = $this->submit();

		$this->assertSame( 'done', $this->state( $post_id ) );
		$this->assertSame( array( 'https://a.test/hook', 'https://b.test/hook' ), $this->sent );
		$this->assertFalse( wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) ), 'The safety net is cleared once the run finished.' );
		$this->assertSame( array( 1, 1 ), array_column( $this->log( $post_id ), 'attempts' ) );
	}

	public function test_no_destination_at_all_is_done_not_retried() {
		$post_id = $this->submit();

		$this->assertSame( 'done', $this->state( $post_id ), 'There is nothing a second try could reach.' );
		$this->assertFalse( wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) ) );
	}

	// --- the retry ---------------------------------------------------------------------------------

	public function test_a_destination_that_fails_is_tried_again_and_the_one_that_worked_is_not_sent_twice() {
		$this->destinations( array( 'https://good.test/hook', 'https://bad.test/hook' ) );
		$this->answers['https://bad.test/hook'] = 503;

		$post_id = $this->submit();

		$this->assertSame( 'retry', $this->state( $post_id ) );
		$next = wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) );
		$this->assertNotFalse( $next );
		$this->assertEqualsWithDelta( time() + 5 * MINUTE_IN_SECONDS, $next, 5, 'The second try is five minutes on.' );

		// The receiver is back.
		$this->answers['https://bad.test/hook'] = 200;
		$this->sent = array();
		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id );

		$this->assertSame( array( 'https://bad.test/hook' ), $this->sent, 'Only the destination that had failed.' );
		$this->assertSame( 'done', $this->state( $post_id ) );

		$log = $this->log( $post_id );
		$this->assertCount( 2, $log, 'One line per destination, not one per try.' );
		$this->assertTrue( $log[0]['success'] );
		$this->assertSame( 1, $log[0]['attempts'] );
		$this->assertTrue( $log[1]['success'] );
		$this->assertSame( 2, $log[1]['attempts'] );
	}

	public function test_it_gives_up_after_three_tries_and_the_gap_grows() {
		$this->destinations( array( 'https://bad.test/hook' ) );
		$this->answers['https://bad.test/hook'] = 500;

		$post_id = $this->submit();
		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id ); // second try

		$this->assertSame( 'retry', $this->state( $post_id ) );
		$this->assertEqualsWithDelta( time() + 30 * MINUTE_IN_SECONDS, wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) ), 5, 'The third try is half an hour on.' );

		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id ); // third and last

		$this->assertSame( 'done', $this->state( $post_id ) );
		$this->assertFalse( wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) ) );
		$this->assertCount( 3, $this->sent );
		$this->assertSame( 3, $this->log( $post_id )[0]['attempts'] );
		$this->assertFalse( $this->log( $post_id )[0]['success'] );

		// An event left over, or fired by hand, sends nothing more.
		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id );
		$this->assertCount( 3, $this->sent );
	}

	public function test_the_number_of_tries_can_be_changed() {
		add_filter( 'devbase/forms/dispatch_max_attempts', function () {
			return 1;
		} );
		$this->destinations( array( 'https://bad.test/hook' ) );
		$this->answers['https://bad.test/hook'] = 500;

		$post_id = $this->submit();

		$this->assertSame( 'done', $this->state( $post_id ), 'One try in all: no retry.' );
	}

	public function test_two_identical_rows_are_two_destinations() {
		$this->destinations( array( 'https://same.test/hook', 'https://same.test/hook' ) );

		$post_id = $this->submit();

		$this->assertCount( 2, $this->sent );
		$keys = array_column( $this->log( $post_id ), 'key' );
		$this->assertCount( 2, array_unique( $keys ) );
	}

	public function test_a_destination_keeps_its_place_in_the_log_when_the_rows_are_reordered_between_tries() {
		$this->destinations( array( 'https://good.test/hook', 'https://bad.test/hook' ) );
		$this->answers['https://bad.test/hook'] = 503;
		$post_id = $this->submit();

		// The administrator swaps the two rows before the retry.
		$this->destinations( array( 'https://bad.test/hook', 'https://good.test/hook' ) );
		$this->answers['https://bad.test/hook'] = 200;
		$this->sent = array();
		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id );

		$this->assertSame( array( 'https://bad.test/hook' ), $this->sent, 'Told apart by where they send to, not by their position.' );
	}

	public function test_the_retry_event_ignores_what_is_not_a_waiting_submission() {
		$this->destinations( array( 'https://a.test/hook' ) );

		// A submission from before 1.39.0 carries the old yes/no flag.
		$old = self::factory()->post->create( array( 'post_type' => 'form_data', 'meta_input' => array( '_devbase_dispatched' => '1', 'form_name' => 'retry_test_form', 'content' => '{"a":{"aria_label":"A","value":"b"}}' ) ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, '_devbase_dispatched', 'pending' );

		$this->sent = array();
		do_action( DEVBASE_FORMS_RETRY_HOOK, $old );
		do_action( DEVBASE_FORMS_RETRY_HOOK, $page );
		do_action( DEVBASE_FORMS_RETRY_HOOK, 999999 );

		$this->assertSame( array(), $this->sent );
	}

	// --- after the answer --------------------------------------------------------------------------

	public function test_on_the_command_line_it_sends_at_once() {
		$this->assertFalse( devbase_forms_dispatch_deferred(), 'WP-CLI and this suite have no one waiting for an answer.' );
	}

	public function test_where_it_can_finish_the_response_first_nothing_is_sent_until_the_end_of_the_request() {
		add_filter( 'devbase/forms/defer_dispatch', '__return_true' );
		$this->destinations( array( 'https://a.test/hook' ) );

		$post_id = $this->submit();

		$this->assertSame( array(), $this->sent, 'The visitor is answered before any destination is called.' );
		$this->assertSame( 'pending', $this->state( $post_id ) );
		$this->assertNotFalse( has_action( 'shutdown', 'devbase_forms_dispatch_queued' ) );
		$this->assertNotFalse( wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) ), 'And if the request dies first, the event sends it.' );

		devbase_forms_dispatch_queued(); // the end of the request

		$this->assertSame( array( 'https://a.test/hook' ), $this->sent );
		$this->assertSame( 'done', $this->state( $post_id ) );
		$this->assertFalse( wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) ) );

		devbase_forms_dispatch_queued();
		$this->assertCount( 1, $this->sent, 'The queue is taken once.' );
	}

	public function test_a_request_that_died_before_sending_is_picked_up_by_the_event() {
		add_filter( 'devbase/forms/defer_dispatch', '__return_true' );
		$this->destinations( array( 'https://a.test/hook' ) );

		$post_id = $this->submit();
		devbase_forms_dispatch_later(); // the request dies: its queue is gone

		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id );

		$this->assertSame( array( 'https://a.test/hook' ), $this->sent );
		$this->assertSame( 'done', $this->state( $post_id ) );
	}

	public function test_a_submission_the_event_already_sent_is_not_sent_again_at_the_end_of_a_slow_request() {
		add_filter( 'devbase/forms/defer_dispatch', '__return_true' );
		$this->destinations( array( 'https://a.test/hook' ) );

		$post_id = $this->submit();
		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id );
		devbase_forms_dispatch_queued();

		$this->assertCount( 1, $this->sent );
	}

	public function test_resaving_a_submission_in_any_state_sends_nothing() {
		$this->destinations( array( 'https://bad.test/hook' ) );
		$this->answers['https://bad.test/hook'] = 500;
		$post_id = $this->submit(); // state: retry

		$this->sent = array();
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Edited' ) );

		$this->assertSame( array(), $this->sent );
	}

	// --- the submission screen ---------------------------------------------------------------------

	public function test_the_submission_screen_says_how_often_it_tried_and_that_it_will_try_again() {
		$this->destinations( array( 'https://bad.test/hook' ) );
		$this->answers['https://bad.test/hook'] = 500;
		$post_id = $this->submit();
		do_action( DEVBASE_FORMS_RETRY_HOOK, $post_id );

		ob_start();
		devbase_forms_render_submission_meta_box( get_post( $post_id ) );
		$html = ob_get_clean();

		// Compared through the translation: the test site may run in French.
		$this->assertStringContainsString( esc_html( sprintf( __( '(tried %d times)', 'devbase_parent_theme' ), 2 ) ), $html );
		$this->assertStringContainsString( esc_html( sprintf( __( 'A destination that failed will be tried again in about %s.', 'devbase_parent_theme' ), human_time_diff( time(), wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) ) ) ) ), $html );
	}

	// --- the mail server's timeout -----------------------------------------------------------------

	private function mailer_after_init() {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$mailer = new PHPMailer\PHPMailer\PHPMailer( true );
		do_action_ref_array( 'phpmailer_init', array( &$mailer ) );

		return $mailer;
	}

	public function test_a_mail_server_that_does_not_answer_is_given_fifteen_seconds_not_five_minutes() {
		update_field( 'smtp_enabled', 1, 'option' );
		update_field( 'smtp_host', 'smtp.example.test', 'option' );

		$mailer = $this->mailer_after_init();

		$this->assertSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 15, $mailer->Timeout, 'PHPMailer\'s own default is 300 seconds, long enough to hold a form submission for five minutes.' );

		add_filter( 'devbase/smtp/timeout', function () {
			return 40;
		} );
		$this->assertSame( 40, $this->mailer_after_init()->Timeout );

		update_field( 'smtp_host', '', 'option' );
	}

	public function test_mail_is_left_alone_when_smtp_is_off() {
		update_field( 'smtp_enabled', 0, 'option' );

		$mailer = $this->mailer_after_init();

		$this->assertNotSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 300, $mailer->Timeout, 'Not ours to change while the site sends mail its own way.' );
	}
}
