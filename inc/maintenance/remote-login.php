<?php

// Remote login: the developer opens this site's wp-admin from Brand Relay, without a password, ONLY while the site's
// own administrators allow it (Theme Settings > Licence & Updates > Remote access).
//
// How it works:
//
//   1. Brand Relay POSTs /wp-json/devbase/v1/login, signed like a sync ping (HMAC under the site key) AND with Brand
//      Relay's own Ed25519 key (devbase_relay_ping_signed_by_relay()), so a stolen site key is not enough. The body
//      names who asks: {"by": "dashboard user", "ip": "their address"}.
//   2. While remote login is allowed, the site answers a login link: wp-login.php?action=devbase_relay_login&ticket=…
//      The ticket is 256 random bits, kept only as its SHA-256, single use, valid DEVBASE_REMOTE_LOGIN_TICKET_TTL seconds.
//   3. Brand Relay sends the browser there. The site uses the ticket up and starts a normal WordPress session for the
//      chosen user, DEVBASE_REMOTE_LOGIN_SESSION_HOURS long, marked as remote so it can be ended on its own.
//
// Brand Relay never holds a password or a session. Nothing here can be switched on from outside: the setting is a
// plain option (not an SCF field, so neither Brand Relay's sync nor the MCP abilities can write it), saved only by an
// administrator's own form post with a nonce. Switching it off ends every remote session at once and deletes the
// support user, if one was made.
//
// It also needs a maintenance plan in force (devbase_remote_login_plan_active()). Without one it cannot be switched
// on, and when the plan lapses the site switches it off itself (devbase_remote_login_enforce_plan()), exactly as the
// client's own switch-off does. That is checked when the plan arrives from Brand Relay, at every login request and
// link, at every request of a remote session, and when the tab is shown. After a renewal the client switches it on
// again themselves.

define( 'DEVBASE_REMOTE_LOGIN_OPTION', 'devbase_remote_login' );
define( 'DEVBASE_REMOTE_LOGIN_LOG', 'devbase_remote_login_log' );
define( 'DEVBASE_REMOTE_LOGIN_TICKET_TTL', 60 );
define( 'DEVBASE_REMOTE_LOGIN_SESSION_HOURS', 4 );
define( 'DEVBASE_REMOTE_LOGIN_LOG_MAX', 50 );
define( 'DEVBASE_REMOTE_LOGIN_SUPPORT_META', 'devbase_remote_support' );
define( 'DEVBASE_REMOTE_LOGIN_SESSION_KEY', 'devbase_remote_login' );

/**
 * The setting: { on: bool, user: 'support'|int (an administrator's ID), email: bool, by: int, at: int }.
 *
 * @return array{on: bool, user: string|int, email: bool, by: int, at: int}
 */
function devbase_remote_login_settings() {
	$stored = get_option( DEVBASE_REMOTE_LOGIN_OPTION );
	$stored = is_array( $stored ) ? $stored : array();
	$user   = $stored['user'] ?? 'support';

	return array(
		'on'    => ! empty( $stored['on'] ),
		'user'  => 'support' === $user ? 'support' : absint( $user ),
		'email' => ! empty( $stored['email'] ),
		'by'    => absint( $stored['by'] ?? 0 ),
		'at'    => absint( $stored['at'] ?? 0 ),
	);
}

/** The support user's login name (filterable, e.g. to carry the agency's name). */
function devbase_remote_login_support_login() {
	return sanitize_user( (string) apply_filters( 'devbase/remote_login/support_login', 'remote-support' ), true );
}

/** The support user made by this file, or null. A user of that name not made here is never used or deleted. */
function devbase_remote_login_support_user() {
	$user = get_user_by( 'login', devbase_remote_login_support_login() );

	return ( $user && get_user_meta( $user->ID, DEVBASE_REMOTE_LOGIN_SUPPORT_META, true ) ) ? $user : null;
}

