<?php

// Sends a submission to a single SMTP destination row — formats the
// generic $content map as an HTML key/value table (no per-form templates;
// that's the whole point of a shared dispatch layer) and sends via
// wp_mail(). wp_mail() automatically routes through this theme's existing
// SMTP transport (inc/theme-settings/smtp/mailer.php's phpmailer_init
// hook) whenever smtp_enabled is on — no new mail-transport code here.

/**
 * @param array  $row       A single row from Form Destinations' `destinations`
 *                           repeater (type === 'smtp'): label, to_email.
 * @param string $form_name
 * @param array  $content   The submission's field => [aria_label, value] map.
 * @return array{success: bool, label: string, message: string}
 */
function devbase_forms_dispatch_smtp( array $row, $form_name, array $content ) {
	if ( empty( $row['to_email'] ) ) {
		$message = sprintf( 'SMTP destination "%s" has no To address.', $row['label'] ?? '' );
		error_log( '[Dev Base Forms] ' . $message );
		return array( 'success' => false, 'label' => __( 'SMTP', 'devbase_parent_theme' ), 'message' => $message );
	}

	$label = ! empty( $row['label'] ) ? $row['label'] : $row['to_email'];

	$rows = '';
	foreach ( $content as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		$field_label = isset( $field['aria_label'] ) ? $field['aria_label'] : '';
		$value       = isset( $field['value'] ) ? $field['value'] : '';

		$rows .= sprintf(
			'<tr><td style="padding:6px 12px;font-weight:bold;vertical-align:top;">%s</td><td style="padding:6px 12px;">%s</td></tr>',
			esc_html( $field_label ),
			nl2br( esc_html( $value ) )
		);
	}

	$html = sprintf(
		'<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;">%s</table>',
		$rows
	);

	$subject = sprintf(
		/* translators: %s: form name */
		__( 'New submission: %s', 'devbase_parent_theme' ),
		$form_name
	);

	$sent = wp_mail( $row['to_email'], $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );

	$message = sprintf( 'SMTP "%s" (%s): %s', $label, $row['to_email'], $sent ? 'sent' : 'failed' );
	error_log( '[Dev Base Forms] ' . $message );

	return array( 'success' => $sent, 'label' => $label, 'message' => $message );
}
