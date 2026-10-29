<?php
// Covers inc/theme-settings/miscellaneous/credit.php.
//
// Brand Relay is faked with WordPress's pre_http_request filter, the same
// approach Test_Warden_Updates.php uses. Only the brand address is answered;
// the credit code must never need anything else.

class Test_Credit extends WP_UnitTestCase {

	const BRAND_URL = 'https://relay.test/v1/brand/acme';

	private $filter = null;
	private $requests = array();

	public function set_up() {
		parent::set_up();

		delete_option( DEVBASE_CREDIT_OPTION );
		update_field( 'credit_brand_url', self::BRAND_URL, 'option' );
	}

	public function tear_down() {
		$this->unfake();
		$this->requests = array();
		delete_option( DEVBASE_CREDIT_OPTION );
		update_field( 'credit_brand_url', '', 'option' );

		parent::tear_down();
	}

	private function unfake() {
		if ( null !== $this->filter ) {
			remove_filter( 'pre_http_request', $this->filter, 10 );
			$this->filter = null;
		}
	}

	private function brand( array $overrides = array() ) {
		return array_replace_recursive( array(
			'v'       => 1,
			'status'  => 'active',
			'show'    => true,
			'brand'   => array( 'slug' => 'acme', 'name' => 'Acme', 'url' => 'https://acme.example' ),
			'link'    => array( 'text' => 'Acme Web', 'url' => 'https://acme.example/restaurants', 'rel' => '', 'follow' => true ),
			'message' => 'Designed by',
			'assets'  => array( 'logo' => array( 'url' => 'https://relay.test/files/logo.svg', 'alt' => 'Acme logo', 'type' => 'image/svg+xml' ) ),
			'ttl'     => 3600,
		), $overrides );
	}

	private function fake( $code, $body ) {
		$this->unfake();

		$this->filter = function ( $preempt, $args, $url ) use ( $code, $body ) {
			$this->requests[] = array( 'url' => $url, 'args' => $args );

			return array(
				'response' => array( 'code' => $code ),
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	// --- Downloading and storing ---------------------------------------------

	public function test_it_downloads_the_public_address_and_builds_the_credit_from_the_copy() {
		$this->fake( 200, $this->brand() );

		$this->assertTrue( devbase_credit_refresh( true )['ok'] );

		$credit = devbase_credit();

		$this->assertSame( 'Designed by', $credit['message'] );
		$this->assertSame( 'Acme Web', $credit['link']['text'] );
		$this->assertSame( 'https://acme.example/restaurants', $credit['link']['url'] );
		$this->assertSame( 'Acme', $credit['brand']['name'] );
		$this->assertSame( 'https://relay.test/files/logo.svg', $credit['logo']['url'] );
		$this->assertSame( 'https://relay.test/v1/brand/acme', explode( '?', $this->requests[0]['url'] )[0] );
	}

	public function test_it_sends_no_credentials_and_the_site_language() {
		$this->fake( 200, $this->brand() );

		devbase_credit_refresh( true );

		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );
		$this->assertStringContainsString( 'lang=', $this->requests[0]['url'] );
	}

	public function test_private_values_in_the_response_never_reach_the_stored_copy() {
		$this->fake( 200, $this->brand( array(
			'params' => array( 'warden_parent_licence' => 'WRD-SECRET-LICENCE-KEY', 'accent' => '#fff' ),
			'site'   => array( 'name' => 'Client', 'domain' => 'client.test' ),
			'rules'  => array( array( 'id' => 'rul_1' ) ),
			'embed'  => array( 'html' => '<script>alert(1)</script>', 'css' => 'body{display:none}' ),
			'extra'  => 'WRD-ANOTHER-SECRET',
		) ) );

		devbase_credit_refresh( true );

		$stored = maybe_serialize( get_option( DEVBASE_CREDIT_OPTION ) );

		foreach ( array( 'WRD-', 'warden_parent_licence', 'params', 'accent', 'client.test', 'rul_1', '<script', 'display:none', 'extra' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $stored, "'{$needle}' leaked into the stored credit" );
		}
	}

	public function test_a_hostile_response_is_neutralised_on_the_way_in() {
		$this->fake( 200, $this->brand( array(
			'link'    => array( 'text' => '<script>alert(1)</script>Acme', 'url' => 'javascript:alert(1)' ),
			'message' => '<b>Designed</b> by',
		) ) );

		// A javascript: link is not a usable credit at all.
		$this->assertFalse( devbase_credit_refresh( true )['ok'] );
		$this->assertNull( devbase_credit() );
	}

	public function test_markup_is_escaped_when_the_html_is_built() {
		$this->fake( 200, $this->brand( array( 'link' => array( 'text' => 'Acme "&" Co' ), 'message' => 'Made <3 by' ) ) );
		devbase_credit_refresh( true );

		$html = devbase_credit_html();

		$this->assertStringContainsString( '>Acme &quot;&amp;&quot; Co</a>', $html );
		$this->assertStringContainsString( 'rel="noopener"', $html );
		$this->assertStringNotContainsString( '<3', $html );
	}

	public function test_rel_is_limited_to_known_tokens_and_follow_false_adds_nofollow() {
		$this->fake( 200, $this->brand( array( 'link' => array( 'rel' => 'sponsored onclick=x evil', 'follow' => false ) ) ) );
		devbase_credit_refresh( true );

		$rel = devbase_credit()['link']['rel'];

		$tokens = explode( ' ', $rel );
		sort( $tokens );

		$this->assertSame( array( 'nofollow', 'sponsored' ), $tokens );
		$this->assertStringNotContainsString( 'evil', $rel );
		$this->assertStringNotContainsString( 'onclick', $rel );
	}

	public function test_a_follow_link_has_no_nofollow() {
		$this->fake( 200, $this->brand() );
		devbase_credit_refresh( true );

		$this->assertStringNotContainsString( 'nofollow', devbase_credit_html() );
	}

	// --- Failing soft ---------------------------------------------------------

	public function test_a_failed_download_keeps_the_last_good_copy_and_records_the_error() {
		$this->fake( 200, $this->brand() );
		devbase_credit_refresh( true );

		foreach ( array( array( 500, '{}' ), array( 404, '{}' ), array( 200, 'not json' ), array( 200, '{"link":{}}' ) ) as $case ) {
			$this->fake( $case[0], $case[1] );

			$this->assertFalse( devbase_credit_refresh( true )['ok'] );
			$this->assertSame( 'Acme Web', devbase_credit()['link']['text'] );
			$this->assertNotSame( '', devbase_credit_get_state()['last_error'] );
		}
	}

	public function test_with_no_copy_and_a_failure_there_is_no_credit() {
		$this->fake( 500, '{}' );

		devbase_credit_refresh( true );

		$this->assertNull( devbase_credit() );
		$this->assertSame( '', devbase_credit_html() );
	}

	public function test_a_brand_marked_hidden_shows_no_credit() {
		$this->fake( 200, $this->brand( array( 'show' => false ) ) );
		devbase_credit_refresh( true );

		$this->assertNull( devbase_credit() );
	}

	// --- Rendering never downloads -------------------------------------------

	public function test_building_the_credit_makes_no_http_request() {
		$this->fake( 200, $this->brand() );
		devbase_credit_refresh( true );
		$before = count( $this->requests );

		devbase_credit();
		devbase_credit_html();

		$this->assertCount( $before, $this->requests );
	}

	public function test_nothing_stored_and_nothing_downloaded_means_no_credit_and_no_request() {
		$this->fake( 200, $this->brand() );

		$this->assertNull( devbase_credit() );
		$this->assertCount( 0, $this->requests );
	}

	// --- The address ----------------------------------------------------------

	public function test_the_copy_is_dropped_when_the_address_changes() {
		$this->fake( 200, $this->brand() );
		devbase_credit_refresh( true );
		$this->assertNotNull( devbase_credit() );

		update_field( 'credit_brand_url', 'https://relay.test/v1/brand/other', 'option' );

		$this->assertNull( devbase_credit() );
	}

	public function test_clearing_the_address_removes_the_credit_and_downloads_nothing() {
		$this->fake( 200, $this->brand() );
		devbase_credit_refresh( true );
		update_field( 'credit_brand_url', '', 'option' );
		$before = count( $this->requests );

		$result = devbase_credit_refresh( true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not configured', $result['error'] );
		$this->assertNull( devbase_credit() );
		$this->assertCount( $before, $this->requests );
	}

	public function test_a_plain_http_address_is_refused_outside_a_local_environment() {
		update_field( 'credit_brand_url', 'http://relay.test/v1/brand/acme', 'option' );

		if ( 'local' === wp_get_environment_type() ) {
			$this->markTestSkipped( 'http is allowed on a local environment.' );
		}

		$this->assertSame( '', devbase_credit_brand_url() );
	}

	// --- Hiding the settings --------------------------------------------------

	private function credit_fields() {
		return array(
			array( 'key' => 'field_devbase_misc_credit_tab', 'name' => '', 'type' => 'tab' ),
			array( 'key' => 'field_devbase_credit_brand_url', 'name' => 'credit_brand_url', 'type' => 'url' ),
		);
	}

	public function test_the_admin_has_no_status_function_or_status_field() {
		$this->assertFalse( function_exists( 'devbase_credit_status_html' ) );
		$this->assertFalse( acf_get_field( 'field_devbase_credit_status' ) );
	}

	public function test_the_credit_tab_shows_by_default() {
		foreach ( $this->credit_fields() as $field ) {
			$this->assertIsArray( apply_filters( 'acf/prepare_field', $field ) );
		}
	}

	public function test_the_credit_tab_is_removed_when_the_settings_are_hidden() {
		add_filter( 'devbase/credit/hide_settings', '__return_true' );

		foreach ( $this->credit_fields() as $field ) {
			$this->assertFalse( apply_filters( 'acf/prepare_field', $field ) );
		}

		remove_filter( 'devbase/credit/hide_settings', '__return_true' );
	}

	public function test_hiding_the_settings_leaves_other_fields_and_the_credit_itself_alone() {
		add_filter( 'devbase/credit/hide_settings', '__return_true' );
		$this->fake( 200, $this->brand() );

		$other = array( 'key' => 'field_devbase_retention_enabled', 'name' => 'retention_enabled', 'type' => 'true_false' );

		$this->assertIsArray( apply_filters( 'acf/prepare_field', $other ) );
		$this->assertTrue( devbase_credit_refresh( true )['ok'] );
		$this->assertNotSame( '', devbase_credit_html() );

		remove_filter( 'devbase/credit/hide_settings', '__return_true' );
	}

	public function test_the_address_can_come_from_code_instead_of_the_field() {
		update_field( 'credit_brand_url', '', 'option' );
		add_filter( 'devbase/credit/brand_url', fn() => 'https://relay.test/v1/brand/acme' );
		$this->fake( 200, $this->brand() );

		$this->assertTrue( devbase_credit_refresh( true )['ok'] );
		$this->assertSame( 'Acme Web', devbase_credit()['link']['text'] );

		remove_all_filters( 'devbase/credit/brand_url' );
	}

	// --- Scheduling -----------------------------------------------------------

	public function test_an_automatic_refresh_inside_the_minimum_gap_does_not_download_again() {
		$this->fake( 200, $this->brand() );
		devbase_credit_refresh( true );

		devbase_credit_refresh( false );

		$this->assertCount( 1, $this->requests );
	}

	public function test_a_forced_refresh_always_downloads() {
		$this->fake( 200, $this->brand() );
		devbase_credit_refresh( true );

		devbase_credit_refresh( true );

		$this->assertCount( 2, $this->requests );
	}
}
