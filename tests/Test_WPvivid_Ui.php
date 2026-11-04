<?php
// Covers hiding WPvivid Backup's own screens (inc/bundled-plugins/wpvivid.php): hidden when "Hide WPvivid Backup"
// is on, from the menu, the admin bar, the Plugins list and the notices it puts everywhere; shown when it is off;
// the activation redirect that would throw an admin onto its page; and what the Backups tab says either way.

class WPvivid_Stub_Notices {
	public function show() {
		echo 'x';
	}
}

class Test_WPvivid_Ui extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// The theme only hides WPvivid while the plan includes backups (the theme is the one using it).
		devbase_relay_store_plan( array( 'licence' => array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => array( 'backup' ), 'limits' => array() ) ) );
	}

	public function tear_down() {
		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		delete_option( 'options_hide_wpvivid_ui' );
		delete_option( 'options_lock_remote_setup' );
		remove_all_filters( 'devbase/wpvivid/hide_ui' );

		parent::tear_down();
	}

	private function lock( $on ) {
		update_field( 'hide_wpvivid_ui', $on ? 1 : 0, 'option' );
	}

	public function test_the_remote_setup_lock_does_not_hide_it_the_switch_of_its_own_does() {
		update_field( 'hide_wpvivid_ui', 0, 'option' );
		update_field( 'lock_remote_setup', 1, 'option' );
		$this->assertFalse( devbase_wpvivid_ui_hidden() );

		update_field( 'hide_wpvivid_ui', 1, 'option' );
		$this->assertTrue( devbase_wpvivid_ui_hidden() );
	}

	public function test_it_is_hidden_exactly_when_the_switch_is_on_and_a_filter_can_decide() {
		$this->lock( false );
		$this->assertFalse( devbase_wpvivid_ui_hidden() );

		$this->lock( true );
		$this->assertTrue( devbase_wpvivid_ui_hidden() );

		add_filter( 'devbase/wpvivid/hide_ui', '__return_false' );
		$this->assertFalse( devbase_wpvivid_ui_hidden() );
	}

	public function test_a_wpvivid_the_theme_is_not_using_is_never_hidden() {
		update_field( 'hide_wpvivid_ui', 1, 'option' );
		$this->assertTrue( devbase_wpvivid_ui_hidden() );

		// No backups in the plan: whatever WPvivid is on the site belongs to the site (a client's own, say).
		devbase_relay_store_plan( array( 'licence' => array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => array( 'uptime' ), 'limits' => array() ) ) );
		$this->assertFalse( devbase_wpvivid_ui_hidden() );

		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		$this->assertFalse( devbase_wpvivid_ui_hidden(), 'No plan at all: nothing of ours to hide.' );
	}

	public function test_the_menu_entries_go_but_other_menus_stay() {
		global $menu;
		$saved = $menu;
		$menu  = array(
			array( 'WPvivid Backup', 'manage_options', 'WPvivid', '', '', '', '' ),
			array( 'WPvivid Snapshot', 'manage_options', 'wpvivid-snapshot', '', '', '', '' ),
			array( 'Pages', 'edit_pages', 'edit.php?post_type=page', '', '', '', '' ),
		);

		$this->lock( true );
		do_action( 'admin_menu' );

		$slugs = array_column( $menu, 2 );
		$this->assertNotContains( 'WPvivid', $slugs );
		$this->assertNotContains( 'wpvivid-snapshot', $slugs );
		$this->assertContains( 'edit.php?post_type=page', $slugs );

		$menu = $saved;
	}

	public function test_the_menu_stays_when_not_locked() {
		global $menu;
		$saved = $menu;
		$menu  = array( array( 'WPvivid Backup', 'manage_options', 'WPvivid', '', '', '', '' ) );

		$this->lock( false );
		do_action( 'admin_menu' );

		$this->assertContains( 'WPvivid', array_column( $menu, 2 ) );
		$menu = $saved;
	}

	public function test_the_admin_bar_entry_and_its_children_go() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		$bar->add_node( array( 'id' => 'wpvivid_admin_menu', 'title' => 'WPvivid Backup' ) );
		$bar->add_node( array( 'id' => 'wpvivid_admin_menu_backup', 'parent' => 'wpvivid_admin_menu', 'title' => 'Backup & Restore' ) );
		$bar->add_node( array( 'id' => 'site-name', 'title' => 'Site' ) );

		$this->lock( true );
		// Our callback only (core's own would need a signed-in user).
		foreach ( $GLOBALS['wp_filter']['admin_bar_menu']->callbacks[ PHP_INT_MAX ] ?? array() as $entry ) {
			call_user_func( $entry['function'], $bar );
		}

		$this->assertNull( $bar->get_node( 'wpvivid_admin_menu' ) );
		$this->assertNull( $bar->get_node( 'wpvivid_admin_menu_backup' ) );
		$this->assertNotNull( $bar->get_node( 'site-name' ) );
	}

	public function test_its_plugins_row_and_links_go_when_locked_and_stay_when_not() {
		$file    = 'wpvivid-backuprestore/wpvivid-backuprestore.php';
		$plugins = array( $file => array( 'Name' => 'WPvivid' ), 'other/other.php' => array( 'Name' => 'Other' ) );

		$this->lock( false );
		$this->assertArrayHasKey( $file, apply_filters( 'all_plugins', $plugins ) );
		$this->assertSame( array( 'Settings' ), apply_filters( 'plugin_action_links_' . $file, array( 'Settings' ) ) );

		$this->lock( true );
		$this->assertArrayNotHasKey( $file, apply_filters( 'all_plugins', $plugins ) );
		$this->assertArrayHasKey( 'other/other.php', apply_filters( 'all_plugins', $plugins ) );
		$this->assertSame( array(), apply_filters( 'plugin_action_links_' . $file, array( 'Settings' ) ) );
	}

	public function test_its_notices_are_removed_and_other_notices_are_left() {
		$stub = new WPvivid_Stub_Notices();
		add_action( 'admin_notices', array( $stub, 'show' ) );
		add_action( 'admin_notices', 'devbase_test_other_notice' );

		devbase_wpvivid_remove_notices();

		$this->assertFalse( has_action( 'admin_notices', array( $stub, 'show' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', 'devbase_test_other_notice' ) );
		remove_action( 'admin_notices', 'devbase_test_other_notice' );
	}

	public function test_it_is_told_apart_from_other_callbacks_by_class_and_by_folder() {
		$this->assertTrue( devbase_wpvivid_owns_callback( array( new WPvivid_Stub_Notices(), 'show' ) ) );
		$this->assertFalse( devbase_wpvivid_owns_callback( array( $this, 'tear_down' ) ) );
		$this->assertFalse( devbase_wpvivid_owns_callback( function () {} ), 'A closure written elsewhere is not theirs.' );
	}

	public function test_the_activation_redirect_is_cancelled_when_the_theme_activates_it() {
		update_option( 'wpvivid_do_activation_redirect', true );

		do_action( 'devbase/bundled_plugin_activated', 'something-else' );
		$this->assertNotFalse( get_option( 'wpvivid_do_activation_redirect' ) );

		do_action( 'devbase/bundled_plugin_activated', 'wpvivid-backuprestore' );
		$this->assertFalse( get_option( 'wpvivid_do_activation_redirect' ) );
	}

	public function test_the_backups_page_sends_a_locked_out_client_to_their_developer_not_to_wpvivid() {
		$job = array( 'type' => 'download', 'state' => 'done', 'updated_at' => time() );

		$this->lock( false );
		$this->assertStringContainsString( 'Open WPvivid Backup', devbase_backup_job_summary( $job ) );

		$this->lock( true );
		$this->assertStringContainsString( 'ask your developer', devbase_backup_job_summary( $job ) );
	}
}

function devbase_test_other_notice() {
}
