<?php

// devbase_form_submit() — the whole server side of an AJAX form in one call.
// Before it, every form's handler repeated the same steps (honeypot, rate
// limit, validation, the error replies, the pipeline call) and differed only
// in its fields and its rules; the first client site's contact form had
// forgotten the rate limit that its newsletter form had. Now a handler is:
//
//   add_action( 'wp_ajax_nopriv_my_contact', function () {
//       devbase_form_send( 'contact_form', array(
//           'fields'   => array( 'name' => 'Name', 'email' => array( 'label' => 'Email', 'sanitize' => 'sanitize_email' ) ),
//           'validate' => function ( $values ) {
//               return is_email( $values['email'] ) ? array() : array( 'email' => 'Enter a valid email address.' );
//           },
//       ) );
//   } );
//
// paired with devbase_form_fields() in the form markup (fields.php) and the
// shared script assets/js/forms.js in the browser.
//
// The steps, in order, and what each answers:
//
//   1. honeypot filled     200 success, nothing stored (a bot thinks it worked)
//   2. rate limit          429 { code: rate_limited }
//   3. nonce               403 { code: blocked }
//   4. validate            422 { code: invalid, field, message, errors: [{field, message}] }
//   5. CAPTCHA             403 { code: blocked }
//   6. before_log false    200 success, nothing stored (e.g. the same signup twice)
//   7. stored              200 success, with whatever 'success' returns
//
// Validation runs BEFORE the CAPTCHA on purpose: a CAPTCHA token works once,
// so checking it first would spend it on a submission that is then sent
// back for a typo, and the visitor's corrected retry would fail. Validation
// costs nothing and reveals nothing, and the nonce and rate limit already
// stand in front of it. Nothing is stored until every gate has passed, so
// the fail-closed rule is unchanged: no valid nonce or CAPTCHA, no
// submission.

/**
 * Runs a submission through every step above and returns the answer,
 * without sending it (devbase_form_send() sends it).
 *
 * @param string $form_name The registered form name (inc/forms/registry or the
 *                          devbase/forms/registered_forms filter), the same one
 *                          passed to devbase_form_fields().
 * @param array  $args {
 *     @type array    $fields     field_name => label, or field_name => [ 'label', 'sanitize' ]:
 *                                the devbase_form_build_content() map. Only these are stored.
 *     @type callable $validate   fn( array $values, array $data ): array of field_name => message,
 *                                empty when valid. $values are the sanitized values by field
 *                                name; $data the raw submission (for anything not stored).
 *     @type callable $before_log fn( array $content, array $data ): false to answer success
 *                                WITHOUT storing. Runs only after every gate has passed.
 *     @type callable $after_log  fn( int $post_id, array $content, array $data ): runs once stored.
 *     @type callable $success    fn( array $values, array $data ): array of data for the reply.
 *     @type array    $messages   Visitor-facing text for 'rate_limited' and 'blocked'.
 *     @type string   $honeypot   The honeypot field name. Default 'website', as printed by
 *                                devbase_form_fields().
 *     @type array    $data       The submission. Default $_POST.
 * }
 * @return array{status:int, success:bool, data:array}
 */
function devbase_form_submit( $form_name, array $args = array() ) {
	$args = wp_parse_args( $args, array(
		'fields'     => array(),
		'validate'   => null,
		'before_log' => null,
		'after_log'  => null,
		'success'    => null,
		'messages'   => array(),
		'honeypot'   => 'website',
		'data'       => null,
	) );

	$data     = is_array( $args['data'] ) ? $args['data'] : $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$messages = wp_parse_args( $args['messages'], array(
		'rate_limited' => __( 'Too many attempts. Please try again in a few minutes.', 'devbase_parent_theme' ),
		'blocked'      => __( 'We could not verify your submission. Please reload the page and try again.', 'devbase_parent_theme' ),
	) );

	$answer = function ( $status, $success, array $body = array() ) {
		return array( 'status' => $status, 'success' => $success, 'data' => $body );
	};

	if ( '' !== $args['honeypot'] && ! empty( $data[ $args['honeypot'] ] ) ) {
		return $answer( 200, true );
	}

	if ( devbase_form_rate_limited( $form_name ) ) {
		return $answer( 429, false, array( 'code' => 'rate_limited', 'message' => $messages['rate_limited'] ) );
	}

	if ( ! devbase_form_nonce_valid( $form_name, $data ) ) {
		return $answer( 403, false, array( 'code' => 'blocked', 'message' => $messages['blocked'] ) );
	}

	$content = devbase_form_build_content( $data, $args['fields'] );
	$values  = wp_list_pluck( $content, 'value' );

	if ( is_callable( $args['validate'] ) ) {
		$errors = array();

		foreach ( (array) call_user_func( $args['validate'], $values, $data ) as $field => $message ) {
			$errors[] = array( 'field' => (string) $field, 'message' => (string) $message );
		}

		if ( $errors ) {
			// field/message of the first error at the top level too, for a script
			// that shows one error at a time.
			return $answer( 422, false, array(
				'code'    => 'invalid',
				'field'   => $errors[0]['field'],
				'message' => $errors[0]['message'],
				'errors'  => $errors,
			) );
		}
	}

	if ( ! devbase_security_verify_request( $data ) ) {
		return $answer( 403, false, array( 'code' => 'blocked', 'message' => $messages['blocked'] ) );
	}

	$reply = is_callable( $args['success'] ) ? (array) call_user_func( $args['success'], $values, $data ) : array();

	if ( is_callable( $args['before_log'] ) && false === call_user_func( $args['before_log'], $content, $data ) ) {
		return $answer( 200, true, $reply );
	}

	$post_id = devbase_form_store( $form_name, $content );

	if ( ! $post_id ) {
		return $answer( 500, false, array( 'code' => 'not_stored', 'message' => $messages['blocked'] ) );
	}

	if ( is_callable( $args['after_log'] ) ) {
		call_user_func( $args['after_log'], $post_id, $content, $data );
	}

	return $answer( 200, true, $reply );
}

/**
 * devbase_form_submit(), sent as the JSON reply of an AJAX handler
 * ({ success, data } with the status code), then exits.
 */
function devbase_form_send( $form_name, array $args = array() ) {
	$result = devbase_form_submit( $form_name, $args );

	// (object): an empty reply is {} like every other, not [].
	wp_send_json( array( 'success' => $result['success'], 'data' => (object) $result['data'] ), $result['status'] );
}
