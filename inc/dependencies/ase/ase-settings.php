<?php

// Our standard Admin Site Enhancements module/setting defaults, added one by
// one as decided. Shared between the two hooks below.
//
// A child theme can adjust this baseline through the
// 'devbase/ase/defaults' filter (see devbase_ase_resolved_defaults()
// below) — but read the note on that function first: a DEFAULT only ever
// fills a gap, so changing one has no effect on a site where that ASE key
// already has a stored value. For a client-specific setting that must win
// regardless, use 'devbase/ase/enforced' instead.
function devbase_ase_default_settings() {
	return array(
		// Allow Administrators to upload SVG files (sanitized on upload).
		'enable_svg_upload'     => true,
		'enable_svg_upload_for' => array(
			'administrator' => true,
		),

		// Auto-publish scheduled posts that WP-Cron missed.
		'enable_missed_schedule_posts_auto_publish' => true,

		// Clean up admin bar: master toggle for the module below, plus the
		// specific items to remove.
		'hide_modify_elements'     => true,
		'hide_ab_wp_logo_menu'     => true, // Remove WordPress logo/menu.
		'hide_ab_customize_menu'   => true, // Remove Customize menu.
		'hide_ab_comments_menu'    => true, // Remove comments counter/link.
		'hide_ab_new_content_menu' => true, // Remove new content ("+ New") menu.
		'hide_ab_howdy'            => true, // Remove 'Howdy' greeting.

		// Hide admin notices (tucked behind a bell icon in the admin bar instead).
		'hide_admin_notices' => true,

		// Disable dashboard widgets: master toggle, plus Quick Draft and
		// WordPress Events and News specifically. Keys are
		// {widget_id}__{context}__{priority}, per WP core's registration of
		// these two widgets (wp-admin/includes/dashboard.php).
		'disable_dashboard_widgets'  => true,
		'disabled_dashboard_widgets' => array(
			'dashboard_quick_press__side__core' => true, // Quick Draft.
			'dashboard_primary__side__core'     => true, // WordPress Events and News.
		),

		// Widen the admin menu to 220px (default is 200px). The value is
		// echoed straight into CSS with no unit appended, so it must
		// include "px" itself (confirmed in ASE's own
		// includes/admin-menu-width/wp-v5-greater.php).
		'wider_admin_menu' => true,
		'admin_menu_width' => '220px',

		// Enhance List Tables: master toggle, plus the featured image and
		// excerpt columns.
		'enhance_list_tables'        => true,
		'show_featured_image_column' => true,
		'show_excerpt_column'        => true,

		// Custom admin footer text: master toggle, left side (site name +
		// rights notice) and right side (credit).
		'custom_admin_footer_text'  => true,
		'custom_admin_footer_left'  => sprintf(
			'%s &copy; %s. All rights reserved.',
			get_bloginfo( 'name' ),
			gmdate( 'Y' )
		),
		// Agency branding is deliberately NOT set here. This theme ships to
		// anyone, and a hardcoded footer credit would stamp one agency's name
		// on every site installing it. Set it per client from a child theme:
		//
		//   add_filter( 'devbase/ase/enforced', function ( $enforced ) {
		//       $enforced['custom_admin_footer_right'] = 'Site Built by Acme';
		//       return $enforced;
		//   } );

		// Use the site icon and site URL on the login page instead of WordPress's own.
		'site_identity_on_login' => true,

		// Enables per-user "disable login" (adds a checkbox on each user's
		// profile screen) — doesn't disable any specific user by default.
		'disable_user_account' => true,

		// Blocks /?author=1 style author archives, which otherwise leak
		// usernames (including admin accounts) via the resulting redirect/slug.
		'disable_author_archives' => true,

		// Locks out an IP/username after repeated failed logins. Its log
		// table is created separately — see inc/dependencies/ase-limit-login-table.php.
		'limit_login_attempts' => true,

		// Obfuscates author slugs (e.g. /author/username/ -> a hashed slug)
		// so usernames aren't exposed via author archive URLs.
		'obfuscate_author_slugs' => true,

		// Disable Smaller Components: master toggle, plus the sub-items that
		// are zero/near-zero risk with a real benefit. Deliberately NOT
		// enabling: resource version numbers (breaks cache-busting on
		// updates), frontend Dashicons (can break custom login/forms),
		// jQuery Migrate (can break older third-party plugins), block
		// widgets screen (UI preference, not security/perf), lazy loading
		// (disabling it is a performance regression), application passwords
		// (a secure, legitimate REST API auth method — disabling breaks
		// integrations without closing a real vulnerability), and the
		// user's own password-change notification (a real account-takeover
		// detection signal — removing it makes the site less secure).
		'disable_smaller_components'                            => true,
		'disable_head_generator_tag'                             => true, // Hide WP version in <head> meta generator tag.
		'disable_feed_generator_tag'                              => true, // Hide WP version in RSS <generator> tag.
		'disable_head_wlwmanifest_tag'                            => true, // Windows Live Writer manifest tag (dead feature since 2017).
		'disable_head_rsd_tag'                                    => true, // Really Simple Discovery link tag.
		'disable_head_shortlink_tag'                              => true, // Default WP shortlink tag (no SEO value).
		'disable_emoji_support'                                   => true, // Emoji polyfill unneeded by modern browsers.
		'disable_site_admin_email_verification_screen'            => true, // Periodic "confirm your admin email" nag screen.
		'disable_admin_email_notification_after_password_change' => true, // Admin gets emailed on every user's password change — noise, not a meaningful signal.
		'disable_plugin_theme_editor'                             => true, // Blocks the live PHP editor in Appearance/Plugins — standard hardening.

		// Disable comments site-wide (posts, pages, attachments) — most of
		// our sites don't need comments.
		'disable_comments'     => true,
		'disable_comments_for' => array(
			'post'       => true,
			'page'       => true,
			'attachment' => true,
		),
	);
}