/**
 * Who a remote login signs in as right now, creating the support user when that is the choice.
 *
 * @return WP_User|WP_Error
 */
function devbase_remote_login_user( $create = false ) {
	$settings = devbase_remote_login_settings();

	if ( 'support' !== $settings['user'] ) {
		$user = get_userdata( (int) $settings['user'] );

		return ( $user && user_can( $user, 'manage_options' ) )
			? $user
			: new WP_Error( 'no_user', 'The administrator chosen for remote login is no longer an administrator.' );
	}

	$user = devbase_remote_login_support_user();

	if ( $user || ! $create ) {
		return $user ? $user : new WP_Error( 'no_user', 'The support user does not exist yet.' );
	}

	if ( username_exists( devbase_remote_login_support_login() ) ) {
		return new WP_Error( 'no_user', 'A user named ' . devbase_remote_login_support_login() . ' already exists and was not made for remote login.' );
	}

	$id = wp_insert_user( array(
		'user_login'   => devbase_remote_login_support_login(),
		'user_pass'    => wp_generate_password( 64, true, true ),
		'display_name' => (string) apply_filters( 'devbase/remote_login/support_name', __( 'Remote support', 'devbase_parent_theme' ) ),
		'role'         => 'administrator',
		'description'  => __( 'Made for remote login from the developer\'s management service. Deleted when remote login is switched off.', 'devbase_parent_theme' ),
	) );

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	update_user_meta( $id, DEVBASE_REMOTE_LOGIN_SUPPORT_META, 1 );

	return get_userdata( $id );
}

/** Appends to the log shown on the Remote access tab: { at, by, ip, user, ok, note }. */
function devbase_remote_login_log( array $entry ) {
	$log   = get_option( DEVBASE_REMOTE_LOGIN_LOG );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array_merge( array( 'at' => time(), 'by' => '', 'ip' => '', 'user' => '', 'ok' => false, 'note' => '' ), $entry );

	update_option( DEVBASE_REMOTE_LOGIN_LOG, array_slice( $log, -DEVBASE_REMOTE_LOGIN_LOG_MAX ), false );
}

// --- 1. The ticket ------------------------------------------------------------------------------------------------

/** The ping check, plus Brand Relay's own signature, which remote login always requires. */
function devbase_remote_login_verify( WP_REST_Request $request ) {
	$verified = devbase_relay_verify_ping( $request );

	if ( true !== $verified ) {
		return $verified;
	}

	if ( true !== devbase_relay_ping_signed_by_relay( $request ) ) {
		return new WP_Error( 'devbase_relay_unsigned', 'Remote login needs Brand Relay\'s own signature, and this site does not have its key yet. Sync the site, then try again.', array( 'status' => 401 ) );
	}

	return true;
}

/** Deletes up to 20 tickets past their lifetime. */
function devbase_remote_login_forget_old_tickets( $now ) {
	global $wpdb;

	$names = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 50",
		$wpdb->esc_like( 'devbase_login_ticket_' ) . '%'
	) );

	foreach ( (array) $names as $name ) {
		$ticket = json_decode( (string) get_option( $name ), true );

		if ( ! is_array( $ticket ) || (int) ( $ticket['expires'] ?? 0 ) < $now ) {
			delete_option( $name );
		}
	}
}

