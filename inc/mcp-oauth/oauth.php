<?php

// Sign-in for MCP clients that can't send an Application Password: the
// "Add custom connector" dialog in Claude (web, desktop, mobile) and ChatGPT.
// They only take an address, then expect OAuth: they read where to log in,
// send the person to log in on this site and approve, and get a token.
//
// Implements the server side of the MCP authorization spec (revision
// 2026-07-28, https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization):
//
//   discovery   /.well-known/oauth-protected-resource[/<mcp path>]   (RFC 9728)
//               /.well-known/oauth-authorization-server[/<site path>] (RFC 8414)
//               and a 401 from the MCP route carrying WWW-Authenticate:
//               Bearer resource_metadata="…" (Claude starts the sign-in only on that)
//   clients     Client ID Metadata Documents (client_id is an https URL, fetched
//               and checked) and, for older clients, Dynamic Client Registration
//               (RFC 7591, deprecated by the spec but still used)
//   authorize   authorize.php: wp-admin login, then an Allow / Cancel page
//   token       authorization_code with PKCE S256 only, refresh_token rotated on
//               every use (reuse of an old one revokes the whole connection)
//   resource    tokens are bound to the MCP address (RFC 8707) and accepted
//               ONLY on the MCP route, nowhere else in the REST API
//
// HOW IT FITS WITH THE MCP ADAPTER: the adapter has no sign-in of its own; it
// asks WordPress who the user is and checks current_user_can(). This file
// answers "who is it" for a Bearer token through determine_current_user, the
// same hook Application Passwords use, so the adapter is untouched and Basic
// (Application Password) requests keep working exactly as before. One request
// carries one Authorization header, so the two never meet.
//
// STEPPING ASIDE: the adapter's maintainers plan their own sign-in
// (WordPress/mcp-adapter#394). devbase_mcp_oauth_other_provider() looks for
// one (or any other plugin's) and, when found, everything here switches off
// and Theme Settings says which one is in charge. The daily MCP Adapter
// release workflow (.github/workflows/mcp-adapter-release.yml) opens an issue
// for every new adapter version, which is when to read its changelog.
//
// STORAGE: nothing secret is stored readable. Codes and access tokens live in
// transients keyed by the SHA-256 of the token; a connection ("grant") lives
// in the user's meta with the hash of its current refresh token. Deleting the
// grant (Profile > Connected AI Apps) kills its access token on the next
// request, because every token check also checks the grant still exists.

const DEVBASE_MCP_OAUTH_SCOPE       = 'mcp';
const DEVBASE_MCP_OAUTH_CODE_TTL    = 10 * MINUTE_IN_SECONDS;
const DEVBASE_MCP_OAUTH_ACCESS_TTL  = HOUR_IN_SECONDS;
const DEVBASE_MCP_OAUTH_REFRESH_TTL = 30 * DAY_IN_SECONDS;
const DEVBASE_MCP_OAUTH_GRANTS_META = 'devbase_mcp_oauth_grants';
const DEVBASE_MCP_OAUTH_CLIENTS     = 'devbase_mcp_oauth_clients';

// ── Addresses ────────────────────────────────────────────────────────────

/** The MCP endpoint, which is the OAuth "resource". No trailing slash (spec). */
function devbase_mcp_oauth_resource() {
	return untrailingslashit( (string) apply_filters( 'devbase/mcp_oauth/resource', rest_url( 'mcp/mcp-adapter-default-server' ) ) );
}

/** The issuer: the site's own address (so clients that only try the root find it). */
function devbase_mcp_oauth_issuer() {
	return untrailingslashit( home_url() );
}

/** The path part of a URL, without a trailing slash ('' for the root). */
function devbase_mcp_oauth_path( $url ) {
	return untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
}

/** Where the protected-resource metadata is served (the path-insertion form). */
function devbase_mcp_oauth_resource_metadata_url() {
	$origin = devbase_mcp_oauth_origin( devbase_mcp_oauth_resource() );

	return $origin . '/.well-known/oauth-protected-resource' . devbase_mcp_oauth_path( devbase_mcp_oauth_resource() );
}

function devbase_mcp_oauth_origin( $url ) {
	$p = wp_parse_url( $url );

	return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
}

