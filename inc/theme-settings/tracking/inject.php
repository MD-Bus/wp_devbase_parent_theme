<?php

// Actually outputs what's configured in Tracking settings.php. Kept in its
// own file, same reasoning as SMTP's settings.php/mailer.php split: define
// the fields in one file, act on them in another, so each file has one job.

// Google Tag Manager <head> snippet + GA4 gtag.js, right before </head>.
add_action( 'wp_head', function () {
	$gtm_id = get_field( 'gtm_id', 'option' );
	$ga4_id = get_field( 'ga4_id', 'option' );

	if ( ! empty( $gtm_id ) ) {
		printf(
			"<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','%s');</script>\n<!-- End Google Tag Manager -->\n",
			esc_js( $gtm_id )
		);
	}

	if ( ! empty( $ga4_id ) ) {
		printf(
			"<!-- Google Analytics (GA4) -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=%1\$s\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','%1\$s');</script>\n<!-- End Google Analytics (GA4) -->\n",
			esc_js( $ga4_id )
		);
	}

	$header_scripts = get_field( 'header_scripts', 'option' );
	if ( ! empty( $header_scripts ) ) {
		echo "<!-- Custom Header Scripts -->\n" . $header_scripts . "\n<!-- End Custom Header Scripts -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
} );

// Google Tag Manager <noscript> snippet, meant to sit right after <body>.
// Requires whatever template renders <body> to actually call
// wp_body_open() — views/index.twig (this theme's only front-end
// template so far) does, right after its own <body> tag, so this fires
// for real there. Any future project-specific template (header.twig,
// etc.) needs the same call, or this stays inert, same as WordPress
// core's own contract for this hook.
add_action( 'wp_body_open', function () {
	$gtm_id = get_field( 'gtm_id', 'option' );

	if ( empty( $gtm_id ) ) {
		return;
	}

	printf(
		'<!-- Google Tag Manager (noscript) --><noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%1$s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript><!-- End Google Tag Manager (noscript) -->',
		esc_attr( $gtm_id )
	);
} );

// Custom footer scripts, right before </body>.
add_action( 'wp_footer', function () {
	$footer_scripts = get_field( 'footer_scripts', 'option' );

	if ( empty( $footer_scripts ) ) {
		return;
	}

	echo "<!-- Custom Footer Scripts -->\n" . $footer_scripts . "\n<!-- End Custom Footer Scripts -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );
