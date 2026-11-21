<?php

// Remote updates. When Brand Relay's signed ping names `updates` (and the plan has the `updates`
// service in force), the site sends WordPress's version and every installed plugin, each with the
// update WordPress itself offers for it, to Brand Relay (POST /v1/site/updates): step 1, the inventory.
// See ROADMAP.md item 11 and inc/maintenance/AGENT.md.
//
// UPDATE NOW (step 2): Brand Relay's ANSWER may carry `apply`, a list of { id, kind, item }. The site
// asked a Brand Relay it already trusts, over HTTPS, so a forged ping can only make it ask early. An
// item is only a name ("akismet/akismet.php", "core"), never a URL or a version: the site installs the
// update WordPress ITSELF offers for that item, through WordPress's own automatic updater (maintenance
// mode, the auto_updater lock, and its own rollback of a plugin update that breaks the site), or nothing.
// It answers the ping first (`started`), applies after the answer, then sends a second report at once
// with the `results`; results not acknowledged by Brand Relay are kept and sent with the next report.
//
// NEVER UPDATED FROM HERE: the theme (Warden's updater), the bundled plugins (the theme's release
// control: reported with `bundled: true`, refused if asked), themes, translations. A site whose host
// disables automatic updates or file changes (AUTOMATIC_UPDATER_DISABLED, DISALLOW_FILE_MODS) refuses too.

/** An update check older than this is refreshed from api.wordpress.org first (many sites have cron off). */
const DEVBASE_UPDATES_REFRESH_AFTER = 12 * HOUR_IN_SECONDS;

/** Brand Relay takes at most 500 plugins and 128 KB. */
const DEVBASE_UPDATES_MAX_PLUGINS = 500;

/** Items applied from one answer at most (Brand Relay hands over 20). */
const DEVBASE_UPDATES_APPLY_MAX = 20;

/** Results of applied updates that Brand Relay has not acknowledged yet: sent with every report until it does. */
const DEVBASE_UPDATES_RESULTS = 'devbase_updates_results';

/** Rounds of apply-then-report one request runs at most (Brand Relay may hand over more after the first). */
const DEVBASE_UPDATES_ROUNDS = 3;

/** Loads the admin-side functions a ping does not load (get_plugins, wp_update_plugins, wp_version_check). */
function devbase_updates_load_admin() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'wp_update_plugins' ) || ! function_exists( 'wp_version_check' ) ) {
		require_once ABSPATH . 'wp-includes/update.php';
	}
}

/**
 * Refreshes WordPress's own update checks when they are older than DEVBASE_UPDATES_REFRESH_AFTER,
 * so what is reported is what WordPress would offer now. The checks are WordPress's, unchanged.
 * Filterable for tests (`devbase/updates/refresh` false: never call api.wordpress.org).
 */
function devbase_updates_refresh() {
	if ( ! apply_filters( 'devbase/updates/refresh', true ) ) {
		return;
	}

	$stale = function ( $transient ) {
		$value = get_site_transient( $transient );

		return ! is_object( $value ) || empty( $value->last_checked ) || time() - (int) $value->last_checked > DEVBASE_UPDATES_REFRESH_AFTER;
	};

	if ( $stale( 'update_plugins' ) ) {
		wp_update_plugins();
	}
	if ( $stale( 'update_core' ) ) {
		wp_version_check();
	}
}

/**
 * WordPress's update offers while applying. An update clears WordPress's own update caches, so the next item in the
 * same request may find none: then they are fetched again, once per request, and looked at a second time. Only what
 * WordPress offers is ever used.
 *
 * @param string|null   $transient update_plugins or update_core; null starts a new run (each may be fetched again).
 * @param callable|null $has Whether the offers include the one wanted.
 * @return object|false
 */