function devbase_mcp_oauth_authorize_url() {
	// https always: sign-in only runs on an HTTPS site, and admin_url() would
	// otherwise take the scheme of whatever request is asking.
	return set_url_scheme( add_query_arg( 'action', 'devbase_mcp_authorize', admin_url( 'admin-post.php' ) ), 'https' );
}

function devbase_mcp_oauth_endpoint( $name ) {
	return rest_url( 'devbase-oauth/v1/' . $name );
}

// ── On or off ────────────────────────────────────────────────────────────

/**
 * Another sign-in for the MCP endpoint, if one exists: its name, or ''.
 *
 * Looks at the REST routes for anything OAuth-like that isn't ours (an
 * authorize/token/register/.well-known route under the adapter's `mcp`
 * namespace, or any route naming OAuth for MCP), and at the
 * devbase/mcp_oauth/other_provider filter for anything this can't see.
 * Checked on rest_api_init, so it costs nothing on normal page views.
 */
function devbase_mcp_oauth_other_provider() {
	static $scanned = null;

	$found = null !== $scanned ? $scanned : '';

	if ( null === $scanned && did_action( 'rest_api_init' ) && function_exists( 'rest_get_server' ) ) {
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			$found = devbase_mcp_oauth_route_provider( $route );

			if ( '' !== $found ) {
				break;
			}
		}

		// Remembered only once the routes could be seen.
		$scanned = $found;
	}

	return (string) apply_filters( 'devbase/mcp_oauth/other_provider', $found );
}

/**
 * Who a REST route's OAuth belongs to, or '' when it isn't someone else's
 * MCP sign-in: anything OAuth-like under the adapter's `mcp` namespace, or a
 * route naming both MCP and OAuth. Ours (devbase-oauth/) never counts.
 */
function devbase_mcp_oauth_route_provider( $route ) {
	if ( 0 === strpos( $route, '/devbase-oauth/' ) ) {
		return '';
	}

	if ( 0 === strpos( $route, '/mcp/' ) && preg_match( '#oauth|/authorize|/token|/register|well-known#i', $route ) ) {
		return __( 'the MCP Adapter', 'devbase_parent_theme' );
	}

	if ( preg_match( '#mcp[^/]*/.*oauth|oauth[^/]*/.*mcp#i', $route ) ) {
		/* translators: %s: the plugin's REST route */
		return sprintf( __( 'another plugin (%s)', 'devbase_parent_theme' ), $route );
	}

	return '';
}

/**
 * Why sign-in is off, or '' when it is on. In order: switched off, plain
 * permalinks (the issuer and endpoints need real paths), not HTTPS (the spec
 * requires it), someone else handles it.
 */
function devbase_mcp_oauth_inactive_reason() {
	if ( ! get_field( 'mcp_oauth_enabled', 'option' ) ) {
		return __( 'Switched off.', 'devbase_parent_theme' );
	}

	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		return __( 'Needs pretty permalinks (Settings > Permalinks, any choice except Plain).', 'devbase_parent_theme' );
	}

	if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
		return __( 'Needs the site to run on HTTPS.', 'devbase_parent_theme' );
	}

	$other = devbase_mcp_oauth_other_provider();

	if ( '' !== $other ) {
		/* translators: %s: who handles sign-in instead */
		return sprintf( __( 'Sign-in is handled by %s, so this theme\'s is off.', 'devbase_parent_theme' ), $other );
	}

	return '';
}

function devbase_mcp_oauth_active() {
	return '' === devbase_mcp_oauth_inactive_reason();
}

// ── Discovery documents ──────────────────────────────────────────────────

function devbase_mcp_oauth_resource_metadata() {
	return array(
		'resource'                 => devbase_mcp_oauth_resource(),
		'authorization_servers'    => array( devbase_mcp_oauth_issuer() ),
		'scopes_supported'         => array( DEVBASE_MCP_OAUTH_SCOPE ),
		'bearer_methods_supported' => array( 'header' ),
		'resource_name'            => get_bloginfo( 'name' ),
	);
}

