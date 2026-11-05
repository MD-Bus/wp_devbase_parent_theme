<?php

// Site health: the report a site sends to Brand Relay (POST /v1/site/heartbeat).
//
// It is a plan service ("health"). Brand Relay asks for it by naming `health` in a signed
// ping (see maintenance.php), so Brand Relay is the clock and the site never has to remember
// to report. devbase_maintenance_run() calls devbase_heartbeat_run() only while the plan says
// health is in force. Every answer carries `next_in`, the plan's interval in seconds, which is
// kept for the status line; a 202 means "too soon, not stored" and is a success.
//
// What is sent is versions, counts, disk figures and the results of WordPress's own Site Health
// tests (a name and WordPress's sentence for each one that did not pass). Nothing from the site's
// content, its users or its plugin files.

/**
 * The report. Fields Brand Relay does not know are ignored by it, and a field
 * that cannot be read here is left out rather than sent empty.
 *
 * @return array<string, mixed>
 */
function devbase_heartbeat_report() {
	$theme  = wp_get_theme();
	$parent = $theme->parent();

	$report = array(
		'wordpress'    => get_bloginfo( 'version' ),
		'php'          => PHP_VERSION,
		// The version Brand Relay shows is the site's own theme; the base theme's
		// goes beside it (an extra field, ignored until Brand Relay reads it).
		'theme'        => (string) $theme->get( 'Version' ),
		'parent_theme' => $parent ? (string) $parent->get( 'Version' ) : null,
		'updates'      => devbase_heartbeat_update_counts(),
		'disk_free'    => devbase_heartbeat_disk( 'disk_free_space' ),
		'disk_total'   => devbase_heartbeat_disk( 'disk_total_space' ),
		'cron_ok'      => devbase_heartbeat_cron_ok(),
		// Filled in once there is a backup to report (inc/maintenance/ will grow one).
		'last_backup_at' => apply_filters( 'devbase/maintenance/last_backup_at', null ),
		'site_health'    => devbase_heartbeat_site_health(),
	);

	$report = array_filter( $report, function ( $value ) {
		return null !== $value;
	} );

	return (array) apply_filters( 'devbase/maintenance/report', $report );
}

/**
 * The WordPress Site Health tests that run here: WordPress's own local ("direct") tests, by their id.
 * Left out on purpose: `rest_availability` (a real REST request) and `http_requests`; and the asynchronous
 * tests (loopback, background updates, WordPress.org, HTTPS status, page cache, authorization header), which
 * make real network calls and do not belong in a ping. Only WordPress's own tests: a plugin's test is code
 * we did not write, and it could be slow.
 *
 * @return string[]
 */
function devbase_heartbeat_site_health_tests() {
	return array(
		'wordpress_version', 'plugin_version', 'theme_version', 'php_version', 'php_extensions', 'php_default_timezone',
		'php_sessions', 'sql_server', 'ssl_support', 'scheduled_events', 'is_in_debug_mode', 'file_uploads',
		'plugin_theme_auto_updates', 'update_temp_backup_writable', 'available_updates_disk_space', 'autoloaded_options',
		'insecure_registration', 'search_engine_visibility', 'opcode_cache',
	);
}

/**
 * WordPress's own Site Health results, in the shape Brand Relay reads (0.37.0): the counts of every test run, and
 * the ones that did not pass (critical first), each as WordPress's test id and its own sentence, scrubbed. About 30 ms.
 *
 * Null when WordPress's Site Health class is not there or no test could run. A test that fails to run is left out
 * of the counts (a report with a missing figure is better than none).
 *
 * @return array{good: int, recommended: int, critical: int, issues: array[]}|null
 */
function devbase_heartbeat_site_health() {
	// The tests use admin-side functions (plugins, updates, the filesystem) that a ping does not load. Always asked for:
	// WordPress or another plugin may already have loaded the Site Health class, but not the files its tests rely on.
	foreach ( array( 'file', 'plugin', 'update', 'misc', 'theme', 'class-wp-site-health' ) as $include ) {
		$path = ABSPATH . 'wp-admin/includes/' . $include . '.php';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		require_once $path;
	}

	if ( ! class_exists( 'WP_Site_Health' ) ) {
		return null;
	}

	$health = WP_Site_Health::get_instance();
	$counts = array( 'good' => 0, 'recommended' => 0, 'critical' => 0 );
	$issues = array();
	$ran    = 0;

	// One test (`php_version`) asks api.wordpress.org which PHP versions are acceptable, and WordPress keeps the answer
	// for a week. A ping must never wait on it for long: while the tests run, a request gets three seconds at most.
	$cap = function ( $args ) {
		$args['timeout'] = min( (float) ( $args['timeout'] ?? 5 ), 3 );

		return $args;
	};
	add_filter( 'http_request_args', $cap );

	foreach ( devbase_heartbeat_site_health_tests() as $test ) {
		$method = 'get_test_' . $test;

		if ( ! method_exists( $health, $method ) ) {
			continue;
		}

		try {
			// What WordPress's own (private) perform_test() does: run it, then let plugins adjust the result.
			$result = apply_filters( 'site_status_test_result', $health->$method() );
		} catch ( Throwable $e ) {
			continue;
		}

		$status = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '';

		if ( ! isset( $counts[ $status ] ) ) {
			continue;
		}

		++$ran;
		++$counts[ $status ];

		if ( 'good' !== $status ) {
			$issues[] = array(
				'test'   => $test,
				'status' => $status,
				'label'  => devbase_events_scrub( wp_strip_all_tags( (string) ( $result['label'] ?? '' ) ), 200 ),
			);
		}
	}

	remove_filter( 'http_request_args', $cap );

	if ( ! $ran ) {
		return null;
	}

	usort( $issues, function ( $a, $b ) {
		return ( 'critical' === $b['status'] ) <=> ( 'critical' === $a['status'] );
	} );

	return $counts + array( 'issues' => array_slice( $issues, 0, 30 ) );
}

