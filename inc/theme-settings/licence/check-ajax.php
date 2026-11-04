<?php

// AJAX handler for the "Check licence and updates now" button on Theme Settings >
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

/**
 * What the plan says about the plugins that follow it, and what was just done about it: one
 * line per plugin tied to a plan service ("WPvivid Backup: ..."). Runs the same sync that
 * runs on a wp-admin load, so the install, the deactivation and the removal happen now
 * and the result is shown, instead of waiting for the next page load.
 *
 * @return string[] Plain-text lines (the caller escapes nothing further: they are built from translated strings and names).
 */
function devbase_licence_plugin_lines() {
	$lines   = array();
	$plugins = devbase_bundled_plugins();

	$results = devbase_bundled_plugins_pending() ? devbase_sync_bundled_plugins() : array();

	foreach ( $plugins as $slug => $def ) {
		if ( ! devbase_bundled_plugin_is_service_bound( $def ) ) {
			continue;
		}

		$name       = $def['name'];
		$in_force   = devbase_relay_service_in_force( $def['service'] );
		$active     = devbase_bundled_plugin_is_active( $def['file'] );
		$version    = devbase_installed_plugin_version( $slug );
		$result     = $results[ $slug ] ?? '';

		if ( is_wp_error( $result ) ) {
			/* translators: 1: plugin name, 2: the problem. */
			$lines[] = sprintf( __( '%1$s: problem (%2$s).', 'devbase_parent_theme' ), $name, $result->get_error_message() );
			continue;
		}

		if ( 'busy' === $result ) {
			/* translators: %s: plugin name. */
			$lines[] = sprintf( __( '%s: a backup or restore is running, so nothing was changed. Check again when it is done.', 'devbase_parent_theme' ), $name );
		} elseif ( $in_force ) {
			$what = array(
				'installed'   => __( 'installed and activated now', 'devbase_parent_theme' ),
				'upgraded'    => __( 'updated now', 'devbase_parent_theme' ),
				'upgrading'   => __( 'will be updated at the end of this request', 'devbase_parent_theme' ),
				'activated'   => __( 'activated now', 'devbase_parent_theme' ),
			);

			if ( isset( $what[ $result ] ) ) {
				/* translators: 1: plugin name, 2: what was done, such as "installed and activated now". */
				$lines[] = sprintf( __( '%1$s: included in this site\'s plan, %2$s.', 'devbase_parent_theme' ), $name, $what[ $result ] );
			} elseif ( $active ) {
				/* translators: 1: plugin name, 2: its version. */
				$lines[] = sprintf( __( '%1$s: included in this site\'s plan, active (version %2$s).', 'devbase_parent_theme' ), $name, $version );
			} else {
				/* translators: %s: plugin name. */
				$lines[] = sprintf( __( '%s: included in this site\'s plan, but not active (see the notice at the top of the Plugins page).', 'devbase_parent_theme' ), $name );
			}
		} elseif ( 'removed' === $result ) {
			/* translators: %s: plugin name. */
			$lines[] = sprintf( __( '%s: not included in this site\'s plan, deactivated and removed now.', 'devbase_parent_theme' ), $name );
		} elseif ( '' === $version ) {
			/* translators: %s: plugin name. */
			$lines[] = sprintf( __( '%s: not included in this site\'s plan, so it is not installed.', 'devbase_parent_theme' ), $name );
		} else {
			/* translators: %s: plugin name. */
			$lines[] = sprintf( __( '%s: not included in this site\'s plan, but it was not installed by the theme, so it was left alone.', 'devbase_parent_theme' ), $name );
		}
	}

	return $lines;
}

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
			? esc_html__( 'Remote settings: updated.', 'devbase_parent_theme' )
			/* translators: %s: the error the sync returned. */
			: sprintf( esc_html__( 'Remote settings: update failed (%s).', 'devbase_parent_theme' ), $sync['error'] );
	}

	// 0b. The plan Brand Relay just sent decides which plugins this site carries (WPvivid
	//     Backup, while Backups is in force). Apply it now and say what happened.
	if ( devbase_relay_enabled() ) {
		$lines = array_merge( $lines, devbase_licence_plugin_lines() );
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
			$lines[] = sprintf( esc_html__( '%s: no licence, so updates are off.', 'devbase_parent_theme' ), $name );
			continue;
		}

		$installed = devbase_update_installed_version( $stylesheet );

		if ( null === $installed ) {
			/* translators: %s: theme name */
			$lines[] = sprintf( esc_html__( '%s: could not read the installed version.', 'devbase_parent_theme' ), $name );
			continue;
		}

		$response = devbase_warden_check( $stylesheet, $licence, $installed, true );

		if ( empty( $response ) ) {
			$state = devbase_warden_get_state( $stylesheet );
			$error = ! empty( $state['last_error'] ) ? $state['last_error'] : __( 'no response', 'devbase_parent_theme' );

			/* translators: 1: theme name, 2: the error returned */
			$lines[] = sprintf( esc_html__( '%1$s: the check failed (%2$s).', 'devbase_parent_theme' ), $name, $error );
			continue;
		}

		$update = devbase_warden_pending_update( $response, $installed );

		if ( null !== $update ) {
			/* translators: 1: theme name, 2: installed version, 3: available version */
			$lines[] = sprintf(
				esc_html__( '%1$s: version %3$s available (you have %2$s).', 'devbase_parent_theme' ),
				$name,
				$installed,
				$update['version']
			);
		} elseif ( empty( $response['valid'] ) ) {
			/* translators: 1: theme name, 2: licence status reported by Warden */
			$lines[] = sprintf(
				esc_html__( '%1$s: no active licence (%2$s), so updates are paused.', 'devbase_parent_theme' ),
				$name,
				! empty( $response['status'] ) ? $response['status'] : '—'
			);
		} else {
			/* translators: 1: theme name, 2: installed version */
			$lines[] = sprintf( esc_html__( '%1$s: up to date (version %2$s).', 'devbase_parent_theme' ), $name, $installed );
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
