<?php

// Error tracking: the site's fatal errors and uncaught exceptions, sent to Brand
// Relay (POST /v1/site/events) when its signed ping names `events`.
//
// It is a plan service ("events"). The site COLLECTS only while the saved plan says
// the service is in force (so a plan without it costs nothing), keeps the errors in a
// small queue, and SENDS them when Brand Relay asks (the plan's "Ask for errors" interval),
// like every other service: Brand Relay is the clock (see maintenance.php).
//
// Fatals (a shutdown function) AND warnings, notices and deprecations (a chained PHP error
// handler that only counts, see "Warnings and notices" below). What is kept is scrubbed here
// before it is queued and again by Brand Relay: first line of the message, paths cut to
// wp-content/..., no emails, no tokens, no query strings, never request data.

/** The queue of errors waiting to be sent: fingerprint => event. Not autoloaded. */
const DEVBASE_EVENTS_QUEUE = 'devbase_events_queue';

/** The last send: { sent_at, accepted, last_error }. */
const DEVBASE_EVENTS_STATE = 'devbase_events_state';

/** The queue never holds more distinct errors than this (the least severe, then the oldest, go first). */
const DEVBASE_EVENTS_QUEUE_MAX = 200;

/** One request notes at most this many distinct warnings/notices (a loop that warns 10 000 times is one row with a count). */
const DEVBASE_EVENTS_REQUEST_MAX = 25;

/** An error already queued within this many seconds is not written again (a noisy site must not write the option on every request). */
const DEVBASE_EVENTS_REWRITE_AFTER = 60;

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

	devbase_events_enqueue( array( devbase_events_build( $error ) ) );

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

/** How severe a level is: lower is more important (used to decide what the full queue drops and what is sent first). */
function devbase_events_rank( $level ) {
	return array( 'fatal' => 0, 'error' => 1, 'warning' => 2, 'notice' => 3 )[ $level ] ?? 1;
}

/**
 * Merges events into the queue: the same error adds to `count`, a new one is added, and past the cap the least
 * severe, then the oldest, go. One read and at most one write of the option.
 *
 * An error already queued a moment ago is left alone (no write at all), so a site that warns on every request
 * does not write the database on every request; its count is then a little low, which is fine.
 *
 * @param array[] $events Events as devbase_events_build() makes them (with `level` and `count` set).
 * @return bool Whether the queue changed.
 */
function devbase_events_enqueue( array $events ) {
	$queue   = get_option( DEVBASE_EVENTS_QUEUE );
	$queue   = is_array( $queue ) ? $queue : array();
	$changed = false;

	foreach ( $events as $event ) {
		$fingerprint = devbase_events_fingerprint( $event );

		if ( isset( $queue[ $fingerprint ] ) ) {
			if ( 'fatal' !== $event['level'] && time() - (int) $queue[ $fingerprint ]['at'] < DEVBASE_EVENTS_REWRITE_AFTER ) {
				continue;
			}

			$queue[ $fingerprint ]['count'] = (int) $queue[ $fingerprint ]['count'] + (int) $event['count'];
			$queue[ $fingerprint ]['at']    = $event['at'];
		} else {
			$queue[ $fingerprint ] = $event;
		}

		$changed = true;
	}

	if ( ! $changed ) {
		return false;
	}

	if ( count( $queue ) > DEVBASE_EVENTS_QUEUE_MAX ) {
		$order = array_keys( $queue );
		usort( $order, function ( $a, $b ) use ( $queue ) {
			return devbase_events_rank( $queue[ $a ]['level'] ) <=> devbase_events_rank( $queue[ $b ]['level'] ) ?: (int) $queue[ $b ]['at'] <=> (int) $queue[ $a ]['at'];
		} );
		$queue = array_intersect_key( $queue, array_flip( array_slice( $order, 0, DEVBASE_EVENTS_QUEUE_MAX ) ) );
	}

	update_option( DEVBASE_EVENTS_QUEUE, $queue, false );

	return true;
}

