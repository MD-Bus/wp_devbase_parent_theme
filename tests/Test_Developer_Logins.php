<?php
// Covers inc/helpers/developer.php: the one list of developer logins that hides the
// Credit tab and the Licence page's Remote setup tab from everyone else.

class Test_Developer_Logins extends WP_UnitTestCase {

	private $logins = array();

	public function set_up() {
		parent::set_up();
		$this->logins = array();
		add_filter( 'devbase/developer_logins', array( $this, 'logins' ) );
	}

	public function tear_down() {
		remove_filter( 'devbase/developer_logins', array( $this, 'logins' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function logins() {
		return $this->logins;
	}

	private function as_user( $login ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => $login ) ) );
	}

	public function test_with_no_list_nothing_is_hidden() {
		$this->as_user( 'a_client' );

		$this->assertSame( array(), devbase_developer_logins() );
		$this->assertFalse( devbase_hide_from_non_developers() );
		$this->assertFalse( devbase_credit_settings_hidden() === true && ! defined( 'DEVBASE_CREDIT_BRAND_URL' ) );
	}

	public function test_a_client_is_hidden_from_developer_settings_once_a_list_exists() {
		$this->logins = array( 'jane' );
		$this->as_user( 'a_client' );

		$this->assertTrue( devbase_hide_from_non_developers() );
		$this->assertTrue( devbase_credit_settings_hidden() );
		$this->assertTrue( devbase_remote_setup_hidden() );
	}

	public function test_a_developer_sees_everything() {
		$this->logins = array( 'jane', 'john' );
		$this->as_user( 'john' );

		$this->assertFalse( devbase_hide_from_non_developers() );
		if ( ! defined( 'DEVBASE_CREDIT_BRAND_URL' ) ) {
			$this->assertFalse( devbase_credit_settings_hidden() );
		}
	}

	public function test_junk_in_the_list_is_ignored() {
		$this->logins = array( 'jane', '', null, 42 );

		$this->assertSame( array( 'jane', '42' ), devbase_developer_logins() );
	}

	public function test_the_older_filters_still_win() {
		$this->logins = array( 'jane' );
		$this->as_user( 'a_client' );
		add_filter( 'devbase/licence/hide_remote_setup', '__return_false' );

		$this->assertFalse( devbase_remote_setup_hidden() );
		remove_filter( 'devbase/licence/hide_remote_setup', '__return_false' );
	}
}
