<?php

// STAGED ROLLOUT. A new release is not offered to every site the moment Warden has it: each site waits
// its own share of a window (6 hours by default) from the time the release was published, so a bad
// release can be withdrawn in Warden before most sites have taken it.
//
//   - The wait is a fixed share of the window per site and per release (a hash of the site's address,
//     the theme and the version), so the same sites are not always first and a site's place does not
//     change between two checks.
//   - It counts from the release's `released_at` (Warden's; `uploaded_at` is read too), or from when this site first saw the
//     release when Warden sends none, or a time this site cannot believe (more than a day ahead).
//   - When the wait is over, Warden is asked again before anything is offered, so a release withdrawn
//     meanwhile is not installed from a 12-hour-old cached answer. If Warden cannot be reached then, the
//     cached answer stands, as everywhere else (fail-soft).
//   - A single WP-Cron event at the due time refreshes the update list and runs WordPress's unattended
//     updates, so the install does not wait for core's next twice-daily check.
//   - Skipped for an urgent release: `urgent: true` in Warden's release, or "[urgent]" in its release notes (its `changelog`).
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
	// Warden's check answer carries the public release notes as `changelog` (its internal `notes` never leave Warden), so
	// "[urgent]" is looked for in both.
	$urgent = ! empty( $release['urgent'] )
		|| false !== stripos( (string) ( $release['changelog'] ?? '' ), '[urgent]' )
		|| false !== stripos( (string) ( $release['notes'] ?? '' ), '[urgent]' );

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

	// An administrator pressed "Make available now" for this very version (the entry starts afresh with each new version).
	if ( ! empty( $entry['released_now'] ) ) {
		return 0;
	}

	// Warden's check answer says `released_at`; `uploaded_at` is what its file list says.
	$when  = (string) ( $release['released_at'] ?? $release['uploaded_at'] ?? '' );
	$start = '' !== $when ? strtotime( $when ) : false;

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

// --- Make available now ---------------------------------------------------------------------------------
//
// An administrator can skip the wait for THIS site and THIS release: a button on the Licence status tab, there only while
// Theme Settings > Miscellaneous > Management > "Allow Make available now" is on (off by default, so a client's administrator
// does not bypass the staging unless it was handed to them). It marks the release as released here, refreshes the update list,
// and sends the administrator to Dashboard > Updates, where the update is now offered like any other. It installs nothing by itself.

/** Whether the "Make available now" button is offered. */
function devbase_update_make_available_enabled() {
	return (bool) apply_filters( 'devbase/updates/make_available_enabled', devbase_switch_enabled( 'allow_make_available' ) );
}

/** The address of the button (a GET with a nonce, like the form Resend link). */
function devbase_update_make_available_url( $stylesheet ) {
	return wp_nonce_url(
		add_query_arg( array( 'action' => 'devbase_make_available', 'theme' => $stylesheet ), admin_url( 'admin-post.php' ) ),
		'devbase_make_available_' . $stylesheet
	);
}

/**
 * Skips the wait for the release this track is waiting on .
 *
 * @return string|null The version released, or null when nothing is waiting.
 */
function devbase_update_make_available( $stylesheet ) {
	$state     = devbase_warden_get_state( $stylesheet );
	$response  = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();
	$installed = devbase_update_installed_version( $stylesheet );
	$release   = ( $response && null !== $installed ) ? devbase_warden_pending_update( $response, $installed ) : null;

	if ( null === $release || null === devbase_update_rollout_waiting( $stylesheet, $release ) ) {
		return null;
	}

	$entry                 = devbase_update_rollout_entry( $stylesheet, (string) $release['version'] );
	$entry['released_now'] = time();
	devbase_update_rollout_save( $stylesheet, $entry );

	wp_clear_scheduled_hook( DEVBASE_UPDATE_ROLLOUT_HOOK, array( $stylesheet ) );
	delete_site_transient( 'update_themes' );
	wp_update_themes();

	return (string) $release['version'];
}

add_action( 'admin_post_devbase_make_available', function () {
	$stylesheet = isset( $_GET['theme'] ) ? sanitize_text_field( wp_unslash( $_GET['theme'] ) ) : '';

	check_admin_referer( 'devbase_make_available_' . $stylesheet );

	if ( ! current_user_can( 'update_themes' ) || ! devbase_update_make_available_enabled() ) {
		wp_die( esc_html__( 'You are not allowed to release this update.', 'devbase_parent_theme' ), 403 );
	}

	devbase_update_make_available( $stylesheet );

	wp_safe_redirect( admin_url( 'update-core.php' ) );
	exit;
} );

// The due time: refresh the theme update list (our filter now offers the release), then run
// WordPress's unattended updates, as core's own twice-daily event does.
add_action( DEVBASE_UPDATE_ROLLOUT_HOOK, function () {
	delete_site_transient( 'update_themes' );
	wp_update_themes();

	if ( apply_filters( 'devbase/updates/rollout_runs_auto_update', true ) && function_exists( 'wp_maybe_auto_update' ) ) {
		wp_maybe_auto_update();
	}
} );
