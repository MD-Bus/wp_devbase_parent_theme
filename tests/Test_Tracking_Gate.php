<?php
// Covers the gate in inc/theme-settings/tracking/inject.php: tracking is printed only on production, never
// for logged-in staff, and a consent plugin can hold it back.

class Test_Tracking_Gate extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		update_field( 'gtm_id', 'GTM-TEST1', 'option' );
		update_field( 'ga4_id', 'G-TEST2', 'option' );
		update_field( 'header_scripts', '<script>window.headerPixel=1</script>', 'option' );
		update_field( 'footer_scripts', '<script>window.footerPixel=1</script>', 'option' );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		foreach ( array( 'gtm_id', 'ga4_id', 'header_scripts', 'footer_scripts' ) as $name ) {
			update_field( $name, '', 'option' );
		}

		parent::tear_down();
	}

	private function page() {
		// The theme's own callbacks only (the whole wp_head would run The SEO Framework without a query).
		ob_start();
		devbase_tracking_head();
		devbase_tracking_body_open();
		devbase_tracking_footer();

		return ob_get_clean();
	}

	private function environment( $type ) {
		add_filter( 'devbase/tracking/environment', function () use ( $type ) {
			return $type;
		} );
	}

	public function test_the_callbacks_are_on_the_page_hooks() {
		$this->assertNotFalse( has_action( 'wp_head', 'devbase_tracking_head' ) );
		$this->assertNotFalse( has_action( 'wp_body_open', 'devbase_tracking_body_open' ) );
		$this->assertNotFalse( has_action( 'wp_footer', 'devbase_tracking_footer' ) );
	}

	public function test_a_visitor_on_production_gets_everything() {
		$this->environment( 'production' );

		$html = $this->page();

		$this->assertStringContainsString( 'GTM-TEST1', $html );
		$this->assertStringContainsString( 'G-TEST2', $html );
		$this->assertStringContainsString( 'headerPixel', $html );
		$this->assertStringContainsString( 'footerPixel', $html );
	}

	public function test_nothing_is_printed_on_staging_or_local() {
		foreach ( array( 'staging', 'local', 'development' ) as $type ) {
			$this->environment( $type );

			$html = $this->page();

			$this->assertStringNotContainsString( 'GTM-TEST1', $html, $type );
			$this->assertStringNotContainsString( 'headerPixel', $html, $type );
			$this->assertStringNotContainsString( 'footerPixel', $html, $type );
			$this->assertSame( 'environment', devbase_tracking_off_reason() );

			remove_all_filters( 'devbase/tracking/environment' );
		}
	}

	public function test_a_logged_in_editor_is_not_tracked_but_a_subscriber_is() {
		$this->environment( 'production' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringNotContainsString( 'GTM-TEST1', $this->page() );
		$this->assertSame( 'staff', devbase_tracking_off_reason() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertStringContainsString( 'GTM-TEST1', $this->page(), 'A customer with an account is a visitor.' );
	}

	public function test_a_consent_plugin_can_hold_tracking_back() {
		$this->environment( 'production' );
		add_filter( 'devbase/tracking/enabled', '__return_false' );

		$html = $this->page();

		$this->assertStringNotContainsString( 'GTM-TEST1', $html );
		$this->assertStringNotContainsString( 'footerPixel', $html );
	}

	public function test_the_filter_can_keep_custom_scripts_while_analytics_waits() {
		$this->environment( 'production' );
		add_filter( 'devbase/tracking/enabled', function ( $enabled, $kind ) {
			return 'scripts' === $kind;
		}, 10, 2 );

		$html = $this->page();

		$this->assertStringNotContainsString( 'GTM-TEST1', $html );
		$this->assertStringContainsString( 'headerPixel', $html );
	}

	public function test_a_child_theme_can_track_staging_on_purpose() {
		$this->environment( 'staging' );
		add_filter( 'devbase/tracking/enabled', '__return_true' );

		$this->assertStringContainsString( 'GTM-TEST1', $this->page() );
	}
}
