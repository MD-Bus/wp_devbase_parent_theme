<?php

// Data retention for form_data submissions — reads the Retention Policy
// tab on inc/theme-settings/miscellaneous/ and permanently deletes
// submissions older than their configured period. Off by default: if
// 'retention_enabled' isn't checked, nothing here does anything.
//
// Semantics: a form covered by zero policy rows is retained forever (no
// change from the theme's default behavior). A form covered by more than
// one row is purged at whichever row's period is shortest, since each
// row's purge runs independently, on its own schedule, against whatever
// posts still exist — there's no principled way to prefer one configured
// row over another, so this is documented behavior, not resolved.

/**
 * Row `period` value => day count. A simple day-based approximation
 * (e.g. "1 month" = 30 days, not calendar-accurate) — fine for a
 * retention ceiling, not a precise legal deadline system.
 *
 * @return array<string,int>
 */
function devbase_forms_retention_period_days() {
	return array(
		'1_day'    => 1,
		'1_week'   => 7,
		'1_month'  => 30,
		'3_months' => 90,
		'6_months' => 180,
		'1_year'   => 365,
	);
}

// Standard WP-cron idiom: schedule once, on every 'init', if not already
// scheduled. The callback itself checks retention_enabled and bails if
// off, so this is harmless to leave scheduled even when the feature is
// disabled — cheaper than constantly scheduling/unscheduling as the
// toggle changes.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'devbase_forms_retention_purge' ) ) {
		wp_schedule_event( time(), 'daily', 'devbase_forms_retention_purge' );
	}
} );

// Unschedule when this theme is deactivated — matches this theme's
// existing "no persistent state left behind" bar (see
// inc/hide-admin-menu-items.php). switch_theme fires for ANY theme
// deactivation, not just this one, but unscheduling is harmless either
// way; it reschedules itself on the next 'init' if this theme becomes
// active again.
add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'devbase_forms_retention_purge' );
} );

add_action( 'devbase_forms_retention_purge', 'devbase_forms_run_retention_purge' );

/**
 * Runs the configured retention policy once. Safe to call directly (the
 * "Run Purge Now" button in wp-admin does exactly that, for real
 * verification without waiting for cron).
 *
 * Records a `devbase_retention_last_run` option (time + deleted count)
 * whenever the job actually executes — including a 0-deleted run, since
 * "the job ran and found nothing to do" still proves the schedule is
 * alive. This exists specifically because WP-Cron is pseudo-cron (only
 * fires on a page load) and some hosts disable it entirely in favor of a
 * real system cron hitting wp-cron.php — without this, there'd be no way
 * to tell "nothing needed deleting" apart from "this hasn't run in
 * weeks." See devbase_forms_retention_last_run_message() for how the
 * Retention Policy tab surfaces this.
 *
 * @return int Total number of form_data posts deleted across all rows.
 */
function devbase_forms_run_retention_purge() {
	if ( ! get_field( 'retention_enabled', 'option' ) ) {
		return 0;
	}

	$policies      = get_field( 'retention_policies', 'option' );
	$day_counts    = devbase_forms_retention_period_days();
	$total_deleted = 0;
	$batch_size    = 200;

	if ( ! empty( $policies ) && is_array( $policies ) ) {
		foreach ( $policies as $row ) {
			$period     = isset( $row['period'] ) ? $row['period'] : '';
			$applies_to = ! empty( $row['apply_to'] ) && is_array( $row['apply_to'] ) ? $row['apply_to'] : array();

			if ( empty( $day_counts[ $period ] ) || empty( $applies_to ) ) {
				continue;
			}

			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $day_counts[ $period ] * DAY_IN_SECONDS ) );

			$old_submissions = get_posts( array(
				'post_type'      => 'form_data',
				'post_status'    => 'any',
				'posts_per_page' => $batch_size,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before'    => $cutoff,
						'inclusive' => false,
						'column'    => 'post_date_gmt',
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'form_name',
						'value'   => $applies_to,
						'compare' => 'IN',
					),
				),
			) );

			foreach ( $old_submissions as $post_id ) {
				if ( wp_delete_post( $post_id, true ) ) {
					++$total_deleted;
				}
			}
		}
	}

	update_option( 'devbase_retention_last_run', array(
		'time'    => time(),
		'deleted' => $total_deleted,
	), false );

	return $total_deleted;
}

/**
 * Human-readable "Last run: ..." string for the Retention Policy tab, or
 * a "never run yet" message. Flags a run older than ~36 hours (a daily
 * job should run every ~24h; 36h gives slack for a slow day) as possibly
 * stale — the practical signal for "WP-Cron might not be firing on this
 * site," since there's no other way to detect that from inside WordPress
 * itself.
 *
 * @return string
 */
function devbase_forms_retention_last_run_message() {
	$last_run = get_option( 'devbase_retention_last_run' );

	if ( empty( $last_run['time'] ) ) {
		return __( 'Never run yet.', 'devbase_parent_theme' );
	}

	$age_hours = ( time() - $last_run['time'] ) / HOUR_IN_SECONDS;

	$message = sprintf(
		/* translators: 1: date/time of last run, 2: number of submissions deleted that run */
		__( 'Last run: %1$s — %2$d deleted.', 'devbase_parent_theme' ),
		wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run['time'] ),
		$last_run['deleted']
	);

	if ( $age_hours > 36 ) {
		$message .= ' ' . __( '⚠ Over 36 hours ago — if this keeps growing, WP-Cron may not be running on this site (common on hosts that disable it in favor of a real system cron). Use "Run Purge Now" to verify, or point a real cron job at wp-cron.php.', 'devbase_parent_theme' );
	}

	return $message;
}
