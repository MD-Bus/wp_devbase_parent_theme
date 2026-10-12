<?php
// Covers inc/dependencies/dependency-updater.php after the approval gate
// was removed. The old devbase_pick_target_version() /
// devbase_get_approved_versions() pair (and the whole
// devbase-dependency-worker allow-list they read) no longer exist —
// SCF/ASE now track WordPress.org's current stable release directly, so
// what needs covering is "does it read the right version, and does it only
// ever move forward."
//
// Every network call is faked with WordPress's own pre_http_request filter
// rather than a hand-rolled mock — the same approach Test_Form_Dispatch.php
// uses for webhooks and smtp/test.php uses for wp_mail_failed.
//
// Nothing here is ever allowed to complete a real download: the test that
// asserts an update IS attempted returns a WP_Error for the download URL,
// so devbase_apply_dependency_update() bails at its own is_wp_error()
// check and never touches the real vendor/ folder.

class Test_Dependency_Updater extends WP_UnitTestCase {

	/** @var array Every outbound URL seen during a test. */
	private $requests = array();

	/** @var callable|null */
	private $filter = null;

	public function tear_down() {
		if ( null !== $this->filter ) {
			remove_filter( 'pre_http_request', $this->filter, 10 );
			$this->filter = null;
		}

		$this->requests = array();

		parent::tear_down();
	}

