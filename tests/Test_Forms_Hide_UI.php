<?php
// Covers the Management switch that hides the Registered Forms and Form Data menus (inc/forms/post-types.php).

class Test_Forms_Hide_UI extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'options_hide_registered_forms_ui' );
		delete_option( 'options_hide_form_data_ui' );
		unset( $GLOBALS['menu'] );

		parent::tear_down();
	}

	private function menu_after_admin_menu() {
		$GLOBALS['menu'] = array(
			array( 'Registered Forms', 'manage_options', 'edit.php?post_type=registered_form' ),
			array( 'Form Data', 'manage_options', 'edit.php?post_type=form_data' ),
			array( 'Other', 'manage_options', 'devbase-other-page' ),
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'admin_menu' );

		return array_column( (array) $GLOBALS['menu'], 2 );
	}

	public function test_the_forms_menus_are_shown_by_default() {
		$this->assertFalse( devbase_forms_ui_hidden( 'registered_form' ) );
		$this->assertFalse( devbase_forms_ui_hidden( 'form_data' ) );
		$menu = $this->menu_after_admin_menu();
		$this->assertContains( 'edit.php?post_type=registered_form', $menu );
		$this->assertContains( 'edit.php?post_type=form_data', $menu );
	}

	public function test_each_switch_hides_only_its_own_menu() {
		update_option( 'options_hide_registered_forms_ui', '1' );
		$menu = $this->menu_after_admin_menu();
		$this->assertNotContains( 'edit.php?post_type=registered_form', $menu );
		$this->assertContains( 'edit.php?post_type=form_data', $menu );
		$this->assertContains( 'devbase-other-page', $menu );

		delete_option( 'options_hide_registered_forms_ui' );
		update_option( 'options_hide_form_data_ui', '1' );
		$menu = $this->menu_after_admin_menu();
		$this->assertContains( 'edit.php?post_type=registered_form', $menu );
		$this->assertNotContains( 'edit.php?post_type=form_data', $menu );
	}

	public function test_a_filter_can_decide_it_in_code() {
		add_filter( 'devbase/forms/hide_ui', '__return_true' );
		$this->assertTrue( devbase_forms_ui_hidden( 'form_data' ) );
		remove_filter( 'devbase/forms/hide_ui', '__return_true' );
	}
}