function devbase_updates_offers( $transient, $has = null ) {
	static $fetched = array();

	if ( null === $transient || null === $has ) {
		$fetched = array();

		return false;
	}

	$offers = get_site_transient( $transient );

	if ( ( ! is_object( $offers ) || ! $has( $offers ) ) && empty( $fetched[ $transient ] ) && apply_filters( 'devbase/updates/refresh', true ) ) {
		$fetched[ $transient ] = true;
		'update_core' === $transient ? wp_version_check() : wp_update_plugins();
		$offers = get_site_transient( $transient );
	}

	return $offers;
}

/** The plugin files the theme bundles: never updated from Brand Relay. */
function devbase_updates_bundled_files() {
	$files = array();

	foreach ( function_exists( 'devbase_bundled_plugins' ) ? devbase_bundled_plugins() : array() as $def ) {
		if ( ! empty( $def['file'] ) ) {
			$files[] = (string) $def['file'];
		}
	}

	return $files;
}

/**
 * The core part of the inventory: the running version and the update WordPress offers (the first
 * `upgrade` offer of the update_core transient), or none.
 *
 * @return array{version: string, update?: array{version: string}}
 */
function devbase_updates_core() {
	$version = devbase_updates_core_version();
	$core    = array( 'version' => $version );
	$offers  = get_site_transient( 'update_core' );

	foreach ( is_object( $offers ) && ! empty( $offers->updates ) ? (array) $offers->updates : array() as $offer ) {
		if ( is_object( $offer ) && 'upgrade' === ( $offer->response ?? '' ) && ! empty( $offer->current ) && version_compare( (string) $offer->current, $version, '>' ) ) {
			$core['update'] = array( 'version' => (string) $offer->current );
			break;
		}
	}

	return $core;
}

/**
 * Every installed plugin: file, name, version, whether it is active (on this site or the network),
 * whether the theme bundles it, and the update WordPress offers for it (the update_plugins transient).
 *
 * @return array[]
 */
function devbase_updates_plugins() {
	$offers  = get_site_transient( 'update_plugins' );
	$offers  = is_object( $offers ) && ! empty( $offers->response ) ? (array) $offers->response : array();
	$bundled = devbase_updates_bundled_files();
	$plugins = array();

	foreach ( get_plugins() as $file => $data ) {
		$plugin = array(
			'file'    => (string) $file,
			'name'    => wp_strip_all_tags( (string) ( $data['Name'] ?? $file ) ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'active'  => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ),
			'bundled' => in_array( $file, $bundled, true ),
		);

		$offer = $offers[ $file ] ?? null;
		if ( is_object( $offer ) && ! empty( $offer->new_version ) && version_compare( (string) $offer->new_version, $plugin['version'], '>' ) ) {
			$plugin['update'] = array( 'version' => (string) $offer->new_version );
		}

		$plugins[] = $plugin;

		if ( count( $plugins ) >= DEVBASE_UPDATES_MAX_PLUGINS ) {
			break;
		}
	}

	return $plugins;
}

/**
 * The WordPress version on disk. Read from wp-includes/version.php, not the global: after a core update in this same
 * request the global still holds the version the request started with.
 */
function devbase_updates_core_version() {
	$file = ABSPATH . 'wp-includes/version.php';
	$code = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	return preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $code, $m ) ? $m[1] : (string) get_bloginfo( 'version' );
}

/** The request body: { core, plugins }. */
function devbase_updates_inventory() {
	devbase_updates_load_admin();
	devbase_updates_refresh();

	return array(
		'core'    => devbase_updates_core(),
		'plugins' => devbase_updates_plugins(),
	);
}

/**
 * Posts the inventory, with any results not yet acknowledged, and returns the HTTP code and the decoded answer
 * (code 0 and the error message when the request itself failed). Results are dropped once Brand Relay answers 200.
 *
 * @return array{0: int, 1: array, 2: string}
 */
function devbase_updates_send() {
	$results  = get_option( DEVBASE_UPDATES_RESULTS );
	$results  = is_array( $results ) ? array_values( $results ) : array();
	$body     = devbase_updates_inventory();
	if ( $results ) {
		$body['results'] = $results;
	}

	$response = wp_remote_post(
		devbase_relay_url() . '/v1/site/updates',
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . devbase_relay_site_key(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'        => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 0, array(), $response->get_error_message() );
	}

	$code   = (int) wp_remote_retrieve_response_code( $response );
	$answer = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 === $code && $results ) {
		delete_option( DEVBASE_UPDATES_RESULTS );
	}

	return array( $code, is_array( $answer ) ? $answer : array(), '' );
}

