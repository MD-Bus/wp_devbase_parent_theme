<?php
// Covers inc/mcp-oauth/: the sign-in Claude's and ChatGPT's "add a
// connector" use. Discovery, the 401 that starts it, client registration,
// the authorization checks, codes, tokens and refresh rotation, the Bearer
// sign-in (MCP route only), revocation, and stepping aside for another
// provider. The test site is http://example.org, so set_up moves it to https
// with pretty permalinks, the two things sign-in needs.

class Test_MCP_OAuth extends WP_UnitTestCase {

	const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

	private $user_id;
	private $client_id;
	private $verifier;

	public function set_up() {
		parent::set_up();

		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		$this->set_permalink_structure( '/%postname%/' );
		update_field( 'mcp_oauth_enabled', 1, 'option' );

		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$registered      = devbase_mcp_oauth_register_client( array( 'client_name' => 'Claude', 'redirect_uris' => array( self::REDIRECT ) ) );
		$this->client_id = $registered['body']['client_id'];
		$this->verifier  = str_repeat( 'a1B2-c3D4.', 6 );
	}

	public function tear_down() {
		update_field( 'mcp_oauth_enabled', 0, 'option' );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['REQUEST_URI'] = '';
		remove_all_filters( 'devbase/mcp_oauth/other_provider' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	// ── helpers ──

	private function challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private function authorize_params( array $overrides = array() ) {
		return array_merge( array(
			'response_type'         => 'code',
			'client_id'             => $this->client_id,
			'redirect_uri'          => self::REDIRECT,
			'code_challenge'        => $this->challenge( $this->verifier ),
			'code_challenge_method' => 'S256',
			'state'                 => 'xyz',
			'resource'              => devbase_mcp_oauth_resource(),
		), $overrides );
	}

	private function code() {
		$check = devbase_mcp_oauth_check_authorize( $this->authorize_params() );

		return devbase_mcp_oauth_issue_code( $this->user_id, $check['ok'] );
	}

	private function exchange( $code, array $overrides = array() ) {
		return devbase_mcp_oauth_token_request( array_merge( array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $this->client_id,
			'redirect_uri'  => self::REDIRECT,
			'code_verifier' => $this->verifier,
			'resource'      => devbase_mcp_oauth_resource(),
		), $overrides ) );
	}

	private function tokens() {
		return $this->exchange( $this->code() )['body'];
	}

	private function signed_in_with( $token, $uri = '/wp-json/mcp/mcp-adapter-default-server' ) {
		$_SERVER['REQUEST_URI']        = $uri;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

		return (int) apply_filters( 'determine_current_user', false );
	}

	// ── on / off ──

	public function test_off_unless_switched_on() {
		update_field( 'mcp_oauth_enabled', 0, 'option' );

		$this->assertFalse( devbase_mcp_oauth_active() );
		$this->assertSame( 0, $this->signed_in_with( 'dbat_' . str_repeat( 'x', 43 ) ) );
	}

	public function test_off_without_https_or_with_plain_permalinks() {
		update_option( 'home', 'http://example.org' );
		$this->assertStringContainsString( 'HTTPS', devbase_mcp_oauth_inactive_reason() );

		update_option( 'home', 'https://example.org' );
		$this->set_permalink_structure( '' );
		$this->assertStringContainsString( 'permalinks', devbase_mcp_oauth_inactive_reason() );
	}

	public function test_steps_aside_when_another_provider_handles_sign_in() {
		$tokens = $this->tokens();
		add_filter( 'devbase/mcp_oauth/other_provider', function () {
			return 'the MCP Adapter';
		} );

		$this->assertFalse( devbase_mcp_oauth_active() );
		$this->assertStringContainsString( 'the MCP Adapter', devbase_mcp_oauth_inactive_reason() );
		$this->assertSame( 0, $this->signed_in_with( $tokens['access_token'] ), 'Bearer is left to the other provider.' );

		$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( null, 401 ), rest_get_server(), new WP_REST_Request( 'POST', '/mcp/mcp-adapter-default-server' ) );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->get_headers() );
	}

	public function test_recognises_an_oauth_route_that_is_not_ours() {
		$this->assertSame( 'the MCP Adapter', devbase_mcp_oauth_route_provider( '/mcp/oauth/authorize' ) );
		$this->assertSame( 'the MCP Adapter', devbase_mcp_oauth_route_provider( '/mcp/mcp-adapter-default-server/token' ) );

		$this->assertSame( '', devbase_mcp_oauth_route_provider( '/devbase-oauth/v1/token' ) );
		$this->assertSame( '', devbase_mcp_oauth_route_provider( '/mcp/mcp-adapter-default-server' ) );
		$this->assertSame( '', devbase_mcp_oauth_route_provider( '/wp/v2/posts' ) );

		// Another plugin's OAuth for its OWN MCP server is not sign-in for this endpoint.
		$this->assertSame( '', devbase_mcp_oauth_route_provider( '/seopress/mcp/v1/oauth-protected-resource' ) );
		$this->assertSame( '', devbase_mcp_oauth_route_provider( '/seopress/mcp/v1/oauth-authorization-server' ) );
	}

	public function test_nothing_on_this_site_today_counts_as_another_provider() {
		rest_get_server();

		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			$this->assertSame( '', devbase_mcp_oauth_route_provider( $route ), $route );
		}
	}

	// ── discovery ──

	public function test_discovery_documents() {
		$resource = devbase_mcp_oauth_resource_metadata();
		$this->assertSame( 'https://example.org/wp-json/mcp/mcp-adapter-default-server', $resource['resource'] );
		$this->assertSame( array( 'https://example.org' ), $resource['authorization_servers'] );

		$server = devbase_mcp_oauth_server_metadata();
		$this->assertSame( 'https://example.org', $server['issuer'] );
		$this->assertSame( array( 'S256' ), $server['code_challenge_methods_supported'] );
		$this->assertTrue( $server['client_id_metadata_document_supported'] );
		$this->assertTrue( $server['authorization_response_iss_parameter_supported'] );
		$this->assertStringStartsWith( 'https://example.org/wp-admin/admin-post.php', $server['authorization_endpoint'] );
	}

	public function test_discovery_addresses() {
		$this->assertSame( 'resource', devbase_mcp_oauth_well_known_for( '/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server' ) );
		$this->assertSame( 'resource', devbase_mcp_oauth_well_known_for( '/.well-known/oauth-protected-resource' ) );
		$this->assertSame( 'server', devbase_mcp_oauth_well_known_for( '/.well-known/oauth-authorization-server' ) );
		$this->assertSame( '', devbase_mcp_oauth_well_known_for( '/.well-known/oauth-authorization-server/elsewhere' ) );
	}

	public function test_a_401_from_the_mcp_route_says_where_to_sign_in() {
		$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( null, 401 ), rest_get_server(), new WP_REST_Request( 'POST', '/mcp/mcp-adapter-default-server' ) );

		$this->assertSame(
			'Bearer resource_metadata="https://example.org/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server", scope="mcp"',
			$response->get_headers()['WWW-Authenticate']
		);
	}

	public function test_other_401s_and_existing_challenges_are_left_alone() {
		$other = apply_filters( 'rest_post_dispatch', new WP_REST_Response( null, 401 ), rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $other->get_headers() );

		$theirs = new WP_REST_Response( null, 401 );
		$theirs->header( 'WWW-Authenticate', 'Bearer realm="theirs"' );
		$theirs = apply_filters( 'rest_post_dispatch', $theirs, rest_get_server(), new WP_REST_Request( 'POST', '/mcp/mcp-adapter-default-server' ) );
		$this->assertSame( 'Bearer realm="theirs"', $theirs->get_headers()['WWW-Authenticate'] );
	}

	// ── clients ──

	public function test_registration_accepts_only_safe_redirect_addresses() {
		foreach ( array( 'http://evil.example/cb', 'javascript:alert(1)', 'https://x.example/cb#frag', 'myapp://cb' ) as $bad ) {
			$this->assertSame( 400, devbase_mcp_oauth_register_client( array( 'redirect_uris' => array( $bad ) ) )['status'], $bad );
		}

		$this->assertSame( 201, devbase_mcp_oauth_register_client( array( 'redirect_uris' => array( 'http://localhost:6274/callback', 'http://127.0.0.1:33418/' ) ) )['status'] );
		$this->assertSame( 400, devbase_mcp_oauth_register_client( array( 'redirect_uris' => array( self::REDIRECT ), 'token_endpoint_auth_method' => 'client_secret_basic' ) )['status'] );
	}

	public function test_registration_is_rate_limited() {
		for ( $i = 0; $i < 19; $i++ ) {
			devbase_mcp_oauth_register_client( array( 'redirect_uris' => array( self::REDIRECT ) ) );
		}

		$this->assertSame( 429, devbase_mcp_oauth_register_client( array( 'redirect_uris' => array( self::REDIRECT ) ) )['status'] );
	}

	public function test_a_client_id_metadata_document_must_name_itself() {
		$url = 'https://example.com/oauth/client.json';
		$doc = array( 'client_id' => $url, 'client_name' => 'ChatGPT', 'redirect_uris' => array( 'https://chatgpt.com/connector/oauth/cb' ) );

		add_filter( 'pre_http_request', function () use ( &$doc ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $doc ), 'headers' => array(), 'cookies' => array() );
		} );

		$client = devbase_mcp_oauth_client( $url );
		$this->assertSame( 'ChatGPT', $client['client_name'] );

		delete_transient( 'devbase_mcp_cimd_' . md5( $url ) );
		$doc['client_id'] = 'https://elsewhere.example/client.json';
		$this->assertWPError( devbase_mcp_oauth_client( $url ) );
	}

	// ── authorize ──

	public function test_untrusted_redirects_are_shown_not_followed() {
		$this->assertArrayHasKey( 'page', devbase_mcp_oauth_check_authorize( $this->authorize_params( array( 'client_id' => 'dbc_unknown' ) ) ) );
		$this->assertArrayHasKey( 'page', devbase_mcp_oauth_check_authorize( $this->authorize_params( array( 'redirect_uri' => 'https://claude.ai/api/mcp/other' ) ) ) );
	}

	public function test_pkce_and_resource_are_enforced() {
		$plain = devbase_mcp_oauth_check_authorize( $this->authorize_params( array( 'code_challenge_method' => 'plain' ) ) );
		$this->assertSame( 'invalid_request', $plain['redirect']['error'] );
		$this->assertSame( 'xyz', $plain['redirect']['state'] );

		$elsewhere = devbase_mcp_oauth_check_authorize( $this->authorize_params( array( 'resource' => 'https://other.example/mcp' ) ) );
		$this->assertSame( 'invalid_target', $elsewhere['redirect']['error'] );

		$missing = devbase_mcp_oauth_check_authorize( $this->authorize_params( array( 'resource' => '' ) ) );
		$this->assertSame( devbase_mcp_oauth_resource(), $missing['ok']['resource'] );
	}

	public function test_the_redirect_back_carries_the_issuer() {
		$url = devbase_mcp_oauth_redirect_back( self::REDIRECT, array( 'code' => 'abc', 'state' => 'xyz' ) );

		$this->assertSame( self::REDIRECT . '?code=abc&state=xyz&iss=https%3A%2F%2Fexample.org', $url );
	}

	// ── tokens ──

	public function test_a_code_becomes_tokens_once() {
		$code  = $this->code();
		$first = $this->exchange( $code );

		$this->assertSame( 200, $first['status'] );
		$this->assertSame( 'Bearer', $first['body']['token_type'] );
		$this->assertStringStartsWith( 'dbat_', $first['body']['access_token'] );
		$this->assertStringStartsWith( 'dbrt_', $first['body']['refresh_token'] );

		$this->assertSame( 'invalid_grant', $this->exchange( $code )['body']['error'] );
	}

	public function test_the_code_needs_the_right_verifier_redirect_and_client() {
		$this->assertSame( 'invalid_grant', $this->exchange( $this->code(), array( 'code_verifier' => str_repeat( 'z', 50 ) ) )['body']['error'] );
		$this->assertSame( 'invalid_grant', $this->exchange( $this->code(), array( 'redirect_uri' => 'https://claude.ai/x' ) )['body']['error'] );
		$this->assertSame( 'invalid_grant', $this->exchange( $this->code(), array( 'client_id' => 'dbc_other' ) )['body']['error'] );
		$this->assertSame( 'invalid_target', $this->exchange( $this->code(), array( 'resource' => 'https://other.example/mcp' ) )['body']['error'] );
	}

	public function test_a_failed_attempt_still_uses_up_the_code() {
		$code = $this->code();
		$this->exchange( $code, array( 'code_verifier' => str_repeat( 'z', 50 ) ) );

		$this->assertSame( 'invalid_grant', $this->exchange( $code )['body']['error'] );
	}

	public function test_refresh_rotates_and_a_reused_one_ends_the_connection() {
		$tokens = $this->tokens();
		$next   = devbase_mcp_oauth_token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'] ) );

		$this->assertSame( 200, $next['status'] );
		$this->assertNotSame( $tokens['refresh_token'], $next['body']['refresh_token'] );

		$replay = devbase_mcp_oauth_token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'] ) );
		$this->assertSame( 'invalid_grant', $replay['body']['error'] );

		$this->assertSame( array(), devbase_mcp_oauth_grants( $this->user_id ) );
		$this->assertSame( 0, $this->signed_in_with( $next['body']['access_token'] ) );
	}

	// ── Bearer sign-in ──

	public function test_the_access_token_signs_in_on_the_mcp_route() {
		$tokens = $this->tokens();

		$this->assertSame( $this->user_id, $this->signed_in_with( $tokens['access_token'] ) );
		$this->assertSame( $this->user_id, $this->signed_in_with( $tokens['access_token'], '/index.php?rest_route=/mcp/mcp-adapter-default-server' ) );
	}

	public function test_the_access_token_works_nowhere_else() {
		$tokens = $this->tokens();

		$this->assertSame( 0, $this->signed_in_with( $tokens['access_token'], '/wp-json/wp/v2/users/me' ) );
		$this->assertSame( 0, $this->signed_in_with( $tokens['access_token'], '/wp-admin/' ) );
	}

	public function test_a_token_for_another_resource_is_refused() {
		$token = 'dbat_' . str_repeat( 'Q', 43 );
		set_transient( 'devbase_mcp_at_' . hash( 'sha256', $token ), array( 'user_id' => $this->user_id, 'grant_id' => 'x', 'client_id' => $this->client_id, 'resource' => 'https://other.example/mcp', 'expires' => time() + 60 ), 60 );

		$this->assertSame( 0, $this->signed_in_with( $token ) );
	}

	public function test_a_refused_token_is_named_in_the_401() {
		$this->signed_in_with( 'dbat_' . str_repeat( 'n', 43 ) );
		$response = apply_filters( 'rest_post_dispatch', new WP_REST_Response( null, 401 ), rest_get_server(), new WP_REST_Request( 'POST', '/mcp/mcp-adapter-default-server' ) );
		$GLOBALS['devbase_mcp_oauth_token_refused'] = false;

		$this->assertStringContainsString( 'error="invalid_token"', $response->get_headers()['WWW-Authenticate'] );
	}

	public function test_application_passwords_and_cookies_come_first() {
		$tokens = $this->tokens();
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Core's cookie check (priority 10) would drop a passed-in id; in a real
		// request it runs first and its answer is what reaches priority 25.
		remove_filter( 'determine_current_user', 'wp_validate_auth_cookie' );
		remove_filter( 'determine_current_user', 'wp_validate_logged_in_cookie', 20 );

		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/mcp-adapter-default-server';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$this->assertSame( $other, (int) apply_filters( 'determine_current_user', $other ), 'A user already found is never replaced.' );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'someone:' . $tokens['access_token'] );
		$this->assertSame( 0, (int) apply_filters( 'determine_current_user', false ), 'Basic is never read as a token.' );
	}

	public function test_disconnecting_stops_the_token_at_once() {
		$tokens = $this->tokens();
		$grants = devbase_mcp_oauth_grants( $this->user_id );

		$this->assertSame( 'Claude', reset( $grants )['client_name'] );

		devbase_mcp_oauth_revoke_grant( $this->user_id, key( $grants ) );

		$this->assertSame( 0, $this->signed_in_with( $tokens['access_token'] ) );
	}

	public function test_revoking_the_refresh_token_ends_the_connection() {
		$tokens = $this->tokens();

		devbase_mcp_oauth_revoke_token( $tokens['refresh_token'] );

		$this->assertSame( array(), devbase_mcp_oauth_grants( $this->user_id ) );
	}

	public function test_a_user_who_can_no_longer_edit_is_signed_out() {
		$tokens = $this->tokens();
		( new WP_User( $this->user_id ) )->set_role( 'subscriber' );

		$this->assertSame( 0, $this->signed_in_with( $tokens['access_token'] ) );
	}

	public function test_mcp_clients_cannot_switch_it() {
		$this->assertContains( 'mcp_oauth_enabled', devbase_abilities_protected_option_fields() );
	}
}
