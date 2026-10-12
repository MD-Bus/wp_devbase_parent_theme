<?php

// Keeps the vendored dependencies (SCF, ASE) up to date straight from
// WordPress.org — always whatever that plugin's current stable release is.
//
// There is deliberately NO approval gate here. An earlier version of this
// file checked every candidate version against a curated allow-list served
// by a small external Cloudflare Worker (devbase-dependency-worker), so a
// release only rolled out once it had been explicitly tested and approved.
// That whole mechanism — the Worker, its access key, the multi-URL
// failover list, the approved-version picker and the rollback-by-
// disapproval behaviour — was removed when this theme moved to a
// parent/child model with Warden-delivered theme updates. Version control
// now happens in one place instead of two: what ships inside a parent
// theme release. Nothing here needs its own second opinion.
//
// Practical consequence worth knowing: a bad SCF or ASE release now
// reaches sites on the next daily check with nothing standing in its way.
// The mitigation is the same one mcp-adapter-updater.php has always relied
// on (see mcp-adapter/AGENT.md) — pin a known-good copy in the parent
// theme's committed vendor/ baseline and ship it via Warden, which
// overwrites vendor/ wholesale on update.
//
// Design (unchanged from the gated version):
// - Checked at most once/day, gated on a stored timestamp rather than a
//   recurring wp_schedule_event — a low-traffic site might not get a
//   request for days, and a recurring cron event just silently never
//   fires until something visits anyway. Checking the timestamp on
//   admin_init (a guaranteed real visit) is more reliable.
// - The actual network call is deferred to a single wp-cron event fired
//   immediately, so it doesn't block the admin page load that triggered it.
// - Swaps are staged: download and extract to a temp folder, sanity-check
//   the main file is present, then swap directories via rename() (atomic
//   on the same filesystem) with a rollback path if the swap itself fails
//   — rather than deleting vendor/<slug> before the replacement is known-good.

define( 'DEVBASE_DEPENDENCY_CHECK_HOOK', 'devbase_check_dependency_updates' );

/**
 * WordPress.org slug (as used in vendor/<slug>/ and the download URL) =>
 * main plugin file, relative to that folder, used to sanity-check an
 * extracted package before swapping it in.
 */
function devbase_managed_dependencies() {
	return array(
		'secure-custom-fields'    => 'secure-custom-fields.php',
		'admin-site-enhancements' => 'admin-site-enhancements.php',
	);
}

/**
 * The current stable version WordPress.org publishes for a plugin slug,
 * via its public plugin-information API. Returns null on any failure —
 * callers treat that as "skip this dependency this time" rather than
 * guessing, so a WordPress.org outage never triggers a bogus swap.
 */
function devbase_get_latest_wporg_version( $slug ) {
	$url = add_query_arg(
		array(
			'action'            => 'plugin_information',
			'request[slug]'     => $slug,
			'request[fields][]' => 'version',
		),
		'https://api.wordpress.org/plugins/info/1.2/'
	);

	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		error_log( "devbase_parent_theme: could not reach WordPress.org for {$slug}" );
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) || empty( $data['version'] ) ) {
		error_log( "devbase_parent_theme: WordPress.org returned no version for {$slug}" );
		return null;
	}

	return (string) $data['version'];
}

/**
 * Currently installed version of a dependency, read directly from the
 * plugin's own file header — not Composer's installed.json. Our own swap
 * (devbase_apply_dependency_update()) replaces the files directly via
 * rename() and never touches Composer's bookkeeping, so installed.json
 * would go stale the moment this updater does its job; the file header is
 * the one source that's always accurate regardless of how the files got
 * there (Composer, or this updater).
 */
function devbase_get_installed_version( $slug, $main_file ) {
	$plugin_file = get_template_directory() . '/vendor/' . $slug . '/' . $main_file;

	if ( ! file_exists( $plugin_file ) ) {
		return null;
	}

	$data = get_file_data( $plugin_file, array( 'Version' => 'Version' ) );

	return ! empty( $data['Version'] ) ? $data['Version'] : null;
}

