<?php

// Registers the Miscellaneous sub-page (under Theme Settings) — a
// deliberate catch-all for settings that don't warrant their own
// top-level sub-page, organized into tabs (ACF's native `type => 'tab'`
// field, same mechanism as tracking/settings.php's Analytics/Custom
// Scripts/Cookies tabs — not the cross-file filter-based tab assembly
// schema/ uses, since that pattern exists for multiple features
// contributing tabs from separate folders, and this is one file with
// room to grow more tabs later without restructuring).
//
// Four tabs so far: Retention Policy, System Status, MCP Access, Credit.
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_options_sub_page( array(
		'page_title'  => __( 'Miscellaneous', 'devbase_parent_theme' ),
		'menu_title'  => __( 'Miscellaneous', 'devbase_parent_theme' ),
		'menu_slug'   => 'miscellaneous-settings',
		'parent_slug' => 'theme-settings',
	) );

	$retention_enabled_condition = array(
		array(
			array(
				'field'    => 'field_devbase_retention_enabled',
				'operator' => '==',
				'value'    => '1',
			),
		),
	);

	acf_add_local_field_group( array(
		'key'      => 'group_devbase_miscellaneous',
		'title'    => __( 'Miscellaneous', 'devbase_parent_theme' ),
		'fields'   => array(

			// Tab: Retention Policy.
			array(
				'key'   => 'field_devbase_misc_retention_tab',
				'label' => __( 'Retention Policy', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_devbase_retention_enabled',
				'label'         => __( 'Enable Retention Policy', 'devbase_parent_theme' ),
				'name'          => 'retention_enabled',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
				'instructions'  => __( 'Off by default: submissions are kept forever unless this is enabled. When on, a daily job permanently deletes form submissions older than the period configured below, for whichever forms each row applies to.', 'devbase_parent_theme' ),
			),
			array(
				'key'               => 'field_devbase_retention_policies',
				'label'             => __( 'Retention Policies', 'devbase_parent_theme' ),
				'name'              => 'retention_policies',
				'type'              => 'repeater',
				'layout'            => 'block',
				'button_label'      => __( 'Add Policy', 'devbase_parent_theme' ),
				'instructions'      => __( 'Each row deletes submissions older than the chosen period, for the form(s) it applies to. A form with no matching row is kept forever. A form covered by more than one row is purged at whichever period is shortest.', 'devbase_parent_theme' ),
				'conditional_logic' => $retention_enabled_condition,
				'sub_fields'        => array(
					array(
						'key'           => 'field_devbase_retention_period',
						'label'         => __( 'Delete After', 'devbase_parent_theme' ),
						'name'          => 'period',
						'type'          => 'select',
						'required'      => 1,
						'default_value' => '1_year',
						'choices'       => array(
							'1_day'    => __( '1 Day', 'devbase_parent_theme' ),
							'1_week'   => __( '1 Week', 'devbase_parent_theme' ),
							'1_month'  => __( '1 Month', 'devbase_parent_theme' ),
							'3_months' => __( '3 Months', 'devbase_parent_theme' ),
							'6_months' => __( '6 Months', 'devbase_parent_theme' ),
							'1_year'   => __( '1 Year', 'devbase_parent_theme' ),
						),
						'wrapper'       => array( 'width' => '50' ),
					),
					array(
						'key'          => 'field_devbase_retention_apply_to',
						'label'        => __( 'Applies To', 'devbase_parent_theme' ),
						'name'         => 'apply_to',
						'type'         => 'select',
						'multiple'     => 1,
						'ui'           => 1,
						'instructions' => __( 'Which registered form(s) this policy purges.', 'devbase_parent_theme' ),
						'choices'      => array(), // populated dynamically, see below
						'wrapper'      => array( 'width' => '50' ),
					),
				),
			),

			// Manual trigger — see purge-ajax.php for the handler. Real
			// verification without waiting for the daily cron run, same
			// pattern as SMTP Settings' "Send Test Email" button.
			array(
				'key'               => 'field_devbase_retention_run_now',
				'label'             => __( 'Run Purge Now', 'devbase_parent_theme' ),
				'name'              => '',
				'type'              => 'message',
				'message'           => '<button type="button" id="devbase-retention-purge-now" class="button">'
					. __( 'Run Purge Now', 'devbase_parent_theme' )
					. '</button> <span id="devbase-retention-purge-result"></span>',
				'conditional_logic' => $retention_enabled_condition,
			),

			// Shows when the job (cron OR the button above) last actually
			// ran, and flags a stale run — see
			// devbase_forms_retention_last_run_message() (inc/forms/retention.php)
			// for why this exists: WP-Cron only fires on a page load, and
			// some hosts disable it in favor of a real system cron, so
			// there'd otherwise be no way to tell "nothing needed
			// deleting" apart from "this silently stopped running weeks
			// ago." Re-evaluated on every page load of this admin screen,
			// not cached.
			array(
				'key'               => 'field_devbase_retention_last_run',
				'label'             => __( 'Status', 'devbase_parent_theme' ),
				'name'              => '',
				'type'              => 'message',
				'message'           => esc_html( devbase_forms_retention_last_run_message() ),
				'conditional_logic' => $retention_enabled_condition,
			),

			// Tab: System Status. Pulls together the three health
			// signals that otherwise each live on their own settings
			// page (SMTP Settings, the dispatch log scattered across
			// individual Form Data submissions, and this same page's
			// own Retention Policy tab) — so "is anything actually
			// broken right now" is answerable in one place instead of
			// checking three. Every field here is read-only and
			// recomputed on every page load, not cached — this is a
			// live status view, not a saved setting.
			array(
				'key'   => 'field_devbase_misc_status_tab',
				'label' => __( 'System Status', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'     => 'field_devbase_status_smtp',
				'label'   => __( 'SMTP', 'devbase_parent_theme' ),
				'name'    => '',
				'type'    => 'message',
				'message' => esc_html( devbase_smtp_last_test_message() ),
			),
			array(
				'key'          => 'field_devbase_status_dispatch',
				'label'        => __( 'Form Notifications', 'devbase_parent_theme' ),
				'name'         => '',
				'type'         => 'message',
				// Deliberately NOT esc_html()'d, unlike the other status
				// fields here — this one legitimately contains a real
				// HTML failure table (built with its own per-cell
				// esc_html() calls in status.php), not just plain text.
				'message'      => devbase_forms_dispatch_status_message(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'instructions' => __( 'Checks the most recent 50 dispatch attempts across all submissions.', 'devbase_parent_theme' ),
			),
			array(
				'key'     => 'field_devbase_status_retention',
				'label'   => __( 'Retention Policy', 'devbase_parent_theme' ),
				'name'    => '',
				'type'    => 'message',
				'message' => esc_html( devbase_forms_retention_last_run_message() ),
			),
			// The two vendored-dependency update checks. These matter
			// more than they look: SCF/ASE/MCP Adapter keep themselves
			// current on a daily WP-Cron event, which only fires when
			// the site gets a request. If that stops — no traffic, or a
			// host with WP-Cron disabled and no system cron — the site
			// silently stops receiving dependency updates, and there is
			// no other signal anywhere that it happened. This is the
			// only place that's visible.
			array(
				'key'          => 'field_devbase_status_dependencies',
				'label'        => __( 'Dependency Updates', 'devbase_parent_theme' ),
				'name'         => '',
				'type'         => 'message',
				'message'      => esc_html( devbase_dependency_last_check_message() ),
				'instructions' => __( 'Secure Custom Fields and Admin Site Enhancements, tracked against WordPress.org.', 'devbase_parent_theme' ),
			),
			array(
				'key'          => 'field_devbase_status_mcp',
				'label'        => __( 'MCP Adapter Updates', 'devbase_parent_theme' ),
				'name'         => '',
				'type'         => 'message',
				'message'      => esc_html( devbase_mcp_last_check_message() ),
				'instructions' => __( 'Tracked against GitHub Releases, separately from the two above.', 'devbase_parent_theme' ),
			),

			// Tab: MCP Access. Opt-in allow-list controlling exactly which
			// post types and options pages the MCP content abilities
			// (inc/abilities/) expose — nothing is listable/readable/
			// writable through MCP until explicitly checked on here.
			// registered_form/form_data never appear as choices at all
			// (see devbase_abilities_excluded_post_types() in
			// inc/abilities/helpers.php) — they're submission-log data,
			// not something to ever expose as an option.
			array(
				'key'   => 'field_devbase_misc_mcp_tab',
				'label' => __( 'MCP Access', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'          => 'field_devbase_mcp_allowed_post_types',
				'label'        => __( 'Post Types', 'devbase_parent_theme' ),
				'name'         => 'mcp_allowed_post_types',
				'type'         => 'select',
				'multiple'     => 1,
				'ui'           => 1,
				'instructions' => __( 'Which post types an MCP client can list, read, create, and update. Unlisted post types are completely invisible to MCP.', 'devbase_parent_theme' ),
				'choices'      => array(), // populated dynamically, see below
			),
			array(
				'key'          => 'field_devbase_mcp_allowed_options_pages',
				'label'        => __( 'Options Pages', 'devbase_parent_theme' ),
				'name'         => 'mcp_allowed_options_pages',
				'type'         => 'select',
				'multiple'     => 1,
				'ui'           => 1,
				'instructions' => __( 'Which options pages an MCP client can list, read, and update. Unlisted options pages are completely invisible to MCP.', 'devbase_parent_theme' ),
				'choices'      => array(), // populated dynamically, see below
			),
			// devbase/upload-media (inc/abilities/media.php). Off by default:
			// it lets an MCP client add files to the site, which is its own
			// decision, separate from which content it can edit.
			array(
				'key'           => 'field_devbase_mcp_allow_media_upload',
				'label'         => __( 'Allow Media Uploads', 'devbase_parent_theme' ),
				'name'          => 'mcp_allow_media_upload',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
				'instructions'  => __( 'Lets an MCP client add images and files to the media library, for example a photo for a block. Off: it can only use what is already in the library.', 'devbase_parent_theme' ),
			),

			// Tab: Credit. One setting; everything else is downloaded. See credit.php.
			array(
				'key'   => 'field_devbase_misc_credit_tab',
				'label' => __( 'Credit', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'          => 'field_devbase_credit_brand_url',
				'label'        => __( 'Brand Address', 'devbase_parent_theme' ),
				'name'         => 'credit_brand_url',
				'type'         => 'url',
				'instructions' => __( 'The public address of the brand in Brand Relay, for example https://relay.example.com/v1/brand/yourbrand. It is downloaded when you save this page and then once a day, and the credit is built from the saved copy. Leave it empty for no credit.', 'devbase_parent_theme' ),
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'options_page',
					'operator' => '==',
					'value'    => 'miscellaneous-settings',
				),
			),
		),
	) );
} );

// Dynamic choices for "Applies To" — see inc/forms/registry/choices.php.
add_filter( 'acf/load_field/key=field_devbase_retention_apply_to', function ( $field ) {
	$field['choices'] = devbase_forms_get_registered_form_choices();
	return $field;
} );

// Dynamic choices for the MCP Access tab's post type picker — every real
// content post type (see devbase_abilities_content_post_types()'s own
// show_in_rest+public filter in inc/abilities/helpers.php), minus the
// hardcoded exclusions, regardless of whether they're currently allowed —
// this picker IS how they get allowed.
add_filter( 'acf/load_field/key=field_devbase_mcp_allowed_post_types', function ( $field ) {
	$post_types = get_post_types( array( 'show_in_rest' => true, 'public' => true ), 'objects' );

	foreach ( devbase_abilities_excluded_post_types() as $excluded ) {
		unset( $post_types[ $excluded ] );
	}

	$choices = array();

	foreach ( $post_types as $post_type ) {
		$choices[ $post_type->name ] = $post_type->label;
	}

	$field['choices'] = $choices;
	return $field;
} );

// Dynamic choices for the MCP Access tab's options page picker. Built as a
// plain menu_slug => title loop, which also happens to resolve the
// duplicate-slug quirk in acf_get_options_pages() (the top-level "Theme
// Settings" parent page's menu_slug colliding with the real SMTP Settings
// sub-page's) — the second entry processed for a given slug simply
// overwrites the first, leaving one clean choice per real slug.
add_filter( 'acf/load_field/key=field_devbase_mcp_allowed_options_pages', function ( $field ) {
	$choices = array();

	foreach ( (array) acf_get_options_pages() as $page ) {
		$choices[ $page['menu_slug'] ] = $page['page_title'];
	}

	$field['choices'] = $choices;
	return $field;
} );

// Loads the "Run Purge Now" button's JS only on this specific admin page.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! isset( $_GET['page'] ) || 'miscellaneous-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	wp_enqueue_script(
		'devbase-retention-purge',
		get_template_directory_uri() . '/inc/theme-settings/miscellaneous/purge.js',
		array(),
		'1.0.0',
		true
	);

	wp_localize_script( 'devbase-retention-purge', 'devbaseRetentionPurge', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'devbase_retention_purge' ),
		'runningText' => __( 'Purging…', 'devbase_parent_theme' ),
		'errorText'   => __( 'Something went wrong. Please try again.', 'devbase_parent_theme' ),
	) );
} );