/**
 * How many updates are waiting, from WordPress's own saved checks. (Not
 * wp_get_update_data(): it counts only for a logged-in user who may update, and
 * this runs on a visitor's request.) The bundled WooCommerce is not in the count
 * while the theme looks after it; a waiting theme update from Warden is.
 *
 * @return array{core: int, plugins: int, themes: int}
 */
function devbase_heartbeat_update_counts() {
	$plugins = get_site_transient( 'update_plugins' );
	$themes  = get_site_transient( 'update_themes' );
	$core    = get_site_transient( 'update_core' );
	$cores   = 0;

	foreach ( is_object( $core ) && ! empty( $core->updates ) ? (array) $core->updates : array() as $update ) {
		if ( is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response ) {
			$cores = 1;
			break;
		}
	}

	return array(
		'core'    => $cores,
		'plugins' => is_object( $plugins ) && ! empty( $plugins->response ) ? count( (array) $plugins->response ) : 0,
		'themes'  => is_object( $themes ) && ! empty( $themes->response ) ? count( (array) $themes->response ) : 0,
	);
}

/**
 * Free or total disk space in bytes, or null where the host forbids reading it.
 *
 * @param string $function disk_free_space or disk_total_space.
 */
function devbase_heartbeat_disk( $function ) {
	if ( ! function_exists( $function ) ) {
		return null;
	}

	$bytes = @$function( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	return false === $bytes ? null : (int) $bytes;
}

/**
 * Whether scheduled tasks are running: no event is more than an hour overdue.
 * (With WP-Cron off and no system cron, events pile up overdue: that is the
 * answer Brand Relay wants.)
 */
function devbase_heartbeat_cron_ok() {
	$events = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();

	foreach ( is_array( $events ) ? array_keys( $events ) : array() as $when ) {
		if ( is_int( $when ) && $when < time() - HOUR_IN_SECONDS ) {
			return false;
		}
	}

	return true;
}

/**
 * Sends one report and says what happened, for the maintenance check to record.
 * Runs only while the plan says health is in force.
 *
 * @return array{status: string, detail: string, sent_at?: int, gap?: int}
 */
function devbase_heartbeat_run() {
	$response = wp_remote_post(
		devbase_relay_url() . '/v1/site/heartbeat',
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . devbase_relay_site_key(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'        => wp_json_encode( devbase_heartbeat_report() ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 'status' => 'error', 'detail' => $response->get_error_message() );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$body = is_array( $body ) ? $body : array();

	// Stored (200) or acknowledged as too soon (202): both are fine.
	if ( 200 === $code || 202 === $code ) {
		return array(
			'status'  => 'ok',
			'detail'  => '',
			'gap'     => isset( $body['next_in'] ) ? (int) $body['next_in'] : 0,
			'sent_at' => time(),
		);
	}

	// The key was refused, or the plan no longer has health although the plan we pulled
	// a moment ago said it did (Brand Relay caches that answer for 5 minutes).
	if ( 401 === $code ) {
		return array( 'status' => 'refused', 'detail' => '' );
	}

	if ( 403 === $code && 'feature_off' === ( $body['error'] ?? '' ) ) {
		return array( 'status' => 'not_in_plan', 'detail' => '' );
	}

	return array( 'status' => 'error', 'detail' => "HTTP {$code}" );
}

/**
 * The Site health line for the Remote setup tab: whether Brand Relay's reports are
 * getting through, and how recently. (The developer's view; nothing about it is shown
 * to a client.)
 */
function devbase_heartbeat_status_html() {
	if ( ! devbase_relay_enabled() ) {
		return '<p>' . esc_html__( 'Site health reports are off: enter the management server address and site access key first.', 'devbase_parent_theme' ) . '</p>';
	}

	$state   = devbase_maintenance_state();
	$service = $state['services']['health'] ?? array();
	$status  = (string) ( $service['status'] ?? '' );

	switch ( $status ) {
		case 'ok':
			$line = sprintf(
				/* translators: %s: how long ago, e.g. "3 hours". */
				esc_html__( 'Last report sent %s ago, when Brand Relay asked for it.', 'devbase_parent_theme' ),
				esc_html( human_time_diff( (int) ( $service['sent_at'] ?? time() ) ) )
			);
			break;
		case 'not_in_plan':
			$line = esc_html__( 'Not included in this site\'s plan, so no reports are sent.', 'devbase_parent_theme' );
			break;
		case 'refused':
			$line = esc_html__( 'The management server did not accept this site\'s access key.', 'devbase_parent_theme' );
			break;
		case 'error':
			/* translators: %s: the error. */
			$line = sprintf( esc_html__( 'The last report failed (%s).', 'devbase_parent_theme' ), esc_html( (string) ( $service['detail'] ?? '' ) ) );
			break;
		default:
			$line = esc_html__( 'No report sent yet: the management server has not asked for one.', 'devbase_parent_theme' );
	}

	$checked = (int) ( $state['plan_checked_at'] ?? 0 );

	if ( $checked ) {
		/* translators: %s: how long ago, e.g. "3 hours". */
		$line .= ' ' . sprintf( esc_html__( 'Plan last checked %s ago.', 'devbase_parent_theme' ), esc_html( human_time_diff( $checked ) ) );
	}

	return '<p>' . $line . '</p>';
}