/** POST /devbase/v1/login: a login link, or why there is none. */
function devbase_remote_login_issue( WP_REST_Request $request ) {
	$body = json_decode( $request->get_body(), true );
	$by   = is_array( $body ) && is_string( $body['by'] ?? null ) ? substr( sanitize_text_field( $body['by'] ), 0, 100 ) : '';
	$ip   = is_array( $body ) && is_string( $body['ip'] ?? null ) && filter_var( $body['ip'], FILTER_VALIDATE_IP ) ? $body['ip'] : '';

	if ( ! devbase_remote_login_settings()['on'] ) {
		devbase_remote_login_log( array( 'by' => $by, 'ip' => $ip, 'note' => 'Refused: remote login is switched off.' ) );

		return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_allowed', 'message' => 'The site\'s administrators have not allowed remote login (Theme Settings > Licence & Updates > Remote access).' ), 403 );
	}

	if ( devbase_remote_login_enforce_plan() ) {
		devbase_remote_login_log( array( 'by' => $by, 'ip' => $ip, 'note' => 'Refused: the site has no active maintenance plan.' ) );

		// 409: Brand Relay shows a 409's own message.
		return new WP_REST_Response( array( 'ok' => false, 'error' => 'no_plan', 'message' => 'The site has no active maintenance plan (none, or it is suspended or expired), so remote login was switched off. The client can switch it on again once the plan is active.' ), 409 );
	}

	$user = devbase_remote_login_user( false );

	if ( is_wp_error( $user ) && 'support' !== devbase_remote_login_settings()['user'] ) {
		devbase_remote_login_log( array( 'by' => $by, 'ip' => $ip, 'note' => 'Refused: ' . $user->get_error_message() ) );

		return new WP_REST_Response( array( 'ok' => false, 'error' => 'no_user', 'message' => $user->get_error_message() ), 409 );
	}

	$now    = time();
	$ticket = bin2hex( random_bytes( 32 ) );

	devbase_remote_login_forget_old_tickets( $now );
	add_option( 'devbase_login_ticket_' . hash( 'sha256', $ticket ), wp_json_encode( array( 'expires' => $now + DEVBASE_REMOTE_LOGIN_TICKET_TTL, 'by' => $by, 'ip' => $ip ) ), '', false );

	return new WP_REST_Response( array(
		'ok'         => true,
		'url'        => add_query_arg( array( 'action' => 'devbase_relay_login', 'ticket' => $ticket ), site_url( 'wp-login.php', 'login' ) ),
		'expires_in' => DEVBASE_REMOTE_LOGIN_TICKET_TTL,
	), 200 );
}

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'devbase_relay_enabled' ) || ! devbase_relay_enabled() ) {
		return;
	}

	register_rest_route( 'devbase/v1', '/login', array(
		'methods'             => 'POST',
		'permission_callback' => 'devbase_remote_login_verify',
		'callback'            => 'devbase_remote_login_issue',
	) );
} );

// --- 2. The login link --------------------------------------------------------------------------------------------

/**
 * Uses up a ticket and returns who it signs in, or why not. Separate from the wp-login.php hook so it can be tested.
 *
 * @return array{user: WP_User, by: string, ip: string}|WP_Error
 */
