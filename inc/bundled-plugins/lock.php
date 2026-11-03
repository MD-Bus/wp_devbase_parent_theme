<?php

// While the active theme requires a bundled plugin, only a theme release may
// change it. WordPress itself must not update, deactivate or delete it, from
// any screen: not the automatic updater, not the Plugins list, not a bulk
// action, not AJAX, not an uploaded zip over the top. Each hook below closes
// one door; the active_plugins filter is the backstop behind all of them.
//
// Nothing here applies to a plugin the theme does not require, and nothing
// here stops WP-CLI (a developer on the server can do anything).

/**
 * Whether a plugin file is one the theme currently locks.
 *
 * @param string $file wp-content/plugins/ relative main file.
 */
function devbase_plugin_is_locked( $file ) {
	return is_string( $file ) && in_array( $file, devbase_required_plugin_files(), true );
}

/** The plugins' folder names (woocommerce) that are locked. */
function devbase_locked_plugin_folders() {
	return array_map( 'dirname', devbase_required_plugin_files() );
}

/** The message every blocked action shows. */
function devbase_plugin_locked_message() {
	return __( 'This plugin is managed by the theme. It is updated only when the theme is updated, and stays active while the theme needs it.', 'devbase_parent_theme' );
}

// No update notice, no one-click update, no bulk update: WordPress never
// learns that a newer version exists. Filtered on read AND before storing.
$devbase_strip_locked_updates = function ( $value ) {
	if ( ! is_object( $value ) ) {
		return $value;
	}

	foreach ( devbase_required_plugin_files() as $file ) {
		unset( $value->response[ $file ], $value->no_update[ $file ], $value->checked[ $file ] );
	}

	return $value;
};
add_filter( 'site_transient_update_plugins', $devbase_strip_locked_updates, 99 );
add_filter( 'pre_set_site_transient_update_plugins', $devbase_strip_locked_updates, 99 );
unset( $devbase_strip_locked_updates );

// Never auto-updated, whatever the site, the host or a plugin says.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return ( is_object( $item ) && ! empty( $item->plugin ) && devbase_plugin_is_locked( $item->plugin ) ) ? false : $update;
}, 99, 2 );

// The Plugins list's auto-update column says why there is no toggle.
add_filter( 'plugin_auto_update_setting_html', function ( $html, $plugin_file ) {
	return devbase_plugin_is_locked( $plugin_file ) ? esc_html__( 'Updated with the theme', 'devbase_parent_theme' ) : $html;
}, 99, 2 );

// No Deactivate / Delete links; a note instead.
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

add_filter( 'plugin_row_meta', function ( $meta, $plugin_file ) {
	if ( devbase_plugin_is_locked( $plugin_file ) ) {
		$meta[] = esc_html( devbase_plugin_locked_message() );
	}

	return $meta;
}, 99, 2 );

// Capability checks core makes per plugin.
add_filter( 'map_meta_cap', function ( $caps, $cap, $user_id, $args ) {
	if ( in_array( $cap, array( 'deactivate_plugin', 'delete_plugin', 'update_plugin' ), true )
		&& ! empty( $args[0] ) && devbase_plugin_is_locked( $args[0] ) ) {
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

	if ( ! in_array( $action, array( 'deactivate', 'deactivate-selected', 'delete-selected', 'update-selected' ), true ) ) {
		return;
	}

	$files = isset( $_REQUEST['checked'] ) ? (array) wp_unslash( $_REQUEST['checked'] ) : array();
	if ( isset( $_REQUEST['plugin'] ) ) {
		$files[] = wp_unslash( $_REQUEST['plugin'] );
	}

	foreach ( $files as $file ) {
		if ( devbase_plugin_is_locked( $file ) ) {
			wp_die( esc_html( devbase_plugin_locked_message() ), '', array( 'response' => 403, 'back_link' => true ) );
		}
	}
} );

// The same actions over AJAX (the Plugins list's inline Update and Delete).
foreach ( array( 'update-plugin', 'delete-plugin' ) as $devbase_ajax_action ) {
	add_action( 'wp_ajax_' . $devbase_ajax_action, function () {
		$file = isset( $_POST['plugin'] ) ? wp_unslash( $_POST['plugin'] ) : '';

		if ( devbase_plugin_is_locked( $file ) ) {
			wp_send_json_error( array( 'errorMessage' => devbase_plugin_locked_message() ) );
		}
	}, 0 );
}
unset( $devbase_ajax_action );

// Whatever route reaches the upgrader: refuse to overwrite the plugin.
add_filter( 'upgrader_pre_install', function ( $response, $hook_extra ) {
	if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && devbase_plugin_is_locked( $hook_extra['plugin'] ) ) {
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

// The backstop: however it was attempted, the option that lists active
// plugins keeps the locked ones in it.
add_filter( 'pre_update_option_active_plugins', function ( $active ) {
	if ( ! is_array( $active ) ) {
		return $active;
	}

	$missing = array_diff( devbase_required_plugin_files(), $active );

	return $missing ? array_values( array_merge( $active, $missing ) ) : $active;
}, 99 );
