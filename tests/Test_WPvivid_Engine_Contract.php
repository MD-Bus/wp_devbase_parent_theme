<?php
// The backup engine (inc/backups/backups.php) calls WPvivid Backup's INTERNALS: classes, methods, options and
// one nonce action that are nobody's promised API. Every other backup test replaces the engine with filters,
// so a WPvivid release that renamed any of them would pass the whole suite and break every site's backups.
//
// This reads the bundled zip itself (as Test_WPvivid_Lockdown does for the ajax allowlist) and fails when a
// name the engine depends on is gone. It runs on the Dependabot pull request that bumps WPvivid, which is
// where a break should be found. A failure here means: read what changed in WPvivid, adapt
// devbase_backup_engine_prepare() / _run() / _status() / _files() / devbase_backup_delete_local(), then
// update this list.

class Test_WPvivid_Engine_Contract extends WP_UnitTestCase {

	/** @var array<string, string> path inside the zip => source */
	private static $files = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();

		$zip = new ZipArchive();

		if ( true !== $zip->open( get_template_directory() . '/bundled-plugins/wpvivid-backuprestore.zip' ) ) {
			return;
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( '.php' === substr( $name, -4 ) && false === strpos( $name, '/vendor/' ) ) {
				self::$files[ $name ] = (string) $zip->getFromIndex( $i );
			}
		}