function devbase_remote_login_redeem( $ticket ) {
	if ( ! is_string( $ticket ) || ! preg_match( '/^[a-f0-9]{64}$/', $ticket ) ) {
		return new WP_Error( 'bad_ticket', __( 'This login link is not valid.', 'devbase_parent_theme' ) );
	}

	$name   = 'devbase_login_ticket_' . hash( 'sha256', $ticket );
	$stored = get_option( $name );

	// delete_option() is one DELETE: of two requests with the same ticket, only one deletes the row.
	if ( false === $stored || ! delete_option( $name ) ) {
		return new WP_Error( 'bad_ticket', __( 'This login link was already used or is not valid.', 'devbase_parent_theme' ) );
	}

	$data = json_decode( (string) $stored, true );

	if ( ! is_array( $data ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
		return new WP_Error( 'bad_ticket', __( 'This login link has expired.', 'devbase_parent_theme' ) );
	}

	$by = (string) ( $data['by'] ?? '' );
	$ip = (string) ( $data['ip'] ?? '' );

	if ( ! devbase_remote_login_settings()['on'] ) {
		devbase_remote_login_log( array( 'by' => $by, 'ip' => $ip, 'note' => 'Refused: remote login was switched off.' ) );

		return new WP_Error( 'not_allowed', __( 'Remote login is switched off on this site.', 'devbase_parent_theme' ) );
	}

	if ( devbase_remote_login_enforce_plan() ) {
		devbase_remote_login_log( array( 'by' => $by, 'ip' => $ip, 'note' => 'Refused: the site has no active maintenance plan.' ) );

		return new WP_Error( 'no_plan', __( 'Remote login was switched off: this site has no active maintenance plan.', 'devbase_parent_theme' ) );
	}

	$user = devbase_remote_login_user( true );

	if ( is_wp_error( $user ) ) {
		devbase_remote_login_log( array( 'by' => $by, 'ip' => $ip, 'note' => 'Refused: ' . $user->get_error_message() ) );

		return $user;
	}

	return array( 'user' => $user, 'by' => $by, 'ip' => $ip );
}

/** Starts the remote session: a normal WordPress login, short, and marked so it can be ended on its own. */
function devbase_remote_login_start_session( WP_User $user, $by, $ip ) {
	$length = function () {
		return DEVBASE_REMOTE_LOGIN_SESSION_HOURS * HOUR_IN_SECONDS;
	};
	$mark   = function ( $session ) use ( $by ) {
		$session[ DEVBASE_REMOTE_LOGIN_SESSION_KEY ] = '' !== $by ? $by : 'Brand Relay';

		return $session;
	};

	add_filter( 'auth_cookie_expiration', $length, 999 );
	add_filter( 'attach_session_information', $mark, 999 );
	wp_set_auth_cookie( $user->ID, false, is_ssl() );
	remove_filter( 'auth_cookie_expiration', $length, 999 );
	remove_filter( 'attach_session_information', $mark, 999 );

	wp_set_current_user( $user->ID );
	devbase_remote_login_log( array( 'by' => $by, 'ip' => $ip, 'user' => $user->user_login, 'ok' => true, 'note' => 'Logged in.' ) );
	devbase_remote_login_notify( $user, $by, $ip );

	/** Audit plugins hear of it as of any login. */
	do_action( 'wp_login', $user->user_login, $user );
}

/** The email to the administrator who allowed remote login, when they asked for one. */
function devbase_remote_login_notify( WP_User $user, $by, $ip ) {
	$settings = devbase_remote_login_settings();
	$to       = $settings['email'] && $settings['by'] ? get_userdata( $settings['by'] ) : null;

	if ( ! $to || ! is_email( $to->user_email ) ) {
		return;
	}

	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	wp_mail(
		$to->user_email,
		/* translators: %s: site name */
		sprintf( __( '[%s] Your developer logged in remotely', 'devbase_parent_theme' ), $site ),
		sprintf(
			/* translators: 1: site name, 2: user, 3: who, 4: IP address, 5: hours, 6: settings URL */
			__( "Your developer logged in to %1\$s remotely as %2\$s.\n\nAsked by: %3\$s\nFrom: %4\$s\n\nThe session ends by itself after %5\$d hours. To stop remote logins, switch them off here (this also ends any remote session now):\n%6\$s", 'devbase_parent_theme' ),
			$site,
			$user->user_login,
			'' !== $by ? $by : '—',
			'' !== $ip ? $ip : '—',
			DEVBASE_REMOTE_LOGIN_SESSION_HOURS,
			devbase_remote_login_settings_url()
		)
	);
}

add_action( 'login_form_devbase_relay_login', function () {
	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );

	$redeemed = devbase_remote_login_redeem( isset( $_GET['ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['ticket'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( is_wp_error( $redeemed ) ) {
		wp_die( esc_html( $redeemed->get_error_message() . ' ' . __( 'Open WP admin from the management service again.', 'devbase_parent_theme' ) ), esc_html__( 'Remote login', 'devbase_parent_theme' ), array( 'response' => 403 ) );
	}

	devbase_remote_login_start_session( $redeemed['user'], $redeemed['by'], $redeemed['ip'] );
	wp_safe_redirect( admin_url() );
	exit;
} );

/** Whether the site has a maintenance plan in force now (any plan: active, started, not expired), as Brand Relay last said. */
function devbase_remote_login_plan_active() {
	return (bool) apply_filters( 'devbase/remote_login/plan_active', function_exists( 'devbase_relay_plan_active' ) && devbase_relay_plan_active() );
}

/**
 * Switches remote login off when it is on and the site has no maintenance plan in force, the same way the client's
 * own switch-off does (remote sessions end, the support user is deleted). Returns true when there is no plan, whether
 * it was on or not.
 */
function devbase_remote_login_enforce_plan() {
	if ( devbase_remote_login_plan_active() ) {
		return false;
	}

	$settings = devbase_remote_login_settings();

	if ( $settings['on'] ) {
		devbase_remote_login_save( array( 'on' => false, 'user' => $settings['user'], 'email' => $settings['email'] ), (int) $settings['by'], 'the site has no active maintenance plan' );
	}

	return true;
}

// When the plan arrives from Brand Relay (every pull of /v1/site).
add_action( 'add_option_devbase_relay_plan', 'devbase_remote_login_enforce_plan' );
add_action( 'update_option_devbase_relay_plan', 'devbase_remote_login_enforce_plan' );

/**
 * At every request of a remote session: when remote login is off, or the plan lapsed (which switches it off), the
 * session ends now. A look at the session already loaded; nothing else runs for anyone else.
 */
function devbase_remote_login_guard_session() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$token   = wp_get_session_token();
	$session = '' !== $token ? WP_Session_Tokens::get_instance( get_current_user_id() )->get( $token ) : null;

	if ( ! is_array( $session ) || ! isset( $session[ DEVBASE_REMOTE_LOGIN_SESSION_KEY ] ) ) {
		return;
	}

	devbase_remote_login_enforce_plan();

	if ( devbase_remote_login_settings()['on'] ) {
		return;
	}

	WP_Session_Tokens::get_instance( get_current_user_id() )->destroy( $token );
	wp_clear_auth_cookie();
	wp_set_current_user( 0 );
}
add_action( 'init', 'devbase_remote_login_guard_session', 1 );

// --- 3. Switching it off ------------------------------------------------------------------------------------------

/** Ends every remote session, on every user. Returns how many. */
function devbase_remote_login_end_sessions() {
	global $wpdb;

	$ended = 0;
	$ids   = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens' AND meta_value LIKE %s",
		'%' . $wpdb->esc_like( DEVBASE_REMOTE_LOGIN_SESSION_KEY ) . '%'
	) );

	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		$sessions = get_user_meta( $id, 'session_tokens', true );

		if ( ! is_array( $sessions ) ) {
			continue;
		}

		$kept = array_filter( $sessions, function ( $session ) {
			return ! is_array( $session ) || ! isset( $session[ DEVBASE_REMOTE_LOGIN_SESSION_KEY ] );
		} );

		$ended += count( $sessions ) - count( $kept );
		$kept ? update_user_meta( $id, 'session_tokens', $kept ) : delete_user_meta( $id, 'session_tokens' );
	}

	return $ended;
}

