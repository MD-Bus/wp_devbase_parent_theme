<?php

// HEALTH CHECK AFTER A THEME UPDATE, WITH ROLLBACK. WordPress (6.6+) checks a plugin's unattended update
// for a fatal error and puts the old version back; it does nothing of the kind for a theme. This does it
// for our two themes, whoever started the update (unattended, Dashboard > Updates, WP-CLI):
//
//   1. Before the new files go in (upgrader_pre_install), the home page and wp-admin/admin-ajax.php are
//      loaded once: the baseline (see devbase_update_health_urls()).
//   2. After the update (upgrader_process_complete), they are loaded again.
//   3. If the second load ends in a fatal error, and either the first did not or the error is in the
//      updated theme's own files, the previous version is put back from the copy WordPress itself keeps
//      during an update (upgrade-temp-backup/, restore_temp_backup()). Nothing is copied by us.
//
// The page is loaded the way WordPress checks plugins: a loopback request carrying a scrape key, which
// gets past maintenance mode and makes the page end with the last PHP error as JSON between two markers
// (wp_start_scraping_edited_file_errors()). A request that fails, or a page without the markers (a page
// cache answered), is "unknown", and unknown never rolls back: a host that blocks loopback requests
// would otherwise undo every update.
//
// A rolled-back version is remembered and never offered again (devbase_update_gate() in rollout.php);
// the next release is. It is recorded (the last 10), said on the update screen itself (Dashboard > Updates
// otherwise ends on WordPress's own "updated successfully"), shown to administrators for a week, on the
// Licence status tab, and in the health report as `theme_rollbacks`.
//
// Tried end to end on a throwaway site (2026-11-10, a fake Warden signing with a test key, everything else
// real): a release whose functions.php fatals was put back both by the unattended updater (from wp-cron.php)
// and from Dashboard > Updates; a release that broke wp-admin only was not, which is why admin-ajax.php is
// checked as well now.
//
// The check runs in the PHP process that did the update, so it is the OLD version's code that runs it.
// It protects updates from the first release that contains it onwards.

const DEVBASE_UPDATE_ROLLBACK_OPTION = 'devbase_update_rollbacks';

/** @return array{baseline: array<string, string>, log: array<int, array>, bad: array<string, string[]>} */
function devbase_update_rollback_state() {
	$state = get_option( DEVBASE_UPDATE_ROLLBACK_OPTION );
	$state = is_array( $state ) ? $state : array();

	return array(
		'baseline' => isset( $state['baseline'] ) && is_array( $state['baseline'] ) ? $state['baseline'] : array(),
		'log'      => isset( $state['log'] ) && is_array( $state['log'] ) ? array_values( $state['log'] ) : array(),
		'bad'      => isset( $state['bad'] ) && is_array( $state['bad'] ) ? $state['bad'] : array(),
	);
}

function devbase_update_rollback_save( array $state ) {
	update_option( DEVBASE_UPDATE_ROLLBACK_OPTION, $state, false );
}

/** Whether this version of a theme was rolled back here. */
function devbase_update_version_rolled_back( $stylesheet, $version ) {
	$bad = devbase_update_rollback_state()['bad'][ $stylesheet ] ?? array();

	return in_array( (string) $version, (array) $bad, true );
}

/** Our themes among those an upgrade is about. */
function devbase_update_rollback_themes( $hook_extra ) {
	if ( ! is_array( $hook_extra ) || ( isset( $hook_extra['type'] ) && 'theme' !== $hook_extra['type'] ) ) {
		return array();
	}

	$themes = array();

	if ( ! empty( $hook_extra['theme'] ) ) {
		$themes[] = (string) $hook_extra['theme'];
	}

	if ( ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
		$themes = array_merge( $themes, array_map( 'strval', $hook_extra['themes'] ) );
	}

	return array_values( array_intersect( array_unique( $themes ), array_keys( devbase_update_tracks() ) ) );
}

/**
 * The pages the check loads. The home page, and wp-admin/admin-ajax.php: it loads the whole admin side
 * (is_admin(), admin_init) without a login, so a release that breaks only wp-admin is caught too. Seen on a
 * throwaway site (2026-11-10): such a release passed a home-page-only check and left the administrator on an
 * HTTP 500 at /wp-admin/, unable even to reach Dashboard > Updates. Every handler the themes put on admin_init
 * is a no-op for an anonymous AJAX request (each checks a capability or skips AJAX), and admin-ajax.php is
 * reached anonymously by every visitor's browser anyway. `devbase/updates/health_check_urls` (string[]).
 *
 * @return string[]
 */
