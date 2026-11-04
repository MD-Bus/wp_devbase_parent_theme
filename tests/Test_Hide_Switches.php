<?php
// Covers the three Management switches that hide technical screens from a client (Lock remote setup, Hide
// WPvivid Backup, Hide Secure Custom Fields): ON until someone saves one off, read from the saved option so
// the default holds before any field is registered, and an explicit "off" staying off.

class Test_Hide_Switches extends WP_UnitTestCase {

	private $names = array( 'lock_remote_setup', 'hide_wpvivid_ui', 'hide_scf_ui' );

	public function set_up() {
		parent::set_up();
		$this->forget();
		devbase_relay_store_plan( array( 'licence' => array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => array( 'backup' ), 'limits' => array() ) ) );
	}

	public function tear_down() {
		delete_option( DEVBASE_RELAY_PLAN_OPTION );
		$this->forget();
		parent::tear_down();
	}

	private function forget() {
		foreach ( $this->names as $name ) {
			delete_option( 'options_' . $name );
			delete_option( '_options_' . $name );
		}
	}

	public function test_all_three_are_on_when_nothing_was_ever_saved() {
		foreach ( $this->names as $name ) {
			$this->assertTrue( devbase_switch_on( $name ), $name );
		}

		$this->assertTrue( devbase_remote_setup_hidden() );
		$this->assertTrue( devbase_wpvivid_ui_hidden() );
		$this->assertTrue( devbase_scf_ui_hidden() );
	}

	public function test_an_explicit_off_stays_off_and_an_explicit_on_is_on() {
		update_option( 'options_hide_scf_ui', '0' );
		$this->assertFalse( devbase_switch_on( 'hide_scf_ui' ) );
		$this->assertFalse( devbase_scf_ui_hidden() );

		update_option( 'options_hide_scf_ui', '1' );
		$this->assertTrue( devbase_switch_on( 'hide_scf_ui' ) );
	}

	public function test_the_switches_are_independent() {
		update_option( 'options_lock_remote_setup', '0' );

		$this->assertFalse( devbase_remote_setup_hidden() );
		$this->assertTrue( devbase_wpvivid_ui_hidden() );
		$this->assertTrue( devbase_scf_ui_hidden() );
	}

	public function test_updating_through_scf_works_the_same_way() {
		update_field( 'hide_wpvivid_ui', 0, 'option' );
		$this->assertFalse( devbase_wpvivid_ui_hidden() );

		update_field( 'hide_wpvivid_ui', 1, 'option' );
		$this->assertTrue( devbase_wpvivid_ui_hidden() );
	}

	public function test_the_fields_are_registered_on_and_the_scf_one_is_there() {
		foreach ( array( 'field_devbase_lock_remote_setup', 'field_devbase_hide_wpvivid_ui', 'field_devbase_hide_scf_ui' ) as $key ) {
			$field = acf_get_field( $key );
			$this->assertNotEmpty( $field, $key );
			$this->assertSame( 1, (int) $field['default_value'], $key . ' shows as on the first time it is opened.' );
		}
	}

	public function test_scf_is_told_to_close_its_screens_unless_the_switch_is_off() {
		$this->assertFalse( apply_filters( 'acf/settings/show_admin', true ) );

		update_option( 'options_hide_scf_ui', '0' );
		$this->assertTrue( apply_filters( 'acf/settings/show_admin', true ) );
	}
}
