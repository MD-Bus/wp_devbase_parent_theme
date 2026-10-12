<?php

// Registers the SMTP Settings sub-page (under Theme Settings): a toggle to
// enable custom SMTP, the connection fields (hidden via ACF conditional
// logic until the toggle is on), and a "Send Test Email" button. The field
// group is registered here in code (not built through the SCF UI) so it
// ships with the theme and is identical on every site. The actual
// mail-sending logic that reads these fields lives in
// inc/theme-settings/smtp/mailer.php.
//
// Required fields: ACF skips required-field validation for any field
// currently hidden by conditional logic, so marking host/port/username/
// password/from-email as required only actually blocks saving while the
// enable toggle is on — exactly "if SMTP is enabled, don't let save work
// until everything is filled" with no custom JS needed. Encryption is
// deliberately NOT required: "None" is a legitimate choice whose
// underlying value is an empty string, so requiring it would make that
// choice unselectable. From Name is left optional too — mailer.php falls
// back to the site name if it's blank.
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'SMTP Settings', 'devbase_parent_theme' ),
		'menu_title'  => __( 'SMTP Settings', 'devbase_parent_theme' ),
		'menu_slug'   => 'smtp-settings',
		'parent_slug' => 'theme-settings',
	) );

	$enabled_condition = array(
		array(
			array(
				'field'    => 'field_devbase_smtp_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_notifications_smtp',
		'title'    => __( 'SMTP Server', 'devbase_parent_theme' ),
		'fields'   => array(
			array(
				'key'           => 'field_devbase_smtp_enabled',
				'label'         => __( 'Enable Custom SMTP', 'devbase_parent_theme' ),
				'name'          => 'smtp_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
			),
			// Row: connection basics — host, port, encryption together.
			array(
				'key'               => 'field_devbase_smtp_host',
				'label'             => __( 'SMTP Host', 'devbase_parent_theme' ),
				'name'              => 'smtp_host',
				'type'              => 'text',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_smtp_port',
				'label'             => __( 'SMTP Port', 'devbase_parent_theme' ),
				'name'              => 'smtp_port',
				'type'              => 'number',
				'required'          => 1,
				'default_value'     => 587,
				'wrapper'           => array( 'width' => '25' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_smtp_encryption',
				'label'             => __( 'Encryption', 'devbase_parent_theme' ),
				'name'              => 'smtp_encryption',
				'type'              => 'select',
				'choices'           => array(
					''    => __( 'None', 'devbase_parent_theme' ),
					'ssl' => 'SSL',
					'tls' => 'TLS',
				),
				'default_value'     => 'tls',
				'wrapper'           => array( 'width' => '25' ),
				'conditional_logic' => $enabled_condition,
			),

			// Row: authentication — username and password side by side.
			array(
				'key'               => 'field_devbase_smtp_username',
				'label'             => __( 'SMTP Username', 'devbase_parent_theme' ),
				'name'              => 'smtp_username',
				'type'              => 'text',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_smtp_password',
				'label'             => __( 'SMTP Password', 'devbase_parent_theme' ),
				'name'              => 'smtp_password',
				'type'              => 'password',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $enabled_condition,
			),

			// Row: sender identity — from email and from name side by side.
			array(
				'key'               => 'field_devbase_smtp_from_email',
				'label'             => __( 'From Email', 'devbase_parent_theme' ),
				'name'              => 'smtp_from_email',
				'type'              => 'email',
				'required'          => 1,
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $enabled_condition,
			),
			array(
				'key'               => 'field_devbase_smtp_from_name',
				'label'             => __( 'From Name', 'devbase_parent_theme' ),
				'name'              => 'smtp_from_name',
				'type'              => 'text',
				'wrapper'           => array( 'width' => '50' ),
				'conditional_logic' => $enabled_condition,
			),

			// Test button — see inc/theme-settings/smtp/test.php for the
			// AJAX handler and inc/theme-settings/smtp/test.js for the click
			// handling. Only shown once SMTP is enabled and saved settings
			// exist to test against.
			array(
				'key'               => 'field_devbase_smtp_test',
				'label'             => __( 'Test Connection', 'devbase_parent_theme' ),
				'name'              => '',
				'type'              => 'message',
				'message'           => '<button type="button" id="devbase-smtp-test" class="button">'
					. __( 'Send Test Email', 'devbase_parent_theme' )
					. '</button> <span id="devbase-smtp-test-result"></span>',
				'conditional_logic' => $enabled_condition,
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'smtp-settings',
				),
			),
		),
	) );
} );

// Loads the test-button JS only on this specific admin page — no reason to
// ship it site-wide in wp-admin.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! isset( $_GET['page'] ) || 'smtp-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	wp_enqueue_script(
		'devbase-smtp-test',
		get_template_directory_uri() . '/inc/theme-settings/smtp/test.js',
		array(),
		'1.0.0',
		true
	);

	wp_localize_script( 'devbase-smtp-test', 'devbaseSmtpTest', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'devbase_smtp_test' ),
		'sendingText' => __( 'Sending…', 'devbase_parent_theme' ),
		'errorText'   => __( 'Something went wrong. Please try again.', 'devbase_parent_theme' ),
	) );
} );
