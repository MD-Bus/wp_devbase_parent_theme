<?php

// What the parent needs to know about WPvivid Backup without loading it.

/**
 * Whether WPvivid is in the middle of a backup or a restore. Read from its own saved state, so it
 * works while the plugin is not loaded: a task in its task list that is `running` (or no longer
 * answering), or the restore's progress file, which exists from the start of a restore until it is
 * cleaned up. While this is true the theme neither deactivates nor deletes the plugin.
 */
function devbase_wpvivid_is_busy() {
	// Our own backup or download job counts too: it uses WPvivid's folder and its delete function.
	if ( devbase_backup_job_active() ) {
		return true;
	}

	foreach ( (array) get_option( 'wpvivid_task_list', array() ) as $task ) {
		$status = is_array( $task ) && isset( $task['status']['str'] ) ? (string) $task['status']['str'] : '';

		if ( in_array( $status, array( 'running', 'no_responds', 'wait_resume' ), true ) ) {
			return true;
		}
	}

	$local = (array) get_option( 'wpvivid_local_setting', array() );
	$dir   = ! empty( $local['path'] ) ? (string) $local['path'] : 'wpvividbackups';

	return file_exists( WP_CONTENT_DIR . '/' . trim( $dir, '/\\' ) . '/wpvivid_restoredata' );
}

// --- hiding WPvivid's own screens ------------------------------------------------------------------
//
// The theme makes and moves backups; WPvivid's interface is only needed to press Restore. So when
// "Hide WPvivid Backup" is on (Theme Settings > Miscellaneous > Management, a switch of its own beside
// "Lock remote setup"), WPvivid disappears from the menu, the admin bar, the Plugins list and the
// notices it puts on every admin screen. Hidden is not blocked: its page still opens by its address
// (admin.php?page=WPvivid), which the Backups tab offers to whoever has the switch off. It is ON by default. Nothing here
// changes what WPvivid does.

/**
 * Whether WPvivid's screens are hidden: the switch is on AND the site's plan includes backups, which is when
 * the theme is the one using WPvivid. Without that it is nobody's business but the site's own: a WPvivid a
 * client installed and uses keeps its menu. (A filter can decide it in code.)
 */
function devbase_wpvivid_ui_hidden() {
	return (bool) apply_filters( 'devbase/wpvivid/hide_ui', devbase_switch_on( 'hide_wpvivid_ui' ) && devbase_relay_service_in_force( 'backup' ) );
}

/** WPvivid's top-level menu, and every other WPvivid page (its Snapshots page has a menu of its own). */
add_action( 'admin_menu', function () {
	if ( ! devbase_wpvivid_ui_hidden() ) {
		return;
	}

	global $menu;

	foreach ( (array) $menu as $item ) {
		if ( isset( $item[2] ) && 0 === stripos( (string) $item[2], 'wpvivid' ) ) {
			remove_menu_page( $item[2] );
		}
	}
}, PHP_INT_MAX );

/** Its admin bar entry and everything under it. */
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! devbase_wpvivid_ui_hidden() ) {
		return;
	}

	foreach ( (array) $bar->get_nodes() as $id => $node ) {
		if ( 0 === strpos( (string) $id, 'wpvivid' ) ) {
			$bar->remove_node( $id );
		}
	}
}, PHP_INT_MAX );

/** The links under its name on the Plugins screen, and its row in the list. */
add_filter( 'plugin_action_links_wpvivid-backuprestore/wpvivid-backuprestore.php', function ( $links ) {
	return devbase_wpvivid_ui_hidden() ? array() : $links;
}, PHP_INT_MAX );

add_filter( 'all_plugins', function ( $plugins ) {
	if ( devbase_wpvivid_ui_hidden() ) {
		unset( $plugins['wpvivid-backuprestore/wpvivid-backuprestore.php'] );
	}

	return $plugins;
} );

/**
 * Whether an admin notice callback belongs to WPvivid: a method of one of its classes, or a closure
 * written in its folder.
 */
function devbase_wpvivid_owns_callback( $callback ) {
	if ( is_array( $callback ) ) {
		$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

		return 0 === stripos( $owner, 'WPvivid' );
	}

	if ( $callback instanceof Closure ) {
		$file = ( new ReflectionFunction( $callback ) )->getFileName();

		return false !== $file && false !== strpos( str_replace( '\\', '/', $file ), '/wpvivid-backuprestore/' );
	}

	return is_string( $callback ) && 0 === stripos( $callback, 'wpvivid' );
}

