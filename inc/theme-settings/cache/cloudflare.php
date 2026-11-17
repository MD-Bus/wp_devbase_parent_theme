<?php

// Cloudflare's API, for the Cloudflare tab: clear the cache (everything, or a list of URLs), test the token, and
// create or remove the one cache rule that lets Cloudflare keep pages.
//
// THE RULE. Cloudflare does not cache HTML on its own. The rule the theme creates (in the zone's Cache Rules, the
// `http_request_cache_settings` phase) makes this site's pages eligible with edge TTL mode `bypass_by_default`:
// Cloudflare keeps a page only when the answer carries a Cache-Control header saying it may, and passes it through
// otherwise. headers.php sends that header only while "Let Cloudflare cache pages" is on and only on public pages.
// So the theme decides page by page, and switching the setting (or the whole integration) off stops page caching at
// once, even if the rule is left in Cloudflare. The rule also skips wp-admin, the login page, the REST API and any
// visitor with a logged-in, password or cart cookie, as a second fence.
//
// The rule is found again by its `ref` (DEVBASE_CACHE_CF_RULE_REF), so "Create" updates it in place and never adds a
// second one, and other rules in the zone are never touched.
//
// Requests time out after 10 seconds. Nothing here throws: every call answers { ok, status, result, error }.

const DEVBASE_CACHE_CF_API      = 'https://api.cloudflare.com/client/v4';
const DEVBASE_CACHE_CF_RULE_REF = 'devbase_cache_pages';

/** Most URLs one purge call may name (Free, Pro and Business; Enterprise allows more). */
const DEVBASE_CACHE_CF_URLS_PER_CALL = 100;

/** True for a well-formed zone ID. */
function devbase_cache_cf_valid_zone_id( $zone ) {
	return 1 === preg_match( '/^[a-f0-9]{32}$/', (string) $zone );
}

/** The zone ID: wp-config.php first, then the saved setting. '' when neither is valid. */
function devbase_cache_cf_zone_id() {
	$zone = defined( 'DEVBASE_CLOUDFLARE_ZONE_ID' ) ? (string) DEVBASE_CLOUDFLARE_ZONE_ID : (string) devbase_cache_setting( 'cache_cf_zone_id' );
	$zone = strtolower( trim( $zone ) );

	return devbase_cache_cf_valid_zone_id( $zone ) ? $zone : '';
}

/** The API token: wp-config.php first, then the saved setting. */
function devbase_cache_cf_token() {
	return trim( defined( 'DEVBASE_CLOUDFLARE_API_TOKEN' ) ? (string) DEVBASE_CLOUDFLARE_API_TOKEN : (string) devbase_cache_setting( 'cache_cf_api_token' ) );
}

/**
 * One call to the zone's part of the API.
 *
 * @param string     $method GET, POST, PUT, PATCH or DELETE.
 * @param string     $path   After /zones/{zone}, e.g. '/purge_cache'. '' for the zone itself.
 * @param array|null $body   Sent as JSON.
 * @return array { ok: bool, status: int, result: mixed, error: string }
 */
function devbase_cache_cf_request( $method, $path, $body = null ) {
	$zone  = devbase_cache_cf_zone_id();
	$token = devbase_cache_cf_token();

	if ( '' === $zone || '' === $token ) {
		return array(
			'ok'     => false,
			'status' => 0,
			'result' => null,
			'error'  => __( 'Cloudflare is not set up: the zone ID or the API token is missing.', 'devbase_parent_theme' ),
		);
	}

	$args = array(
		'method'  => $method,
		'timeout' => 10,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);

	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( DEVBASE_CACHE_CF_API . '/zones/' . $zone . $path, $args );

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'     => false,
			'status' => 0,
			'result' => null,
			'error'  => $response->get_error_message(),
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$ok     = $status >= 200 && $status < 300 && is_array( $json ) && ! empty( $json['success'] );
	$error  = '';

	if ( ! $ok ) {
		$messages = array();

		foreach ( (array) ( is_array( $json ) ? ( $json['errors'] ?? array() ) : array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['message'] ) ) {
				$messages[] = (string) $item['message'] . ( isset( $item['code'] ) ? ' (' . (int) $item['code'] . ')' : '' );
			}
		}

		$error = $messages ? implode( '; ', $messages ) : 'HTTP ' . $status;

		if ( 403 === $status || 401 === $status ) {
			$error .= ' — ' . __( 'the token is wrong, or it lacks a permission listed above the Zone ID field.', 'devbase_parent_theme' );
		}
	}

	return array(
		'ok'     => $ok,
		'status' => $status,
		'result' => is_array( $json ) ? ( $json['result'] ?? null ) : null,
		'error'  => $error,
	);
}

