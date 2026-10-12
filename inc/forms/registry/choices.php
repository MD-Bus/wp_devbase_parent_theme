<?php

// Shared "which registered forms exist" lookup — used by every ACF/SCF
// select field elsewhere in this theme whose choices should be real
// registered form names, not hand-maintained or free text. Both
// inc/theme-settings/destinations/ (Applies To) and
// inc/theme-settings/miscellaneous/ (Retention Policy's Applies To) use
// this, so it lives here once rather than each duplicating the same
// get_posts() query.

/**
 * @return array<string,string> form_name (stable key, what dispatch and
 *                               retention actually match against) => post
 *                               title (admin-facing label).
 */
function devbase_forms_get_registered_form_choices() {
	$forms = get_posts( array(
		'post_type'      => 'registered_form',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	) );

	$choices = array();
	foreach ( $forms as $form ) {
		$form_name = get_post_meta( $form->ID, 'form_name', true );
		if ( $form_name ) {
			$choices[ $form_name ] = $form->post_title;
		}
	}

	return $choices;
}