/** The notices WPvivid puts on every admin screen (a missing rule, a Pro edition to update...). */
function devbase_wpvivid_remove_notices() {
	global $wp_filter;

	foreach ( array( 'admin_notices', 'network_admin_notices', 'all_admin_notices' ) as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( devbase_wpvivid_owns_callback( $entry['function'] ) ) {
					remove_action( $hook, $entry['function'], $priority );
				}
			}
		}
	}
}
add_action( 'in_admin_header', function () {
	if ( devbase_wpvivid_ui_hidden() && ( ! isset( $_GET['page'] ) || 0 !== stripos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'wpvivid' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		devbase_wpvivid_remove_notices();
	}
}, 1 );

/**
 * WPvivid sends the admin to its own screen on the next page after it is activated. When the theme
 * activates it, that would throw the person who happened to load a page somewhere unexpected.
 */
add_action( 'devbase/bundled_plugin_activated', function ( $slug ) {
	if ( 'wpvivid-backuprestore' === $slug ) {
		delete_option( 'wpvivid_do_activation_redirect' );
	}
} );

// --- locking WPvivid down to what the theme's flow uses -------------------------------------------------
//
// WPvivid is large and does far more than the theme needs: cloud storage, site-to-site migration, staging, database
// snapshots, export/import, an unused-image cleaner, schedules, e-mail reports. Each of those registers admin-ajax actions,
// and a few register start-up handlers. The theme makes the backup, sends it to Brand Relay, brings one back, and the
// client presses Restore: so while the theme is the one using WPvivid, every WPvivid action NOT on the allowlist below is
// unhooked. Nothing of WPvivid is edited: its hooks are ordinary WordPress hooks, and they are removed after it has added them.
//
// What this does NOT do: the files stay on disk and load, and WPvivid's own `plugins_loaded` handler for receiving a site-to-site
// transfer (it needs a transfer key, and checks it before reading anything) is registered before a theme can run, so it
// stays. See inc/bundled-plugins/AGENT.md.

/**
 * Whether WPvivid is locked down: only while the site's plan includes backups, the same condition as hiding its screens
 * (a WPvivid a client installed and uses for themselves is not touched). A filter can decide it in code.
 */
function devbase_wpvivid_locked_down() {
	return (bool) apply_filters( 'devbase/wpvivid/lockdown', devbase_relay_service_in_force( 'backup' ) );
}

/**
 * The admin-ajax actions of WPvivid that stay (names without the `wp_ajax_` / `wp_ajax_nopriv_` prefix): watching and cancelling a
 * backup (STARTING one is not here: only Brand Relay's ping starts a backup, and the theme's own job calls WPvivid's methods directly, not
 * through these actions), the list of backups and deleting one, the whole restore (including the few actions that are `nopriv` only because
 * a restore swaps the database under the page; each still checks a nonce and `manage_options`) and its logs.
 * Everything else is removed, so a WPvivid update that adds an action starts out removed.
 *
 * @return string[]
 */
function devbase_wpvivid_allowed_ajax_actions() {
	return (array) apply_filters( 'devbase/wpvivid/allowed_ajax', array(
		// Backup: watch and cancel a running one.
		'wpvivid_backup_cancel', 'wpvivid_list_tasks', 'wpvivid_delete_task', 'wpvivid_get_last_backup', 'wpvivid_read_last_backup_log',
		'wpvivid_task_monitor', 'wpvivid_list_tasks_2', 'wpvivid_shutdown_backup', 'wpvivid_delete_task_2',
		// The list of backups in WPvivid's folder, deleting one, locking one, rescanning the folder.
		'wpvivid_get_backup_list', 'wpvivid_delete_backup', 'wpvivid_delete_backup_array', 'wpvivid_set_security_lock',
		'wpvivid_rescan_local_folder', 'wpvivid_get_backup_count',
		// Restore, all of it.
		'wpvivid_prepare_restore', 'wpvivid_download_restore', 'wpvivid_init_restore_page', 'wpvivid_delete_last_restore_data',
		'wpvivid_restore', 'wpvivid_get_restore_progress', 'wpvivid_get_download_restore_progress', 'wpvivid_check_download_has_zero_date',
		'wpvivid_get_restore_file_is_migrate', 'wpvivid_get_ini_memory_limit',
		'wpvivid_init_restore_task_2', 'wpvivid_do_restore_2', 'wpvivid_get_restore_progress_2', 'wpvivid_finish_restore_2', 'wpvivid_restore_failed_2',
		// Logs. (No settings: WPvivid's Settings tab is gone; the theme sets what its backups need itself.)
		'wpvivid_get_log_list', 'wpvivid_view_log', 'wpvivid_view_backup_log', 'wpvivid_view_backup_task_log',
	) );
}

/**
 * Unhooks every WPvivid admin-ajax action that is not on the allowlist, and the start-up handlers of the cloud storage
 * sign-ins. Runs on `init` (WPvivid has registered everything by then, and admin-ajax dispatches after `init`).
 *
 * @return string[] The hooks that were unhooked, for the tests.
 */
function devbase_wpvivid_lock_down() {
	global $wp_filter;

	if ( ! devbase_wpvivid_locked_down() ) {
		return array();
	}

	$allowed = array_flip( devbase_wpvivid_allowed_ajax_actions() );
	$removed = array();

	foreach ( array_keys( (array) $wp_filter ) as $hook ) {
		if ( 0 !== strpos( $hook, 'wp_ajax_' ) ) {
			continue;
		}

		$action = (string) preg_replace( '/^wp_ajax_(nopriv_)?/', '', $hook );

		if ( isset( $allowed[ $action ] ) ) {
			continue;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( devbase_wpvivid_owns_callback( $entry['function'] ) ) {
					remove_action( $hook, $entry['function'], $priority );
					$removed[] = $hook;
				}
			}
		}
	}

	return $removed;
}
// A wrapper: the function's return value (what it removed) is for its direct callers, not for the hook.
add_action( 'init', function () {
	devbase_wpvivid_lock_down();
}, PHP_INT_MAX );

