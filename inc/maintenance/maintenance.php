<?php

// The maintenance check: what a Brand Relay ping can ask the site to do besides
// pulling its settings.
//
// BRAND RELAY IS THE CLOCK. Most sites get a few visits a day and many have WP-Cron
// off, so a site cannot be trusted to remember to report. Instead Brand Relay pings
// the signed sync route (POST /wp-json/devbase/v1/sync, the same URL as the settings
// sync) with a small signed JSON body naming what to run:
//
//     { "run": ["settings", "health", "events", "backup"] }
//
// An EMPTY body is the old ping and means settings only. The site runs the names it
// knows, ignores the rest, and answers { ok, error, changed, ran: { name: result } }
// where a result is ok, sent, started (a backup is under way), clear (checked, no errors), not_in_plan, skipped or error. Nothing here runs on a
// visit, on cron, or on a schedule.
//
// NEVER SEND IN THE BLIND. Before a service runs, the plan is pulled (GET /v1/site, unless
// it was pulled a minute ago, as when the same ping also ran `settings`), and the service
// runs only if the plan says it is in force. Brand Relay stays the authority and enforces its
// own side; a refusal is recorded. See inc/maintenance/AGENT.md.

/** Autoloaded: { attempted_at, plan_checked_at, plan_error, last_ping_at, services: { key: {...} } }. */
const DEVBASE_MAINTENANCE_STATE = 'devbase_maintenance_state';

/** The single-run lock (two pings at once must not both send). */
const DEVBASE_MAINTENANCE_LOCK = 'devbase_maintenance_lock';

/**
 * A plan pulled this many seconds ago or less is used as it is (a ping that also ran `settings`
 * has just pulled it). Anything older is pulled again first, so a plan changed in Brand Relay
 * is what "Run now" acts on.
 */
const DEVBASE_MAINTENANCE_PLAN_FRESH = 10;

/** The services Brand Relay can name (its own keys). `uptime` is done by Brand Relay itself. */
function devbase_maintenance_known() {
	return array( 'uptime', 'health', 'events', 'backup' );
}

/**
 * The services this site can run: key => callback. A callback returns array(
 * 'status' => ok|started|clear|idle|error|refused|not_in_plan, 'detail' => string, 'sent_at' => int (optional),
 * 'gap' => int (optional) ). `clear` (checked, no errors) is answered `clear`; `idle` (ran moments ago) is answered `skipped`; so is a known service
 * with no callback (uptime, and backup until it is built).
 *
 * @return array<string, callable>
 */
function devbase_maintenance_services() {
	return (array) apply_filters( 'devbase/maintenance/services', array(
		'health' => 'devbase_heartbeat_run',
		'events' => 'devbase_events_run',
		'backup' => 'devbase_backup_run',
	) );
}

/** The saved state. */
function devbase_maintenance_state() {
	$state = get_option( DEVBASE_MAINTENANCE_STATE );

	return is_array( $state ) ? $state : array();
}

/**
 * What a ping asks for, from its (signed) body. An empty or unreadable body is the
 * old ping: settings only. Otherwise the names the site knows, in order, without
 * repeats; names it does not know are ignored.
 *
 * @param string $body The raw request body.
 * @return string[]
 */
function devbase_maintenance_requested( $body ) {
	$data = json_decode( (string) $body, true );

	if ( ! is_array( $data ) || ! isset( $data['run'] ) || ! is_array( $data['run'] ) ) {
		return array( 'settings' );
	}

	$allowed = array_merge( array( 'settings' ), devbase_maintenance_known() );

	return array_values( array_unique( array_filter( $data['run'], function ( $name ) use ( $allowed ) {
		return is_string( $name ) && in_array( $name, $allowed, true );
	} ) ) );
}