/** Deletes the support user, giving what it wrote to $reassign (an administrator). */
function devbase_remote_login_delete_support_user( $reassign ) {
	$user = devbase_remote_login_support_user();

	if ( ! $user ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	return wp_delete_user( $user->ID, $reassign && (int) $reassign !== $user->ID ? (int) $reassign : null );
}

/**
 * Saves the setting. Any change to who may log in, or to whether anyone may, ends the remote sessions open now, and
 * the support user is deleted once it is no longer the choice.
 *
 * @param array $input { on, user, email } as posted.
 * @param int    $by  The administrator saving it (or, when the site switches it off itself, the one who switched it on).
 * @param string $why Why, when the site switches it off itself.
 * @return array The saved setting.
 */
function devbase_remote_login_save( array $input, $by, $why = '' ) {
	$before = devbase_remote_login_settings();
	$user   = isset( $input['user'] ) && 'support' !== $input['user'] ? absint( $input['user'] ) : 'support';

	if ( 'support' !== $user && ! user_can( $user, 'manage_options' ) ) {
		$user = 'support';
	}

	// Without a maintenance plan in force it cannot be switched on.
	$on = ! empty( $input['on'] ) && devbase_remote_login_plan_active();

	$after = array(
		'on'    => $on,
		'user'  => $user,
		'email' => ! empty( $input['email'] ),
		'by'    => $before['on'] && $on ? $before['by'] : (int) $by,
		'at'    => $before['on'] && $on ? $before['at'] : time(),
	);

	// The email goes to whoever last chose it.
	if ( $after['email'] && ! $before['email'] ) {
		$after['by'] = (int) $by;
	}

	update_option( DEVBASE_REMOTE_LOGIN_OPTION, $after, false );

	if ( $before['on'] !== $after['on'] || $before['user'] !== $after['user'] ) {
		$ended = devbase_remote_login_end_sessions();
		$note  = $after['on'] ? 'Remote login switched on.' : ( '' !== $why ? "Remote login switched off: {$why}." : 'Remote login switched off.' );

		if ( ! $after['on'] || 'support' !== $after['user'] ) {
			devbase_remote_login_delete_support_user( $by );
		}

		$actor = get_userdata( (int) $by );
		devbase_remote_login_log( array( 'by' => '' !== $why ? 'site' : ( $actor ? $actor->user_login : '' ), 'ok' => true, 'note' => $note . ( $ended ? " {$ended} remote session(s) ended." : '' ) ) );
	}

	return $after;
}

/** The panel's posted choice, saved (the caller verified the nonce). */
function devbase_remote_login_save_posted() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller verified the nonce.
	devbase_remote_login_save(
		array(
			'on'    => ! empty( $_POST['devbase_remote_login_on'] ),
			'user'  => isset( $_POST['devbase_remote_login_user'] ) ? sanitize_text_field( wp_unslash( $_POST['devbase_remote_login_user'] ) ) : 'support',
			'email' => ! empty( $_POST['devbase_remote_login_email'] ),
		),
		get_current_user_id()
	);
	// phpcs:enable
}