// --- Warnings and notices -----------------------------------------------------------------------
//
// A PHP error handler that CHANGES NOTHING and costs almost nothing:
//
// - It is only installed while the saved plan says error tracking is in force (a plan without it costs nothing).
// - It calls whatever handler was there before it (WordPress's own, Query Monitor ...) and returns that handler's answer, or
//   false (PHP's normal handling goes on) when there was none. What the site shows and logs does not change.
// - Per error it does an array lookup and a counter: no database, no regular expressions, no backtrace. The message is
//   kept once per distinct place (file, line, kind), the rest only count. At most DEVBASE_EVENTS_REQUEST_MAX places per request.
// - It leaves errors silenced with `@` alone.
// - Everything else (scrubbing, the queue) happens ONCE, in a shutdown function, and only for a request that had something.

/** The Brand Relay level for a PHP error number, or null for one we do not keep (fatals have their own path). */
function devbase_events_level( $errno ) {
	switch ( $errno ) {
		case E_WARNING:
		case E_USER_WARNING:
		case E_CORE_WARNING:
		case E_COMPILE_WARNING:
			return 'warning';
		case E_NOTICE:
		case E_USER_NOTICE:
		case E_DEPRECATED:
		case E_USER_DEPRECATED:
			return 'notice';
		case E_RECOVERABLE_ERROR:
		case E_USER_ERROR:
			return 'error';
	}

	return null;
}

/**
 * The warnings and notices this request has seen, by place; the handler this chained to; and a reset for tests.
 *
 * @param string $what notes | previous | reset
 * @param mixed  $value For `previous`: the handler to remember.
 * @return mixed
 */
function &devbase_events_state( $what = 'notes', $value = null ) {
	static $notes    = array();
	static $previous = null;
	static $null     = null;

	if ( 'reset' === $what ) {
		$notes    = array();
		$previous = null;

		return $null;
	}

	if ( 'previous' === $what ) {
		if ( null !== $value ) {
			$previous = $value;
		}

		return $previous;
	}

	return $notes;
}

/** The PHP error handler. Notes the error, then hands it on exactly as PHP or the earlier handler would have. */
function devbase_events_handler( $errno, $errstr, $errfile = '', $errline = 0 ) {
	static $busy = false;

	if ( ! $busy ) {
		$busy = true;

		// `@` leaves only the fatal bits in error_reporting(): leave those errors alone.
		if ( ( error_reporting() & ~4437 ) && null !== devbase_events_level( $errno ) ) {
			$notes = &devbase_events_state( 'notes' );
			$key   = $errno . '|' . $errfile . ':' . $errline;

			if ( isset( $notes[ $key ] ) ) {
				++$notes[ $key ]['count'];
			} elseif ( count( $notes ) < DEVBASE_EVENTS_REQUEST_MAX ) {
				$notes[ $key ] = array( 'type' => $errno, 'message' => substr( (string) $errstr, 0, 1000 ), 'file' => (string) $errfile, 'line' => (int) $errline, 'count' => 1 );
			}
		}

		$busy = false;
	}

	$previous = devbase_events_state( 'previous' );

	return $previous ? call_user_func( $previous, $errno, $errstr, $errfile, $errline ) : false;
}

/** Turns what the handler noted into queued events: once, at the end of a request that had something. */
function devbase_events_flush() {
	$notes = devbase_events_state( 'notes' );

	if ( ! $notes ) {
		return false;
	}

	if ( ! function_exists( 'devbase_relay_service_in_force' ) || ! devbase_relay_service_in_force( 'events' ) ) {
		return false;
	}

	$events = array();

	foreach ( $notes as $note ) {
		try {
			$event          = devbase_events_build( array( 'type' => $note['type'], 'message' => $note['message'], 'file' => $note['file'], 'line' => $note['line'] ) );
			$event['level'] = devbase_events_level( $note['type'] );
			$event['count'] = $note['count'];
			$event['type']  = in_array( $note['type'], array( E_DEPRECATED, E_USER_DEPRECATED ), true ) ? 'deprecated' : $event['type'];
			$events[]       = $event;
		} catch ( Throwable $e ) {
			continue; // Never let reporting an error be the error.
		}
	}

	devbase_events_state( 'reset' );

	return $events && devbase_events_enqueue( $events );
}