/** The cloud storage sign-in handlers WPvivid puts on `init` (Dropbox, Google Drive, OneDrive): nothing here signs in to a cloud. */
add_action( 'init', function () {
	global $wp_filter;

	if ( ! devbase_wpvivid_locked_down() || empty( $wp_filter['init'] ) ) {
		return;
	}

	foreach ( $wp_filter['init']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $entry ) {
			if ( is_array( $entry['function'] ) && 'handle_auth_actions' === $entry['function'][1] && devbase_wpvivid_owns_callback( $entry['function'] ) ) {
				remove_action( 'init', $entry['function'], $priority );
			}
		}
	}
}, 1 );

// --- and what its screens still show ---------------------------------------------------------------------
//
// With the actions gone, a tab for them would only be a dead button. WPvivid builds its tabs, sub-tabs and sub-menus
// through filters, so while it is locked down they are cut to what still works: Backup & Restore (with its list of backups, log
// and restore) and Log. Schedule, Remote Storage, Auto-Migration, Key, Debug, Premium, MainWP, Snapshots, Staging,
// Export/Import and the Image Cleaner are gone from the screen as well as from the server.

/** Keeps only the entries whose key is allowed; leaves the list alone when not locked down. */
function devbase_wpvivid_keep_only( $list, $allowed ) {
	if ( ! devbase_wpvivid_locked_down() || ! is_array( $list ) ) {
		return $list;
	}

	return array_intersect_key( $list, array_flip( $allowed ) );
}

/** Top-level tabs of WPvivid's page. */
add_filter( 'wpvivid_add_tab_page', function ( $tabs ) {
	return devbase_wpvivid_keep_only( $tabs, (array) apply_filters( 'devbase/wpvivid/allowed_tabs', array( 'backup_restore', 'log' ) ) );
}, PHP_INT_MAX );

/** The tabs inside Backup & Restore: backups, log and restore (no upload, no retrieving from remote storage). */
add_filter( 'wpvivid_backuppage_load_backuplist', function ( $tabs ) {
	return devbase_wpvivid_keep_only( $tabs, (array) apply_filters( 'devbase/wpvivid/allowed_backup_tabs', array( 'list_backup', 'list_log', 'list_restore' ) ) );
}, PHP_INT_MAX );

/** The tabs inside Settings (no Staging or Media Cleaner settings) and inside Logs (no Staging logs). */
add_filter( 'wpvivid_add_setting_tab_page', function ( $tabs ) {
	return devbase_wpvivid_keep_only( $tabs, (array) apply_filters( 'devbase/wpvivid/allowed_setting_tabs', array( 'general_setting', 'advance_setting' ) ) );
}, PHP_INT_MAX );

add_filter( 'wpvivid_add_log_tab_page', function ( $tabs ) {
	return devbase_wpvivid_keep_only( $tabs, (array) apply_filters( 'devbase/wpvivid/allowed_log_tabs', array( 'backup_log_page' ) ) );
}, PHP_INT_MAX );

/** Its sub-menu entries, by menu slug: Backup & Restore and Settings. */
add_filter( 'wpvivid_get_admin_menus', function ( $menus ) {
	return devbase_wpvivid_keep_only( $menus, (array) apply_filters( 'devbase/wpvivid/allowed_menus', array( 'WPvivid' ) ) );
}, PHP_INT_MAX );

/** The boxes in WPvivid's sidebar (its version, "like the plugin?", a how-to list, Pro promotion). */
add_action( 'init', function () {
	global $wp_filter;

	if ( ! devbase_wpvivid_locked_down() || empty( $wp_filter['wpvivid_add_side_bar'] ) ) {
		return;
	}

	foreach ( $wp_filter['wpvivid_add_side_bar']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $entry ) {
			if ( devbase_wpvivid_owns_callback( $entry['function'] ) ) {
				remove_action( 'wpvivid_add_side_bar', $entry['function'], $priority );
			}
		}
	}
}, PHP_INT_MAX );

/** The "Back Up Manually" box on WPvivid's page: clients never start a backup, so it is not shown (its actions are unhooked as well). */
add_action( 'admin_head', function () {
	if ( devbase_wpvivid_locked_down() && isset( $_GET['page'] ) && 0 === stripos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'wpvivid' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<style>#wpvivid_postbox_backup{display:none!important}</style>';
	}
} );
