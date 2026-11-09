<?php
// Covers locking WPvivid Backup down to what the theme's flow uses (inc/bundled-plugins/wpvivid.php): while the plan includes
// backups, every WPvivid admin-ajax action that is not on the allowlist is unhooked, and nothing is touched otherwise.
// That the real backup and restore still work with it is checked live (see inc/bundled-plugins/AGENT.md): the real plugin's
// hooks cannot be loaded inside the suite.

class WPvivid_Stub_Handlers {
	public function handle() {}
	public function handle_auth_actions() {}
}

class Test_WPvivid_Lockdown extends WP_UnitTestCase {

	private $hooks = array();

	public function set_up() {
		parent::set_up();
		devbase_relay_store_plan( array( 'licence' => array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => array( 'backup' ), 'limits' => array() ) ) );
	}

	public function tear_down() {
		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		remove_all_filters( 'devbase/wpvivid/lockdown' );
		remove_all_filters( 'devbase/wpvivid/allowed_ajax' );

		foreach ( $this->hooks as $hook ) {
			remove_all_actions( $hook );
		}

		parent::tear_down();
	}

	private function register( $action, $object = null ) {
		$this->hooks[] = $action;
		add_action( $action, array( $object ?? new WPvivid_Stub_Handlers(), 'handle' ) );
	}

	public function test_it_is_on_while_the_plan_includes_backups_and_off_without() {
		$this->assertTrue( devbase_wpvivid_locked_down() );

		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		$this->assertFalse( devbase_wpvivid_locked_down(), 'A WPvivid the client uses for themselves is not touched.' );

		add_filter( 'devbase/wpvivid/lockdown', '__return_true' );
		$this->assertTrue( devbase_wpvivid_locked_down(), 'A filter can decide it in code.' );
	}

	public function test_actions_off_the_allowlist_are_unhooked_and_allowed_ones_stay() {
		$this->register( 'wp_ajax_wpvivid_list_tasks_2' );
		$this->register( 'wp_ajax_wpvivid_backup_now_2' );
		$this->register( 'wp_ajax_nopriv_wpvivid_do_restore_2' );
		$this->register( 'wp_ajax_wpvivid_migrate_now' );
		$this->register( 'wp_ajax_wpvivid_export_now' );
		$this->register( 'wp_ajax_nopriv_wpvivid_restore' );
		$this->register( 'wp_ajax_wpvivid_add_remote' );

		$removed = devbase_wpvivid_lock_down();

		$this->assertTrue( has_action( 'wp_ajax_wpvivid_list_tasks_2' ) !== false, 'Watching a backup stays.' );
		$this->assertFalse( has_action( 'wp_ajax_wpvivid_backup_now_2' ), 'Starting one from a screen goes: only Brand Relay starts a backup.' );
		$this->assertTrue( has_action( 'wp_ajax_nopriv_wpvivid_do_restore_2' ) !== false, 'Restore stays, including the nopriv half.' );
		$this->assertTrue( has_action( 'wp_ajax_nopriv_wpvivid_restore' ) !== false );
		$this->assertFalse( has_action( 'wp_ajax_wpvivid_migrate_now' ), 'Site-to-site migration goes.' );
		$this->assertFalse( has_action( 'wp_ajax_wpvivid_export_now' ), 'Export goes.' );
		$this->assertFalse( has_action( 'wp_ajax_wpvivid_add_remote' ), 'Cloud storage goes.' );
		$this->assertContains( 'wp_ajax_wpvivid_migrate_now', $removed );
	}

	public function test_only_wpvivids_own_callbacks_are_removed() {
		// Another plugin's handler on a hook that merely has a WPvivid-looking name is not WPvivid's to lose.
		$this->hooks[] = 'wp_ajax_wpvivid_export_now';
		add_action( 'wp_ajax_wpvivid_export_now', function () {} );

		devbase_wpvivid_lock_down();

		$this->assertNotFalse( has_action( 'wp_ajax_wpvivid_export_now' ), 'A closure written outside the plugin is left alone.' );
	}

	public function test_nothing_is_removed_when_it_is_not_locked_down() {
		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		$this->register( 'wp_ajax_wpvivid_migrate_now' );

		$this->assertSame( array(), devbase_wpvivid_lock_down() );
		$this->assertNotFalse( has_action( 'wp_ajax_wpvivid_migrate_now' ) );
	}

	public function test_the_cloud_sign_in_handlers_on_init_are_removed() {
		$this->hooks[] = 'init';
		$handlers      = new WPvivid_Stub_Handlers();
		add_action( 'init', array( $handlers, 'handle_auth_actions' ) );

		do_action( 'init' ); // Runs the removal at priority 1; the handler would run at 10.

		$this->assertFalse( has_action( 'init', array( $handlers, 'handle_auth_actions' ) ) );
	}

	public function test_the_allowlist_can_be_changed_by_a_filter() {
		add_filter( 'devbase/wpvivid/allowed_ajax', fn( $list ) => array_merge( $list, array( 'wpvivid_export_now' ) ) );
		$this->register( 'wp_ajax_wpvivid_export_now' );

		devbase_wpvivid_lock_down();

		$this->assertNotFalse( has_action( 'wp_ajax_wpvivid_export_now' ) );
	}

	public function test_every_allowed_action_still_exists_in_the_bundled_wpvivid() {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( get_template_directory() . '/bundled-plugins/wpvivid-backuprestore.zip' ) );

		$source = '';

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( preg_match( '#/(includes|admin)/.*\.php$#', $name ) && false === strpos( $name, '/vendor/' ) ) {
				$source .= $zip->getFromIndex( $i );
			}
		}

		$this->assertNotSame( '', $source );

		$missing = array();

		foreach ( devbase_wpvivid_allowed_ajax_actions() as $action ) {
			if ( false === strpos( $source, "'wp_ajax_{$action}'" ) && false === strpos( $source, "'wp_ajax_nopriv_{$action}'" ) ) {
				$missing[] = $action;
			}
		}

		$this->assertSame( array(), $missing, 'An allowed action WPvivid renamed or dropped: the flow that used it would silently lose it. Update the allowlist (and check the flow).' );
	}

	// --- what the screens still show ----------------------------------------------------------

	public function test_the_tabs_are_cut_to_backup_and_restore_settings_and_log() {
		$tabs = array( 'backup_restore' => 1, 'schedule' => 1, 'remote_storage' => 1, 'setting' => 1, 'website_info' => 1, 'log' => 1, 'premium' => 1, 'migrate' => 1, 'key' => 1, 'mwp' => 1 );

		$this->assertSame( array( 'backup_restore', 'setting', 'log' ), array_keys( apply_filters( 'wpvivid_add_tab_page', $tabs ) ) );
	}

	public function test_the_inner_tabs_menus_and_logs_are_cut_too() {
		$this->assertSame( array( 'list_backup', 'list_log', 'list_restore' ), array_keys( apply_filters( 'wpvivid_backuppage_load_backuplist', array( 'list_backup' => 1, 'list_upload' => 1, 'list_log' => 1, 'list_restore' => 1, 'list_download' => 1 ) ) ) );
		$this->assertSame( array( 'general_setting', 'advance_setting' ), array_keys( apply_filters( 'wpvivid_add_setting_tab_page', array( 'general_setting' => 1, 'advance_setting' => 1, 'staging_setting' => 1, 'uc_setting' => 1 ) ) ) );
		$this->assertSame( array( 'backup_log_page' ), array_keys( apply_filters( 'wpvivid_add_log_tab_page', array( 'backup_log_page' => 1, 'staging_log_page' => 1 ) ) ) );
		$this->assertSame( array( 'WPvivid', 'wpvivid-setting' ), array_keys( apply_filters( 'wpvivid_get_admin_menus', array( 'WPvivid' => 1, 'wpvivid-snapshot-ex' => 1, 'wpvivid-setting' => 1, 'wpvivid-staging' => 1, 'wpvivid-export' => 1 ) ) ) );
	}

	public function test_the_screens_are_left_alone_when_not_locked_down() {
		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		$tabs = array( 'backup_restore' => 1, 'schedule' => 1, 'premium' => 1 );

		$this->assertSame( array_keys( $tabs ), array_keys( apply_filters( 'wpvivid_add_tab_page', $tabs ) ) );
	}

	public function test_the_sidebar_boxes_of_wpvivid_are_removed() {
		$this->hooks[] = 'wpvivid_add_side_bar';
		$box           = new WPvivid_Stub_Handlers();
		add_action( 'wpvivid_add_side_bar', array( $box, 'handle' ) );

		do_action( 'init' ); // The removal runs on init, at the last priority.

		$this->assertFalse( has_action( 'wpvivid_add_side_bar', array( $box, 'handle' ) ) );
	}
}