function devbase_mcp_oauth_server_metadata() {
	return array(
		'issuer'                                         => devbase_mcp_oauth_issuer(),
		'authorization_endpoint'                         => devbase_mcp_oauth_authorize_url(),
		'token_endpoint'                                 => devbase_mcp_oauth_endpoint( 'token' ),
		'registration_endpoint'                          => devbase_mcp_oauth_endpoint( 'register' ),
		'revocation_endpoint'                            => devbase_mcp_oauth_endpoint( 'revoke' ),
		'scopes_supported'                               => array( DEVBASE_MCP_OAUTH_SCOPE ),
		'response_types_supported'                       => array( 'code' ),
		'grant_types_supported'                          => array( 'authorization_code', 'refresh_token' ),
		'token_endpoint_auth_methods_supported'          => array( 'none' ),
		'code_challenge_methods_supported'               => array( 'S256' ),
		'client_id_metadata_document_supported'          => true,
		'authorization_response_iss_parameter_supported' => true,
	);
}

/**
 * Which discovery document a request path asks for: 'resource', 'server' or ''.
 * Accepts the path-insertion form and the root form of each.
 */
function devbase_mcp_oauth_well_known_for( $path ) {
	$path = untrailingslashit( $path );

	$resource = array(
		'/.well-known/oauth-protected-resource' . devbase_mcp_oauth_path( devbase_mcp_oauth_resource() ),
		'/.well-known/oauth-protected-resource',
	);
	$server   = array(
		'/.well-known/oauth-authorization-server' . devbase_mcp_oauth_path( devbase_mcp_oauth_issuer() ),
		'/.well-known/oauth-authorization-server',
	);

	if ( in_array( $path, $resource, true ) ) {
		return 'resource';
	}

	return in_array( $path, $server, true ) ? 'server' : '';
}

// Served before WordPress decides the address is a 404. Requests for
// /.well-known/… reach index.php like any address with no file behind it.
add_action( 'parse_request', function () {
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( false === strpos( $path, '/.well-known/oauth-' ) ) {
		return;
	}

	// Discovery runs outside the REST API, so make the other-provider check
	// see the REST routes too.
	if ( ! did_action( 'rest_api_init' ) ) {
		rest_get_server();
	}

	$which = devbase_mcp_oauth_well_known_for( $path );

	if ( '' === $which || ! devbase_mcp_oauth_active() ) {
		return;
	}

	devbase_mcp_oauth_send_json( 'resource' === $which ? devbase_mcp_oauth_resource_metadata() : devbase_mcp_oauth_server_metadata(), 200, array( 'Cache-Control' => 'public, max-age=3600' ) );
}, 0 );

function devbase_mcp_oauth_send_json( $body, $status = 200, array $headers = array() ) {
	status_header( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );

	foreach ( $headers as $name => $value ) {
		header( $name . ': ' . $value );
	}

	echo wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
	exit;
}

// ── Is this the MCP route? ───────────────────────────────────────────────

/** True when the current request is for the MCP endpoint (pretty or ?rest_route= form). */
function devbase_mcp_oauth_is_mcp_request() {
	$route = devbase_mcp_oauth_path( devbase_mcp_oauth_resource() );
	$route = substr( $route, strlen( devbase_mcp_oauth_path( rest_url() ) ) ); // e.g. /mcp/mcp-adapter-default-server

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

	if ( $path === devbase_mcp_oauth_path( devbase_mcp_oauth_resource() ) ) {
		return true;
	}

	// determine_current_user can run before $_GET is final, so read the address itself.
	parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
	$rest_route = isset( $query['rest_route'] ) && is_string( $query['rest_route'] ) ? untrailingslashit( $query['rest_route'] ) : '';

	return '' !== $route && $rest_route === $route;
}

/** The Authorization header, wherever the server put it. */
function devbase_mcp_oauth_authorization_header() {
	foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			return trim( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
	}

	if ( function_exists( 'getallheaders' ) ) {
		foreach ( (array) getallheaders() as $name => $value ) {
			if ( 'authorization' === strtolower( $name ) ) {
				return trim( $value );
			}
		}
	}

	return '';
}

// ── Bearer sign-in on the MCP route ──────────────────────────────────────

/** Set when a Bearer token was presented and refused, for the 401's error="invalid_token". */
$GLOBALS['devbase_mcp_oauth_token_refused'] = false;

