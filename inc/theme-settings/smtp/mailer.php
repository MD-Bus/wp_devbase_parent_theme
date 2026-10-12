<?php

// Routes all outgoing WordPress email through a custom SMTP server, once
// enabled and configured on Theme Settings > Notifications > SMTP Server.
// Does nothing at all unless smtp_enabled is on AND a host is actually
// set — an incomplete or disabled config leaves WordPress's default mail
// behavior untouched rather than breaking outgoing mail site-wide.
add_action( 'phpmailer_init', function ( $phpmailer ) {
	if ( ! function_exists( 'get_field' ) ) {
		return;
	}

	if ( ! get_field( 'smtp_enabled', 'option' ) ) {
		return;
	}

	$host = get_field( 'smtp_host', 'option' );

	if ( empty( $host ) ) {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host     = $host;
	$phpmailer->Port     = (int) get_field( 'smtp_port', 'option' ) ?: 587;
	$phpmailer->SMTPAuth = true;
	$phpmailer->Username = (string) get_field( 'smtp_username', 'option' );
	$phpmailer->Password = (string) get_field( 'smtp_password', 'option' );

	$encryption = get_field( 'smtp_encryption', 'option' );

	if ( $encryption ) {
		$phpmailer->SMTPSecure = $encryption;
	}

	$from_email = get_field( 'smtp_from_email', 'option' );
	$from_name  = get_field( 'smtp_from_name', 'option' );

	if ( $from_email ) {
		$phpmailer->setFrom( $from_email, $from_name ? $from_name : get_bloginfo( 'name' ) );
	}
} );
