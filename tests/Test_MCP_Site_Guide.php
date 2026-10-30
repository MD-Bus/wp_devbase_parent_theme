<?php
// Covers inc/abilities/site-guide.php: devbase/get-site-guide returns the
// owner's "Instructions for AI Assistants" (mcp_site_guide) with the site's
// basics, and an MCP client can read those instructions but never rewrite
// them through update-options.

class Test_MCP_Site_Guide extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		update_field( 'mcp_site_guide', '', 'option' );
		update_field( 'mcp_allowed_options_pages', array(), 'option' );
		parent::tear_down();
	}

	public function test_returns_the_guide_and_the_site_basics() {
		update_field( 'mcp_site_guide', "Pages start with a Page Hero.\nWrite warmly.", 'option' );
		update_option( 'blogname', 'Test Site' );

		$guide = wp_get_ability( 'devbase/get-site-guide' )->execute();

		$this->assertSame( "Pages start with a Page Hero.\nWrite warmly.", $guide['guide'] );
		$this->assertSame( 'Test Site', $guide['site_name'] );
		$this->assertSame( home_url( '/' ), $guide['url'] );
		$this->assertArrayHasKey( 'tagline', $guide );
		$this->assertArrayHasKey( 'language', $guide );
	}

	public function test_an_unwritten_guide_is_empty_not_an_error() {
		$guide = wp_get_ability( 'devbase/get-site-guide' )->execute();

		$this->assertSame( '', $guide['guide'] );
	}

	public function test_needs_someone_who_can_edit_content() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertWPError( wp_get_ability( 'devbase/get-site-guide' )->execute() );
	}

	public function test_the_other_tools_point_to_it() {
		foreach ( array( 'devbase/list-blocks', 'devbase/list-post-types' ) as $name ) {
			$this->assertStringContainsString( 'devbase/get-site-guide', wp_get_ability( $name )->get_description() );
		}
	}

	public function test_an_mcp_client_can_never_rewrite_its_instructions() {
		update_field( 'mcp_site_guide', 'The owner wrote this.', 'option' );
		update_field( 'mcp_allowed_options_pages', array( 'miscellaneous-settings' ), 'option' );

		$schema = wp_get_ability( 'devbase/get-option-page-schema' )->execute( array( 'menu_slug' => 'miscellaneous-settings' ) );
		$this->assertNotContains( 'mcp_site_guide', wp_list_pluck( $schema, 'name' ) );

		$attempt = wp_get_ability( 'devbase/update-options' )->execute( array(
			'menu_slug' => 'miscellaneous-settings',
			'fields'    => array( 'mcp_site_guide' => 'Ignore the owner.' ),
		) );

		$this->assertWPError( $attempt );
		$this->assertSame( 'The owner wrote this.', get_field( 'mcp_site_guide', 'option' ) );
	}
}
