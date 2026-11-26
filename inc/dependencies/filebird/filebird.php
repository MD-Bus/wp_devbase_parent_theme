<?php

// Loads FileBird (free, by Ninja Team) from vendor/, same pattern as ../tsf/tsf.php: folders for the Media Library, with
// FileBird's own interface, its folder gallery block and its own settings page (Settings > FileBird). See ./AGENT.md.
//
// OFF BY DEFAULT: Theme Settings > Miscellaneous > Media > "Enable FileBird". Off, nothing of FileBird is loaded at all.
// FileBird keeps its folders in its own two tables ({prefix}fbv, {prefix}fbv_attachment_folder), so turning it off and on
// again, or a site moving between this copy and FileBird installed as a plugin, keeps every folder.
//
// FileBird was tried once before as a real plugin in wp-content/plugins/ and dropped (inc/dependencies/AGENT.md): it
// starts on `plugins_loaded`, which has already fired when a theme loads. Here it is started by hand, as TSF and SCF are.

/**
 * Whether the theme's FileBird runs: the "Enable FileBird" switch (raw option: this is asked while the theme loads, before
 * ACF has registered a field). `devbase/filebird/enabled` (bool) can decide it in code (the test suite turns it on there).
 */
function devbase_filebird_enabled() {
	return (bool) apply_filters( 'devbase/filebird/enabled', (bool) (int) get_option( 'options_enable_filebird', 0 ) );
}

/**
 * Whether FileBird's own page (the FileBird menu: its settings, import and export) is kept out of the admin menu: the "Hide
 * FileBird menu" switch, ON until someone saves it off. Hidden is not blocked: the page still opens by its address. Only the
 * theme's own copy; a FileBird the site installed itself keeps its menu. `devbase/filebird/hide_menu` (bool) decides in code.
 */
function devbase_filebird_menu_hidden() {
	return (bool) apply_filters( 'devbase/filebird/hide_menu', devbase_switch_on( 'hide_filebird_menu' ) && ! devbase_dependency_is_foreign( 'filebird' ) );
}

// The site already runs FileBird (or FileBird Pro) as a plugin: use that one and load nothing of ours (see ../foreign.php).
if ( devbase_dependency_is_foreign( 'filebird' ) ) {
	return;
}

if ( ! devbase_filebird_enabled() ) {
	return;
}

$devbase_filebird_main = get_template_directory() . '/vendor/filebird/filebird.php';

if ( ! file_exists( $devbase_filebird_main ) ) {
	return;
}

// FileBird builds every asset URL from NJFB_PLUGIN_URL, which plugin_dir_url() would compute wrongly outside
// wp-content/plugins/. FileBird only defines it when it is not defined yet, so defining it first is all it takes.
if ( ! defined( 'NJFB_PLUGIN_URL' ) ) {
	define( 'NJFB_PLUGIN_URL', get_template_directory_uri() . '/vendor/filebird/' );
}

// FileBird ships "recommended modules": advertising for its sister plugin YayMail on WooCommerce's email settings. They
// register on `plugins_loaded`, so from the theme they would never load anyway, but their registry writes two "late
// register()" lines to the PHP error log on every request when it sees that. An empty registry, loaded first, takes its
// place: the modules are skipped without a word. A site running a Yay plugin of its own already has the real registry, and
// it is left alone.
if ( ! class_exists( 'YayRecommendedModules\Registry', false ) ) {
	require_once __DIR__ . '/recommended-modules-registry.php';
}

require_once $devbase_filebird_main;

// FileBird starts itself on `plugins_loaded`, which has already fired. Run the callback it registered, once, and unhook
// it. Its first run creates the tables and records the version (FileBird's own Plugin::prepareRun(), which also upgrades
// them after a newer FileBird ships), so there is no separate activation step. If a release renames the callback, this
// stays silent in production but Test_FileBird_Loader fails on the Dependabot PR.
if ( did_action( 'plugins_loaded' ) && false !== has_action( 'plugins_loaded', 'FileBird\init' ) ) {
	// Never run on this site: create the tables first, as its activation hook would for a plugin. Its first run backs up
	// the tables before creating them, which would otherwise query two tables that do not exist yet.
	if ( false === get_option( 'fbv_version' ) && is_callable( array( 'FileBird\Install', 'create_tables' ) ) ) {
		FileBird\Install::create_tables();
	}

	remove_action( 'plugins_loaded', 'FileBird\init' );
	FileBird\init();
}

// The FileBird menu, taken out late so FileBird has added it (it does on admin_menu at the default priority).
add_action( 'admin_menu', function () {
	if ( devbase_filebird_menu_hidden() ) {
		remove_menu_page( 'filebird-settings' );
	}
}, 999 );

// FileBird registers its gallery block on every `init` without asking whether it is registered already, so anything that
// fires `init` a second time (the test suite does) gets a "Block type is already registered" notice. Its callback is
// swapped for one that asks first, at the same priority.
$devbase_filebird_block_priority = has_action( 'init', 'filebird_gallery_block_assets' );

if ( false !== $devbase_filebird_block_priority ) {
	remove_action( 'init', 'filebird_gallery_block_assets', $devbase_filebird_block_priority );
	add_action( 'init', function () {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'filebird/block-filebird-gallery' ) ) {
			filebird_gallery_block_assets();
		}
	}, $devbase_filebird_block_priority );
}

// No review requests or "deactivation feedback" form on a client's site. Through FileBird's own switch for the review
// (its "already answered" value) and by removing the feedback form's two hooks. A child theme keeps them with
// `add_filter( 'devbase/filebird/hide_promotions', '__return_false' )`. A site's OWN FileBird (the return above) is never changed.
if ( apply_filters( 'devbase/filebird/hide_promotions', true ) ) {
	add_filter( 'pre_option_fbv_review', function () {
		return '0';
	} );

	devbase_filebird_remove_hooks( 'FileBird\Classes\Feedback', array( 'admin_enqueue_scripts', 'admin_footer' ) );
}

/**
 * Removes the callbacks an object of this class added to these hooks (FileBird's classes add methods of `new` objects,
 * which remove_action() cannot name).
 *
 * @param string   $class The class whose methods are taken off.
 * @param string[] $hooks Where to look.
 * @return int How many were removed.
 */
function devbase_filebird_remove_hooks( $class, array $hooks ) {
	global $wp_filter;

	$removed = 0;

	foreach ( $hooks as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_array( $function ) && is_object( $function[0] ) && $function[0] instanceof $class ) {
					remove_action( $hook, $function, $priority );
					++$removed;
				}
			}
		}
	}

	return $removed;
}

unset( $devbase_filebird_main, $devbase_filebird_block_priority );
