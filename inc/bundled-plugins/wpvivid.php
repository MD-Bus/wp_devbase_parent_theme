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

		if ( in_array( $status, array( 'running', 'no_responds' ), true ) ) {
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
// (admin.php?page=WPvivid), which the Backups page offers to whoever has the switch off. It is ON by default. Nothing here
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