// Priority 25: after Application Passwords (20). Only ever acts when nobody is
// signed in yet, the request is the MCP route, and it carries a Bearer token.
add_filter( 'determine_current_user', function ( $user_id ) {
	// Reading the settings below (get_field) can ask who the user is again,
	// which comes straight back here: answer "nobody yet" while busy.
	static $busy = false;

	if ( $busy || $user_id || ! devbase_mcp_oauth_is_mcp_request() ) {
		return $user_id;
	}

	$header = devbase_mcp_oauth_authorization_header();

	if ( 0 !== stripos( $header, 'Bearer ' ) ) {
		return $user_id;
	}

	$busy   = true;
	$active = devbase_mcp_oauth_active();
	$found  = $active ? devbase_mcp_oauth_user_for_access_token( trim( substr( $header, 7 ) ) ) : 0;
	$busy   = false;

	if ( ! $active ) {
		return $user_id;
	}

	if ( ! $found ) {
		$GLOBALS['devbase_mcp_oauth_token_refused'] = true;
	}

	return $found ? $found : $user_id;
}, 25 );

/**
 * The user an access token signs in, or 0. Checks it exists (unexpired), was
 * issued for THIS MCP address, its connection still exists, and the user may
 * still edit content.
 */
function devbase_mcp_oauth_user_for_access_token( $token ) {
	if ( ! is_string( $token ) || ! preg_match( '/^dbat_[A-Za-z0-9_-]{40,}$/', $token ) ) {
		return 0;
	}

	$record = get_transient( 'devbase_mcp_at_' . hash( 'sha256', $token ) );

	if ( ! is_array( $record ) || $record['resource'] !== devbase_mcp_oauth_resource() || (int) $record['expires'] < time() ) {
		return 0;
	}

	$grants = devbase_mcp_oauth_grants( (int) $record['user_id'] );

	if ( ! isset( $grants[ $record['grant_id'] ] ) || ! user_can( (int) $record['user_id'], 'edit_posts' ) ) {
		return 0;
	}

	// Last used, at most every 5 minutes so a busy client doesn't write on every call.
	if ( time() - (int) ( $grants[ $record['grant_id'] ]['last_used'] ?? 0 ) > 5 * MINUTE_IN_SECONDS ) {
		$grants[ $record['grant_id'] ]['last_used'] = time();
		update_user_meta( (int) $record['user_id'], DEVBASE_MCP_OAUTH_GRANTS_META, $grants );
	}

	return (int) $record['user_id'];
}

// The 401 that starts sign-in. Only on the MCP route, only when this theme
// handles sign-in, and never over a WWW-Authenticate someone else already set.
add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	if ( ! $response instanceof WP_HTTP_Response || 401 !== $response->get_status() ) {
		return $response;
	}

	$route = devbase_mcp_oauth_path( devbase_mcp_oauth_resource() );
	$route = substr( $route, strlen( devbase_mcp_oauth_path( rest_url() ) ) );

	if ( untrailingslashit( $request->get_route() ) !== $route || ! devbase_mcp_oauth_active() ) {
		return $response;
	}

	$headers = $response->get_headers();

	foreach ( array_keys( $headers ) as $name ) {
		if ( 'www-authenticate' === strtolower( $name ) ) {
			return $response;
		}
	}

	$challenge = sprintf( 'Bearer resource_metadata="%s", scope="%s"', devbase_mcp_oauth_resource_metadata_url(), DEVBASE_MCP_OAUTH_SCOPE );

	if ( ! empty( $GLOBALS['devbase_mcp_oauth_token_refused'] ) ) {
		$challenge .= ', error="invalid_token", error_description="The access token is invalid or has expired."';
	}

	$response->header( 'WWW-Authenticate', $challenge );

	return $response;
}, 10, 3 );

// ── Clients ──────────────────────────────────────────────────────────────

