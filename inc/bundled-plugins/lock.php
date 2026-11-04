<?php

// While the active theme requires a bundled plugin, WordPress must not change
// it from any screen. Two different promises, kept apart on purpose:
//
//   UPDATE LOCKS    only a theme release changes the plugin's version: no
//                   update notice, no automatic, one-click or bulk update, no
//                   uploaded zip over it. These hold WHILE THE LICENCE IS VALID.
//                   When Warden says the licence is not valid, or the site has no
//                   licence key, the theme no longer maintains the site, so the
//                   locks lift and the client or their host decides when to
//                   update (nothing is pushed, auto-updates stay off unless
//                   switched on). See devbase_bundled_plugins_licence_lapsed().
//
//   PRESENCE LOCKS  the plugin stays installed and active: no Deactivate, no
//                   Delete. These ALWAYS hold while a child requires it, licence
//                   or not, because the shop does not work without it.
//
// Each hook below closes one door; the active_plugins filter is the backstop
// behind the presence locks. Nothing here applies to a plugin the theme does not
// require, and nothing here stops WP-CLI (a developer on the server can do
// anything).

/**
 * Whether the licence no longer covers this site: there is no licence key at
 * all, or Warden's last SIGNED answer for the base theme says it is not valid.
 *
 * Only a signed answer counts. A Warden outage, a key that has never been
 * answered, or an unreadable answer leaves the locks as they are: a hiccup must
 * never open a site's plugins up.
 *
 * @return bool
 */
function devbase_bundled_plugins_licence_lapsed() {
	if ( '' === devbase_warden_parent_licence() ) {
		$lapsed = true;
	} else {
		$state    = devbase_warden_get_state( get_template() );
		$response = isset( $state['response'] ) && is_array( $state['response'] ) ? $state['response'] : array();
		$lapsed   = array() !== $response && empty( $response['valid'] );
	}

	return (bool) apply_filters( 'devbase/bundled_plugins/licence_lapsed', $lapsed );
}

/**
 * The plugin files whose UPDATES are locked right now: the required ones, unless
 * the licence has lapsed (or the theme is removing them).
 *
 * @return string[]
 */
function devbase_update_locked_plugin_files() {
	return devbase_bundled_plugins_licence_lapsed() ? array() : devbase_required_plugin_files();
}

/**
 * Whether a plugin file is one the theme keeps installed and active.
 *
 * @param string $file wp-content/plugins/ relative main file.
 */
function devbase_plugin_is_locked( $file ) {
	return is_string( $file ) && in_array( $file, devbase_required_plugin_files(), true );
}

/**
 * Whether a plugin file is one whose updates the theme alone controls.
 *
 * @param string $file wp-content/plugins/ relative main file.
 */
function devbase_plugin_update_is_locked( $file ) {
	return is_string( $file ) && in_array( $file, devbase_update_locked_plugin_files(), true );
}

/** The plugins' folder names (woocommerce) whose updates are locked. */
function devbase_locked_plugin_folders() {
	return array_map( 'dirname', devbase_update_locked_plugin_files() );
}

/** The message a blocked update shows. */
function devbase_plugin_locked_message() {
	return __( 'This plugin is managed by the theme. It is updated only when the theme is updated, and stays active while the theme needs it.', 'devbase_parent_theme' );
}

/** The message a blocked deactivate or delete shows. */
function devbase_plugin_presence_message() {
	return __( 'This plugin is part of this site\'s theme and must stay installed and active.', 'devbase_parent_theme' );
}

// ---------------------------------------------------------------------------
// UPDATE LOCKS (lifted when the licence has lapsed)
// ---------------------------------------------------------------------------

// No update notice, no one-click update, no bulk update: WordPress never
// learns that a newer version exists. Filtered on read AND before storing.
$devbase_strip_locked_updates = function ( $value ) {
	if ( ! is_object( $value ) ) {
		return $value;
	}

	foreach ( devbase_update_locked_plugin_files() as $file ) {
		unset( $value->response[ $file ], $value->no_update[ $file ], $value->checked[ $file ] );
	}

	return $value;
};
add_filter( 'site_transient_update_plugins', $devbase_strip_locked_updates, 99 );
add_filter( 'pre_set_site_transient_update_plugins', $devbase_strip_locked_updates, 99 );
unset( $devbase_strip_locked_updates );

// Never auto-updated, whatever the site, the host or a plugin says.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return ( is_object( $item ) && ! empty( $item->plugin ) && devbase_plugin_update_is_locked( $item->plugin ) ) ? false : $update;
}, 99, 2 );

// The Plugins list's auto-update column says why there is no toggle.
add_filter( 'plugin_auto_update_setting_html', function ( $html, $plugin_file ) {
	return devbase_plugin_update_is_locked( $plugin_file ) ? esc_html__( 'Updated with the theme', 'devbase_parent_theme' ) : $html;
}, 99, 2 );

// Whatever route reaches the upgrader: refuse to overwrite the plugin.
add_filter( 'upgrader_pre_install', function ( $response, $hook_extra ) {
	if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && devbase_plugin_update_is_locked( $hook_extra['plugin'] ) ) {
		return new WP_Error( 'devbase_plugin_locked', devbase_plugin_locked_message() );
	}

	return $response;
}, 10, 2 );

