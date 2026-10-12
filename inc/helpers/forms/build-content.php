<?php

// devbase_form_build_content() — turns $_POST (or any array of raw
// submitted values) into the $content shape devbase_form_log_submission()
// expects, so a form author never hand-writes that array structure or
// re-picks the right sanitizer per field.
//
// Can't be fully automatic: aria_label is an HTML attribute PHP never
// sees in $_POST, so the caller still supplies a field_name => label map
// — what this function automates is the array shaping and the
// sanitization, not label discovery.

/**
 * @param array $post   Usually $_POST. Read raw and unslashed here — do
 *                       not pre-sanitize before calling this.
 * @param array $fields Map of field_name => label, OR
 *                       field_name => [ 'label' => string, 'sanitize' => callable ].
 *                       Omitting 'sanitize' defaults to sanitize_text_field().
 *                       Example:
 *                       [
 *                           'name'    => 'Name',
 *                           'email'   => [ 'label' => 'Email Address', 'sanitize' => 'sanitize_email' ],
 *                           'message' => [ 'label' => 'Message', 'sanitize' => 'sanitize_textarea_field' ],
 *                       ]
 * @return array Shaped for devbase_form_log_submission()'s $content param.
 */
function devbase_form_build_content( array $post, array $fields ) {
	$content = array();

	foreach ( $fields as $field_name => $spec ) {
		if ( is_array( $spec ) ) {
			$label    = isset( $spec['label'] ) ? $spec['label'] : $field_name;
			$sanitize = isset( $spec['sanitize'] ) && is_callable( $spec['sanitize'] ) ? $spec['sanitize'] : 'sanitize_text_field';
		} else {
			$label    = $spec;
			$sanitize = 'sanitize_text_field';
		}

		$raw = isset( $post[ $field_name ] ) ? wp_unslash( $post[ $field_name ] ) : '';

		$content[ $field_name ] = array(
			'aria_label' => $label,
			'value'      => call_user_func( $sanitize, $raw ),
		);
	}

	return $content;
}
