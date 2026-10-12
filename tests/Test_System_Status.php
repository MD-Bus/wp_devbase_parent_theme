<?php
// Covers the Miscellaneous > System Status tab's three data sources:
// devbase_smtp_last_test_message() (inc/theme-settings/smtp/test.php),
// devbase_forms_get_recent_dispatch_summary()/devbase_forms_dispatch_status_message()
// (inc/forms/dispatch/status.php), reusing
// devbase_forms_retention_last_run_message() (already covered by
// Test_Retention_Policy.php) for the third.
//
// Every assertion here compares against the ACTUAL translated string (via
// __()/esc_html__(), rebuilding the same sprintf() the real function
// does) rather than a hardcoded English literal — this theme ships real
// fr_FR/fr_CA/fr_BE translations and this dev site's real locale is
// fr_CA, so an English-literal assertion would be asserting a
// locale-dependent implementation detail, the same mistake fixed in
// Test_Retention_Policy.php and, before that, in the cookie-consent tests
// (that feature was removed from this theme on 2026-10-11). See
// tests/AGENT.md, "Never assert on an English literal", for the full story.

class Test_System_Status extends WP_UnitTestCase {

	public function tear_down() {
		update_field( 'smtp_enabled', 0, 'option' );
		delete_option( 'devbase_smtp_last_test' );
		parent::tear_down();
	}

	public function test_smtp_status_when_disabled() {
		update_field( 'smtp_enabled', 0, 'option' );
		$this->assertSame(
			__( 'SMTP is disabled — using PHP\'s default mail().', 'devbase_parent_theme' ),
			devbase_smtp_last_test_message()
		);
	}

	public function test_smtp_status_when_enabled_but_never_tested() {
		update_field( 'smtp_enabled', 1, 'option' );
		$this->assertSame(
			__( 'SMTP is enabled — not tested yet. Use "Send Test Email" on SMTP Settings.', 'devbase_parent_theme' ),
			devbase_smtp_last_test_message()
		);
	}

	public function test_smtp_status_reflects_successful_test() {
		update_field( 'smtp_enabled', 1, 'option' );

		$time = time();
		update_option( 'devbase_smtp_last_test', array( 'time' => $time, 'success' => true, 'message' => '' ), false );

		$when     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
		$expected = sprintf( __( 'SMTP last tested successfully: %s.', 'devbase_parent_theme' ), $when );

		$this->assertSame( $expected, devbase_smtp_last_test_message() );
	}

	public function test_smtp_status_reflects_failed_test() {
		update_field( 'smtp_enabled', 1, 'option' );

		$time = time();
		update_option( 'devbase_smtp_last_test', array( 'time' => $time, 'success' => false, 'message' => 'Could not connect' ), false );

		$when     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
		$expected = sprintf( __( 'SMTP last test FAILED (%1$s): %2$s', 'devbase_parent_theme' ), $when, 'Could not connect' );

		$this->assertSame( $expected, devbase_smtp_last_test_message() );
	}

	public function test_dispatch_summary_with_no_data() {
		$summary = devbase_forms_get_recent_dispatch_summary();
		$this->assertSame( 0, $summary['checked'] );
		$this->assertSame(
			esc_html__( 'No dispatch attempts recorded yet.', 'devbase_parent_theme' ),
			devbase_forms_dispatch_status_message()
		);
	}

	public function test_dispatch_summary_counts_success_and_failure() {
		self::factory()->post->create( array(
			'post_type'  => 'form_data',
			'meta_input' => array(
				'form_name'              => 'status_test_form',
				'_devbase_dispatch_log' => wp_json_encode( array(
					array( 'success' => true, 'label' => 'SMTP Inbox', 'message' => 'sent' ),
				) ),
			),
		) );

		self::factory()->post->create( array(
			'post_type'  => 'form_data',
			'meta_input' => array(
				'form_name'              => 'status_test_form',
				'_devbase_dispatch_log' => wp_json_encode( array(
					array( 'success' => false, 'label' => 'CRM Webhook', 'message' => 'HTTP 500' ),
				) ),
			),
		) );

		$summary = devbase_forms_get_recent_dispatch_summary();

		$this->assertSame( 2, $summary['checked'] );
		$this->assertSame( 1, $summary['success'] );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertCount( 1, $summary['recent_failures'] );
		$this->assertSame( 'CRM Webhook', $summary['recent_failures'][0]['label'] );

		// 'CRM Webhook' and 'HTTP 500' are raw stored values (a
		// destination label, an HTTP status detail), never translated —
		// safe to assert literally, unlike the surrounding sentence text.
		$message = devbase_forms_dispatch_status_message();
		$this->assertStringContainsString( 'CRM Webhook', $message );
		$this->assertStringContainsString( 'HTTP 500', $message );
	}

	public function test_dispatch_summary_all_success_message() {
		self::factory()->post->create( array(
			'post_type'  => 'form_data',
			'meta_input' => array(
				'form_name'              => 'status_test_form',
				'_devbase_dispatch_log' => wp_json_encode( array(
					array( 'success' => true, 'label' => 'SMTP Inbox', 'message' => 'sent' ),
				) ),
			),
		) );

		$expected = sprintf( esc_html__( 'All %d recent dispatch attempts succeeded.', 'devbase_parent_theme' ), 1 );
		$this->assertSame( $expected, devbase_forms_dispatch_status_message() );
	}
}
