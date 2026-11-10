<?php
// Covers inc/updates/rollback.php: the home page is checked before and after an update of our themes, and an
// update that broke it is undone from WordPress's own temporary backup. The loopback is faked: by the
// `devbase/updates/health_check` filter for the decision, and by pre_http_request for the scrape itself.

class Test_Update_Rollback_Upgrader {
	/** @var array[] what restore_temp_backup() was asked */
	public $restored = array();

	/** @var true|WP_Error */
	public $answer = true;

	/** @var object|null the upgrader skin, when a test gives one */
	public $skin = null;

	public function restore_temp_backup( array $temp_backups = array() ) {
		$this->restored[] = $temp_backups;

		return $this->answer;
	}
}

class Test_Update_Rollback extends WP_UnitTestCase {

	/** @var array[] the health results the next checks return, in order */
	private $checks = array();

	public function set_up() {
		parent::set_up();

		$this->checks = array();
		add_filter( 'devbase/updates/health_check', array( $this, 'next_check' ) );
		add_filter( 'devbase/updates/health_check_delay', '__return_zero' );
	}

	public function tear_down() {
		remove_filter( 'devbase/updates/health_check', array( $this, 'next_check' ) );
		delete_option( DEVBASE_UPDATE_ROLLBACK_OPTION );

		parent::tear_down();
	}

	public function next_check() {
		return array_shift( $this->checks ) ?? array( 'result' => 'ok' );
	}

	private function update_with( array $before, array $after, $upgrader = null ) {
		$this->checks = array( $before, $after );
		$upgrader     = $upgrader ?? new Test_Update_Rollback_Upgrader();

		$hook_extra = array( 'theme' => get_template(), 'type' => 'theme', 'action' => 'update' );

		$this->assertTrue( apply_filters( 'upgrader_pre_install', true, $hook_extra ), 'The baseline never stops an update.' );

		return array( devbase_update_after( $upgrader, get_template() ), $upgrader );
	}

	private function version() {
		return (string) wp_get_theme( get_template() )->get( 'Version' );
	}

	// --- the decision ------------------------------------------------------------------------------

	public function test_a_healthy_update_is_left_alone() {
		list( $result, $upgrader ) = $this->update_with( array( 'result' => 'ok' ), array( 'result' => 'ok' ) );

		$this->assertSame( 'ok', $result );
		$this->assertSame( array(), $upgrader->restored );
		$this->assertFalse( devbase_update_version_rolled_back( get_template(), $this->version() ) );
	}

	public function test_an_update_that_breaks_the_home_page_is_rolled_back_from_wordpress_backup() {
		list( $result, $upgrader ) = $this->update_with( array( 'result' => 'ok' ), array( 'result' => 'fatal', 'error' => 'Call to undefined function devbase_x()', 'file' => 'wp-content/themes/' . get_template() . '/functions.php' ) );

		$this->assertSame( 'rolled_back', $result );
		$this->assertSame( array( array( array( 'dir' => 'themes', 'slug' => get_template(), 'src' => get_theme_root( get_template() ) ) ) ), $upgrader->restored );
		$this->assertTrue( devbase_update_version_rolled_back( get_template(), $this->version() ), 'That version is never offered again.' );

		$entry = devbase_update_recent_rollbacks()[0];
		$this->assertSame( 'rolled_back', $entry['result'] );
		$this->assertStringContainsString( 'undefined function', $entry['error'] );
	}

	public function test_a_site_already_broken_by_something_else_is_not_rolled_back() {
		list( $result, $upgrader ) = $this->update_with(
			array( 'result' => 'fatal' ),
			array( 'result' => 'fatal', 'error' => 'boom', 'file' => 'wp-content/plugins/other/other.php' )
		);

		$this->assertSame( 'skipped', $result );
		$this->assertSame( array(), $upgrader->restored );
	}

	public function test_a_site_already_broken_is_still_rolled_back_when_the_error_is_in_our_theme() {
		list( $result ) = $this->update_with(
			array( 'result' => 'fatal' ),
			array( 'result' => 'fatal', 'error' => 'boom', 'file' => 'wp-content/themes/' . get_template() . '/inc/x.php' )
		);

		$this->assertSame( 'rolled_back', $result );
	}

	public function test_a_check_that_could_not_be_made_never_rolls_back() {
		list( $result, $upgrader ) = $this->update_with( array( 'result' => 'ok' ), array( 'result' => 'unknown', 'error' => 'cURL error 7' ) );

		$this->assertSame( 'unknown', $result );
		$this->assertSame( array(), $upgrader->restored, 'A host that blocks loopback requests would otherwise undo every update.' );
	}