/**
 * The `apply` of an answer, read strictly: at most DEVBASE_UPDATES_APPLY_MAX items, each an id Brand Relay made
 * (upd_…) and a plugin file or core. Anything else is ignored. Nothing in it is a URL or a version.
 *
 * @return array<int, array{id: string, kind: string, item: string}>
 */
function devbase_updates_apply_list( array $answer ) {
	$items = array();

	foreach ( array_slice( is_array( $answer['apply'] ?? null ) ? $answer['apply'] : array(), 0, DEVBASE_UPDATES_APPLY_MAX ) as $raw ) {
		$id   = is_array( $raw ) ? (string) ( $raw['id'] ?? '' ) : '';
		$kind = is_array( $raw ) ? (string) ( $raw['kind'] ?? '' ) : '';
		$item = is_array( $raw ) ? (string) ( $raw['item'] ?? '' ) : '';

		if ( ! preg_match( '/^upd_[a-z0-9]{8,40}$/', $id ) ) {
			continue;
		}
		if ( 'core' === $kind ) {
			$items[] = array( 'id' => $id, 'kind' => 'core', 'item' => 'core' );
		} elseif ( 'plugin' === $kind && preg_match( '~^(?:[A-Za-z0-9._-]+/)?[A-Za-z0-9._-]+\.php$~', $item ) && false === strpos( $item, '..' ) ) {
			$items[] = array( 'id' => $id, 'kind' => 'plugin', 'item' => $item );
		}
	}

	return $items;
}

/** The items handed over by this request's answer, applied after the answer by devbase_updates_after_answer(). */
function devbase_updates_handed( $set = null ) {
	static $items = array();

	if ( null !== $set ) {
		$items = (array) $set;
	}

	return $items;
}

/**
 * Sends the inventory and says what happened, for the maintenance check to record. Runs only while the plan says
 * the updates service is in force (the maintenance check makes sure). When the answer hands over updates, they are
 * applied once this request has answered Brand Relay's ping (`started`): an update can take longer than the ping waits.
 *
 * @return array{status: string, detail: string, sent_at?: int}
 */
function devbase_updates_run() {
	list( $code, $answer, $error ) = devbase_updates_send();

	if ( 0 === $code ) {
		return array( 'status' => 'error', 'detail' => $error );
	}

	if ( 200 === $code ) {
		$apply = devbase_updates_apply_list( $answer );

		if ( $apply ) {
			devbase_updates_handed( $apply );
			add_action( 'shutdown', 'devbase_updates_after_answer', 1000 );

			return array( 'status' => 'started', 'detail' => count( $apply ) . ' update(s)' );
		}

		return array( 'status' => 'ok', 'detail' => '', 'sent_at' => time() );
	}

	if ( 401 === $code ) {
		return array( 'status' => 'refused', 'detail' => '' );
	}

	if ( 403 === $code && 'feature_off' === ( $answer['error'] ?? '' ) ) {
		return array( 'status' => 'not_in_plan', 'detail' => '' );
	}

	// Sent moments ago (Run now twice): nothing to do, which is what `skipped` means.
	if ( 429 === $code ) {
		return array( 'status' => 'idle', 'detail' => '' );
	}

	return array( 'status' => 'error', 'detail' => "HTTP {$code}" );
}

/**
 * After the ping has its answer: applies what was handed over, keeps the results, and reports at once (Brand Relay
 * accepts a report with results inside its 60-second interval). Its answer may hand over more (Brand Relay sends 20
 * at a time): up to DEVBASE_UPDATES_ROUNDS rounds. Results that could not be sent stay for the next report.
 */
