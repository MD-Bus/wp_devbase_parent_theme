<?php

// Loads The SEO Framework (TSF) from vendor/, same pattern as ../scf/scf.php and ../ase/ase.php.
// TSF is the only SEO implementation this theme has: titles, descriptions, canonicals, Open Graph,
// the JSON-LD graph, breadcrumbs, the sitemap and robots.txt are all TSF's. See ./AGENT.md.

// The page <title> is printed by WordPress only for a theme that declares support for it, and TSF (like
// any SEO plugin) sets the title through WordPress's title filters. Without this no title tag is printed
// at all. So no template of this theme or a child's needs a hardcoded <title>.
add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
} );

/**
 * Whether The SEO Framework runs on this site: Theme Settings > Miscellaneous > SEO > "Enable The SEO Framework". OFF BY DEFAULT
 * (owner's decision, 2026-11-08): a site has no SEO from the theme until someone turns it on, so a client who runs their own SEO
 * plugin never has two. It reads the raw saved option, not get_field(): this is asked while the theme loads, before ACF has
 * registered a field. `devbase/tsf/enabled` (bool) can decide it in code (the test suite turns it on there).
 */
function devbase_tsf_enabled() {
	return (bool) apply_filters( 'devbase/tsf/enabled', (bool) (int) get_option( 'options_enable_tsf', 0 ) && ! devbase_tsf_conflicting_plugin() );
}

/**
 * The name of an active SEO plugin that would print the same tags as TSF (two sets of titles, canonicals, Open Graph tags and
 * JSON-LD are what search engines flag), or '' when there is none (`$refresh` asks again; the answer is kept for the request). TSF only warns about these in its admin and prints anyway, so the
 * theme does not load it beside one. The list is TSF's own "SEO tools" list (its `Compatibility::get_conflicting_plugins()`), copied
 * because TSF is not loaded yet when this is asked; `devbase/tsf/conflicting_plugins` ( array of plugin file => name ) can change it.
 * Read from the active plugins directly (like the foreign TSF check in foreign.php): this runs while the theme loads.
 */
function devbase_tsf_conflicting_plugin( $refresh = false ) {
	static $memo = null;

	if ( $refresh || null === $memo ) {
		$plugins = (array) apply_filters( 'devbase/tsf/conflicting_plugins', array(
			'wordpress-seo/wp-seo.php'                => 'Yoast SEO',
			'wordpress-seo-premium/wp-seo-premium.php' => 'Yoast SEO Premium',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'seo-ultimate/seo-ultimate.php'           => 'SEO Ultimate',
			'wp-seopress/seopress.php'                => 'SEOPress',
			'seo-by-rank-math/rank-math.php'          => 'Rank Math',
			'smartcrawl-seo/wpmu-dev-seo.php'         => 'SmartCrawl',
		) );
		$active  = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$memo = '';

		foreach ( $plugins as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$memo = (string) $name;
				break;
			}
		}
	}

	return $memo;
}

/** The other way round, for the places that read better as "is it off". */
function devbase_tsf_disabled() {
	return ! devbase_tsf_enabled();
}

// The site already runs TSF as a plugin: use that one and load nothing of ours (see ../foreign.php).
if ( devbase_dependency_is_foreign( 'tsf' ) ) {
	return;
}

// Not enabled (the default): TSF is not loaded at all (not hidden: nothing of it runs, so another SEO plugin has the page to itself),
// and nothing of ours that only TSF reads is registered either (the Organization Details page, the archive SEO tab, their hooks).
// The `title-tag` support above stays: any SEO plugin needs it. Nothing saved is deleted; enabling it again brings it all back.
if ( devbase_tsf_disabled() ) {
	return;
}

$devbase_tsf_main = get_template_directory() . '/vendor/autodescription/autodescription.php';

if ( ! file_exists( $devbase_tsf_main ) ) {
	return;
}

// No TSF branding or promotion on a site that runs the theme's own copy. Everything here uses switches TSF itself
// provides, from the theme; nothing in vendor/autodescription/ is touched. A child theme turns it back on with
// `add_filter( 'devbase/tsf/hide_branding', '__return_false' )` (a child's functions.php loads before this).
// A site's OWN TSF (the return above) is never changed.
$devbase_tsf_hide_branding = (bool) apply_filters( 'devbase/tsf/hide_branding', true );

if ( $devbase_tsf_hide_branding ) {
	// TSF reads this constant when it is about to show an Extension Manager suggestion: the "Extensions" button in its
	// settings header that links to its site, and the sponsorship/upgrade suggestions. Read at that moment, so
	// defining it here is early enough. A value the site already set (wp-config.php) is left alone.
	if ( ! defined( 'TSF_DISABLE_SUGGESTIONS' ) ) {
		define( 'TSF_DISABLE_SUGGESTIONS', true );
	}

	// The "<!-- The SEO Framework by Sybre Waaijer -->" comments in the page source, with the timing note...
	add_filter( 'the_seo_framework_indicator', '__return_false' );
	add_filter( 'the_seo_framework_indicator_timing', '__return_false' );

	// ...and "Generated by The SEO Framework" at the foot of the styled sitemap.xml.
	add_filter( 'the_seo_framework_indicator_sitemap', '__return_false' );
}

