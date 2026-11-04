<?php
// The Theme Settings menu icon: a real, valid SVG that WordPress can recolour for every admin colour scheme.
// WordPress rewrites every fill="..." (and a style="...") when it paints a menu icon; a stroke would keep its
// own colour and look wrong on a dark menu, so the icon must be fills only.

class Test_Theme_Settings_Icon extends WP_UnitTestCase {

	private function svg() {
		return (string) file_get_contents( get_template_directory() . '/assets/icons/theme-settings-icon.svg' );
	}

	public function test_it_is_a_valid_twenty_pixel_svg() {
		$xml = simplexml_load_string( $this->svg() );

		$this->assertNotFalse( $xml );
		$this->assertSame( '0 0 20 20', (string) $xml['viewBox'] );
	}

	public function test_it_can_be_recoloured_by_wordpress_fills_only() {
		$svg = preg_replace( '/<!--.*?-->/s', '', $this->svg() ); // the file's own comment talks about strokes

		$this->assertStringContainsString( 'fill="', $svg );
		$this->assertStringNotContainsString( 'stroke', $svg, 'A stroke keeps its own colour; WordPress only repaints fills.' );
		$this->assertStringNotContainsString( 'style=', $svg, 'WordPress replaces a style attribute wholesale.' );
	}

	public function test_the_menu_uses_it_and_the_old_company_logo_is_gone() {
		$this->assertFileDoesNotExist( get_template_directory() . '/assets/icons/devbase-b-icon.svg' );

		$page = acf_get_options_page( 'theme-settings' );

		$this->assertSame( 'data:image/svg+xml;base64,' . base64_encode( $this->svg() ), $page['icon_url'] );
	}
}