function devbase_updates_after_answer() {
	$items = devbase_updates_handed();
	devbase_updates_handed( array() );

	if ( ! $items ) {
		return;
	}

	if ( function_exists( 'fastcgi_finish_request' ) && 'cli' !== PHP_SAPI ) {
		fastcgi_finish_request();
	}
	ignore_user_abort( true );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 15 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	for ( $round = 0; $items && $round < DEVBASE_UPDATES_ROUNDS; $round++ ) {
		devbase_updates_keep_results( devbase_updates_apply( $items ) );

		list( $code, $answer ) = devbase_updates_send();
		$items = 200 === $code ? devbase_updates_apply_list( $answer ) : array();
	}
}

/** Adds results to those waiting to be acknowledged (by id, the latest wins). */
function devbase_updates_keep_results( array $results ) {
	$kept = get_option( DEVBASE_UPDATES_RESULTS );
	$kept = is_array( $kept ) ? $kept : array();

	foreach ( $results as $result ) {
		$kept[ $result['id'] ] = $result;
	}

	update_option( DEVBASE_UPDATES_RESULTS, array_slice( $kept, -50, null, true ), false );
}

/**
 * Applies the items through WordPress's own automatic updater, one at a time, under WordPress's `auto_updater` lock
 * (so it never runs alongside WordPress's own background updates). Returns one result per item:
 * { id, ok, from, to, error }.
 *
 * @param array<int, array{id: string, kind: string, item: string}> $items
 * @return array<int, array{id: string, ok: bool, from: string, to: string, error: string}>
 */
function devbase_updates_apply( array $items ) {
	devbase_updates_load_admin();
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$fail = function ( $item, $error, $from = '', $to = '' ) {
		return array( 'id' => $item['id'], 'ok' => false, 'from' => $from, 'to' => $to, 'error' => $error );
	};

	if ( ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
		return array_map( function ( $item ) use ( $fail ) {
			return $fail( $item, 'WordPress is already running updates. Try again in a few minutes.' );
		}, $items );
	}

	devbase_updates_offers( null );
	$results = array();
	try {
		foreach ( $items as $item ) {
			$results[] = 'core' === $item['kind'] ? devbase_updates_apply_core( $item, $fail ) : devbase_updates_apply_plugin( $item, $fail );
		}
	} finally {
		WP_Upgrader::release_lock( 'auto_updater' );
	}

	return $results;
}

/**
 * Runs WordPress's automatic updater for one item, allowed for that item only while it runs. Tests replace the
 * upgrade itself with `devbase/updates/upgrade` (a non-null value is used as the updater's return value).
 *
 * @param string $type plugin or core.
 * @param object $offer The offer from WordPress's update transient.
 * @return mixed What WP_Automatic_Updater::update() returns: false (declined), a WP_Error, or the upgrader's result.
 */
function devbase_updates_upgrade( $type, $offer ) {
	$pre = apply_filters( 'devbase/updates/upgrade', null, $type, $offer );
	if ( null !== $pre ) {
		return $pre;
	}

	$only = function ( $update, $item ) use ( $type, $offer ) {
		return 'plugin' === $type ? ( isset( $item->plugin ) && $item->plugin === $offer->plugin ) || $update : true;
	};
	$yes = '__return_true';

	add_filter( "auto_update_{$type}", $only, PHP_INT_MAX, 2 );
	if ( 'core' === $type ) {
		add_filter( 'allow_major_auto_core_updates', $yes, PHP_INT_MAX );
		add_filter( 'allow_minor_auto_core_updates', $yes, PHP_INT_MAX );
	}

	try {
		return ( new WP_Automatic_Updater() )->update( $type, $offer );
	} finally {
		remove_filter( "auto_update_{$type}", $only, PHP_INT_MAX );
		remove_filter( 'allow_major_auto_core_updates', $yes, PHP_INT_MAX );
		remove_filter( 'allow_minor_auto_core_updates', $yes, PHP_INT_MAX );
	}
}

