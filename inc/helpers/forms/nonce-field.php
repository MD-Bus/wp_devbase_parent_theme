<?php

// devbase_form_nonce_field( $form_name ) — renders the CSRF nonce field
// devbase_form_log_submission() requires. Pairs with
// devbase_security_widget_html(): both are "call this inside your form
// markup" helpers, one for spam, one for CSRF. Neither is optional on the
// verification side — log-submission.php checks this nonce automatically,
// so a form that doesn't render this field will have every submission
// blocked, the same as a form that doesn't render the CAPTCHA widget
// would fail verification once a provider is configured.
//
// The action is namespaced per form name (devbase_form_{$form_name}),
// not one shared action for every form on the site — so a stolen/replayed
// token for one form can't be reused against a different one.

/**
 * Echoes a nonce field for the given form name. Call this once, anywhere
 * inside the <form> markup, for every form that calls
 * devbase_form_log_submission().
 *
 * @param string $form_name Must match what you'll pass to
 *                           devbase_form_log_submission().
 */
function devbase_form_nonce_field( $form_name ) {
	wp_nonce_field( 'devbase_form_' . $form_name, '_devbase_form_nonce' );
}
