<?php

// Brings wp-content/plugins/ in line with what the active theme requires:
// installs a required plugin from the bundled zip, upgrades it when this theme
// release ships a newer one, activates it, and removes it again once nothing
// requires it. The ONLY thing that ever changes the plugin's files.
//
// Rules (see inc/bundled-plugins/AGENT.md for the reasoning):
//   - Never downgrades. A release that ships an older zip than the site has
//     leaves the site alone; the fix for a bad release is a newer release.
//   - Runs on a wp-admin GET request, WP-CLI, or theme activation. Never on
//     the front end, never on AJAX or cron, never on a POST.
//   - Removal deletes the plugin's FILES only. Its uninstall.php is not run,
//     so orders, products and settings stay in the database.

/** Option listing the slugs this theme installed (and so may remove). */
const DEVBASE_BUNDLED_PLUGINS_MANAGED = 'devbase_bundled_plugins_managed';

/** Option holding the last problem per slug, shown as an admin notice. */
const DEVBASE_BUNDLED_PLUGINS_ERRORS = 'devbase_bundled_plugins_errors';

/**
 * Whether anything needs doing. Cheap: option reads and one file header, so
 * it is safe on every wp-admin load.
 */
function devbase_bundled_plugins_pending() {
	$plugins  = devbase_bundled_plugins();
	$required = devbase_required_plugins();

	foreach ( $required as $slug ) {
		if ( get_transient( 'devbase_bundled_plugin_failed_' . $slug ) ) {
			continue; // Tried recently and failed; don't retry on every page.
		}

		$installed = devbase_installed_plugin_version( $slug );
		$bundled   = devbase_bundled_plugin_version( $slug );

		if ( '' === $installed
			|| ( '' !== $bundled && version_compare( $installed, $bundled, '<' ) )
			|| ! devbase_bundled_plugin_is_active( $plugins[ $slug ]['file'] ) ) {
			return true;
		}
	}

	foreach ( array_diff( (array) get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array() ), $required ) as $slug ) {
		if ( isset( $plugins[ $slug ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Does the work. Safe to call directly (`wp eval 'devbase_sync_bundled_plugins();'`).
 *
 * @return array<string, string|WP_Error> slug => ok | installed | upgraded | activated | removed, or the error.
 */
function devbase_sync_bundled_plugins() {
	$plugins  = devbase_bundled_plugins();
	$required = devbase_required_plugins();
	$results  = array();

	// One sync at a time: two admins loading wp-admin together must not unpack
	// the same zip over each other.
	if ( ! devbase_bundled_plugins_take_lock() ) {
		return $results;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';

	foreach ( $required as $slug ) {
		$results[ $slug ] = devbase_ensure_bundled_plugin( $slug, $plugins[ $slug ] );
	}

	foreach ( array_diff( (array) get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array() ), $required ) as $slug ) {
		if ( isset( $plugins[ $slug ] ) ) {
			$results[ $slug ] = devbase_remove_bundled_plugin( $slug, $plugins[ $slug ] );
		}
	}

	delete_option( 'devbase_bundled_plugins_lock' );

	return $results;
}

/** A short-lived lock; a crashed run frees itself after five minutes. */
function devbase_bundled_plugins_take_lock() {
	if ( add_option( 'devbase_bundled_plugins_lock', time(), '', false ) ) {
		return true;
	}

	if ( (int) get_option( 'devbase_bundled_plugins_lock' ) < time() - 5 * MINUTE_IN_SECONDS ) {
		update_option( 'devbase_bundled_plugins_lock', time(), false );
		return true;
	}

	return false;
}

/** Records or clears a slug's problem and returns the error for chaining. */
function devbase_bundled_plugin_problem( $slug, $error = null ) {
	$errors = (array) get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS, array() );

	if ( null === $error ) {
		unset( $errors[ $slug ] );
		delete_transient( 'devbase_bundled_plugin_failed_' . $slug );
	} else {
		$errors[ $slug ] = $error->get_error_message();
		set_transient( 'devbase_bundled_plugin_failed_' . $slug, 1, 15 * MINUTE_IN_SECONDS );
	}

	if ( $errors ) {
		update_option( DEVBASE_BUNDLED_PLUGINS_ERRORS, $errors, false );
	} else {
		delete_option( DEVBASE_BUNDLED_PLUGINS_ERRORS );
	}

	return $error;
}

/** Adds or removes a slug in the list of plugins this theme installed. */
function devbase_bundled_plugin_manage( $slug, $managed ) {
	$list = (array) get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array() );
	$new  = $managed ? array_values( array_unique( array_merge( $list, array( $slug ) ) ) ) : array_values( array_diff( $list, array( $slug ) ) );

	if ( $new !== $list ) {
		update_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, $new, false );
	}
}

/**
 * Installs or upgrades one plugin's files if the bundled zip is newer, then
 * activates it.
 *
 * @return string|WP_Error
 */
function devbase_ensure_bundled_plugin( $slug, $def ) {
	$installed = devbase_installed_plugin_version( $slug );
	$bundled   = devbase_bundled_plugin_version( $slug );
	$was_here  = '' !== $installed;
	$result    = 'ok';

	if ( ! $was_here || ( '' !== $bundled && version_compare( $installed, $bundled, '<' ) ) ) {
		$copied = devbase_install_bundled_plugin_files( $slug, $def );
		if ( is_wp_error( $copied ) ) {
			return devbase_bundled_plugin_problem( $slug, $copied );
		}

		// The zip must really have raised the version, or this would unpack
		// 17 MB on every request (a release built without bundle-plugins.sh).
		$after = devbase_installed_plugin_version( $slug );
		if ( '' === $after || ( '' !== $installed && version_compare( $after, $installed, '<=' ) ) ) {
			return devbase_bundled_plugin_problem( $slug, new WP_Error( 'no_change', sprintf(
				/* translators: %s: plugin name. */
				__( 'The bundled %s did not install. The theme release is built wrong; reinstall the theme.', 'devbase_parent_theme' ),
				$def['name']
			) ) );
		}

		$result = $was_here ? 'upgraded' : 'installed';
	}

	if ( ! devbase_bundled_plugin_is_active( $def['file'] ) ) {
		// NOT silent: WooCommerce creates its tables and pages in its activation hook.
		$activated = activate_plugin( $def['file'] );
		if ( is_wp_error( $activated ) ) {
			return devbase_bundled_plugin_problem( $slug, $activated );
		}

		$result = 'ok' === $result ? 'activated' : $result;
	}

	devbase_bundled_plugin_manage( $slug, true );
	devbase_bundled_plugin_problem( $slug );

	return $result;
}

/**
 * Unpacks the bundled zip over the plugin's folder. Unpacks beside the real
 * folder first and swaps, so a failed unpack leaves the working copy alone.
 *
 * @return true|WP_Error
 */
function devbase_install_bundled_plugin_files( $slug, $def ) {
	if ( ! is_readable( $def['zip'] ) ) {
		return new WP_Error( 'missing_zip', sprintf(
			/* translators: %s: plugin name. */
			__( 'The bundled %s file is missing from the theme.', 'devbase_parent_theme' ),
			$def['name']
		) );
	}

	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'filesystem', sprintf(
			/* translators: %s: plugin name. */
			__( 'WordPress cannot write to the plugins folder, so %s was not installed. The host must allow direct file access.', 'devbase_parent_theme' ),
			$def['name']
		) );
	}

	global $wp_filesystem;

	$folder  = dirname( $def['file'] );
	$upgrade = trailingslashit( $wp_filesystem->wp_content_dir() ) . 'upgrade/';
	$work    = $upgrade . 'devbase-' . $slug . '-' . wp_generate_password( 8, false ) . '/';
	$live    = trailingslashit( $wp_filesystem->wp_plugins_dir() ) . $folder;

	if ( ! $wp_filesystem->is_dir( $upgrade ) ) {
		$wp_filesystem->mkdir( $upgrade );
	}

	$unzipped = unzip_file( $def['zip'], $work );
	if ( is_wp_error( $unzipped ) ) {
		$wp_filesystem->delete( $work, true );
		return $unzipped;
	}

	if ( ! $wp_filesystem->is_dir( $work . $folder ) ) {
		$wp_filesystem->delete( $work, true );
		return new WP_Error( 'bad_zip', sprintf(
			/* translators: %s: plugin name. */
			__( 'The bundled %s file is not a valid plugin package.', 'devbase_parent_theme' ),
			$def['name']
		) );
	}

	$backup = $work . 'previous';
	if ( $wp_filesystem->exists( $live ) && ! $wp_filesystem->move( $live, $backup, true ) ) {
		$wp_filesystem->delete( $work, true );
		return new WP_Error( 'swap', sprintf(
			/* translators: %s: plugin name. */
			__( 'The existing %s could not be replaced.', 'devbase_parent_theme' ),
			$def['name']
		) );
	}

	if ( ! $wp_filesystem->move( $work . $folder, $live, true ) ) {
		if ( $wp_filesystem->exists( $backup ) ) {
			$wp_filesystem->move( $backup, $live, true );
		}
		$wp_filesystem->delete( $work, true );
		return new WP_Error( 'swap', sprintf(
			/* translators: %s: plugin name. */
			__( '%s could not be installed. The previous copy was restored.', 'devbase_parent_theme' ),
			$def['name']
		) );
	}

	$wp_filesystem->delete( $work, true );

	if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
		wp_opcache_invalidate_directory( WP_PLUGIN_DIR . '/' . $folder );
	}

	return true;
}

