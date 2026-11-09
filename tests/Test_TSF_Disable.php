<?php
// Covers the "Enable The SEO Framework" switch (Theme Settings > Miscellaneous > SEO tab): the reader in
// inc/dependencies/tsf/tsf.php and what the rest of the theme does with it. TSF is OFF by default. That TSF is really not loaded is
// checked live, not here: the loader runs once while the theme loads, so it cannot be re-run inside a test (see
// inc/dependencies/tsf/AGENT.md). The suite's bootstrap turns TSF on with a filter; these tests take it off where they need the default.

class Test_TSF_Disable extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Back to what a real site has: nothing decides it in code, the saved option does.
		$this->saved_filters = $GLOBALS['wp_filter']['devbase/tsf/enabled'] ?? null;
		remove_all_filters( 'devbase/tsf/enabled' );
	}

	public function tear_down() {
		remove_all_filters( 'devbase/tsf/enabled' );
		if ( $this->saved_filters ) {
			$GLOBALS['wp_filter']['devbase/tsf/enabled'] = $this->saved_filters;
		}
		parent::tear_down();
	}

	private $saved_filters;

	public function test_tsf_is_off_unless_the_switch_was_turned_on() {
		delete_option( 'options_enable_tsf' );
		$this->assertFalse( devbase_tsf_enabled(), 'Never saved: TSF is off.' );
		$this->assertTrue( devbase_tsf_disabled() );

		update_option( 'options_enable_tsf', '0' );
		$this->assertFalse( devbase_tsf_enabled() );

		update_option( 'options_enable_tsf', '1' );
		$this->assertTrue( devbase_tsf_enabled() );
		$this->assertFalse( devbase_tsf_disabled() );
	}

	public function test_a_filter_can_decide_it_in_code() {
		add_filter( 'devbase/tsf/enabled', '__return_true' );
		$this->assertTrue( devbase_tsf_enabled() );

		update_option( 'options_enable_tsf', '1' );
		remove_all_filters( 'devbase/tsf/enabled' );
		add_filter( 'devbase/tsf/enabled', '__return_false' );
		$this->assertFalse( devbase_tsf_enabled(), 'The filter has the last word.' );
	}

	public function test_the_switch_has_a_tab_of_its_own_that_is_never_hidden_and_is_off_by_default() {
		$field = acf_get_field( 'field_devbase_enable_tsf' );

		$this->assertIsArray( $field );
		$this->assertSame( 'enable_tsf', $field['name'] );
		$this->assertSame( 'true_false', $field['type'] );
		$this->assertEquals( 0, $field['default_value'], 'TSF is off until someone turns this on.' );

		$group = acf_get_field_group( $field['parent'] );
		$this->assertSame( 'miscellaneous-settings', $group['location'][0][0]['value'] );

		$tab = acf_get_field( 'field_devbase_misc_seo_tab' );
		$this->assertSame( 'SEO', $tab['label'] );

		$source = file_get_contents( get_template_directory() . '/inc/theme-settings/miscellaneous/settings.php' );
		$this->assertStringNotContainsString( 'misc_seo_tab', substr( $source, strpos( $source, 'wp_add_inline_style' ) ), 'The hide system must not know the SEO tab.' );
	}

	public function test_the_archive_seo_tab_is_not_registered_while_tsf_is_off() {
		devbase_archive_seo_register_fields( 'devtest_off', 'archive-devtest_off' );

		$this->assertFalse( acf_get_field( 'field_devbase_archive_seo_title_devtest_off' ), 'Only TSF reads these fields.' );
	}

	public function test_system_status_says_when_the_bundled_tsf_is_not_running() {
		$this->assertStringContainsString( '(disabled)', devbase_bundled_versions()['The SEO Framework'] );

		add_filter( 'devbase/tsf/enabled', '__return_true' );
		$this->assertStringNotContainsString( 'disabled', devbase_bundled_versions()['The SEO Framework'] );
	}

	public function test_an_active_seo_plugin_keeps_tsf_off_even_when_the_switch_is_on() {
		update_option( 'options_enable_tsf', '1' );
		$this->assertTrue( devbase_tsf_enabled(), 'No other SEO plugin: the switch decides.' );

		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( (array) $active, array( 'wp-seopress/seopress.php' ) ) );
		$this->reset_conflict_memo();

		$this->assertSame( 'SEOPress', devbase_tsf_conflicting_plugin() );
		$this->assertFalse( devbase_tsf_enabled(), 'Two SEO plugins would print every tag twice.' );

		update_option( 'active_plugins', $active );
		$this->reset_conflict_memo();
		$this->assertSame( '', devbase_tsf_conflicting_plugin() );
		$this->assertTrue( devbase_tsf_enabled() );
	}

	public function test_the_conflict_list_can_be_changed_in_code() {
		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( (array) $active, array( 'some-seo/some-seo.php' ) ) );
		add_filter( 'devbase/tsf/conflicting_plugins', fn() => array( 'some-seo/some-seo.php' => 'Some SEO' ) );
		$this->reset_conflict_memo();

		$this->assertSame( 'Some SEO', devbase_tsf_conflicting_plugin() );

		remove_all_filters( 'devbase/tsf/conflicting_plugins' );
		update_option( 'active_plugins', $active );
		$this->reset_conflict_memo();
	}

	private function reset_conflict_memo() {
		devbase_tsf_conflicting_plugin( true );
	}
}