/** Clears everything in the zone. */
function devbase_cache_cf_purge_everything() {
	return devbase_cache_cf_request( 'POST', '/purge_cache', array( 'purge_everything' => true ) );
}

/**
 * Clears a list of URLs, in calls of DEVBASE_CACHE_CF_URLS_PER_CALL.
 *
 * @param string[] $urls Full URLs.
 * @return array { ok: bool, error: string }
 */
function devbase_cache_cf_purge_urls( array $urls ) {
	$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );

	foreach ( array_chunk( $urls, DEVBASE_CACHE_CF_URLS_PER_CALL ) as $chunk ) {
		$result = devbase_cache_cf_request( 'POST', '/purge_cache', array( 'files' => $chunk ) );

		if ( ! $result['ok'] ) {
			return array(
				'ok'    => false,
				'error' => $result['error'],
			);
		}
	}

	return array(
		'ok'    => true,
		'error' => '',
	);
}

/** True when the site's host is the zone's domain or a subdomain of it. */
function devbase_cache_cf_host_in_zone( $host, $zone_name ) {
	$host      = strtolower( (string) $host );
	$zone_name = strtolower( (string) $zone_name );

	return '' !== $zone_name && ( $host === $zone_name || substr( $host, -strlen( '.' . $zone_name ) ) === '.' . $zone_name );
}

/**
 * The "Test connection" button: reads the zone, checks it is this site's, and clears one URL nobody visits (the
 * only way to learn whether the token may clear the cache).
 *
 * @return array { ok: bool, message: string }
 */
function devbase_cache_cf_test() {
	$zone = devbase_cache_cf_request( 'GET', '' );

	if ( ! $zone['ok'] ) {
		return array(
			'ok'      => false,
			/* translators: %s: Cloudflare's error */
			'message' => sprintf( __( 'Could not read the zone: %s', 'devbase_parent_theme' ), $zone['error'] ),
		);
	}

	$name  = is_array( $zone['result'] ) ? (string) ( $zone['result']['name'] ?? '' ) : '';
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$lines = array(
		/* translators: %s: a domain name */
		'✓ ' . sprintf( __( 'Zone found: %s', 'devbase_parent_theme' ), $name ),
	);

	if ( ! devbase_cache_cf_host_in_zone( $host, $name ) ) {
		return array(
			'ok'      => false,
			/* translators: 1: this site's domain, 2: the zone's domain */
			'message' => implode( "\n", $lines ) . "\n✗ " . sprintf( __( 'This site is %1$s, which is not in the zone %2$s. Check the zone ID.', 'devbase_parent_theme' ), $host, $name ),
		);
	}

	$purge = devbase_cache_cf_request( 'POST', '/purge_cache', array( 'files' => array( home_url( '/devbase-cache-test' ) ) ) );

	if ( ! $purge['ok'] ) {
		return array(
			'ok'      => false,
			/* translators: %s: Cloudflare's error */
			'message' => implode( "\n", $lines ) . "\n✗ " . sprintf( __( 'The token may not clear the cache: %s', 'devbase_parent_theme' ), $purge['error'] ),
		);
	}

	$lines[] = '✓ ' . __( 'The token may clear the cache.', 'devbase_parent_theme' );

	return array(
		'ok'      => true,
		'message' => implode( "\n", $lines ),
	);
}

/**
 * The cache rule, for this site's host.
 *
 * @return array
 */
function devbase_cache_cf_rule_definition() {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	$expression = sprintf(
		'(http.host eq "%s" and not starts_with(http.request.uri.path, "/wp-admin") and not starts_with(http.request.uri.path, "/wp-login.php") and not starts_with(http.request.uri.path, "/wp-json/") and not http.request.uri.path contains "/wp-cron.php" and not http.cookie contains "wordpress_logged_in_" and not http.cookie contains "wp-postpass_" and not http.cookie contains "woocommerce_items_in_cart" and not http.cookie contains "comment_author_")',
		str_replace( array( '\\', '"' ), '', $host )
	);

	return array(
		'ref'               => DEVBASE_CACHE_CF_RULE_REF,
		'description'       => 'Dev Base theme: cache pages the site marks cacheable (managed from the site, Theme Settings > Cache)',
		'expression'        => $expression,
		'action'            => 'set_cache_settings',
		'action_parameters' => array(
			'cache'       => true,
			'edge_ttl'    => array( 'mode' => 'bypass_by_default' ),
			'browser_ttl' => array( 'mode' => 'respect_origin' ),
		),
		'enabled'           => true,
	);
}

