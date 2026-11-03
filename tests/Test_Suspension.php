<?php
// Covers inc/suspension/: the payment kill switch. Off unless a child turns it
// on; then it follows the stored, signed Warden answer (only "suspended" locks),
// a failed or missing answer never changes anything, and a locked site answers
// the public REST API with 503 and loses its code editors.

class Test_Suspension extends WP_UnitTestCase {

	private $enabled = false;
	private $config  = array();

	public function set_up() {
		parent::set_up();

		$this->enabled = false;
		$this->config  = array();

		add_filter( 'devbase/suspension/enabled', array( $this, 'enabled_filter' ) );
		add_filter( 'devbase/suspension/config', array( $this, 'config_filter' ) );

		delete_option( $this->state_option() );
		delete_option( DEVBASE_LICENCE_LOCK );
		$this->refresh();
	}

	public function tear_down() {
		remove_filter( 'devbase/suspension/enabled', array( $this, 'enabled_filter' ) );
		remove_filter( 'devbase/suspension/config', array( $this, 'config_filter' ) );
		delete_option( $this->state_option() );
		delete_option( DEVBASE_LICENCE_LOCK );
		wp_set_current_user( 0 );
		$this->refresh();

		parent::tear_down();
	}

	public function enabled_filter() {
		return $this->enabled;
	}

	public function config_filter( $config ) {
		return array_merge( $config, $this->config );
	}

	private function state_option() {
		return 'devbase_warden_state_' . get_stylesheet();
	}

	private function refresh() {
		devbase_suspension_config( true );
		devbase_licence_verdict( true );
	}

	private function answer( $status, $extra = array() ) {
		update_option( $this->state_option(), array(
			'response'     => array_merge( array( 'status' => $status ), $extra ),
			'succeeded_at' => time(),
			'checked_at'   => time(),
		) );
		$this->refresh();
	}

	// --- off by default ---------------------------------------------------------

	public function test_it_does_nothing_unless_a_child_turns_it_on() {
		$this->answer( 'suspended' );
		$this->config = array( 'suspended' => true );
		$this->refresh();

		$this->assertFalse( devbase_suspension_enabled() );
		$this->assertFalse( devbase_is_suspended() );
		$this->assertFalse( devbase_licence_verdict()['locked'] );
		$this->assertFalse( devbase_licence_due() );
		$this->assertNull( devbase_run_licence_check() );
	}

	// --- the licence decides --------------------------------------------------------

	public function test_only_a_suspended_answer_locks_the_site() {
		$this->enabled = true;

		$this->answer( 'suspended' );
		$this->assertTrue( devbase_is_suspended() );

		foreach ( array( 'active', 'completed', 'expired', 'pending', 'unknown' ) as $status ) {
			$this->answer( $status );
			$this->assertFalse( devbase_is_suspended(), "'$status' must not lock the site." );
		}
	}

	public function test_a_site_is_never_locked_on_silence() {
		$this->enabled = true;

		// Never had a good answer.
		$this->assertFalse( devbase_is_suspended() );

		// A failed check records an error and no answer.
		update_option( $this->state_option(), array( 'checked_at' => time(), 'last_error' => 'HTTP 503' ) );
		$this->refresh();
		$this->assertFalse( devbase_is_suspended() );
	}

	public function test_a_failed_check_keeps_the_last_good_answer() {
		$this->enabled = true;
		update_option( $this->state_option(), array(
			'response'     => array( 'status' => 'suspended' ),
			'succeeded_at' => time() - DAY_IN_SECONDS,
			'checked_at'   => time(),
			'last_error'   => 'timeout',
		) );
		$this->refresh();

		$this->assertTrue( devbase_is_suspended(), 'Suspended stays suspended when Warden cannot be reached.' );
	}

	public function test_the_licence_can_be_ignored() {
		$this->enabled = true;
		$this->config  = array( 'licence_lock' => false );
		$this->answer( 'suspended' );

		$this->assertFalse( devbase_is_suspended() );
	}

