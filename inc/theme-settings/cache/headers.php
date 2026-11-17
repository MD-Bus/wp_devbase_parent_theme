<?php

// What each public page tells caches, decided once per page at the very end of template_redirect (after WordPress's
// redirects and after WooCommerce has marked its cart, checkout and account pages).
//
//   exclude  A path on the General tab's list: "do not cache" for every cache (nocache_headers(), the DONOTCACHEPAGE
//            constant cache plugins read, and LiteSpeed's own header).
//   private  Only while Cloudflare page caching is on: a logged-in visitor, a preview, a password-protected page, or a
//            visitor with a comment, password or cart cookie. "Do not cache", so no one else is ever served their page.
//   cache    Only while Cloudflare page caching is on: `Cache-Control: public, max-age=0, s-maxage=N`. Cloudflare keeps
//            the page N seconds (its rule, cloudflare.php, only caches pages that say so); browsers check back every time,
//            so a cleared page shows at once.
//   none     Anything else: nothing is sent and the site behaves as it did. So are pages that already carry a
//            Cache-Control header or DONOTCACHEPAGE (someone else decided), searches, 404s and anything not GET/HEAD.

/**
 * True when a path is on the "Never cache these paths" list.
 *
 * @param string $path The request path, e.g. /members/area.
 */
function devbase_cache_path_excluded( $path ) {
	$rows = devbase_cache_setting( 'cache_exclusions' );

	if ( ! is_array( $rows ) || ! $rows ) {
		return false;
	}

	$path = '/' . ltrim( strtolower( (string) $path ), '/' );

	foreach ( $rows as $row ) {
		$rule = is_array( $row ) ? trim( strtolower( (string) ( $row['path'] ?? '' ) ) ) : '';

		if ( '' === $rule ) {
			continue;
		}

		// A full address pasted in counts by its path.
		if ( preg_match( '#^https?://#', $rule ) ) {
			$rule = (string) wp_parse_url( $rule, PHP_URL_PATH );
		}

		$rule = '/' . ltrim( $rule, '/' );

		if ( 'exact' === ( $row['match'] ?? 'prefix' ) ) {
			if ( untrailingslashit( $path ) === untrailingslashit( $rule ) ) {
				return true;
			}
		} elseif ( 0 === strpos( $path, $rule ) || untrailingslashit( $path ) === untrailingslashit( $rule ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The decision for the current request: exclude, private, cache or none.
 *
 * @param string[]|null $sent The headers already set (headers_list(); tests pass their own).
 */
function devbase_cache_page_decision( $sent = null ) {
	if ( ! devbase_cache_enabled() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return 'none';
	}

	$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return 'none';
	}

	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( devbase_cache_path_excluded( $path ) ) {
		return 'exclude';
	}

	if ( ! devbase_cache_cf_page_cache() ) {
		return 'none';
	}

	if ( is_user_logged_in() || is_preview() || is_customize_preview() || ( is_singular() && post_password_required() ) ) {
		return 'private';
	}

	foreach ( array_keys( $_COOKIE ) as $cookie ) {
		foreach ( array( 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_' ) as $prefix ) {
			if ( 0 === strpos( (string) $cookie, $prefix ) ) {
				return 'private';
			}
		}
	}

	// Defined at all means a plugin (WooCommerce, for one) decided this page is not cached.
	if ( defined( 'DONOTCACHEPAGE' ) ) {
		return 'none';
	}

	foreach ( null === $sent ? headers_list() : $sent as $header ) {
		if ( 0 === stripos( (string) $header, 'cache-control:' ) ) {
			return 'none';
		}
	}

	if ( is_search() || is_404() ) {
		return 'none';
	}

	return 'cache';
}

add_action( 'template_redirect', function () {
	if ( headers_sent() ) {
		return;
	}

	switch ( devbase_cache_page_decision() ) {
		case 'exclude':
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			break;

		case 'private':
			nocache_headers();
			break;

		case 'cache':
			header( 'Cache-Control: public, max-age=0, s-maxage=' . devbase_cache_cf_lifetime() );
			break;
	}
}, PHP_INT_MAX );
