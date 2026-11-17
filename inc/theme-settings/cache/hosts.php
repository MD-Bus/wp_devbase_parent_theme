<?php

// The host caches (and cache plugins) the theme knows how to clear, and how it finds them.
//
// Third-party functions are called by name (call_user_func): they exist only on their host.
//
// Every entry is detected by what the host itself loads into WordPress (its function, class, constant or hook), never
// by guessing from the domain, and cleared only through that same function or hook. A host that is not detected is
// left alone. A child theme adds its own through `devbase/cache/hosts`.

/**
 * Calls a host's function by name, when it exists.
 *
 * @param string $name The function.
 */
function devbase_cache_call( $name ) {
	if ( is_callable( $name ) ) {
		call_user_func( $name );
	}
}

/** Set when a LiteSpeed purge header could not be sent; the next request sends it. */
const DEVBASE_CACHE_LITESPEED_PENDING = 'devbase_cache_litespeed_pending';

// Only on a LiteSpeed server with the integration on. The option is kept at 0 rather than deleted, autoloaded, so
// checking it never costs a query.
add_action( 'init', function () {
	if ( false === stripos( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ), 'litespeed' ) || ! devbase_cache_enabled() ) {
		return;
	}

	if ( get_option( DEVBASE_CACHE_LITESPEED_PENDING ) && ! headers_sent() ) {
		update_option( DEVBASE_CACHE_LITESPEED_PENDING, 0, true );
		header( 'X-LiteSpeed-Purge: *' );
	}
} );

/**
 * Every known cache: id => label, kind (host or plugin), detect (callable, true when present), purge (callable that
 * clears everything).
 *
 * @return array<string,array>
 */
