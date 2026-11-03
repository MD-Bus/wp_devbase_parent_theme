<?php

// Payment kill switch. OFF unless a child theme turns it on (devbase/suspension/enabled).
//
// When on, the site is suspended when EITHER
//   * its Warden licence status says so (licence.php: the normal, automatic way), OR
//   * the child's config says 'suspended' => true (a manual override).
// While suspended, every public request is answered with a 503 "unavailable" page
// instead of the site. Logged-in users keep wp-admin and wp-login.php, so the
// developer can still log in. See inc/suspension/AGENT.md.

/** Whether the active child theme has turned the kill switch on. */
function devbase_suspension_enabled() {
	return true === apply_filters( 'devbase/suspension/enabled', false );
}

/**
 * The settings: these defaults, over which a child's devbase/suspension/config
 * filter is merged. Cached for the request; pass true to drop the cache.
 *
 * @param bool $reset Forget the cached copy first.
 * @return array<string, mixed>
 */
function devbase_suspension_config( $reset = false ) {
	static $config = null;

	if ( $reset ) {
		$config = null;
	}

	if ( null === $config ) {
		$given  = apply_filters( 'devbase/suspension/config', array() );
		$config = wp_parse_args( is_array( $given ) ? $given : array(), array(
			// Manual override, independent of the licence.
			'suspended'     => false,
			// Follow the Warden licence status.
			'licence_lock'  => true,
			// Only "suspended" locks. expired, pending, completed, active, an unknown
			// domain or key never do.
			'lock_statuses' => array( 'suspended' ),
			// The stored answer is the cache: a request that finds it older than this
			// refreshes it after the page is sent. A scheduled task does too.
			'check_hours'   => 6,
			// After a failed check, wait this long before trying again.
			'retry_minutes' => 15,
			// The blocked page. Plain text.
			'title'         => 'Website temporarily unavailable',
			'message'       => 'If you are the owner of this website, please contact the developer.',
			'status'        => 'Service unavailable',
			// The button: an email address or an https:// address. Empty hides it.
			'contact'       => '',
			'contact_label' => 'Contact the developer',
			// Optional font files (URLs) for the blocked page; system fonts otherwise.
			'font_sans'     => '',
			'font_mono'     => '',
		) );
	}

	return $config;
}

function devbase_is_suspended() {
	return devbase_suspension_enabled() && ( true === devbase_suspension_config()['suspended'] || devbase_licence_verdict()['locked'] );
}

