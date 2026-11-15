<?php

// The Brevo adapter for the Email API route (api.php): turns the neutral message into Brevo's transactional email
// request and reads its answer back. https://developers.brevo.com/reference/sendtransacemail
//
// POST https://api.brevo.com/v3/smtp/email, JSON, the key in an `api-key` header. 201 with a messageId is a send;
// anything else carries { code, message }, which becomes the error shown by the test button and kept in the failure
// log ("Key not found", "Sender not valid", "Not enough credits"...).
//
// Brevo has two kinds of key. The API needs an API key (xkeysib-...); an SMTP key (xsmtpsib-...) is refused with
// "Key not found", which says nothing useful, so the form refuses one up front.

/** Brevo's send endpoint. Filter `devbase/mail_api/brevo/endpoint` (a test or a proxy). */
function devbase_mail_api_brevo_endpoint() {
	return (string) apply_filters( 'devbase/mail_api/brevo/endpoint', 'https://api.brevo.com/v3/smtp/email' );
}

/**
 * Brevo's request body for a message.
 *
 * @param array $message devbase_mail_api_message().
 * @return array
 */
function devbase_mail_api_brevo_body( array $message ) {
	$person = function ( array $address ) {
		return '' !== $address['name'] ? array( 'email' => $address['email'], 'name' => $address['name'] ) : array( 'email' => $address['email'] );
	};

	$body = array(
		'sender'  => $person( $message['from'] ),
		'to'      => array_map( $person, $message['to'] ),
		'subject' => $message['subject'],
	);

	if ( $message['html'] ) {
		$body['htmlContent'] = $message['body'];
	} else {
		$body['textContent'] = $message['body'];
	}

	foreach ( array( 'cc' => 'cc', 'bcc' => 'bcc' ) as $from => $to ) {
		if ( $message[ $from ] ) {
			$body[ $to ] = array_map( $person, $message[ $from ] );
		}
	}

	// Brevo takes one Reply-To address.
	if ( $message['reply_to'] ) {
		$body['replyTo'] = $person( $message['reply_to'][0] );
	}

	if ( $message['headers'] ) {
		$body['headers'] = $message['headers'];
	}

	foreach ( $message['attachments'] as $file ) {
		$content = file_get_contents( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false !== $content ) {
			$body['attachment'][] = array( 'name' => $file['name'], 'content' => base64_encode( $content ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
	}

	return $body;
}

/**
 * Sends a message through Brevo.
 *
 * @param array $message  devbase_mail_api_message().
 * @param array $settings devbase_mail_api_settings( 'brevo' ).
 * @return true|WP_Error
 */
function devbase_mail_api_brevo_send( array $message, array $settings ) {
	$response = wp_remote_post( devbase_mail_api_brevo_endpoint(), array(
		'timeout' => devbase_mail_api_timeout(),
		'headers' => array(
			'api-key'      => (string) ( $settings['brevo_api_key'] ?? '' ),
			'accept'       => 'application/json',
			'content-type' => 'application/json',
		),
		'body'    => wp_json_encode( devbase_mail_api_brevo_body( $message ) ),
	) );

	if ( is_wp_error( $response ) ) {
		/* translators: %s: the connection error. */
		return new WP_Error( 'devbase_mail_api_brevo_unreachable', sprintf( __( 'Brevo could not be reached: %s', 'devbase_parent_theme' ), $response->get_error_message() ) );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( $status >= 200 && $status < 300 ) {
		return true;
	}

	$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$reason = is_array( $answer ) && ! empty( $answer['message'] ) && is_string( $answer['message'] ) ? $answer['message'] : '';
	$code   = is_array( $answer ) && ! empty( $answer['code'] ) && is_string( $answer['code'] ) ? $answer['code'] : '';

	if ( '' === $reason ) {
		/* translators: %d: an HTTP status code. */
		return new WP_Error( 'devbase_mail_api_brevo_refused', sprintf( __( 'Brevo refused the email (HTTP %d).', 'devbase_parent_theme' ), $status ) );
	}

	/* translators: 1: Brevo's reason, 2: Brevo's error code and the HTTP status. */
	return new WP_Error( 'devbase_mail_api_brevo_refused', sprintf( __( 'Brevo refused the email: %1$s (%2$s)', 'devbase_parent_theme' ), $reason, trim( $code . ', HTTP ' . $status, ', ' ) ) );
}

add_filter( 'devbase/mail_api/providers', function ( $providers ) {
	$providers['brevo'] = array(
		'label'    => 'Brevo',
		'send'     => 'devbase_mail_api_brevo_send',
		'required' => array( 'brevo_api_key' ),
		'fields'   => array(
			array(
				'key'          => 'field_devbase_brevo_api_key',
				'label'        => __( 'Brevo API Key', 'devbase_parent_theme' ),
				'name'         => 'brevo_api_key',
				'type'         => 'password',
				'required'     => 1,
				'instructions' => __( 'In Brevo: Settings > SMTP & API > API Keys > Generate a new API key. It starts with xkeysib-. The SMTP key on the same screen does not work here.', 'devbase_parent_theme' ),
			),
		),
	);

	return $providers;
} );

// An SMTP key pasted where the API key goes is the likeliest mistake, and Brevo's own answer to it ("Key not found")
// does not say what is wrong.
add_filter( 'acf/validate_value/key=field_devbase_brevo_api_key', function ( $valid, $value ) {
	if ( true === $valid && is_string( $value ) && 0 === strpos( trim( $value ), 'xsmtpsib-' ) ) {
		return __( 'This is a Brevo SMTP key. The Email API needs an API key, which starts with xkeysib-.', 'devbase_parent_theme' );
	}

	return $valid;
}, 10, 2 );
