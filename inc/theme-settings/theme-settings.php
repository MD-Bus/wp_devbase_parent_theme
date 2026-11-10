<?php

// Registers an empty SCF options page under Settings > Theme Settings.
// No fields yet — this just creates the page itself. Add fields later via
// an SCF field group with its location set to this options page
// (menu_slug: theme-settings), same as any other ACF/SCF field group.
//
// Title is wrapped in __() so it can vary by site language (see
// inc/i18n.php for how translations are loaded) — e.g. a French
// translation file could map this to "Réglages du thème" without any
// code change here.
/**
 * Gives a read-only message field its text only when the field is shown (`acf/prepare_field`, which SCF runs while
 * rendering a form, never for get_field()). The field groups are registered on `acf/init`, which runs on EVERY request,
 * front end included: a status message built in the group array ran its option reads and queries for every visitor
 * (about 16 queries a page, measured 2026-11-10). Register the field with an empty `message` and pass its builder here.
 *
 * @param string   $key   The message field's key.
 * @param callable $build Returns the message HTML, escaped as the field expects.
 */
function devbase_message_when_shown( $key, callable $build ) {
	add_filter( "acf/prepare_field/key={$key}", function ( $field ) use ( $build ) {
		if ( is_array( $field ) ) {
			$field['message'] = (string) $build();
		}

		return $field;
	} );
}

add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		return;
	}

	$icon_path = get_template_directory() . '/assets/icons/theme-settings-icon.svg';
	$icon_url  = '';

	// The icon is fills only on purpose: WordPress recolours an admin menu icon by rewriting every fill="..." to the
	// colour scheme's (wp-admin/js/svg-painter.js), and does not touch strokes or style attributes.
	// WP admin menu icons take an inline data: URI (not a plain file URL) so
	// the browser doesn't need an extra request just for a tiny sidebar icon.
	if ( file_exists( $icon_path ) ) {
		$icon_url = 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon_path ) );
	}

	acf_add_options_page( array(
		'page_title' => __( 'Theme Settings', 'devbase_parent_theme' ),
		'menu_title' => __( 'Theme Settings', 'devbase_parent_theme' ),
		'menu_slug'  => 'theme-settings',
		'capability' => 'manage_options',
		'icon_url'   => $icon_url,
	) );
} );
