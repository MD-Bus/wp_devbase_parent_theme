<?php

// What the parent needs to know about WPvivid Backup without loading it.

/**
 * Whether WPvivid is in the middle of a backup or a restore. Read from its own saved state, so it
 * works while the plugin is not loaded: a task in its task list that is `running` (or no longer
 * answering), or the restore's progress file, which exists from the start of a restore until it is
 * cleaned up. While this is true the theme neither deactivates nor deletes the plugin.
 */
function devbase_wpvivid_is_busy() {
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
