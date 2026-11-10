<?php
// Covers inc/theme-settings/smtp/failures.php: every mail failure is kept (not only the test button's),
// shown on System Status, and sent in the health report without naming anyone.

class Test_Mail_Log extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( DEVBASE_MAIL_LOG_OPTION );
		parent::tear_down();
	}

	private function fail_mail( $message = 'SMTP Error: Could not authenticate.', array $to = array( 'office@site.test' ) ) {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $message, array( 'to' => $to, 'subject' => 'New submission: contact' ) ) );
	}

	public function test_a_real_mail_failure_is_recorded() {
		$this->fail_mail( 'SMTP Error: Could not authenticate.', array( 'office@site.test', 'boss@site.test' ) );

		$log = devbase_mail_log();
		$this->assertCount( 1, $log['failures'] );
		$this->assertSame( 'SMTP Error: Could not authenticate.', $log['failures'][0]['error'] );
		$this->assertSame( 'office@site.test +1', $log['failures'][0]['to'] );
	}

	public function test_a_failure_from_wp_mail_itself_is_recorded() {
		// No SMTP host to reach: wp_mail() returns false and fires wp_mail_failed, as on a real site.
		add_filter( 'pre_wp_mail', function () {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.', array( 'to' => array( 'x@site.test' ) ) ) );
			return false;
		} );

		$this->assertFalse( wp_mail( 'x@site.test', 'Hello', 'Body' ) );
		$this->assertSame( 'Could not instantiate mail function.', devbase_mail_log()['failures'][0]['error'] );
	}

	public function test_only_the_last_twenty_are_kept_newest_first() {
		for ( $i = 1; $i <= DEVBASE_MAIL_LOG_KEEP + 5; $i++ ) {
			$this->fail_mail( "failure {$i}" );
		}

		$failures = devbase_mail_log()['failures'];
		$this->assertCount( DEVBASE_MAIL_LOG_KEEP, $failures );
		$this->assertSame( 'failure ' . ( DEVBASE_MAIL_LOG_KEEP + 5 ), $failures[0]['error'] );
	}

	public function test_a_sent_mail_is_recorded_at_most_once_a_minute() {
		do_action( 'wp_mail_succeeded', array() );
		$first = devbase_mail_log()['sent_at'];
		$this->assertGreaterThan( time() - 5, $first );

		update_option( DEVBASE_MAIL_LOG_OPTION, array( 'sent_at' => $first - 30, 'failures' => array() ) );
		do_action( 'wp_mail_succeeded', array() );
		$this->assertSame( $first - 30, devbase_mail_log()['sent_at'], 'Within the minute: no write.' );
	}

	public function test_the_status_line_names_the_last_failure_and_counts_the_week() {
		$this->assertStringContainsString( __( 'No mail has failed to send.', 'devbase_parent_theme' ), devbase_mail_status_message() );

		$this->fail_mail( 'Old one' );
		$log                         = devbase_mail_log();
		$log['failures'][0]['time'] -= 8 * DAY_IN_SECONDS;
		update_option( DEVBASE_MAIL_LOG_OPTION, $log );
		$this->fail_mail( 'Connection refused' );

		$message = devbase_mail_status_message();
		$this->assertStringContainsString( 'Connection refused', $message );
		$this->assertStringContainsString( sprintf( _n( '%d failure in the last 7 days.', '%d failures in the last 7 days.', 1, 'devbase_parent_theme' ), 1 ), $message );
	}

	public function test_the_health_report_carries_counts_and_a_scrubbed_error_but_no_recipient() {
		$this->fail_mail( 'SMTP Error: The following recipients failed: jane@example.com', array( 'jane@example.com' ) );

		$report = devbase_heartbeat_report();

		$this->assertSame( 1, $report['mail']['failed_7d'] );
		$this->assertStringContainsString( '[email]', $report['mail']['last_error'] );
		$this->assertStringNotContainsString( 'jane@example.com', wp_json_encode( $report ) );
	}

	public function test_system_status_has_a_mail_delivery_line() {
		$field = acf_get_field( 'field_devbase_status_mail' );

		$this->assertIsArray( $field );
		$this->assertSame( 'message', $field['type'] );
	}
}