/** True for a redirect URI the spec allows: https, or http on the loopback address. */
function devbase_mcp_oauth_redirect_uri_allowed( $uri ) {
	if ( ! is_string( $uri ) || '' === $uri || false !== strpos( $uri, '#' ) ) {
		return false;
	}

	$p = wp_parse_url( $uri );

	if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
		return false;
	}

	if ( 'https' === $p['scheme'] ) {
		return true;
	}

	return 'http' === $p['scheme'] && in_array( strtolower( $p['host'] ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
}

function devbase_mcp_oauth_redirect_is_loopback( $uri ) {
	return in_array( strtolower( (string) wp_parse_url( $uri, PHP_URL_HOST ) ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
}

/**
 * A client by its client_id: { client_id, client_name, redirect_uris, source }
 * or a WP_Error. A client_id that is an https URL with a path is a Client ID
 * Metadata Document, fetched (SSRF-safe: wp_safe_remote_get refuses private and
 * local addresses, no redirects, 5 s, small body) and cached for an hour. Any
 * other client_id must have been registered here.
 */
function devbase_mcp_oauth_client( $client_id ) {
	$client_id = is_string( $client_id ) ? trim( $client_id ) : '';

	if ( '' === $client_id ) {
		return new WP_Error( 'invalid_client', __( 'No client_id.', 'devbase_parent_theme' ) );
	}

	if ( 0 === strpos( $client_id, 'https://' ) ) {
		return devbase_mcp_oauth_metadata_document_client( $client_id );
	}

	$clients = get_option( DEVBASE_MCP_OAUTH_CLIENTS, array() );

	if ( ! is_array( $clients ) || ! isset( $clients[ $client_id ] ) ) {
		return new WP_Error( 'invalid_client', __( 'Unknown client.', 'devbase_parent_theme' ) );
	}

	return array_merge( $clients[ $client_id ], array( 'client_id' => $client_id, 'source' => 'registered' ) );
}

function devbase_mcp_oauth_metadata_document_client( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	if ( '' === trim( $path, '/' ) || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'invalid_client', __( 'A client_id URL needs a path and a public address.', 'devbase_parent_theme' ) );
	}

	$cache_key = 'devbase_mcp_cimd_' . md5( $url );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_safe_remote_get( $url, array(
		'timeout'             => 5,
		'redirection'         => 0,
		'limit_response_size' => 16 * KB_IN_BYTES,
		'headers'             => array( 'Accept' => 'application/json' ),
	) );

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'invalid_client', __( 'The client\'s metadata document could not be fetched.', 'devbase_parent_theme' ) );
	}

	$doc = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $doc ) || ( $doc['client_id'] ?? '' ) !== $url || empty( $doc['client_name'] ) || empty( $doc['redirect_uris'] ) || ! is_array( $doc['redirect_uris'] ) ) {
		return new WP_Error( 'invalid_client', __( 'The client\'s metadata document is not valid (client_id must equal its address; client_name and redirect_uris are required).', 'devbase_parent_theme' ) );
	}

	$redirects = array_values( array_filter( $doc['redirect_uris'], 'devbase_mcp_oauth_redirect_uri_allowed' ) );

	if ( ! $redirects ) {
		return new WP_Error( 'invalid_client', __( 'The client lists no allowed redirect address (https, or http on localhost).', 'devbase_parent_theme' ) );
	}

	$client = array(
		'client_id'     => $url,
		'client_name'   => sanitize_text_field( (string) $doc['client_name'] ),
		'redirect_uris' => $redirects,
		'source'        => 'metadata_document',
	);

	set_transient( $cache_key, $client, HOUR_IN_SECONDS );

	return $client;
}

/**
 * Dynamic Client Registration (RFC 7591). Public clients only (no secret).
 * At most 20 registrations per address per hour; registrations never used
 * within 30 days, or unused for 90, are removed so the list can't grow forever.
 *
 * @return array{status:int, body:array}
 */
