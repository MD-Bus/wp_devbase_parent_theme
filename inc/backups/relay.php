<?php

// Brand Relay's backup API, as the site uses it (see Brand Relay's docs, integration/backups):
// declare a backup, send it in parts, complete it, list, download. This file only talks to Brand
// Relay; inc/backups/backups.php decides what to send and when.
//
// Two calls stream a file instead of loading it: a part is up to 50 MiB, and the WordPress HTTP
// API would hold it (twice) in memory, which a 128 MB host cannot. cURL reads the part straight
// from the file at its offset, and writes a download straight to disk, so memory stays flat
// however big the backup is. Both can be replaced in tests (the filters below).

/**
 * A JSON call to Brand Relay with the site key.
 *
 * @param string     $method GET or POST.
 * @param string     $path   Starting with /v1/site/backups.
 * @param array|null $body   Sent as JSON.
 * @return array{code: int, data: array}|WP_Error
 */
function devbase_backup_api( $method, $path, $body = null ) {
	$args = array(
		'method'      => $method,
		'timeout'     => 30,
		'redirection' => 0,
		'headers'     => array(
			'Authorization' => 'Bearer ' . devbase_relay_site_key(),
			'Accept'        => 'application/json',
		),
	);

	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}

	$response = wp_remote_request( devbase_relay_url() . $path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'data' => is_array( $data ) ? $data : array(),
	);
}

/**
 * Sends one part of a file: $length bytes from $offset, as a PUT of exactly that many bytes.
 *
 * @param string $path   The part's address (from the declaration, {n} already filled in).
 * @param string $file   Local file.
 * @param int    $offset Where the part starts.
 * @param int    $length The part's size.
 * @return int|WP_Error The HTTP status, or an error when the request itself failed.
 */
function devbase_backup_put_part( $path, $file, $offset, $length ) {
	$override = apply_filters( 'devbase/backup/put_part', null, $path, $file, $offset, $length );
	if ( null !== $override ) {
		return $override;
	}

	if ( ! function_exists( 'curl_init' ) ) {
		return new WP_Error( 'curl', 'The PHP cURL extension is needed to send backups.' );
	}

	$handle = fopen( $file, 'rb' );
	if ( ! $handle ) {
		return new WP_Error( 'read', 'The backup file cannot be read.' );
	}
	fseek( $handle, $offset );

	$curl = curl_init( devbase_relay_url() . $path );
	curl_setopt_array( $curl, array(
		CURLOPT_UPLOAD         => true,
		CURLOPT_CUSTOMREQUEST  => 'PUT',
		CURLOPT_INFILE         => $handle,
		CURLOPT_INFILESIZE     => $length,
		CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . devbase_relay_site_key(), 'Content-Type: application/octet-stream', 'Expect:' ),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT        => 900,
		CURLOPT_FOLLOWLOCATION => false,
	) );

	curl_exec( $curl );
	$code  = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	$error = curl_error( $curl );
	curl_close( $curl );
	fclose( $handle );

	return '' !== $error ? new WP_Error( 'put', $error ) : $code;
}

/**
 * Downloads bytes $from..$to of a stored file and appends them to an open file handle.
 *
 * @param string   $path    /v1/site/backups/{id}/files/{name}
 * @param resource $handle  Open for writing, positioned at the end.
 * @param array    $headers Receives the response headers (lower-case names), such as x-checksum-sha256.
 * @return int|WP_Error The HTTP status (206, or 200 when the whole file came), or an error.
 */
function devbase_backup_get_range( $path, $handle, $from, $to, &$headers = array() ) {
	$override = apply_filters( 'devbase/backup/get_range', null, $path, $handle, $from, $to );
	if ( null !== $override ) {
		$headers = (array) apply_filters( 'devbase/backup/get_range_headers', array(), $path );

		return $override;
	}

	if ( ! function_exists( 'curl_init' ) ) {
		return new WP_Error( 'curl', 'The PHP cURL extension is needed to fetch backups.' );
	}

	$headers = array();
	$curl    = curl_init( devbase_relay_url() . $path );
	curl_setopt_array( $curl, array(
		CURLOPT_FILE           => $handle,
		CURLOPT_RANGE          => $from . '-' . $to,
		CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . devbase_relay_site_key() ),
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT        => 900,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_HEADERFUNCTION => function ( $curl, $line ) use ( &$headers ) {
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
			}

			return strlen( $line );
		},
	) );

	curl_exec( $curl );
	$code  = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	$error = curl_error( $curl );
	curl_close( $curl );

	return '' !== $error ? new WP_Error( 'get', $error ) : $code;
}