function devbase_update_health_urls() {
	return array_values( array_filter( (array) apply_filters( 'devbase/updates/health_check_urls', array( home_url( '/' ), admin_url( 'admin-ajax.php' ) ) ), 'is_string' ) );
}

/**
 * Loads each page of devbase_update_health_urls() and reports the worst: a fatal error on any page is fatal
 * (and stops there), else a page that could not be checked is unknown, else ok.
 *
 * @return array{result: string, error: string, file: string, url: string} result is ok, fatal or unknown.
 */
function devbase_update_health_check() {
	$pre = apply_filters( 'devbase/updates/health_check', null );

	if ( is_array( $pre ) ) {
		return $pre + array( 'result' => 'unknown', 'error' => '', 'file' => '', 'url' => '' );
	}

	$worst = array( 'result' => 'ok', 'error' => '', 'file' => '', 'url' => '' );

	foreach ( devbase_update_health_urls() as $url ) {
		$check = devbase_update_health_load( $url ) + array( 'url' => $url );

		if ( 'fatal' === $check['result'] ) {
			return $check;
		}

		if ( 'unknown' === $check['result'] && 'ok' === $worst['result'] ) {
			$worst = $check;
		}
	}

	return $worst;
}

/**
 * Loads one page of this site the way WordPress checks an edited plugin, and reports how it ended.
 *
 * @param string $page The page's URL.
 * @return array{result: string, error: string, file: string}
 */