// Jetpack's SEO output is switched off (WordPress.com hosting always has Jetpack, and it prints a second set of social tags). Jetpack's
// Open Graph tags go beside TSF's, and Jetpack picks the first picture on the page for og:image: on a home page with a hero background
// that made Facebook show the hero instead of the social image set in TSF (Twitter/X read TSF's own tag, so the two disagreed). Off here:
// its Open Graph and Twitter card tags, and its SEO Tools and Sitemaps modules (TSF does both jobs), which can then not be switched on.
// Only filters, so nothing runs and nothing is checked on a site without Jetpack. Jetpack's Site Accelerator (the image CDN) is not SEO and
// stays. `devbase/tsf/disable_jetpack_seo` (bool, default true) leaves Jetpack alone. Only the theme's own copy of TSF (the returns above).
if ( apply_filters( 'devbase/tsf/disable_jetpack_seo', true ) ) {
	add_filter( 'jetpack_enable_open_graph', '__return_false' );
	add_filter( 'jetpack_disable_twitter_cards', '__return_true' );
	add_filter( 'jetpack_get_available_modules', 'devbase_tsf_remove_jetpack_seo_modules' );
}

/** Takes Jetpack's SEO Tools and Sitemaps modules out of the list it can switch on. */
function devbase_tsf_remove_jetpack_seo_modules( $modules ) {
	unset( $modules['seo-tools'], $modules['sitemaps'] );

	return $modules;
}

// The organization is described on the theme's Organization Details page (inc/theme-settings/schema/), which replaces what TSF
// holds for it. So TSF's own "Presence" tab (this website represents an Organization or a Person, its name, logo and nine profile
// links) is taken out of its Schema settings box, through TSF's `the_seo_framework_schema_settings_tabs` filter: two places for the
// same thing is what an editor gets wrong. The General and Breadcrumbs tabs stay. Nothing already saved there is deleted: a value
// TSF holds still prints when the page here is empty for it, it just cannot be edited any more. `devbase/tsf/hide_organization_fields`
// (bool, default true) keeps the tab. A site's OWN TSF (the return above) is never changed.
function devbase_tsf_hide_presence_tab( $tabs ) {
	unset( $tabs['presence'] );

	return $tabs;
}

if ( apply_filters( 'devbase/tsf/hide_organization_fields', true ) ) {
	add_filter( 'the_seo_framework_schema_settings_tabs', 'devbase_tsf_hide_presence_tab' );
}

// TSF builds the URL of its admin CSS/JS with plugin_dir_url(), which only resolves for files under
// wp-content/plugins/. Define the constant first, pointing at the real location in vendor/. TSF's own
// define() is unconditional: PHP keeps the first value and warns on the second, so ours wins.
define( 'THE_SEO_FRAMEWORK_DIR_URL', get_template_directory_uri() . '/vendor/autodescription/' );

// Silence just that one harmless "Constant already defined" warning, then restore error reporting.
$devbase_tsf_error_reporting = error_reporting( error_reporting() & ~E_WARNING );
require_once $devbase_tsf_main;
error_reporting( $devbase_tsf_error_reporting );

// The admin notices TSF shows after an install ("follow our 5-minute setup guide") and after each update ("Thank you for
// updating The SEO Framework"). TSF registers them only when its stored database version differs from the one it ships, in the
// file it has just loaded, so they are taken off here. These are TSF's internal function names, not a promised API: if a
// release renames them the removal does nothing and the notice shows once, which is harmless (Test_TSF_Loader watches for it).
if ( $devbase_tsf_hide_branding ) {
	remove_action( 'the_seo_framework_upgraded', 'The_SEO_Framework\Bootstrap\_prepare_upgrade_notice', 99 );
	remove_action( 'the_seo_framework_upgraded', 'The_SEO_Framework\Bootstrap\_prepare_upgrade_suggestion', 100 );
	remove_action( 'the_seo_framework_downgraded', 'The_SEO_Framework\Bootstrap\_prepare_downgrade_notice', 99 );
}

// TSF starts itself on `plugins_loaded` (priority 5), which has already fired: a theme loads after
// plugins do. The same class of problem as SCF's abilities hook (see ../scf/scf.php). Run the callback
// TSF registered, once, and unhook it so it can never run twice. If a TSF release renames or moves that
// callback, this stays silent in production but Test_TSF_Loader fails on the Dependabot PR, which is
// the point of that test.
if ( did_action( 'plugins_loaded' ) && false !== has_action( 'plugins_loaded', 'The_SEO_Framework\_load_tsf' ) ) {
	remove_action( 'plugins_loaded', 'The_SEO_Framework\_load_tsf', 5 );
	The_SEO_Framework\_load_tsf();
}

unset( $devbase_tsf_main, $devbase_tsf_error_reporting, $devbase_tsf_hide_branding );
