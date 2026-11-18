<?php
// Every file a template of this theme loads from {{ theme.uri }} must exist in the theme.
// The front-end placeholder (views/index.twig) showed a broken image from 1.30.0 to 1.52.2:
// 1.30.0 deleted assets/icons/devbase-b-icon.svg, which the template still loaded.

class Test_Template_Assets extends WP_UnitTestCase {

	public function test_every_asset_a_template_loads_exists() {
		$root  = get_template_directory();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/views', FilesystemIterator::SKIP_DOTS ) );
		$seen  = 0;

		foreach ( $files as $file ) {
			if ( 'twig' !== $file->getExtension() ) {
				continue;
			}

			preg_match_all( '~\{\{\s*theme\.uri\s*\}\}(/[^"\'\s?#)]+)~', (string) file_get_contents( $file->getPathname() ), $matches );

			foreach ( $matches[1] as $path ) {
				++$seen;
				$this->assertFileExists( $root . $path, substr( $file->getPathname(), strlen( $root ) + 1 ) . " loads {$path}, which the theme does not ship." );
			}
		}

		$this->assertGreaterThan( 0, $seen, 'No {{ theme.uri }} asset found in views/: the pattern no longer matches the templates.' );
	}

	public function test_placeholder_renders_with_its_icon() {
		$html = Timber\Timber::compile( 'index.twig', Timber\Timber::context() );

		$this->assertStringContainsString( '/assets/icons/theme-settings-icon.svg', $html );
		$this->assertStringNotContainsString( 'devbase-b-icon', $html );
	}
}
