<?php

// Loads Admin Site Enhancements from vendor/, same pattern as inc/dependencies/scf.php.
// ASE computes its asset URL/path with plugins_url()/plugin_dir_path(),
// which only resolves correctly for files under wp-content/plugins/. Since
// it's loaded from the theme's vendor/ folder instead, pre-define its own
// constants (ASENHA_URL, ASENHA_PATH) before requiring it — ASE's own
// define() calls are unconditional, but PHP keeps the first-defined value
// and just emits a harmless warning on the second, so ours wins.
if ( file_exists( get_template_directory() . '/vendor/admin-site-enhancements/admin-site-enhancements.php' ) ) {
	define( 'ASENHA_URL', get_template_directory_uri() . '/vendor/admin-site-enhancements/' );
	define( 'ASENHA_PATH', get_template_directory() . '/vendor/admin-site-enhancements/' );

	// ASE's own (now-skipped) define() calls would otherwise emit a harmless
	// "Constant already defined" E_WARNING for both constants above — silence
	// just that, not error reporting generally, then restore it immediately.
	$previous_error_reporting = error_reporting( error_reporting() & ~E_WARNING );
	require_once get_template_directory() . '/vendor/admin-site-enhancements/admin-site-enhancements.php';
	error_reporting( $previous_error_reporting );
}