function devbase_mcp_oauth_register_client( array $meta ) {
	$redirects = isset( $meta['redirect_uris'] ) && is_array( $meta['redirect_uris'] ) ? array_values( $meta['redirect_uris'] ) : array();

	if ( ! $redirects || count( $redirects ) !== count( array_filter( $redirects, 'devbase_mcp_oauth_redirect_uri_allowed' ) ) ) {
		return array( 'status' => 400, 'body' => array( 'error' => 'invalid_redirect_uri', 'error_description' => 'Every redirect_uri must be https, or http on localhost, without a fragment.' ) );
	}

	$auth_method = $meta['token_endpoint_auth_method'] ?? 'none';

	if ( 'none' !== $auth_method ) {
		return array( 'status' => 400, 'body' => array( 'error' => 'invalid_client_metadata', 'error_description' => 'Only public clients (token_endpoint_auth_method "none") are supported.' ) );
	}

	$limit = get_transient( 'devbase_mcp_reg_' . md5( devbase_form_client_ip() ) );

	if ( (int) $limit >= 20 ) {
		return array( 'status' => 429, 'body' => array( 'error' => 'too_many_requests', 'error_description' => 'Too many registrations. Try again later.' ) );
	}

	set_transient( 'devbase_mcp_reg_' . md5( devbase_form_client_ip() ), (int) $limit + 1, HOUR_IN_SECONDS );

	$clients = get_option( DEVBASE_MCP_OAUTH_CLIENTS, array() );
	$clients = is_array( $clients ) ? $clients : array();
	$now     = time();

	foreach ( $clients as $id => $c ) {
		$idle = $now - (int) ( $c['last_used'] ?: $c['created'] );

		if ( $idle > ( empty( $c['last_used'] ) ? 30 : 90 ) * DAY_IN_SECONDS ) {
			unset( $clients[ $id ] );
		}
	}

	$client_id = 'dbc_' . devbase_mcp_oauth_random( 24 );
	$name      = sanitize_text_field( (string) ( $meta['client_name'] ?? '' ) );

	$clients[ $client_id ] = array(
		'client_name'   => '' !== $name ? $name : __( 'Unnamed app', 'devbase_parent_theme' ),
		'redirect_uris' => array_map( 'strval', $redirects ),
		'created'       => $now,
		'last_used'     => 0,
	);

	update_option( DEVBASE_MCP_OAUTH_CLIENTS, $clients, false );

	return array( 'status' => 201, 'body' => array(
		'client_id'                  => $client_id,
		'client_id_issued_at'        => $now,
		'client_name'                => $clients[ $client_id ]['client_name'],
		'redirect_uris'              => $clients[ $client_id ]['redirect_uris'],
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		'response_types'             => array( 'code' ),
		'token_endpoint_auth_method' => 'none',
	) );
}

// ── Codes, grants and tokens ─────────────────────────────────────────────

function devbase_mcp_oauth_random( $bytes = 32 ) {
	return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
}

/** A user's connections: grant_id => { client_id, client_name, redirect_host, created, last_used, refresh_hash, refresh_expires, previous_hashes }. */
function devbase_mcp_oauth_grants( $user_id ) {
	$grants = get_user_meta( (int) $user_id, DEVBASE_MCP_OAUTH_GRANTS_META, true );

	return is_array( $grants ) ? $grants : array();
}

function devbase_mcp_oauth_revoke_grant( $user_id, $grant_id ) {
	$grants = devbase_mcp_oauth_grants( $user_id );

	if ( ! isset( $grants[ $grant_id ] ) ) {
		return false;
	}

	unset( $grants[ $grant_id ] );
	update_user_meta( (int) $user_id, DEVBASE_MCP_OAUTH_GRANTS_META, $grants );

	return true;
}

/**
 * Issues a one-time authorization code after the person clicked Allow.
 * Everything the token request must match is bound to it.
 */
function devbase_mcp_oauth_issue_code( $user_id, array $request ) {
	$code = 'dbcd_' . devbase_mcp_oauth_random();

	set_transient( 'devbase_mcp_code_' . hash( 'sha256', $code ), array(
		'user_id'        => (int) $user_id,
		'client_id'      => $request['client_id'],
		'client_name'    => $request['client_name'],
		'redirect_uri'   => $request['redirect_uri'],
		'code_challenge' => $request['code_challenge'],
		'resource'       => $request['resource'],
		'expires'        => time() + DEVBASE_MCP_OAUTH_CODE_TTL,
	), DEVBASE_MCP_OAUTH_CODE_TTL );

	return $code;
}

