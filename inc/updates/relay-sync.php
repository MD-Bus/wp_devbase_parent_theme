<?php

// Pulls this site's Warden settings from Brand Relay, so a licence can be set,
// replaced or fixed from a distance without anyone touching the site.
//
// WHY IT EXISTS. A licence typed into the Licence page can be removed or
// changed by whoever uses the site, which cuts us off from fixing it
// remotely. Here the value lives in Brand Relay and is written back on every
// sync, so a removed or altered key is restored on the next pull or ping.
//
// Brand Relay (github.com/momardia54/brand-relay) is the delivery channel
// only. Warden stays the authority: it still validates every key, binds it to
// this domain, and signs the answer (see warden.php). This file never talks to
// Warden and never decides whether a licence is valid; it only copies values
// from one place to another.
//
//   GET {relay url}/v1/site        Authorization: Bearer {relay site key}
//   { ..., "params": { "warden_base_url":    "https://...",
//                      "warden_public_key":  "base64...",
//                      "warden_licence_key": "WRD-...",
//                      "relay_base_url":     "https://...",
//                      "relay_site_key":     "brs_..." } }
//
// ONE licence key (since 1.18.0; Warden 0.5.0 lets one key cover several
// apps). It is checked as the tag parent-theme for the base theme and child-theme for
// the site's own theme (see warden.php). The two params of 1.17.0 and earlier,
// `warden_parent_licence` and `warden_child_licence`, are no longer read.
//
// NAMING. One name per value, everywhere. A Brand Relay param is called
// exactly like the Licence-page field it fills (see devbase_relay_params()),
// so there is no mapping table to remember. The params must be SITE params in
// Brand Relay, never brand params: brand params are public, site params reach
// only the site holding the key.
//
// The ONLY two values typed on this site are the Brand Relay URL and site key
// (fields `relay_base_url` and `relay_site_key`, Theme Settings > Licence >
// Remote setup), and only once, to connect it. After that they can be changed
// from Brand Relay like everything else. For development, or to keep them out
// of the database, wp-config.php may set either one, and a constant wins over
// its field:
//
//   define( 'DEVBASE_RELAY_URL',      'https://relay.example.com' );
//   define( 'DEVBASE_RELAY_SITE_KEY', 'brs_...' );
//
// There is NO scheduled sync. A sync runs only when something asks for it:
//
// 1. A ping: POST /wp-json/devbase/v1/sync (documented in the README). The
//    ping carries NO licence data. It only makes the site pull, over TLS,
//    from the configured Brand Relay address, so a forged or replayed ping can
//    cause one rate-limited fetch and nothing else. Its signed body may also name
//    maintenance services to run (inc/maintenance/maintenance.php): names only,
//    from a fixed list, never values. The one endpoint that
//    touches licences can never be used to plant one. A ping must also name
//    the Brand Relay as its Origin (see devbase_relay_verify_ping()).
// 2. An administrator: saving the Licence page, or "Check licence and updates now".
//
// So a value changed or removed on the site is put back by the next ping, not
// before; send a ping whenever a licence is changed in Brand Relay.
//
// Fail soft, same rule as warden.php: any failure (unreachable, bad status,
// malformed JSON) leaves the current values in place. An empty or missing
// param means "no change", never "clear": revoking a licence is done in
// Warden, where it is actually enforced.
//
// EVERY value is brought back in line with Brand Relay on each sync. That is
// the point of it: a site must never need someone on it to move to another
// Warden, another signing key, another Brand Relay or another site key. It
// also means a Brand Relay account decides where theme code comes from, so that
// account must be protected like the code itself. To take that power away on a
// site, hold the value in code (devbase_relay_value_in_code()).
//
// The Brand Relay address and site key are what the next sync needs, so a wrong
// one would cut the site off for good. They are saved only after a request with
// the new values succeeds (devbase_relay_request()). If either one is removed on
// the site there is no sync to restore it; set them as wp-config.php constants
// where a site's users could otherwise clear the field.
//
// A value that is sent but not applied is named in `refused` in the answer
// (never its value), so Brand Relay can say so instead of "nothing changed".

define( 'DEVBASE_RELAY_SYNC_HOOK', 'devbase_relay_sync' ); // retired: only used to remove the old schedule

// Minimum gap between two pulls triggered from outside, so a ping flood
// can't turn this site into a request generator.
define( 'DEVBASE_RELAY_MIN_INTERVAL', 30 );

