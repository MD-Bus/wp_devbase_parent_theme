<?php

// The Warden client: one function that asks a Warden deployment "is this
// licence valid, and is there a newer release for this version."
//
// Warden (github.com/momardia54/Warden) is our own self-hosted licensing +
// update-delivery service — a Cloudflare Worker with D1/R2 behind it. This
// file only talks to its public per-site check endpoint; the management API
// (creating/renewing licences with a Bearer key) is a dashboard concern and
// deliberately has no client here, since a client site must never hold a
// credential that can mint licences.
//
// Endpoint shape (see Warden's own docs/checking.md):
//
//   GET {base}/check/{licence key}?domain={host}&version={installed}&nonce={random}
//
//   {
//     "valid": true, "status": "active", "message": "",
//     "license_key": "WRD-...", "domain": "client-site.org", "nonce": "...",
//     "expires_at": "...", "grace_ends_at": null, "checked_at": "...",
//     "update": {
//       "current": "1.3.0", "available": true,
//       "latest": { "version": "1.4.0", "download_url": "...", "changelog": "...",
//                   "sha256": "...", "signature": "..." }
//     }
//   }
//
// The release is NESTED under update.latest and update.available says whether
// it is newer than the version the site sent (Warden's docs, guide/releases.md).
//
// Three of Warden's documented rules are implemented here rather than left
// to callers, because getting any of them wrong is a security or
// availability bug, not a style choice:
//
// 1. VERIFY THE SIGNATURE FIRST. Every response carries an Ed25519
//    signature over the raw body in X-Warden-Signature-Ed25519. An
//    unverified response is treated as a transport error, never as a
//    verdict — so a hostile network can't answer "your licence is invalid"
//    (or, worse, hand over a download URL) on Warden's behalf.
// 2. ECHO THE NONCE. We send a random nonce and reject any response that
//    doesn't return the same one, plus any response whose license_key
//    isn't the key we asked about. Without this, a captured old response
//    could be replayed indefinitely.
// 3. FAIL SOFT, NEVER CLOSED. On a timeout, a 5xx, a parse failure or a
//    signature failure we return the LAST KNOWN GOOD response instead of
//    "invalid". A Warden outage must never lock a client out of their own
//    site or make their theme look unlicensed.

define( 'DEVBASE_WARDEN_CACHE_TTL', 12 * HOUR_IN_SECONDS );

// How long a last-known-good response may keep authorising UPDATES after
// Warden stops answering. Fail-soft has to be bounded, or "soft" becomes
// "forever" — see devbase_warden_offer_window() for the real scenario
// this closes.
define( 'DEVBASE_WARDEN_OFFER_WINDOW', 7 * DAY_IN_SECONDS );

/**
 * Where the per-track state is cached. One option per track (the theme's
 * directory name), holding the last response plus when it was fetched.
 */
function devbase_warden_state_option( $track ) {
	return 'devbase_warden_state_' . $track;
}

function devbase_warden_get_state( $track ) {
	$state = get_option( devbase_warden_state_option( $track ) );

	return is_array( $state ) ? $state : array();
}

/**
 * This site's domain, as Warden expects it — host only, no scheme or path,
 * since that's what a licence's domain allow-list is written against.
 */
function devbase_warden_domain() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	return is_string( $host ) ? $host : '';
}

/**
 * Verifies an Ed25519 signature over the exact raw response body.
 *
 * Returns false when libsodium isn't available at all, rather than
 * skipping verification — "we couldn't check" must behave identically to
 * "the check failed", or the guarantee is worthless on exactly the hosts
 * where it matters. (libsodium has been in PHP core since 7.2, so this
 * should never actually trigger; it's here so the failure is a refusal
 * rather than a silent bypass.)
 */
function devbase_warden_verify_signature( $signature, $body, $public_key_base64 ) {
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		error_log( 'devbase_parent_theme: libsodium unavailable, cannot verify Warden signature' );
		return false;
	}

	if ( empty( $signature ) || empty( $public_key_base64 ) ) {
		return false;
	}

	$sig = base64_decode( (string) $signature, true );
	$key = base64_decode( (string) $public_key_base64, true );

	if ( false === $sig || false === $key ) {
		return false;
	}

	if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
		return false;
	}

	try {
		return sodium_crypto_sign_verify_detached( $sig, $body, $key );
	} catch ( Exception $e ) {
		// sodium throws rather than returning false on malformed input.
		return false;
	}
}

/**
 * Asks Warden about one track, at most once per DEVBASE_WARDEN_CACHE_TTL.
 *
 * @param string $track      The theme directory name this licence covers.
 * @param string $licence    The WRD-... licence key.
 * @param string $version    The installed version, for update checking.
 * @param bool   $force      Skip the cache (used by the "Check Now" button).
 * @return array The last known good response, or array() if there has never
 *               been one.
 */
