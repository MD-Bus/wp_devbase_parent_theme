<?php

// AJAX handler for the "Run Purge Now" button on Theme Settings >
// Miscellaneous > Retention Policy (see settings.php for the button
// itself, purge.js for the click handling).
//
// Runs the SAME devbase_forms_run_retention_purge() the daily cron
// calls (inc/forms/retention.php) — real verification without waiting a
// day, same reasoning as SMTP Settings' "Send Test Email" button. Uses
// whatever's currently SAVED (not unsaved in-progress form values —
// save first, then run).
add_action( 'wp_ajax_devbase_retention_purge', function () {
	check_ajax_referer( 'devbase_retention_purge' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'devbase_parent_theme' ) ) );
	}

	if ( ! get_field( 'retention_enabled', 'option' ) ) {
		wp_send_json_error( array( 'message' => __( 'Retention Policy is disabled — enable and save it first.', 'devbase_parent_theme' ) ) );
	}

	$deleted = devbase_forms_run_retention_purge();

	wp_send_json_success( array(
		/* translators: %d: number of submissions deleted */
		'message' => sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $deleted, 'devbase_parent_theme' ), $deleted ),
	) );
} );