	public function test_a_restore_that_fails_is_recorded_and_the_version_still_blocked() {
		$upgrader         = new Test_Update_Rollback_Upgrader();
		$upgrader->answer = new WP_Error( 'fs_temp_backup_delete', 'Could not restore.' );

		list( $result ) = $this->update_with( array( 'result' => 'ok' ), array( 'result' => 'fatal', 'error' => 'boom', 'file' => '' ), $upgrader );

		$this->assertSame( 'rollback_failed', $result );
		$this->assertTrue( devbase_update_version_rolled_back( get_template(), $this->version() ) );
	}

	public function test_an_update_this_code_did_not_prepare_is_skipped() {
		$upgrader = new Test_Update_Rollback_Upgrader();
		$this->checks = array( array( 'result' => 'fatal' ) );

		$this->assertSame( 'skipped', devbase_update_after( $upgrader, get_template() ) );
		$this->assertSame( array(), $upgrader->restored );
	}

	public function test_another_theme_is_none_of_our_business() {
		$this->assertSame( array(), devbase_update_rollback_themes( array( 'theme' => 'twentytwentyfour', 'type' => 'theme' ) ) );
		$this->assertSame( array(), devbase_update_rollback_themes( array( 'plugin' => 'x/x.php', 'type' => 'plugin' ) ) );
		$this->assertSame( array( get_template() ), devbase_update_rollback_themes( array( 'themes' => array( 'twentytwentyfour', get_template() ), 'type' => 'theme', 'bulk' => true ) ) );
	}

	public function test_it_runs_from_upgrader_process_complete() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; // core's own listeners on this hook need it
		$this->checks = array( array( 'result' => 'ok' ), array( 'result' => 'fatal', 'error' => 'boom', 'file' => '' ) );
		$upgrader     = new Test_Update_Rollback_Upgrader();

		apply_filters( 'upgrader_pre_install', true, array( 'theme' => get_template(), 'type' => 'theme', 'action' => 'update' ) );
		do_action( 'upgrader_process_complete', $upgrader, array( 'action' => 'update', 'type' => 'theme', 'bulk' => true, 'themes' => array( get_template() ) ) );