function devbase_cache_hosts() {
	$hosts = array(
		'litespeed_plugin' => array(
			'label'  => 'LiteSpeed Cache (plugin)',
			'kind'   => 'plugin',
			'detect' => function () {
				return defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' );
			},
			'purge'  => function () {
				do_action( 'litespeed_purge_all' );
			},
		),
		// A LiteSpeed server without the plugin (its cache can still be on, through .htaccess): the purge is a
		// response header, so it only works while headers can still be sent. devbase_cache_purge_hosts() runs at the
		// time of the change, not at shutdown, for this reason.
		'litespeed_server' => array(
			'label'  => 'LiteSpeed server',
			'kind'   => 'host',
			'detect' => function () {
				return ! defined( 'LSCWP_V' ) && false !== stripos( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ), 'litespeed' );
			},
			'purge'  => function () {
				// The update screens print as they go: the header goes out with the next request instead.
				if ( headers_sent() ) {
					update_option( DEVBASE_CACHE_LITESPEED_PENDING, 1, true );

					return __( 'on the next page load', 'devbase_parent_theme' );
				}

				header( 'X-LiteSpeed-Purge: *' );

				return '';
			},
		),
		'siteground'       => array(
			'label'  => 'SiteGround',
			'kind'   => 'host',
			'detect' => function () {
				return function_exists( 'sg_cachepress_purge_everything' ) || function_exists( 'sg_cachepress_purge_cache' );
			},
			'purge'  => function () {
				if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
					devbase_cache_call( 'sg_cachepress_purge_everything' );
				} else {
					devbase_cache_call( 'sg_cachepress_purge_cache' );
				}
			},
		),
		'wpengine'         => array(
			'label'  => 'WP Engine',
			'kind'   => 'host',
			'detect' => function () {
				return class_exists( 'WpeCommon' );
			},
			'purge'  => function () {
				foreach ( array( 'purge_memcached', 'purge_varnish_cache' ) as $method ) {
					if ( is_callable( array( 'WpeCommon', $method ) ) ) {
						call_user_func( array( 'WpeCommon', $method ) );
					}
				}
			},
		),
		'kinsta'           => array(
			'label'  => 'Kinsta',
			'kind'   => 'host',
			'detect' => function () {
				global $kinsta_cache;

				return isset( $kinsta_cache->kinsta_cache_purge ) && method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' );
			},
			'purge'  => function () {
				global $kinsta_cache;

				$kinsta_cache->kinsta_cache_purge->purge_complete_caches();
			},
		),
		'breeze'           => array(
			'label'  => 'Cloudways (Breeze)',
			'kind'   => 'host',
			'detect' => function () {
				return (bool) has_action( 'breeze_clear_all_cache' );
			},
			'purge'  => function () {
				do_action( 'breeze_clear_all_cache' );
			},
		),
		'pantheon'         => array(
			'label'  => 'Pantheon',
			'kind'   => 'host',
			'detect' => function () {
				return function_exists( 'pantheon_wp_clear_edge_all' );
			},
			'purge'  => function () {
				devbase_cache_call( 'pantheon_wp_clear_edge_all' );
			},
		),
		'nginx_helper'     => array(
			'label'  => 'Nginx Helper',
			'kind'   => 'host',
			'detect' => function () {
				return (bool) has_action( 'rt_nginx_helper_purge_all' );
			},
			'purge'  => function () {
				do_action( 'rt_nginx_helper_purge_all' );
			},
		),
		'wp_rocket'        => array(
			'label'  => 'WP Rocket',
			'kind'   => 'plugin',
			'detect' => function () {
				return function_exists( 'rocket_clean_domain' );
			},
			'purge'  => function () {
				devbase_cache_call( 'rocket_clean_domain' );
			},
		),
		'w3tc'             => array(
			'label'  => 'W3 Total Cache',
			'kind'   => 'plugin',
			'detect' => function () {
				return function_exists( 'w3tc_flush_all' );
			},
			'purge'  => function () {
				devbase_cache_call( 'w3tc_flush_all' );
			},
		),
		'wp_super_cache'   => array(
			'label'  => 'WP Super Cache',
			'kind'   => 'plugin',
			'detect' => function () {
				return function_exists( 'wp_cache_clear_cache' );
			},
			'purge'  => function () {
				devbase_cache_call( 'wp_cache_clear_cache' );
			},
		),
	);

	$filtered = apply_filters( 'devbase/cache/hosts', $hosts );

	return is_array( $filtered ) ? $filtered : $hosts;
}

/**
 * The known caches present on this site.
 *
 * @return array<string,array>
 */
function devbase_cache_detected_hosts() {
	$found = array();

	foreach ( devbase_cache_hosts() as $id => $host ) {
		if ( ! is_array( $host ) || ! isset( $host['detect'], $host['purge'], $host['label'] ) || ! is_callable( $host['detect'] ) ) {
			continue;
		}

		try {
			if ( call_user_func( $host['detect'] ) ) {
				$found[ $id ] = $host;
			}
		} catch ( Throwable $e ) {
			continue;
		}
	}

	return $found;
}

/** True when this request came to the site through Cloudflare (it adds CF-Ray to every request it forwards). */
function devbase_cache_behind_cloudflare() {
	return ! empty( $_SERVER['HTTP_CF_RAY'] );
}

/**
 * What could clash with the theme, as sentences for the Status tab.
 *
 * @return string[]
 */
