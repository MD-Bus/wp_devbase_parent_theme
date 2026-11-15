<?php

// AJAX handler for the "Send Test Email" button on Theme Settings >
// SMTP Settings (see inc/theme-settings/smtp-settings.php for the button
// itself, inc/theme-settings/smtp-test.js for the click handling).
//
// Sends a real test email to the current admin's own address, using
// whatever SMTP config is currently SAVED (not unsaved in-progress form
// values — save first, then test). Captures the real PHPMailer failure
// reason via wp_mail_failed rather than just reporting a generic
// success/failure, since "it failed" alone isn't enough to diagnose a bad
// host/port/credentials.
add_action( 'wp_ajax_devbase_smtp_test', function () {
	check_ajax_referer( 'devbase_smtp_test' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'devbase_parent_theme' ) ) );
	}

	// Which tab's button was pressed. The test always sends through what is SAVED; when that is not this tab's way of
	// sending, the mail would go another way and the result would say nothing about this tab.
	$route  = isset( $_POST['route'] ) && 'api' === $_POST['route'] ? 'api' : 'smtp'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$active = devbase_mail_active_route();

	if ( 'api' === $route && 'api' !== $active ) {
		wp_send_json_error( array( 'message' => __( 'The Email API is not on with a complete setup. Save changes first: the test uses the saved settings.', 'devbase_parent_theme' ) ) );
	}

	if ( 'smtp' === $route && 'smtp' !== $active ) {
		wp_send_json_error( array( 'message' => __( 'SMTP is not on. Save changes first: the test uses the saved settings.', 'devbase_parent_theme' ) ) );
	}

	$error_message = '';
	$capture_error = function ( $wp_error ) use ( &$error_message ) {
		$error_message = $wp_error->get_error_message();
	};
	add_action( 'wp_mail_failed', $capture_error );

	$to = wp_get_current_user()->user_email;

	if ( 'api' === $route ) {
		$label = devbase_mail_api_providers()[ devbase_mail_api_active_provider() ]['label'] ?? '';
		/* translators: %s: the email service, e.g. Brevo. */
		$subject = sprintf( __( 'Email API test email (%s)', 'devbase_parent_theme' ), $label );
		/* translators: %s: the email service, e.g. Brevo. */
		$body = sprintf( __( 'This is a test email from Theme Settings > Email > Email API. If you received this, the site is sending its email through %s.', 'devbase_parent_theme' ), $label );
	} else {
		$subject = __( 'SMTP test email', 'devbase_parent_theme' );
		$body    = __( 'This is a test email from Theme Settings > SMTP Settings. If you received this, your SMTP configuration is working.', 'devbase_parent_theme' );
	}

	$sent = wp_mail( $to, $subject, $body );

	remove_action( 'wp_mail_failed', $capture_error );

	// Recorded regardless of outcome — this is what
	// devbase_smtp_last_test_message() (below) surfaces on the
	// Miscellaneous > System Status tab, so "is SMTP actually working"
	// is answerable without visiting SMTP Settings and clicking the
	// button again.
	update_option( 'devbase_smtp_last_test', array(
		'time'    => time(),
		'route'   => $route,
		'success' => (bool) $sent,
		'message' => $sent ? '' : ( $error_message ? $error_message : __( 'Failed to send test email.', 'devbase_parent_theme' ) ),
	), false );

	if ( $sent ) {
		wp_send_json_success( array(
			/* translators: %s: email address the test message was sent to. */
			'message' => sprintf( __( 'Test email sent to %s. Check the inbox (and spam folder).', 'devbase_parent_theme' ), $to ),
		) );
	}

	wp_send_json_error( array(
		'message' => $error_message ? $error_message : __( 'Failed to send test email.', 'devbase_parent_theme' ),
	) );
} );

/**
 * The way mail goes out right now: 'smtp' (SMTP on with a host, mailer.php), 'api' (api.php has a complete service), or
 * 'mail' (WordPress's own mail()). SMTP wins if both are somehow on, as at send time.
 */
function devbase_mail_active_route() {
	if ( function_exists( 'get_field' ) && get_field( 'smtp_enabled', 'option' ) && '' !== (string) get_field( 'smtp_host', 'option' ) ) {
		return 'smtp';
	}

	return '' !== devbase_mail_api_active_provider() ? 'api' : 'mail';
}

/**
 * Human-readable SMTP status line for the Miscellaneous > System Status
 * tab: whether SMTP is enabled, and — if a test has ever been run — when
 * and whether it succeeded. Never runs a test itself; purely reads what
 * the "Send Test Email" button (above) last recorded.
 *
 * @return string
 */
function devbase_smtp_last_test_message() {
	$last_test = get_option( 'devbase_smtp_last_test' );
	$last_test = is_array( $last_test ) ? $last_test : array();
	// A test from before the Email API tab has no route: it was an SMTP test.
	$tested    = (string) ( $last_test['route'] ?? 'smtp' );

	if ( 'api' === devbase_mail_active_route() ) {
		$label = (string) ( devbase_mail_api_providers()[ devbase_mail_api_active_provider() ]['label'] ?? '' );

		if ( empty( $last_test['time'] ) || 'api' !== $tested ) {
			/* translators: %s: the email service, e.g. Brevo. */
			return sprintf( __( 'Email is sent through the %s API — not tested yet. Use "Send Test Email" on Email > Email API.', 'devbase_parent_theme' ), $label );
		}

		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_test['time'] );

		if ( ! empty( $last_test['success'] ) ) {
			/* translators: 1: the email service, 2: date/time of the last successful test */
			return sprintf( __( 'Email API (%1$s) last tested successfully: %2$s.', 'devbase_parent_theme' ), $label, $when );
		}

		/* translators: 1: the email service, 2: date/time of the last failed test, 3: the captured error message */
		return sprintf( __( 'Email API (%1$s) last test FAILED (%2$s): %3$s', 'devbase_parent_theme' ), $label, $when, $last_test['message'] );
	}

	if ( ! get_field( 'smtp_enabled', 'option' ) ) {
		return __( 'SMTP is disabled — using PHP\'s default mail().', 'devbase_parent_theme' );
	}

	if ( empty( $last_test['time'] ) || 'smtp' !== $tested ) {
		return __( 'SMTP is enabled — not tested yet. Use "Send Test Email" on SMTP Settings.', 'devbase_parent_theme' );
	}

	$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_test['time'] );

	if ( ! empty( $last_test['success'] ) ) {
		/* translators: %s: date/time of the last successful test */
		return sprintf( __( 'SMTP last tested successfully: %s.', 'devbase_parent_theme' ), $when );
	}

	/* translators: 1: date/time of the last failed test, 2: the captured error message */
	return sprintf( __( 'SMTP last test FAILED (%1$s): %2$s', 'devbase_parent_theme' ), $when, $last_test['message'] );
}
