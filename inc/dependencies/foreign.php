<?php

// Is a dependency ALREADY running on this site, installed by someone else as a real plugin?
//
// The theme carries its own copies of Secure Custom Fields, Admin Site Enhancements, the MCP
// Adapter and The SEO Framework in vendor/ and loads them itself. If the site already has one of them as an ordinary
// plugin (a client installed it, or the host did), loading ours too would fight it: our asset URL
// overrides would break theirs, our ASE defaults would be written over their settings, a second copy
// would redeclare classes. So when one is already there we use THEIRS: the theme's loader for that
// dependency does nothing, and nothing the theme does to its own copy (hiding SCF's screens, the ASE
// defaults) touches it.
//
// This has to be asked BEFORE the theme loads its own copies (afterwards the answer is always yes), so
// it is evaluated once, here, right when this file loads: plugins load before the theme, so anything a
// site activated is already defined by now.

/**
 * Which dependencies a plugin of the site already provides: key => bool.
 *
 * @return array<string, bool>
 */
function devbase_foreign_dependencies() {
	static $found = null;

	if ( null === $found ) {
		$found = array(
			// ACF or SCF, any edition: their main class. (false: never trigger an autoloader to ask.)
			'scf' => class_exists( 'ACF', false ) || defined( 'ACF_VERSION' ),
			// ASE defines its version the moment its main file loads.
			'ase' => defined( 'ASENHA_VERSION' ),
			// The MCP Adapter's own main class.
			'mcp' => class_exists( 'WP\MCP\Plugin', false ),
			// TSF defines its version the moment its main file loads.
			'tsf' => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
		);
	}

	return (array) apply_filters( 'devbase/dependencies/foreign', $found );
}

/** Whether the site's own copy of a dependency (scf, ase or mcp) is the one running. */
function devbase_dependency_is_foreign( $key ) {
	return ! empty( devbase_foreign_dependencies()[ $key ] );
}

devbase_foreign_dependencies(); // Ask now, before the theme loads its own copies.
