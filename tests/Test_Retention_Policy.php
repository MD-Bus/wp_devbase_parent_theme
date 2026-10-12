<?php
// Covers inc/forms/retention.php: the day-count map, that a disabled
// toggle is a real no-op, that only submissions past their applicable
// cutoff AND matching the policy's apply_to get deleted, and that a
// recent post covered by the same policy survives.

class Test_Retention_Policy extends WP_UnitTestCase {

	public function tear_down() {
		update_field( 'retention_enabled', 0, 'option' );
		update_field( 'retention_policies', array(), 'option' );
		delete_option( 'devbase_retention_last_run' );
		parent::tear_down();
	}

	private function create_submission( $form_name, $days_old ) {
		$date = gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) );

		return self::factory()->post->create( array(
			'post_type'     => 'form_data',
			'post_date'     => get_date_from_gmt( $date ),
			'post_date_gmt' => $date,
			'meta_input'    => array(
				'form_name' => $form_name,
				'content'   => wp_json_encode( array( 'email' => array( 'aria_label' => 'Email', 'value' => 'a@b.com' ) ) ),
			),
		) );
	}

	public function test_day_counts_match_documented_values() {
		$days = devbase_forms_retention_period_days();

		$this->assertSame( array(
			'1_day'    => 1,
			'1_week'   => 7,
			'1_month'  => 30,
			'3_months' => 90,
			'6_months' => 180,
			'1_year'   => 365,
		), $days );
	}

	public function test_disabled_policy_is_a_real_no_op() {
		update_field( 'retention_enabled', 0, 'option' );
		update_field( 'retention_policies', array(
			array( 'period' => '1_day', 'apply_to' => array( 'retention_test_form' ) ),
		), 'option' );

		$old_post_id = $this->create_submission( 'retention_test_form', 10 );

		$deleted = devbase_forms_run_retention_purge();

		$this->assertSame( 0, $deleted );
		$this->assertInstanceOf( 'WP_Post', get_post( $old_post_id ), 'A submission must survive when the retention toggle is off, even with a matching policy configured.' );
	}

	public function test_purge_deletes_only_submissions_past_cutoff_for_matching_forms() {
		update_field( 'retention_enabled', 1, 'option' );
		update_field( 'retention_policies', array(
			array( 'period' => '1_week', 'apply_to' => array( 'retention_test_form' ) ),
		), 'option' );

		$old_matching_id   = $this->create_submission( 'retention_test_form', 10 ); // past 1 week — should go
		$recent_matching_id = $this->create_submission( 'retention_test_form', 2 ); // within 1 week — should stay
		$old_other_form_id  = $this->create_submission( 'unrelated_form', 10 ); // past cutoff but not covered — should stay

		$deleted = devbase_forms_run_retention_purge();

		$this->assertSame( 1, $deleted );
		$this->assertNull( get_post( $old_matching_id ), 'A submission older than its applicable policy period must be permanently deleted.' );
		$this->assertInstanceOf( 'WP_Post', get_post( $recent_matching_id ), 'A submission within the retention period must survive.' );
		$this->assertInstanceOf( 'WP_Post', get_post( $old_other_form_id ), 'A submission for a form not covered by any policy row must survive regardless of age.' );
	}

	public function test_last_run_is_recorded_only_when_the_job_actually_executes() {
		$this->assertSame( __( 'Never run yet.', 'devbase_parent_theme' ), devbase_forms_retention_last_run_message(), 'Before any run, the message must say so plainly.' );

		update_field( 'retention_enabled', 0, 'option' );
		devbase_forms_run_retention_purge();
		$this->assertFalse( get_option( 'devbase_retention_last_run' ), 'A disabled toggle must not record a run — it never actually executed.' );

		update_field( 'retention_enabled', 1, 'option' );
		devbase_forms_run_retention_purge();
		$last_run = get_option( 'devbase_retention_last_run' );
		$this->assertIsArray( $last_run );
		$this->assertSame( 0, $last_run['deleted'], 'A run with no configured policies still counts as having run, with 0 deleted.' );

		// Locale-independent: the message text itself is translated (see
		// languages/), so assert on the underlying data/behavior rather
		// than hardcoded English wording. "0" is a digit, not translated
		// by sprintf's %d, so it's safe to check for regardless of site
		// locale — unlike asserting the literal English word "deleted".
		$message = devbase_forms_retention_last_run_message();
		$this->assertNotSame( __( 'Never run yet.', 'devbase_parent_theme' ), $message, 'After a real (even 0-deleted) run, the message must no longer say "never run".' );
		$this->assertMatchesRegularExpression( '/\b0\b/', $message, 'The run count (0) should appear in the message.' );
	}

	public function test_last_run_message_flags_a_stale_run() {
		update_option( 'devbase_retention_last_run', array(
			'time'    => time() - ( 40 * HOUR_IN_SECONDS ),
			'deleted' => 3,
		), false );

		$this->assertStringContainsString( 'WP-Cron', devbase_forms_retention_last_run_message(), 'A run older than ~36 hours should be flagged as possibly stale.' );

		update_option( 'devbase_retention_last_run', array(
			'time'    => time() - HOUR_IN_SECONDS,
			'deleted' => 3,
		), false );

		$this->assertStringNotContainsString( 'WP-Cron', devbase_forms_retention_last_run_message(), 'A recent run should not be flagged.' );
	}

	public function test_form_covered_by_multiple_rows_is_purged_at_the_shortest_period() {
		update_field( 'retention_enabled', 1, 'option' );
		update_field( 'retention_policies', array(
			array( 'period' => '1_day', 'apply_to' => array( 'retention_test_form' ) ),
			array( 'period' => '1_year', 'apply_to' => array( 'retention_test_form' ) ),
		), 'option' );

		// 10 days old: past the 1-day row's cutoff, well within the 1-year row's.
		$post_id = $this->create_submission( 'retention_test_form', 10 );

		devbase_forms_run_retention_purge();

		$this->assertNull( get_post( $post_id ), 'The shorter configured period should govern when a form is covered by more than one row.' );
	}
}