/** A new access token (and refresh token) for a grant. */
function devbase_mcp_oauth_issue_tokens( $user_id, $grant_id, $client_id, $resource ) {
	$access  = 'dbat_' . devbase_mcp_oauth_random();
	$secret  = devbase_mcp_oauth_random();
	$refresh = 'dbrt_' . (int) $user_id . '_' . $grant_id . '_' . $secret;

	set_transient( 'devbase_mcp_at_' . hash( 'sha256', $access ), array(
		'user_id'   => (int) $user_id,
		'grant_id'  => $grant_id,
		'client_id' => $client_id,
		'resource'  => $resource,
		'expires'   => time() + DEVBASE_MCP_OAUTH_ACCESS_TTL,
	), DEVBASE_MCP_OAUTH_ACCESS_TTL );

	$grants = devbase_mcp_oauth_grants( $user_id );

	if ( isset( $grants[ $grant_id ] ) ) {
		// Remember the replaced refresh token's hash: if it is ever presented
		// again, it was stolen (or replayed), and the whole connection is revoked.
		$previous = (array) ( $grants[ $grant_id ]['previous_hashes'] ?? array() );

		if ( ! empty( $grants[ $grant_id ]['refresh_hash'] ) ) {
			$previous[] = $grants[ $grant_id ]['refresh_hash'];
		}

		$grants[ $grant_id ]['previous_hashes'] = array_slice( $previous, -10 );
		$grants[ $grant_id ]['refresh_hash']    = hash( 'sha256', $secret );
		$grants[ $grant_id ]['refresh_expires'] = time() + DEVBASE_MCP_OAUTH_REFRESH_TTL;
		update_user_meta( (int) $user_id, DEVBASE_MCP_OAUTH_GRANTS_META, $grants );
	}

	return array(
		'access_token'  => $access,
		'token_type'    => 'Bearer',
		'expires_in'    => DEVBASE_MCP_OAUTH_ACCESS_TTL,
		'refresh_token' => $refresh,
		'scope'         => DEVBASE_MCP_OAUTH_SCOPE,
	);
}

/**
 * The token endpoint: authorization_code (with PKCE) or refresh_token.
 *
 * @return array{status:int, body:array}
 */
function devbase_mcp_oauth_token_request( array $params ) {
	$fail = function ( $error, $description, $status = 400 ) {
		return array( 'status' => $status, 'body' => array( 'error' => $error, 'error_description' => $description ) );
	};

	$grant_type = (string) ( $params['grant_type'] ?? '' );
	$resource   = isset( $params['resource'] ) ? untrailingslashit( (string) $params['resource'] ) : devbase_mcp_oauth_resource();

	if ( devbase_mcp_oauth_resource() !== $resource ) {
		return $fail( 'invalid_target', 'This server only issues tokens for ' . devbase_mcp_oauth_resource() . '.' );
	}

	if ( 'authorization_code' === $grant_type ) {
		$code = (string) ( $params['code'] ?? '' );
		$key  = 'devbase_mcp_code_' . hash( 'sha256', $code );
		$data = '' !== $code ? get_transient( $key ) : false;

		// One use only, whatever happens next.
		delete_transient( $key );

		if ( ! is_array( $data ) || (int) $data['expires'] < time() ) {
			return $fail( 'invalid_grant', 'The code is invalid, used or expired.' );
		}

		if ( ( $params['client_id'] ?? '' ) !== $data['client_id'] || ( $params['redirect_uri'] ?? '' ) !== $data['redirect_uri'] || $data['resource'] !== $resource ) {
			return $fail( 'invalid_grant', 'client_id, redirect_uri or resource does not match the authorization request.' );
		}

		$verifier = (string) ( $params['code_verifier'] ?? '' );

		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) || ! hash_equals( $data['code_challenge'], rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) ) ) {
			return $fail( 'invalid_grant', 'PKCE verification failed.' );
		}

		if ( ! user_can( (int) $data['user_id'], 'edit_posts' ) ) {
			return $fail( 'invalid_grant', 'The user can no longer connect.' );
		}

		$grant_id = bin2hex( random_bytes( 8 ) );
		$grants   = devbase_mcp_oauth_grants( $data['user_id'] );

		$grants[ $grant_id ] = array(
			'client_id'     => $data['client_id'],
			'client_name'   => $data['client_name'],
			'redirect_host' => (string) wp_parse_url( $data['redirect_uri'], PHP_URL_HOST ),
			'created'       => time(),
			'last_used'     => time(),
		);
		update_user_meta( (int) $data['user_id'], DEVBASE_MCP_OAUTH_GRANTS_META, $grants );

		devbase_mcp_oauth_touch_client( $data['client_id'] );

		return array( 'status' => 200, 'body' => devbase_mcp_oauth_issue_tokens( $data['user_id'], $grant_id, $data['client_id'], $resource ) );
	}

	if ( 'refresh_token' === $grant_type ) {
		if ( ! preg_match( '/^dbrt_(\d+)_([a-f0-9]{16})_([A-Za-z0-9_-]{40,})$/', (string) ( $params['refresh_token'] ?? '' ), $m ) ) {
			return $fail( 'invalid_grant', 'The refresh token is invalid.' );
		}

		list( , $user_id, $grant_id, $secret ) = $m;
		$grants = devbase_mcp_oauth_grants( $user_id );
		$grant  = $grants[ $grant_id ] ?? null;
		$hash   = hash( 'sha256', $secret );

		if ( ! $grant ) {
			return $fail( 'invalid_grant', 'The connection was removed.' );
		}

		if ( in_array( $hash, (array) ( $grant['previous_hashes'] ?? array() ), true ) ) {
			// A replaced refresh token came back: it was copied. End the connection.
			devbase_mcp_oauth_revoke_grant( $user_id, $grant_id );

			return $fail( 'invalid_grant', 'This refresh token was already used; the connection has been revoked.' );
		}

		if ( empty( $grant['refresh_hash'] ) || ! hash_equals( $grant['refresh_hash'], $hash ) || (int) $grant['refresh_expires'] < time() ) {
			return $fail( 'invalid_grant', 'The refresh token is invalid or expired.' );
		}

		if ( isset( $params['client_id'] ) && $params['client_id'] !== $grant['client_id'] ) {
			return $fail( 'invalid_grant', 'The refresh token belongs to another client.' );
		}

		if ( ! user_can( (int) $user_id, 'edit_posts' ) ) {
			return $fail( 'invalid_grant', 'The user can no longer connect.' );
		}

		return array( 'status' => 200, 'body' => devbase_mcp_oauth_issue_tokens( $user_id, $grant_id, $grant['client_id'], $resource ) );
	}

	return $fail( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
}

