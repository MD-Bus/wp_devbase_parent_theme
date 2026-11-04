<?php

// Error tracking: the site's fatal errors and uncaught exceptions, sent to Brand
// Relay (POST /v1/site/events) when its signed ping names `events`.
//
// It is a plan service ("events"). The site COLLECTS only while the saved plan says
// the service is in force (so a plan without it costs nothing), keeps the errors in a
// small queue, and SENDS them when Brand Relay asks (the plan's "Ask for errors" interval),
// like every other service: Brand Relay is the clock (see maintenance.php).
//
// Fatals only, for now: warnings and notices flood, and catching them means taking over
// PHP's error handler. What is kept is scrubbed here before it is queued and again by Brand
// Relay: first line of the message, paths cut to wp-content/..., no emails, no tokens, no
// query strings, never request data.

/** The queue of errors waiting to be sent: fingerprint => event. Not autoloaded. */
const DEVBASE_EVENTS_QUEUE = 'devbase_events_queue';

/** The last send: { sent_at, accepted, last_error }. */
const DEVBASE_EVENTS_STATE = 'devbase_events_state';

/** The queue never holds more distinct errors than this (the oldest go first). */
const DEVBASE_EVENTS_QUEUE_MAX = 100;

/** Brand Relay takes at most 50 events and 64 KB per batch; stay under both. */
const DEVBASE_EVENTS_BATCH_MAX   = 50;
const DEVBASE_EVENTS_BATCH_BYTES = 60000;

/** The PHP error types that end a request. */
function devbase_events_fatal_types() {
	return array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR );
}

/**
 * A path as it is useful to read and safe to send: from wp-content/, wp-includes/
 * or wp-admin/ on, else just the file name. Never the server's directory layout.
 */
function devbase_events_relative_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );

	if ( preg_match( '~(wp-content|wp-includes|wp-admin)/.*$~', $path, $m ) ) {
		return $m[0];
	}

	return basename( $path );
}

/**
 * Scrubs a message for sending: the first line only (an exception's stack trace
 * follows it), paths cut down, emails, long mixed tokens and URL query strings
 * removed, and a length limit.
 */
function devbase_events_scrub( $text, $max = 500 ) {
	$text = trim( (string) preg_split( '/\R/', (string) $text )[0] );

	// Anything up to wp-content/, wp-includes/ or wp-admin/ is the server's directory layout, spaces in folder
	// names included ("/Users/me/Web Design/site/wp-content/..."): cut it, whatever it contains.
	$text = preg_replace( '~(?<![:\\w/\\\\])(?:[A-Za-z]:)?[\\\\/](?:[^\\\\/\'"():,]+[\\\\/])*?(wp-content|wp-includes|wp-admin)[\\\\/]~', '$1/', $text );

	// Any other file path, but never the middle of a URL: it cannot start right after a colon, a slash or a word character.
	$text = preg_replace_callback( '~(?<![:\\w/\\\\])(?:[A-Za-z]:)?[\\\\/](?:[^\s\'"():,]+[\\\\/])+[^\s\'"():,]*~', function ( $m ) {
		return devbase_events_relative_path( $m[0] );
	}, $text );
	$text = preg_replace( '~(https?://[^\s?#\'"]+)\?[^\s\'"]*~', '$1', $text );
	$text = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text );
	$text = preg_replace( '/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{24,}\b/', '[token]', $text );

	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
}

/** Where the request came from, in a word. */
function devbase_events_where() {
	if ( 'cli' === PHP_SAPI ) {
		return 'cli';
	}
	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return 'cron';
	}
	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		return 'ajax';
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return 'rest';
	}

	return function_exists( 'is_admin' ) && is_admin() ? 'admin' : 'frontend';
}

/**
 * One event from a PHP error array (as error_get_last() gives it), ready to queue.
 *
 * @param array $error { type, message, file, line }
 * @return array
 */
function devbase_events_build( array $error ) {
	$message = (string) ( $error['message'] ?? '' );
	$file    = devbase_events_relative_path( (string) ( $error['file'] ?? '' ) );
	$path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$context = array(
		'wp'    => get_bloginfo( 'version' ),
		'php'   => PHP_VERSION,
		'where' => devbase_events_where(),
	);

	if ( preg_match( '~wp-content/(plugins|themes|mu-plugins)/([^/]+)~', $file, $m ) ) {
		$context[ 'plugins' === $m[1] ? 'plugin' : ( 'themes' === $m[1] ? 'theme' : 'mu-plugin' ) ] = $m[2];
	}

	return array(
		'type'    => 0 === strpos( $message, 'Uncaught ' ) ? 'exception' : 'php',
		'level'   => 'fatal',
		'message' => devbase_events_scrub( $message ),
		'file'    => $file,
		'line'    => (int) ( $error['line'] ?? 0 ),
		'url'     => substr( $path, 0, 300 ),
		'count'   => 1,
		'at'      => time(),
		'context' => $context,
	);
}