// How far a ping's timestamp may be from our clock, and how long its nonce
// is remembered (twice the window, so a nonce outlives every valid timestamp).
define( 'DEVBASE_RELAY_PING_WINDOW', 5 * MINUTE_IN_SECONDS );

define( 'DEVBASE_RELAY_STATE_OPTION', 'devbase_relay_sync_state' );

// Requests one IP may make to the ping route per window, valid or not.
define( 'DEVBASE_RELAY_PING_LIMIT', 10 );
define( 'DEVBASE_RELAY_PING_LIMIT_WINDOW', MINUTE_IN_SECONDS );

/**
 * Brand Relay base URL: the constant if defined, else the field. Must be
 * https, except on a local environment, so a misconfigured site can't send its
 * key in clear.
 */
function devbase_relay_url() {
	if ( defined( 'DEVBASE_RELAY_URL' ) ) {
		$url = (string) DEVBASE_RELAY_URL;
	} else {
		$url = function_exists( 'get_field' ) ? (string) get_field( 'relay_base_url', 'option' ) : '';
	}

	$url = untrailingslashit( trim( (string) apply_filters( 'devbase/relay/url', $url ) ) );

	if ( '' === $url ) {
		return '';
	}

	if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && 'local' !== wp_get_environment_type() ) {
		return '';
	}

	return $url;
}

/**
 * The Brand Relay's origin (scheme://host[:port], no path), which is what a
 * ping's Origin header must equal.
 */
function devbase_relay_origin() {
	$url = devbase_relay_url();

	if ( '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );

	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

	return strtolower( $parts['scheme'] . '://' . $parts['host'] . $port );
}

/**
 * The site key: the constant if defined, else the field.
 */
function devbase_relay_site_key() {
	if ( defined( 'DEVBASE_RELAY_SITE_KEY' ) ) {
		return trim( (string) DEVBASE_RELAY_SITE_KEY );
	}

	if ( ! function_exists( 'get_field' ) ) {
		return '';
	}

	return trim( (string) get_field( 'relay_site_key', 'option' ) );
}

/**
 * Whether this install is wired to a Brand Relay at all. When it isn't,
 * nothing in this file does anything.
 */
function devbase_relay_enabled() {
	return '' !== devbase_relay_url() && '' !== devbase_relay_site_key();
}

/**
 * The secret a ping is signed with. Derived from the site key, so there is no
 * second secret to provision: whoever holds the site key can sign, and the
 * key itself is never used as the HMAC key directly.
 */
function devbase_relay_ping_secret() {
	$key = devbase_relay_site_key();

	return '' === $key ? '' : hash_hmac( 'sha256', 'devbase-relay-ping', $key );
}

/**
 * The provisionable values: param name (identical to the field name) => the
 * themes whose cached Warden answer a change invalidates. The cached answer
 * was given by the old Warden, under the old key, about the old licence, so any
 * of the three invalidates it for both themes. Order matters: the address and
 * key are written before the licence that depends on them.
 */
function devbase_relay_params() {
	$themes = array_values( array_unique( array_filter( array( get_template(), is_child_theme() ? get_stylesheet() : '' ) ) ) );

	return array(
		'warden_base_url'    => $themes,
		'warden_public_key'  => $themes,
		'warden_licence_key' => $themes,
		'relay_base_url'     => array(),
		'relay_site_key'     => array(),
	);
}

/**
 * The value a param is held at in code, or null when it can be provisioned. A
 * value held in code always wins: it is the one way to refuse a param on a site.
 *
 * @return string|null
 */
function devbase_relay_value_in_code( $name ) {
	$value = null;

	if ( 'warden_base_url' === $name && defined( 'DEVBASE_WARDEN_URL' ) ) {
		$value = devbase_warden_base_url();
	} elseif ( 'warden_public_key' === $name && devbase_warden_pinned_keys() ) {
		$value = devbase_warden_public_key();
	} elseif ( 'relay_base_url' === $name && defined( 'DEVBASE_RELAY_URL' ) ) {
		$value = untrailingslashit( trim( (string) DEVBASE_RELAY_URL ) );
	} elseif ( 'relay_site_key' === $name && defined( 'DEVBASE_RELAY_SITE_KEY' ) ) {
		$value = trim( (string) DEVBASE_RELAY_SITE_KEY );
	}

	return apply_filters( 'devbase/relay/value_in_code', $value, $name );
}

/**
 * Cleans one incoming value, or returns '' when it isn't acceptable.
 */
function devbase_relay_clean_param( $name, $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$value = trim( $value );

	switch ( $name ) {
		case 'warden_base_url':
		case 'relay_base_url':
			$url = esc_url_raw( $value );

			return ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) || 'local' === wp_get_environment_type() ) ? untrailingslashit( $url ) : '';

		case 'warden_public_key':
			$raw = base64_decode( $value, true );

			return ( false !== $raw && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) ) ? $value : '';

		case 'relay_site_key':
			return preg_match( '/^brs_\S{1,250}$/', $value ) ? $value : '';

		default:
			return substr( sanitize_text_field( $value ), 0, 128 );
	}
}