/**
 * Deactivates a plugin and deletes its folder. Files only: uninstall.php is
 * NOT run (delete_plugins() would), so the shop's data survives.
 *
 * @return string|WP_Error
 */
function devbase_remove_bundled_plugin( $slug, $def ) {
	devbase_bundled_plugins_unlocked( true );

	if ( devbase_bundled_plugin_is_active( $def['file'] ) ) {
		deactivate_plugins( $def['file'] );
	}

	$removed = true;
	if ( file_exists( WP_PLUGIN_DIR . '/' . dirname( $def['file'] ) ) ) {
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$removed = $wp_filesystem->delete( trailingslashit( $wp_filesystem->wp_plugins_dir() ) . dirname( $def['file'] ), true );
		} else {
			$removed = false;
		}
	}

	devbase_bundled_plugins_unlocked( false );

	if ( ! $removed ) {
		return devbase_bundled_plugin_problem( $slug, new WP_Error( 'remove', sprintf(
			/* translators: %s: plugin name. */
			__( '%s could not be removed from the plugins folder.', 'devbase_parent_theme' ),
			$def['name']
		) ) );
	}

	devbase_bundled_plugin_manage( $slug, false );
	devbase_bundled_plugin_problem( $slug );

	return 'removed';
}

// wp-admin GET requests and WP-CLI: the moment a theme release's newer zip
// gets applied, and the self-heal if the plugin was deleted or deactivated by
// hand. Not AJAX, cron or POST, so no save is ever interrupted.
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || wp_doing_cron() || wp_installing() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	if ( ! devbase_bundled_plugins_pending() ) {
		return;
	}

	$was_active = array();
	foreach ( devbase_required_plugins() as $slug ) {
		$was_active[ $slug ] = devbase_bundled_plugin_is_active( devbase_bundled_plugins()[ $slug ]['file'] );
	}

	$results = devbase_sync_bundled_plugins();

	// An active plugin's old files were already loaded for this request, and
	// its new ones are on disk now. Start over on a clean request.
	foreach ( $results as $slug => $result ) {
		if ( 'upgraded' === $result && ! empty( $was_active[ $slug ] ) && ! headers_sent() && isset( $_SERVER['REQUEST_URI'] ) ) {
			wp_safe_redirect( set_url_scheme( 'http://' . wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) . wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			exit;
		}
	}
} );