/** Installs the handler (and its shutdown flush) while the plan has error tracking. */
function devbase_events_install() {
	if ( ! function_exists( 'devbase_relay_service_in_force' ) || ! devbase_relay_service_in_force( 'events' ) ) {
		return false;
	}

	$previous = set_error_handler( 'devbase_events_handler' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler

	// Installed twice, the "earlier handler" would be ourselves and the handler would call itself without end:
	// the second time changes nothing.
	if ( 'devbase_events_handler' === $previous ) {
		return true;
	}

	devbase_events_state( 'previous', $previous );
	register_shutdown_function( 'devbase_events_flush' );

	return true;
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

devbase_events_install();

/**
 * The request body for a batch. `at` goes as an ISO 8601 time: Brand Relay ignores a number and would date every event
 * by the moment the batch arrived, which is wrong for an error that waited in the queue.
 */
function devbase_events_body( array $batch ) {
	$events = array();

	foreach ( $batch as $event ) {
		$event['at'] = gmdate( 'c', (int) $event['at'] );
		$events[]    = $event;
	}

	return wp_json_encode( array( 'events' => $events ) );
}

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

	// One batch: at most 50 events and 60 KB, the most severe first (a fatal never waits behind notices), then the oldest.
	$order = array_keys( $queue );
	usort( $order, function ( $a, $b ) use ( $queue ) {
		return devbase_events_rank( $queue[ $a ]['level'] ) <=> devbase_events_rank( $queue[ $b ]['level'] );
	} );
	$batch = array();
	foreach ( array_slice( $order, 0, DEVBASE_EVENTS_BATCH_MAX ) as $key ) {
		$batch[ $key ] = $queue[ $key ]; // In that order: if the body is too big, the last (least severe) ones are the ones to wait.
	}
	$body  = devbase_events_body( $batch );

	while ( strlen( (string) $body ) > DEVBASE_EVENTS_BATCH_BYTES && count( $batch ) > 1 ) {
		array_pop( $batch );
		$body = devbase_events_body( $batch );
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
		devbase_events_dequeue( $batch );

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
		devbase_events_dequeue( $batch );
	}

	return devbase_events_note( array( 'status' => 'error', 'detail' => "HTTP {$code}" ) );
}

/**
 * Takes a sent batch off the queue. The queue is read again here, not reused from before the send:
 * errors queued by other requests during the (up to 10 second) call stay queued. An entry whose
 * count grew meanwhile keeps the difference.
 *
 * @param array $batch fingerprint => event, as sent.
 */
function devbase_events_dequeue( array $batch ) {
	wp_cache_delete( DEVBASE_EVENTS_QUEUE, 'options' );

	$queue = get_option( DEVBASE_EVENTS_QUEUE );
	$queue = is_array( $queue ) ? $queue : array();

	foreach ( $batch as $fingerprint => $sent ) {
		if ( ! isset( $queue[ $fingerprint ] ) ) {
			continue;
		}

		$left = (int) $queue[ $fingerprint ]['count'] - (int) ( $sent['count'] ?? 0 );

		if ( $left > 0 && (int) $queue[ $fingerprint ]['at'] > (int) ( $sent['at'] ?? 0 ) ) {
			$queue[ $fingerprint ]['count'] = $left;
		} else {
			unset( $queue[ $fingerprint ] );
		}
	}

	update_option( DEVBASE_EVENTS_QUEUE, $queue, false );
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