function devbase_relay_get_state() {
	$state = get_option( DEVBASE_RELAY_STATE_OPTION );

	return is_array( $state ) ? $state : array();
}

/**
 * One `GET /v1/site` with the given address and key. Saves nothing, so it also
 * proves a new address and key work before they replace the current ones.
 *
 * @return array|string The decoded answer, or an error message.
 */
function devbase_relay_request( $url, $key ) {
	$response = wp_remote_get(
		$url . '/v1/site',
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response->get_error_message();
	}

	$code = wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		return "HTTP {$code}";
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	return is_array( $data ) ? $data : 'malformed JSON';
}

/**
 * Fetches this site's whole answer from Brand Relay (`GET /v1/site`): the params
 * and, for a site with a subscription, the `licence` object (plan, services and
 * limits in force). Every successful pull also refreshes the plan snapshot
 * (inc/maintenance/plan.php), since this is the one place it can change.
 *
 * @return array|string The decoded answer, or an error message.
 */
function devbase_relay_fetch_site() {
	$data = devbase_relay_request( devbase_relay_url(), devbase_relay_site_key() );

	if ( ! is_array( $data ) ) {
		return $data;
	}

	devbase_relay_store_plan( $data );

	return $data;
}

/**
 * Fetches this site's private params from Brand Relay.
 *
 * @return array|string The params array, or an error message.
 */
function devbase_relay_fetch_params() {
	$data = devbase_relay_fetch_site();

	if ( ! is_array( $data ) ) {
		return $data;
	}

	return isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array();
}

/**
 * Copies the provisioned values into the Licence page's options.
 *
 * @param bool $respect_interval Apply the minimum gap between pulls. True for
 *                               pings; the admin paths pass false.
 * @return array { ok: bool, error: string, changed: string[], refused: string[] }
 *               where `changed` and `refused` list param names, never values.
 */
function devbase_relay_sync( $respect_interval = false ) {
	$result = array(
		'ok'      => false,
		'error'   => '',
		'changed' => array(),
		'refused' => array(),
	);

	if ( ! devbase_relay_enabled() ) {
		$result['error'] = 'not configured';
		return $result;
	}

	if ( ! function_exists( 'update_field' ) ) {
		$result['error'] = 'ACF unavailable';
		return $result;
	}

	$state = devbase_relay_get_state();

	if ( $respect_interval && ( time() - (int) ( $state['attempted_at'] ?? 0 ) ) < DEVBASE_RELAY_MIN_INTERVAL ) {
		$result['error'] = 'rate limited';
		return $result;
	}

	// Recorded before the request, so a hanging Brand Relay is retried at the
	// pace of the interval and not on every ping.
	$state['attempted_at'] = time();
	update_option( DEVBASE_RELAY_STATE_OPTION, $state, false );

	$params = devbase_relay_fetch_params();

	if ( ! is_array( $params ) ) {
		$state['last_error'] = $params;
		update_option( DEVBASE_RELAY_STATE_OPTION, $state, false );
		error_log( "devbase_parent_theme: Brand Relay sync failed — {$params}" );

		$result['error'] = $params;
		return $result;
	}

	$rules    = devbase_relay_params();
	$incoming = array();

	foreach ( $rules as $name => $tracks ) {
		if ( ! isset( $params[ $name ] ) || ( is_string( $params[ $name ] ) && '' === trim( $params[ $name ] ) ) ) {
			continue;
		}

		$value = devbase_relay_clean_param( $name, $params[ $name ] );

		if ( '' === $value ) {
			$result['refused'][] = $name;
			continue;
		}

		$in_code = devbase_relay_value_in_code( $name );
		$current = null === $in_code ? trim( (string) get_field( $name, 'option' ) ) : $in_code;

		if ( $value === $current ) {
			continue;
		}

		if ( null !== $in_code ) {
			$result['refused'][] = $name;
			continue;
		}

		$incoming[ $name ] = $value;
	}

	// The next sync depends on these two: a pair that doesn't work is never saved.
	$connection = array_intersect_key( $incoming, array_flip( array( 'relay_base_url', 'relay_site_key' ) ) );

	if ( $connection ) {
		$answer = devbase_relay_request( $connection['relay_base_url'] ?? devbase_relay_url(), $connection['relay_site_key'] ?? devbase_relay_site_key() );

		if ( ! is_array( $answer ) ) {
			error_log( "devbase_parent_theme: new Brand Relay address or key not saved — {$answer}" );

			$incoming          = array_diff_key( $incoming, $connection );
			$result['refused'] = array_merge( $result['refused'], array_keys( $connection ) );
		}
	}

	$stale = array();

	foreach ( $incoming as $name => $value ) {
		update_field( $name, $value, 'option' );
		$result['changed'][] = $name;

		$stale = array_merge( $stale, $rules[ $name ] );

		// The single key replaces the two old per-theme keys for good.
		if ( 'warden_licence_key' === $name ) {
			devbase_warden_forget_legacy_licences();
		}
	}

	// A new Warden address, signing key or licence makes the cached answer out
	// of date. Drop it so the next check uses the new values, and ask now rather
	// than waiting for core's schedule: that is the point of changing them remotely.
	if ( $stale ) {
		foreach ( array_unique( $stale ) as $stylesheet ) {
			delete_option( devbase_warden_state_option( $stylesheet ) );
		}

		delete_site_transient( 'update_themes' );
		wp_update_themes();
	}

	$state['last_error']   = '';
	$state['succeeded_at'] = time();
	$state['last_changed'] = $result['changed'];
	update_option( DEVBASE_RELAY_STATE_OPTION, $state, false );

	$result['ok'] = true;
	return $result;
}

