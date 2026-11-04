<?php

// Registers the Licence sub-page (under Theme Settings): the Warden
// endpoint and signing key for this install, plus ONE licence key. Since
// Warden 0.5.0 a single key covers several apps, each under a tag; this
// parent theme checks it as the tag parent-theme and the active child theme as
// the tag child-theme (see inc/updates/warden.php).
//
// Same self-registering acf/init + acf_add_options_sub_page() +
// acf_add_local_field_group() pattern as every other sub-page under Theme
// Settings (inc/theme-settings/smtp/settings.php is the reference example).
//
// This file defines the fields and the accessors over them, nothing more.
// Everything that ACTS on these values lives in inc/updates/ — see
// inc/updates/AGENT.md.
//
// Why the endpoint and public key are fields rather than constants in
// code: a Warden deployment can be migrated (its Worker URL changes) and
// its signing key can be rotated, and neither should require shipping a
// new theme release to every client site — which is precisely the
// chicken-and-egg problem of hardcoding the address of your own update
// service into the thing it updates.

add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Licence', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Licence', 'devbase_parent_theme' ),
		'menu_slug'   => 'licence-settings',
		'parent_slug' => 'theme-settings',
	) );

	$fields = array(
		// STATUS: what a client administrator comes here for. First, so it opens first.
		array(
			'key'   => 'field_devbase_licence_tab_status',
			'label' => __( 'Status', 'devbase_parent_theme' ),
			'type'  => 'tab',
		),
		array(
			'key'      => 'field_devbase_licence_status',
			'label'    => __( 'Licence and updates', 'devbase_parent_theme' ),
			'type'     => 'message',
			'message'  => devbase_licence_status_html(),
			'esc_html' => 0,
		),
		// Manual trigger. Nothing on a client site needs this: every check it
		// runs is already on a schedule. It exists so a licence or a freshly
		// published release can be verified immediately instead of waiting up to
		// 12 hours. It calls exactly the functions the schedule does; see
		// check-ajax.php.
		array(
			'key'          => 'field_devbase_licence_check_now',
			'label'        => __( 'Check for updates', 'devbase_parent_theme' ),
			'type'         => 'message',
			'message'      => '<button type="button" id="devbase-check-updates-now" class="button">'
				. esc_html__( 'Check for updates now', 'devbase_parent_theme' )
				. '</button> <span id="devbase-check-updates-result" style="white-space:pre-line;display:inline-block;vertical-align:top;"></span>',
			'esc_html'     => 0,
			'instructions' => __( 'Checks for updates immediately. Save any changes first.', 'devbase_parent_theme' ),
		),

		// LICENCE: read-only. Set automatically, so one calm notice says so,
		// rather than the same line under every field.
		array(
			'key'   => 'field_devbase_licence_tab_keys',
			'label' => __( 'Licence', 'devbase_parent_theme' ),
			'type'  => 'tab',
		),
		array(
			'key'       => 'field_devbase_licence_warning',
			'label'     => '',
			'name'      => '',
			'type'      => 'message',
			'message'   => '<div class="notice notice-info inline" style="margin:0;"><p>'
				. esc_html__( 'Your licence is managed by your developer. These details are set automatically and cannot be edited here. Please contact your developer for any licence question.', 'devbase_parent_theme' )
				. '</p></div>',
			'new_lines' => '',
			'esc_html'  => 0,
		),
		array(
			'key'   => 'field_devbase_warden_licence_key',
			'label' => __( 'Licence key', 'devbase_parent_theme' ),
			'name'  => 'warden_licence_key',
			'type'  => 'password',
		),
		array(
			'key'   => 'field_devbase_warden_base_url',
			'label' => __( 'Update server', 'devbase_parent_theme' ),
			'name'  => 'warden_base_url',
			'type'  => 'url',
		),
		array(
			'key'   => 'field_devbase_warden_public_key',
			'label' => __( 'Verification key', 'devbase_parent_theme' ),
			'name'  => 'warden_public_key',
			'type'  => 'textarea',
			'rows'  => 2,
		),

		// REMOTE SETUP: the developer's tab. Hidden from clients where a child
		// asks (devbase_remote_setup_hidden()).
		array(
			'key'   => 'field_devbase_licence_tab_relay',
			'label' => __( 'Remote setup', 'devbase_parent_theme' ),
			'type'  => 'tab',
		),
		array(
			'key'          => 'field_devbase_relay_base_url',
			'label'        => __( 'Management server address', 'devbase_parent_theme' ),
			'name'         => 'relay_base_url',
			'type'         => 'url',
			'instructions' => __( 'The address of the management service, with no trailing path, e.g. https://relay.example.com. The Licence tab is filled in from there, and requests to this site are only accepted from this address.', 'devbase_parent_theme' ),
		),
		array(
			'key'          => 'field_devbase_relay_site_key',
			'label'        => __( 'Site access key', 'devbase_parent_theme' ),
			'name'         => 'relay_site_key',
			'type'         => 'password',
			'instructions' => __( 'This site\'s key from the management service (it starts with brs_). Once both fields are saved, the Licence tab fills itself in and stays up to date.', 'devbase_parent_theme' ),
		),
		array(
			'key'      => 'field_devbase_heartbeat_status',
			'label'    => __( 'Site health', 'devbase_parent_theme' ),
			'type'     => 'message',
			'message'  => devbase_heartbeat_status_html(),
			'esc_html' => 0,
		),
		array(
			'key'      => 'field_devbase_events_status',
			'label'    => __( 'Error tracking', 'devbase_parent_theme' ),
			'type'     => 'message',
			'message'  => devbase_events_status_html(),
			'esc_html' => 0,
		),
	);

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_licence',
		'title'    => __( 'Licence and updates', 'devbase_parent_theme' ),
		'fields'   => $fields,
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'licence-settings',
				),
			),
		),
	) );
} );

