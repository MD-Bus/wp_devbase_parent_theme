<?php
// Covers inc/legacy-mcp-oauth-cleanup.php: the one-time removal of the data the
// withdrawn MCP sign-in left behind, and the two guards that stop it from ever
// touching data a returning sign-in would own.

class Test_Legacy_MCP_OAuth_Cleanup extends WP_UnitTestCase {

	private function seed() {
		$user_id = self::factory()->user->create();
		update_option( 'devbase_mcp_oauth_clients', array( 'c1' => array( 'client_name' => 'x' ) ) );
		update_user_meta( $user_id, 'devbase_mcp_oauth_grants', array( 'g1' => array( 'refresh' => 'x' ) ) );
		update_option( 'options_mcp_oauth_enabled', '1' );
		update_option( '_options_mcp_oauth_enabled', 'field_devbase_mcp_oauth_enabled' );
		delete_option( 'devbase_legacy_mcp_oauth_cleaned' );
		return $user_id;
	}

	public function test_removes_the_legacy_data_and_marks_itself_done() {
		$user_id = $this->seed();

		$this->assertTrue( devbase_legacy_mcp_oauth_cleanup() );

		$this->assertFalse( get_option( 'devbase_mcp_oauth_clients' ) );
		$this->assertSame( '', get_user_meta( $user_id, 'devbase_mcp_oauth_grants', true ) );
		$this->assertFalse( get_option( 'options_mcp_oauth_enabled' ) );
		$this->assertFalse( get_option( '_options_mcp_oauth_enabled' ) );
		$this->assertNotEmpty( get_option( 'devbase_legacy_mcp_oauth_cleaned' ) );
	}

	public function test_runs_only_once_so_data_made_later_survives() {
		$this->seed();
		devbase_legacy_mcp_oauth_cleanup();

		update_option( 'devbase_mcp_oauth_clients', array( 'later' => array() ) );

		$this->assertFalse( devbase_legacy_mcp_oauth_cleanup() );
		$this->assertSame( array( 'later' => array() ), get_option( 'devbase_mcp_oauth_clients' ), 'A second run must not delete data created after the clean-up ran.' );
	}

	public function test_does_nothing_while_the_sign_in_code_is_loaded() {
		$user_id = $this->seed();
		add_filter( 'devbase/legacy_mcp_oauth_cleanup/code_loaded', '__return_true' );

		$this->assertFalse( devbase_legacy_mcp_oauth_cleanup() );
		remove_filter( 'devbase/legacy_mcp_oauth_cleanup/code_loaded', '__return_true' );

		$this->assertNotFalse( get_option( 'devbase_mcp_oauth_clients' ) );
		$this->assertNotEmpty( get_user_meta( $user_id, 'devbase_mcp_oauth_grants', true ) );
		$this->assertFalse( get_option( 'devbase_legacy_mcp_oauth_cleaned' ), 'A skipped run must not mark itself done.' );
	}
}
