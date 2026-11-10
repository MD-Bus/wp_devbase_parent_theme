<?php

// STAGED ROLLOUT. A new release is not offered to every site the moment Warden has it: each site waits
// its own share of a window (6 hours by default) from the time the release was published, so a bad
// release can be withdrawn in Warden before most sites have taken it.
//
//   - The wait is a fixed share of the window per site and per release (a hash of the site's address,
//     the theme and the version), so the same sites are not always first and a site's place does not
//     change between two checks.
//   - It counts from the release's `uploaded_at` (Warden's), or from when this site first saw the
//     release when Warden sends none, or a time this site cannot believe (more than a day ahead).
//   - When the wait is over, Warden is asked again before anything is offered, so a release withdrawn
//     meanwhile is not installed from a 12-hour-old cached answer. If Warden cannot be reached then, the
//     cached answer stands, as everywhere else (fail-soft).
//   - A single WP-Cron event at the due time refreshes the update list and runs WordPress's unattended
//     updates, so the install does not wait for core's next twice-daily check.
//   - Skipped for an urgent release: `urgent: true` in Warden's release, or "[urgent]" in its notes.
//     The filter `devbase/updates/rollout_window` sets the window in seconds; 0 turns staging off.
//
// Also here: a version this site rolled back (inc/updates/rollback.php) is never offered again; the
// next release is.

const DEVBASE_UPDATE_ROLLOUT_OPTION = 'devbase_update_rollout';
const DEVBASE_UPDATE_ROLLOUT_HOOK   = 'devbase_update_rollout_due';

/** The rollout window in seconds (0: offered at once). */
function devbase_update_rollout_window() {
	return max( 0, (int) apply_filters( 'devbase/updates/rollout_window', 6 * HOUR_IN_SECONDS ) );
}

/** Whether a release skips the wait. */
function devbase_update_release_urgent( array $release ) {
	$urgent = ! empty( $release['urgent'] ) || false !== stripos( (string) ( $release['notes'] ?? '' ), '[urgent]' );

	return (bool) apply_filters( 'devbase/updates/release_urgent', $urgent, $release );
}

/** @return array<string, array{version:string, seen_at:int, rechecked_at:int}> */
function devbase_update_rollout_state() {
	$state = get_option( DEVBASE_UPDATE_ROLLOUT_OPTION );

	return is_array( $state ) ? $state : array();
}

/** The state of one track's newest release, started when the version is first seen. */
function devbase_update_rollout_entry( $stylesheet, $version ) {
	$state = devbase_update_rollout_state();
	$entry = $state[ $stylesheet ] ?? array();

	if ( ( $entry['version'] ?? '' ) !== (string) $version ) {
		$entry = array( 'version' => (string) $version, 'seen_at' => time(), 'rechecked_at' => 0 );
		$state[ $stylesheet ] = $entry;
		update_option( DEVBASE_UPDATE_ROLLOUT_OPTION, $state, false );
	}

	return $entry;
}

/** Saves a changed entry. */
function devbase_update_rollout_save( $stylesheet, array $entry ) {
	$state                = devbase_update_rollout_state();
	$state[ $stylesheet ] = $entry;
	update_option( DEVBASE_UPDATE_ROLLOUT_OPTION, $state, false );
}

/**
 * When this site may install a release: 0 when at once.
 *
 * @param string $stylesheet The theme.
 * @param array  $release    Warden's update.latest.
 */
function devbase_update_rollout_due_at( $stylesheet, array $release ) {
	$window = devbase_update_rollout_window();

	if ( 0 === $window || devbase_update_release_urgent( $release ) ) {
		return 0;
	}

	$entry = devbase_update_rollout_entry( $stylesheet, (string) $release['version'] );
	$start = ! empty( $release['uploaded_at'] ) ? strtotime( (string) $release['uploaded_at'] ) : false;

	if ( ! $start || $start > time() + DAY_IN_SECONDS ) {
		$start = (int) $entry['seen_at'];
	}

	$hash   = hash( 'sha256', home_url( '/' ) . '|' . $stylesheet . '|' . $release['version'] );
	$offset = (int) ( hexdec( substr( $hash, 0, 7 ) ) % $window );

	return (int) $start + $offset;
}

/**
 * The gate between Warden's offer and WordPress's update list: null holds the release back.
 *
 * @param string     $stylesheet The theme.
 * @param string     $licence    Its licence key.
 * @param string     $installed  The installed version.
 * @param array|null $release    What Warden offers (devbase_warden_pending_update()), or null.
 * @return array|null The release to offer now, or null.
 */
function devbase_update_gate( $stylesheet, $licence, $installed, $release ) {
	if ( null === $release ) {
		return null;
	}

	if ( function_exists( 'devbase_update_version_rolled_back' ) && devbase_update_version_rolled_back( $stylesheet, (string) $release['version'] ) ) {
		return null;
	}

	$due = devbase_update_rollout_due_at( $stylesheet, $release );

	if ( $due > time() ) {
		if ( ! wp_next_scheduled( DEVBASE_UPDATE_ROLLOUT_HOOK, array( $stylesheet ) ) ) {
			wp_schedule_single_event( $due + MINUTE_IN_SECONDS, DEVBASE_UPDATE_ROLLOUT_HOOK, array( $stylesheet ) );
		}

		return null;
	}

	if ( 0 === $due ) {
		return $release;
	}

	// The wait is over: ask Warden again (once per release, or once per cache period if it cannot be
	// reached), unless the answer we hold was received after the due time anyway.
	$state = devbase_warden_get_state( $stylesheet );
	$entry = devbase_update_rollout_entry( $stylesheet, (string) $release['version'] );

	if ( (int) ( $state['succeeded_at'] ?? 0 ) < $due && time() - (int) $entry['rechecked_at'] >= DEVBASE_WARDEN_CACHE_TTL ) {
		$entry['rechecked_at'] = time();
		devbase_update_rollout_save( $stylesheet, $entry );

		$response = devbase_warden_check( $stylesheet, $licence, $installed, true );
		$fresh    = $response ? devbase_warden_pending_update( $response, $installed ) : null;

		// Withdrawn, or replaced by a newer release (whose own wait starts now).
		if ( null === $fresh || (string) $fresh['version'] !== (string) $release['version'] ) {
			return null === $fresh ? null : devbase_update_gate( $stylesheet, $licence, $installed, $fresh );
		}

		$release = $fresh;
	}

	return $release;
}

/**
 * The release a track is waiting to install, and when: for the Licence status tab.
 *
 * @return array{version:string, due:int}|null
 */
function devbase_update_rollout_waiting( $stylesheet, $release ) {
	if ( ! is_array( $release ) ) {
		return null;
	}

	if ( function_exists( 'devbase_update_version_rolled_back' ) && devbase_update_version_rolled_back( $stylesheet, (string) $release['version'] ) ) {
		return null;
	}

	$due = devbase_update_rollout_due_at( $stylesheet, $release );

	return $due > time() ? array( 'version' => (string) $release['version'], 'due' => $due ) : null;
}

// The due time: refresh the theme update list (our filter now offers the release), then run
// WordPress's unattended updates, as core's own twice-daily event does.
add_action( DEVBASE_UPDATE_ROLLOUT_HOOK, function () {
	delete_site_transient( 'update_themes' );
	wp_update_themes();

	if ( apply_filters( 'devbase/updates/rollout_runs_auto_update', true ) && function_exists( 'wp_maybe_auto_update' ) ) {
		wp_maybe_auto_update();
	}
} );