// Scheduling: a real recurring daily WP-Cron event, the same mechanism
// WordPress core itself uses for `wp_update_plugins`/`wp_update_themes`.
//
// This used to be an `admin_init` hook gated on a stored timestamp, with a
// deliberate comment explaining why a recurring event was avoided ("a
// low-traffic site might go days without a visit, so a recurring cron
// event just silently never fires until something triggers WP-Cron
// anyway"). That reasoning was wrong in an important way, and it mattered
// in practice: BOTH approaches need a page load, but they count DIFFERENT
// page loads. `admin_init` only fires in wp-admin (and admin-ajax.php —
// confirmed, wp-admin/admin-ajax.php does `do_action('admin_init')`), so
// the check only ever ran when someone logged into the back end. Most
// client sites get front-end traffic daily and an admin visit maybe once
// a quarter, which meant dependency updates effectively stopped happening
// on exactly the sites that most needed them.
//
// A recurring event is strictly better here: `wp_cron()` is hooked to
// `init` by core (wp-includes/default-filters.php), so ANY request —
// front-end included — spawns the cron runner and fires whatever is due.
// It also keeps working on hosts that set DISABLE_WP_CRON and point a real
// system cron at wp-cron.php, which the admin_init version did not benefit
// from at all.
//
// Still not a guarantee: a site with literally zero traffic and no system
// cron will not check. That's inherent to WP-Cron being pseudo-cron, which
// is exactly why the last-run record below exists.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( DEVBASE_DEPENDENCY_CHECK_HOOK ) ) {
		// time() as the first run, so a fresh install checks on its very
		// next request rather than waiting a full day.
		wp_schedule_event( time(), 'daily', DEVBASE_DEPENDENCY_CHECK_HOOK );
	}
} );

// Unschedule when this theme is deactivated — same "no persistent state
// left behind" bar as inc/forms/retention.php. switch_theme fires for ANY
// theme deactivation, but unscheduling is harmless either way; the init
// hook above reschedules it if this theme becomes active again.
add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( DEVBASE_DEPENDENCY_CHECK_HOOK );
} );

add_action( DEVBASE_DEPENDENCY_CHECK_HOOK, 'devbase_run_dependency_check' );

/**
 * Human-readable summary of the last dependency check, for the System
 * Status tab (inc/theme-settings/miscellaneous/).
 *
 * Exists for the same reason the retention policy has one: WP-Cron only
 * fires on a page load, so without a visible record there is no way to
 * tell "everything is already current" apart from "this silently stopped
 * running weeks ago" — and the latter now matters more, because the whole
 * point of moving off admin_init was to keep this alive on sites nobody
 * logs into.
 */
function devbase_dependency_last_check_message() {
	$last = get_option( 'devbase_dependency_last_check' );

	if ( empty( $last['time'] ) ) {
		return __( 'Never run yet.', 'devbase_parent_theme' );
	}

	$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] );

	if ( ! empty( $last['updated'] ) ) {
		$parts = array();
		foreach ( $last['updated'] as $slug => $version ) {
			$parts[] = $slug . ' → ' . $version;
		}

		$message = sprintf(
			/* translators: 1: date/time of the last check, 2: comma-separated list of "slug → version" */
			__( 'Last checked: %1$s — updated %2$s.', 'devbase_parent_theme' ),
			$when,
			implode( ', ', $parts )
		);
	} else {
		$message = sprintf(
			/* translators: %s: date/time of the last check */
			__( 'Last checked: %s — everything already current.', 'devbase_parent_theme' ),
			$when
		);
	}

	if ( ! empty( $last['errors'] ) ) {
		$message .= ' ' . sprintf(
			/* translators: %s: comma-separated list of errors */
			__( '⚠ Problems: %s', 'devbase_parent_theme' ),
			implode( '; ', $last['errors'] )
		);
	}

	$age_hours = ( time() - $last['time'] ) / HOUR_IN_SECONDS;

	if ( $age_hours > 48 ) {
		$message .= ' ' . __( '⚠ Over 48 hours ago. This runs on a daily WP-Cron event, which only fires when the site gets a request — if this keeps growing, either the site has no traffic at all or WP-Cron is not running (common on hosts that disable it in favour of a real system cron). Point a real cron job at wp-cron.php if so.', 'devbase_parent_theme' );
	}

	return $message;
}

