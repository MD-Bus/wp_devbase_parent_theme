<?php

// SCF computes its own asset URL with plugin_dir_url(), which only resolves
// correctly for files under wp-content/plugins/. Since SCF is loaded from
// the theme's vendor/ folder instead, that computation returns a broken URL
// and its admin CSS/JS 404s. Override the path/url settings SCF reads so its
// assets resolve to the real location.

// Points SCF at its real filesystem path inside vendor/.
add_filter( 'acf/settings/path', function () {
	return get_template_directory() . '/vendor/secure-custom-fields/';
} );

// Points SCF at its real public URL inside vendor/, fixing the CSS/JS 404s.
add_filter( 'acf/settings/url', function () {
	return get_template_directory_uri() . '/vendor/secure-custom-fields/';
} );

// Boots SCF itself, now that its path/url settings are corrected.
require_once get_template_directory() . '/vendor/secure-custom-fields/secure-custom-fields.php';

// SCF's own Abilities API integration (SCF_Abilities_Integration, in
// vendor/secure-custom-fields/includes/abilities/) hooks its setup on
// `plugins_loaded` — but that action has already fired by the time this
// file runs, since themes load after plugins do. Same class of bug this
// theme hit before with FileBird's activation hook (see
// ase-limit-login-table.php): a hook SCF assumes fires during its own
// boot has already passed by the time vendor/-loading gets here, so its
// callback silently never runs. Replicate what that callback would have
// done — require the same ability class files directly — rather than
// patching vendor/ code the auto-updater would overwrite anyway. Safe
// regardless of exactly when this runs: each of these files defers its
// real wp_register_ability() calls to the `wp_abilities_api_init` hook,
// which WordPress core fires lazily (on first access, after `init`), not
// tied to plugin/theme load order.
if ( function_exists( 'acf_include' ) && function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' ) ) {
	acf_include( 'includes/abilities/class-scf-internal-post-type-abilities.php' );
	acf_include( 'includes/abilities/class-scf-post-type-abilities.php' );
	acf_include( 'includes/abilities/class-scf-taxonomy-abilities.php' );
	acf_include( 'includes/abilities/class-scf-ui-options-page-abilities.php' );
	acf_include( 'includes/abilities/class-scf-field-group-abilities.php' );
	acf_include( 'includes/abilities/class-scf-field-abilities.php' );
}
