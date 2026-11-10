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
		'capability'  => 'manage_options',
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
				'message'           => '', // devbase_message_when_shown(), below the group.
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
				'message' => '',
			),
			// Every mail the site sends, not only the test button (inc/theme-settings/smtp/failures.php).
			array(
				'key'     => 'field_devbase_status_mail',
				'label'   => __( 'Mail Delivery', 'devbase_parent_theme' ),
				'name'    => '',
				'type'    => 'message',
				'message' => '',
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
				'message'      => '',
				'instructions' => __( 'Checks the most recent 50 dispatch attempts across all submissions.', 'devbase_parent_theme' ),
			),
			// What the forms' honeypot discarded (inc/helpers/forms/submit.php). A discarded submission is
			// answered as a success, so this count is the only place a wrong catch can be seen.
			array(
				'key'     => 'field_devbase_status_honeypot',
				'label'   => __( 'Spam Trap', 'devbase_parent_theme' ),
				'name'    => '',
				'type'    => 'message',
				'message' => '',
			),
			array(
				'key'     => 'field_devbase_status_retention',
				'label'   => __( 'Retention Policy', 'devbase_parent_theme' ),
				'name'    => '',
				'type'    => 'message',
				'message' => '',
			),
			// The bundled dependency versions. They change only with a
			// theme release (see inc/dependencies/bundled-versions.php),
			// so this is what the site runs, read from vendor/.
			array(
				'key'          => 'field_devbase_status_dependencies',
				'label'        => __( 'Bundled Dependencies', 'devbase_parent_theme' ),
				'name'         => '',
				'type'         => 'message',
				'message'      => '',
				'instructions' => __( 'Shipped with the theme and updated only by a theme update.', 'devbase_parent_theme' ),
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
			// devbase/get-site-guide (inc/abilities/site-guide.php) returns it. Protected:
			// an MCP client reads it but can never change it.
			array(
				'key'          => 'field_devbase_mcp_site_guide',
				'label'        => __( 'Instructions for AI Assistants', 'devbase_parent_theme' ),
				'name'         => 'mcp_site_guide',
				'type'         => 'textarea',
				'rows'         => 14,
				'new_lines'    => '',
				'instructions' => __( 'What an AI assistant connected through MCP should know before it writes on this site: who the site is for, the tone to write in, and how pages are put together (which block starts a page, which ends it, what each block is for, how long the text should be). It reads this first. Plain text; it cannot change it.', 'devbase_parent_theme' ),
			),
			// What MCP clients changed (inc/abilities/audit-log.php). Read-only; built with its own escaping.
			array(
				'key'          => 'field_devbase_mcp_activity',
				'label'        => __( 'MCP Activity', 'devbase_parent_theme' ),
				'name'         => '',
				'type'         => 'message',
				'message'      => '',
				'instructions' => __( 'The last 20 changes made through MCP (kept 90 days), with the values an options page had before.', 'devbase_parent_theme' ),
			),

			// Tab: SEO. A tab of its own that is always visible (unlike Credit and Management, which hidden-tabs.js keeps
			// out of sight). One switch: whether The SEO Framework runs at all. Off by default.
			array(
				'key'   => 'field_devbase_misc_seo_tab',
				'label' => __( 'SEO', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_devbase_enable_tsf',
				'label'         => __( 'Enable The SEO Framework', 'devbase_parent_theme' ),
				'name'          => 'enable_tsf',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 0,
				'instructions'  => ( function_exists( 'devbase_tsf_conflicting_plugin' ) && devbase_tsf_conflicting_plugin()
					/* translators: %s = the name of the other SEO plugin. */
					? sprintf( __( 'Kept off while %s is active: two SEO plugins would print every tag twice. Deactivate it to be able to use The SEO Framework. ', 'devbase_parent_theme' ), devbase_tsf_conflicting_plugin() )
					: '' ) . __( 'Off by default. When on, The SEO Framework runs the site\'s SEO: titles, descriptions, social tags, structured data, the sitemap and robots.txt, and an SEO box on the edit screens. When off it is not loaded at all (not just hidden), so a client who runs their own SEO plugin has the page to themselves. The Organization Details page and the archive SEO tab are only there while it is on, because only The SEO Framework reads them. Nothing is deleted when you turn it off. It takes effect on the next page load.', 'devbase_parent_theme' ),
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
			// Tab: Management. One switch for the Licence page's Remote setup tab. Kept out of sight, like
			// the Credit tab, until a key is pressed twice (hidden-tabs.js).
			array(
				'key'   => 'field_devbase_misc_management_tab',
				'label' => __( 'Management', 'devbase_parent_theme' ),
				'name'  => '',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_devbase_lock_remote_setup',
				'label'         => __( 'Lock remote setup', 'devbase_parent_theme' ),
				'name'          => 'lock_remote_setup',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 1,
				'instructions'  => __( 'When on (the default), the Remote setup tab on the Licence page is hidden. When off, it is visible.', 'devbase_parent_theme' ),
			),
			array(
				'key'           => 'field_devbase_hide_wpvivid_ui',
				'label'         => __( 'Hide WPvivid Backup', 'devbase_parent_theme' ),
				'name'          => 'hide_wpvivid_ui',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 1,
				'instructions'  => __( 'When on (the default), WPvivid Backup is hidden from the admin menu, the admin bar, the Plugins list and its notices. Backups keep working. Hidden is not blocked: its page still opens by its address, and the Backups tab on the Licence page keeps offering the link to whoever has this off.', 'devbase_parent_theme' ),
			),
			array(
				'key'           => 'field_devbase_hide_scf_ui',
				'label'         => __( 'Hide Secure Custom Fields', 'devbase_parent_theme' ),
				'name'          => 'hide_scf_ui',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 1,
				'instructions'  => __( 'When on (the default), the Custom Fields menu and its screens (field groups, post types, taxonomies, options pages, tools) are hidden and closed, so nobody changes the site\'s structure by accident. Everything the theme built keeps working, and the Theme Settings pages stay. Turn it off here to edit field groups.', 'devbase_parent_theme' ),
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

// The read-only status lines, worked out only when this page is shown (see devbase_message_when_shown()).
// Re-evaluated on every load of the screen, never cached, as before.
devbase_message_when_shown( 'field_devbase_retention_last_run', fn() => esc_html( devbase_forms_retention_last_run_message() ) );
devbase_message_when_shown( 'field_devbase_status_smtp', fn() => esc_html( devbase_smtp_last_test_message() ) );
devbase_message_when_shown( 'field_devbase_status_mail', fn() => esc_html( devbase_mail_status_message() ) );
// Deliberately NOT esc_html()'d: a real HTML failure table, escaped per cell in status.php.
devbase_message_when_shown( 'field_devbase_status_dispatch', 'devbase_forms_dispatch_status_message' );
devbase_message_when_shown( 'field_devbase_status_honeypot', fn() => esc_html( devbase_form_honeypot_status_message() ) );
devbase_message_when_shown( 'field_devbase_status_retention', fn() => esc_html( devbase_forms_retention_last_run_message() ) );
devbase_message_when_shown( 'field_devbase_status_dependencies', fn() => esc_html( devbase_bundled_versions_message() ) );
// Built with its own escaping (inc/abilities/audit-log.php).
devbase_message_when_shown( 'field_devbase_mcp_activity', fn() => function_exists( 'devbase_abilities_audit_html' ) ? devbase_abilities_audit_html() : '' );

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
		// Pages holding secrets or the update server are never offered (see devbase_abilities_sensitive_options_pages()).
		if ( in_array( $page['menu_slug'], devbase_abilities_sensitive_options_pages(), true ) ) {
			continue;
		}

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

	// The Credit and Management tabs start hidden, in CSS so they never flash while the page loads.
	wp_register_style( 'devbase-hidden-tabs', false, array(), '1.0.0' );
	wp_enqueue_style( 'devbase-hidden-tabs' );
	wp_add_inline_style( 'devbase-hidden-tabs', 'body:not(.devbase-hidden-tabs-open) .acf-tab-group li:has(> :is([data-key="field_devbase_misc_credit_tab"], [data-key="field_devbase_misc_management_tab"])) { display: none; }' );

	wp_enqueue_script(
		'devbase-hidden-tabs',
		get_template_directory_uri() . '/inc/theme-settings/miscellaneous/hidden-tabs.js',
		array( 'acf-input' ),
		'1.0.0',
		true
	);
} );

/**
 * A Management switch that is ON until someone saves it off. It reads the saved option itself, not
 * get_field(): the Secure Custom Fields menu is decided before any field is registered, when get_field()
 * would find nothing and the default would not apply. An explicit "off" saved earlier stays off.
 *
 * @param string $name The field name (lock_remote_setup, hide_wpvivid_ui, hide_scf_ui).
 */
function devbase_switch_on( $name ) {
	$raw = get_option( 'options_' . $name, null );

	return null === $raw || false === $raw || '' === $raw ? true : (bool) (int) $raw;
}
