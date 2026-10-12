<?php
// Boots the WP core test suite (wp-phpunit) against ABSPATH's real
// WordPress core files but the isolated database from wp-tests-config.php,
// then forces this theme active before WordPress finishes loading — same
// mechanism a real request uses, just pointed at throwaway data.
//
// Uses tests/vendor/autoload.php (its own composer.json, tests/composer.json)
// — NOT the theme root's vendor/autoload.php. PHPUnit/wp-phpunit/yoast live
// in their own separate vendor/ specifically so the theme root's vendor/
// (SCF/ASE/MCP Adapter/Timber, committed to git) never has to reference
// dev-only packages — see tests/AGENT.md and inc/dependencies/AGENT.md for
// why that distinction is load-bearing, not just tidiness. The theme's own
// runtime classes (Timber, etc.) are still autoloaded separately, via
// functions.php's own require of the theme root's vendor/autoload.php,
// once WordPress loads this theme below — both autoloaders coexist fine.
require_once __DIR__ . '/vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: __DIR__ . '/vendor/wp-phpunit/wp-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

function devbase_tests_switch_theme() {
	switch_theme( 'wp_devbase_parent_theme' );
}
tests_add_filter( 'muplugins_loaded', 'devbase_tests_switch_theme' );

/**
 * Rebuilds the REST server so routes include post types registered after it
 * was first built.
 *
 * WordPress builds the REST server LAZILY — rest_get_server() only
 * constructs it `if ( empty( $wp_rest_server ) )`, and that is what fires
 * the `rest_api_init` action whose priority-99 callback,
 * create_initial_rest_routes(), enumerates post types. In production that
 * is always late enough: `add_action( 'init', 'rest_api_init' )` hooks a
 * function that merely adds rewrites and a query var, and nothing builds
 * the server until a request actually needs it — long after every CPT has
 * registered on `init`.
 *
 * In a test suite the ordering is not guaranteed. A test file that
 * registers a post type in set_up() AFTER an earlier file already caused
 * the server to be built gets no route for it, so anything going through
 * rest_do_request() fails with `rest_no_route` (404) — while the same file
 * passes in isolation, because then it runs first. That cost a real
 * debugging session: 13/13 alone, 6 failures in the suite.
 *
 * Call this in set_up() after register_post_type(), and
 * devbase_tests_reset_rest_server() in tear_down(). Exists as a shared
 * helper rather than a snippet to retype, because the failure it prevents
 * is invisible until the full suite runs.
 */
function devbase_tests_rebuild_rest_server() {
	global $wp_rest_server;

	$wp_rest_server = new WP_REST_Server();
	do_action( 'rest_api_init', $wp_rest_server );
}

/**
 * Drops the REST server, so the next test file gets one rebuilt without
 * whatever post types this file registered.
 */
function devbase_tests_reset_rest_server() {
	global $wp_rest_server;

	$wp_rest_server = null;
}

require $wp_tests_dir . '/includes/bootstrap.php';
