<?php

// The brand credit ("Designed by <brand>"): downloads a brand's PUBLIC details
// from Brand Relay, keeps a local copy, and builds the credit from that copy.
//
// ONE SETTING. Theme Settings > Miscellaneous > Credit asks for a single
// value, the brand's public address, e.g.
//
//   https://relay.example.com/v1/brand/yourbrand
//
// WHAT IT DOES
//
// - Downloads that address (no key, no private data) when the setting is
//   saved, then once a day through WP-Cron, and once shortly after it is first
//   set. Brand Relay's own `ttl` is honoured as a minimum gap between
//   automatic downloads.
// - Saves the result in ONE non-autoloaded option, `devbase_credit_data`.
// - devbase_credit() and devbase_credit_html() read ONLY that copy. Rendering
//   a page never makes an HTTP request, so a slow or dead Brand Relay can't
//   slow a page down or break it.
//
// THE LOCAL COPY IS AN ALLOWLIST. devbase_credit_clean() builds a NEW array
// from named fields and every value is validated and escaped on the way in;
// the response is never stored whole. Anything Brand Relay adds later, and
// anything not needed for a credit (`params`, `rules`, `embed`, site data),
// is dropped. This is deliberate: the credit must be a public-data cache that
// can never end up holding a private value, and remote HTML/CSS (`embed`) is
// never printed, so a compromised Brand Relay account can't inject markup
// into client sites. The markup is built here from plain fields.
//
// FAIL SOFT, same rule as the Warden client. If a download fails, or returns
// something unexpected, the last good copy stays. If there has never been one,
// no credit is shown. A brand that Brand Relay marks `show: false` shows no
// credit.
//
// THE SHAPE A THEME GETS
//
//   devbase_credit() -> array|null
//   array(
//     'message'    => 'Designed by',                       // may be ''
//     'link'       => array( 'text', 'url', 'rel', 'follow' ),
//     'brand'      => array( 'name', 'url' ),
//     'logo'       => array( 'url', 'alt' ) | null,
//     'fetched_at' => int,
//   )
//
// devbase_credit_html() returns "message <a ...>text</a>" ready to print, every
// part escaped. A child theme decides where it sits and how it looks.

define( 'DEVBASE_CREDIT_OPTION', 'devbase_credit_data' );
define( 'DEVBASE_CREDIT_HOOK', 'devbase_credit_refresh' );

// Never download more often than this on a schedule, whatever Brand Relay's
// ttl says, and never trust a ttl longer than a day.
define( 'DEVBASE_CREDIT_MIN_INTERVAL', 15 * MINUTE_IN_SECONDS );

// rel tokens a brand's link may carry. `noopener` is always added by us.
const DEVBASE_CREDIT_REL_TOKENS = array( 'nofollow', 'sponsored', 'ugc', 'noreferrer' );

/**
 * The configured brand address, or ''. https only, except on a local
 * environment.
 */
function devbase_credit_brand_url() {
	if ( ! function_exists( 'get_field' ) ) {
		return '';
	}

	$url = esc_url_raw( trim( (string) get_field( 'credit_brand_url', 'option' ) ), array( 'http', 'https' ) );

	if ( '' === $url ) {
		return '';
	}

	if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && 'local' !== wp_get_environment_type() ) {
		return '';
	}

	return $url;
}

function devbase_credit_get_state() {
	$state = get_option( DEVBASE_CREDIT_OPTION );

	return is_array( $state ) ? $state : array();
}

/**
 * A plain-text value of at most $max characters, or ''. Over-long is refused,
 * not truncated: a truncated brand name is a wrong brand name.
 */
function devbase_credit_text( $value, $max ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$value = trim( sanitize_text_field( $value ) );

	return strlen( $value ) > $max ? '' : $value;
}

/**
 * An http(s) address of reasonable length, or ''.
 */
function devbase_credit_url( $value ) {
	if ( ! is_string( $value ) || strlen( $value ) > 500 ) {
		return '';
	}

	$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );

	return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? $url : '';
}

/**
 * Builds the stored copy from a Brand Relay response. Only the fields named
 * here survive. Returns null when the response is not a usable credit.
 *
 * @return array|null
 */
