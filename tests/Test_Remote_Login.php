<?php
/**
 * Remote login (inc/maintenance/remote-login.php) and Brand Relay's own ping signature (inc/updates/relay-sync.php).
 */
class Test_Remote_Login extends WP_UnitTestCase {

	private $keypair;
	private $mails = array();
	private $mail_filter;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( ! defined( 'DEVBASE_RELAY_URL' ) ) {
			define( 'DEVBASE_RELAY_URL', 'https://relay.test' );
		}
		if ( ! defined( 'DEVBASE_RELAY_SITE_KEY' ) ) {
			define( 'DEVBASE_RELAY_SITE_KEY', 'brs_testkey' );
		}
	}

	public function set_up() {
		parent::set_up();

		static $n = 0;
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( ++$n % 250 + 1 );

		$this->keypair = sodium_crypto_sign_keypair();
		update_option( DEVBASE_RELAY_PING_KEY_OPTION, base64_encode( sodium_crypto_sign_publickey( $this->keypair ) ) );
		foreach ( array( DEVBASE_REMOTE_LOGIN_OPTION, DEVBASE_REMOTE_LOGIN_LOG ) as $option ) {
			delete_option( $option );
		}
		$this->plan( 'active' );
		add_filter( 'send_auth_cookies', '__return_false' );

		$this->mails       = array();
		$this->mail_filter = function ( $pre, $atts ) {
			$this->mails[] = $atts;

			return true;
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
		delete_option( DEVBASE_RELAY_PING_KEY_OPTION );
		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		parent::tear_down();
	}

	/** A request signed as Brand Relay signs it: the HMAC under the site key, and (unless told not to) its Ed25519 key. */
	private function signed( $route, $body, $relay = true, $secret_key = null ) {
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 12 ) );
		$origin    = 'https://relay.test';
		$message   = $timestamp . '.' . $nonce . '.' . $origin . '.' . $body;

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body( $body );
		$request->set_header( 'origin', $origin );
		$request->set_header( 'x-relay-timestamp', $timestamp );
		$request->set_header( 'x-relay-nonce', $nonce );
		$request->set_header( 'x-relay-signature', hash_hmac( 'sha256', $message, devbase_relay_ping_secret() ) );
		if ( $relay ) {
			$request->set_header( 'x-relay-ed25519', base64_encode( sodium_crypto_sign_detached( $message, $secret_key ?? sodium_crypto_sign_secretkey( $this->keypair ) ) ) );
		}

		return $request;
	}

	/** The plan as Brand Relay's /v1/site answer gives it: active (until next year), expired, suspended or none. */
	private function plan( $state ) {
		$licence = 'none' === $state ? null : array(
			'plan'       => 'Care',
			'status'     => 'expired' === $state ? 'expired' : $state,
			'services'   => array( 'health' ),
			'starts_at'  => gmdate( 'c', time() - YEAR_IN_SECONDS ),
			'expires_at' => gmdate( 'c', 'expired' === $state ? time() - 60 : time() + YEAR_IN_SECONDS ),
		);
		devbase_relay_store_plan( array( 'licence' => $licence ) );
	}

	private function allow( $user = 'support', $email = false ) {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		devbase_remote_login_save( array( 'on' => true, 'user' => 'support' === $user ? 'support' : $user, 'email' => $email ), $admin );

		return $admin;
	}

	private function ticket_url() {
		$response = rest_get_server()->dispatch( $this->signed( '/devbase/v1/login', '{"by":"owner","ip":"203.0.113.9"}' ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data()['url'];
	}

	private function ticket_of( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query['ticket'];
	}

	// --- Brand Relay's own signature ---------------------------------------------------------------------------

	public function test_the_ping_key_is_taken_from_brand_relays_answer_only_when_it_is_a_real_key() {
		delete_option( DEVBASE_RELAY_PING_KEY_OPTION );
		$key = base64_encode( sodium_crypto_sign_publickey( $this->keypair ) );

		devbase_relay_store_ping_key( array( 'ping_key' => 'not-a-key' ) );
		$this->assertSame( '', devbase_relay_ping_key() );

		devbase_relay_store_ping_key( array( 'ping_key' => $key ) );
		$this->assertSame( sodium_crypto_sign_publickey( $this->keypair ), devbase_relay_ping_key() );

		devbase_relay_store_ping_key( array() );
		$this->assertSame( $key, get_option( DEVBASE_RELAY_PING_KEY_OPTION ), 'An answer without one keeps the stored key.' );
	}

	public function test_a_ping_with_brand_relays_signature_is_checked_and_a_forged_one_is_refused() {
		$this->assertTrue( devbase_relay_ping_signed_by_relay( $this->signed( '/devbase/v1/sync', '{"run":["health"]}' ) ) );

		$other  = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$forged = $this->signed( '/devbase/v1/sync', '', true, $other );
		$this->assertFalse( devbase_relay_ping_signed_by_relay( $forged ) );
		$this->assertInstanceOf( WP_Error::class, devbase_relay_verify_ping( $forged ), 'Right site key, wrong Brand Relay key: refused.' );

		$this->assertTrue( devbase_relay_verify_ping( $this->signed( '/devbase/v1/sync', '', false ) ), 'An unsigned ping (older Brand Relay) still syncs.' );

		delete_option( DEVBASE_RELAY_PING_KEY_OPTION );
		$this->assertNull( devbase_relay_ping_signed_by_relay( $this->signed( '/devbase/v1/sync', '' ) ), 'No key known yet.' );
	}

	// --- The ticket ---------------------------------------------------------------------------------------------

	public function test_no_login_link_while_the_client_has_not_allowed_it() {
		$response = rest_get_server()->dispatch( $this->signed( '/devbase/v1/login', '{"by":"owner"}' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_allowed', $response->get_data()['error'] );
		$this->assertStringContainsString( 'switched off', get_option( DEVBASE_REMOTE_LOGIN_LOG )[0]['note'] );
	}

	public function test_the_site_key_alone_is_not_enough_brand_relays_own_signature_is_required() {
		$this->allow();

		$this->assertSame( 401, rest_get_server()->dispatch( $this->signed( '/devbase/v1/login', '{}', false ) )->get_status(), 'Valid HMAC, no Ed25519.' );

		delete_option( DEVBASE_RELAY_PING_KEY_OPTION );
		$this->assertSame( 401, rest_get_server()->dispatch( $this->signed( '/devbase/v1/login', '{}' ) )->get_status(), 'No key known: refused, not trusted.' );
	}

	public function test_a_login_link_is_single_use_short_lived_and_the_ticket_is_kept_only_as_a_hash() {
		$this->allow();
		$url    = $this->ticket_url();
		$ticket = $this->ticket_of( $url );

		$this->assertStringContainsString( 'wp-login.php?action=devbase_relay_login', $url );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $ticket );
		$this->assertFalse( get_option( 'devbase_login_ticket_' . $ticket ), 'Not stored as is.' );
		$stored = json_decode( get_option( 'devbase_login_ticket_' . hash( 'sha256', $ticket ) ), true );
		$this->assertLessThanOrEqual( time() + DEVBASE_REMOTE_LOGIN_TICKET_TTL, $stored['expires'] );

		$this->assertIsArray( devbase_remote_login_redeem( $ticket ) );
		$this->assertInstanceOf( WP_Error::class, devbase_remote_login_redeem( $ticket ), 'Used once.' );
		$this->assertInstanceOf( WP_Error::class, devbase_remote_login_redeem( str_repeat( 'a', 64 ) ) );
		$this->assertInstanceOf( WP_Error::class, devbase_remote_login_redeem( 'x' ) );
	}

	public function test_an_expired_link_or_one_used_after_remote_login_was_switched_off_does_nothing() {
		$admin  = $this->allow();
		$ticket = $this->ticket_of( $this->ticket_url() );
		update_option( 'devbase_login_ticket_' . hash( 'sha256', $ticket ), wp_json_encode( array( 'expires' => time() - 1 ) ) );
		$this->assertSame( 'bad_ticket', devbase_remote_login_redeem( $ticket )->get_error_code() );

		$ticket = $this->ticket_of( $this->ticket_url() );
		devbase_remote_login_save( array( 'on' => false ), $admin );
		$this->assertSame( 'not_allowed', devbase_remote_login_redeem( $ticket )->get_error_code() );
	}

	// --- Who is logged in ---------------------------------------------------------------------------------------

	public function test_the_support_user_is_made_at_the_first_login_as_an_administrator() {
		$this->allow();
		$this->assertNull( devbase_remote_login_support_user(), 'Not before a login.' );

		$redeemed = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) );

		$this->assertSame( 'remote-support', $redeemed['user']->user_login );
		$this->assertTrue( user_can( $redeemed['user'], 'manage_options' ) );
		$this->assertSame( 'owner', $redeemed['by'] );
		$this->assertSame( $redeemed['user']->ID, devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) )['user']->ID, 'Then reused.' );
	}

	public function test_a_user_already_named_like_the_support_user_is_never_taken_over() {
		self::factory()->user->create( array( 'user_login' => 'remote-support', 'role' => 'subscriber' ) );
		$this->allow();

		$this->assertSame( 'no_user', devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) )->get_error_code() );
	}

	public function test_an_existing_administrator_can_be_chosen_and_only_while_they_are_one() {
		$chosen = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->allow( $chosen );

		$this->assertSame( $chosen, devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) )['user']->ID );
		$this->assertNull( devbase_remote_login_support_user(), 'No support user made.' );

		( new WP_User( $chosen ) )->set_role( 'editor' );
		$response = rest_get_server()->dispatch( $this->signed( '/devbase/v1/login', '{}' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'no_user', $response->get_data()['error'] );
	}

	public function test_an_editor_cannot_be_chosen() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame( 'support', devbase_remote_login_save( array( 'on' => true, 'user' => (string) $editor ), $admin )['user'] );
	}

	// --- The session --------------------------------------------------------------------------------------------

	public function test_the_session_is_short_marked_as_remote_recorded_and_emailed_when_asked() {
		$admin    = $this->allow( 'support', true );
		$redeemed = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) );
		$before   = time();

		devbase_remote_login_start_session( $redeemed['user'], $redeemed['by'], $redeemed['ip'] );

		$this->assertSame( $redeemed['user']->ID, get_current_user_id() );
		$sessions = array_values( WP_Session_Tokens::get_instance( $redeemed['user']->ID )->get_all() );
		$this->assertCount( 1, $sessions );
		$this->assertSame( 'owner', $sessions[0][ DEVBASE_REMOTE_LOGIN_SESSION_KEY ] );
		$this->assertLessThanOrEqual( $before + DEVBASE_REMOTE_LOGIN_SESSION_HOURS * HOUR_IN_SECONDS + 5, $sessions[0]['expiration'] );
		$this->assertSame( 14 * DAY_IN_SECONDS, (int) apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, 1, true ), 'The short length was for this login only.' );

		$log = get_option( DEVBASE_REMOTE_LOGIN_LOG );
		$this->assertTrue( end( $log )['ok'] );
		$this->assertSame( 'remote-support', end( $log )['user'] );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( get_userdata( $admin )->user_email, $this->mails[0]['to'] );
		$this->assertStringContainsString( 'owner', $this->mails[0]['message'] );
	}

	public function test_no_email_unless_the_client_asked_for_one() {
		$this->allow();
		$redeemed = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) );
		devbase_remote_login_start_session( $redeemed['user'], $redeemed['by'], $redeemed['ip'] );

		$this->assertSame( array(), $this->mails );
	}

	// --- Switching it off ---------------------------------------------------------------------------------------

	public function test_switching_it_off_ends_remote_sessions_only_and_deletes_the_support_user_giving_its_posts_to_the_admin() {
		$chosen = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin  = $this->allow( $chosen );
		// The chosen admin's own session, and a remote one.
		WP_Session_Tokens::get_instance( $chosen )->create( time() + DAY_IN_SECONDS );
		$redeemed = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) );
		devbase_remote_login_start_session( $redeemed['user'], $redeemed['by'], $redeemed['ip'] );
		$this->assertCount( 2, WP_Session_Tokens::get_instance( $chosen )->get_all() );

		// Switch to the support user: the remote session on the old choice ends, the user's own stays.
		devbase_remote_login_save( array( 'on' => true, 'user' => 'support' ), $admin );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $chosen )->get_all() );

		$support = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) )['user'];
		$post    = self::factory()->post->create( array( 'post_author' => $support->ID ) );

		devbase_remote_login_save( array( 'on' => false ), $admin );

		$this->assertFalse( get_userdata( $support->ID ), 'Deleted.' );
		$this->assertSame( $admin, (int) get_post( $post )->post_author, 'Its post kept, given to the admin who switched it off.' );
		$this->assertStringContainsString( 'switched off', end( get_option( DEVBASE_REMOTE_LOGIN_LOG ) )['note'] );
	}

	public function test_saving_without_a_change_ends_nothing() {
		$admin    = $this->allow();
		$redeemed = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) );
		devbase_remote_login_start_session( $redeemed['user'], $redeemed['by'], $redeemed['ip'] );

		devbase_remote_login_save( array( 'on' => true, 'user' => 'support', 'email' => true ), $admin );

		$this->assertCount( 1, WP_Session_Tokens::get_instance( $redeemed['user']->ID )->get_all() );
	}

	public function test_the_setting_is_not_a_field_anything_remote_can_write() {
		$this->assertSame( array(), array_intersect( array( 'devbase_remote_login', 'remote_login' ), array_keys( devbase_relay_params() ) ), 'Not a Brand Relay site parameter.' );
		$this->assertFalse( get_option( 'options_' . DEVBASE_REMOTE_LOGIN_OPTION ), 'Not stored as an SCF option field.' );
	}

	public function test_the_tab_shows_the_warning_and_the_controls() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = devbase_remote_login_panel_html();

		$this->assertStringContainsString( __( 'Remote login is off.', 'devbase_parent_theme' ), $html );
		$this->assertStringContainsString( esc_html__( 'I allow my developer to log in to this site remotely, as an administrator, without my password.', 'devbase_parent_theme' ), $html );
		$this->assertStringNotContainsString( 'Brand Relay', $html, 'Product names are for the developer, not the client.' );
		$this->assertStringContainsString( 'name="devbase_remote_login_on"', $html );
		$this->assertStringContainsString( 'name="devbase_remote_login_nonce"', $html );
		$this->assertStringNotContainsString( 'formaction', $html, 'One button: the page\'s own Save changes.' );
		$this->assertStringContainsString( 'value="support"', $html );
	}

	// --- The maintenance plan -----------------------------------------------------------------------------------

	public function test_without_an_active_plan_it_cannot_be_switched_on_and_the_tab_says_why() {
		foreach ( array( 'none', 'expired', 'suspended' ) as $state ) {
			$this->plan( $state );
			$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

			$this->assertFalse( devbase_remote_login_save( array( 'on' => true, 'user' => 'support' ), $admin )['on'], $state );

			wp_set_current_user( $admin );
			$html = devbase_remote_login_panel_html();
			$this->assertMatchesRegularExpression( '/name="devbase_remote_login_on"[^>]*disabled/', $html, $state );
			$this->assertStringContainsString( esc_html__( 'Remote login needs an active maintenance plan.', 'devbase_parent_theme' ), $html );
		}
	}

	public function test_when_the_plan_lapses_the_site_switches_it_off_itself_and_it_stays_off_after_a_renewal() {
		$admin    = $this->allow();
		$redeemed = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) );
		devbase_remote_login_start_session( $redeemed['user'], $redeemed['by'], $redeemed['ip'] );
		$support = $redeemed['user']->ID;
		$post    = self::factory()->post->create( array( 'post_author' => $support ) );

		// Brand Relay's next answer says the plan expired: switched off on arrival, like the client's own switch-off.
		$this->plan( 'expired' );

		$this->assertFalse( devbase_remote_login_settings()['on'] );
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $support )->get_all(), 'Remote session ended.' );
		$this->assertFalse( get_userdata( $support ), 'Support user deleted.' );
		$this->assertSame( $admin, (int) get_post( $post )->post_author, 'Its post given to the admin who switched it on.' );
		$this->assertStringContainsString( 'no active maintenance plan', end( get_option( DEVBASE_REMOTE_LOGIN_LOG ) )['note'] );

		// Renewed: the door stays closed until the client opens it again.
		$this->plan( 'active' );
		$this->assertFalse( devbase_remote_login_settings()['on'] );
		$response = rest_get_server()->dispatch( $this->signed( '/devbase/v1/login', '{"by":"owner"}' ) );
		$this->assertSame( 'not_allowed', $response->get_data()['error'] );
	}

	public function test_a_plan_that_ends_by_the_clock_closes_it_at_the_next_login_request_with_a_reason_brand_relay_shows() {
		$this->allow();
		// Expired by the clock, before any new answer from Brand Relay (stored directly: no hook runs).
		$plan                          = get_option( DEVBASE_RELAY_PLAN_OPTION );
		$plan['licence']['expires_at'] = gmdate( 'c', time() - 60 );
		remove_all_actions( 'update_option_' . DEVBASE_RELAY_PLAN_OPTION );
		update_option( DEVBASE_RELAY_PLAN_OPTION, $plan );
		$this->assertTrue( devbase_remote_login_settings()['on'] );

		$response = rest_get_server()->dispatch( $this->signed( '/devbase/v1/login', '{"by":"owner"}' ) );

		$this->assertSame( 409, $response->get_status(), 'Brand Relay shows the message of a 409.' );
		$this->assertSame( 'no_plan', $response->get_data()['error'] );
		$this->assertFalse( devbase_remote_login_settings()['on'] );
	}

	public function test_a_link_issued_before_the_plan_lapsed_does_not_log_in() {
		$this->allow();
		$ticket = $this->ticket_of( $this->ticket_url() );
		add_filter( 'devbase/remote_login/plan_active', '__return_false' );

		$this->assertSame( 'no_plan', devbase_remote_login_redeem( $ticket )->get_error_code() );
		$this->assertFalse( devbase_remote_login_settings()['on'] );
		remove_filter( 'devbase/remote_login/plan_active', '__return_false' );
	}

	public function test_an_open_remote_session_ends_at_its_next_request_once_the_plan_has_lapsed() {
		$this->allow();
		$user = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) )['user'];
		$mark = function ( $info ) {
			return $info + array( DEVBASE_REMOTE_LOGIN_SESSION_KEY => 'owner' );
		};
		add_filter( 'attach_session_information', $mark );
		$token = WP_Session_Tokens::get_instance( $user->ID )->create( time() + HOUR_IN_SECONDS );
		remove_filter( 'attach_session_information', $mark );

		// This request comes from the remote session.
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, time() + HOUR_IN_SECONDS, 'logged_in', $token );
		wp_set_current_user( $user->ID );

		devbase_remote_login_guard_session();
		$this->assertSame( $user->ID, get_current_user_id(), 'Plan active: nothing happens.' );

		add_filter( 'devbase/remote_login/plan_active', '__return_false' );
		devbase_remote_login_guard_session();
		remove_filter( 'devbase/remote_login/plan_active', '__return_false' );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$this->assertSame( 0, get_current_user_id(), 'Logged out now.' );
		$this->assertFalse( devbase_remote_login_settings()['on'], 'And switched off.' );
		$this->assertNull( WP_Session_Tokens::get_instance( $user->ID )->get( $token ) );
	}


	public function test_the_health_report_says_whether_it_is_on_and_a_lapsed_plan_reports_off() {
		$this->assertSame( array( 'on' => false, 'as' => 'support' ), devbase_heartbeat_report()['remote_login'] );

		$chosen = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->allow( $chosen );
		$this->assertSame( array( 'on' => true, 'as' => 'admin' ), devbase_heartbeat_report()['remote_login'], 'Which administrator is not said.' );

		add_filter( 'devbase/remote_login/plan_active', '__return_false' );
		$this->assertFalse( devbase_heartbeat_report()['remote_login']['on'] );
		remove_filter( 'devbase/remote_login/plan_active', '__return_false' );
	}


	/** A remote session as the browser holds it: its logged-in cookie. */
	private function remote_cookie( WP_User $user ) {
		$mark = function ( $info ) {
			return $info + array( DEVBASE_REMOTE_LOGIN_SESSION_KEY => 'owner' );
		};
		add_filter( 'attach_session_information', $mark );
		$token = WP_Session_Tokens::get_instance( $user->ID )->create( time() + HOUR_IN_SECONDS );
		remove_filter( 'attach_session_information', $mark );

		return wp_generate_auth_cookie( $user->ID, time() + HOUR_IN_SECONDS, 'logged_in', $token );
	}

	public function test_an_open_remote_session_stops_working_at_once_when_the_user_changes_it_is_switched_off_or_the_plan_lapses() {
		$cases = array(
			'the chosen user changes' => function ( $admin ) {
				devbase_remote_login_save( array( 'on' => true, 'user' => $admin ), $admin );
			},
			'switched off'            => function ( $admin ) {
				devbase_remote_login_save( array( 'on' => false ), $admin );
			},
			'the plan lapses'         => function () {
				$this->plan( 'expired' );
			},
		);

		$_SERVER['REQUEST_METHOD'] = 'GET'; // wp_validate_auth_cookie() reads it.

		foreach ( $cases as $case => $change ) {
			$this->plan( 'active' );
			$admin  = $this->allow();
			$user   = devbase_remote_login_redeem( $this->ticket_of( $this->ticket_url() ) )['user'];
			$cookie = $this->remote_cookie( $user );
			$this->assertSame( $user->ID, wp_validate_auth_cookie( $cookie, 'logged_in' ), "{$case}: works before." );

			$change( $admin );

			$this->assertFalse( wp_validate_auth_cookie( $cookie, 'logged_in' ), "{$case}: the cookie no longer logs anyone in." );
		}
	}


	public function test_the_pages_own_save_changes_saves_it_and_only_with_the_panels_nonce_and_an_administrator() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = function ( $nonce ) {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array( 'devbase_remote_login_nonce' => $nonce, 'devbase_remote_login_on' => '1', 'devbase_remote_login_user' => 'support', 'devbase_remote_login_email' => '1', 'acf' => array() );
			devbase_remote_login_save_from_page();
			$_POST                     = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		};

		wp_set_current_user( $admin );
		$post( 'forged' );
		$this->assertFalse( devbase_remote_login_settings()['on'], 'A wrong nonce saves nothing.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post( wp_create_nonce( 'devbase_remote_login' ) );
		$this->assertFalse( devbase_remote_login_settings()['on'], 'An editor saves nothing.' );

		wp_set_current_user( $admin );
		$post( wp_create_nonce( 'devbase_remote_login' ) );
		$this->assertTrue( devbase_remote_login_settings()['on'] );
		$this->assertTrue( devbase_remote_login_settings()['email'] );
	}

}
