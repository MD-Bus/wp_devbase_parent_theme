<?php

// Keeps the vendored MCP Adapter (vendor/mcp-adapter/) on GitHub's latest
// release. Deliberately NOT gated through devbase-dependency-worker's
// approved-versions list like SCF/ASE in dependency-updater.php: this
// dependency isn't on the WordPress.org plugin directory at all (it only
// ships via GitHub Releases), and it's low-risk enough — a headless API
// bridge nothing else on the site depends on functioning — that going
// straight to "latest" without a manual approval step is an acceptable
// trade for one less thing to maintain. Reuses
// devbase_get_installed_version() and devbase_apply_dependency_update()
// from dependency-updater.php, which don't care where a version/URL came
// from.

define( 'DEVBASE_MCP_UPDATE_CHECK_HOOK', 'devbase_check_mcp_update' );

// Scheduling: a real recurring daily WP-Cron event, same as
// ../dependency-updater.php (read its scheduling comment for the full
// reasoning). Short version: this used to hook `admin_init`, which only
// fires in wp-admin, so on a client site nobody logs into the check simply
// never ran. `wp_cron()` is hooked to `init` by core, so a recurring event
// fires from ANY request including front-end traffic.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( DEVBASE_MCP_UPDATE_CHECK_HOOK ) ) {
		wp_schedule_event( time(), 'daily', DEVBASE_MCP_UPDATE_CHECK_HOOK );
	}
} );

add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( DEVBASE_MCP_UPDATE_CHECK_HOOK );
} );

/**
 * Human-readable summary of the last MCP Adapter check, for the System
 * Status tab. Same purpose as
 * devbase_dependency_last_check_message() — see that function for why a
 * visible last-run record matters for anything on a WP-Cron schedule.
 */
function devbase_mcp_last_check_message() {
	$last = get_option( 'devbase_mcp_last_check' );

	if ( empty( $last['time'] ) ) {
		return __( 'Never run yet.', 'devbase_parent_theme' );
	}

	$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] );

	if ( ! empty( $last['updated'] ) ) {
		$message = sprintf(
			/* translators: 1: date/time of the last check, 2: the version updated to */
			__( 'Last checked: %1$s — updated to %2$s.', 'devbase_parent_theme' ),
			$when,
			$last['updated']
		);
	} else {
		$message = sprintf(
			/* translators: %s: date/time of the last check */
			__( 'Last checked: %s — everything already current.', 'devbase_parent_theme' ),
			$when
		);
	}

	if ( ! empty( $last['error'] ) ) {
		$message .= ' ' . sprintf(
			/* translators: %s: the error encountered */
			__( '⚠ Problem: %s', 'devbase_parent_theme' ),
			$last['error']
		);
	}

	if ( ( ( time() - $last['time'] ) / HOUR_IN_SECONDS ) > 48 ) {
		$message .= ' ' . __( '⚠ Over 48 hours ago — see the note on the dependency check above.', 'devbase_parent_theme' );
	}

	return $message;
}

add_action( DEVBASE_MCP_UPDATE_CHECK_HOOK, 'devbase_run_mcp_update_check' );

function devbase_run_mcp_update_check() {
	// GitHub's API requires a User-Agent; wp_remote_get() doesn't send one
	// by default.
	$response = wp_remote_get( 'https://api.github.com/repos/WordPress/mcp-adapter/releases/latest', array(
		'timeout' => 10,
		'headers' => array( 'User-Agent' => 'devbase_parent_theme' ),
	) );

	$record = function ( $updated, $error ) {
		// Recorded on every run, including one that changed nothing —
		// "it ran and found nothing to do" is what proves the schedule is
		// still alive. See devbase_mcp_last_check_message().
		update_option(
			'devbase_mcp_last_check',
			array(
				'time'    => time(),
				'updated' => $updated,
				'error'   => $error,
			),
			false
		);
	};

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		error_log( 'devbase_parent_theme: mcp-adapter update check failed to reach GitHub' );
		$record( '', __( 'could not reach GitHub', 'devbase_parent_theme' ) );
		return;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $release['tag_name'] ) ) {
		error_log( 'devbase_parent_theme: mcp-adapter update check got a malformed GitHub response' );
		$record( '', __( 'GitHub returned a malformed response', 'devbase_parent_theme' ) );
		return;
	}

	// Releases are tagged e.g. "0.6.1", not "v0.6.1" — but strip a leading
	// "v" defensively in case that ever changes.
	$latest_version    = ltrim( $release['tag_name'], 'v' );
	$installed_version = devbase_get_installed_version( 'mcp-adapter', 'mcp-adapter.php' );

	if ( null === $installed_version ) {
		// Nothing installed to update. This updater only ever UPDATES an
		// existing copy — devbase_apply_dependency_update()'s first step
		// renames the live folder aside and fails if there isn't one. A
		// fresh install gets its starting copy from the committed vendor/
		// baseline.
		$record( '', __( 'MCP Adapter is not installed', 'devbase_parent_theme' ) );
		return;
	}

	// Forward-only, via version_compare() rather than the string equality
	// this used to use. Equality meant that if GitHub's "latest" ever
	// pointed at an OLDER release — a yanked release, or a retagged one —
	// this would dutifully downgrade the site. SCF/ASE already refused
	// downgrades in ../dependency-updater.php; this is the same rule,
	// applied consistently. "No approval gate" was always meant as "take
	// new releases without review", never "follow the tag backwards".
	if ( ! version_compare( $latest_version, $installed_version, '>' ) ) {
		$record( '', '' );
		return;
	}

	// The "latest" download link always resolves to whatever release GitHub
	// currently considers latest — the same one this check just read — so
	// no need to hunt through $release['assets'] for a specific URL.
	$download_url = 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip';

	if ( devbase_apply_dependency_update( 'mcp-adapter', 'mcp-adapter.php', $latest_version, $download_url ) ) {
		$record( $latest_version, '' );
	} else {
		/* translators: %s: version number */
		$record( '', sprintf( __( 'failed to update to %s', 'devbase_parent_theme' ), $latest_version ) );
	}
}