// Fills in any of our default keys that are missing — on both routine admin
// page loads and on reactivating this theme. Deliberately never overwrites a
// key that already has a value, even after a theme switch: there's no way to
// tell "ASE's own settings form reset this to false on a stale save" apart
// from "a site admin deliberately turned this off and saved" — both produce
// identical stored state. Since silently re-enabling something a client
// turned off is worse than leaving an accidental reset uncorrected, this
// only ever fills gaps, never reasserts.
/**
 * This theme's baseline defaults, after any child-theme adjustment.
 *
 * Filtered values here are still DEFAULTS in the strict sense: the merge
 * below only fills keys that don't already exist, so changing one of these
 * from a child theme affects a fresh site but silently does nothing on a
 * site where an admin (or ASE's own settings form) has already written that
 * key. That is deliberate and unchanged — see the comment on the merge.
 */
function devbase_ase_resolved_defaults() {
	$defaults = apply_filters( 'devbase/ase/defaults', devbase_ase_default_settings() );

	return is_array( $defaults ) ? $defaults : array();
}

/**
 * Settings a site insists on, reasserted on every run even when a value
 * already exists.
 *
 * Empty in this parent theme by design — nothing here is enforced, because
 * this theme ships identically to every client site and can't know which
 * deviations are deliberate. It exists for CHILD themes: a child declaring
 * a key here is making an explicit, client-specific statement that its
 * value must win, which is categorically different from the ambient default
 * above. That distinction is what keeps the "never silently reassert" rule
 * intact — the rule exists because this theme cannot tell an intentional
 * change apart from ASE's own stale-form-save reset, and a child theme
 * naming one specific key removes exactly that ambiguity.
 *
 * Use sparingly: an enforced key can no longer be changed from ASE's own
 * settings page in wp-admin (it will revert on the next admin page load),
 * which is confusing for a site admin who doesn't know why.
 */
function devbase_ase_enforced_settings() {
	$enforced = apply_filters( 'devbase/ase/enforced', array() );

	return is_array( $enforced ) ? $enforced : array();
}

$devbase_apply_ase_defaults = function () {
	if ( ! file_exists( get_template_directory() . '/vendor/admin-site-enhancements/admin-site-enhancements.php' ) ) {
		return;
	}

	$current = get_option( 'admin_site_enhancements', array() );

	// array_merge( defaults, current ): current wins, so this only ever
	// fills in keys that are missing entirely — never overwrites.
	$merged = array_merge( devbase_ase_resolved_defaults(), $current );

	// Enforced keys go the other way round: they're applied AFTER the
	// fill-gaps merge and deliberately overwrite whatever is stored.
	$merged = array_merge( $merged, devbase_ase_enforced_settings() );

	// Avoid a DB write when nothing actually changed.
	if ( $merged !== $current ) {
		update_option( 'admin_site_enhancements', $merged );
	}
};

add_action( 'admin_init', $devbase_apply_ase_defaults );
add_action( 'after_switch_theme', $devbase_apply_ase_defaults );