/** Sends the blocked page and stops. */
function devbase_suspension_die() {
	$c       = devbase_suspension_config();
	$contact = trim( (string) $c['contact'] );
	$href    = '' === $contact ? '' : ( is_email( $contact ) ? 'mailto:' . $contact : $contact );

	if ( ! headers_sent() ) {
		status_header( 503 );
		nocache_headers();
		header( 'Retry-After: 86400' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $c['title'] . ' – ' . get_bloginfo( 'name' ) ); ?></title>
<style>
<?php if ( $c['font_sans'] ) : ?>@font-face{font-family:Inter;font-style:normal;font-weight:100 900;font-display:swap;src:url(<?php echo esc_url( $c['font_sans'] ); ?>) format('woff2')}
<?php endif; ?>
<?php if ( $c['font_mono'] ) : ?>@font-face{font-family:'Geist Mono';font-style:normal;font-weight:100 900;font-display:swap;src:url(<?php echo esc_url( $c['font_mono'] ); ?>) format('woff2')}
<?php endif; ?>
*{box-sizing:border-box}
html,body{margin:0}
body{min-height:100vh;display:flex;flex-direction:column;background:#f8f8f8;color:#1a1a1a;font:15px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}
.mono{font-family:'Geist Mono',ui-monospace,monospace;font-size:13px;letter-spacing:.01em}
header,footer{display:flex;justify-content:space-between;gap:16px;padding:20px 32px}
header{border-bottom:1px solid #e8e8e8}
footer{border-top:1px solid #e8e8e8;color:#6b6b6b}
main{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 24px}
.box{width:100%;max-width:560px}
.status{display:flex;align-items:center;gap:8px;color:#6b6b6b;margin-bottom:20px}
.dot{width:8px;height:8px;border-radius:50%;background:#e5484d}
h1{font:600 clamp(26px,5vw,34px)/1.2 Inter,ui-sans-serif,system-ui,sans-serif;letter-spacing:-.02em;margin:0 0 14px}
p{margin:0 0 28px;font-size:17px;max-width:46ch}
.btn{display:inline-block;padding:12px 20px;border-radius:4px;background:#1a1a1a;color:#f8f8f8;text-decoration:none;font:500 15px/1.2 Inter,ui-sans-serif,system-ui,sans-serif}
.btn:hover{background:#000}
.btn:focus-visible{outline:2px solid #1a1a1a;outline-offset:3px}
a.link{color:inherit}
@media (max-width:520px){header,footer{padding:16px 20px}}
</style>
</head>
<body>
<header class="mono"><span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span></header>
<main>
<div class="box">
<div class="status mono"><span class="dot" aria-hidden="true"></span><?php echo esc_html( $c['status'] ); ?></div>
<h1><?php echo esc_html( $c['title'] ); ?></h1>
<p><?php echo esc_html( $c['message'] ); ?></p>
<?php if ( $href ) : ?><a class="btn" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $c['contact_label'] ); ?></a><?php endif; ?>
</div>
</main>
<footer class="mono"><span>&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?></span><?php if ( $href ) : ?><a class="link" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( preg_replace( '#^(https?://|mailto:)#', '', rtrim( $href, '/' ) ) ); ?></a><?php endif; ?></footer>
</body>
</html>
<?php
	exit;
}

// Every front-end page, feed, sitemap, search and 404, for every visitor, logged in or not.
add_action( 'template_redirect', function () {
	if ( devbase_is_suspended() ) {
		devbase_suspension_die();
	}
}, -9999 );

// Public endpoints that bypass template_redirect: the contact form and other
// admin-ajax / admin-post handlers, and the REST API, for visitors who are not logged in.
add_action( 'admin_init', function () {
	if ( devbase_is_suspended() && ! is_user_logged_in() && ( wp_doing_ajax() || false !== strpos( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ), 'admin-post.php' ) ) ) {
		devbase_suspension_die();
	}
}, -9999 );

add_filter( 'rest_authentication_errors', function ( $result ) {
	if ( devbase_is_suspended() && ! is_user_logged_in() ) {
		return new WP_Error( 'devbase_suspended', devbase_suspension_config()['message'], array( 'status' => 503 ) );
	}

	return $result;
}, 1 );

// While suspended the theme/plugin code editors are off even if wp-config allows them,
// so the switch cannot be flipped from inside the dashboard.
add_filter( 'map_meta_cap', function ( $caps, $cap ) {
	if ( in_array( $cap, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) && devbase_is_suspended() ) {
		return array( 'do_not_allow' );
	}

	return $caps;
}, 10, 2 );

// Tell logged-in users why the public site is dark, and warn before it goes dark.
add_action( 'admin_notices', function () {
	if ( ! devbase_suspension_enabled() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$verdict = devbase_licence_verdict();

	if ( $verdict['locked'] ) {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'This site is suspended.', 'devbase_parent_theme' ) . '</strong> '
			. esc_html__( 'Visitors see an "unavailable" page. It is restored automatically at the next licence check once the licence is active again.', 'devbase_parent_theme' ) . '</p></div>';
	} elseif ( true === devbase_suspension_config()['suspended'] ) {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'This site is suspended.', 'devbase_parent_theme' ) . '</strong> '
			. esc_html__( 'Visitors see an "unavailable" page. Please contact the developer.', 'devbase_parent_theme' ) . '</p></div>';
	} elseif ( '' !== $verdict['grace_ends_at'] ) {
		$ts   = strtotime( $verdict['grace_ends_at'] );
		$text = $ts
			? sprintf(
				/* translators: %s: a date */
				__( 'The site stays online until %s. After that, visitors will see an "unavailable" page. Please contact the developer.', 'devbase_parent_theme' ),
				wp_date( get_option( 'date_format' ), $ts )
			)
			: __( 'After the grace period, visitors will see an "unavailable" page. Please contact the developer.', 'devbase_parent_theme' );

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Licence payment is late.', 'devbase_parent_theme' ) . '</strong> ' . esc_html( $text ) . '</p></div>';
	}
} );

add_action( 'admin_bar_menu', function ( $bar ) {
	if ( devbase_is_suspended() ) {
		$bar->add_node( array( 'id' => 'devbase-suspended', 'title' => '⛔ ' . esc_html__( 'Site suspended', 'devbase_parent_theme' ), 'href' => false ) );
	}
}, 5 );
