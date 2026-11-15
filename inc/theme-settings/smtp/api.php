<?php

// Sends the site's mail through an email service's HTTPS API instead of SMTP, for hosts that block the SMTP ports
// (25, 465, 587). Picked on Theme Settings > Email > Email API; only one way of sending is on at a time.
//
// HOW IT FITS. It takes over inside wp_mail() through WordPress's own `pre_wp_mail` filter, so every mail the site
// sends goes this way: form notifications (Form Destinations rows of type "email"), password resets, WooCommerce,
// plugins and the Send Test Email button. WordPress has already applied its `wp_mail` filter by then. The theme keeps
// doing what it does for SMTP: `wp_mail_failed` and `wp_mail_succeeded` fire exactly as WordPress fires them, so the
// failure log (failures.php), the health report's `mail` field, the form-dispatch retries and the test button's real
// error message all work unchanged.
//
// ONE ADAPTER PER SERVICE. There is no common standard for these APIs, so this file builds one neutral message
// (devbase_mail_api_message()) and each service's adapter turns it into that service's request and reads its answer
// back: true, or a WP_Error with the service's own reason. Adding a service is one more entry in
// devbase_mail_api_providers() (filter `devbase/mail_api/providers`) and one adapter file; nothing here changes.
//
// SMTP WINS. If both switches are somehow on (a value written by code, not the form, which refuses it), SMTP keeps
// sending as before and this stays out of the way.

/**
 * The services that can send, id => definition:
 *   label     (string)   shown in the "Service" choice;
 *   send      (callable) fn( array $message, array $settings ): true|WP_Error;
 *   fields    (array)    the service's own SCF fields (key, label, name, type...), shown once it is chosen;
 *   required  (string[]) the field NAMES that must be filled before mail goes this way.
 *
 * @return array<string,array>
 */
function devbase_mail_api_providers() {
	$providers = apply_filters( 'devbase/mail_api/providers', array() );

	if ( ! is_array( $providers ) ) {
		return array();
	}

	return array_filter( $providers, function ( $provider ) {
		return is_array( $provider ) && ! empty( $provider['label'] ) && isset( $provider['send'] ) && is_callable( $provider['send'] );
	} );
}

/**
 * The saved settings for a service: its own fields plus the shared sender, by field name.
 *
 * @param string $id A provider id.
 * @return array<string,string>
 */
