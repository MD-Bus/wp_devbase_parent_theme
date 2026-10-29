<?php

// Reusable API for any form in this theme that needs a CAPTCHA: it never
// talks to Turnstile or reCAPTCHA directly. It asks these functions "which
// provider is active" and "does this token check out," and they handle
// whichever one is actually configured. Security Settings enforces that at
// most one provider is enabled at once (see settings.php), so there's never
// an ambiguous "both active" case here.
//
// reCAPTCHA here is v3 ONLY: score-based, invisible, no checkbox. A v2 or an
// Enterprise key is the wrong type and Google answers "Invalid key type". The
// page loads Google's script with the site key (render=KEY), assets/js/
// recaptcha-v3.js keeps a fresh one-use token in a hidden g-recaptcha-response
// field of each form, and the server accepts it only when Google says it is
// genuine, is for the expected action, and scores at least the minimum set in
// Security Settings.

/**
 * Which provider (if any) is currently active.
 *
 * @return string|null 'turnstile', 'recaptcha', or null if neither is enabled.
 */
function devbase_security_active_provider() {
	if ( get_field( 'turnstile_enabled', 'option' ) ) {
		return 'turnstile';
	}

	if ( get_field( 'recaptcha_enabled', 'option' ) ) {
		return 'recaptcha';
	}

	return null;
}

/**
 * The widget markup for the currently active provider, ready to echo/print
 * straight into a form. Empty string if no provider is active — forms
 * should just skip rendering (and skip verification) in that case.
 */
function devbase_security_widget_html() {
	$provider = devbase_security_active_provider();

	if ( 'turnstile' === $provider ) {
		$site_key = get_field( 'turnstile_site_key', 'option' );
		if ( empty( $site_key ) ) {
			return '';
		}

		wp_enqueue_script( 'devbase-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );

		return '<div class="cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '"></div>';
	}

	if ( 'recaptcha' === $provider ) {
		$site_key = get_field( 'recaptcha_site_key', 'option' );
		if ( empty( $site_key ) ) {
			return '';
		}

		// v3 needs the site key in the script address. No version argument: Google's
		// script must not get a ?ver= added to it.
		wp_enqueue_script( 'devbase-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'devbase-recaptcha-v3', get_template_directory_uri() . '/assets/js/recaptcha-v3.js', array(), (string) filemtime( get_template_directory() . '/assets/js/recaptcha-v3.js' ), true );

		return '<input type="hidden" name="g-recaptcha-response" value="" data-devbase-recaptcha data-sitekey="' . esc_attr( $site_key ) . '" data-action="' . esc_attr( devbase_security_recaptcha_action() ) . '">';
	}

	return '';
}

/**
 * The action name a v3 token is minted for and must be checked against, so a
 * token earned on one kind of page cannot be replayed as another. Filterable.
 */
function devbase_security_recaptcha_action() {
	return (string) apply_filters( 'devbase/security/recaptcha_action', 'submit' );
}

/**
 * The lowest v3 score (0.0 bot ... 1.0 human) that is let through. Set in
 * Security Settings; 0.5 is Google's own default for a form.
 */
function devbase_security_recaptcha_min_score() {
	$score = get_field( 'recaptcha_min_score', 'option' );

	return ( is_numeric( $score ) && (float) $score > 0 && (float) $score <= 1 ) ? (float) $score : 0.5;
}

/**
 * Verifies a submitted CAPTCHA token against whichever provider is active.
 *
 * @param string $token The token submitted with the form (field name
 *                       'cf-turnstile-response' for Turnstile, 'g-recaptcha-response'
 *                       for reCAPTCHA — read the right one before calling this).
 * @return bool True if verification passed, or if no provider is active
 *              (nothing to check, so nothing blocks the form). False only
 *              when a provider is active and the token failed verification.
 */
function devbase_security_verify( $token ) {
	$provider = devbase_security_active_provider();

	if ( null === $provider ) {
		return true;
	}

	if ( empty( $token ) ) {
		return false;
	}

	if ( 'turnstile' === $provider ) {
		$secret = get_field( 'turnstile_secret_key', 'option' );
		$url    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	} else {
		$secret = get_field( 'recaptcha_secret_key', 'option' );
		$url    = 'https://www.google.com/recaptcha/api/siteverify';
	}

	if ( empty( $secret ) ) {
		return false;
	}

	$response = wp_remote_post( $url, array(
		'timeout' => 10,
		'body'    => array(
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		),
	) );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['success'] ) ) {
		return false;
	}

	if ( 'recaptcha' !== $provider ) {
		return true;
	}

	// v3: "success" only means Google recognised the token. Whether to trust the
	// visitor is the score, and the token must be for the action we asked for.
	// An answer with no score is not a v3 answer (a v2 key's, say), so it fails.
	if ( ! isset( $body['score'] ) || ! is_numeric( $body['score'] ) ) {
		return false;
	}

	if ( isset( $body['action'] ) && devbase_security_recaptcha_action() !== $body['action'] ) {
		return false;
	}

	return (float) $body['score'] >= devbase_security_recaptcha_min_score();
}

/**
 * Convenience wrapper for the common case: read the right $_POST field for
 * whichever provider is active and verify it in one call. Returns true
 * (nothing to check) when no provider is active, same as devbase_security_verify().
 */
function devbase_security_verify_request() {
	$provider = devbase_security_active_provider();

	if ( null === $provider ) {
		return true;
	}

	$field = 'turnstile' === $provider ? 'cf-turnstile-response' : 'g-recaptcha-response';

	$token = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	return devbase_security_verify( $token );
}