// Earlier versions synced twice a day through WP-Cron. Remove that event from
// any site that still has it; nothing schedules it again.
add_action( 'init', function () {
	if ( wp_next_scheduled( DEVBASE_RELAY_SYNC_HOOK ) ) {
		wp_clear_scheduled_hook( DEVBASE_RELAY_SYNC_HOOK );
	}
} );

// Saving the Licence page pulls straight away, so entering the URL and key is
// the whole setup: no separate button.
add_action( 'acf/save_post', function () {
	if ( ! isset( $_GET['page'] ) || 'licence-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	devbase_relay_sync( false );
}, 20 );

/**
 * Counts this request against its IP and says whether the IP is over the
 * limit. Runs before ANY other check, so unsigned and forged requests are
 * limited too: DEVBASE_RELAY_MIN_INTERVAL only protects the outbound fetch,
 * and only for requests that already passed the signature.
 *
 * Fixed window per IP, kept in a transient. The IP is REMOTE_ADDR, which behind
 * a proxy or CDN is the proxy's address, so there the limit is shared by every
 * client of that proxy; the filter `devbase/relay/ping_limit` can raise it.
 * A persistent object cache makes this cheaper, but is not required.
 *
 * @return bool True when the request must be refused.
 */
function devbase_relay_ping_throttled() {
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	$key    = 'devbase_relay_hits_' . md5( $ip );
	$limit  = (int) apply_filters( 'devbase/relay/ping_limit', DEVBASE_RELAY_PING_LIMIT );
	$now    = time();
	$bucket = get_transient( $key );

	if ( ! is_array( $bucket ) || ( $now - (int) $bucket['start'] ) >= DEVBASE_RELAY_PING_LIMIT_WINDOW ) {
		$bucket = array( 'start' => $now, 'count' => 0 );
	}

	if ( $bucket['count'] >= $limit ) {
		return true;
	}

	++$bucket['count'];
	set_transient( $key, $bucket, DEVBASE_RELAY_PING_LIMIT_WINDOW - ( $now - (int) $bucket['start'] ) );

	return false;
}

/**
 * Checks a ping. Three things must hold:
 *
 * 0. RATE. The caller's IP is within its request budget (see above).
 *
 * 1. ORIGIN. The Origin header must equal the configured Brand Relay's origin.
 *    A ping naming any other origin, or none, is refused before anything else
 *    is computed.
 * 2. SIGNATURE. HMAC-SHA256, hex, under devbase_relay_ping_secret(), over
 *      {timestamp}.{nonce}.{origin}.{raw body}
 *    The origin is part of the signed string, so a valid signature can't be
 *    re-used with a different Origin. The timestamp window and single-use
 *    nonce stop replays; the comparison is constant time.
 *
 * Be clear about what the Origin check is: a request header is a claim, not
 * proof, and a server-to-server caller can write any value. The signature is
 * what proves the caller holds this site's key. The Origin check stops a
 * pinger configured for a different Brand Relay (or a key reused across
 * deployments) from being accepted by mistake, and makes the rule explicit.
 *
 * @return true|WP_Error
 */
function devbase_relay_verify_ping( WP_REST_Request $request ) {
	$secret = devbase_relay_ping_secret();

	if ( '' === $secret ) {
		return new WP_Error( 'devbase_relay_disabled', 'Not available.', array( 'status' => 404 ) );
	}

	if ( devbase_relay_ping_throttled() ) {
		return new WP_Error( 'devbase_relay_throttled', 'Too many requests.', array( 'status' => 429 ) );
	}

	$timestamp = (string) $request->get_header( 'x-relay-timestamp' );
	$nonce     = (string) $request->get_header( 'x-relay-nonce' );
	$signature = strtolower( (string) $request->get_header( 'x-relay-signature' ) );
	$origin    = strtolower( rtrim( trim( (string) $request->get_header( 'origin' ) ), '/' ) );

	$denied = new WP_Error( 'devbase_relay_denied', 'Invalid request.', array( 'status' => 401 ) );

	if ( '' === $origin || ! hash_equals( devbase_relay_origin(), $origin ) ) {
		return $denied;
	}

	if ( ! ctype_digit( $timestamp ) || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
		return $denied;
	}

	if ( abs( time() - (int) $timestamp ) > DEVBASE_RELAY_PING_WINDOW ) {
		return $denied;
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $origin . '.' . $request->get_body(), $secret );

	if ( ! hash_equals( $expected, $signature ) ) {
		return $denied;
	}

	// Only a correctly signed nonce is recorded, so unsigned traffic can't
	// fill the transient table.
	// Recorded with add_option(), which is one atomic INSERT: two copies of the same ping arriving
	// together cannot both pass (a get-then-set on a transient let them). The value is the time it
	// may be forgotten; expired records are swept here, a few at a time.
	$nonce_key = 'devbase_relay_nonce_' . md5( $nonce );
	$now       = time();

	if ( ! add_option( $nonce_key, $now + DEVBASE_RELAY_PING_WINDOW * 2, '', false ) ) {
		if ( (int) get_option( $nonce_key ) > $now ) {
			return $denied;
		}

		// A record past its window: this nonce is no longer a replay (its timestamp is long refused anyway).
		update_option( $nonce_key, $now + DEVBASE_RELAY_PING_WINDOW * 2, false );
	}

	devbase_relay_forget_old_nonces( $now );

	return true;
}

/** Deletes up to 20 ping nonce records whose window has passed. */
function devbase_relay_forget_old_nonces( $now ) {
	global $wpdb;

	$names = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 20",
		$wpdb->esc_like( 'devbase_relay_nonce_' ) . '%',
		$now
	) );

	foreach ( (array) $names as $name ) {
		delete_option( $name );
	}
}