function devbase_cache_conflicts() {
	$warnings = array();
	$plugins  = array_filter( devbase_cache_detected_hosts(), function ( $host ) {
		return 'plugin' === ( $host['kind'] ?? '' );
	} );

	$cf_plugins = array();

	if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) ) {
		$cf_plugins[] = 'Cloudflare';
	}

	foreach ( array( 'litespeed_plugin', 'wp_rocket' ) as $id ) {
		if ( isset( $plugins[ $id ] ) ) {
			$cf_plugins[] = $plugins[ $id ]['label'];
		}
	}

	if ( $plugins ) {
		/* translators: %s: plugin names */
		$warnings[] = sprintf( __( 'A cache plugin is active: %s. The theme also clears its cache on the changes it watches, which is harmless. If that plugin already does everything you need, leave the theme\'s integration off.', 'devbase_parent_theme' ), implode( ', ', wp_list_pluck( $plugins, 'label' ) ) );
	}

	if ( $cf_plugins && devbase_cache_cf_active() ) {
		/* translators: %s: plugin names */
		$warnings[] = sprintf( __( '%s can also talk to Cloudflare. Use Cloudflare in only one place: switch it off here or in the plugin.', 'devbase_parent_theme' ), implode( ', ', $cf_plugins ) );
	}

	if ( devbase_cache_cf_page_cache() && ( $plugins || ( defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) ) ) {
		$warnings[] = __( 'Another page cache is installed (advanced-cache.php or a cache plugin) while the theme lets Cloudflare cache pages. Both set caching headers: keep page caching in one of them.', 'devbase_parent_theme' );
	}

	if ( devbase_cache_enabled() && ! devbase_cache_cf_active() && devbase_cache_behind_cloudflare() ) {
		$warnings[] = __( 'This site is behind Cloudflare, but the Cloudflare tab is off: Cloudflare is not cleared when the site changes.', 'devbase_parent_theme' );
	}

	return $warnings;
}

/**
 * Loads the home page twice and reads the cache headers in the answers, like WordPress's own Site Health page cache
 * test. For the "Check" button: never on a page load.
 *
 * @return array { ok: bool, message: string }
 */
function devbase_cache_detect_headers() {
	$names = array( 'cache-control', 'expires', 'age', 'last-modified', 'etag', 'x-cache', 'x-cache-enabled', 'x-cache-disabled', 'x-proxy-cache', 'x-litespeed-cache', 'x-litespeed-cache-control', 'x-sg-cache', 'x-kinsta-cache', 'x-varnish', 'x-srcache-fetch-status', 'x-fastcgi-cache', 'cf-cache-status', 'cf-ray', 'x-powered-by-wpe', 'wpe-backend' );
	$seen  = array();

	for ( $i = 0; $i < 2; $i++ ) {
		$response = wp_remote_get( home_url( '/' ), array(
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array( 'Cache-Control' => '' ),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: the error */
				'message' => sprintf( __( 'The home page could not be loaded: %s', 'devbase_parent_theme' ), $response->get_error_message() ),
			);
		}

		$seen = array();

		foreach ( $names as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );

			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}

			if ( '' !== (string) $value ) {
				$seen[ $name ] = (string) $value;
			}
		}
	}

	$lines = array();

	foreach ( $seen as $name => $value ) {
		$lines[] = $name . ': ' . $value;
	}

	$hit = false;

	foreach ( array( 'x-cache', 'x-proxy-cache', 'x-litespeed-cache', 'x-sg-cache', 'x-kinsta-cache', 'cf-cache-status', 'x-srcache-fetch-status', 'x-fastcgi-cache' ) as $name ) {
		if ( isset( $seen[ $name ] ) && false !== stripos( $seen[ $name ], 'hit' ) ) {
			$hit = true;
		}
	}

	if ( isset( $seen['age'] ) && (int) $seen['age'] > 0 ) {
		$hit = true;
	}

	$verdict = $hit
		? __( 'The second load came from a cache: a page cache is working.', 'devbase_parent_theme' )
		: __( 'The second load did not come from a cache. A page cache may be off, or it skips pages for this kind of request.', 'devbase_parent_theme' );

	devbase_cache_update_status( array(
		'detect' => array(
			'at'      => time(),
			'hit'     => $hit,
			'headers' => $seen,
		),
	) );

	return array(
		'ok'      => true,
		'message' => $verdict . ( $lines ? "\n\n" . implode( "\n", $lines ) : "\n\n" . __( 'No cache headers in the answer.', 'devbase_parent_theme' ) ),
	);
}
