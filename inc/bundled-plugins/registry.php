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
 * Optional, for a plugin tied to a Brand Relay plan service:
 * `service`     the plan service that requires it (it is required only while
 *               devbase_relay_service_in_force() says so).
 * `text_domain` how to recognise the same plugin installed under another folder.
 * `conflicts`   plugin files that make installing ours unsafe (a Pro edition).
 * `busy`        callable( $slug ): true while the plugin is mid-task and must
 *               not be deactivated or deleted.
 *
 * @return array<string, array<string, string>>
 */
function devbase_bundled_plugins() {
	$dir = get_template_directory() . '/bundled-plugins/';

	return apply_filters( 'devbase/bundled_plugins', array(
		'woocommerce' => array(
			'name'        => 'WooCommerce',
			'file'        => 'woocommerce/woocommerce.php',
			'zip'         => $dir . 'woocommerce.zip',
			'text_domain' => 'woocommerce',
		),
		// Required by the PARENT, not by a child: only while the site's Brand Relay
		// plan has the `backup` service in force (see devbase_required_plugins()).
		// When the service goes off it is deactivated and its files are deleted at
		// once (a buffer after expiry is Brand Relay's to give, by keeping the service
		// in force); never while one of its tasks is running.
		'wpvivid-backuprestore' => array(
			'name'        => 'WPvivid Backup',
			'file'        => 'wpvivid-backuprestore/wpvivid-backuprestore.php',
			'zip'         => $dir . 'wpvivid-backuprestore.zip',
			'service'     => 'backup',
			'text_domain' => 'wpvivid-backuprestore',
			'conflicts'   => array( 'wpvivid-backup-pro/wpvivid-backup-pro.php' ),
			'busy'        => 'devbase_wpvivid_is_busy',
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

	// Plugins tied to a plan service are required by the parent itself, while that service is in force.
	foreach ( $known as $slug => $def ) {
		if ( ! empty( $def['service'] ) && devbase_relay_service_in_force( $def['service'] ) ) {
			$slugs[] = $slug;
		}
	}

	// Whatever is required, a plugin the site already has under another folder name (or whose Pro edition is
	// there) is never installed a second time: two copies would redeclare the same classes.
	$slugs = array_filter( $slugs, function ( $slug ) use ( $known ) {
		return ! isset( $known[ $slug ] ) || null === devbase_bundled_plugin_blocker( $slug, $known[ $slug ] );
	} );

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
		if ( 'wp-plugin/' . $slug === ( $package['name'] ?? '' ) ) {
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


/**
 * Whether a plugin is tied to a plan service (so it is the parent's to install and lapse),
 * rather than required by a child theme.
 */
function devbase_bundled_plugin_is_service_bound( $def ) {
	return ! empty( $def['service'] );
}

/**
 * Why a service-bound plugin must NOT be installed by us, or null when it can be.
 *
 *  - `conflict`: a file in `conflicts` exists (the Pro edition: two editions of
 *    the same plugin define the same classes). We skip and say so.
 *  - `elsewhere`: the same plugin is already installed under another folder name
 *    (found by its text domain). A second copy would fatal on redeclared classes,
 *    so the existing one is used and left alone. Not an error.
 *
 * Cheap on the usual paths: our own copy being present short-circuits the scan of
 * every installed plugin's header.
 *
 * @return array{reason: string, message: string}|null
 */
function devbase_bundled_plugin_blocker( $slug, $def ) {
	foreach ( (array) ( $def['conflicts'] ?? array() ) as $file ) {
		if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			return array(
				'reason'  => 'conflict',
				'message' => sprintf(
					/* translators: 1: plugin name, 2: the conflicting plugin file. */
					__( '%1$s was not installed because %2$s is installed. Remove one of the two editions.', 'devbase_parent_theme' ),
					$def['name'],
					$file
				),
			);
		}
	}

	if ( empty( $def['text_domain'] ) || file_exists( WP_PLUGIN_DIR . '/' . $def['file'] ) ) {
		return null;
	}

	// Scanning every installed plugin's header is slow, and this runs on front-end requests too
	// (until our copy is on disk), so the answer is kept for ten minutes.
	$cache = get_transient( 'devbase_bundled_elsewhere_' . $slug );
	if ( false === $cache ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$cache = 'no';
		foreach ( get_plugins() as $file => $data ) {
			if ( $file !== $def['file'] && ( $data['TextDomain'] ?? '' ) === $def['text_domain'] ) {
				$cache = 'yes';
				break;
			}
		}

		set_transient( 'devbase_bundled_elsewhere_' . $slug, $cache, 10 * MINUTE_IN_SECONDS );
	}

	return 'yes' === $cache ? array( 'reason' => 'elsewhere', 'message' => '' ) : null;
}

/** Whether a service-bound plugin is mid-task right now (a backup or restore running). */
function devbase_bundled_plugin_busy( $slug, $def ) {
	$busy = ! empty( $def['busy'] ) && is_callable( $def['busy'] ) && (bool) call_user_func( $def['busy'], $slug );

	return (bool) apply_filters( 'devbase/bundled_plugin_busy', $busy, $slug );
}