// ...including an uploaded or downloaded package with the same folder name,
// which would otherwise be offered "replace current with uploaded".
add_filter( 'upgrader_source_selection', function ( $source ) {
	if ( is_string( $source ) && in_array( basename( untrailingslashit( $source ) ), devbase_locked_plugin_folders(), true ) ) {
		return new WP_Error( 'devbase_plugin_locked', devbase_plugin_locked_message() );
	}

	return $source;
}, 10, 1 );

// The same update over AJAX (the Plugins list's inline Update).
add_action( 'wp_ajax_update-plugin', function () {
	$file = isset( $_POST['plugin'] ) ? wp_unslash( $_POST['plugin'] ) : '';

	if ( devbase_plugin_update_is_locked( $file ) ) {
		wp_send_json_error( array( 'errorMessage' => devbase_plugin_locked_message() ) );
	}
}, 0 );

// ---------------------------------------------------------------------------
// PRESENCE LOCKS (always, while a child requires the plugin)
// ---------------------------------------------------------------------------

// No Deactivate / Delete links.
$devbase_locked_action_links = function ( $actions, $plugin_file ) {
	if ( ! devbase_plugin_is_locked( $plugin_file ) ) {
		return $actions;
	}

	unset( $actions['deactivate'], $actions['delete'], $actions['network_deactivate'] );

	return $actions;
};
add_filter( 'plugin_action_links', $devbase_locked_action_links, 99, 2 );
add_filter( 'network_admin_plugin_action_links', $devbase_locked_action_links, 99, 2 );
unset( $devbase_locked_action_links );

// A note on the row: managed by the theme while the licence is valid; "yours to
// update" once it has lapsed.
add_filter( 'plugin_row_meta', function ( $meta, $plugin_file ) {
	if ( devbase_plugin_update_is_locked( $plugin_file ) ) {
		$meta[] = esc_html( devbase_plugin_locked_message() );
	} elseif ( devbase_plugin_is_locked( $plugin_file ) ) {
		$meta[] = esc_html__( 'Updates are no longer provided by the theme (no active licence). You manage this plugin\'s updates.', 'devbase_parent_theme' );
	}

	return $meta;
}, 99, 2 );

// Capability checks core makes per plugin.
add_filter( 'map_meta_cap', function ( $caps, $cap, $user_id, $args ) {
	if ( empty( $args[0] ) ) {
		return $caps;
	}

	if ( in_array( $cap, array( 'deactivate_plugin', 'delete_plugin' ), true ) && devbase_plugin_is_locked( $args[0] ) ) {
		return array( 'do_not_allow' );
	}

	if ( 'update_plugin' === $cap && devbase_plugin_update_is_locked( $args[0] ) ) {
		return array( 'do_not_allow' );
	}

	return $caps;
}, 99, 4 );

// The Plugins screen: single and bulk deactivate, delete and update.
add_action( 'load-plugins.php', function () {
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( '-1' === $action || '' === $action ) {
		$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
	}

	if ( in_array( $action, array( 'deactivate', 'deactivate-selected', 'delete-selected' ), true ) ) {
		$blocked = 'devbase_plugin_is_locked';
		$message = devbase_plugin_presence_message();
	} elseif ( 'update-selected' === $action ) {
		$blocked = 'devbase_plugin_update_is_locked';
		$message = devbase_plugin_locked_message();
	} else {
		return;
	}

	$files = isset( $_REQUEST['checked'] ) ? (array) wp_unslash( $_REQUEST['checked'] ) : array();
	if ( isset( $_REQUEST['plugin'] ) ) {
		$files[] = wp_unslash( $_REQUEST['plugin'] );
	}

	foreach ( $files as $file ) {
		if ( $blocked( $file ) ) {
			wp_die( esc_html( $message ), '', array( 'response' => 403, 'back_link' => true ) );
		}
	}
} );

// Delete over AJAX (the Plugins list's inline Delete).
add_action( 'wp_ajax_delete-plugin', function () {
	$file = isset( $_POST['plugin'] ) ? wp_unslash( $_POST['plugin'] ) : '';

	if ( devbase_plugin_is_locked( $file ) ) {
		wp_send_json_error( array( 'errorMessage' => devbase_plugin_presence_message() ) );
	}
}, 0 );

// The backstop: however it was attempted, the option that lists active
// plugins keeps the required ones in it.
add_filter( 'pre_update_option_active_plugins', function ( $active ) {
	if ( ! is_array( $active ) ) {
		return $active;
	}

	$missing = array_diff( devbase_required_plugin_files(), $active );

	return $missing ? array_values( array_merge( $active, $missing ) ) : $active;
}, 99 );

// ---------------------------------------------------------------------------
// The lapse notice
// ---------------------------------------------------------------------------

// Tells an administrator, where they would look for it (the Plugins screen and
// the Dashboard), that the theme has stopped looking after the bundled plugin.
// Said once, plainly, so the choice to update is theirs and knowingly made.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'update_plugins' ) || ! devbase_bundled_plugins_licence_lapsed() ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
		return;
	}

	$plugins = devbase_bundled_plugins();
	$names   = array();

	foreach ( devbase_required_plugins() as $slug ) {
		$names[] = $plugins[ $slug ]['name'];
	}

	if ( ! $names ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Theme updates are paused: this site has no active licence.', 'devbase_parent_theme' ),
		esc_html(
			sprintf(
				/* translators: %s: plugin names, e.g. WooCommerce. */
				__( '%s stays at the version this theme shipped and will not be updated by the theme. You can update it from the Plugins screen when you choose, which also means taking any security fix yourself.', 'devbase_parent_theme' ),
				implode( ', ', $names )
			)
		)
	);
} );
