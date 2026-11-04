<?php

// Renders the read-only "Status" tab on the Licence sub-page: what Warden
// last said about each theme track, recomputed on every page load.
//
// Same split as every other sub-page in this directory — settings.php
// defines fields, this file only reads and presents. And the same framing
// as inc/theme-settings/miscellaneous/'s System Status tab: a live view of
// real recorded state, never a saved setting, and never something that
// triggers a check itself. The check happens on WordPress's own update
// schedule (see inc/updates/AGENT.md); this just shows its last result.
//
// Why this tab exists at all, rather than trusting the update to appear on
// Appearance > Themes: an update that never shows up has several very
// different causes — no licence key entered, a key that's expired or
// suspended, a Warden URL typo, a signing key mismatch, or simply "you're
// already on the latest release". Those are indistinguishable from the
// themes screen, where all of them look identical to "nothing to do".
// Exactly the same reasoning as the Retention Policy tab's staleness
// indicator: without visibility you can't tell "nothing needed doing"
// apart from "this silently stopped working weeks ago".

/**
 * One <tr> per theme track, or a "not configured" note.
 */
function devbase_licence_status_html() {
	$tracks = function_exists( 'devbase_update_tracks' ) ? devbase_update_tracks() : array();

	if ( empty( $tracks ) ) {
		return '<p>' . esc_html__( 'No themes are set up for updates.', 'devbase_parent_theme' ) . '</p>';
	}

	$rows = '';

	foreach ( $tracks as $stylesheet => $licence ) {
		$rows .= devbase_licence_status_row( $stylesheet, $licence );
	}

	return devbase_relay_status_html() . '<table class="widefat striped"><thead><tr>'
		. '<th>' . esc_html__( 'Theme', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Installed version', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Licence', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Updates', 'devbase_parent_theme' ) . '</th>'
		. '</tr></thead><tbody>' . $rows . '</tbody></table>';
}

function devbase_licence_status_row( $stylesheet, $licence ) {
	$theme     = wp_get_theme( $stylesheet );
	$name      = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
	$installed = function_exists( 'devbase_update_installed_version' )
		? devbase_update_installed_version( $stylesheet )
		: null;

	if ( '' === trim( (string) $licence ) ) {
		return '<tr><td><strong>' . esc_html( $name ) . '</strong></td>'
			. '<td>' . esc_html( (string) $installed ) . '</td>'
			. '<td colspan="2"><em>' . esc_html__( 'No licence on this site, so updates are off.', 'devbase_parent_theme' ) . '</em></td></tr>';
	}

	$state    = devbase_warden_get_state( $stylesheet );
	$response = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();
	$error    = ! empty( $state['last_error'] ) ? (string) $state['last_error'] : '';

	if ( empty( $response ) ) {
		$detail = '' !== $error
			/* translators: %s: the error Warden's last check returned. */
			? sprintf( esc_html__( 'Not checked yet. The last attempt failed: %s', 'devbase_parent_theme' ), esc_html( $error ) )
			: esc_html__( 'Not checked yet.', 'devbase_parent_theme' );

		return '<tr><td><strong>' . esc_html( $name ) . '</strong></td>'
			. '<td>' . esc_html( (string) $installed ) . '</td>'
			. '<td colspan="2">' . $detail . '</td></tr>';
	}

	$status  = ! empty( $response['status'] ) ? ucfirst( (string) $response['status'] ) : '—';
	$valid   = ! empty( $response['valid'] );
	$licence_cell = ( $valid ? '✓ ' : '✗ ' ) . esc_html( $status );

	// For the developer only (the clients do not need the tag or the app's name):
	// which app on the licence this row is, and the app's name as it is kept.
	if ( ! devbase_remote_setup_hidden() ) {
		$tag      = function_exists( 'devbase_warden_tag' ) ? devbase_warden_tag( $stylesheet, $licence ) : '';
		$app_name = isset( $response['app']['name'] ) ? (string) $response['app']['name'] : '';

		$licence_cell .= '<br><small>' . ( '' !== $tag
			/* translators: 1: the tag (parent-theme or child-theme), 2: the app's name. */
			? sprintf( esc_html__( 'Tag %1$s%2$s', 'devbase_parent_theme' ), esc_html( $tag ), '' !== $app_name ? esc_html( ' · ' . $app_name ) : '' )
			: esc_html__( 'Old per-theme key, no tag — enter the single licence key.', 'devbase_parent_theme' ) ) . '</small>';
	}

	if ( ! empty( $response['expires_at'] ) ) {
		$expires = strtotime( (string) $response['expires_at'] );
		/* translators: %s: a date, e.g. 30 July 2027. */
		$licence_cell .= '<br><small>' . sprintf( esc_html__( 'Valid until %s', 'devbase_parent_theme' ), esc_html( $expires ? wp_date( get_option( 'date_format' ), $expires ) : (string) $response['expires_at'] ) ) . '</small>';
	}

	if ( ! empty( $response['message'] ) ) {
		$licence_cell .= '<br><small>' . esc_html( (string) $response['message'] ) . '</small>';
	}

	$update = ( null !== $installed && function_exists( 'devbase_warden_pending_update' ) )
		? devbase_warden_pending_update( $response, $installed )
		: null;

	if ( null !== $update ) {
		/* translators: %s: the new version number available. */
		$update_cell = sprintf( esc_html__( 'Version %s available', 'devbase_parent_theme' ), esc_html( (string) $update['version'] ) );
	} elseif ( ! $valid ) {
		$update_cell = esc_html__( 'Paused: no active licence.', 'devbase_parent_theme' );
	} else {
		$update_cell = esc_html__( 'Up to date.', 'devbase_parent_theme' );
	}

	// A stale error alongside a good cached response is worth showing: it
	// means we're running on a last-known-good answer, not a fresh one.
	if ( '' !== $error ) {
		/* translators: %s: the error the most recent check returned. */
		$update_cell .= '<br><small>' . sprintf( esc_html__( 'The last check failed (%s); showing the previous result.', 'devbase_parent_theme' ), esc_html( $error ) ) . '</small>';
	}

	return '<tr><td><strong>' . esc_html( $name ) . '</strong></td>'
		. '<td>' . esc_html( (string) $installed ) . '</td>'
		. '<td>' . $licence_cell . '</td>'
		. '<td>' . $update_cell . '</td></tr>';
}

/**
 * One line above the table, for the developer only (the Remote setup tab's
 * audience): whether this site receives its licence settings remotely, and how
 * recently. Clients see nothing here.
 */
function devbase_relay_status_html() {
	if ( ! function_exists( 'devbase_relay_enabled' ) || devbase_remote_setup_hidden() ) {
		return '';
	}

	if ( ! devbase_relay_enabled() ) {
		return '<p><strong>' . esc_html__( 'Remote setup is not complete: enter the management server address and site access key (Remote setup tab) to receive settings automatically.', 'devbase_parent_theme' ) . '</strong></p>';
	}

	$state = devbase_relay_get_state();
	$error = ! empty( $state['last_error'] ) ? (string) $state['last_error'] : '';

	if ( ! empty( $state['succeeded_at'] ) ) {
		/* translators: %s: how long ago the last successful update was, e.g. "3 hours". */
		$line = sprintf( esc_html__( 'Settings are managed remotely. Last updated %s ago.', 'devbase_parent_theme' ), esc_html( human_time_diff( (int) $state['succeeded_at'] ) ) );
	} else {
		$line = esc_html__( 'Settings are managed remotely. Not updated yet.', 'devbase_parent_theme' );
	}

	if ( '' !== $error ) {
		/* translators: %s: the error the last update returned. */
		$line .= ' ' . sprintf( esc_html__( 'The last update failed (%s); keeping the current values.', 'devbase_parent_theme' ), esc_html( $error ) );
	}

	return '<p><strong>' . $line . '</strong></p>';
}
