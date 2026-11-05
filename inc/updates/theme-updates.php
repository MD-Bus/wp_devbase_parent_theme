<?php

// Delivers updates for this theme — and for the active child theme — from
// Warden, through WordPress's OWN theme-update pipeline.
//
// Why core's pipeline rather than a swap of our own: swapping the directory
// of the theme that is currently executing would mean reimplementing
// maintenance mode, filesystem credentials (hosts that need FTP), and
// failure rollback — all of which Theme_Upgrader already does, with core's
// own rollback since WP 6.3. So
// this file does exactly one thing: tell WordPress an update exists and
// where to get it. Core downloads, extracts, swaps and rolls back.
//
// The payoff is that everything downstream comes free: the "Update
// available" notice on Appearance > Themes, the Dashboard > Updates
// screen, WP-CLI `wp theme update`, and genuine unattended auto-updates on
// core's own twice-daily cron.
//
// TWO TRACKS, DETECTED NOT DECLARED. There are exactly two themes in this
// model and WordPress already knows which is which — get_template() is the
// parent, get_stylesheet() is the child (they're equal when no child is
// active). So neither theme has to register itself, and a child can't
// misregister itself. devbase_update_tracks() is still filterable for a
// non-standard setup, but nothing needs to use it.

/**
 * Which themes we deliver updates for, as
 *   stylesheet (directory name) => licence key.
 *
 * Both tracks carry the SAME key (the site's single licence key); what tells
 * Warden which theme is meant is the tag, parent-theme or child-theme, added when the
 * track is checked (devbase_warden_tag()).
 *
 * A track with an empty licence key is skipped entirely — an unlicensed or
 * not-yet-configured site simply never sees an update offer, rather than
 * erroring.
 */
function devbase_update_tracks() {
	$tracks = array(
		get_template() => devbase_warden_parent_licence(),
	);

	if ( is_child_theme() ) {
		$tracks[ get_stylesheet() ] = devbase_warden_child_licence();
	}

	$tracks = apply_filters( 'devbase/updates/tracks', $tracks );

	return is_array( $tracks ) ? $tracks : array();
}

/**
 * The installed version of a theme, read from its own style.css header.
 * This is what gets sent to Warden as `version` and what any offered
 * update is compared against.
 */
function devbase_update_installed_version( $stylesheet ) {
	$theme = wp_get_theme( $stylesheet );

	if ( ! $theme->exists() ) {
		return null;
	}

	$version = $theme->get( 'Version' );

	return ! empty( $version ) ? (string) $version : null;
}

/**
 * Injects Warden's answer into the theme-update transient.
 *
 * Hooked on pre_set_site_transient_update_themes, which core fires right
 * after its own wordpress.org update check. Our themes aren't on
 * wordpress.org, so core never has anything to say about them and there's
 * nothing to override — we're only ever adding entries.
 *
 * Note this filter can fire several times per request. The actual network
 * call is rate-limited to once per DEVBASE_WARDEN_CACHE_TTL inside
 * devbase_warden_check(), so extra invocations are cheap option reads.
 */
function devbase_inject_theme_updates( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	foreach ( devbase_update_tracks() as $stylesheet => $licence ) {
		if ( '' === trim( (string) $licence ) ) {
			continue;
		}

		$installed = devbase_update_installed_version( $stylesheet );

		if ( null === $installed ) {
			continue;
		}

		$response = devbase_warden_check( $stylesheet, $licence, $installed );

		// The policy decision of whether a response is still trusted enough
		// to install from lives here; warden.php only decides what the
		// response IS. A response we're serving from cache because Warden
		// can't be reached stops authorising updates once it's older than
		// the offer window — see devbase_warden_offer_window() for the
		// licence-deleted scenario that closes.
		$update = ( $response && devbase_warden_offer_window( $stylesheet ) )
			? devbase_warden_pending_update( $response, $installed )
			: null;

		if ( null === $update ) {
			// Listing the theme under no_update is what makes WordPress
			// show its auto-update toggle on Appearance > Themes at all —
			// a theme absent from both lists is treated as unmanaged and
			// gets no toggle, which would make the auto-update setting
			// below invisible and apparently broken.
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			$transient->no_update[ $stylesheet ] = array(
				'theme'        => $stylesheet,
				'new_version'  => $installed,
				'url'          => '',
				'package'      => '',
				'requires'     => '',
				'requires_php' => '',
			);

			continue;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $stylesheet ] = array(
			'theme'        => $stylesheet,
			'new_version'  => (string) $update['version'],
			'url'          => '',
			'package'      => (string) $update['download_url'],
			'requires'     => '',
			'requires_php' => '',
		);
	}

	return $transient;
}
add_filter( 'pre_set_site_transient_update_themes', 'devbase_inject_theme_updates' );

