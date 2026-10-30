<?php

// AJAX handler for the "Check for Updates Now" button on Theme Settings >
// Licence > Status (see settings.php for the button itself, check.js for
// the click handling).
//
// Nothing on a client site needs this. The Warden check already runs on
// its own schedule. This button exists for US: to verify a Warden licence
// or a freshly-published release immediately, instead of waiting up to 12
// hours (the Warden cache) to find out whether it worked. (Until 1.7.0 it
// also ran the daily dependency update checks; those are gone, see
// inc/dependencies/bundled-versions.php.)
//
// Same framing as SMTP Settings' "Send Test Email" and the Retention
// Policy's "Run Purge Now": it calls exactly the same functions the cron
// jobs call, so a success here is real evidence about the scheduled path
// and not a separate code route that might behave differently.

add_action( 'wp_ajax_devbase_check_updates', function () {
	check_ajax_referer( 'devbase_check_updates' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'devbase_parent_theme' ) ) );
	}

	$lines = array();

	// 0. Pull the licence settings from Brand Relay first, so the checks below
	//    run against the newest keys. A no-op when it isn't configured.
	if ( devbase_relay_enabled() ) {
		$sync    = devbase_relay_sync( false );
		$lines[] = $sync['ok']
			? esc_html__( 'Brand Relay: synced.', 'devbase_parent_theme' )
			/* translators: %s: the error the sync returned. */
			: sprintf( esc_html__( 'Brand Relay: sync failed (%s).', 'devbase_parent_theme' ), $sync['error'] );
	}

	// 1. Warden, per theme track, FORCED past the 12-hour cache. A "check
	//    now" that hands back a cached answer would be worse than no
	//    button at all — it would look like confirmation while telling you
	//    nothing about the current state.
	foreach ( devbase_update_tracks() as $stylesheet => $licence ) {
		$theme = wp_get_theme( $stylesheet );
		$name  = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;

		if ( '' === trim( (string) $licence ) ) {
			/* translators: %s: theme name */
			$lines[] = sprintf( esc_html__( '%s: no licence key entered.', 'devbase_parent_theme' ), $name );
			continue;
		}

		$installed = devbase_update_installed_version( $stylesheet );

		if ( null === $installed ) {
			/* translators: %s: theme name */
			$lines[] = sprintf( esc_html__( '%s: could not read an installed version.', 'devbase_parent_theme' ), $name );
			continue;
		}

		$response = devbase_warden_check( $stylesheet, $licence, $installed, true );

		if ( empty( $response ) ) {
			$state = devbase_warden_get_state( $stylesheet );
			$error = ! empty( $state['last_error'] ) ? $state['last_error'] : __( 'no response', 'devbase_parent_theme' );

			/* translators: 1: theme name, 2: the error returned */
			$lines[] = sprintf( esc_html__( '%1$s: check failed — %2$s', 'devbase_parent_theme' ), $name, $error );
			continue;
		}

		$update = devbase_warden_pending_update( $response, $installed );

		if ( null !== $update ) {
			/* translators: 1: theme name, 2: installed version, 3: available version */
			$lines[] = sprintf(
				esc_html__( '%1$s: %3$s available (installed %2$s).', 'devbase_parent_theme' ),
				$name,
				$installed,
				$update['version']
			);
		} elseif ( empty( $response['valid'] ) ) {
			/* translators: 1: theme name, 2: licence status reported by Warden */
			$lines[] = sprintf(
				esc_html__( '%1$s: licence not valid (%2$s) — updates blocked.', 'devbase_parent_theme' ),
				$name,
				! empty( $response['status'] ) ? $response['status'] : '—'
			);
		} else {
			/* translators: 1: theme name, 2: installed version */
			$lines[] = sprintf( esc_html__( '%1$s: up to date (%2$s).', 'devbase_parent_theme' ), $name, $installed );
		}
	}

	// 2. Make the result visible where WordPress itself shows it. Without
	//    this, a newly-found update would sit in our own state but not
	//    appear on Appearance > Themes or Dashboard > Updates until core's
	//    own twice-daily check happened to run — which looks exactly like
	//    the button having done nothing.
	delete_site_transient( 'update_themes' );
	wp_update_themes();

	wp_send_json_success( array( 'message' => implode( "\n", $lines ) ) );
} );