// Saved with the page's own Save changes (the panel sits inside its form). Not on acf/save_post: SCF skips its save,
// and that action, when the page posts none of its own fields, which is the case on Licence & Updates. The panel's
// nonce is only in that form, so a POST that carries it is that form's.
function devbase_remote_login_save_from_page() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['devbase_remote_login_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['devbase_remote_login_nonce'] ) ), 'devbase_remote_login' ) ) {
		return;
	}

	devbase_remote_login_save_posted();
}
add_action( 'admin_init', 'devbase_remote_login_save_from_page' );

/** Where the setting is (the Remote access tab). */
function devbase_remote_login_settings_url() {
	return function_exists( 'devbase_settings_page_url' ) ? devbase_settings_page_url( 'licence-settings' ) : admin_url( 'admin.php?page=licence-settings' );
}

// --- 4. The Remote access tab -------------------------------------------------------------------------------------

/** The tab's content: what it does, the controls, and the last logins. */
function devbase_remote_login_panel_html() {
	$plan     = ! devbase_remote_login_enforce_plan(); // First: a lapsed plan switches it off (and deletes the support user).
	$settings = devbase_remote_login_settings();
	$admins   = get_users( array( 'role' => 'administrator', 'orderby' => 'display_name', 'number' => 100 ) );
	$support  = devbase_remote_login_support_user();
	$admins   = array_filter( $admins, function ( $user ) use ( $support ) {
		return ! $support || $user->ID !== $support->ID;
	} );
	$log      = array_reverse( (array) get_option( DEVBASE_REMOTE_LOGIN_LOG, array() ) );
	$key      = function_exists( 'devbase_relay_ping_key' ) && '' !== devbase_relay_ping_key();
	$on       = $settings['on'];

	ob_start();
	?>
	<div class="devbase-remote-login">
		<p><strong><?php echo $on ? esc_html__( 'Remote login is ON.', 'devbase_parent_theme' ) : esc_html__( 'Remote login is off.', 'devbase_parent_theme' ); ?></strong>
		<?php
		if ( $on && $settings['at'] ) {
			$who = get_userdata( $settings['by'] );
			/* translators: 1: user, 2: date */
			echo esc_html( sprintf( __( 'Allowed by %1$s on %2$s.', 'devbase_parent_theme' ), $who ? $who->display_name : '?', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $settings['at'] ) ) );
		}
		?>
		</p>
		<div style="border-left:4px solid #dba617;background:#fcf9e8;padding:8px 12px;margin:0 0 12px;max-width:720px">
			<p style="margin:0 0 6px"><strong><?php esc_html_e( 'What this allows', 'devbase_parent_theme' ); ?></strong></p>
			<p style="margin:0"><?php esc_html_e( 'While this is on, your developer can open this site\'s dashboard from their management service and is logged in as an administrator, without your password. They can then do anything an administrator can: change content, settings, plugins and users. Each login is recorded below, and a session ends by itself after 4 hours. Switch it off at any time: that also ends any remote session at once.', 'devbase_parent_theme' ); ?></p>
		</div>
		<input type="hidden" name="devbase_remote_login_nonce" value="<?php echo esc_attr( wp_create_nonce( 'devbase_remote_login' ) ); ?>" />
		<p><label><input type="checkbox" name="devbase_remote_login_on" value="1" <?php checked( $on ); ?> <?php disabled( ! $plan ); ?> />
			<?php esc_html_e( 'I allow my developer to log in to this site remotely, as an administrator, without my password.', 'devbase_parent_theme' ); ?></label></p>
		<p><label for="devbase-remote-login-user"><?php esc_html_e( 'Log in as', 'devbase_parent_theme' ); ?></label><br />
			<select id="devbase-remote-login-user" name="devbase_remote_login_user">
				<option value="support" <?php selected( 'support', $settings['user'] ); ?>><?php esc_html_e( 'A separate support user (made at the first login, deleted when this is switched off)', 'devbase_parent_theme' ); ?></option>
				<?php foreach ( $admins as $admin ) : ?>
					<option value="<?php echo esc_attr( (string) $admin->ID ); ?>" <?php selected( $admin->ID, $settings['user'] ); ?>><?php echo esc_html( $admin->display_name . ' (' . $admin->user_login . ')' ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label><input type="checkbox" name="devbase_remote_login_email" value="1" <?php checked( $settings['email'] ); ?> />
			<?php esc_html_e( 'Email me each time my developer logs in.', 'devbase_parent_theme' ); ?></label></p>
		<?php if ( ! $plan ) : ?>
			<p class="description"><strong><?php esc_html_e( 'Remote login needs an active maintenance plan.', 'devbase_parent_theme' ); ?></strong> <?php esc_html_e( 'This site has none (or it is suspended or expired), so remote login is off and cannot be switched on. Remote login switches itself off when the plan ends.', 'devbase_parent_theme' ); ?></p>
		<?php endif; ?>
		<?php if ( $on && ! $key ) : ?>
			<p class="description"><?php esc_html_e( 'The management service has not given this site its signing key yet, so logins will be refused until the next sync.', 'devbase_parent_theme' ); ?></p>
		<?php endif; ?>
		<?php if ( $log ) : ?>
			<p style="margin-top:16px"><strong><?php esc_html_e( 'Recent activity', 'devbase_parent_theme' ); ?></strong></p>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
				<?php foreach ( array_slice( $log, 0, 10 ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $row['at'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['note'] ?? '' ) . ( ! empty( $row['user'] ) ? ' (' . $row['user'] . ')' : '' ) ); ?></td>
						<td><?php echo esc_html( trim( (string) ( $row['by'] ?? '' ) . ' ' . ( ! empty( $row['ip'] ) ? '· ' . $row['ip'] : '' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}