	public function test_the_manual_override_suspends_regardless_of_the_licence() {
		$this->enabled = true;
		$this->config  = array( 'suspended' => true );
		$this->answer( 'active' );

		$this->assertTrue( devbase_is_suspended() );
	}

	public function test_a_child_can_change_the_lock_statuses_and_the_message() {
		$this->enabled = true;
		$this->config  = array( 'lock_statuses' => array( 'revoked' ), 'title' => 'Closed' );
		$this->answer( 'suspended' );
		$this->assertFalse( devbase_is_suspended() );

		$this->answer( 'revoked' );
		$this->assertTrue( devbase_is_suspended() );
		$this->assertSame( 'Closed', devbase_suspension_config()['title'] );
		$this->assertSame( 'Service unavailable', devbase_suspension_config()['status'], 'Unset keys keep their defaults.' );
	}

	// --- what a locked site does --------------------------------------------------------

	public function test_the_public_rest_api_answers_503_but_logged_in_users_get_through() {
		$this->enabled = true;
		$this->answer( 'suspended' );

		$blocked = apply_filters( 'rest_authentication_errors', null );
		$this->assertWPError( $blocked );
		$this->assertSame( 503, $blocked->get_error_data()['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertNotWPError( apply_filters( 'rest_authentication_errors', null ) );
	}

	public function test_the_rest_api_is_untouched_when_not_suspended() {
		$this->enabled = true;
		$this->answer( 'active' );

		$this->assertNotWPError( apply_filters( 'rest_authentication_errors', null ) );
	}

	public function test_code_editors_are_off_while_suspended() {
		$this->enabled = true;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->answer( 'suspended' );
		$this->assertFalse( user_can( $admin, 'edit_plugins' ) );
		$this->assertFalse( user_can( $admin, 'edit_themes' ) );

		$this->answer( 'active' );
		$this->assertNotSame( array( 'do_not_allow' ), map_meta_cap( 'edit_themes', $admin ) );
	}

	// --- when it checks ---------------------------------------------------------------------

	public function test_a_stale_answer_is_due_and_a_failed_one_retries_sooner() {
		$this->enabled = true;
		$this->config  = array( 'check_hours' => 6, 'retry_minutes' => 15 );

		update_option( $this->state_option(), array( 'checked_at' => time() - HOUR_IN_SECONDS ) );
		$this->assertFalse( devbase_licence_due(), 'Fresh: not due.' );

		update_option( $this->state_option(), array( 'checked_at' => time() - 7 * HOUR_IN_SECONDS ) );
		$this->assertTrue( devbase_licence_due() );

		update_option( $this->state_option(), array( 'checked_at' => time() - 20 * MINUTE_IN_SECONDS, 'last_error' => 'x' ) );
		$this->assertTrue( devbase_licence_due(), 'Failed 20 minutes ago: retry.' );

		update_option( $this->state_option(), array( 'checked_at' => time() - 5 * MINUTE_IN_SECONDS, 'last_error' => 'x' ) );
		$this->assertFalse( devbase_licence_due(), 'Failed 5 minutes ago: wait.' );
	}

	public function test_only_one_request_refreshes_at_a_time() {
		$this->assertTrue( devbase_licence_lock() );
		$this->assertFalse( devbase_licence_lock() );

		update_option( DEVBASE_LICENCE_LOCK, time() - 2 * MINUTE_IN_SECONDS );
		$this->assertTrue( devbase_licence_lock(), 'A lock older than a minute is stale.' );
	}

	public function test_the_check_is_scheduled_only_while_enabled() {
		wp_clear_scheduled_hook( DEVBASE_LICENCE_CRON );
		do_action( 'init' );
		$this->assertFalse( wp_next_scheduled( DEVBASE_LICENCE_CRON ) );

		$this->enabled = true;
		do_action( 'init' );
		$this->assertNotFalse( wp_next_scheduled( DEVBASE_LICENCE_CRON ) );

		wp_clear_scheduled_hook( DEVBASE_LICENCE_CRON );
	}
}