// ---------------------------------------------------------------------------
// Accessors. Every one of these is read by inc/updates/; nothing else in the
// theme touches these option values directly.
//
// All use the literal 'option' store rather than this page's own menu_slug,
// matching every other sub-page under inc/theme-settings/ — see
// inc/theme-settings/schema/organization/AGENT.md for the real bug caused by
// getting this wrong once.
// ---------------------------------------------------------------------------

function devbase_warden_base_url() {
	if ( ! function_exists( 'get_field' ) ) {
		return '';
	}

	return trim( (string) get_field( 'warden_base_url', 'option' ) );
}

function devbase_warden_public_key() {
	if ( ! function_exists( 'get_field' ) ) {
		return '';
	}

	return trim( (string) get_field( 'warden_public_key', 'option' ) );
}

/**
 * The site's single Warden licence key ('' when none is set).
 */
function devbase_warden_licence() {
	if ( ! function_exists( 'get_field' ) ) {
		return '';
	}

	return trim( (string) get_field( 'warden_licence_key', 'option' ) );
}

/**
 * A per-theme key stored by 1.17.0 and earlier (two keys, no tags). Those
 * fields no longer exist, but the saved values do, and a site that has not yet
 * been given the single key keeps updating with them (untagged checks, which
 * Warden answers for a licence with one app until 1.0).
 *
 * @param string $which 'parent' or 'child'.
 */
function devbase_warden_legacy_licence( $which ) {
	return trim( (string) get_option( 'options_warden_' . $which . '_licence', '' ) );
}

/**
 * The key to check the parent theme with: the single key, else the old
 * parent-only key.
 */
function devbase_warden_parent_licence() {
	$key = devbase_warden_licence();

	return '' !== $key ? $key : devbase_warden_legacy_licence( 'parent' );
}

/**
 * The key to check the child theme with: the single key, else the old
 * child-only key. (Child themes call this, so its name and meaning stay.)
 */
function devbase_warden_child_licence() {
	$key = devbase_warden_licence();

	return '' !== $key ? $key : devbase_warden_legacy_licence( 'child' );
}

/**
 * Drops the two old keys once the single key is in place, so nothing is left
 * to fall back on and no stale licence lingers in the database.
 */
function devbase_warden_forget_legacy_licences() {
	foreach ( array( 'parent', 'child' ) as $which ) {
		delete_option( 'options_warden_' . $which . '_licence' );
		delete_option( '_options_warden_' . $which . '_licence' );
	}
}