/** Takes the single-run lock. The insert is atomic; a lock older than two minutes is stale. */
function devbase_maintenance_lock() {
	if ( add_option( DEVBASE_MAINTENANCE_LOCK, time(), '', false ) ) {
		return true;
	}

	if ( ( time() - (int) get_option( DEVBASE_MAINTENANCE_LOCK ) ) > 2 * MINUTE_IN_SECONDS ) {
		delete_option( DEVBASE_MAINTENANCE_LOCK );

		return add_option( DEVBASE_MAINTENANCE_LOCK, time(), '', false );
	}

	return false;
}

/**
 * Runs the named services (not `settings`, which is the sync), one at a time, and
 * says what became of each. Safe to call directly (`wp eval 'print_r( devbase_maintenance_run( array( "health" ) ) );'`).
 *
 * @param string[] $services Names from devbase_maintenance_requested().
 * @return array<string, string> name => ok | sent | clear | not_in_plan | skipped | error
 */
function devbase_maintenance_run( array $services ) {
	$ran = array();

	if ( ! $services ) {
		return $ran;
	}

	$state = devbase_maintenance_state();
	$now   = time();

	// NO DELAY OF OUR OWN: when Brand Relay asks, the site runs and reports. How often it asks is
	// Brand Relay's to manage from the plan (and its "Run now" buttons). The only refusal left
	// is that two pings cannot run at once: one is under way right now, which is what `skipped` says.
	if ( ! devbase_maintenance_lock() ) {
		return array_fill_keys( $services, 'skipped' );
	}

	try {
		$state['attempted_at'] = $now;
		$state['last_ping_at'] = $now;

		// The plan first: never send in the blind. Used as it is if it was pulled a minute ago.
		if ( $now - (int) ( devbase_relay_plan()['fetched_at'] ?? 0 ) > DEVBASE_MAINTENANCE_PLAN_FRESH ) {
			$fetched                  = devbase_relay_fetch_site();
			$state['plan_checked_at'] = $now;
			$state['plan_error']      = is_array( $fetched ) ? '' : (string) $fetched;
		}

		// No plan at all (never pulled, and the pull failed): nothing can be said to be in force.
		$no_plan = ! devbase_relay_plan() && ! empty( $state['plan_error'] );

		foreach ( $services as $key ) {
			$callback = devbase_maintenance_services()[ $key ] ?? null;

			if ( ! $callback ) {
				$ran[ $key ] = 'skipped';
				continue;
			}

			$service = isset( $state['services'][ $key ] ) && is_array( $state['services'][ $key ] ) ? $state['services'][ $key ] : array();

			if ( $no_plan ) {
				$ran[ $key ] = 'error';
				$service     = array_merge( $service, array( 'status' => 'error', 'detail' => (string) $state['plan_error'], 'attempted_at' => $now ) );
			} elseif ( ! devbase_relay_service_in_force( $key ) ) {
				$ran[ $key ] = 'not_in_plan';
				$service     = array_merge( $service, array( 'status' => 'not_in_plan', 'detail' => '', 'attempted_at' => $now ) );
			} else {
				$result  = (array) call_user_func( $callback );
				$status  = (string) ( $result['status'] ?? 'error' );
				$service = array_merge( $service, array(
					'status'       => $status,
					'detail'       => (string) ( $result['detail'] ?? '' ),
					'attempted_at' => $now,
				) );

				foreach ( array( 'sent_at', 'gap' ) as $field ) {
					if ( isset( $result[ $field ] ) ) {
						$service[ $field ] = (int) $result[ $field ];
					}
				}

				$ran[ $key ] = 'ok' === $status ? 'sent' : ( 'clear' === $status ? 'clear' : ( 'started' === $status ? 'started' : ( 'idle' === $status ? 'skipped' : ( 'not_in_plan' === $status ? 'not_in_plan' : 'error' ) ) ) );
			}

			$state['services'][ $key ] = $service;
		}

		update_option( DEVBASE_MAINTENANCE_STATE, $state, true );
	} finally {
		delete_option( DEVBASE_MAINTENANCE_LOCK );
	}

	return $ran;
}
