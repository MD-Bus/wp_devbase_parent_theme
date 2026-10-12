<?php

// Registers the Form Destinations sub-page (under Theme Settings): one
// repeater where each row IS a destination — pick a Type (SMTP or
// Webhook) and the row reveals the fields relevant to that type, plus an
// "Applies To" multi-select naming which Registered Form(s) route
// submissions here. Replaces what used to be two separate places
// (SMTP Settings' own Destinations repeater, a standalone Webhook
// Settings page) plus a per-Registered-Form Destinations picker — see
// inc/forms/AGENT.md for why: a row is now self-contained (its own
// routing lives on the same row as its own send config), so there's no
// cross-page reference left to keep in sync or orphan.
//
// Required fields: same "ACF skips required-validation on a field
// currently hidden by conditional logic" mechanism already used by SMTP
// Settings/Security Settings/Schema — To Email is only required while
// Type is "SMTP"; URL only while Type is "Webhook". Conditional logic
// here targets a SIBLING field within the same repeater row (Type's
// field key), which ACF evaluates per-row, not globally.
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Form Destinations', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Form Destinations', 'devbase_parent_theme' ),
		'menu_slug'   => 'destinations-settings',
		'parent_slug' => 'theme-settings',
	) );

	$smtp_condition = array(
		array(
			array(
				'field'    => 'field_devbase_destination_type',
				'operator' => '==',
				'value'    => 'smtp',
			),
		),
	);

	$webhook_condition = array(
		array(
			array(
				'field'    => 'field_devbase_destination_type',
				'operator' => '==',
				'value'    => 'webhook',
			),
		),
	);

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_form_destinations',
		'title'    => __( 'Destinations', 'devbase_parent_theme' ),
		'fields'   => array(
			array(
				'key'          => 'field_devbase_destinations',
				'label'        => __( 'Destinations', 'devbase_parent_theme' ),
				'name'         => 'destinations',
				'type'         => 'repeater',
				'layout'       => 'block',
				'button_label' => __( 'Add Destination', 'devbase_parent_theme' ),
				'instructions' => __( 'Each row is a place submissions can be sent, and which registered form(s) route there.', 'devbase_parent_theme' ),
				'sub_fields'   => array(
					array(
						'key'      => 'field_devbase_destination_label',
						'label'    => __( 'Label', 'devbase_parent_theme' ),
						'name'     => 'label',
						'type'     => 'text',
						'required' => 1,
						'wrapper'  => array( 'width' => '50' ),
					),
					array(
						'key'           => 'field_devbase_destination_type',
						'label'         => __( 'Type', 'devbase_parent_theme' ),
						'name'          => 'type',
						'type'          => 'select',
						'required'      => 1,
						'default_value' => 'smtp',
						'choices'       => array(
							'smtp'    => __( 'SMTP (email)', 'devbase_parent_theme' ),
							'webhook' => __( 'Webhook', 'devbase_parent_theme' ),
						),
						'wrapper'       => array( 'width' => '50' ),
					),
					array(
						'key'               => 'field_devbase_destination_to_email',
						'label'             => __( 'Send To', 'devbase_parent_theme' ),
						'name'              => 'to_email',
						'type'              => 'email',
						'required'          => 1,
						'conditional_logic' => $smtp_condition,
					),
					array(
						'key'               => 'field_devbase_destination_url',
						'label'             => __( 'Endpoint URL', 'devbase_parent_theme' ),
						'name'              => 'url',
						'type'              => 'url',
						'required'          => 1,
						'conditional_logic' => $webhook_condition,
					),
					array(
						'key'               => 'field_devbase_destination_auth_value',
						'label'             => __( 'Password / Key', 'devbase_parent_theme' ),
						'name'              => 'auth_value',
						'type'              => 'password',
						'instructions'      => __( 'Optional. Value sent in the header below to authenticate the request.', 'devbase_parent_theme' ),
						'wrapper'           => array( 'width' => '50' ),
						'conditional_logic' => $webhook_condition,
					),
					array(
						'key'               => 'field_devbase_destination_auth_header_name',
						'label'             => __( 'Header Name', 'devbase_parent_theme' ),
						'name'              => 'auth_header_name',
						'type'              => 'text',
						'placeholder'       => 'X-API-Key',
						'instructions'      => __( 'Name of the HTTP header used to send the password/key above (e.g. X-API-Key, Authorization…).', 'devbase_parent_theme' ),
						'wrapper'           => array( 'width' => '50' ),
						'conditional_logic' => $webhook_condition,
					),
					array(
						'key'          => 'field_devbase_destination_apply_to',
						'label'        => __( 'Applies To', 'devbase_parent_theme' ),
						'name'         => 'apply_to',
						'type'         => 'select',
						'multiple'     => 1,
						'ui'           => 1,
						'instructions' => __( 'Which registered form(s) should route submissions to this destination.', 'devbase_parent_theme' ),
						'choices'      => array(), // populated dynamically, see below
					),
				),
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'destinations-settings',
				),
			),
		),
	) );
} );

// Dynamic choices for "Applies To" — see inc/forms/registry/choices.php.
// Fires every time this field is loaded, including once per existing
// repeater row — always reflects whichever forms are currently
// registered, never stale or hand-maintained.
add_filter( 'acf/load_field/key=field_devbase_destination_apply_to', function ( $field ) {
	$field['choices'] = devbase_forms_get_registered_form_choices();
	return $field;
} );
