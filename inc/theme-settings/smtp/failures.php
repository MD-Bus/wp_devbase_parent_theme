<?php

// What happened to the site's real mail, not only the "Send Test Email" button: every wp_mail()
// failure is recorded (WordPress's own wp_mail_failed action, whatever sent the mail: a form
// notification, a password reset, WooCommerce, a plugin), and the time of the last mail that went out
// (wp_mail_succeeded, WordPress 5.9+).
//
// Shown on Miscellaneous > System Status, and sent in the health report (inc/maintenance/heartbeat.php)
// as `mail`, scrubbed: counts, times and the error's first line with addresses replaced. Recipients and
// subjects are kept on the site only, for System Status, and only the last few.

/** The option holding the log: last sent time, recent failures (newest first). */
const DEVBASE_MAIL_LOG_OPTION = 'devbase_mail_log';

/** How many failures are kept. */
const DEVBASE_MAIL_LOG_KEEP = 20;

/** @return array{sent_at:int, failures:array<int, array{time:int, error:string, to:string}>} */
function devbase_mail_log() {
	$log = get_option( DEVBASE_MAIL_LOG_OPTION );
	$log = is_array( $log ) ? $log : array();

	return array(
		'sent_at'  => (int) ( $log['sent_at'] ?? 0 ),
		'failures' => isset( $log['failures'] ) && is_array( $log['failures'] ) ? array_values( $log['failures'] ) : array(),
	);
}

/**
 * Records one failed mail.
 *
 * @param WP_Error $error WordPress's error; its data holds the message (to, subject...).
 */
function devbase_mail_record_failure( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return;
	}

	$data = $error->get_error_data();
	$to   = is_array( $data ) && isset( $data['to'] ) ? (array) $data['to'] : array();
	$to   = array_filter( array_map( 'strval', $to ) );

	$log = devbase_mail_log();

	array_unshift( $log['failures'], array(
		'time'  => time(),
		'error' => function_exists( 'mb_substr' ) ? mb_substr( (string) $error->get_error_message(), 0, 300 ) : substr( (string) $error->get_error_message(), 0, 300 ),
		// The first recipient and how many more: enough to tell a form notification from a password reset.
		'to'    => $to ? reset( $to ) . ( count( $to ) > 1 ? ' +' . ( count( $to ) - 1 ) : '' ) : '',
	) );

	$log['failures'] = array_slice( $log['failures'], 0, DEVBASE_MAIL_LOG_KEEP );

	update_option( DEVBASE_MAIL_LOG_OPTION, $log, false );

	// Also an error for Brand Relay: a form notification, a password reset or an order mail that never leaves is silent.
	if ( function_exists( 'devbase_events_report' ) ) {
		devbase_events_report( 'mail', 'A mail failed to send', array( 'reason' => (string) $error->get_error_message() ) );
	}
}
add_action( 'wp_mail_failed', 'devbase_mail_record_failure' );

/** Records that a mail went out (one write per minute at most, so a burst of mail does not mean a burst of writes). */
function devbase_mail_record_sent() {
	$log = devbase_mail_log();

	if ( time() - $log['sent_at'] < MINUTE_IN_SECONDS ) {
		return;
	}

	$log['sent_at'] = time();
	update_option( DEVBASE_MAIL_LOG_OPTION, $log, false );
}
add_action( 'wp_mail_succeeded', 'devbase_mail_record_sent' );

/**
 * Failures in the last $days days.
 *
 * @param int $days Days back.
 */
function devbase_mail_failures_since( $days = 7 ) {
	$since = time() - (int) $days * DAY_IN_SECONDS;

	return count( array_filter( devbase_mail_log()['failures'], function ( $f ) use ( $since ) {
		return (int) ( $f['time'] ?? 0 ) >= $since;
	} ) );
}

/** The System Status line: the last failure, how many this week, the last mail sent. Plain text. */
function devbase_mail_status_message() {
	$log    = devbase_mail_log();
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$parts  = array();

	if ( ! $log['failures'] ) {
		$parts[] = __( 'No mail has failed to send.', 'devbase_parent_theme' );
	} else {
		$last = $log['failures'][0];
		/* translators: 1: date and time, 2: the recipient, 3: the error. */
		$parts[] = sprintf( __( 'Last failure %1$s, to %2$s: %3$s', 'devbase_parent_theme' ), wp_date( $format, (int) $last['time'] ), '' !== $last['to'] ? $last['to'] : '—', $last['error'] );
		/* translators: %d: a number of failed mails. */
		$parts[] = sprintf( _n( '%d failure in the last 7 days.', '%d failures in the last 7 days.', devbase_mail_failures_since( 7 ), 'devbase_parent_theme' ), devbase_mail_failures_since( 7 ) );
	}

	$parts[] = $log['sent_at']
		/* translators: %s: date and time. */
		? sprintf( __( 'Last mail sent %s.', 'devbase_parent_theme' ), wp_date( $format, $log['sent_at'] ) )
		: __( 'No mail sent since this was installed.', 'devbase_parent_theme' );

	return implode( ' ', $parts );
}

/**
 * The `mail` field of the health report. Nothing that names a person: no recipient, no subject, and
 * the error is scrubbed (devbase_events_scrub() replaces addresses with [email]).
 *
 * @param array $report The report.
 */
function devbase_mail_report( $report ) {
	$log  = devbase_mail_log();
	$last = $log['failures'][0] ?? null;

	$report['mail'] = array_filter( array(
		'failed_7d'      => devbase_mail_failures_since( 7 ),
		'last_failed_at' => $last ? gmdate( 'c', (int) $last['time'] ) : null,
		'last_error'     => $last && function_exists( 'devbase_events_scrub' ) ? devbase_events_scrub( $last['error'], 200 ) : null,
		'last_sent_at'   => $log['sent_at'] ? gmdate( 'c', $log['sent_at'] ) : null,
	), function ( $v ) {
		return null !== $v;
	} );

	return $report;
}
add_filter( 'devbase/maintenance/report', 'devbase_mail_report' );