function devbase_warden_check( $track, $licence, $version, $force = false ) {
	$state  = devbase_warden_get_state( $track );
	$cached = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();

	if ( '' === trim( (string) $licence ) ) {
		return array();
	}

	$base_url   = devbase_warden_base_url();
	$public_key = devbase_warden_public_key();

	if ( '' === $base_url || '' === $public_key ) {
		// Not configured yet — no endpoint to ask, and no key to verify a
		// reply with. Returning the cache (usually empty) keeps this a
		// no-op rather than an error state.
		return $cached;
	}

	$checked_at = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;

	if ( ! $force && ( time() - $checked_at ) < DEVBASE_WARDEN_CACHE_TTL ) {
		return $cached;
	}

	$nonce = bin2hex( random_bytes( 16 ) );

	$url = trailingslashit( $base_url ) . 'check/' . rawurlencode( $licence );
	$url = add_query_arg(
		array(
			'domain'  => devbase_warden_domain(),
			'version' => $version,
			'nonce'   => $nonce,
		),
		$url
	);

	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

	// Record the attempt regardless of outcome, so a hard-down Warden is
	// retried once per TTL rather than on every single update check.
	$state['checked_at'] = time();

	$fail = function ( $reason ) use ( $track, &$state, $cached ) {
		$state['last_error'] = $reason;
		update_option( devbase_warden_state_option( $track ), $state, false );
		error_log( "devbase_parent_theme: Warden check for {$track} failed — {$reason}" );

		// Fail soft: the previous good answer stands.
		return $cached;
	};

	if ( is_wp_error( $response ) ) {
		return $fail( $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( 200 !== $code ) {
		return $fail( "HTTP {$code}" );
	}

	$signature = wp_remote_retrieve_header( $response, 'x-warden-signature-ed25519' );

	if ( ! devbase_warden_verify_signature( $signature, $body, $public_key ) ) {
		return $fail( 'signature verification failed' );
	}

	$result = json_decode( $body, true );

	if ( ! is_array( $result ) ) {
		return $fail( 'malformed JSON' );
	}

	// Replay protection: the response must be about the key we asked for,
	// and must echo our nonce back.
	if ( ! isset( $result['license_key'] ) || ! hash_equals( (string) $licence, (string) $result['license_key'] ) ) {
		return $fail( 'response was for a different licence key' );
	}

	if ( ! isset( $result['nonce'] ) || ! hash_equals( $nonce, (string) $result['nonce'] ) ) {
		return $fail( 'nonce mismatch (possible replay)' );
	}

	$state['response']     = $result;
	$state['last_error']   = '';
	// Distinct from checked_at, which records every ATTEMPT including
	// failures. This is the one the offer window below measures against.
	$state['succeeded_at'] = time();
	update_option( devbase_warden_state_option( $track ), $state, false );

	return $result;
}

/**
 * Whether a track's last successful response is recent enough to still
 * authorise installing an update.
 *
 * Fail-soft (returning the last known good response when Warden can't be
 * reached) is correct and required — Warden's own docs say so, and a
 * client must never be locked out by an outage. But applied without a
 * bound it has a real hole, found by testing the exact scenario rather
 * than reasoning about it:
 *
 *   1. A licence is valid, and the response carries an update offer.
 *   2. The licence is then DELETED from Warden, so `/check/` starts
 *      returning an unsigned 404.
 *   3. An unsigned non-200 is a transport error, not a verdict (correctly
 *      — we must not let an unauthenticated response revoke a licence),
 *      so the cached response stands...
 *   4. ...and that cached response keeps offering version 99.0.0 FOREVER.
 *      Verified: it did exactly that before this window existed.
 *
 * Not site-breaking — the download itself would be rejected by Warden, so
 * Theme_Upgrader just fails and the site keeps running on its current
 * version. But WordPress would show a permanent update that can never
 * install, retried daily by auto-update, for a licence that no longer
 * exists.
 *
 * Note this bounds UPDATES only. The cached response is still returned for
 * display, so the Status tab can keep showing the last known licence state
 * alongside the error — "here's what we last knew, and we can't currently
 * reach Warden" is strictly more useful than a blank.
 *
 * An expired or suspended licence does NOT depend on this: Warden answers
 * those with a signed 200 carrying `valid: false`, which
 * devbase_warden_pending_update() rejects outright. This window only
 * covers the case where Warden stops answering about a licence at all.
 */
function devbase_warden_offer_window( $track ) {
	$state = devbase_warden_get_state( $track );

	$succeeded_at = isset( $state['succeeded_at'] ) ? (int) $state['succeeded_at'] : 0;

	if ( 0 === $succeeded_at ) {
		// Never had a verified success. Any response we hold came from
		// before this field existed, so treat it as in-window rather than
		// cutting off a site mid-upgrade of this theme.
		return ! empty( $state['response'] );
	}

	$window = (int) apply_filters( 'devbase/updates/offer_window', DEVBASE_WARDEN_OFFER_WINDOW, $track );

	return ( time() - $succeeded_at ) < $window;
}

/**
 * The update offer inside a verified Warden response, if it's both present
 * and actually newer than what's installed. Returns the release itself
 * (Warden's `update.latest`: version, download_url, sha256, ...).
 *
 * Warden's documented shape is `update.available` plus the release nested
 * under `update.latest`. Warden decides whether anything is offered (it knows
 * the release track and the licence's status), but this re-checks the version
 * locally anyway: a release track misconfigured to a lower version must
 * never produce a "downgrade" that WordPress would then install.
 */
function devbase_warden_pending_update( array $response, $installed_version ) {
	if ( empty( $response['valid'] ) ) {
		return null;
	}

	if ( empty( $response['update'] ) || ! is_array( $response['update'] ) ) {
		return null;
	}

	$offer = $response['update'];

	if ( empty( $offer['available'] ) || empty( $offer['latest'] ) || ! is_array( $offer['latest'] ) ) {
		return null;
	}

	$update = $offer['latest'];

	if ( empty( $update['version'] ) || empty( $update['download_url'] ) ) {
		return null;
	}

	if ( ! version_compare( $update['version'], $installed_version, '>' ) ) {
		return null;
	}

	return $update;
}
