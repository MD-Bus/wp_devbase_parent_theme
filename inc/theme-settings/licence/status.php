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
		return '<p>' . esc_html__( 'No theme tracks are configured.', 'devbase_parent_theme' ) . '</p>';
	}

	$rows = '';

	foreach ( $tracks as $stylesheet => $licence ) {
		$rows .= devbase_licence_status_row( $stylesheet, $licence );
	}

	return devbase_relay_status_html() . '<table class="widefat striped"><thead><tr>'
		. '<th>' . esc_html__( 'Theme', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Installed', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Licence', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Update', 'devbase_parent_theme' ) . '</th>'
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
			. '<td colspan="2"><em>' . esc_html__( 'No licence key entered — update checks are off for this theme.', 'devbase_parent_theme' ) . '</em></td></tr>';
	}

	$state    = devbase_warden_get_state( $stylesheet );
	$response = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();
	$error    = ! empty( $state['last_error'] ) ? (string) $state['last_error'] : '';

	if ( empty( $response ) ) {
		$detail = '' !== $error
			/* translators: %s: the error Warden's last check returned. */
			? sprintf( esc_html__( 'Never checked successfully. Last error: %s', 'devbase_parent_theme' ), esc_html( $error ) )
			: esc_html__( 'Not checked yet.', 'devbase_parent_theme' );

		return '<tr><td><strong>' . esc_html( $name ) . '</strong></td>'
			. '<td>' . esc_html( (string) $installed ) . '</td>'
			. '<td colspan="2">' . $detail . '</td></tr>';
	}

	$status  = ! empty( $response['status'] ) ? (string) $response['status'] : '—';
	$valid   = ! empty( $response['valid'] );
	$licence_cell = ( $valid ? '✓ ' : '✗ ' ) . esc_html( $status );

	if ( ! empty( $response['expires_at'] ) ) {
		/* translators: %s: an ISO-8601 expiry date, as Warden returned it. */
		$licence_cell .= '<br><small>' . sprintf( esc_html__( 'Expires %s', 'devbase_parent_theme' ), esc_html( (string) $response['expires_at'] ) ) . '</small>';
	}

	if ( ! empty( $response['message'] ) ) {
		$licence_cell .= '<br><small>' . esc_html( (string) $response['message'] ) . '</small>';
	}

	$update = ( null !== $installed && function_exists( 'devbase_warden_pending_update' ) )
		? devbase_warden_pending_update( $response, $installed )
		: null;

	if ( null !== $update ) {
		/* translators: %s: the new version number available. */
		$update_cell = sprintf( esc_html__( '%s available', 'devbase_parent_theme' ), esc_html( (string) $update['version'] ) );
	} elseif ( ! $valid ) {
		$update_cell = esc_html__( 'Blocked — licence is not valid.', 'devbase_parent_theme' );
	} else {
		$update_cell = esc_html__( 'Up to date.', 'devbase_parent_theme' );
	}

	// A stale error alongside a good cached response is worth showing: it
	// means we're running on a last-known-good answer, not a fresh one.
	if ( '' !== $error ) {
		/* translators: %s: the error the most recent check returned. */
		$update_cell .= '<br><small>' . sprintf( esc_html__( 'Last check failed (%s) — showing the previous result.', 'devbase_parent_theme' ), esc_html( $error ) ) . '</small>';
	}

	return '<tr><td><strong>' . esc_html( $name ) . '</strong></td>'
		. '<td>' . esc_html( (string) $installed ) . '</td>'
		. '<td>' . $licence_cell . '</td>'
		. '<td>' . $update_cell . '</td></tr>';
}

/**
 * One line above the table saying where the licence settings come from, so a
 * key that changed by itself isn't a mystery.
 */
function devbase_relay_status_html() {
	if ( ! function_exists( 'devbase_relay_enabled' ) ) {
		return '';
	}

	if ( ! devbase_relay_enabled() ) {
		return '<p><strong>' . esc_html__( 'The Brand Relay URL and site key are not both entered, so nothing is being provisioned.', 'devbase_parent_theme' ) . '</strong></p>';
	}

	$state = devbase_relay_get_state();
	$error = ! empty( $state['last_error'] ) ? (string) $state['last_error'] : '';

	if ( ! empty( $state['succeeded_at'] ) ) {
		/* translators: %s: how long ago the last successful sync was, e.g. "3 hours". */
		$line = sprintf( esc_html__( 'Licence settings are provisioned from Brand Relay. Last synced %s ago.', 'devbase_parent_theme' ), esc_html( human_time_diff( (int) $state['succeeded_at'] ) ) );
	} else {
		$line = esc_html__( 'Licence settings are provisioned from Brand Relay. Not synced yet.', 'devbase_parent_theme' );
	}

	if ( '' !== $error ) {
		/* translators: %s: the error the last sync returned. */
		$line .= ' ' . sprintf( esc_html__( 'Last sync failed (%s); keeping the current values.', 'devbase_parent_theme' ), esc_html( $error ) );
	}

	return '<p><strong>' . $line . '</strong></p>';
}