// Right after this theme is activated: install what it requires, and remove
// what the theme it replaced had installed.
add_action( 'after_switch_theme', function () {
	if ( devbase_bundled_plugins_pending() ) {
		devbase_sync_bundled_plugins();
	}
} );

// Switching to a theme that isn't built on this parent: nothing will ever
// clean up after it, so do it now, for every plugin this theme installed.
// Switching to another child of this parent is left to that child's own
// after_switch_theme run, which knows what it requires.
add_action( 'switch_theme', function ( $new_name, $new_theme ) {
	if ( $new_theme instanceof WP_Theme && basename( dirname( __DIR__, 2 ) ) === $new_theme->get_template() ) {
		return;
	}

	$plugins = devbase_bundled_plugins();
	$managed = (array) get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array() );

	if ( ! $managed || ! devbase_bundled_plugins_take_lock() ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';

	foreach ( $managed as $slug ) {
		if ( isset( $plugins[ $slug ] ) ) {
			devbase_remove_bundled_plugin( $slug, $plugins[ $slug ] );
		}
	}

	delete_option( 'devbase_bundled_plugins_lock' );
}, 10, 2 );

// Says so when a required plugin could not be installed. A shop without
// WooCommerce is broken, so this stays until it is fixed.
add_action( 'admin_notices', function () {
	$errors = (array) get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS, array() );

	if ( ! $errors || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	foreach ( $errors as $message ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
} );
