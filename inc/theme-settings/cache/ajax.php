<?php

// The Cache page's buttons (cache.js): clear everything now, check the home page's cache headers, test the
// Cloudflare token, and create or remove the cache rule. Each uses the SAVED settings, like the other test buttons in
// Theme Settings: save first, then press.

/**
 * The checks every button makes: the nonce, who is asking, and (except for the header check) that the integration is
 * on.
 *
 * @param bool $needs_enabled Refuse while the integration is off.
 * @param bool $needs_cf      Refuse while Cloudflare is not set up.
 */
function devbase_cache_ajax_guard( $needs_enabled = true, $needs_cf = false ) {
	check_ajax_referer( 'devbase_cache' );

	if ( ! current_user_can( devbase_settings_capability() ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'devbase_parent_theme' ) ), 403 );
	}

	if ( $needs_enabled && ! devbase_cache_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'The cache integration is off: switch it on and save first.', 'devbase_parent_theme' ) ) );
	}

	if ( $needs_cf && ! devbase_cache_cf_active() ) {
		wp_send_json_error( array( 'message' => __( 'Cloudflare is not set up: switch it on, fill in the zone ID and the token, and save first.', 'devbase_parent_theme' ) ) );
	}
}

/**
 * The results of a clear as lines.
 *
 * @param array[] $results { label, ok, message } each.
 */
function devbase_cache_results_text( array $results ) {
	$lines = array();

	foreach ( $results as $result ) {
		$lines[] = ( ! empty( $result['ok'] ) ? '✓ ' : '✗ ' ) . (string) $result['label'] . ( '' !== (string) ( $result['message'] ?? '' ) ? ' — ' . $result['message'] : '' );
	}

	return implode( "\n", $lines );
}

add_action( 'wp_ajax_devbase_cache_clear', function () {
	devbase_cache_ajax_guard();

	$results = devbase_cache_clear_all_now();
	$ok      = ! in_array( false, wp_list_pluck( $results, 'ok' ), true );
	$message = ( $ok ? __( 'Cleared.', 'devbase_parent_theme' ) : __( 'Not everything could be cleared.', 'devbase_parent_theme' ) ) . "\n" . devbase_cache_results_text( $results );

	$ok ? wp_send_json_success( array( 'message' => $message ) ) : wp_send_json_error( array( 'message' => $message ) );
} );

add_action( 'wp_ajax_devbase_cache_detect', function () {
	devbase_cache_ajax_guard( false );

	$result = devbase_cache_detect_headers();

	$result['ok'] ? wp_send_json_success( array( 'message' => $result['message'] ) ) : wp_send_json_error( array( 'message' => $result['message'] ) );
} );

add_action( 'wp_ajax_devbase_cache_cf_test', function () {
	devbase_cache_ajax_guard( true, true );

	$result = devbase_cache_cf_test();

	$result['ok'] ? wp_send_json_success( array( 'message' => $result['message'] ) ) : wp_send_json_error( array( 'message' => $result['message'] ) );
} );

add_action( 'wp_ajax_devbase_cache_cf_rule', function () {
	devbase_cache_ajax_guard( true, true );

	$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in the guard.
	$result = 'remove' === $op ? devbase_cache_cf_rule_remove() : devbase_cache_cf_rule_install();

	$result['ok'] ? wp_send_json_success( array( 'message' => $result['message'] ) ) : wp_send_json_error( array( 'message' => $result['message'] ) );
} );