/** Two errors are the same when their kind, place and message (numbers aside) are. */
function devbase_events_fingerprint( array $event ) {
	return md5( $event['type'] . '|' . $event['file'] . '|' . $event['line'] . '|' . preg_replace( '/\d+/', '#', $event['message'] ) );
}

/**
 * Queues the error that ended this request, if there is one and the plan has error
 * tracking in force. Called from two places (our shutdown function, and the wp_die
 * handler filter, since WordPress's own fatal-error handler ends the request with wp_die()
 * before later shutdown functions run) and acts once per request.
 *
 * @return bool Whether an error was queued.
 */
function devbase_events_capture() {
	if ( devbase_events_captured() ) {
		return false;
	}

	$error = apply_filters( 'devbase/events/last_error', error_get_last() );

	if ( ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), devbase_events_fatal_types(), true ) ) {
		return false;
	}

	devbase_events_captured( true );

	// Only while the plan says error tracking is in force: a plan without it costs nothing.
	if ( ! function_exists( 'devbase_relay_service_in_force' ) || ! devbase_relay_service_in_force( 'events' ) ) {
		return false;
	}

	$event       = devbase_events_build( $error );
	$fingerprint = devbase_events_fingerprint( $event );
	$queue       = get_option( DEVBASE_EVENTS_QUEUE );
	$queue       = is_array( $queue ) ? $queue : array();

	if ( isset( $queue[ $fingerprint ] ) ) {
		$queue[ $fingerprint ]['count'] = (int) $queue[ $fingerprint ]['count'] + 1;
		$queue[ $fingerprint ]['at']    = $event['at'];
	} else {
		$queue[ $fingerprint ] = $event;
	}

	if ( count( $queue ) > DEVBASE_EVENTS_QUEUE_MAX ) {
		$queue = array_slice( $queue, -DEVBASE_EVENTS_QUEUE_MAX, null, true );
	}

	update_option( DEVBASE_EVENTS_QUEUE, $queue, false );

	return true;
}

/**
 * Whether this request's fatal error has already been looked at (it is looked at once,
 * from whichever of the two hooks below fires first). Pass false to reset (tests).
 *
 * @param bool|null $set New state, or null to read it.
 */
function devbase_events_captured( $set = null ) {
	static $captured = false;

	if ( null !== $set ) {
		$captured = (bool) $set;
	}

	return $captured;
}

// Our own shutdown function, for the fatals WordPress's handler does not end the request on.
register_shutdown_function( 'devbase_events_capture' );

// WordPress's fatal-error handler ends the request with wp_die() inside ITS shutdown function,
// which runs before ours; this filter is on that path. Nothing is changed: the handler is
// returned as it came.
add_filter( 'wp_die_handler', function ( $handler ) {
	devbase_events_capture();

	return $handler;
}, 1 );

/**
 * Sends the queued errors (one batch) and says what happened, for the maintenance check to
 * record. Runs only while the plan says error tracking is in force.
 *
 * @return array{status: string, detail: string, sent_at?: int, checked_at?: int}
 */
