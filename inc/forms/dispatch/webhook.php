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
	// LAN), so a destination cannot be used to reach the server's own network. It also refuses
	// ports other than 80, 443 and 8080, which protects nothing on a public host (the port is the
	// receiver's own), so the port of THIS url is allowed for this one request (n8n on :5678 works).
	// A receiver that genuinely lives on a private address (an intranet API) is let through by the
	// devbase/forms/webhook_unsafe_ok filter. A local environment is exempt.
	$unsafe_ok = 'local' === wp_get_environment_type() || (bool) apply_filters( 'devbase/forms/webhook_unsafe_ok', false, $row['url'], $row );

	if ( $unsafe_ok ) {
		$response = wp_remote_post( $row['url'], $args );
	} else {
		$port     = (int) wp_parse_url( $row['url'], PHP_URL_PORT );
		$allow    = static function ( $ports ) use ( $port ) {
			if ( $port > 0 ) {
				$ports[] = $port;
			}
			return $ports;
		};
		add_filter( 'http_allowed_safe_ports', $allow );
		$response = wp_safe_remote_post( $row['url'], $args );
		remove_filter( 'http_allowed_safe_ports', $allow );
	}

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
