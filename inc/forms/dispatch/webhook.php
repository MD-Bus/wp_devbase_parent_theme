<?php

// Sends a submission to a single webhook destination row — POSTs the raw
// $content map as JSON. Authentication is a plain static header (not an
// HMAC signature): if the row has a Password/Key and Header Name set,
// that value is sent as-is under that header name — matches how most
// real receivers (n8n, Zapier, Make, a client's own API) actually expect
// to be authenticated.

/**
 * @param array  $row       A single row from Form Destinations' `destinations`
 *                           repeater (type === 'webhook'): label, url,
 *                           auth_value, auth_header_name.
 * @param string $form_name
 * @param array  $content   The submission's field => [aria_label, value] map.
 * @return array{success: bool, label: string, message: string}
 */
function devbase_forms_dispatch_webhook( array $row, $form_name, array $content ) {
	if ( empty( $row['url'] ) ) {
		$message = sprintf( 'Webhook destination "%s" has no URL.', $row['label'] ?? '' );
		error_log( '[Dev Base Forms] ' . $message );
		return array( 'success' => false, 'label' => __( 'Webhook', 'devbase_parent_theme' ), 'message' => $message );
	}

	$label = ! empty( $row['label'] ) ? $row['label'] : $row['url'];

	$body = wp_json_encode( array(
		'form_name' => $form_name,
		'timestamp' => current_time( 'mysql', true ),
		'data'      => $content,
	) );

	$headers = array( 'Content-Type' => 'application/json' );

	if ( ! empty( $row['auth_value'] ) && ! empty( $row['auth_header_name'] ) ) {
		$headers[ $row['auth_header_name'] ] = $row['auth_value'];
	}

	$args = array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => $headers,
		'body'        => $body,
	);

	// wp_safe_remote_post() refuses private and local addresses (169.254.169.254, localhost, the
	// LAN) and ports other than 80, 443 and 8080, so a destination cannot be used to reach the
	// server's own network. A receiver that genuinely lives there (n8n on :5678, an intranet API)
	// is let through by the devbase/forms/webhook_unsafe_ok filter, or WordPress's own
	// http_request_host_is_external / http_allowed_safe_ports filters. A local environment is exempt.
	$unsafe_ok = 'local' === wp_get_environment_type() || (bool) apply_filters( 'devbase/forms/webhook_unsafe_ok', false, $row['url'], $row );
	$response  = $unsafe_ok ? wp_remote_post( $row['url'], $args ) : wp_safe_remote_post( $row['url'], $args );

	if ( is_wp_error( $response ) ) {
		$message = sprintf( 'Webhook "%s" (%s) failed: %s', $label, $row['url'], $response->get_error_message() );
		error_log( '[Dev Base Forms] ' . $message );
		return array( 'success' => false, 'label' => $label, 'message' => $message );
	}

	$code    = wp_remote_retrieve_response_code( $response );
	$success = $code >= 200 && $code < 300;
	$message = sprintf( 'Webhook "%s" (%s): %s (HTTP %d)', $label, $row['url'], $success ? 'sent' : 'failed', $code );

	error_log( '[Dev Base Forms] ' . $message );

	return array( 'success' => $success, 'label' => $label, 'message' => $message );
}
