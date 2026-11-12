<?php
// Covers inc/dependencies/tsf/tsf.php: The SEO Framework (TSF) loaded from vendor/, which a plugin is not
// built for. Every assertion on TSF's own source here is a contract this theme relies on: when a Dependabot
// pull request moves TSF and one of them fails, the loader or one of our hooks needs a look before release.

class Test_TSF_Loader extends WP_UnitTestCase {

	const SRC = __DIR__ . '/../vendor/autodescription/';

	private function source( $relative ) {
		return (string) file_get_contents( self::SRC . $relative );
	}

	// --- It runs ---------------------------------------------------------------

	public function test_tsf_is_running_from_vendor() {
		$this->assertTrue( defined( 'THE_SEO_FRAMEWORK_VERSION' ) );
		$this->assertFileExists( self::SRC . 'autodescription.php' );
		$this->assertSame( dirname( THE_SEO_FRAMEWORK_PLUGIN_BASE_FILE ), realpath( rtrim( self::SRC, '/' ) ) );
	}

	public function test_its_assets_resolve_to_vendor_not_the_plugins_folder() {
		$this->assertSame( get_template_directory_uri() . '/vendor/autodescription/', THE_SEO_FRAMEWORK_DIR_URL );
	}

	public function test_tsf_was_started_by_the_loader_exactly_once() {
		$this->assertGreaterThan( 0, did_action( 'the_seo_framework_loaded' ) );
		$this->assertFalse( has_action( 'plugins_loaded', 'The_SEO_Framework\_load_tsf' ), 'Its own start-up hook is taken off after the loader runs it.' );
		$this->assertTrue( function_exists( 'tsf' ) );
	}

	public function test_the_theme_supports_the_title_tag() {
		$this->assertTrue( current_theme_supports( 'title-tag' ) );
	}

	public function test_an_unmodified_site_prints_one_graph_with_one_website_and_one_organization() {
		$this->go_to( home_url( '/' ) );
		$graph = The_SEO_Framework\Meta\Schema::get_generated_graph();
		$json  = (string) wp_json_encode( $graph );

		// TSF puts an entity inline in whatever refers to it when only one thing does, so count entities, not nodes.
		$this->assertSame( 1, substr_count( $json, '"@type":"WebSite"' ) );
		$this->assertSame( 1, substr_count( $json, '"@type":"Organization"' ), 'The knowledge graph is on by default.' );
	}

	// --- No TSF branding or promotion (only for the theme's own copy) --------------------

	public function test_the_branding_comments_and_the_sitemap_link_are_switched_off() {
		$this->assertFalse( apply_filters( 'the_seo_framework_indicator', true ) );
		$this->assertFalse( apply_filters( 'the_seo_framework_indicator_timing', true ) );
		$this->assertFalse( apply_filters( 'the_seo_framework_indicator_sitemap', true ) );
	}

	public function test_the_extension_suggestions_are_off_even_for_an_administrator() {
		$this->assertTrue( defined( 'TSF_DISABLE_SUGGESTIONS' ) && TSF_DISABLE_SUGGESTIONS );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The "Extensions" button in TSF's settings header and its suggestions ask this one function.
		$this->assertFalse( The_SEO_Framework\Admin\Utils::display_extension_suggestions() );
	}

	// --- Jetpack's SEO output is switched off (only for the theme's copy) ----------------

	public function test_jetpacks_social_tags_and_seo_modules_are_switched_off() {
		$this->assertFalse( apply_filters( 'jetpack_enable_open_graph', true ) );
		$this->assertTrue( apply_filters( 'jetpack_disable_twitter_cards', false ) );

		$modules = apply_filters( 'jetpack_get_available_modules', array( 'seo-tools' => '1', 'sitemaps' => '1', 'photon' => '1' ) );

		$this->assertSame( array( 'photon' ), array_keys( $modules ), 'Site Accelerator stays; SEO Tools and Sitemaps go.' );
	}

	// --- TSF's own organization fields are hidden (only for the theme's copy) ------------

