<?php

// devbase_form_log_submission() — the one function any form built in this
// theme (or a client site built on it) needs to call. It handles CSRF
// verification, spam gating, and logging; inc/forms/dispatch/orchestrator.php
// picks up from there automatically via the save_post_form_data hook, so a
// form author never needs to touch notification logic at all.
//
// CSRF is automatic, not optional, same as the CAPTCHA gate: this
// function verifies the nonce devbase_form_nonce_field( $form_name )
// renders (see nonce-field.php) before doing anything else. A form that
// doesn't render that field will have every submission blocked here.
//
// This lives in inc/helpers/ (not inc/forms/) deliberately: it's the
// public, dev-facing surface of the forms feature — everything else in
// inc/forms/ (the CPTs, the registry, dispatch) is internal machinery
// this function's insert triggers via a WordPress hook, not a direct
// call, so the two were never actually coupled at the file level. See
// inc/helpers/AGENT.md for the "public helpers live here" convention.
//
// $content shape — the contract every form author must follow, keyed by a
// STABLE field identifier (never the human-readable aria-label text, so
// relabeling a field in the UI later never silently breaks stored data or
// dispatch formatting):
//
//   [
//       'email'   => [ 'aria_label' => 'Email Address', 'value' => 'a@b.com' ],
//       'message' => [ 'aria_label' => 'Message',       'value' => 'Hello'   ],
//   ]

/**
 * Logs a form submission, gated by a per-form CSRF nonce and whichever
 * CAPTCHA provider (if any) is currently active. Both gates are automatic
 * and not optional — a form author calling this function gets them for
 * free, without needing to remember devbase_security_verify_request()
 * or a manual wp_verify_nonce() call themselves.
 *
 * @param string $form_name Must match a 'name' entry in registered-forms.json,
 *                           and the name passed to devbase_form_nonce_field().
 * @param array  $content   See the shape documented above.
 * @return int|false The new form_data post ID, or false if the submission
 *                    was blocked (missing/invalid nonce, spam gate failed,
 *                    or bad input).
 */
function devbase_form_log_submission( $form_name, array $content ) {
	$form_name = is_string( $form_name ) ? trim( $form_name ) : '';

	if ( '' === $form_name ) {
		return false;
	}

	$nonce = isset( $_POST['_devbase_form_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_devbase_form_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( ! wp_verify_nonce( $nonce, 'devbase_form_' . $form_name ) ) {
		return false;
	}

	if ( ! devbase_security_verify_request() ) {
		return false;
	}

	if ( empty( $content ) ) {
		return false;
	}

	// wp_slash() here matters: wp_insert_post()'s meta_input runs every
	// value through wp_unslash() before storing (WordPress assumes meta
	// input arrives already magic-quoted, same as $_POST would be, and
	// undoes that). Without pre-slashing, wp_json_encode()'s own \" escapes
	// around any literal quote in a submitted value get silently stripped,
	// corrupting the stored JSON — confirmed with a real submission
	// containing a quote character before this fix.
	$post_id = wp_insert_post( array(
		'post_type'   => 'form_data',
		'post_status' => 'publish',
		/* translators: 1: form name, 2: submission date/time */
		'post_title'  => sprintf( __( '%1$s — %2$s', 'devbase_parent_theme' ), $form_name, current_time( 'mysql' ) ),
		'meta_input'  => array(
			'form_name' => $form_name,
			'content'   => wp_slash( wp_json_encode( $content ) ),
		),
	), true );

	if ( is_wp_error( $post_id ) ) {
		return false;
	}

	return $post_id;
}
