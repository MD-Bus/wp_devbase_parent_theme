<?php

// Who may open and save Theme Settings, and what a save from that screen may change.
//
// WHY THIS FILE EXISTS. SCF gives every options page the capability `edit_posts` unless told
// otherwise, and it applies that default to each sub-page on its own. Until 1.33.0 none of these
// pages set one, so a Contributor could open Theme Settings > Licence, read the secrets in the page
// source and save new values: an update server and signing key of their own, a webhook that copies
// every submission, an SMTP server that receives password resets. Each page now names its
// capability, and this file makes sure of it for any page under Theme Settings, including one a
// child theme adds.
//
// Two more rules, because "disabled" and "hidden" are only HTML. SCF saves every acf[field_key] a
// request posts, whether or not the field was on the screen:
//
//   1. The provisioned licence values (update server, verification key, licence key) are never
//      taken from a form. Only devbase_relay_sync() writes them (it calls update_field(), which
//      this does not touch), and so does code calling update_field() directly (WP-CLI, tests).
//   2. While the Remote setup tab is hidden, its two fields are not taken from a form either. A
//      value baked in as a constant is never written from a form.
//
// And secrets are not sent back to the browser: a password field is drawn empty, and an empty
// submission keeps the stored value (so saving a page never wipes a password). To remove a secret,
// switch off what uses it.

/** The capability that opens Theme Settings and every page under it. */
function devbase_settings_capability() {
	return (string) apply_filters( 'devbase/settings/capability', 'manage_options' );
}

/** The capability that opens the Archive pages (content fields an Editor may need). */
function devbase_archive_settings_capability() {
	return (string) apply_filters( 'devbase/archive/capability', 'edit_pages' );
}

// Every page this theme owns gets its capability, whatever the call that added it said.
add_filter( 'acf/validate_options_page', function ( $page ) {
	if ( ! is_array( $page ) ) {
		return $page;
	}

	$slug   = (string) ( $page['menu_slug'] ?? '' );
	$parent = (string) ( $page['parent_slug'] ?? '' );

	if ( 'theme-settings' === $slug || 'theme-settings' === $parent ) {
		$page['capability'] = devbase_settings_capability();
	} elseif ( 'archive-settings' === $slug || 'archive-settings' === $parent ) {
		$page['capability'] = devbase_archive_settings_capability();
	}

	return $page;
} );

/** Field keys that are provisioned and never accepted from a form. */
function devbase_settings_provisioned_keys() {
	return array( 'field_devbase_warden_licence_key', 'field_devbase_warden_base_url', 'field_devbase_warden_public_key' );
}

/** Top-level password fields: drawn empty, kept when submitted empty. */
function devbase_settings_secret_keys() {
	return array(
		'field_devbase_warden_licence_key',
		'field_devbase_relay_site_key',
		'field_devbase_smtp_password',
		'field_devbase_turnstile_secret_key',
		'field_devbase_recaptcha_secret_key',
	);
}

/**
 * The form keys a save from the options screen must not carry right now.
 *
 * @return string[]
 */
function devbase_settings_locked_keys() {
	$locked = devbase_settings_provisioned_keys();

	if ( devbase_remote_setup_hidden() || defined( 'DEVBASE_RELAY_URL' ) ) {
		$locked[] = 'field_devbase_relay_base_url';
	}

	if ( devbase_remote_setup_hidden() || defined( 'DEVBASE_RELAY_SITE_KEY' ) ) {
		$locked[] = 'field_devbase_relay_site_key';
	}

	return $locked;
}

/**
 * Removes what a form may not change from a submitted acf[] array. Separate from the hook so it
 * can be tested on its own.
 *
 * @param array $values The submitted acf[] values (field key => value).
 * @return array
 */
function devbase_settings_filter_submission( array $values ) {
	foreach ( devbase_settings_locked_keys() as $key ) {
		unset( $values[ $key ] );
	}

	foreach ( devbase_settings_secret_keys() as $key ) {
		if ( array_key_exists( $key, $values ) && '' === trim( (string) ( is_scalar( $values[ $key ] ) ? $values[ $key ] : '' ) ) ) {
			unset( $values[ $key ] );
		}
	}

	return $values;
}

// Runs before SCF's own save (acf/save_post priority 10 reads $_POST['acf']), only for an
// options page save.
add_action( 'acf/save_post', function ( $post_id ) {
	if ( ! in_array( $post_id, array( 'option', 'options' ), true ) || empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}

	$_POST['acf'] = devbase_settings_filter_submission( $_POST['acf'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
}, 1 );

// Secrets are drawn empty with a note that one is stored. Runs late, after the licence page's own
// prepare_field filter.
add_filter( 'acf/prepare_field', function ( $field ) {
	if ( ! is_array( $field ) || ! isset( $field['key'] ) || ! in_array( $field['key'], devbase_settings_secret_keys(), true ) ) {
		return $field;
	}

	$stored = is_string( $field['value'] ?? null ) ? $field['value'] : '';

	$field['value'] = '';

	if ( '' !== $stored ) {
		$field['placeholder'] = __( 'Saved (hidden). Leave empty to keep it.', 'devbase_parent_theme' );
		// Empty means "keep the stored one", so it must not be asked for again (the browser's
		// own check and SCF's server check both look at `required`).
		$field['required'] = 0;
	}

	return $field;
}, 99 );

// The server-side half of the same rule: SCF validates from the field's definition, not from what
// was drawn, so a required secret submitted empty is accepted while one is already stored.
add_filter( 'acf/validate_value', function ( $valid, $value, $field ) {
	if ( true === $valid || ! is_array( $field ) || ! isset( $field['key'], $field['name'] ) || ! in_array( $field['key'], devbase_settings_secret_keys(), true ) ) {
		return $valid;
	}

	if ( '' === trim( (string) ( is_scalar( $value ) ? $value : '' ) ) && '' !== (string) get_option( 'options_' . $field['name'], '' ) ) {
		return true;
	}

	return $valid;
}, 20, 3 );