/** What a declined or failed update says, in one line. */
function devbase_updates_error( $result ) {
	if ( is_wp_error( $result ) ) {
		$messages = array_filter( array_map( 'wp_strip_all_tags', $result->get_error_messages() ) );

		return $messages ? implode( ' ', array_unique( $messages ) ) : $result->get_error_code();
	}

	return ( new WP_Automatic_Updater() )->is_disabled()
		? 'Automatic updates are switched off on this site (AUTOMATIC_UPDATER_DISABLED or DISALLOW_FILE_MODS in wp-config.php).'
		: 'WordPress declined the update: its files are not writable by PHP, the site is a version-control checkout, or the update needs a newer PHP or MySQL.';
}

/** One plugin: only an installed, non-bundled plugin with an update WordPress offers, never anything else. */
function devbase_updates_apply_plugin( array $item, callable $fail ) {
	$file      = $item['item'];
	$installed = get_plugins();

	if ( ! isset( $installed[ $file ] ) ) {
		return $fail( $item, 'The plugin is not installed.' );
	}

	$from = (string) $installed[ $file ]['Version'];

	if ( in_array( $file, devbase_updates_bundled_files(), true ) ) {
		return $fail( $item, 'The theme updates this plugin itself.', $from );
	}

	$offers = devbase_updates_offers( 'update_plugins', function ( $offers ) use ( $file ) {
		return isset( $offers->response[ $file ] );
	} );
	$offer  = is_object( $offers ) && isset( $offers->response[ $file ] ) ? $offers->response[ $file ] : null;

	if ( ! is_object( $offer ) || empty( $offer->new_version ) || empty( $offer->package ) || ! version_compare( (string) $offer->new_version, $from, '>' ) ) {
		return $fail( $item, 'WordPress offers no update for this plugin now.', $from );
	}

	$offer         = clone $offer;
	$offer->plugin = $file;
	$to            = (string) $offer->new_version;
	$result        = devbase_updates_upgrade( 'plugin', $offer );

	if ( false === $result || is_wp_error( $result ) ) {
		return $fail( $item, devbase_updates_error( $result ), $from, $to );
	}

	wp_clean_plugins_cache( false );
	/** Test seam: the version on disk after the update (the tests cannot write plugin files). */
	$now = (string) apply_filters( 'devbase/updates/installed_version', get_plugins()[ $file ]['Version'] ?? '', $file );

	return '' !== $now && version_compare( $now, $from, '>' )
		? array( 'id' => $item['id'], 'ok' => true, 'from' => $from, 'to' => $now, 'error' => '' )
		: $fail( $item, 'WordPress reported success but the plugin is still at ' . ( $now ? $now : 'an unknown version' ) . '.', $from, $to );
}

/** WordPress itself: only the update WordPress offers (the first `upgrade` offer), never a version named by Brand Relay. */
function devbase_updates_apply_core( array $item, callable $fail ) {
	$from   = devbase_updates_core_version();
	$offers = devbase_updates_offers( 'update_core', function ( $offers ) {
		return ! empty( $offers->updates );
	} );
	$offer  = null;

	foreach ( is_object( $offers ) && ! empty( $offers->updates ) ? (array) $offers->updates : array() as $candidate ) {
		if ( is_object( $candidate ) && 'upgrade' === ( $candidate->response ?? '' ) && ! empty( $candidate->current ) && version_compare( (string) $candidate->current, $from, '>' ) ) {
			$offer = $candidate;
			break;
		}
	}

	if ( ! $offer ) {
		return $fail( $item, 'WordPress offers no update now.', $from );
	}

	$to     = (string) $offer->current;
	$result = devbase_updates_upgrade( 'core', $offer );

	if ( false === $result || is_wp_error( $result ) ) {
		return $fail( $item, devbase_updates_error( $result ), $from, $to );
	}

	$now = devbase_updates_core_version();

	return version_compare( $now, $from, '>' )
		? array( 'id' => $item['id'], 'ok' => true, 'from' => $from, 'to' => $now, 'error' => '' )
		: $fail( $item, "WordPress reported success but is still at {$now}.", $from, $to );
}