/**
 * The zone's cache-rules ruleset and the theme's rule in it.
 *
 * @return array { ok: bool, error: string, ruleset: string ('' when the zone has none), rule: string ('' when absent) }
 */
function devbase_cache_cf_find_rule() {
	$entry = devbase_cache_cf_request( 'GET', '/rulesets/phases/http_request_cache_settings/entrypoint' );

	if ( 404 === $entry['status'] ) {
		return array(
			'ok'      => true,
			'error'   => '',
			'ruleset' => '',
			'rule'    => '',
		);
	}

	if ( ! $entry['ok'] || ! is_array( $entry['result'] ) ) {
		return array(
			'ok'      => false,
			'error'   => $entry['error'],
			'ruleset' => '',
			'rule'    => '',
		);
	}

	$rule = '';

	foreach ( (array) ( $entry['result']['rules'] ?? array() ) as $item ) {
		if ( is_array( $item ) && DEVBASE_CACHE_CF_RULE_REF === ( $item['ref'] ?? '' ) ) {
			$rule = (string) ( $item['id'] ?? '' );
			break;
		}
	}

	return array(
		'ok'      => true,
		'error'   => '',
		'ruleset' => (string) ( $entry['result']['id'] ?? '' ),
		'rule'    => $rule,
	);
}

/**
 * Creates the rule, or updates it when it is already there. Other rules in the zone are kept.
 *
 * @return array { ok: bool, message: string }
 */
function devbase_cache_cf_rule_install() {
	$found = devbase_cache_cf_find_rule();

	if ( ! $found['ok'] ) {
		/* translators: %s: Cloudflare's error */
		return array( 'ok' => false, 'message' => sprintf( __( 'Could not read the zone\'s cache rules: %s', 'devbase_parent_theme' ), $found['error'] ) );
	}

	$rule = devbase_cache_cf_rule_definition();

	if ( '' === $found['ruleset'] ) {
		// No cache rules at all yet: create the phase's ruleset with this one rule.
		$result = devbase_cache_cf_request( 'PUT', '/rulesets/phases/http_request_cache_settings/entrypoint', array( 'rules' => array( $rule ) ) );
	} elseif ( '' === $found['rule'] ) {
		$result = devbase_cache_cf_request( 'POST', '/rulesets/' . rawurlencode( $found['ruleset'] ) . '/rules', $rule );
	} else {
		$result = devbase_cache_cf_request( 'PATCH', '/rulesets/' . rawurlencode( $found['ruleset'] ) . '/rules/' . rawurlencode( $found['rule'] ), $rule );
	}

	if ( ! $result['ok'] ) {
		/* translators: %s: Cloudflare's error */
		return array( 'ok' => false, 'message' => sprintf( __( 'The rule could not be saved: %s', 'devbase_parent_theme' ), $result['error'] ) );
	}

	$after = devbase_cache_cf_find_rule();

	devbase_cache_update_status( array(
		'rule' => array(
			'id'      => $after['rule'],
			'ruleset' => $after['ruleset'],
			'at'      => time(),
		),
	) );

	return array(
		'ok'      => true,
		'message' => '' === $found['rule'] ? __( 'Cache rule created.', 'devbase_parent_theme' ) : __( 'Cache rule updated.', 'devbase_parent_theme' ),
	);
}

/**
 * Removes the theme's rule. Other rules in the zone are kept.
 *
 * @return array { ok: bool, message: string }
 */
function devbase_cache_cf_rule_remove() {
	$found = devbase_cache_cf_find_rule();

	if ( ! $found['ok'] ) {
		/* translators: %s: Cloudflare's error */
		return array( 'ok' => false, 'message' => sprintf( __( 'Could not read the zone\'s cache rules: %s', 'devbase_parent_theme' ), $found['error'] ) );
	}

	if ( '' !== $found['rule'] ) {
		$result = devbase_cache_cf_request( 'DELETE', '/rulesets/' . rawurlencode( $found['ruleset'] ) . '/rules/' . rawurlencode( $found['rule'] ) );

		if ( ! $result['ok'] ) {
			/* translators: %s: Cloudflare's error */
			return array( 'ok' => false, 'message' => sprintf( __( 'The rule could not be removed: %s', 'devbase_parent_theme' ), $result['error'] ) );
		}
	}

	devbase_cache_update_status( array( 'rule' => array() ) );

	return array(
		'ok'      => true,
		'message' => '' === $found['rule'] ? __( 'There was no rule to remove.', 'devbase_parent_theme' ) : __( 'Cache rule removed.', 'devbase_parent_theme' ),
	);
}