function devbase_credit_clean( $data ) {
	if ( ! is_array( $data ) ) {
		return null;
	}

	$brand = isset( $data['brand'] ) && is_array( $data['brand'] ) ? $data['brand'] : array();
	$link  = isset( $data['link'] ) && is_array( $data['link'] ) ? $data['link'] : array();

	$brand_name = devbase_credit_text( $brand['name'] ?? '', 80 );
	$brand_url  = devbase_credit_url( $brand['url'] ?? '' );
	$link_text  = devbase_credit_text( $link['text'] ?? '', 80 );
	$link_url   = devbase_credit_url( $link['url'] ?? '' );

	// A credit with nothing to say or nowhere to go is not a credit.
	if ( '' === $link_text || '' === $link_url ) {
		return null;
	}

	$rel = array();

	foreach ( preg_split( '/\s+/', strtolower( is_string( $link['rel'] ?? '' ) ? $link['rel'] : '' ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
		if ( in_array( $token, DEVBASE_CREDIT_REL_TOKENS, true ) ) {
			$rel[ $token ] = $token;
		}
	}

	$follow = ! isset( $link['follow'] ) || (bool) $link['follow'];

	if ( ! $follow ) {
		$rel['nofollow'] = 'nofollow';
	}

	$logo = null;

	if ( isset( $data['assets']['logo'] ) && is_array( $data['assets']['logo'] ) ) {
		$logo_url = devbase_credit_url( $data['assets']['logo']['url'] ?? '' );

		if ( '' !== $logo_url ) {
			$logo = array(
				'url' => $logo_url,
				'alt' => devbase_credit_text( $data['assets']['logo']['alt'] ?? '', 120 ),
			);
		}
	}

	return array(
		'show'    => ! isset( $data['show'] ) || (bool) $data['show'],
		'message' => devbase_credit_text( $data['message'] ?? '', 80 ),
		'link'    => array(
			'text'   => $link_text,
			'url'    => $link_url,
			'rel'    => implode( ' ', $rel ),
			'follow' => $follow,
		),
		'brand'   => array(
			'name' => '' !== $brand_name ? $brand_name : $link_text,
			'url'  => '' !== $brand_url ? $brand_url : $link_url,
		),
		'logo'    => $logo,
	);
}

/**
 * Downloads the brand's public details and keeps them.
 *
 * @param bool $force Ignore the minimum gap (saving the setting does).
 * @return array { ok: bool, error: string }
 */
function devbase_credit_refresh( $force = false ) {
	$result = array(
		'ok'    => false,
		'error' => '',
	);

	$url = devbase_credit_brand_url();

	if ( '' === $url ) {
		$result['error'] = 'not configured';
		return $result;
	}

	$state = devbase_credit_get_state();

	// A different address is a different brand: never keep the old one's copy.
	if ( isset( $state['source'] ) && $state['source'] !== $url ) {
		$state = array();
	}

	if ( ! $force && ! empty( $state['data'] ) && ! empty( $state['fetched_at'] ) ) {
		$gap = min( DAY_IN_SECONDS, max( DEVBASE_CREDIT_MIN_INTERVAL, (int) ( $state['ttl'] ?? 0 ) ) );

		if ( ( time() - (int) $state['fetched_at'] ) < $gap ) {
			$result['ok'] = true;
			return $result;
		}
	}

	$state['source']       = $url;
	$state['attempted_at'] = time();

	// The site's language, so a multilingual brand answers in it.
	$response = wp_remote_get(
		add_query_arg( 'lang', str_replace( '_', '-', get_locale() ), $url ),
		array(
			'timeout'             => 10,
			'redirection'         => 0,
			'limit_response_size' => 262144,
			'headers'             => array( 'Accept' => 'application/json' ),
		)
	);

	$error = '';
	$clean = null;

	if ( is_wp_error( $response ) ) {
		$error = $response->get_error_message();
	} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		$error = 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
	} else {
		$json  = json_decode( wp_remote_retrieve_body( $response ), true );
		$clean = devbase_credit_clean( $json );

		if ( null === $clean ) {
			$error = 'unexpected response';
		} elseif ( is_array( $json ) && isset( $json['ttl'] ) && is_numeric( $json['ttl'] ) ) {
			$state['ttl'] = max( 0, (int) $json['ttl'] );
		}
	}

	if ( null === $clean ) {
		// Fail soft: the previous copy, if any, stays.
		$state['last_error'] = $error;
		update_option( DEVBASE_CREDIT_OPTION, $state, false );
		error_log( "devbase_parent_theme: credit refresh failed — {$error}" );

		$result['error'] = $error;
		return $result;
	}

	$state['data']       = $clean;
	$state['fetched_at'] = time();
	$state['last_error'] = '';
	update_option( DEVBASE_CREDIT_OPTION, $state, false );

	$result['ok'] = true;
	return $result;
}

/**
 * The credit to show, or null. Reads the local copy only; never downloads.
 *
 * @return array|null
 */
function devbase_credit() {
	$state = devbase_credit_get_state();

	if ( empty( $state['data'] ) || ! is_array( $state['data'] ) ) {
		return null;
	}

	// The copy belongs to the address it came from; if the setting now points
	// elsewhere (or was cleared) it is not this site's credit any more.
	if ( ! isset( $state['source'] ) || devbase_credit_brand_url() !== $state['source'] ) {
		return null;
	}

	if ( empty( $state['data']['show'] ) ) {
		return null;
	}

	$credit               = $state['data'];
	$credit['fetched_at'] = (int) ( $state['fetched_at'] ?? 0 );
	unset( $credit['show'] );

	return apply_filters( 'devbase/credit', $credit );
}

/**
 * "message <a>text</a>", every part escaped, or '' when there is no credit.
 */
function devbase_credit_html() {
	$credit = devbase_credit();

	if ( null === $credit ) {
		return '';
	}

	$rel = trim( $credit['link']['rel'] . ' noopener' );

	$html  = '' !== $credit['message'] ? esc_html( $credit['message'] ) . ' ' : '';
	$html .= sprintf(
		'<a href="%s" target="_blank" rel="%s">%s</a>',
		esc_url( $credit['link']['url'] ),
		esc_attr( $rel ),
		esc_html( $credit['link']['text'] )
	);

	return $html;
}

// Schedule: daily while an address is set, removed when it isn't. A brand new
// address with nothing stored yet gets one early download so the credit
// appears without waiting a day.
add_action( 'init', function () {
	$next = wp_next_scheduled( DEVBASE_CREDIT_HOOK );

	if ( '' === devbase_credit_brand_url() ) {
		if ( $next ) {
			wp_clear_scheduled_hook( DEVBASE_CREDIT_HOOK );
		}

		return;
	}

	if ( ! $next ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', DEVBASE_CREDIT_HOOK );
	}
} );

add_action( DEVBASE_CREDIT_HOOK, function () {
	devbase_credit_refresh( false );
} );

// Saving the Miscellaneous page downloads straight away, so entering the
// address is the whole setup.
add_action( 'acf/save_post', function () {
	if ( ! isset( $_GET['page'] ) || 'miscellaneous-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	if ( '' !== devbase_credit_brand_url() ) {
		devbase_credit_refresh( true );
	}
}, 20 );

/**
 * The read-only status shown in the Credit tab: what is stored, when, and the
 * last error. Presentation only; it never downloads.
 */
function devbase_credit_status_html() {
	if ( '' === devbase_credit_brand_url() ) {
		return '<p><em>' . esc_html__( 'No brand address entered, so no credit is downloaded.', 'devbase_parent_theme' ) . '</em></p>';
	}

	$state = devbase_credit_get_state();
	$error = ! empty( $state['last_error'] ) ? (string) $state['last_error'] : '';
	$html  = '';

	if ( ! empty( $state['data'] ) && ! empty( $state['fetched_at'] ) ) {
		/* translators: %s: how long ago the credit was last downloaded, e.g. "3 hours". */
		$html .= '<p><strong>' . sprintf( esc_html__( 'Downloaded %s ago.', 'devbase_parent_theme' ), esc_html( human_time_diff( (int) $state['fetched_at'] ) ) ) . '</strong></p>';

		$preview = devbase_credit_html();

		$html .= '' !== $preview
			? '<p>' . esc_html__( 'The credit currently reads:', 'devbase_parent_theme' ) . ' ' . $preview . '</p>'
			: '<p>' . esc_html__( 'The brand is set to show no credit.', 'devbase_parent_theme' ) . '</p>';
	} else {
		$html .= '<p><strong>' . esc_html__( 'Nothing downloaded yet.', 'devbase_parent_theme' ) . '</strong></p>';
	}

	if ( '' !== $error ) {
		/* translators: %s: the error the last download returned. */
		$html .= '<p>' . sprintf( esc_html__( 'The last download failed (%s). The previous credit, if any, is still used.', 'devbase_parent_theme' ), esc_html( $error ) ) . '</p>';
	}

	return $html;
}