function devbase_mail_api_settings( $id ) {
	$providers = devbase_mail_api_providers();
	$names     = array( 'mail_api_from_email', 'mail_api_from_name' );

	foreach ( (array) ( $providers[ $id ]['fields'] ?? array() ) as $field ) {
		if ( ! empty( $field['name'] ) ) {
			$names[] = (string) $field['name'];
		}
	}

	$settings = array();

	foreach ( $names as $name ) {
		$value             = function_exists( 'get_field' ) ? get_field( $name, 'option' ) : '';
		$settings[ $name ] = is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	return $settings;
}

/**
 * The service mail goes through right now, or '' when mail is not sent through an API: switched off, SMTP on, no
 * service chosen, or the chosen one not fully set up (an incomplete setup leaves WordPress's own mail alone, as an
 * incomplete SMTP setup does, instead of failing every mail).
 */
function devbase_mail_api_active_provider() {
	if ( ! function_exists( 'get_field' ) || ! get_field( 'mail_api_enabled', 'option' ) || get_field( 'smtp_enabled', 'option' ) ) {
		return '';
	}

	$id        = (string) get_field( 'mail_api_provider', 'option' );
	$providers = devbase_mail_api_providers();

	if ( ! isset( $providers[ $id ] ) ) {
		return '';
	}

	$settings = devbase_mail_api_settings( $id );

	if ( ! is_email( $settings['mail_api_from_email'] ) ) {
		return '';
	}

	foreach ( (array) ( $providers[ $id ]['required'] ?? array() ) as $name ) {
		if ( '' === ( $settings[ $name ] ?? '' ) ) {
			return '';
		}
	}

	return $id;
}

/**
 * Splits a list of addresses the way wp_mail() takes them (an array, or a comma-separated string; each "Name <a@b>" or
 * "a@b") into { email, name } pairs. Invalid addresses are dropped.
 *
 * @param string|array $list Addresses.
 * @return array<int, array{email:string, name:string}>
 */
function devbase_mail_api_addresses( $list ) {
	if ( ! is_array( $list ) ) {
		$list = explode( ',', (string) $list );
	}

	$out = array();

	foreach ( $list as $item ) {
		$item = trim( (string) $item );
		$name = '';

		if ( preg_match( '/(.*)<(.+)>/', $item, $m ) ) {
			$name = trim( trim( $m[1] ), '"\'' );
			$item = trim( $m[2] );
		}

		if ( is_email( $item ) ) {
			$out[] = array( 'email' => $item, 'name' => $name );
		}
	}

	return $out;
}

/**
 * Turns wp_mail()'s arguments into the neutral message every adapter reads. Headers are read as wp_mail() reads them;
 * From is the sender saved on the Email API tab (the one the service has verified), as the SMTP route does with its
 * own From Email, and WordPress's content type and charset filters still apply.
 *
 * @param array $atts     wp_mail()'s to, subject, message, headers, attachments.
 * @param array $settings devbase_mail_api_settings() for the service.
 * @return array{from:array, to:array, cc:array, bcc:array, reply_to:array, subject:string, body:string, html:bool, charset:string, headers:array, attachments:array}
 */
function devbase_mail_api_message( array $atts, array $settings ) {
	$headers = $atts['headers'] ?? array();

	if ( ! is_array( $headers ) ) {
		$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
	}

	$cc           = array();
	$bcc          = array();
	$reply_to     = array();
	$custom       = array();
	$content_type = 'text/plain';
	$charset      = '';

	foreach ( $headers as $header ) {
		if ( false === strpos( (string) $header, ':' ) ) {
			continue;
		}

		list( $name, $value ) = explode( ':', trim( (string) $header ), 2 );
		$name  = trim( $name );
		$value = trim( $value );

		switch ( strtolower( $name ) ) {
			case 'from':
			case 'mime-version':
				// From is the verified sender (below); MIME is the service's business.
				break;
			case 'cc':
				$cc = array_merge( $cc, explode( ',', $value ) );
				break;
			case 'bcc':
				$bcc = array_merge( $bcc, explode( ',', $value ) );
				break;
			case 'reply-to':
				$reply_to = array_merge( $reply_to, explode( ',', $value ) );
				break;
			case 'content-type':
				if ( false !== strpos( $value, ';' ) ) {
					list( $type, $params ) = explode( ';', $value, 2 );
					$content_type = trim( $type );

					if ( preg_match( '/charset\s*=\s*"?([^";\s]+)/i', $params, $m ) ) {
						$charset = $m[1];
					}
				} elseif ( '' !== $value ) {
					$content_type = $value;
				}
				break;
			default:
				// A line break in a value would be a header of its own; only clean names and values go through.
				if ( preg_match( '/^[A-Za-z0-9-]+$/', $name ) && ! preg_match( '/[\r\n]/', $value ) ) {
					$custom[ $name ] = $value;
				}
		}
	}

	$content_type = (string) apply_filters( 'wp_mail_content_type', $content_type );
	$charset      = (string) apply_filters( 'wp_mail_charset', '' !== $charset ? $charset : get_bloginfo( 'charset' ) );

	$attachments = $atts['attachments'] ?? array();

	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
	}

	$files = array();

	foreach ( $attachments as $key => $path ) {
		$path = trim( (string) $path );

		// As wp_mail() does, an attachment that cannot be read is left out rather than stopping the mail.
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			continue;
		}

		$files[] = array(
			'name' => is_string( $key ) && '' !== $key ? $key : wp_basename( $path ),
			'path' => $path,
		);
	}

	$from_name = '' !== ( $settings['mail_api_from_name'] ?? '' ) ? $settings['mail_api_from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	return array(
		'from'        => array( 'email' => (string) ( $settings['mail_api_from_email'] ?? '' ), 'name' => $from_name ),
		'to'          => devbase_mail_api_addresses( $atts['to'] ?? array() ),
		'cc'          => devbase_mail_api_addresses( $cc ),
		'bcc'         => devbase_mail_api_addresses( $bcc ),
		'reply_to'    => devbase_mail_api_addresses( $reply_to ),
		'subject'     => (string) ( $atts['subject'] ?? '' ),
		'body'        => (string) ( $atts['message'] ?? '' ),
		// multipart/alternative is WooCommerce's "Multipart" email type: the body is the HTML, and WooCommerce adds the
		// plain-text part itself in phpmailer_init, which an API route never runs. The service gets the HTML.
		'html'        => in_array( strtolower( $content_type ), array( 'text/html', 'multipart/alternative' ), true ),
		'charset'     => $charset,
		'headers'     => $custom,
		'attachments' => $files,
	);
}