/**
 * Opts our two themes into core's unattended auto-updates. Always on: the point
 * of this whole system is that a client site keeps itself current without anyone
 * remembering to log in, so there is deliberately no setting to turn it off (it
 * used to be a field on Theme Settings > Licence; a stale stored value is ignored).
 *
 * Returns $update untouched for every other theme, so this never changes
 * auto-update behaviour for anything but ours.
 */
function devbase_enable_theme_auto_updates( $update, $item ) {
	$stylesheet = is_object( $item ) && isset( $item->theme ) ? $item->theme : '';

	if ( '' === $stylesheet || ! array_key_exists( $stylesheet, devbase_update_tracks() ) ) {
		return $update;
	}

	return true;
}
add_filter( 'auto_update_theme', 'devbase_enable_theme_auto_updates', 10, 2 );

/**
 * The release a package address belongs to: one of our tracks whose last signed Warden answer
 * offers exactly this download_url. Null for any other package (another theme, a plugin, core),
 * which this file leaves alone.
 *
 * @param string $package The package URL WordPress is about to download.
 * @param string $theme   The theme being updated, when WordPress says (hook_extra['theme']).
 * @return array|null The release (Warden's update.latest).
 */
function devbase_update_release_for_package( $package, $theme = '' ) {
	foreach ( devbase_update_tracks() as $stylesheet => $licence ) {
		if ( '' !== $theme && $theme !== $stylesheet ) {
			continue;
		}

		$state     = devbase_warden_get_state( $stylesheet );
		$response  = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();
		$installed = devbase_update_installed_version( $stylesheet );

		if ( ! $response || null === $installed ) {
			continue;
		}

		$release = devbase_warden_pending_update( $response, $installed );

		if ( null !== $release && (string) $release['download_url'] === (string) $package ) {
			return $release;
		}
	}

	return null;
}

/**
 * Checks a downloaded package against its release: the file's SHA-256 must equal the `sha256` of
 * the signed Warden answer, and the release `signature` (Ed25519 over that hex text, Warden's
 * docs/releases.md "Verifying a package") must verify with a trusted key.
 *
 * @param string $file    The downloaded file.
 * @param array  $release Warden's update.latest.
 * @return true|WP_Error
 */
function devbase_update_verify_package( $file, array $release ) {
	$expected = strtolower( trim( (string) ( $release['sha256'] ?? '' ) ) );

	if ( ! preg_match( '/^[0-9a-f]{64}$/', $expected ) ) {
		return new WP_Error( 'devbase_package_unverified', __( 'Warden gave this release no checksum, so it was not installed.', 'devbase_parent_theme' ) );
	}

	$actual = hash_file( 'sha256', $file );

	if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
		return new WP_Error( 'devbase_package_mismatch', __( 'The downloaded theme package does not match its published checksum, so it was not installed.', 'devbase_parent_theme' ) );
	}

	if ( ! devbase_warden_verify_any( (string) ( $release['signature'] ?? '' ), $expected, devbase_warden_trusted_keys() ) ) {
		return new WP_Error( 'devbase_package_signature', __( 'The theme package\'s signature could not be verified, so it was not installed.', 'devbase_parent_theme' ) );
	}

	return true;
}

/**
 * Downloads our own theme packages here instead of in core, so they can be verified before
 * Theme_Upgrader unpacks them. Without this, WordPress installs whatever bytes the download
 * address returns: the signed answer said WHERE to download, but nothing checked WHAT came back.
 *
 * @param false|string|WP_Error $reply    False to let core download.
 * @param string                $package  The package URL.
 * @param WP_Upgrader           $upgrader The upgrader.
 * @param array                 $hook_extra Extra arguments (WordPress 5.5+), with 'theme'.
 * @return false|string|WP_Error The verified local file, an error, or $reply untouched.
 */
function devbase_verify_theme_package_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
	if ( false !== $reply || ! is_string( $package ) || '' === $package ) {
		return $reply;
	}

	$theme   = is_array( $hook_extra ) && ! empty( $hook_extra['theme'] ) ? (string) $hook_extra['theme'] : '';
	$release = devbase_update_release_for_package( $package, $theme );

	if ( null === $release ) {
		return $reply;
	}

	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$file = download_url( $package, 300 );

	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$verified = devbase_update_verify_package( $file, $release );

	if ( is_wp_error( $verified ) ) {
		wp_delete_file( $file );
		error_log( 'devbase_parent_theme: theme package refused — ' . $verified->get_error_message() );

		return $verified;
	}

	return $file;
}
add_filter( 'upgrader_pre_download', 'devbase_verify_theme_package_download', 10, 4 );