function devbase_update_health_load( $page ) {
	// During maintenance mode, the scrape key must be the one WordPress derives from it (load.php).
	// Read, not included: WordPress writes one PHP line setting $upgrading to a timestamp.
	$upgrading = null;
	$file      = ABSPATH . '.maintenance';

	if ( is_readable( $file ) && preg_match( '/\$upgrading\s*=\s*(\d+)/', (string) file_get_contents( $file ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$upgrading = (int) $m[1];
	}

	if ( null !== $upgrading ) {
		$key   = md5( (string) $upgrading );
		$nonce = (string) $upgrading;
	} else {
		$key   = md5( wp_generate_password( 32, false ) );
		$nonce = wp_generate_password( 20, false );
	}

	set_transient( 'scrape_key_' . $key, $nonce, 60 );

	$url       = add_query_arg( array( 'wp_scrape_key' => $key, 'wp_scrape_nonce' => $nonce ), $page );
	$sslverify = apply_filters( 'https_local_ssl_verify', false, $url );
	$headers   = array( 'Cache-Control' => 'no-cache' );

	if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
		$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	$response = wp_remote_get( $url, array( 'timeout' => 30, 'sslverify' => $sslverify, 'headers' => $headers ) );

	delete_transient( 'scrape_key_' . $key );

	return devbase_update_health_read( $response, $key );
}

/**
 * Reads a scrape response.
 *
 * @param array|WP_Error $response The loopback response.
 * @param string         $key      The scrape key.
 */
function devbase_update_health_read( $response, $key ) {
	if ( is_wp_error( $response ) ) {
		return array( 'result' => 'unknown', 'error' => $response->get_error_message(), 'file' => '' );
	}

	$body  = (string) wp_remote_retrieve_body( $response );
	$start = "###### wp_scraping_result_start:$key ######";
	$end   = "###### wp_scraping_result_end:$key ######";
	$at    = strpos( $body, $start );

	if ( false === $at ) {
		// No markers: the page never reached WordPress (a cache, a proxy) or died before the scrape began.
		$code = (int) wp_remote_retrieve_response_code( $response );

		return array( 'result' => 'unknown', 'error' => "HTTP {$code}, no scrape result", 'file' => '' );
	}

	$json   = substr( $body, $at + strlen( $start ) );
	$json   = trim( substr( $json, 0, (int) strpos( $json, $end ) ) );
	$result = json_decode( $json, true );

	if ( is_array( $result ) && isset( $result['type'] ) ) {
		return array( 'result' => 'fatal', 'error' => (string) ( $result['message'] ?? '' ), 'file' => (string) ( $result['file'] ?? '' ) );
	}

	if ( is_array( $result ) && isset( $result['code'] ) ) {
		return array( 'result' => 'unknown', 'error' => (string) $result['code'], 'file' => '' );
	}

	return array( 'result' => 'ok', 'error' => '', 'file' => '' );
}

/** Whether a fatal error's file (relative to ABSPATH, as the scrape gives it) is inside a theme. */
function devbase_update_error_in_theme( $file, $stylesheet ) {
	if ( '' === (string) $file ) {
		return false;
	}

	$root = trailingslashit( wp_normalize_path( get_theme_root( $stylesheet ) ) ) . $stylesheet . '/';
	$path = wp_normalize_path( (string) $file );
	$path = 0 === strpos( $path, '/' ) ? $path : wp_normalize_path( ABSPATH ) . $path;

	return 0 === strpos( $path, $root );
}

// 1. The baseline, before the new files go in.
add_filter( 'upgrader_pre_install', function ( $response, $hook_extra ) {
	$themes = devbase_update_rollback_themes( $hook_extra );

	if ( ! $themes || is_wp_error( $response ) || ! apply_filters( 'devbase/updates/rollback_enabled', true ) ) {
		return $response;
	}

	$state = devbase_update_rollback_state();
	$check = devbase_update_health_check();

	foreach ( $themes as $stylesheet ) {
		$state['baseline'][ $stylesheet ] = $check['result'];
	}

	devbase_update_rollback_save( $state );

	return $response;
}, 10, 2 );

// 2 and 3. After the update.
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {
	if ( ! is_array( $hook_extra ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
		return;
	}

	foreach ( devbase_update_rollback_themes( $hook_extra ) as $stylesheet ) {
		devbase_update_after( $upgrader, $stylesheet );
	}
}, 20, 2 );

/**
 * Checks one updated theme and rolls it back when the update broke the home page or wp-admin.
 *
 * @param WP_Upgrader $upgrader   The upgrader that ran (its temp backup is the previous version).
 * @param string      $stylesheet The theme.
 * @return string ok, unknown, rolled_back, rollback_failed or skipped.
 */
function devbase_update_after( $upgrader, $stylesheet ) {
	$state    = devbase_update_rollback_state();
	$baseline = $state['baseline'][ $stylesheet ] ?? null;
	unset( $state['baseline'][ $stylesheet ] );
	devbase_update_rollback_save( $state );

	// No baseline: the update was not prepared by this code (switched off, or started before it loaded).
	if ( null === $baseline ) {
		return 'skipped';
	}

	wp_clean_themes_cache();
	$to = (string) wp_get_theme( $stylesheet )->get( 'Version' );

	// Give OPcache (validate_timestamps with revalidate_freq) the time to see the new files, as core does.
	$wait = (int) apply_filters( 'devbase/updates/health_check_delay', 2 );
	if ( $wait > 0 ) {
		sleep( $wait );
	}

	$check = devbase_update_health_check();

	if ( 'fatal' !== $check['result'] ) {
		return $check['result'];
	}

	// Already broken before the update, by something else: putting the old theme back would not help.
	if ( 'fatal' === $baseline && ! devbase_update_error_in_theme( $check['file'], $stylesheet ) ) {
		return 'skipped';
	}

	$restored = ( is_object( $upgrader ) && method_exists( $upgrader, 'restore_temp_backup' ) )
		? $upgrader->restore_temp_backup( array( array( 'dir' => 'themes', 'slug' => $stylesheet, 'src' => get_theme_root( $stylesheet ) ) ) )
		: new WP_Error( 'no_restore', 'This WordPress cannot restore a theme update (6.6 or later is needed).' );

	wp_clean_themes_cache();
	$from   = (string) wp_get_theme( $stylesheet )->get( 'Version' );
	$ok     = true === $restored;
	$result = $ok ? 'rolled_back' : 'rollback_failed';

	$state                       = devbase_update_rollback_state();
	$state['bad'][ $stylesheet ] = array_slice( array_values( array_unique( array_merge( (array) ( $state['bad'][ $stylesheet ] ?? array() ), array( $to ) ) ) ), -10 );

	array_unshift( $state['log'], array(
		'time'   => time(),
		'theme'  => $stylesheet,
		'to'     => $to,
		'from'   => $ok ? $from : '',
		'result' => $result,
		'error'  => function_exists( 'devbase_events_scrub' ) ? devbase_events_scrub( $check['error'], 300 ) : substr( $check['error'], 0, 300 ),
		'file'   => function_exists( 'devbase_events_scrub' ) ? devbase_events_scrub( $check['file'], 200 ) : substr( $check['file'], 0, 200 ),
	) );
	$state['log'] = array_slice( $state['log'], 0, 10 );

	devbase_update_rollback_save( $state );

	error_log( sprintf(
		'devbase_parent_theme: %s %s broke %s (%s); %s',
		$stylesheet,
		$to,
		false !== strpos( (string) ( $check['url'] ?? '' ), 'admin-ajax.php' ) ? 'wp-admin' : 'the home page',
		$check['error'],
		$ok ? "version {$from} was put back" : 'the previous version could not be put back: ' . ( is_wp_error( $restored ) ? $restored->get_error_message() : 'unknown error' )
	) );

	// Said on the update screen too (Dashboard > Updates prints the skin's feedback; the unattended updater's
	// skin keeps it), which otherwise ends on WordPress's own "updated successfully".
	$skin = is_object( $upgrader ) && property_exists( $upgrader, 'skin' ) ? $upgrader->skin : null;

	if ( is_object( $skin ) && method_exists( $skin, 'feedback' ) ) {
		$skin->feedback( esc_html( devbase_update_rollback_message( $state['log'][0] ) ) );
	}

	// A release that broke the site is an error for Brand Relay too (the site itself was put right, or could not be).
	if ( function_exists( 'devbase_events_report' ) ) {
		devbase_events_report(
			'update',
			$ok ? 'A theme update broke the site and was rolled back: ' . $stylesheet : 'A theme update broke the site and could not be rolled back: ' . $stylesheet,
			array( 'version' => $to, 'reason' => $state['log'][0]['error'] ),
			$ok ? 'warning' : 'error'
		);
	}

	do_action( 'devbase/updates/rolled_back', $stylesheet, $to, $ok, $check );

	return $result;
}

/**
 * What an administrator is told about one rollback (an entry of the log).
 *
 * @param array $e The log entry.
 */
function devbase_update_rollback_message( array $e ) {
	$theme = wp_get_theme( (string) ( $e['theme'] ?? '' ) );
	$name  = $theme->exists() ? $theme->get( 'Name' ) : (string) ( $e['theme'] ?? '' );

	return 'rolled_back' === ( $e['result'] ?? '' )
		/* translators: 1: theme name, 2: the new version, 3: the version put back. */
		? sprintf( __( '%1$s %2$s broke the site after it was installed, so version %3$s was put back. %2$s will not be installed again.', 'devbase_parent_theme' ), $name, $e['to'] ?? '', $e['from'] ?? '' )
		/* translators: 1: theme name, 2: the new version. */
		: sprintf( __( '%1$s %2$s broke the site after it was installed, and the previous version could not be put back. The site needs attention.', 'devbase_parent_theme' ), $name, $e['to'] ?? '' );
}

/** The rollbacks of the last $days days, newest first. */
function devbase_update_recent_rollbacks( $days = 7 ) {
	$since = time() - (int) $days * DAY_IN_SECONDS;

	return array_values( array_filter( devbase_update_rollback_state()['log'], function ( $e ) use ( $since ) {
		return (int) ( $e['time'] ?? 0 ) >= $since;
	} ) );
}

// Told to administrators for a week.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	foreach ( devbase_update_recent_rollbacks( 7 ) as $e ) {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', 'rolled_back' === $e['result'] ? 'warning' : 'error', esc_html( devbase_update_rollback_message( $e ) ) );
	}
} );

// In the health report.
add_filter( 'devbase/maintenance/report', function ( $report ) {
	$recent = devbase_update_recent_rollbacks( 30 );

	if ( $recent ) {
		$report['theme_rollbacks'] = array_map( function ( $e ) {
			return array(
				'at'     => gmdate( 'c', (int) $e['time'] ),
				'theme'  => (string) $e['theme'],
				'to'     => (string) $e['to'],
				'from'   => (string) $e['from'],
				'result' => (string) $e['result'],
				'error'  => (string) $e['error'],
			);
		}, $recent );
	}

	return $report;
} );
