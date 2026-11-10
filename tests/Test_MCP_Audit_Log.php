<?php
// Covers inc/abilities/audit-log.php: every change made through an ability is recorded, with the user, the
// application password and, for an options page, the values before. Driven through the real Abilities API
// (wp_get_ability()->execute()), as an MCP call is.

class Test_MCP_Audit_Log extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => 'auditor' ) ) );
		delete_option( DEVBASE_ABILITIES_AUDIT_OPTION );
	}

	public function tear_down() {
		update_field( 'mcp_allowed_options_pages', array(), 'option' );
		update_field( 'mcp_allowed_post_types', array(), 'option' );
		update_field( 'retention_enabled', 0, 'option' );
		delete_option( DEVBASE_ABILITIES_AUDIT_OPTION );
		parent::tear_down();
	}

	public function test_an_options_change_is_recorded_with_the_value_before() {
		update_field( 'mcp_allowed_options_pages', array( 'miscellaneous-settings' ), 'option' );
		update_field( 'retention_enabled', 0, 'option' );

		$result = wp_get_ability( 'devbase/update-options' )->execute( array(
			'menu_slug' => 'miscellaneous-settings',
			'fields'    => array( 'retention_enabled' => true ),
		) );
		$this->assertIsArray( $result );

		$log = devbase_abilities_audit_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'devbase/update-options', $log[0]['ability'] );
		$this->assertSame( 'options miscellaneous-settings', $log[0]['target'] );
		$this->assertSame( 'auditor', $log[0]['user'] );
		$this->assertSame( array( 'retention_enabled' ), $log[0]['fields'] );
		$this->assertSame( array( 'retention_enabled' => '0' ), $log[0]['before'] );
	}

	public function test_a_refused_call_leaves_no_entry() {
		$result = wp_get_ability( 'devbase/update-options' )->execute( array(
			'menu_slug' => 'miscellaneous-settings', // not allowed
			'fields'    => array( 'retention_enabled' => true ),
		) );

		$this->assertWPError( $result );
		$this->assertSame( array(), devbase_abilities_audit_log() );
	}

	public function test_reading_is_not_recorded() {
		update_field( 'mcp_allowed_options_pages', array( 'miscellaneous-settings' ), 'option' );

		wp_get_ability( 'devbase/get-options' )->execute( array( 'menu_slug' => 'miscellaneous-settings' ) );
		wp_get_ability( 'devbase/list-option-pages' )->execute();

		$this->assertSame( array(), devbase_abilities_audit_log() );
	}

	public function test_a_post_update_is_recorded_with_its_title_before() {
		update_field( 'mcp_allowed_post_types', array( 'page' ), 'option' );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Old title' ) );

		$result = wp_get_ability( 'devbase/update-post' )->execute( array( 'post_type' => 'page', 'id' => $page, 'title' => 'New title' ) );
		$this->assertNotWPError( $result );

		$entry = devbase_abilities_audit_log()[0];
		$this->assertSame( 'devbase/update-post', $entry['ability'] );
		$this->assertSame( 'page ' . $page, $entry['target'] );
		$this->assertSame( array( 'title' ), $entry['fields'] );
		$this->assertSame( 'Old title', $entry['before']['title'] );
	}

	public function test_a_new_post_is_recorded_by_its_id() {
		update_field( 'mcp_allowed_post_types', array( 'page' ), 'option' );

		$result = wp_get_ability( 'devbase/create-post' )->execute( array( 'post_type' => 'page', 'title' => 'Made by MCP' ) );
		$this->assertIsArray( $result );

		$this->assertSame( 'page ' . (int) $result['id'], devbase_abilities_audit_log()[0]['target'] );
	}

	public function test_the_application_password_is_named() {
		$user = wp_get_current_user();
		list( , $item ) = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'Claude on the laptop' ) );
		add_filter( 'rest_authentication_errors', '__return_null' );

		// What WordPress sets after it accepted an application password for this request.
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		try {
			$this->assertSame( 'Claude on the laptop', devbase_abilities_audit_app_password() );
		} finally {
			unset( $GLOBALS['wp_rest_application_password_uuid'] );
		}

		$this->assertSame( '', devbase_abilities_audit_app_password(), 'A browser session has none.' );
	}

	public function test_the_log_is_capped_and_old_entries_go() {
		devbase_abilities_audit_add( array( 'time' => time() - 91 * DAY_IN_SECONDS, 'ability' => 'old' ) );

		for ( $i = 0; $i < DEVBASE_ABILITIES_AUDIT_KEEP + 3; $i++ ) {
			devbase_abilities_audit_add( array( 'time' => time(), 'ability' => "a{$i}" ) );
		}

		$log = devbase_abilities_audit_log();
		$this->assertCount( DEVBASE_ABILITIES_AUDIT_KEEP, $log );
		$this->assertNotContains( 'old', array_column( $log, 'ability' ) );
		$this->assertSame( 'a' . ( DEVBASE_ABILITIES_AUDIT_KEEP + 2 ), $log[0]['ability'] );
	}

	public function test_the_table_escapes_what_it_shows() {
		devbase_abilities_audit_add( array(
			'time'     => time(),
			'ability'  => 'devbase/update-options',
			'user'     => 'auditor',
			'password' => '<b>x</b>',
			'target'   => 'options contact',
			'fields'   => array( 'phone' ),
			'before'   => array( 'phone' => '<script>alert(1)</script>' ),
		) );

		$html = devbase_abilities_audit_html();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
	}
}
