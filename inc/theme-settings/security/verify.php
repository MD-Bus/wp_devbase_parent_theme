<?php

// Reusable API for any form in this theme that needs a CAPTCHA: it never
// talks to Turnstile or reCAPTCHA directly. It asks these functions "which
// provider is active" and "does this token check out," and they handle
// whichever one is actually configured. Security Settings enforces that at
// most one provider is enabled at once (see settings.php), so there's never
// an ambiguous "both active" case here.

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

		wp_enqueue_script( 'devbase-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );

		return '<div class="g-recaptcha" data-sitekey="' . esc_attr( $site_key ) . '"></div>';
	}

	return '';
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

	return ! empty( $body['success'] );
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
