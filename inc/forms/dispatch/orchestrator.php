<?php

// Fires automatically whenever a form_data post is created — the piece
// that makes notification "automatic" for a form author: they call
// devbase_form_log_submission(), and this hook takes it from there.
//
// Guarded two ways against double-firing:
// 1. The standard revision/autosave checks.
// 2. A _devbase_dispatched postmeta flag, set immediately before
//    dispatching. This is the important guard — wp_insert_post() can
//    trigger save_post more than once in some paths, and it also
//    correctly prevents re-dispatching if an admin later manually
//    re-saves a Form Data post in wp-admin.
add_action( 'save_post_form_data', function ( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( get_post_meta( $post_id, '_devbase_dispatched', true ) ) {
		return;
	}

	update_post_meta( $post_id, '_devbase_dispatched', true );

	devbase_forms_dispatch( $post_id );
}, 10, 3 );

/**
 * Reads every configured destination (inc/theme-settings/destinations/),
 * finds the ones whose "Applies To" list includes this submission's
 * form_name, and sends it to each.
 *
 * @param int $post_id A form_data post ID.
 */
function devbase_forms_dispatch( $post_id ) {
	$form_name = get_post_meta( $post_id, 'form_name', true );
	$content   = json_decode( (string) get_post_meta( $post_id, 'content', true ), true );

	if ( empty( $form_name ) || ! is_array( $content ) ) {
		return;
	}

	$destinations = get_field( 'destinations', 'option' );

	if ( empty( $destinations ) || ! is_array( $destinations ) ) {
		$message = sprintf(
			'No destinations configured at all — submission %d for "%s" not dispatched.',
			$post_id,
			$form_name
		);
		error_log( '[Dev Base Forms] ' . $message );
		devbase_forms_save_dispatch_log( $post_id, array(
			array( 'success' => false, 'label' => __( 'No destinations configured', 'devbase_parent_theme' ), 'message' => $message ),
		) );
		return;
	}

	$results = array();

	foreach ( $destinations as $row ) {
		$applies_to = ! empty( $row['apply_to'] ) && is_array( $row['apply_to'] ) ? $row['apply_to'] : array();

		if ( ! in_array( $form_name, $applies_to, true ) ) {
			continue;
		}

		if ( 'smtp' === $row['type'] ) {
			$results[] = devbase_forms_dispatch_smtp( $row, $form_name, $content );
		} elseif ( 'webhook' === $row['type'] ) {
			$results[] = devbase_forms_dispatch_webhook( $row, $form_name, $content );
		}
	}

	if ( empty( $results ) ) {
		$message = sprintf(
			'No destination applies to form "%s" — submission %d not dispatched.',
			$form_name,
			$post_id
		);
		error_log( '[Dev Base Forms] ' . $message );
		$results[] = array( 'success' => false, 'label' => __( 'No matching destination', 'devbase_parent_theme' ), 'message' => $message );
	}

	devbase_forms_save_dispatch_log( $post_id, $results );
}

/**
 * Stores the dispatch log for the Submission meta box
 * (inc/forms/form-data-admin-display.php) to read. wp_slash() is required here:
 * update_post_meta() runs its value through wp_unslash() before storing
 * (WordPress assumes meta input arrives already magic-quoted), which
 * would otherwise silently strip the \" escapes wp_json_encode() adds
 * around any literal quote in a destination label or error message,
 * corrupting the stored JSON.
 *
 * @param int   $post_id A form_data post ID.
 * @param array $results Array of ['success' => bool, 'label' => string, 'message' => string].
 */
function devbase_forms_save_dispatch_log( $post_id, array $results ) {
	update_post_meta( $post_id, '_devbase_dispatch_log', wp_slash( wp_json_encode( $results ) ) );
}
