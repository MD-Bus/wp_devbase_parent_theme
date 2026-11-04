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
//   - A plugin that is LOADED in the current request (WooCommerce is, on every
//     wp-admin page while active) never has its files swapped or deleted
//     mid-request: it loads classes lazily and runs its own shutdown hooks
//     (Action Scheduler), so missing or half-new files are a fatal error.
//     The change is queued and done at the very end of shutdown instead.

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

	// A service in force but a conflicting edition installed: say so once.
	foreach ( $plugins as $slug => $def ) {
		if ( devbase_bundled_plugin_is_service_bound( $def ) && ! isset( ( (array) get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS, array() ) )[ $slug ] )
			&& devbase_relay_service_in_force( $def['service'] ) && 'conflict' === ( devbase_bundled_plugin_blocker( $slug, $def )['reason'] ?? '' ) ) {
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

	// A service in force but its plugin cannot be installed (the Pro edition is there).
	foreach ( $plugins as $slug => $def ) {
		if ( ! devbase_bundled_plugin_is_service_bound( $def ) || ! devbase_relay_service_in_force( $def['service'] ) ) {
			continue;
		}

		$blocker = devbase_bundled_plugin_blocker( $slug, $def );
		if ( $blocker && 'conflict' === $blocker['reason'] ) {
			$results[ $slug ] = devbase_bundled_plugin_problem( $slug, new WP_Error( 'conflict', $blocker['message'] ) );
		}
	}

	devbase_bundled_plugins_release_lock();

	return $results;
}

/**
 * Work that must wait until the end of the request, because the plugin it
 * changes is loaded right now. Pass a callable to queue it; pass nothing to
 * take (and clear) the queue.
 *
 * @param callable|null $job
 * @return callable[]
 */
function devbase_bundled_plugins_deferred( $job = null ) {
	static $queue = array();

	if ( null !== $job ) {
		$queue[] = $job;
		return $queue;
	}

	$jobs  = $queue;
	$queue = array();

	return $jobs;
}

/** Whether the plugin's code is running in this request. */
function devbase_bundled_plugin_is_loaded( $slug, $def ) {
	$real   = realpath( WP_PLUGIN_DIR . '/' . $def['file'] );
	$loaded = false !== $real && in_array( $real, get_included_files(), true );

	return (bool) apply_filters( 'devbase/bundled_plugin_is_loaded', $loaded, $slug );
}

/** Frees the sync lock, unless deferred work still needs it. */
function devbase_bundled_plugins_release_lock() {
	if ( ! devbase_bundled_plugins_pending_jobs() ) {
		delete_option( 'devbase_bundled_plugins_lock' );
	}
}

/** Whether deferred work is queued (without taking it). */
function devbase_bundled_plugins_pending_jobs() {
	$jobs = devbase_bundled_plugins_deferred();

	foreach ( $jobs as $job ) {
		devbase_bundled_plugins_deferred( $job );
	}

	return (bool) $jobs;
}

/** Runs the queued file changes. Hooked to the very end of shutdown. */
function devbase_bundled_plugins_run_deferred() {
	foreach ( devbase_bundled_plugins_deferred() as $job ) {
		$job();
	}

	delete_option( 'devbase_bundled_plugins_lock' );
}
add_action( 'shutdown', 'devbase_bundled_plugins_run_deferred', PHP_INT_MAX );

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
		if ( $was_here && devbase_bundled_plugin_is_loaded( $slug, $def ) ) {
			// Running right now: swap its files after everything else has finished.
			devbase_bundled_plugins_deferred( function () use ( $slug, $def, $installed ) {
				$done = devbase_install_and_check_bundled_plugin( $slug, $def, $installed );
				if ( ! is_wp_error( $done ) ) {
					devbase_bundled_plugin_manage( $slug, true );
					devbase_bundled_plugin_problem( $slug );
				}
			} );

			return 'upgrading';
		}

		$done = devbase_install_and_check_bundled_plugin( $slug, $def, $installed );
		if ( is_wp_error( $done ) ) {
			return $done;
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

	// A plugin tied to a plan service that was ALREADY on the site (the client's own copy) is
	// adopted for updates but never added to the managed list: only what we installed may be
	// removed when the service lapses.
	$adopted = devbase_bundled_plugin_is_service_bound( $def ) && $was_here && ! in_array( $slug, (array) get_option( DEVBASE_BUNDLED_PLUGINS_MANAGED, array() ), true );

	if ( ! $adopted ) {
		devbase_bundled_plugin_manage( $slug, true );
	}
	devbase_bundled_plugin_problem( $slug );

	return $result;
}

