<?php

// Plugins that ship INSIDE the theme as a zip and are installed into
// wp-content/plugins/ for sites that need them. Unlike SCF and ASE (vendor/,
// loaded with require_once) these are real, activated plugins: WooCommerce is
// too big and too tied to WordPress's plugin lifecycle to be loaded from a
// theme. See inc/bundled-plugins/AGENT.md.
//
// This file only answers "which plugins can be bundled" and "which ones does
// the active theme require". The parent never requires any itself: a child
// theme opts in through the devbase/required_plugins filter, so a site that
// needs no shop never gets one.

/**
 * The plugins this theme can install: slug => definition.
 *
 * `file`    the plugin's main file, relative to wp-content/plugins/.
 * `zip`     the committed zip (bin/bundle-plugins.sh builds it).
 * `version` optional; the version the zip carries. Read from
 *           bundled-plugins/composer.lock when left out (the drift check in
 *           bin/check-vendor-drift.sh guarantees the two agree).
 *
 * @return array<string, array<string, string>>
 */
function devbase_bundled_plugins() {
	$dir = get_template_directory() . '/bundled-plugins/';

	return apply_filters( 'devbase/bundled_plugins', array(
		'woocommerce' => array(
			'name' => 'WooCommerce',
			'file' => 'woocommerce/woocommerce.php',
			'zip'  => $dir . 'woocommerce.zip',
		),
	) );
}

/**
 * The slugs the active theme requires. Empty unless a child theme says so:
 *
 *     add_filter( 'devbase/required_plugins', function ( $plugins ) {
 *         $plugins[] = 'woocommerce';
 *         return $plugins;
 *     } );
 *
 * Child code runs before the parent's, so the filter must be registered at
 * the top level of the child (a closure is fine); it is only CALLED later.
 *
 * @return string[]
 */
function devbase_required_plugins() {
	$slugs = (array) apply_filters( 'devbase/required_plugins', array() );
	$known = devbase_bundled_plugins();

	return array_values( array_unique( array_filter( $slugs, function ( $slug ) use ( $known ) {
		return is_string( $slug ) && isset( $known[ $slug ] );
	} ) ) );
}

/**
 * The main files (wp-content/plugins/ relative) of the required plugins, or
 * none while the theme is deliberately removing them.
 *
 * @return string[]
 */
function devbase_required_plugin_files() {
	if ( devbase_bundled_plugins_unlocked() ) {
		return array();
	}

	$plugins = devbase_bundled_plugins();
	$files   = array();

	foreach ( devbase_required_plugins() as $slug ) {
		$files[] = $plugins[ $slug ]['file'];
	}

	return $files;
}

/**
 * Lets the theme's own removal code through the locks in lock.php. Pass true
 * before deactivating or deleting a bundled plugin, false straight after.
 *
 * @param bool|null $set New state, or null to just read it.
 * @return bool
 */
function devbase_bundled_plugins_unlocked( $set = null ) {
	static $unlocked = false;

	if ( null !== $set ) {
		$unlocked = (bool) $set;
	}

	return $unlocked;
}

/**
 * The version this theme release ships for a bundled plugin ('' when unknown).
 *
 * @param string $slug A key of devbase_bundled_plugins().
 */
function devbase_bundled_plugin_version( $slug ) {
	$plugins = devbase_bundled_plugins();

	if ( ! isset( $plugins[ $slug ] ) ) {
		return '';
	}
	if ( ! empty( $plugins[ $slug ]['version'] ) ) {
		return (string) $plugins[ $slug ]['version'];
	}

	$lock = get_template_directory() . '/bundled-plugins/composer.lock';
	$data = is_readable( $lock ) ? json_decode( (string) file_get_contents( $lock ), true ) : null;

	foreach ( is_array( $data ) ? ( $data['packages'] ?? array() ) : array() as $package ) {
		if ( 'wpackagist-plugin/' . $slug === ( $package['name'] ?? '' ) ) {
			return ltrim( (string) $package['version'], 'v' );
		}
	}

	return '';
}

/**
 * The version of a bundled plugin that is on disk in wp-content/plugins/
 * ('' when it isn't there). Reads only the file's header.
 *
 * @param string $slug A key of devbase_bundled_plugins().
 */
function devbase_installed_plugin_version( $slug ) {
	$plugins = devbase_bundled_plugins();

	if ( ! isset( $plugins[ $slug ] ) ) {
		return '';
	}

	$file = WP_PLUGIN_DIR . '/' . $plugins[ $slug ]['file'];
	if ( ! is_readable( $file ) ) {
		return '';
	}

	$header = get_file_data( $file, array( 'Version' => 'Version' ) );

	return (string) $header['Version'];
}

/**
 * Whether a plugin is activated (single site or network).
 *
 * @param string $file wp-content/plugins/ relative main file.
 */
function devbase_bundled_plugin_is_active( $file ) {
	if ( in_array( $file, (array) get_option( 'active_plugins', array() ), true ) ) {
		return true;
	}

	return is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[ $file ] );
}
