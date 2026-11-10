<?php

// Actually outputs what's configured in Tracking settings.php. Kept in its
// own file, same reasoning as SMTP's settings.php/mailer.php split: define
// the fields in one file, act on them in another, so each file has one job.

// ONLY WHERE IT SHOULD RUN (devbase_tracking_enabled()): nothing is printed on a site whose
// environment is not production (WP_ENVIRONMENT_TYPE: local, development, staging), nor for a
// logged-in user who can edit posts (the site's own staff would otherwise fill the analytics with their
// visits), and a consent plugin can switch it off through the `devbase/tracking/enabled` filter.

/**
 * Whether this page prints tracking.
 *
 * @param string $kind What is about to be printed: 'analytics' (GTM, GA4) or 'scripts' (the Custom
 *                     Scripts tab, which may hold more than tracking: a filter can let it through).
 * @return bool
 */
function devbase_tracking_enabled( $kind = 'analytics' ) {
	$reason  = devbase_tracking_off_reason();
	$enabled = '' === $reason;

	/**
	 * Whether tracking is printed on this page. A consent plugin returns false until the visitor has
	 * agreed; a child theme can return true to track staff or a staging site.
	 *
	 * @param bool   $enabled The theme's answer.
	 * @param string $kind    'analytics' or 'scripts'.
	 * @param string $reason  Why the theme said no: 'environment', 'staff', or '' when it said yes.
	 */
	return (bool) apply_filters( 'devbase/tracking/enabled', $enabled, $kind, $reason );
}

/** Why the theme leaves tracking out on this page: 'environment', 'staff', or '' when it does not. */
function devbase_tracking_off_reason() {
	// Filterable for a host that names its environments differently (and for the test suite, since
	// wp_get_environment_type() is read once per request).
	if ( 'production' !== (string) apply_filters( 'devbase/tracking/environment', wp_get_environment_type() ) ) {
		return 'environment';
	}

	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return 'staff';
	}

	return '';
}

// Google Tag Manager <head> snippet + GA4 gtag.js, right before </head>.
function devbase_tracking_head() {
	$analytics = devbase_tracking_enabled( 'analytics' );
	$gtm_id = get_field( 'gtm_id', 'option' );
	$ga4_id = get_field( 'ga4_id', 'option' );

	if ( $analytics && ! empty( $gtm_id ) ) {
		printf(
			"<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','%s');</script>\n<!-- End Google Tag Manager -->\n",
			esc_js( $gtm_id )
		);
	}

	if ( $analytics && ! empty( $ga4_id ) ) {
		printf(
			"<!-- Google Analytics (GA4) -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=%1\$s\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','%1\$s');</script>\n<!-- End Google Analytics (GA4) -->\n",
			esc_js( $ga4_id )
		);
	}

	$header_scripts = get_field( 'header_scripts', 'option' );
	if ( ! empty( $header_scripts ) && devbase_tracking_enabled( 'scripts' ) ) {
		echo "<!-- Custom Header Scripts -->\n" . $header_scripts . "\n<!-- End Custom Header Scripts -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_head', 'devbase_tracking_head' );

// Google Tag Manager <noscript> snippet, meant to sit right after <body>.
// Requires whatever template renders <body> to actually call
// wp_body_open() — views/index.twig (this theme's only front-end
// template so far) does, right after its own <body> tag, so this fires
// for real there. Any future project-specific template (header.twig,
// etc.) needs the same call, or this stays inert, same as WordPress
// core's own contract for this hook.
function devbase_tracking_body_open() {
	$gtm_id = get_field( 'gtm_id', 'option' );

	if ( empty( $gtm_id ) || ! devbase_tracking_enabled( 'analytics' ) ) {
		return;
	}

	printf(
		'<!-- Google Tag Manager (noscript) --><noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%1$s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript><!-- End Google Tag Manager (noscript) -->',
		esc_attr( $gtm_id )
	);
}
add_action( 'wp_body_open', 'devbase_tracking_body_open' );

// Custom footer scripts, right before </body>.
function devbase_tracking_footer() {
	$footer_scripts = get_field( 'footer_scripts', 'option' );

	if ( empty( $footer_scripts ) || ! devbase_tracking_enabled( 'scripts' ) ) {
		return;
	}

	echo "<!-- Custom Footer Scripts -->\n" . $footer_scripts . "\n<!-- End Custom Footer Scripts -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_footer', 'devbase_tracking_footer' );
