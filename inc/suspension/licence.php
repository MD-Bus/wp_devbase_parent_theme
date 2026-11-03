<?php

// Licence-driven suspension (only while a child turns the kill switch on, see
// suspension.php). The site follows its Warden licence status; no update has to
// be installed to switch it off or on.
//
//   Warden status "suspended"  ->  public site shows the blocked page
//   any other status           ->  public site works (active, completed, expired, ...)
//
// HOW IT DECIDES. The parent theme's Warden client (devbase_warden_check) asks
// Warden about this site's licence and keeps the last SIGNED answer in the
// option `devbase_warden_state_<stylesheet>`. This file only reads that answer.
//
// STATE ONLY CHANGES ON A SUCCESSFUL SIGNED ANSWER. A failed check (timeout,
// 5xx, bad signature, unknown key, no internet) leaves the stored answer
// untouched, so:
//
//   * suspended + check fails -> stays suspended
//   * active    + check fails -> stays active
//
// Only the statuses listed in the config's 'lock_statuses' (just "suspended") lock
// the site. Anything else, including "expired" and any other reason Warden might
// say "not valid" (a domain that is not on the licence, an unknown key), never does.
//
// WHEN IT CHECKS. The stored answer is the cache. Any page request that finds it older than
// 'check_hours' (config) refreshes it after the response is sent (a lock keeps it to one at a time; a
// failed attempt retries after 'retry_minutes'). A WordPress scheduled task and the
// "Check for Updates Now" button do the same. It does not depend on WP-Cron, on the update
// check, or on the auto-update switch.
//
// Only hooks are registered at the top level (this runs from the parent's functions.php).

/** Cron hook that refreshes the licence answer. */
const DEVBASE_LICENCE_CRON = 'devbase_licence_check';

/**
 * What the last signed Warden answer says about this site. Cached for the
 * request; pass true to drop the cache.
 *
 * @param bool $reset Forget the cached copy first.
 * @return array{locked: bool, status: string, message: string, grace_ends_at: string, checked_at: int}
 */
function devbase_licence_verdict( $reset = false ) {
	static $verdict = null;

	if ( $reset ) {
		$verdict = null;
	}

	if ( null !== $verdict ) {
		return $verdict;
	}

	$verdict = array(
		'locked'        => false,
		'status'        => '',
		'message'       => '',
		'grace_ends_at' => '',
		'checked_at'    => 0,
	);

	$config = devbase_suspension_config();

	if ( ! devbase_suspension_enabled() || empty( $config['licence_lock'] ) ) {
		return $verdict;
	}

	$state    = get_option( 'devbase_warden_state_' . get_stylesheet() );
	$state    = is_array( $state ) ? $state : array();
	$response = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();

	$verdict['checked_at'] = isset( $state['succeeded_at'] ) ? (int) $state['succeeded_at'] : 0;

	if ( ! $response ) {
		// Never had a good answer: not locked. A site is never locked on silence.
		return $verdict;
	}

	$status = strtolower( trim( (string) ( $response['status'] ?? '' ) ) );
	$locks  = array_map( 'strtolower', array_map( 'trim', (array) $config['lock_statuses'] ) );

	$verdict['status']        = $status;
	$verdict['message']       = isset( $response['message'] ) ? (string) $response['message'] : '';
	$verdict['grace_ends_at'] = ! empty( $response['grace_ends_at'] ) ? (string) $response['grace_ends_at'] : '';
	$verdict['locked']        = '' !== $status && in_array( $status, $locks, true );

	return $verdict;
}

/**
 * Asks Warden now about this site's licence. Safe to call anywhere after the
 * parent theme has loaded. Returns Warden's answer, the last good one if the
 * check failed, or null when there is nothing to check.
 */