/**
 * Housekeeping, run on wp-admin pages: once the single key is set, the old
 * ones go.
 */
function devbase_warden_forget_replaced_licences() {
	if ( '' !== devbase_warden_licence()
		&& ( false !== get_option( 'options_warden_parent_licence' ) || false !== get_option( 'options_warden_child_licence' ) ) ) {
		devbase_warden_forget_legacy_licences();
	}
}
add_action( 'admin_init', 'devbase_warden_forget_replaced_licences' );

/**
 * Whether the Remote setup tab is hidden. Off by default (visible); switched on
 * with "Lock remote setup" on Theme Settings > Miscellaneous > Management, which
 * is out of the way enough that a client does not come across it. A filter can
 * still decide it in code.
 */
function devbase_remote_setup_hidden() {
	$locked = function_exists( 'get_field' ) && (bool) get_field( 'lock_remote_setup', 'option' );

	return (bool) apply_filters( 'devbase/licence/hide_remote_setup', $locked );
}

// The three licence values are provisioned remotely (inc/updates/relay-sync.php),
// so they are always shown but never editable. `disabled` rather than `readonly`:
// a disabled input is not submitted, so saving this page can never rewrite them,
// and browsers grey it out. Each gets its own short help, because the one notice
// at the top already says they are set automatically.
//
// Matched on the field KEY, not its name: acf_prepare_field() overwrites
// $field['name'] with the key (and an acf[...] prefix) before this filter runs,
// so a name check silently never matches.
add_filter( 'acf/prepare_field', function ( $field ) {
	$provisioned = array(
		'field_devbase_warden_licence_key' => __( 'Used to check for updates to this site\'s themes.', 'devbase_parent_theme' ),
		'field_devbase_warden_base_url'    => __( 'Where this site checks for theme updates.', 'devbase_parent_theme' ),
		'field_devbase_warden_public_key'  => __( 'Used to check that updates really come from your developer.', 'devbase_parent_theme' ),
	);

	if ( isset( $field['key'] ) && isset( $provisioned[ $field['key'] ] ) ) {
		$field['disabled']     = 1;
		$field['instructions'] = $provisioned[ $field['key'] ];
	}

	// A value baked in as a constant wins over its field (see relay-sync.php),
	// so the field is shown locked rather than offering an edit that does nothing.
	$baked = array(
		'field_devbase_relay_base_url' => 'DEVBASE_RELAY_URL',
		'field_devbase_relay_site_key' => 'DEVBASE_RELAY_SITE_KEY',
	);

	if ( isset( $field['key'], $baked[ $field['key'] ] ) && defined( $baked[ $field['key'] ] ) ) {
		$field['disabled']     = 1;
		$field['required']     = 0;
		$field['instructions'] = __( 'Set in the site\'s code and managed by the developer.', 'devbase_parent_theme' );
	}

	// The Remote setup tab, for the developer only where a child says so.
	$remote = array( 'field_devbase_licence_tab_relay', 'field_devbase_relay_base_url', 'field_devbase_relay_site_key', 'field_devbase_heartbeat_status', 'field_devbase_events_status' );

	if ( isset( $field['key'] ) && in_array( $field['key'], $remote, true ) && devbase_remote_setup_hidden() ) {
		return false;
	}

	return $field;
} );

// Loads the "Check for updates now" button's JS only on this specific admin
// page — same narrow enqueue as SMTP Settings' test.js and Miscellaneous's
// purge.js, since there's no reason to ship it site-wide.
add_action( 'admin_enqueue_scripts', function () {
	if ( ! isset( $_GET['page'] ) || 'licence-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	wp_enqueue_script(
		'devbase-check-updates',
		get_template_directory_uri() . '/inc/theme-settings/licence/check.js',
		array(),
		'1.0.0',
		true
	);

	wp_localize_script( 'devbase-check-updates', 'devbaseCheckUpdates', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'devbase_check_updates' ),
		'runningText' => __( 'Checking…', 'devbase_parent_theme' ),
		'errorText'   => __( 'The check could not be completed.', 'devbase_parent_theme' ),
	) );
} );
