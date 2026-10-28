<?php

// Registers the Licence sub-page (under Theme Settings): the Warden
// endpoint and signing key for this install, plus one licence key per
// theme track — this parent theme, and the active child theme.
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
		array(
			'key'       => 'field_devbase_licence_warning',
			'label'     => '',
			'name'      => '',
			'type'      => 'message',
			'message'   => '<p style="margin:0;padding:12px 14px;border:2px solid #d63638;border-radius:4px;background:#fcf0f1;color:#d63638;font-size:14px;font-weight:700;">'
				. esc_html__( 'If you are not the developer, do not edit anything on this page.', 'devbase_parent_theme' )
				. '</p>',
			'new_lines' => '',
			'esc_html'  => 0,
		),
		array(
			'key'   => 'field_devbase_licence_tab_connection',
			'label' => __( 'Connection', 'devbase_parent_theme' ),
			'type'  => 'tab',
		),
		array(
			'key'          => 'field_devbase_warden_base_url',
			'label'        => __( 'Warden URL', 'devbase_parent_theme' ),
			'name'         => 'warden_base_url',
			'type'         => 'url',
			'instructions' => __( 'Base URL of the Warden deployment, with no trailing path — e.g. https://warden.example.workers.dev', 'devbase_parent_theme' ),
		),
		array(
			'key'          => 'field_devbase_warden_public_key',
			'label'        => __( 'Warden Public Key', 'devbase_parent_theme' ),
			'name'         => 'warden_public_key',
			'type'         => 'textarea',
			'rows'         => 2,
			'instructions' => __( 'Base64 Ed25519 public key, copied from the licence page in Warden. Every response is verified against this before it is trusted; without it, update checks are skipped entirely rather than trusted unverified.', 'devbase_parent_theme' ),
		),
		array(
			'key'   => 'field_devbase_licence_tab_keys',
			'label' => __( 'Licence Keys', 'devbase_parent_theme' ),
			'type'  => 'tab',
		),
		array(
			'key'          => 'field_devbase_warden_parent_licence',
			'label'        => __( 'Base Theme Licence Key', 'devbase_parent_theme' ),
			'name'         => 'warden_parent_licence',
			'type'         => 'password',
			'instructions' => __( 'Licence key covering this base (parent) theme. Shown as dots; paste a new key to replace it. Leave empty to disable update checks for it.', 'devbase_parent_theme' ),
		),
		array(
			'key'          => 'field_devbase_warden_child_licence',
			'label'        => __( 'Child Theme Licence Key', 'devbase_parent_theme' ),
			'name'         => 'warden_child_licence',
			'type'         => 'password',
			'instructions' => __( 'Licence key covering this site\'s own child theme. Shown as dots; paste a new key to replace it. Leave empty to disable update checks for it.', 'devbase_parent_theme' ),
		),
		array(
			'key'   => 'field_devbase_licence_tab_status',
			'label' => __( 'Status', 'devbase_parent_theme' ),
			'type'  => 'tab',
		),
		array(
			'key'     => 'field_devbase_licence_status',
			'label'   => __( 'Current Status', 'devbase_parent_theme' ),
			'type'    => 'message',
			'message' => devbase_licence_status_html(),
			'esc_html' => 0,
		),
		// Manual trigger. Nothing on a client site needs this — every check
		// it runs is already on a daily WP-Cron schedule that fires on any
		// front-end request. It exists so WE can verify a licence or a
		// freshly-published release immediately instead of waiting up to a
		// day (or 12 hours, for Warden's own cache). Same framing as SMTP
		// Settings' "Send Test Email" and Retention Policy's "Run Purge
		// Now", and it calls exactly the same functions the cron jobs do —
		// see check-ajax.php.
		array(
			'key'     => 'field_devbase_licence_check_now',
			'label'   => __( 'Check for Updates Now', 'devbase_parent_theme' ),
			'type'    => 'message',
			'message' => '<button type="button" id="devbase-check-updates-now" class="button">'
				. esc_html__( 'Check for Updates Now', 'devbase_parent_theme' )
				. '</button> <span id="devbase-check-updates-result" style="white-space:pre-line;display:inline-block;vertical-align:top;"></span>',
			'esc_html' => 0,
			'instructions' => __( 'Runs every update check immediately, bypassing the usual schedule and Warden\'s 12-hour cache. Uses saved settings — save first, then check.', 'devbase_parent_theme' ),
		),
	);

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_licence',
		'title'    => __( 'Licence & Updates', 'devbase_parent_theme' ),
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

function devbase_warden_parent_licence() {
	if ( ! function_exists( 'get_field' ) ) {
		return '';
	}

	return trim( (string) get_field( 'warden_parent_licence', 'option' ) );
}

function devbase_warden_child_licence() {
	if ( ! function_exists( 'get_field' ) ) {
		return '';
	}

	return trim( (string) get_field( 'warden_child_licence', 'option' ) );
}

// Loads the "Check for Updates Now" button's JS only on this specific admin
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
		'errorText'   => __( 'Request failed.', 'devbase_parent_theme' ),
	) );
} );
