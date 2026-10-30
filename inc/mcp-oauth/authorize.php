<?php

// The authorization endpoint: where Claude or ChatGPT sends the person to
// connect. It is wp-admin/admin-post.php?action=devbase_mcp_authorize, so:
//
//   not logged in   auth_redirect() sends them to the site's login (a custom
//                   login address or 2FA plugin works as usual) and back here
//   logged in       an Allow / Cancel page naming the app, where it sends the
//                   person back to, and which account it will act as
//   Allow           a one-time code, sent back to the app's redirect address
//                   with the state and the issuer (RFC 9207)
//
// Nothing is approved without that click: the page needs a nonce, refuses to
// be framed, and is shown again on every connection (no "remember" switch).
// Only accounts that can edit content (edit_posts) can connect; what the app
// may then do is exactly what that account and the MCP Access settings allow.

/**
 * Checks an authorization request. Returns one of:
 *   [ 'ok' => array ]             the request, with the client's name
 *   [ 'page' => string ]          an error to SHOW (the redirect address is not
 *                                 trusted yet, so never redirect to it)
 *   [ 'redirect' => array ]       an error to send back to the app
 */
function devbase_mcp_oauth_check_authorize( array $params ) {
	$get = function ( $key ) use ( $params ) {
		return isset( $params[ $key ] ) && is_string( $params[ $key ] ) ? $params[ $key ] : '';
	};

	$client = devbase_mcp_oauth_client( $get( 'client_id' ) );

	if ( is_wp_error( $client ) ) {
		return array( 'page' => $client->get_error_message() );
	}

	$redirect_uri = $get( 'redirect_uri' );

	// Exact match only, no prefix or pattern.
	if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
		return array( 'page' => __( 'This app asked to send you back to an address it did not register.', 'devbase_parent_theme' ) );
	}

	$error = function ( $code, $description ) use ( $redirect_uri, $get ) {
		return array( 'redirect' => array( 'redirect_uri' => $redirect_uri, 'error' => $code, 'error_description' => $description, 'state' => $get( 'state' ) ) );
	};

	if ( 'code' !== $get( 'response_type' ) ) {
		return $error( 'unsupported_response_type', 'Only response_type=code is supported.' );
	}

	if ( 'S256' !== $get( 'code_challenge_method' ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $get( 'code_challenge' ) ) ) {
		return $error( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
	}

	// The spec says clients send `resource`; one that doesn't still gets a
	// token for the only resource there is. A different one is refused.
	$resource = '' !== $get( 'resource' ) ? untrailingslashit( $get( 'resource' ) ) : devbase_mcp_oauth_resource();

	if ( devbase_mcp_oauth_resource() !== $resource ) {
		return $error( 'invalid_target', 'This server only issues tokens for ' . devbase_mcp_oauth_resource() . '.' );
	}

	return array( 'ok' => array(
		'client_id'      => $client['client_id'],
		'client_name'    => $client['client_name'],
		'redirect_uri'   => $redirect_uri,
		'code_challenge' => $get( 'code_challenge' ),
		'state'          => $get( 'state' ),
		'resource'       => $resource,
	) );
}

/** The redirect back to the app, with the issuer added (RFC 9207). */
function devbase_mcp_oauth_redirect_back( $redirect_uri, array $args ) {
	$args['iss'] = devbase_mcp_oauth_issuer();

	return add_query_arg( array_map( 'rawurlencode', array_filter( $args, 'strlen' ) ), $redirect_uri );
}

// Not logged in: to the login page and back.
add_action( 'admin_post_nopriv_devbase_mcp_authorize', function () {
	if ( ! devbase_mcp_oauth_active() ) {
		wp_die( esc_html__( 'Sign-in for AI apps is not switched on for this site.', 'devbase_parent_theme' ), '', array( 'response' => 404 ) );
	}

	auth_redirect();
} );