add_action( 'rest_api_init', function () {
	// Not wired to Brand Relay, no route: an install that didn't opt in
	// exposes nothing at all.
	if ( ! devbase_relay_enabled() ) {
		return;
	}

	register_rest_route( 'devbase/v1', '/sync', array(
		'methods'             => 'POST',
		'permission_callback' => 'devbase_relay_verify_ping',
		'callback'            => function ( WP_REST_Request $request ) {
			// The ping's signed body says what to run (inc/maintenance/maintenance.php): an
			// empty body is the old ping, settings only. Brand Relay is the clock.
			$run      = devbase_maintenance_requested( $request->get_body() );
			$services = array_values( array_diff( $run, array( 'settings' ) ) );
			$ran      = array();
			$result   = array(
				'ok'      => true,
				'error'   => '',
				'changed' => array(),
				'refused' => array(),
			);

			if ( in_array( 'settings', $run, true ) ) {
				$result          = devbase_relay_sync( true );
				$ran['settings'] = $result['ok'] ? 'ok' : 'error';
			}

			if ( $services ) {
				$ran = array_merge( $ran, devbase_maintenance_run( $services ) );
			}

			return new WP_REST_Response(
				array(
					'ok'      => $result['ok'],
					'error'   => $result['error'],
					'changed' => $result['changed'],
					'refused' => $result['refused'],
					'ran'     => (object) $ran,
				),
				( 'rate limited' === $result['error'] && ! $services ) ? 429 : 200
			);
		},
	) );
} );