/**
 * Unpacks the zip, then checks the version really went up. Records the problem
 * when it didn't (or the unpack failed).
 *
 * @param string $before The version on disk before, '' when none.
 * @return true|WP_Error
 */
function devbase_install_and_check_bundled_plugin( $slug, $def, $before ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$copied = devbase_install_bundled_plugin_files( $slug, $def );
	if ( is_wp_error( $copied ) ) {
		return devbase_bundled_plugin_problem( $slug, $copied );
	}

	// The zip must really have raised the version, or this would unpack
	// 17 MB on every request (a release built without bundle-plugins.sh).
	$after = devbase_installed_plugin_version( $slug );
	if ( '' === $after || ( '' !== $before && version_compare( $after, $before, '<=' ) ) ) {
		return devbase_bundled_plugin_problem( $slug, new WP_Error( 'no_change', sprintf(
			/* translators: %s: plugin name. */
			__( 'The bundled %s did not install. The theme release is built wrong; reinstall the theme.', 'devbase_parent_theme' ),
			$def['name']
		) ) );
	}

	return true;
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

	// WordPress remembers the plugin list for the request; activating needs to see the new files.
	wp_clean_plugins_cache( false );
	delete_transient( 'devbase_bundled_elsewhere_' . $slug );

	return true;
}

/**
 * Deactivates a plugin and deletes its folder. Files only: uninstall.php is
 * NOT run (delete_plugins() would), so the shop's data survives.
 *
 * @return string|WP_Error
 */
function devbase_remove_bundled_plugin( $slug, $def ) {
	if ( devbase_bundled_plugin_is_service_bound( $def ) && devbase_bundled_plugin_busy( $slug, $def ) ) {
		return 'busy'; // Never pull a plugin out from under its own running task.
	}

	if ( devbase_bundled_plugin_is_active( $def['file'] ) ) {
		devbase_bundled_plugins_unlocked( true );
		deactivate_plugins( $def['file'] );
		devbase_bundled_plugins_unlocked( false );
	}

	$delete = function () use ( $slug, $def ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$removed = true;
		if ( file_exists( WP_PLUGIN_DIR . '/' . dirname( $def['file'] ) ) ) {
			$removed = false;
			if ( WP_Filesystem() ) {
				global $wp_filesystem;
				$removed = $wp_filesystem->delete( trailingslashit( $wp_filesystem->wp_plugins_dir() ) . dirname( $def['file'] ), true );
			}
		}

		if ( ! $removed ) {
			return devbase_bundled_plugin_problem( $slug, new WP_Error( 'remove', sprintf(
				/* translators: %s: plugin name. */
				__( '%s could not be removed from the plugins folder.', 'devbase_parent_theme' ),
				$def['name']
			) ) );
		}

		devbase_bundled_plugin_manage( $slug, false );
		devbase_bundled_plugin_problem( $slug );

		return true;
	};

	if ( devbase_bundled_plugin_is_loaded( $slug, $def ) ) {
		// Still running in this request (it was active when the page started).
		// Deleting now breaks its lazy class loading and its shutdown hooks.
		devbase_bundled_plugins_deferred( $delete );

		return 'removed';
	}

	$done = $delete();

	return is_wp_error( $done ) ? $done : 'removed';
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

	devbase_sync_bundled_plugins();
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

	devbase_bundled_plugins_release_lock();
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
