<?php
// Covers what the theme does when the site already runs one of its dependencies as an ordinary plugin
// (inc/dependencies/foreign.php): it uses THEIR copy and leaves it alone. The loaders themselves run once at
// load time, so the real thing (a duplicate plugin actually installed) was tried by hand on a real site; here
// are the decisions built on the answer.

class Test_Foreign_Dependencies extends WP_UnitTestCase {

	private $foreign = array();

	public function set_up() {
		parent::set_up();
		$this->foreign = array();
		add_filter( 'devbase/dependencies/foreign', array( $this, 'set' ) );
	}

	public function tear_down() {
		remove_filter( 'devbase/dependencies/foreign', array( $this, 'set' ) );
		delete_option( 'options_hide_scf_ui' );
		delete_option( 'admin_site_enhancements' );

		parent::tear_down();
	}

	public function set( $found ) {
		return array_merge( $found, $this->foreign );
	}

	public function test_here_the_theme_runs_all_four_of_its_own_copies() {
		remove_filter( 'devbase/dependencies/foreign', array( $this, 'set' ) );

		$this->assertSame( array( 'scf' => false, 'ase' => false, 'mcp' => false, 'tsf' => false ), devbase_foreign_dependencies() );
	}

	public function test_the_answer_for_one_dependency_is_read_by_key() {
		$this->foreign = array( 'ase' => true );

		$this->assertTrue( devbase_dependency_is_foreign( 'ase' ) );
		$this->assertFalse( devbase_dependency_is_foreign( 'scf' ) );
		$this->assertFalse( devbase_dependency_is_foreign( 'unknown' ) );
	}

	public function test_a_scf_or_acf_the_site_installed_keeps_its_screens() {
		delete_option( 'options_hide_scf_ui' ); // on by default
		$this->assertTrue( devbase_scf_ui_hidden() );

		$this->foreign = array( 'scf' => true );
		$this->assertFalse( devbase_scf_ui_hidden(), 'Only the theme\'s own copy is hidden.' );
		$this->assertTrue( apply_filters( 'acf/settings/show_admin', true ) );
	}

	public function test_the_theme_never_writes_its_ase_defaults_into_the_sites_own_ase() {
		update_option( 'admin_site_enhancements', array( 'their_setting' => true ) );
		$this->foreign = array( 'ase' => true );

		do_action( 'after_switch_theme' );

		$this->assertSame( array( 'their_setting' => true ), get_option( 'admin_site_enhancements' ), 'Neither filled in nor enforced.' );
	}

	public function test_without_a_foreign_ase_the_defaults_are_still_filled_in() {
		delete_option( 'admin_site_enhancements' );

		do_action( 'after_switch_theme' );

		$this->assertArrayHasKey( 'enable_svg_upload', (array) get_option( 'admin_site_enhancements' ) );
	}
}