add_action( 'admin_post_devbase_mcp_authorize', function () {
	if ( ! did_action( 'rest_api_init' ) ) {
		rest_get_server(); // So the other-provider check sees the REST routes.
	}

	if ( ! devbase_mcp_oauth_active() ) {
		wp_die( esc_html__( 'Sign-in for AI apps is not switched on for this site.', 'devbase_parent_theme' ), '', array( 'response' => 404 ) );
	}

	nocache_headers();
	header( 'X-Frame-Options: DENY' );
	header( "Content-Security-Policy: frame-ancestors 'none'" );

	$is_post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
	$params  = wp_unslash( $is_post ? $_POST : $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
	$check   = devbase_mcp_oauth_check_authorize( (array) $params );

	if ( isset( $check['page'] ) ) {
		devbase_mcp_oauth_render_page( __( 'This connection can\'t be made', 'devbase_parent_theme' ), '<p>' . esc_html( $check['page'] ) . '</p>', 400 );
	}

	if ( isset( $check['redirect'] ) ) {
		$r = $check['redirect'];
		wp_redirect( devbase_mcp_oauth_redirect_back( $r['redirect_uri'], array( 'error' => $r['error'], 'error_description' => $r['error_description'], 'state' => $r['state'] ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the app's registered address, checked above.
		exit;
	}

	$request = $check['ok'];

	if ( ! current_user_can( 'edit_posts' ) ) {
		devbase_mcp_oauth_render_page( __( 'This account can\'t connect AI apps', 'devbase_parent_theme' ), '<p>' . esc_html__( 'Only accounts that can edit content can connect an AI app to this site. Log in with another account, or ask an administrator.', 'devbase_parent_theme' ) . '</p><p><a href="' . esc_url( wp_logout_url( devbase_mcp_oauth_current_url() ) ) . '">' . esc_html__( 'Log in as someone else', 'devbase_parent_theme' ) . '</a></p>', 403 );
	}

	if ( $is_post ) {
		check_admin_referer( 'devbase_mcp_authorize' );

		if ( empty( $_POST['allow'] ) ) {
			wp_redirect( devbase_mcp_oauth_redirect_back( $request['redirect_uri'], array( 'error' => 'access_denied', 'state' => $request['state'] ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		$code = devbase_mcp_oauth_issue_code( get_current_user_id(), $request );

		wp_redirect( devbase_mcp_oauth_redirect_back( $request['redirect_uri'], array( 'code' => $code, 'state' => $request['state'] ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	devbase_mcp_oauth_render_consent( $request, (array) $params );
} );

function devbase_mcp_oauth_current_url() {
	return set_url_scheme( 'http://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

function devbase_mcp_oauth_render_consent( array $request, array $params ) {
	$user          = wp_get_current_user();
	$redirect_host = (string) wp_parse_url( $request['redirect_uri'], PHP_URL_HOST );
	$client_host   = 0 === strpos( $request['client_id'], 'https://' ) ? (string) wp_parse_url( $request['client_id'], PHP_URL_HOST ) : '';

	ob_start();
	?>
	<p class="lead">
		<?php
		/* translators: 1: app name, 2: site name */
		printf( esc_html__( '%1$s wants to connect to %2$s.', 'devbase_parent_theme' ), '<strong>' . esc_html( $request['client_name'] ) . '</strong>', '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>' );
		?>
	</p>
	<p><?php esc_html_e( 'If you allow it, the app can read and change content on this site as you, within what your account and the site\'s MCP Access settings allow.', 'devbase_parent_theme' ); ?></p>
	<dl>
		<?php if ( '' !== $client_host ) : ?>
			<dt><?php esc_html_e( 'App published by', 'devbase_parent_theme' ); ?></dt>
			<dd><?php echo esc_html( $client_host ); ?></dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'You will be sent back to', 'devbase_parent_theme' ); ?></dt>
		<dd><?php echo esc_html( $redirect_host ); ?></dd>
		<dt><?php esc_html_e( 'Acting as', 'devbase_parent_theme' ); ?></dt>
		<dd><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></dd>
	</dl>
	<?php if ( devbase_mcp_oauth_redirect_is_loopback( $request['redirect_uri'] ) ) : ?>
		<p class="warn"><?php esc_html_e( 'This app runs on your own computer. Only allow it if you just started connecting it yourself.', 'devbase_parent_theme' ); ?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Only allow apps you just started connecting yourself. You can disconnect it at any time from your profile, under Connected AI Apps.', 'devbase_parent_theme' ); ?></p>
	<form method="post" action="<?php echo esc_url( devbase_mcp_oauth_authorize_url() ); ?>">
		<?php wp_nonce_field( 'devbase_mcp_authorize' ); ?>
		<?php foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope' ) as $key ) : ?>
			<?php if ( isset( $params[ $key ] ) && is_string( $params[ $key ] ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $params[ $key ] ); ?>">
			<?php endif; ?>
		<?php endforeach; ?>
		<div class="actions">
			<button type="submit" name="deny" value="1" class="secondary"><?php esc_html_e( 'Cancel', 'devbase_parent_theme' ); ?></button>
			<button type="submit" name="allow" value="1"><?php esc_html_e( 'Allow', 'devbase_parent_theme' ); ?></button>
		</div>
	</form>
	<?php

	/* translators: %s: app name */
	devbase_mcp_oauth_render_page( sprintf( __( 'Connect %s?', 'devbase_parent_theme' ), $request['client_name'] ), ob_get_clean() );
}

function devbase_mcp_oauth_render_page( $title, $body_html, $status = 200 ) {
	status_header( $status );
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' · ' . get_bloginfo( 'name' ) ); ?></title>
	<style>
		:root { color-scheme: light dark; --bg: #f0f0f1; --card: #fff; --text: #1d2327; --muted: #50575e; --line: #dcdcde; --accent: #2271b1; --warn-bg: #fcf9e8; --warn-line: #dba617; }
		@media (prefers-color-scheme: dark) { :root { --bg: #1d2327; --card: #2c3338; --text: #f0f0f1; --muted: #c3c4c7; --line: #50575e; --accent: #72aee6; --warn-bg: #3a3320; } }
		body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		main { max-width: 440px; margin: 8vh auto; padding: 0 16px; }
		.card { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 28px; }
		.site { color: var(--muted); font-size: 13px; margin: 0 0 8px; }
		h1 { font-size: 22px; line-height: 1.3; margin: 0 0 16px; }
		.lead { font-size: 16px; }
		dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 16px 0; padding: 12px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
		dt { color: var(--muted); }
		dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
		.warn { background: var(--warn-bg); border-left: 4px solid var(--warn-line); padding: 8px 12px; }
		.actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; }
		button { font: inherit; padding: 8px 20px; border-radius: 4px; border: 1px solid var(--accent); background: var(--accent); color: #fff; cursor: pointer; }
		button.secondary { background: transparent; color: var(--accent); }
		a { color: var(--accent); }
	</style>
</head>
<body>
	<main>
		<div class="card">
			<p class="site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the callers. ?>
		</div>
	</main>
</body>
</html>
	<?php
	exit;
}
