<?php

// Registers the Email Settings sub-page (under Theme Settings), in two tabs,
// one per way of sending (only one may be on; api.php refuses a save that
// turns on both):
//   - SMTP: a toggle to enable custom SMTP, the connection fields (hidden via
//     ACF conditional logic until the toggle is on), and a "Send Test Email"
//     button.
//   - Email API: for hosts that block the SMTP ports. A toggle, the service
//     (devbase_mail_api_providers(), Brevo first), then that service's own
//     fields and the shared sender, and the same test button. The field
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
		'page_title'  => __( 'Email Settings', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Email Settings', 'devbase_parent_theme' ),
		'menu_slug'   => 'smtp-settings',
		'capability'  => 'manage_options',
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
		'title'    => __( 'Email', 'devbase_parent_theme' ),
		'fields'   => array_merge( array(
			// Tab 1: SMTP. The field keys are the ones from before the tabs, so nothing saved moves.
			array(
				'key'   => 'field_devbase_smtp_tab',
				'label' => __( 'SMTP', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_devbase_smtp_enabled',
				'label'         => __( 'Enable Custom SMTP', 'devbase_parent_theme' ),
				'name'          => 'smtp_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
				'instructions'  => __( 'Only one way of sending can be on. To use the Email API tab, switch this off.', 'devbase_parent_theme' ),
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
				'message'           => devbase_mail_test_button( 'smtp' ),
				'conditional_logic' => $enabled_condition,
			),
		), devbase_mail_api_fields() ),
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

/**
 * The "Send Test Email" button and its result line, for one tab. test.js handles every one on the page.
 *
 * @param string $route 'smtp' or 'api'.
 */
function devbase_mail_test_button( $route ) {
	return '<button type="button" class="button devbase-mail-test" data-route="' . esc_attr( $route ) . '">'
		. esc_html__( 'Send Test Email', 'devbase_parent_theme' )
		. '</button> <span class="devbase-mail-test-result" aria-live="polite"></span>';
}

/**
 * The Email API tab: the banner, the switch, the service, each service's own fields (shown once it is chosen), the
 * shared sender and the test button. Required fields block a save only while they are shown (see the top of this file).
 *
 * @return array SCF field definitions.
 */
function devbase_mail_api_fields() {
	$on = array(
		'field'    => 'field_devbase_mail_api_enabled',
		'operator' => '==',
		'value'    => '1',
	);

	$choices = array( '' => __( 'Choose a service', 'devbase_parent_theme' ) );

	foreach ( devbase_mail_api_providers() as $id => $provider ) {
		$choices[ $id ] = (string) $provider['label'];
	}

	$fields = array(
		array(
			'key'   => 'field_devbase_mail_api_tab',
			'label' => __( 'Email API', 'devbase_parent_theme' ),
			'name'  => '',
			'type'  => 'tab',
		),
		array(
			'key'     => 'field_devbase_mail_api_banner',
			'label'   => '',
			'name'    => '',
			'type'    => 'message',
			'message' => '<div class="notice notice-info inline devbase-mail-api-banner"><p><strong>'
				. esc_html__( 'Use this when your host blocks the SMTP ports (25, 465 and 587).', 'devbase_parent_theme' )
				. '</strong> '
				. esc_html__( 'Mail is sent over HTTPS through the email service\'s API, the connection the site already uses, so it works where SMTP times out. If SMTP works on your host, you can keep using SMTP.', 'devbase_parent_theme' )
				. '</p></div>',
		),
		array(
			'key'           => 'field_devbase_mail_api_enabled',
			'label'         => __( 'Enable Email API', 'devbase_parent_theme' ),
			'name'          => 'mail_api_enabled',
			'type'          => 'true_false',
			'ui'            => 1,
			'default_value' => 0,
			'instructions'  => __( 'Only one way of sending can be on. To use this, switch SMTP off on the SMTP tab.', 'devbase_parent_theme' ),
		),
		array(
			'key'               => 'field_devbase_mail_api_provider',
			'label'             => __( 'Service', 'devbase_parent_theme' ),
			'name'              => 'mail_api_provider',
			'type'              => 'select',
			'choices'           => $choices,
			'default_value'     => '',
			'required'          => 1,
			'conditional_logic' => array( array( $on ) ),
		),
	);

	foreach ( devbase_mail_api_providers() as $id => $provider ) {
		foreach ( (array) ( $provider['fields'] ?? array() ) as $field ) {
			$field['conditional_logic'] = array( array( $on, array(
				'field'    => 'field_devbase_mail_api_provider',
				'operator' => '==',
				'value'    => (string) $id,
			) ) );

			$fields[] = $field;
		}
	}

	$chosen = array( array( $on, array(
		'field'    => 'field_devbase_mail_api_provider',
		'operator' => '!=empty',
	) ) );

	return array_merge( $fields, array(
		array(
			'key'               => 'field_devbase_mail_api_from_email',
			'label'             => __( 'From Email', 'devbase_parent_theme' ),
			'name'              => 'mail_api_from_email',
			'type'              => 'email',
			'required'          => 1,
			'instructions'      => __( 'An address on a domain verified with the service, or a sender it has confirmed.', 'devbase_parent_theme' ),
			'wrapper'           => array( 'width' => '50' ),
			'conditional_logic' => $chosen,
		),
		array(
			'key'               => 'field_devbase_mail_api_from_name',
			'label'             => __( 'From Name', 'devbase_parent_theme' ),
			'name'              => 'mail_api_from_name',
			'type'              => 'text',
			'instructions'      => __( 'Left empty, the site name is used.', 'devbase_parent_theme' ),
			'wrapper'           => array( 'width' => '50' ),
			'conditional_logic' => $chosen,
		),
		array(
			'key'               => 'field_devbase_mail_api_test',
			'label'             => __( 'Test Connection', 'devbase_parent_theme' ),
			'name'              => '',
			'type'              => 'message',
			'message'           => devbase_mail_test_button( 'api' ),
			'conditional_logic' => $chosen,
		),
	) );
}

// Loads the test-button JS only on this specific admin page — no reason to
// ship it site-wide in wp-admin.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! devbase_settings_page_is( 'smtp-settings' ) ) {
		return;
	}

	wp_enqueue_script(
		'devbase-smtp-test',
		get_template_directory_uri() . '/inc/theme-settings/smtp/test.js',
		array(),
		'1.1.0',
		true
	);

	wp_localize_script( 'devbase-smtp-test', 'devbaseSmtpTest', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'devbase_smtp_test' ),
		'sendingText' => __( 'Sending…', 'devbase_parent_theme' ),
		'errorText'   => __( 'Something went wrong. Please try again.', 'devbase_parent_theme' ),
	) );
} );