	public function test_tsfs_presence_tab_is_removed_and_the_other_schema_tabs_stay() {
		$tabs = array( 'general' => array( 'name' => 'General' ), 'presence' => array( 'name' => 'Presence' ), 'breadcrumbs' => array( 'name' => 'Breadcrumbs' ) );

		$this->assertSame( array( 'general', 'breadcrumbs' ), array_keys( apply_filters( 'the_seo_framework_schema_settings_tabs', $tabs ) ) );
	}

	public function test_the_pointer_in_tsfs_schema_box_says_where_the_organization_is_set() {
		ob_start();
		do_action( 'the_seo_framework_schema_metabox_before' );
		$hidden = ob_get_clean();

		$this->assertStringContainsString( 'are set on the theme', $hidden );

		remove_filter( 'the_seo_framework_schema_settings_tabs', 'devbase_tsf_hide_presence_tab' );
		ob_start();
		do_action( 'the_seo_framework_schema_metabox_before' );
		$shown = ob_get_clean();

		$this->assertStringContainsString( 'replace the ones below', $shown, 'A tab that is still there needs the other wording.' );
	}

	// --- The contract with TSF's own source -------------------------------------------

	public function test_tsf_still_starts_itself_on_plugins_loaded_with_the_callback_the_loader_runs() {
		$load = $this->source( 'bootstrap/load.php' );

		$this->assertStringContainsString( "'plugins_loaded', 'The_SEO_Framework\\_load_tsf'", $load );
		$this->assertMatchesRegularExpression( '/function\s+_load_tsf\s*\(/', $load );
	}

	public function test_tsf_still_defines_its_asset_url_in_one_place_we_can_override_first() {
		$this->assertMatchesRegularExpression( "/define\(\s*'THE_SEO_FRAMEWORK_DIR_URL'/", $this->source( 'bootstrap/define.php' ) );
	}

	/** @dataProvider hooks_we_use */
	public function test_the_filters_we_hook_still_exist( $hook ) {
		$found = false;

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::SRC . 'inc', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() && false !== strpos( (string) file_get_contents( $file->getPathname() ), "'$hook'" ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, "TSF no longer applies the filter $hook." );
	}

	public function hooks_we_use() {
		return array(
			'organization and graph' => array( 'the_seo_framework_schema_graph_data' ),
			'archive title'          => array( 'the_seo_framework_title_from_custom_field' ),
			'archive description'    => array( 'the_seo_framework_custom_field_description' ),
			'page-source comment'    => array( 'the_seo_framework_indicator' ),
			'comment timing'         => array( 'the_seo_framework_indicator_timing' ),
			'sitemap footer link'    => array( 'the_seo_framework_indicator_sitemap' ),
			'schema settings tabs'   => array( 'the_seo_framework_schema_settings_tabs' ),
		);
	}

	public function test_the_organization_entity_keeps_the_id_our_merge_matches() {
		$this->assertStringContainsString( "'#/schema/'", $this->source( 'inc/classes/meta/schema/entities/reference.class.php' ) );
	}

	public function test_tsf_still_registers_the_notices_the_loader_takes_off_and_still_reads_the_suggestion_constant() {
		$upgrade = $this->source( 'bootstrap/upgrade.php' );

		// If a release renames these, the removal in tsf.php silently does nothing: the notice shows once, which is harmless,
		// but update tsf.php to the new names.
		$this->assertStringContainsString( "'the_seo_framework_upgraded', 'The_SEO_Framework\\Bootstrap\\_prepare_upgrade_notice', 99", $upgrade );
		$this->assertStringContainsString( "'the_seo_framework_upgraded', 'The_SEO_Framework\\Bootstrap\\_prepare_upgrade_suggestion', 100", $upgrade );
		$this->assertStringContainsString( "'the_seo_framework_downgraded', 'The_SEO_Framework\\Bootstrap\\_prepare_downgrade_notice', 99", $upgrade );
		$this->assertStringContainsString( 'TSF_DISABLE_SUGGESTIONS', $this->source( 'inc/classes/admin/utils.class.php' ) );
	}

	public function test_tsf_still_names_the_schema_tab_we_hide_presence() {
		$view = $this->source( 'inc/views/settings/metaboxes/schema.php' );

		$this->assertMatchesRegularExpression( "/'presence'\\s*=>\\s*\\[/", $view, 'If TSF renames the tab, devbase_tsf_hide_presence_tab() hides nothing.' );
	}
}