/**
 * Sends one wp_mail() call through the active service. Returns what wp_mail() returns and fires what it fires.
 *
 * @param null|bool $return Another filter's answer; anything but null is left alone.
 * @param array     $atts   wp_mail()'s arguments, after its `wp_mail` filter.
 * @return null|bool
 */
function devbase_mail_api_pre_wp_mail( $return, $atts ) {
	if ( null !== $return || ! is_array( $atts ) ) {
		return $return;
	}

	$id = devbase_mail_api_active_provider();

	if ( '' === $id ) {
		return $return;
	}

	$provider = devbase_mail_api_providers()[ $id ];
	$settings = devbase_mail_api_settings( $id );
	$message  = devbase_mail_api_message( $atts, $settings );

	// The same data wp_mail() passes to its own actions, so anything listening sees no difference.
	$mail_data = array(
		'to'          => array_column( $message['to'], 'email' ),
		'subject'     => $message['subject'],
		'message'     => $message['body'],
		'headers'     => $atts['headers'] ?? array(),
		'attachments' => array_column( $message['attachments'], 'path' ),
	);

	if ( ! $message['to'] ) {
		$result = new WP_Error( 'devbase_mail_api_no_recipient', __( 'You must provide at least one recipient email address.', 'devbase_parent_theme' ) );
	} else {
		$result = call_user_func( $provider['send'], $message, $settings );
	}

	if ( true === $result ) {
		do_action( 'wp_mail_succeeded', $mail_data );

		return true;
	}

	$reason = is_wp_error( $result ) ? $result->get_error_message() : __( 'The email service did not accept the message.', 'devbase_parent_theme' );

	do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $reason, $mail_data ) );

	return false;
}
add_filter( 'pre_wp_mail', 'devbase_mail_api_pre_wp_mail', 10, 2 );

/** How long to wait for a service's answer, in seconds. Filter `devbase/mail_api/timeout`; the SMTP route waits 15. */
function devbase_mail_api_timeout() {
	return max( 1, (int) apply_filters( 'devbase/mail_api/timeout', 15 ) );
}

/**
 * Which way of sending is on, and which one a save must refuse. Only one may be on: the switch being turned on while
 * the other is already on (saved, and still on in the same form) gets the error; when both are turned on in one save,
 * the Email API one does.
 *
 * @param string $which    'smtp' or 'api': the switch being validated.
 * @param mixed  $value    Its posted value.
 * @param array  $posted   The posted acf[] values.
 * @return string An error message, or '' when the save is fine.
 */
function devbase_mail_route_conflict( $which, $value, array $posted ) {
	$keys  = array( 'smtp' => 'field_devbase_smtp_enabled', 'api' => 'field_devbase_mail_api_enabled' );
	$names = array( 'smtp' => 'smtp_enabled', 'api' => 'mail_api_enabled' );
	$other = 'smtp' === $which ? 'api' : 'smtp';

	if ( empty( $value ) || empty( $posted[ $keys[ $other ] ] ) ) {
		return '';
	}

	$other_saved = function_exists( 'get_field' ) && get_field( $names[ $other ], 'option' );
	$this_saved  = function_exists( 'get_field' ) && get_field( $names[ $which ], 'option' );

	// The one already on keeps its place; the newcomer is refused.
	$refuse = $other_saved && ! $this_saved ? $which : ( $this_saved && ! $other_saved ? $other : 'api' );

	if ( $refuse !== $which ) {
		return '';
	}

	return 'api' === $which
		? __( 'SMTP is on. Only one way of sending can be on: switch SMTP off on the SMTP tab first.', 'devbase_parent_theme' )
		: __( 'The Email API is on. Only one way of sending can be on: switch it off on the Email API tab first.', 'devbase_parent_theme' );
}

foreach ( array( 'smtp' => 'field_devbase_smtp_enabled', 'api' => 'field_devbase_mail_api_enabled' ) as $devbase_route => $devbase_key ) {
	add_filter( 'acf/validate_value/key=' . $devbase_key, function ( $valid, $value ) use ( $devbase_route ) {
		if ( true !== $valid ) {
			return $valid;
		}

		$posted = isset( $_POST['acf'] ) && is_array( $_POST['acf'] ) ? wp_unslash( $_POST['acf'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		$error  = devbase_mail_route_conflict( $devbase_route, $value, $posted );

		return '' === $error ? $valid : $error;
	}, 10, 2 );
}
unset( $devbase_route, $devbase_key );
