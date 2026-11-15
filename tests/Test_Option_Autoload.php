<?php
// Covers inc/theme-settings/autoload.php: the settings every public page reads (Organization Details, Tracking, two
// from Miscellaneous) are autoloaded whoever writes them (update_field() is what the settings app's save, the classic
// pages and MCP all end in), existing rows are switched once, and nothing else is touched (secrets stay off).

class Test_Option_Autoload extends WP_UnitTestCase {

	public function tear_down() {
		foreach ( array( 'gtm_id', 'header_scripts', 'smtp_password', 'enable_tsf' ) as $name ) {
			delete_field( $name, 'option' );
		}
		delete_option( DEVBASE_AUTOLOAD_STAMP );
		parent::tear_down();
	}

	private function autoload_of( $option ) {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	}

	private function is_on( $option ) {
		return in_array( $this->autoload_of( $option ), wp_autoload_values_to_autoload(), true );
	}

	public function test_the_set_holds_tracking_and_the_two_miscellaneous_fields_and_no_secret() {
		$names = devbase_settings_autoload_fields();

		foreach ( array( 'gtm_id', 'ga4_id', 'header_scripts', 'footer_scripts', 'enable_tsf', 'credit_brand_url' ) as $name ) {
			$this->assertContains( $name, $names );
		}

		foreach ( array( 'smtp_password', 'brevo_api_key', 'warden_licence_key', 'relay_site_key', 'mcp_site_guide' ) as $name ) {
			$this->assertNotContains( $name, $names );
		}
	}

	public function test_organization_details_is_in_the_set_while_its_group_exists() {
		if ( ! acf_get_field_group( 'group_devbase_schema' ) ) {
			$this->markTestSkipped( 'Organization Details exists only while The SEO Framework is on.' );
		}

		$this->assertContains( 'organization_name', devbase_settings_autoload_fields() );
		$this->assertContains( 'organization_locations', devbase_settings_autoload_fields() );
	}

	public function test_names_match_the_value_its_reference_and_repeater_rows_only() {
		$names = array( 'organization_locations', 'gtm_id' );

		$this->assertTrue( devbase_settings_autoload_wanted( 'options_gtm_id', $names ) );
		$this->assertTrue( devbase_settings_autoload_wanted( '_options_gtm_id', $names ), "SCF's reference row is read with the value." );
		$this->assertTrue( devbase_settings_autoload_wanted( 'options_organization_locations_0_label', $names ) );
		$this->assertFalse( devbase_settings_autoload_wanted( 'options_gtm_identity', $names ), 'A longer name is a different field.' );
		$this->assertFalse( devbase_settings_autoload_wanted( 'gtm_id', $names ), 'Not an SCF options row.' );
		$this->assertFalse( devbase_settings_autoload_wanted( 'options_smtp_password', $names ) );
	}

	public function test_a_value_saved_by_update_field_is_autoloaded_and_a_secret_is_not() {
		update_field( 'gtm_id', 'GTM-ABC123', 'option' );
		update_field( 'smtp_password', 'hunter2', 'option' );

		$this->assertTrue( $this->is_on( 'options_gtm_id' ), 'Saved with SCF autoload off, then switched on.' );
		$this->assertTrue( $this->is_on( '_options_gtm_id' ) );
		$this->assertFalse( $this->is_on( 'options_smtp_password' ), 'A secret is not loaded on every request.' );

		// A second save (the value changes, so SCF writes it with autoload off again) keeps it on.
		update_field( 'gtm_id', 'GTM-XYZ789', 'option' );
		$this->assertTrue( $this->is_on( 'options_gtm_id' ) );
		$this->assertSame( 'GTM-XYZ789', get_field( 'gtm_id', 'option' ) );
	}

	public function test_existing_rows_are_switched_once_and_again_when_the_set_changes() {
		// Rows written before this file existed: autoload off.
		add_option( 'options_header_scripts', '<script>1</script>', '', false );
		add_option( '_options_header_scripts', 'field_devbase_header_scripts', '', false );
		wp_set_options_autoload( array( 'options_header_scripts', '_options_header_scripts' ), false );
		$this->assertFalse( $this->is_on( 'options_header_scripts' ) );

		delete_option( DEVBASE_AUTOLOAD_STAMP );
		$this->assertGreaterThan( 0, devbase_settings_autoload_sync() );
		$this->assertTrue( $this->is_on( 'options_header_scripts' ) );
		$this->assertTrue( $this->is_on( '_options_header_scripts' ) );

		$this->assertSame( 0, devbase_settings_autoload_sync(), 'Same set: nothing to do, only the stamp is read.' );

		// A child theme adds its own front-end field: the next pass switches it.
		add_option( 'options_child_banner', 'Hello', '', false );
		wp_set_option_autoload( 'options_child_banner', false );
		add_filter( 'devbase/settings/autoload_fields', function ( $names ) {
			$names[] = 'child_banner';
			return $names;
		} );

		$this->assertGreaterThan( 0, devbase_settings_autoload_sync() );
		$this->assertTrue( $this->is_on( 'options_child_banner' ) );
		delete_option( 'options_child_banner' );
	}
}
