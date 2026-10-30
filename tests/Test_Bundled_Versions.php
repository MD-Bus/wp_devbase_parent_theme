<?php
// Covers inc/dependencies/bundled-versions.php: the bundled versions shown on
// the System Status tab, and the one-off cleanup of the daily dependency
// update checks that sites ran until 1.7.0 (dependency-updater.php and
// mcp-adapter-updater.php, removed so dependencies change only in a release).

class Test_Bundled_Versions extends WP_UnitTestCase {

	private function plugin_version( $file ) {
		return get_file_data( get_template_directory() . '/vendor/' . $file, array( 'Version' => 'Version' ) )['Version'];
	}

	public function test_bundled_versions_are_read_from_vendor() {
		$versions = devbase_bundled_versions();

		$this->assertSame( $this->plugin_version( 'secure-custom-fields/secure-custom-fields.php' ), $versions['Secure Custom Fields'] );
		$this->assertSame( $this->plugin_version( 'admin-site-enhancements/admin-site-enhancements.php' ), $versions['Admin Site Enhancements'] );
		$this->assertSame( $this->plugin_version( 'mcp-adapter/mcp-adapter.php' ), $versions['MCP Adapter'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', $versions['Timber'] );
	}

	public function test_bundled_versions_match_composer_lock() {
		$lock = json_decode( file_get_contents( get_template_directory() . '/composer.lock' ), true );
		$locked = array_column( $lock['packages'], 'version', 'name' );
		$versions = devbase_bundled_versions();

		$this->assertSame( $locked['wpackagist-plugin/secure-custom-fields'], $versions['Secure Custom Fields'] );
		$this->assertSame( $locked['wpackagist-plugin/admin-site-enhancements'], $versions['Admin Site Enhancements'] );
		$this->assertSame( ltrim( $locked['timber/timber'], 'v' ), $versions['Timber'] );
	}

	public function test_status_message_lists_every_bundled_dependency() {
		$message = devbase_bundled_versions_message();

		foreach ( devbase_bundled_versions() as $name => $version ) {
			$this->assertStringContainsString( "{$name} {$version}", $message );
		}
	}

	public function test_retired_update_checks_are_cleared_from_an_older_site() {
		// What a 1.6.x site has: both daily events scheduled, both last-run records stored.
		wp_schedule_event( time(), 'daily', 'devbase_check_dependency_updates' );
		wp_schedule_event( time(), 'daily', 'devbase_check_mcp_update' );
		update_option( 'devbase_dependency_last_check', array( 'time' => time() ) );
		update_option( 'devbase_mcp_last_check', array( 'time' => time() ) );

		do_action( 'init' );

		$this->assertFalse( wp_next_scheduled( 'devbase_check_dependency_updates' ) );
		$this->assertFalse( wp_next_scheduled( 'devbase_check_mcp_update' ) );
		$this->assertFalse( get_option( 'devbase_dependency_last_check' ) );
		$this->assertFalse( get_option( 'devbase_mcp_last_check' ) );
	}

	public function test_nothing_updates_dependencies_on_a_site_any_more() {
		$this->assertFalse( function_exists( 'devbase_run_dependency_check' ) );
		$this->assertFalse( function_exists( 'devbase_run_mcp_update_check' ) );
		$this->assertFalse( function_exists( 'devbase_apply_dependency_update' ) );
		$this->assertFalse( has_action( 'devbase_check_dependency_updates' ) );
		$this->assertFalse( has_action( 'devbase_check_mcp_update' ) );
	}
}