	/**
	 * Intercepts every HTTP request. $responses maps a substring of the URL
	 * to whatever pre_http_request should short-circuit with.
	 */
	private function fake_http( array $responses ) {
		$this->filter = function ( $preempt, $args, $url ) use ( $responses ) {
			$this->requests[] = $url;

			foreach ( $responses as $needle => $response ) {
				if ( false !== strpos( $url, $needle ) ) {
					return $response;
				}
			}

			// Anything unexpected fails loudly rather than hitting the network.
			return new WP_Error( 'unexpected_request', "Unexpected request to {$url}" );
		};

		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	private function wporg_response( $version ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'version' => $version ) ),
		);
	}

	public function test_managed_dependencies_maps_each_slug_to_its_main_file() {
		$managed = devbase_managed_dependencies();

		$this->assertSame( 'secure-custom-fields.php', $managed['secure-custom-fields'] );
		$this->assertSame( 'admin-site-enhancements.php', $managed['admin-site-enhancements'] );
		$this->assertCount( 2, $managed, 'MCP Adapter is deliberately NOT managed here — it has its own ungated updater.' );
	}

	public function test_latest_version_is_read_from_the_wporg_api() {
		$this->fake_http( array( 'api.wordpress.org' => $this->wporg_response( '7.1.2' ) ) );

		$this->assertSame( '7.1.2', devbase_get_latest_wporg_version( 'secure-custom-fields' ) );
	}

	public function test_latest_version_is_null_when_wporg_is_unreachable() {
		$this->fake_http( array( 'api.wordpress.org' => new WP_Error( 'http_request_failed', 'down' ) ) );

		$this->assertNull(
			devbase_get_latest_wporg_version( 'secure-custom-fields' ),
			'A WordPress.org outage must not resolve to a version — that would trigger a bogus swap.'
		);
	}

	public function test_latest_version_is_null_on_a_malformed_response() {
		$this->fake_http( array(
			'api.wordpress.org' => array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not json',
			),
		) );

		$this->assertNull( devbase_get_latest_wporg_version( 'secure-custom-fields' ) );
	}

	public function test_latest_version_is_null_on_a_non_200_response() {
		$this->fake_http( array(
			'api.wordpress.org' => array(
				'response' => array( 'code' => 503 ),
				'body'     => '',
			),
		) );

		$this->assertNull( devbase_get_latest_wporg_version( 'secure-custom-fields' ) );
	}

	public function test_installed_version_is_read_from_the_plugin_file_header() {
		// Reads the real vendored copy's own header — deliberately not
		// Composer's installed.json, which goes stale the moment this
		// updater swaps a folder. See inc/dependencies/AGENT.md.
		$this->assertNotNull(
			devbase_get_installed_version( 'secure-custom-fields', 'secure-custom-fields.php' ),
			'The vendored SCF copy should report a version from its file header.'
		);
	}

	public function test_installed_version_is_null_for_a_dependency_that_is_not_there() {
		$this->assertNull( devbase_get_installed_version( 'not-a-real-plugin', 'not-a-real-plugin.php' ) );
	}

	public function test_no_download_is_attempted_when_the_installed_version_is_already_current() {
		$installed = devbase_get_installed_version( 'secure-custom-fields', 'secure-custom-fields.php' );

		// Report the installed version back as "latest" for everything.
		$this->fake_http( array( 'api.wordpress.org' => $this->wporg_response( $installed ) ) );

		devbase_run_dependency_check();

		$downloads = array_filter(
			$this->requests,
			function ( $url ) {
				return false !== strpos( $url, 'downloads.wordpress.org' );
			}
		);

		$this->assertEmpty( $downloads, 'Nothing should be downloaded when the installed version is already current.' );
	}

	public function test_no_download_is_attempted_when_wporg_reports_an_older_version() {
		// A downgrade must never happen — version_compare( latest, installed, '>' ).
		$this->fake_http( array( 'api.wordpress.org' => $this->wporg_response( '0.0.1' ) ) );

		devbase_run_dependency_check();

		$downloads = array_filter(
			$this->requests,
			function ( $url ) {
				return false !== strpos( $url, 'downloads.wordpress.org' );
			}
		);

		$this->assertEmpty( $downloads, 'An older published version must never trigger a downgrade swap.' );
	}

	public function test_a_newer_version_is_downloaded_from_the_versioned_wporg_url() {
		$this->fake_http( array(
			'api.wordpress.org'       => $this->wporg_response( '999.0.0' ),
			// Fail the download itself, so the real vendor/ folder is never touched.
			'downloads.wordpress.org' => new WP_Error( 'http_request_failed', 'blocked by test' ),
		) );

		devbase_run_dependency_check();

		$downloads = array_values( array_filter(
			$this->requests,
			function ( $url ) {
				return false !== strpos( $url, 'downloads.wordpress.org' );
			}
		) );

		$this->assertNotEmpty( $downloads, 'A newer published version should trigger a download.' );
		$this->assertContains(
			'https://downloads.wordpress.org/plugin/secure-custom-fields.999.0.0.zip',
			$downloads,
			'The download URL must be the versioned WordPress.org URL for the resolved version.'
		);
	}

	// --- Scheduling: must not depend on anyone visiting wp-admin ----------

	public function test_both_update_checks_are_scheduled_as_recurring_daily_events() {
		// These used to hook admin_init, which only fires in wp-admin — so on
		// a client site nobody logs into, dependency updates simply stopped.
		// A recurring event fires from any request, because core hooks
		// wp_cron() to 'init'.
		foreach ( array( DEVBASE_DEPENDENCY_CHECK_HOOK, DEVBASE_MCP_UPDATE_CHECK_HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
			$this->assertFalse( wp_next_scheduled( $hook ) );

			do_action( 'init' );

			$this->assertNotFalse(
				wp_next_scheduled( $hook ),
				"{$hook} must be scheduled from 'init', which fires on front-end requests too."
			);
			$this->assertSame(
				'daily',
				wp_get_schedule( $hook ),
				"{$hook} must be a recurring daily event, not a one-off."
			);
		}
	}

	public function test_scheduling_is_idempotent() {
		wp_clear_scheduled_hook( DEVBASE_DEPENDENCY_CHECK_HOOK );

		do_action( 'init' );
		$first = wp_next_scheduled( DEVBASE_DEPENDENCY_CHECK_HOOK );

		do_action( 'init' );
		$second = wp_next_scheduled( DEVBASE_DEPENDENCY_CHECK_HOOK );

		$this->assertSame( $first, $second, "Repeated 'init' firings must not pile up duplicate events." );
	}

	public function test_switching_theme_unschedules_both_checks() {
		do_action( 'init' );
		$this->assertNotFalse( wp_next_scheduled( DEVBASE_DEPENDENCY_CHECK_HOOK ) );

		do_action( 'switch_theme' );

		$this->assertFalse(
			wp_next_scheduled( DEVBASE_DEPENDENCY_CHECK_HOOK ),
			'Leaving a cron event behind after deactivation breaks this theme\'s "no persistent state" rule.'
		);
		$this->assertFalse( wp_next_scheduled( DEVBASE_MCP_UPDATE_CHECK_HOOK ) );
	}

	// --- The last-run record, which is the only signal this is still alive -
	//
	// Assertions compare against __()-resolved strings, never English
	// literals — this dev site's real locale is fr_CA and these strings are
	// translated. See languages/AGENT.md for the bug that rule exists for.

	public function test_a_run_that_changed_nothing_is_still_recorded() {
		delete_option( 'devbase_dependency_last_check' );

		$installed = devbase_get_installed_version( 'secure-custom-fields', 'secure-custom-fields.php' );
		$this->fake_http( array( 'api.wordpress.org' => $this->wporg_response( $installed ) ) );

		devbase_run_dependency_check();

		$record = get_option( 'devbase_dependency_last_check' );

		$this->assertNotEmpty( $record['time'], '"Ran and found nothing to do" must be distinguishable from "never ran".' );
		$this->assertSame( array(), $record['updated'] );
	}

	public function test_an_unreachable_source_is_recorded_as_an_error() {
		delete_option( 'devbase_dependency_last_check' );

		$this->fake_http( array( 'api.wordpress.org' => new WP_Error( 'http_request_failed', 'down' ) ) );

		devbase_run_dependency_check();

		$record = get_option( 'devbase_dependency_last_check' );

		$this->assertNotEmpty( $record['errors'] );
		$this->assertCount( 2, $record['errors'], 'Both managed dependencies should report a failure.' );
	}

	public function test_the_status_message_distinguishes_never_run_from_up_to_date() {
		delete_option( 'devbase_dependency_last_check' );

		$this->assertSame(
			__( 'Never run yet.', 'devbase_parent_theme' ),
			devbase_dependency_last_check_message()
		);

		update_option( 'devbase_dependency_last_check', array( 'time' => time(), 'updated' => array(), 'errors' => array() ) );

		$this->assertNotSame(
			__( 'Never run yet.', 'devbase_parent_theme' ),
			devbase_dependency_last_check_message()
		);
	}

	public function test_the_status_message_names_what_was_updated() {
		update_option( 'devbase_dependency_last_check', array(
			'time'    => time(),
			'updated' => array( 'admin-site-enhancements' => '9.2.0' ),
			'errors'  => array(),
		) );

		$message = devbase_dependency_last_check_message();

		// The slug and version are raw data, not translated text, so
		// asserting on them directly is locale-safe.
		$this->assertStringContainsString( 'admin-site-enhancements', $message );
		$this->assertStringContainsString( '9.2.0', $message );
	}

	public function test_a_stale_run_is_flagged() {
		update_option( 'devbase_dependency_last_check', array(
			'time'    => time() - ( 72 * HOUR_IN_SECONDS ),
			'updated' => array(),
			'errors'  => array(),
		) );
		$stale = devbase_dependency_last_check_message();

		update_option( 'devbase_dependency_last_check', array(
			'time'    => time(),
			'updated' => array(),
			'errors'  => array(),
		) );
		$fresh = devbase_dependency_last_check_message();

		$this->assertNotSame( $stale, $fresh );
		$this->assertStringContainsString(
			'⚠',
			$stale,
			'A check that has not run in days is the one thing this message exists to surface.'
		);
		$this->assertStringNotContainsString( '⚠', $fresh );
	}

	public function test_the_mcp_status_message_behaves_the_same_way() {
		delete_option( 'devbase_mcp_last_check' );

		$this->assertSame(
			__( 'Never run yet.', 'devbase_parent_theme' ),
			devbase_mcp_last_check_message()
		);

		update_option( 'devbase_mcp_last_check', array( 'time' => time(), 'updated' => '0.7.0', 'error' => '' ) );
		$this->assertStringContainsString( '0.7.0', devbase_mcp_last_check_message() );
	}

	// --- MCP Adapter: forward-only, same as SCF/ASE -----------------------

	public function test_mcp_adapter_is_not_downgraded_when_github_reports_an_older_release() {
		// This used to be a string equality check, which would have
		// dutifully "updated" to an older release if a tag were yanked or
		// retagged. SCF/ASE already refused that; this is the same rule.
		$this->fake_http( array(
			'api.github.com' => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'tag_name' => '0.0.1' ) ),
			),
		) );

		devbase_run_mcp_update_check();

		$downloads = array_filter(
			$this->requests,
			function ( $url ) {
				return false !== strpos( $url, 'releases/latest/download' );
			}
		);

		$this->assertEmpty( $downloads, 'An older GitHub release must never trigger a downgrade swap.' );
	}

	public function test_mcp_adapter_update_is_attempted_for_a_newer_release() {
		$this->fake_http( array(
			'api.github.com'           => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'tag_name' => 'v999.0.0' ) ),
			),
			// Fail the download so the real vendor/ folder is never touched.
			'releases/latest/download' => new WP_Error( 'http_request_failed', 'blocked by test' ),
		) );

		devbase_run_mcp_update_check();

		$downloads = array_filter(
			$this->requests,
			function ( $url ) {
				return false !== strpos( $url, 'releases/latest/download' );
			}
		);

		$this->assertNotEmpty( $downloads, 'A newer GitHub release should trigger a download.' );
	}
}