function devbase_run_dependency_check() {
	$updated = array();
	$errors  = array();

	foreach ( devbase_managed_dependencies() as $slug => $main_file ) {
		$installed = devbase_get_installed_version( $slug, $main_file );

		// Nothing installed at all: this updater only ever UPDATES an
		// existing copy — devbase_apply_dependency_update()'s first step
		// renames the live folder aside, which fails immediately if there
		// is no live folder. A fresh install gets its starting copy from
		// the parent theme's committed vendor/ baseline instead.
		if ( null === $installed ) {
			continue;
		}

		$latest = devbase_get_latest_wporg_version( $slug );

		if ( null === $latest ) {
			/* translators: %s: plugin slug */
			$errors[] = sprintf( __( 'could not resolve a version for %s', 'devbase_parent_theme' ), $slug );
			continue;
		}

		// Only ever move forward. version_compare() rather than a string
		// comparison so e.g. 6.9.10 correctly beats 6.9.9.
		if ( ! version_compare( $latest, $installed, '>' ) ) {
			continue;
		}

		$download_url = "https://downloads.wordpress.org/plugin/{$slug}.{$latest}.zip";

		if ( devbase_apply_dependency_update( $slug, $main_file, $latest, $download_url ) ) {
			$updated[ $slug ] = $latest;
		} else {
			/* translators: 1: plugin slug, 2: version */
			$errors[] = sprintf( __( 'failed to update %1$s to %2$s', 'devbase_parent_theme' ), $slug, $latest );
		}
	}

	// Recorded on every run, including a run that changed nothing — "it ran
	// and found nothing to do" is exactly what proves the schedule is still
	// alive, and is indistinguishable from "it never ran" without this.
	update_option(
		'devbase_dependency_last_check',
		array(
			'time'    => time(),
			'updated' => $updated,
			'errors'  => $errors,
		),
		false
	);
}

/**
 * Downloads and swaps one vendored dependency into place.
 *
 * Generic over the source: the slug/main-file/version/URL are all passed
 * in, so mcp-adapter/mcp-adapter-updater.php reuses this unchanged with a
 * GitHub Releases URL instead of a WordPress.org one.
 *
 * @return bool True on a completed swap, false on any failure (each of
 *              which is error_log()'d with its specific cause). Callers
 *              record this so the System Status tab can distinguish
 *              "nothing needed doing" from "it tried and failed".
 */
function devbase_apply_dependency_update( $slug, $main_file, $version, $download_url ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	$tmp_zip = download_url( $download_url, 30 );

	if ( is_wp_error( $tmp_zip ) ) {
		error_log( "devbase_parent_theme: failed to download {$slug} {$version} — " . $tmp_zip->get_error_message() );
		return false;
	}

	WP_Filesystem();

	$vendor_dir = get_template_directory() . '/vendor';
	$extract_to = $vendor_dir . '/.tmp-' . $slug;

	if ( file_exists( $extract_to ) ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $extract_to, true );
	}

	$unzip_result = unzip_file( $tmp_zip, $extract_to );
	wp_delete_file( $tmp_zip );

	if ( is_wp_error( $unzip_result ) ) {
		error_log( "devbase_parent_theme: failed to extract {$slug} {$version} — " . $unzip_result->get_error_message() );
		return false;
	}

	// The zip contains the folder itself (e.g. secure-custom-fields/...),
	// so the extracted new version lives at $extract_to/$slug.
	$extracted_package = $extract_to . '/' . $slug;

	if ( ! file_exists( $extracted_package . '/' . $main_file ) ) {
		error_log( "devbase_parent_theme: extracted {$slug} {$version} is missing its main file, aborting swap" );
		global $wp_filesystem;
		$wp_filesystem->delete( $extract_to, true );
		return false;
	}

	$live_path   = $vendor_dir . '/' . $slug;
	$backup_path = $vendor_dir . '/.backup-' . $slug;

	if ( file_exists( $backup_path ) ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $backup_path, true );
	}

	// Staged swap: move the live folder aside, move the new one in. If the
	// second rename fails, restore the backup so the site keeps working on
	// the old (still functional) version rather than being left with
	// neither.
	if ( ! rename( $live_path, $backup_path ) ) {
		error_log( "devbase_parent_theme: could not move current {$slug} aside, aborting swap" );
		global $wp_filesystem;
		$wp_filesystem->delete( $extract_to, true );
		return false;
	}

	if ( ! rename( $extracted_package, $live_path ) ) {
		error_log( "devbase_parent_theme: failed to move new {$slug} {$version} into place, rolling back" );
		rename( $backup_path, $live_path );
		global $wp_filesystem;
		$wp_filesystem->delete( $extract_to, true );
		return false;
	}

	global $wp_filesystem;
	$wp_filesystem->delete( $backup_path, true );
	$wp_filesystem->delete( $extract_to, true );

	error_log( "devbase_parent_theme: updated {$slug} to {$version}" );

	return true;
}