function devbase_run_licence_check() {
	if ( ! devbase_suspension_enabled() ) {
		return null;
	}

	$track   = get_stylesheet();
	$licence = devbase_warden_child_licence();

	if ( '' === $licence ) {
		return null;
	}

	$version = devbase_update_installed_version( $track );

	if ( null === $version ) {
		return null;
	}

	$before   = devbase_licence_verdict()['locked'];
	$response = devbase_warden_check( $track, $licence, $version, true );

	// Log a change of verdict, never a failed check (the parent logs those).
	$state = get_option( 'devbase_warden_state_' . $track );
	$after = is_array( $state ) && ! empty( $state['response']['status'] )
		&& in_array( strtolower( (string) $state['response']['status'] ), array_map( 'strtolower', (array) devbase_suspension_config()['lock_statuses'] ), true );

	if ( $before !== $after ) {
		error_log( 'devbase: licence status is now "' . ( $state['response']['status'] ?? '' ) . '", site ' . ( $after ? 'SUSPENDED' : 'restored' ) );
	}

	return $response;
}

// A schedule matching the config's 'check_hours'.
add_filter( 'cron_schedules', function ( $schedules ) {
	$hours = max( 1, (int) devbase_suspension_config()['check_hours'] );

	$schedules['devbase_licence'] = array(
		'interval' => $hours * HOUR_IN_SECONDS,
		'display'  => sprintf( 'Every %d hours (licence check)', $hours ),
	);

	return $schedules;
} );

add_action( 'init', function () {
	if ( devbase_suspension_enabled() && ! empty( devbase_suspension_config()['licence_lock'] ) && ! wp_next_scheduled( DEVBASE_LICENCE_CRON ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'devbase_licence', DEVBASE_LICENCE_CRON );
	}
} );

add_action( DEVBASE_LICENCE_CRON, 'devbase_run_licence_check' );

// REQUEST-DRIVEN REFRESH (the main path; it does not depend on WP-Cron, which many hosts
// cannot run reliably). The stored answer acts as the cache. When it is older than
// 'check_hours' (or, after a failed attempt, older than 'retry_minutes'), the first
// request that finds it stale refreshes it. Rules:
//   * Never make a visitor wait: the refresh runs on `shutdown`, after the page has been sent.
//   * Only one request refreshes at a time (a lock), so a burst of visitors cannot all call Warden.
//   * A failed attempt is not retried on every page: it waits 'retry_minutes'.
//   * Works while the site is suspended too, so it can restore itself.
// The parent records the time of EVERY attempt (`checked_at`, also failed ones) and the last error.

const DEVBASE_LICENCE_LOCK = 'devbase_licence_lock';

/** True when the stored answer is old enough that a request should refresh it. */
function devbase_licence_due() {
	$config = devbase_suspension_config();

	if ( ! devbase_suspension_enabled() || empty( $config['licence_lock'] ) ) {
		return false;
	}

	$state = get_option( 'devbase_warden_state_' . get_stylesheet() );
	$state = is_array( $state ) ? $state : array();
	$last  = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
	$age   = time() - $last;

	// The last attempt failed: retry sooner than the normal interval, but not at once.
	if ( ! empty( $state['last_error'] ) ) {
		return $age >= max( 1, (int) $config['retry_minutes'] ) * MINUTE_IN_SECONDS;
	}

	return $age >= max( 1, (int) $config['check_hours'] ) * HOUR_IN_SECONDS;
}

/** Takes the single-flight lock. The insert is atomic; a lock older than a minute is stale. */
function devbase_licence_lock() {
	if ( add_option( DEVBASE_LICENCE_LOCK, time(), '', false ) ) {
		return true;
	}

	if ( ( time() - (int) get_option( DEVBASE_LICENCE_LOCK ) ) > MINUTE_IN_SECONDS ) {
		delete_option( DEVBASE_LICENCE_LOCK );

		return add_option( DEVBASE_LICENCE_LOCK, time(), '', false );
	}

	return false;
}

add_action( 'shutdown', function () {
	if ( 'cli' === PHP_SAPI || wp_doing_cron() || defined( 'WP_INSTALLING' ) ) {
		return;
	}

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
		return;
	}

	if ( ! devbase_licence_due() || ! devbase_licence_lock() ) {
		return;
	}

	// Let the visitor go first: finish the response, then talk to Warden.
	ignore_user_abort( true );

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} else {
		while ( ob_get_level() > 0 ) {
			@ob_end_flush();
		}
		flush();
	}

	try {
		devbase_run_licence_check();
	} finally {
		delete_option( DEVBASE_LICENCE_LOCK );
	}
}, 9999 );

// Take the schedule off when the theme is switched away.
add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( DEVBASE_LICENCE_CRON );
} );
