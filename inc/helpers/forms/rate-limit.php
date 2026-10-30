<?php

// devbase_form_rate_limited( $form_name ) — the rate limit every form gets
// from the pipeline. devbase_form_log_submission() and devbase_form_submit()
// both call it, so a form author never adds one by hand (and can't forget to:
// the contact form on the first client site had none).
//
// Counted per form and per visitor address, in a transient, over a fixed
// window: the first request opens the window, and once `max` requests have
// been made inside it every further one is refused until it ends. Every
// request counts, valid or not, so guessing at a form costs the same as
// using it. Defaults to 5 requests per 10 minutes, which a person never
// reaches (the forms validate in the browser before sending); a site changes
// it with the devbase/forms/rate_limit filter, per form if it wants.
//
// The address is REMOTE_ADDR. Behind a proxy or CDN that is the proxy's
// address, so every visitor would share one bucket: a site behind one
// returns the real client address from devbase/forms/client_ip (e.g. from a
// header the proxy sets and the server trusts), never from a header a
// visitor can write themselves.

/**
 * The limit for a form: array( 'max' => int, 'window' => seconds ).
 * 'max' 0 turns the limit off for that form.
 */
function devbase_form_rate_limit( $form_name ) {
	$limit = apply_filters( 'devbase/forms/rate_limit', array( 'max' => 5, 'window' => 10 * MINUTE_IN_SECONDS ), $form_name );

	return array(
		'max'    => max( 0, (int) ( $limit['max'] ?? 5 ) ),
		'window' => max( 1, (int) ( $limit['window'] ?? 10 * MINUTE_IN_SECONDS ) ),
	);
}

/** The visitor address requests are counted against. */
function devbase_form_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return (string) apply_filters( 'devbase/forms/client_ip', $ip );
}

/**
 * Counts one request to this form from this visitor; true when it is over
 * the limit (and must be refused). A refused request is not counted, so the
 * window still ends when it was going to.
 *
 * @param string $form_name The registered form name.
 * @return bool
 */
function devbase_form_rate_limited( $form_name ) {
	$limit = devbase_form_rate_limit( $form_name );

	if ( 0 === $limit['max'] ) {
		return false;
	}

	$key    = 'devbase_form_rl_' . md5( $form_name . '|' . devbase_form_client_ip() );
	$bucket = get_transient( $key );
	$now    = time();

	if ( ! is_array( $bucket ) || ( $now - (int) $bucket['start'] ) >= $limit['window'] ) {
		$bucket = array( 'start' => $now, 'count' => 0 );
	}

	if ( $bucket['count'] >= $limit['max'] ) {
		return true;
	}

	++$bucket['count'];
	set_transient( $key, $bucket, max( 1, $limit['window'] - ( $now - (int) $bucket['start'] ) ) );

	return false;
}