function devbase_mcp_oauth_touch_client( $client_id ) {
	$clients = get_option( DEVBASE_MCP_OAUTH_CLIENTS, array() );

	if ( is_array( $clients ) && isset( $clients[ $client_id ] ) ) {
		$clients[ $client_id ]['last_used'] = time();
		update_option( DEVBASE_MCP_OAUTH_CLIENTS, $clients, false );
	}
}

/** Token revocation (RFC 7009): a refresh token ends its connection; always answers 200. */
function devbase_mcp_oauth_revoke_token( $token ) {
	if ( preg_match( '/^dbrt_(\d+)_([a-f0-9]{16})_([A-Za-z0-9_-]{40,})$/', (string) $token, $m ) ) {
		$grants = devbase_mcp_oauth_grants( $m[1] );

		if ( isset( $grants[ $m[2] ]['refresh_hash'] ) && hash_equals( $grants[ $m[2] ]['refresh_hash'], hash( 'sha256', $m[3] ) ) ) {
			devbase_mcp_oauth_revoke_grant( $m[1], $m[2] );
		}
	} elseif ( is_string( $token ) && 0 === strpos( $token, 'dbat_' ) ) {
		delete_transient( 'devbase_mcp_at_' . hash( 'sha256', $token ) );
	}
}

// ── REST endpoints: token, register, revoke ──────────────────────────────

add_action( 'rest_api_init', function () {
	$reply = function ( array $result ) {
		$response = new WP_REST_Response( $result['body'], $result['status'] );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	};

	$off = function () use ( $reply ) {
		return $reply( array( 'status' => 404, 'body' => array( 'error' => 'not_available', 'error_description' => 'Sign-in for MCP clients is not switched on for this site.' ) ) );
	};

	register_rest_route( 'devbase-oauth/v1', '/token', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $request ) use ( $reply, $off ) {
			return devbase_mcp_oauth_active() ? $reply( devbase_mcp_oauth_token_request( $request->get_body_params() ?: $request->get_params() ) ) : $off();
		},
	) );

	register_rest_route( 'devbase-oauth/v1', '/register', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $request ) use ( $reply, $off ) {
			return devbase_mcp_oauth_active() ? $reply( devbase_mcp_oauth_register_client( (array) ( $request->get_json_params() ?: $request->get_body_params() ) ) ) : $off();
		},
	) );

	register_rest_route( 'devbase-oauth/v1', '/revoke', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $request ) use ( $reply ) {
			devbase_mcp_oauth_revoke_token( (string) $request->get_param( 'token' ) );

			return $reply( array( 'status' => 200, 'body' => array() ) );
		},
	) );
} );