		$zip->close();
	}

	/** The source of the one file that declares a class, or '' when none does. */
	private function file_declaring( $class ) {
		foreach ( self::$files as $source ) {
			if ( preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+' . preg_quote( $class, '/' ) . '\b/m', $source ) ) {
				return $source;
			}
		}

		return '';
	}

	private function all_source() {
		return implode( "\n", self::$files );
	}

	private function assert_method( $class, $method, $why ) {
		$source = $this->file_declaring( $class );

		$this->assertNotSame( '', $source, "WPvivid no longer has the class {$class}. {$why}" );
		$this->assertMatchesRegularExpression(
			'/public\s+(?:static\s+)?function\s+' . preg_quote( $method, '/' ) . '\s*\(/',
			$source,
			"{$class}::{$method}() is gone or no longer public. {$why}"
		);
	}

	public function test_the_bundled_zip_is_readable() {
		$this->assertNotEmpty( self::$files, 'bundled-plugins/wpvivid-backuprestore.zip could not be read.' );
	}

	public function test_the_task_class_the_engine_prepares_a_backup_with_is_there() {
		$this->assert_method( 'WPvivid_Backup_Task_2', 'new_backup_task', 'devbase_backup_engine_prepare() creates the backup task with it.' );

		// It is called as new_backup_task( $options, $settings ): two parameters, in that order.
		$this->assertMatchesRegularExpression(
			'/function\s+new_backup_task\s*\(\s*\$options\s*,\s*\$settings\s*\)/',
			$this->file_declaring( 'WPvivid_Backup_Task_2' ),
			'new_backup_task() no longer takes ( $options, $settings ).'
		);
	}

	public function test_the_newer_engine_object_and_its_three_methods_are_there() {
		$why = 'devbase_backup_engine_prepare() and devbase_backup_engine_run() call it on $wpvivid_plugin->backup2.';

		$this->assert_method( 'WPvivid_Backup_2', 'backup_now_2', $why );
		$this->assert_method( 'WPvivid_Backup_2', 'get_backup_settings', $why );
		$this->assert_method( 'WPvivid_Backup_2', 'is_tasks_backup_running', $why );

		$this->assertMatchesRegularExpression(
			'/\$this->backup2\s*=\s*new\s+WPvivid_Backup_2\s*\(/',
			$this->all_source(),
			'The plugin object no longer keeps the newer engine as ->backup2.'
		);
		$this->assertMatchesRegularExpression( '/\$wpvivid_plugin\s*=/', $this->all_source(), 'The global $wpvivid_plugin is no longer set.' );
	}

	public function test_starting_a_backup_still_checks_the_nonce_and_capability_the_engine_provides() {
		// devbase_backup_engine_run() makes the request an administrator and sets $_POST['nonce'] to a
		// 'wpvivid_ajax' nonce and $_POST['task_id'], because that is what backup_now_2() checks and reads.
		$source = $this->file_declaring( 'WPvivid_Backup_2' );
		$this->assertSame( 1, preg_match( '/function\s+backup_now_2\s*\(\s*\)\s*\{(.{0,4000})/s', $source, $m ), 'backup_now_2() now takes arguments.' );

		$this->assertMatchesRegularExpression( '/check_ajax_referer\(\s*[\'"]wpvivid_ajax[\'"]\s*,\s*[\'"]nonce[\'"]\s*\)/', $m[1], 'backup_now_2() checks a different nonce.' );
		$this->assertStringContainsString( "current_user_can('manage_options')", str_replace( array( ' ', '"' ), array( '', "'" ), $m[1] ), 'backup_now_2() checks a different capability.' );
		$this->assertStringContainsString( 'task_id', $m[1], 'backup_now_2() no longer reads the task id from the request.' );
	}

	public function test_the_settings_the_engine_overrides_are_still_settings_wpvivid_reads() {
		$source = $this->file_declaring( 'WPvivid_Backup_2' ) . $this->file_declaring( 'WPvivid_Backup_Task_2' );

		foreach ( array( 'is_merge', 'save_local', 'backup_symlink_folder', 'max_file_size' ) as $setting ) {
			$this->assertStringContainsString( "'{$setting}'", $source, "The newer engine no longer reads the setting {$setting}, which devbase_backup_engine_prepare() sets." );
		}
	}

	public function test_the_delete_the_engine_removes_its_local_copy_with_is_there() {
		$this->assert_method( 'WPvivid_Public_Interface', 'delete_backup', 'devbase_backup_delete_local() removes our local copy through it.' );
		$this->assertMatchesRegularExpression(
			'/function\s+delete_backup\s*\(\s*\$backup_id\s*,\s*\$force_del\s*\)/',
			$this->file_declaring( 'WPvivid_Public_Interface' ),
			'delete_backup() no longer takes ( $backup_id, $force_del ).'
		);
	}

	public function test_the_rescan_a_download_ends_with_is_still_listened_for() {
		$this->assertMatchesRegularExpression(
			'/add_action\(\s*[\'"]wpvivid_rebuild_backup_list[\'"]/',
			$this->all_source(),
			'Nothing in WPvivid listens for wpvivid_rebuild_backup_list any more: a downloaded backup would not appear in its list.'
		);
	}

	public function test_the_options_and_states_read_without_loading_wpvivid_are_still_its_own() {
		$source = $this->all_source();

		foreach ( array( 'wpvivid_task_list', 'wpvivid_backup_list', 'wpvivid_local_setting' ) as $option ) {
			$this->assertStringContainsString( $option, $source, "WPvivid no longer uses the option {$option}, which the engine and the busy check read." );
		}

		// devbase_backup_engine_status() and devbase_wpvivid_is_busy() go by these task states.
		foreach ( array( 'completed', 'running', 'no_responds', 'wait_resume' ) as $state ) {
			$this->assertMatchesRegularExpression( '/[\'"]' . $state . '[\'"]/', $source, "WPvivid no longer uses the task state {$state}." );
		}

		$this->assertStringContainsString( 'wpvivid_restoredata', $source, 'The restore progress file the busy check looks for has another name.' );
	}

	public function test_every_internal_the_theme_calls_is_covered_here() {
		// The other direction: if backups.php starts calling something new of WPvivid's, this list must grow.
		$engine = (string) file_get_contents( get_template_directory() . '/inc/backups/backups.php' );

		preg_match_all( '/->backup2->(\w+)\s*\(/', $engine, $methods );
		$this->assertEqualsCanonicalizing( array( 'is_tasks_backup_running', 'get_backup_settings', 'backup_now_2' ), array_values( array_unique( $methods[1] ) ) );

		preg_match_all( '/\b(WPvivid_\w+)\b/', $engine, $classes );
		$this->assertEqualsCanonicalizing( array( 'WPvivid_Backup_Task_2', 'WPvivid_Public_Interface' ), array_values( array_unique( $classes[1] ) ) );
	}
}