function devbase_events_run() {
	$queue = get_option( DEVBASE_EVENTS_QUEUE );
	$queue = is_array( $queue ) && $queue ? $queue : array();

	// Nothing queued is still a report: an empty batch tells Brand Relay "checked, no errors"
	// (it answers `clear`), so a quiet site is told apart from a site that did not run.

	// One batch: at most 50 events and 60 KB, oldest first.
	$batch = array_slice( $queue, 0, DEVBASE_EVENTS_BATCH_MAX, true );
	$body  = wp_json_encode( array( 'events' => array_values( $batch ) ) );

	while ( strlen( (string) $body ) > DEVBASE_EVENTS_BATCH_BYTES && count( $batch ) > 1 ) {
		array_pop( $batch );
		$body = wp_json_encode( array( 'events' => array_values( $batch ) ) );
	}

	$response = wp_remote_post(
		devbase_relay_url() . '/v1/site/events',
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . devbase_relay_site_key(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'        => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return devbase_events_note( array( 'status' => 'error', 'detail' => $response->get_error_message() ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$data = is_array( $data ) ? $data : array();

	if ( 200 === $code && ! $batch ) {
		return devbase_events_note( array( 'status' => 'clear', 'detail' => '', 'checked_at' => time() ) );
	}

	if ( 200 === $code ) {
		// Taken in: off the queue, whatever was dropped for its own reasons (no message, past the 50th).
		update_option( DEVBASE_EVENTS_QUEUE, array_diff_key( $queue, $batch ), false );

		return devbase_events_note( array( 'status' => 'ok', 'detail' => '', 'sent_at' => time(), 'accepted' => (int) ( $data['accepted'] ?? count( $batch ) ) ) );
	}

	if ( 401 === $code ) {
		return array( 'status' => 'refused', 'detail' => '' );
	}

	if ( 403 === $code && 'feature_off' === ( $data['error'] ?? '' ) ) {
		return array( 'status' => 'not_in_plan', 'detail' => '' );
	}

	// 429: too soon after the last batch, or the plan's daily count is used up. Kept for the next ask.
	// An empty report that is too soon changes nothing: it ran moments ago, which is what `skipped` means.
	if ( 429 === $code && ! $batch && 'daily_limit' !== ( $data['error'] ?? '' ) ) {
		return array( 'status' => 'idle', 'detail' => '' );
	}

	if ( 429 === $code ) {
		return devbase_events_note( array( 'status' => 'error', 'detail' => 'daily_limit' === ( $data['error'] ?? '' ) ? 'daily limit reached' : 'too soon' ) );
	}

	// A batch Brand Relay will never accept (400, 413, 415) is dropped, so it cannot block the queue for good.
	if ( in_array( $code, array( 400, 413, 415 ), true ) ) {
		update_option( DEVBASE_EVENTS_QUEUE, array_diff_key( $queue, $batch ), false );
	}

	return devbase_events_note( array( 'status' => 'error', 'detail' => "HTTP {$code}" ) );
}

/** Keeps the outcome of a send for the status line, and returns it. */
function devbase_events_note( array $result ) {
	$state = get_option( DEVBASE_EVENTS_STATE );
	$state = is_array( $state ) ? $state : array();

	if ( ! empty( $result['sent_at'] ) ) {
		$state['sent_at']  = (int) $result['sent_at'];
		$state['accepted'] = (int) ( $state['accepted'] ?? 0 ) + (int) ( $result['accepted'] ?? 0 );
	}

	if ( ! empty( $result['checked_at'] ) ) {
		$state['checked_at'] = (int) $result['checked_at'];
	}

	$state['last_error'] = 'error' === $result['status'] ? (string) $result['detail'] : '';

	update_option( DEVBASE_EVENTS_STATE, $state, false );

	return $result;
}

/**
 * The Error tracking line for the Remote setup tab: errors waiting, and the last send.
 * (The developer's view; nothing about it is shown to a client.)
 */
function devbase_events_status_html() {
	if ( ! devbase_relay_enabled() ) {
		return '<p>' . esc_html__( 'Error tracking is off: enter the management server address and site access key first.', 'devbase_parent_theme' ) . '</p>';
	}

	$maintenance = devbase_maintenance_state()['services']['events']['status'] ?? '';

	if ( 'not_in_plan' === $maintenance || ! devbase_relay_service_in_force( 'events' ) ) {
		return '<p>' . esc_html__( 'Not included in this site\'s plan, so errors are not collected.', 'devbase_parent_theme' ) . '</p>';
	}

	$queue = get_option( DEVBASE_EVENTS_QUEUE );
	$state = get_option( DEVBASE_EVENTS_STATE );
	$state = is_array( $state ) ? $state : array();
	$line  = sprintf(
		/* translators: %d: how many distinct errors are waiting to be sent. */
		esc_html( _n( '%d error waiting to be sent.', '%d errors waiting to be sent.', is_array( $queue ) ? count( $queue ) : 0, 'devbase_parent_theme' ) ),
		is_array( $queue ) ? count( $queue ) : 0
	);

	if ( ! empty( $state['sent_at'] ) ) {
		/* translators: %s: how long ago, e.g. "3 hours". */
		$line .= ' ' . sprintf( esc_html__( 'Last sent %s ago.', 'devbase_parent_theme' ), esc_html( human_time_diff( (int) $state['sent_at'] ) ) );
	}

	if ( ! empty( $state['checked_at'] ) ) {
		/* translators: %s: how long ago, e.g. "3 hours". */
		$line .= ' ' . sprintf( esc_html__( 'Last checked, no errors, %s ago.', 'devbase_parent_theme' ), esc_html( human_time_diff( (int) $state['checked_at'] ) ) );
	}

	if ( ! empty( $state['last_error'] ) ) {
		/* translators: %s: the error. */
		$line .= ' ' . sprintf( esc_html__( 'The last send failed (%s).', 'devbase_parent_theme' ), esc_html( (string) $state['last_error'] ) );
	}

	return '<p>' . $line . '</p>';
}
