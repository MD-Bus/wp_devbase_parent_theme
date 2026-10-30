<?php

// devbase_form_fields( $form_name, $args ) — everything a form needs that
// the visitor never fills in, printed by one call inside the <form>:
//
//   - the AJAX action (when 'action' is given)
//   - the per-form nonce devbase_form_log_submission()/devbase_form_submit() verify
//   - the honeypot field devbase_form_submit() checks ('website')
//   - the active CAPTCHA's widget or token field (devbase_security_widget_html())
//
// and it loads assets/js/forms.js, the shared script that sends any
// <form data-devbase-form> over AJAX and shows the answer (see that file
// for the data attributes it reads).
//
// Before it, each form template printed these four by hand, and a form that
// forgot one was either open (no CAPTCHA) or broken (no nonce). Twig:
//
//   <form data-devbase-form action="{{ function('admin_url', 'admin-ajax.php') }}" method="post" novalidate>
//       {{ function('devbase_form_fields', 'contact_form', { action: 'my_contact' })|raw }}
//       ...
//   </form>
//
// devbase_form_nonce_field() stays for forms that print their own markup.

/**
 * @param string $form_name The registered form name.
 * @param array  $args {
 *     @type string $action   The wp_ajax_ action the form posts to. Default none.
 *     @type bool   $honeypot Print the honeypot field. Default true.
 *     @type bool   $captcha  Print the CAPTCHA widget. Default true.
 *     @type bool   $script   Load assets/js/forms.js. Default true; false for a
 *                            form with its own script.
 * }
 * @return string HTML, already escaped.
 */
function devbase_form_fields( $form_name, array $args = array() ) {
	$args = wp_parse_args( $args, array(
		'action'   => '',
		'honeypot' => true,
		'captcha'  => true,
		'script'   => true,
	) );

	$html = '';

	if ( '' !== $args['action'] ) {
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $args['action'] ) . '">';
	}

	// No id: two forms on one page (a footer newsletter and a contact form)
	// would otherwise print the same id twice, which wp_nonce_field() does.
	$html .= '<input type="hidden" name="_devbase_form_nonce" value="' . esc_attr( wp_create_nonce( 'devbase_form_' . $form_name ) ) . '">';

	if ( $args['honeypot'] ) {
		// Off-screen rather than display:none, which some bots skip. Hidden from
		// assistive technology and out of the tab order, so no person fills it.
		$html .= '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">'
			. '<label>' . esc_html__( 'Leave this empty', 'devbase_parent_theme' ) . '<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>'
			. '</div>';
	}

	if ( $args['captcha'] ) {
		$html .= devbase_security_widget_html();
	}

	if ( $args['script'] ) {
		devbase_form_enqueue_script();
	}

	return $html;
}

/** Loads assets/js/forms.js (in the footer, so a form rendered mid-page can still ask for it). */
function devbase_form_enqueue_script() {
	$file = get_template_directory() . '/assets/js/forms.js';

	wp_enqueue_script( 'devbase-forms', get_template_directory_uri() . '/assets/js/forms.js', array(), (string) filemtime( $file ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
}
