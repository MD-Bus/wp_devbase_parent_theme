<?php

// Registers the Security sub-page (under Theme Settings) with two tabs —
// Cloudflare Turnstile and Google reCAPTCHA — each with its own enable
// toggle and credentials. Only one provider may be active at a time (see
// the mutual-exclusivity enforcement below); forms read which one is
// active and verify tokens through inc/theme-settings/security/verify.php,
// never by talking to either provider directly.
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Security', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Security', 'devbase_parent_theme' ),
		'menu_slug'   => 'security-settings',
		'parent_slug' => 'theme-settings',
	) );

	$turnstile_enabled_condition = array(
		array(
			array(
				'field'    => 'field_devbase_turnstile_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	$recaptcha_enabled_condition = array(
		array(
			array(
				'field'    => 'field_devbase_recaptcha_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	acf_add_local_field_group( array(
		'key'    => 'group_devbase_security',
		'title'  => __( 'Security', 'devbase_parent_theme' ),
		'fields' => array(

			// Tab 1: Cloudflare Turnstile.
			array(
				'key'   => 'field_devbase_turnstile_tab',
				'label' => __( 'Turnstile', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_devbase_turnstile_enabled',
				'label'         => __( 'Enable Turnstile', 'devbase_parent_theme' ),
				'name'          => 'turnstile_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
			),
			array(
				'key'               => 'field_devbase_turnstile_site_key',
				'label'             => __( 'Site Key', 'devbase_parent_theme' ),
				'name'              => 'turnstile_site_key',
				'type'              => 'text',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $turnstile_enabled_condition,
			),
			array(
				'key'               => 'field_devbase_turnstile_secret_key',
				'label'             => __( 'Secret Key', 'devbase_parent_theme' ),
				'name'              => 'turnstile_secret_key',
				'type'              => 'password',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $turnstile_enabled_condition,
			),

			// Tab 2: Google reCAPTCHA v3 (score-based, invisible). v2 and Enterprise keys
			// are the wrong type and are not supported.
			array(
				'key'   => 'field_devbase_recaptcha_tab',
				'label' => __( 'reCAPTCHA', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_devbase_recaptcha_enabled',
				'label'         => __( 'Enable reCAPTCHA', 'devbase_parent_theme' ),
				'name'          => 'recaptcha_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
				'instructions'  => __( 'Google reCAPTCHA v3: invisible, with no box to tick. Each visitor gets a score and low scores are blocked. Use a score-based (v3) key; a checkbox (v2) or Enterprise key shows "Invalid key type".', 'devbase_parent_theme' ),
			),
			array(
				'key'               => 'field_devbase_recaptcha_site_key',
				'label'             => __( 'Site Key', 'devbase_parent_theme' ),
				'name'              => 'recaptcha_site_key',
				'type'              => 'text',
				'instructions'      => __( 'The v3 site key. Add this website\'s domain to the key in Google.', 'devbase_parent_theme' ),
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $recaptcha_enabled_condition,
			),
			array(
				'key'               => 'field_devbase_recaptcha_secret_key',
				'label'             => __( 'Secret Key', 'devbase_parent_theme' ),
				'name'              => 'recaptcha_secret_key',
				'type'              => 'password',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $recaptcha_enabled_condition,
			),
			array(
				'key'               => 'field_devbase_recaptcha_min_score',
				'label'             => __( 'Minimum Score', 'devbase_parent_theme' ),
				'name'              => 'recaptcha_min_score',
				'type'              => 'number',
				'default_value'     => 0.5,
				'min'               => 0.1,
				'max'               => 1,
				'step'              => 0.1,
				'instructions'      => __( 'Google scores each visitor from 0.0 (most likely a bot) to 1.0 (most likely human). Anyone scoring below this is blocked. 0.5 is Google\'s default; raise it to block more bots, lower it if real visitors are being blocked.', 'devbase_parent_theme' ),
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $recaptcha_enabled_condition,
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'security-settings',
				),
			),
		),
	) );
} );

// Server-side enforcement of "only one provider active at a time" — the
// real backstop. security.js also auto-toggles the other switch off for a
// nicer live UX, but that's JS running in the admin's browser and can't be
// trusted as the actual guarantee; this runs on save, on the server,
// regardless of what the browser did or didn't do.
add_action( 'acf/validate_save_post', function () {
	if ( empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}

	$acf = $_POST['acf']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	$turnstile_enabled = ! empty( $acf['field_devbase_turnstile_enabled'] );
	$recaptcha_enabled = ! empty( $acf['field_devbase_recaptcha_enabled'] );

	if ( $turnstile_enabled && $recaptcha_enabled ) {
		acf_add_validation_error(
			'field_devbase_recaptcha_enabled',
			__( 'Only one of Turnstile or reCAPTCHA can be enabled at a time. Disable the other one first.', 'devbase_parent_theme' )
		);
	}
}, 10, 0 );

// Loads the mutual-exclusivity toggle JS only on this specific admin page.
add_action( 'admin_enqueue_scripts', function () {
	if ( ! isset( $_GET['page'] ) || 'security-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	wp_enqueue_script(
		'devbase-security-settings',
		get_template_directory_uri() . '/inc/theme-settings/security/security.js',
		array(),
		'1.0.0',
		true
	);
} );
