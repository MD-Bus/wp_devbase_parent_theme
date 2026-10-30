<?php

// The vendored dependencies (SCF, ASE, MCP Adapter, Timber, Twig) change in
// ONE place only: a parent theme release. Dependabot (.github/dependabot.yml)
// opens a pull request when SCF, ASE, Timber or Twig has a new version, and a
// daily workflow (.github/workflows/mcp-adapter-release.yml) opens an issue
// for a new MCP Adapter release; the developer tests, commits vendor/ and
// releases. Sites then run exactly the versions the release shipped and
// bin/release.sh tested.
//
// Until 1.7.0 each site also updated SCF, ASE and the MCP Adapter itself, daily
// (dependency-updater.php, mcp-adapter/mcp-adapter-updater.php). Removed:
// because a theme update replaces the whole theme folder, any release carrying
// an older copy than a site had fetched for itself downgraded that plugin on
// install (1.6.0 did exactly that), and a site could run a plugin version that
// was never tested with the theme. See inc/dependencies/AGENT.md.

/**
 * The bundled versions, for the System Status tab: name => version (or an
 * em dash when a copy can't be read).
 *
 * @return array<string, string>
 */
function devbase_bundled_versions() {
	$vendor  = get_template_directory() . '/vendor/';
	$plugins = array(
		'Secure Custom Fields'    => 'secure-custom-fields/secure-custom-fields.php',
		'Admin Site Enhancements' => 'admin-site-enhancements/admin-site-enhancements.php',
		'MCP Adapter'             => 'mcp-adapter/mcp-adapter.php',
	);

	$versions = array();

	foreach ( $plugins as $name => $file ) {
		$header            = is_readable( $vendor . $file ) ? get_file_data( $vendor . $file, array( 'Version' => 'Version' ) ) : array();
		$versions[ $name ] = ! empty( $header['Version'] ) ? $header['Version'] : '—';
	}

	$versions['Timber'] = class_exists( 'Timber\Timber' ) && ! empty( Timber\Timber::$version ) ? Timber\Timber::$version : '—';

	return $versions;
}

/** One line for the System Status tab. */
function devbase_bundled_versions_message() {
	$parts = array();

	foreach ( devbase_bundled_versions() as $name => $version ) {
		$parts[] = $name . ' ' . $version;
	}

	return implode( ', ', $parts );
}

// Sites that ran 1.6.x or earlier still have the two daily events scheduled and
// their last-run records stored. Nothing handles the events any more, so they
// would fire as no-ops forever; clear them once. Cheap on every later request:
// wp_next_scheduled() reads the already-loaded cron option.
add_action( 'init', function () {
	foreach ( array( 'devbase_check_dependency_updates', 'devbase_check_mcp_update' ) as $hook ) {
		if ( wp_next_scheduled( $hook ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	foreach ( array( 'devbase_dependency_last_check', 'devbase_mcp_last_check' ) as $option ) {
		if ( false !== get_option( $option ) ) {
			delete_option( $option );
		}
	}
} );