		$this->assertCount( 1, $upgrader->restored );
	}

	public function test_switched_off_it_does_nothing() {
		add_filter( 'devbase/updates/rollback_enabled', '__return_false' );
		$upgrader = new Test_Update_Rollback_Upgrader();
		$this->checks = array( array( 'result' => 'ok' ), array( 'result' => 'fatal' ) );

		apply_filters( 'upgrader_pre_install', true, array( 'theme' => get_template(), 'type' => 'theme', 'action' => 'update' ) );
		$this->assertSame( 'skipped', devbase_update_after( $upgrader, get_template() ) );
	}

	// --- how a rollback is told --------------------------------------------------------------------

	public function test_administrators_are_told_and_the_health_report_carries_it() {
		$this->update_with( array( 'result' => 'ok' ), array( 'result' => 'fatal', 'error' => 'boom in /home/client/site/wp-content/themes/x.php', 'file' => '' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		do_action( 'admin_notices' );
		$notices = ob_get_clean();
		$this->assertStringContainsString( 'notice-warning', $notices );
		$this->assertStringContainsString( $this->version(), $notices );

		$report = devbase_heartbeat_report();
		$this->assertSame( 'rolled_back', $report['theme_rollbacks'][0]['result'] );
		$this->assertStringNotContainsString( '/home/client', $report['theme_rollbacks'][0]['error'], 'The server path is scrubbed.' );
	}

	// --- reading the scrape ------------------------------------------------------------------------

	private function scrape( $key, $json ) {
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => "<html>page</html>\n###### wp_scraping_result_start:$key ######\n$json\n###### wp_scraping_result_end:$key ######\n" );
	}

	public function test_a_fatal_error_in_the_scrape_is_fatal() {
		$read = devbase_update_health_read( $this->scrape( 'k', '{"type":1,"message":"Uncaught Error","file":"wp-content/themes/t/functions.php","line":3}' ), 'k' );

		$this->assertSame( 'fatal', $read['result'] );
		$this->assertSame( 'wp-content/themes/t/functions.php', $read['file'] );
	}

	public function test_a_clean_scrape_is_ok() {
		$this->assertSame( 'ok', devbase_update_health_read( $this->scrape( 'k', 'true' ), 'k' )['result'] );
	}

	public function test_a_page_without_the_markers_or_a_failed_request_is_unknown() {
		$this->assertSame( 'unknown', devbase_update_health_read( array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '<html>cached</html>' ), 'k' )['result'] );
		$this->assertSame( 'unknown', devbase_update_health_read( new WP_Error( 'http_request_failed', 'refused' ), 'k' )['result'] );
		$this->assertSame( 'unknown', devbase_update_health_read( $this->scrape( 'k', '{"code":"scrape_nonce_failure"}' ), 'k' )['result'] );
	}

	public function test_the_check_loads_the_home_page_and_wp_admin_with_a_scrape_key_wordpress_accepts() {
		remove_filter( 'devbase/updates/health_check', array( $this, 'next_check' ) );
		$seen = array();

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$seen ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$seen[] = array( 'url' => $url, 'stored' => get_transient( 'scrape_key_' . $q['wp_scrape_key'] ), 'nonce' => $q['wp_scrape_nonce'] );

			return $this->scrape( $q['wp_scrape_key'], 'true' );
		}, 10, 3 );

		$this->assertSame( 'ok', devbase_update_health_check()['result'] );
		$this->assertCount( 2, $seen );
		$this->assertStringStartsWith( home_url( '/' ) . '?', $seen[0]['url'] );
		$this->assertStringStartsWith( admin_url( 'admin-ajax.php' ), $seen[1]['url'] );

		foreach ( $seen as $load ) {
			$this->assertSame( $load['nonce'], $load['stored'], 'wp_start_scraping_edited_file_errors() compares the nonce with this transient.' );
		}
	}

	/** Answers the check's loads by page: $pages maps 'home' / 'admin' to the scrape JSON, or to null for no markers. */
	private function pages( array $pages ) {
		remove_filter( 'devbase/updates/health_check', array( $this, 'next_check' ) );

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $pages ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$json = $pages[ false !== strpos( $url, 'admin-ajax.php' ) ? 'admin' : 'home' ];

			return null === $json
				? array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '<html>cached</html>' )
				: $this->scrape( $q['wp_scrape_key'], $json );
		}, 10, 3 );
	}

	public function test_a_release_that_breaks_only_wp_admin_is_fatal() {
		// Seen on a throwaway site: the home page was fine, /wp-admin/ answered HTTP 500 to the administrator.
		$this->pages( array( 'home' => 'true', 'admin' => '{"type":1,"message":"Uncaught Error: Call to undefined function x()","file":"wp-content/themes/t/functions.php"}' ) );

		$check = devbase_update_health_check();

		$this->assertSame( 'fatal', $check['result'] );
		$this->assertStringContainsString( 'admin-ajax.php', $check['url'] );
	}

	public function test_a_fatal_page_outweighs_a_page_that_could_not_be_checked() {
		$this->pages( array( 'home' => null, 'admin' => '{"type":1,"message":"Uncaught Error","file":""}' ) );
		$this->assertSame( 'fatal', devbase_update_health_check()['result'] );
	}

	public function test_one_page_that_could_not_be_checked_is_unknown_never_ok() {
		$this->pages( array( 'home' => 'true', 'admin' => null ) );
		$this->assertSame( 'unknown', devbase_update_health_check()['result'] );
	}

	public function test_the_update_screen_says_the_version_was_put_back() {
		$upgrader       = new Test_Update_Rollback_Upgrader();
		$upgrader->skin = new class() {
			public $said = array();

			public function feedback( $text ) {
				$this->said[] = $text;
			}
		};

		$this->update_with( array( 'result' => 'ok' ), array( 'result' => 'fatal', 'error' => 'boom', 'file' => '' ), $upgrader );

		$this->assertCount( 1, $upgrader->skin->said );
		$this->assertStringContainsString( $this->version(), $upgrader->skin->said[0] );
	}

	public function test_an_error_file_is_matched_to_the_theme_folder_only() {
		$this->assertTrue( devbase_update_error_in_theme( 'wp-content/themes/' . get_template() . '/functions.php', get_template() ) );
		$this->assertFalse( devbase_update_error_in_theme( 'wp-content/themes/' . get_template() . '-other/functions.php', get_template() ) );
		$this->assertFalse( devbase_update_error_in_theme( '', get_template() ) );
	}
}
