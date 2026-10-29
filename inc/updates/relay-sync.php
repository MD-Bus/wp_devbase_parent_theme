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
//   { ..., "params": { "warden_base_url":       "https://...",
//                      "warden_public_key":     "base64...",
//                      "warden_parent_licence": "WRD-...",
//                      "warden_child_licence":  "WRD-..." } }
//
// NAMING. One name per value, everywhere. A Brand Relay param is called
// exactly like the Licence-page field it fills (see devbase_relay_params()),
// so there is no mapping table to remember. The params must be SITE params in
// Brand Relay, never brand params: brand params are public, site params reach
// only the site holding the key.
//
// The ONLY two values typed on this site are the Brand Relay URL and site key
// (fields `relay_base_url` and `relay_site_key`, Theme Settings > Licence >
// Brand Relay). Everything else is provisioned. For development, or to keep
// them out of the database, wp-config.php may set either one, and a constant
// wins over its field:
//
//   define( 'DEVBASE_RELAY_URL',      'https://relay.example.com' );
//   define( 'DEVBASE_RELAY_SITE_KEY', 'brs_...' );
//
// There is NO scheduled sync. A sync runs only when something asks for it:
//
// 1. A ping: POST /wp-json/devbase/v1/sync (documented in the README). The
//    ping carries NO licence data. It only makes the site pull, over TLS,
//    from the configured Brand Relay address, so a forged or replayed ping can
//    cause one rate-limited fetch and nothing else. The one endpoint that
//    touches licences can never be used to plant one. A ping must also name
//    the Brand Relay as its Origin (see devbase_relay_verify_ping()).
// 2. An administrator: saving the Licence page, or "Check for Updates Now".
//
// So a value changed or removed on the site is put back by the next ping, not
// before; send a ping whenever a licence is changed in Brand Relay.
//
// Fail soft, same rule as warden.php: any failure (unreachable, bad status,
// malformed JSON) leaves the current values in place. An empty or missing
// param means "no change", never "clear": revoking a licence is done in
// Warden, where it is actually enforced.
//
// The Warden address and signing key are FILL-ONLY: accepted when the field
// is empty (a fresh install or a removed value), never overwritten while one
// is present. They decide where theme code is downloaded from and which
// signature to trust, so they must not be changeable by whoever controls a
// Brand Relay account. Licence keys carry no such power (a wrong one is simply
// rejected by Warden), so they are always brought back in line.
//
// The root of the chain is the Brand Relay URL and site key themselves: they
// are what a sync needs, so they cannot be restored by one. Set them as
// wp-config.php constants where a site's users could otherwise clear the field.

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
 * The provisionable values: param name (identical to the field name) =>
 * how to treat it. `fill_only` values are never overwritten while present;
 * `track` is the theme whose cached Warden answer a change invalidates. Order
 * matters: the address and key are written before the licences that depend on
 * them.
 */
function devbase_relay_params() {
	return array(
		'warden_base_url'       => array( 'fill_only' => true,  'track' => '' ),
		'warden_public_key'     => array( 'fill_only' => true,  'track' => '' ),
		'warden_parent_licence' => array( 'fill_only' => false, 'track' => get_template() ),
		'warden_child_licence'  => array( 'fill_only' => false, 'track' => is_child_theme() ? get_stylesheet() : '' ),
	);
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
			$url = esc_url_raw( $value );

			return ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) || 'local' === wp_get_environment_type() ) ? untrailingslashit( $url ) : '';

		case 'warden_public_key':
			$raw = base64_decode( $value, true );

			return ( false !== $raw && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) ) ? $value : '';

		default:
			return substr( sanitize_text_field( $value ), 0, 128 );
	}
}

function devbase_relay_get_state() {
	$state = get_option( DEVBASE_RELAY_STATE_OPTION );

	return is_array( $state ) ? $state : array();
}

/**
 * Fetches this site's private params from Brand Relay.
 *
 * @return array|string The params array, or an error message.
 */
function devbase_relay_fetch_params() {
	$response = wp_remote_get(
		devbase_relay_url() . '/v1/site',
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . devbase_relay_site_key(),
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

	if ( ! is_array( $data ) ) {
		return 'malformed JSON';
	}

	return isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array();
}

/**
 * Copies the provisioned values into the Licence page's options.
 *
 * @param bool $respect_interval Apply the minimum gap between pulls. True for
 *                               pings; the admin paths pass false.
 * @return array { ok: bool, error: string, changed: string[] } where
 *               `changed` lists param names, never values.
 */
function devbase_relay_sync( $respect_interval = false ) {
	$result = array(
		'ok'      => false,
		'error'   => '',
		'changed' => array(),
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

	$stale = array();

	foreach ( devbase_relay_params() as $name => $rule ) {
		if ( ! isset( $params[ $name ] ) ) {
			continue;
		}

		$value   = devbase_relay_clean_param( $name, $params[ $name ] );
		$current = trim( (string) get_field( $name, 'option' ) );

		if ( '' === $value || $value === $current || ( $rule['fill_only'] && '' !== $current ) ) {
			continue;
		}

		update_field( $name, $value, 'option' );
		$result['changed'][] = $name;

		if ( '' !== $rule['track'] ) {
			$stale[] = $rule['track'];
		}
	}

	// A new key makes the cached Warden answer about the OLD key. Drop it so
	// the next check asks about the new one, and ask now rather than waiting
	// for core's schedule: that is the point of changing a licence remotely.
	if ( $result['changed'] ) {
		foreach ( $stale as $stylesheet ) {
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
	$nonce_key = 'devbase_relay_nonce_' . md5( $nonce );

	if ( false !== get_transient( $nonce_key ) ) {
		return $denied;
	}

	set_transient( $nonce_key, 1, DEVBASE_RELAY_PING_WINDOW * 2 );

	return true;
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
		'callback'            => function () {
			$result = devbase_relay_sync( true );

			return new WP_REST_Response(
				array(
					'ok'      => $result['ok'],
					'error'   => $result['error'],
					'changed' => $result['changed'],
				),
				'rate limited' === $result['error'] ? 429 : 200
			);
		},
	) );
} );
