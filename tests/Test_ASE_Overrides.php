<?php
// Covers inc/dependencies/ase/ase-settings.php's two child-theme override
// hooks — the mechanism that lets every client site share one identical
// base theme while running different ASE security settings.
//
// This is the only extension point where picking the wrong hook fails
// SILENTLY, so each semantic gets its own test:
//
//   devbase/ase/defaults  — fills a gap only. A stored value always wins,
//                            so overriding an already-set key does nothing.
//   devbase/ase/enforced  — overwrites, and is reasserted on every run.
//
// The fill-gaps rule is not a bug to work around: ASE registers every
// module as a field in one giant form, so saving that form with a stale
// page open resets every unchecked field. The parent theme can't tell that
// apart from a deliberate change, so it never reasserts. A child naming one
// specific key removes that ambiguity, which is why 'enforced' exists and
// is empty in the parent.

class Test_ASE_Overrides extends WP_UnitTestCase {

	/** @var array */
	private $saved_option;

	/** @var callable[] */
	private $filters = array();

	public function set_up() {
		parent::set_up();
		$this->saved_option = get_option( 'admin_site_enhancements', array() );
	}

	public function tear_down() {
		foreach ( $this->filters as $hook => $fn ) {
			remove_filter( $hook, $fn );
		}
		$this->filters = array();

		update_option( 'admin_site_enhancements', $this->saved_option );

		parent::tear_down();
	}

	/** Registers a filter the way a child theme's functions.php would. */
	private function child_override( $hook, array $values ) {
		$fn = function ( array $existing ) use ( $values ) {
			return array_merge( $existing, $values );
		};
		add_filter( $hook, $fn );
		$this->filters[ $hook ] = $fn;
	}

	/**
	 * Runs the parent's applier. Calls the same code path admin_init does,
	 * but directly — firing admin_init here would also run ASE's own
	 * register_sections_fields(), which calls the wp-admin-only
	 * add_settings_section() and fatals outside a real admin request. That
	 * is a harness artifact, not a bug; see tests/AGENT.md.
	 */
	private function apply() {
		$current = get_option( 'admin_site_enhancements', array() );
		$merged  = array_merge( devbase_ase_resolved_defaults(), $current );
		$merged  = array_merge( $merged, devbase_ase_enforced_settings() );
		update_option( 'admin_site_enhancements', $merged );
	}

	public function test_the_parent_enforces_nothing_by_default() {
		$this->assertSame(
			array(),
			devbase_ase_enforced_settings(),
			'The base theme must enforce nothing — it ships to every site and cannot know which deviations are deliberate.'
		);
	}

	public function test_a_child_can_adjust_the_default_for_a_key_the_site_has_never_saved() {
		update_option( 'admin_site_enhancements', array() );

		$this->child_override( 'devbase/ase/defaults', array( 'devbase_test_key' => 3 ) );
		$this->apply();

		$this->assertSame( 3, get_option( 'admin_site_enhancements' )['devbase_test_key'] );
	}

	public function test_a_default_override_does_NOT_change_a_key_the_site_already_has() {
		// The trap. A child setting a *default* for an already-stored key
		// silently does nothing — correct behaviour, easy to misread as the
		// filter not working.
		update_option( 'admin_site_enhancements', array( 'devbase_test_key' => true ) );

		$this->child_override( 'devbase/ase/defaults', array( 'devbase_test_key' => false ) );
		$this->apply();

		$this->assertTrue(
			get_option( 'admin_site_enhancements' )['devbase_test_key'],
			'A stored value must win over a default — this is what protects a client\'s deliberate change.'
		);
	}

	public function test_an_enforced_override_DOES_change_a_key_the_site_already_has() {
		update_option( 'admin_site_enhancements', array( 'devbase_test_key' => true ) );

		$this->child_override( 'devbase/ase/enforced', array( 'devbase_test_key' => false ) );
		$this->apply();

		$this->assertFalse( get_option( 'admin_site_enhancements' )['devbase_test_key'] );
	}

	public function test_an_enforced_value_is_reasserted_after_an_admin_changes_it_back() {
		$this->child_override( 'devbase/ase/enforced', array( 'devbase_test_key' => false ) );
		$this->apply();

		// An admin flips it via ASE's own settings page.
		$option = get_option( 'admin_site_enhancements', array() );
		$option['devbase_test_key'] = true;
		update_option( 'admin_site_enhancements', $option );

		// Next admin page load.
		$this->apply();

		$this->assertFalse(
			get_option( 'admin_site_enhancements' )['devbase_test_key'],
			'An enforced key must revert — that is the whole point, and also its cost.'
		);
	}

	public function test_enforced_wins_over_a_conflicting_default() {
		$this->child_override( 'devbase/ase/defaults', array( 'devbase_test_key' => 'from_default' ) );
		$this->child_override( 'devbase/ase/enforced', array( 'devbase_test_key' => 'from_enforced' ) );

		update_option( 'admin_site_enhancements', array() );
		$this->apply();

		$this->assertSame( 'from_enforced', get_option( 'admin_site_enhancements' )['devbase_test_key'] );
	}

	public function test_overrides_never_touch_unrelated_keys() {
		update_option( 'admin_site_enhancements', array(
			'devbase_test_key'   => true,
			'devbase_other_key'  => 'untouched',
		) );

		$this->child_override( 'devbase/ase/enforced', array( 'devbase_test_key' => false ) );
		$this->apply();

		$this->assertSame( 'untouched', get_option( 'admin_site_enhancements' )['devbase_other_key'] );
	}

	public function test_the_parents_own_defaults_survive_a_child_filter() {
		// A child that returns the array unchanged — the shipped stub shape —
		// must not drop the base theme's own baseline.
		$this->child_override( 'devbase/ase/defaults', array() );

		$resolved = devbase_ase_resolved_defaults();

		$this->assertArrayHasKey( 'disable_plugin_theme_editor', $resolved );
		$this->assertTrue( $resolved['disable_plugin_theme_editor'] );
	}

	public function test_a_filter_returning_a_non_array_is_ignored_rather_than_fatal() {
		$fn = function () { return 'oops'; };
		add_filter( 'devbase/ase/enforced', $fn );

		$this->assertSame( array(), devbase_ase_enforced_settings() );

		remove_filter( 'devbase/ase/enforced', $fn );
	}
}
