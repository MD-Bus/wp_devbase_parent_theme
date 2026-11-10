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
//
// The flag is a STATE since 1.39.0, not a yes/no (any value still means "do not dispatch again from
// save_post"):
//
//   pending  stored, nothing sent yet (the sending happens after the visitor has their answer)
//   retry    sent, and at least one destination failed and will be tried again
//   done     nothing more will be sent
//   1        a submission from before 1.39.0: treated as done
//
// AFTER THE ANSWER. Sending used to happen inside the visitor's request: a mail server or a webhook
// that was slow to answer held the form for as long as it took, and one that hung could end the
// request before the visitor got an answer at all. Where PHP can finish the response first
// (fastcgi_finish_request(), i.e. PHP-FPM, which is nearly every host), the submission is queued and
// sent at the end of the request, after the answer has gone. Elsewhere it is sent at once, as before.
//
// TRIED AGAIN. A destination that fails is tried again by a WP-Cron event, up to
// devbase_forms_dispatch_max_attempts() times in all, a destination at a time: one that already
// received the submission is never sent it twice. The same event is the safety net for a request
// that died between storing and sending. (WP-Cron runs on a Brand Relay ping too, so this does not
// depend on visits: inc/maintenance/maintenance.php.)

/** The WP-Cron event that sends a submission again (its one argument is the form_data post ID). */
const DEVBASE_FORMS_RETRY_HOOK = 'devbase_forms_retry_dispatch';

add_action( 'save_post_form_data', function ( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( get_post_meta( $post_id, '_devbase_dispatched', true ) ) {
		return;
	}

	update_post_meta( $post_id, '_devbase_dispatched', 'pending' );

	// The safety net, set before anything is sent: a request that dies part-way (a timeout, a fatal
	// error in a mail plugin) leaves the submission `pending`, and this event sends it. A run that
	// finishes clears it (devbase_forms_dispatch_finish()).
	wp_schedule_single_event( time() + devbase_forms_dispatch_retry_delay( 1 ), DEVBASE_FORMS_RETRY_HOOK, array( (int) $post_id ) );

	if ( devbase_forms_dispatch_deferred() ) {
		devbase_forms_dispatch_later( $post_id );
		return;
	}

	devbase_forms_dispatch( $post_id );
}, 10, 3 );

/**
 * Whether sending waits until the visitor has their answer. Only where PHP can actually finish the
 * response first; never on the command line (WP-CLI, the test suite), where there is no one waiting.
 */
function devbase_forms_dispatch_deferred() {
	$can = function_exists( 'fastcgi_finish_request' ) && 'cli' !== PHP_SAPI;

	return (bool) apply_filters( 'devbase/forms/defer_dispatch', $can );
}

/** How many times in all a destination is tried (the first send included). */
function devbase_forms_dispatch_max_attempts() {
	return max( 1, (int) apply_filters( 'devbase/forms/dispatch_max_attempts', 3 ) );
}

/**
 * Seconds to wait before the next try, after this many tries: 5 minutes, then 30.
 *
 * @param int $attempts Tries made so far.
 */
function devbase_forms_dispatch_retry_delay( $attempts ) {
	$delay = $attempts <= 1 ? 5 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;

	return max( 1, (int) apply_filters( 'devbase/forms/dispatch_retry_delay', $delay, $attempts ) );
}

/**
 * Queues a submission to be sent at the end of this request.
 *
 * @param int|null $post_id A form_data post ID to queue; null takes (and empties) the queue.
 * @return int[] The queue.
 */
function devbase_forms_dispatch_later( $post_id = null ) {
	static $queue  = array();
	static $hooked = false;

	if ( null === $post_id ) {
		$taken = $queue;
		$queue = array();

		return $taken;
	}

	$queue[] = (int) $post_id;

	// has_action(), not only the flag: the test suite restores hooks between tests.
	if ( ! $hooked || ! has_action( 'shutdown', 'devbase_forms_dispatch_queued' ) ) {
		$hooked = true;
		add_action( 'shutdown', 'devbase_forms_dispatch_queued', 1000 );
	}

	return $queue;
}

/** Sends what this request queued, once the response has been handed to the visitor. */
function devbase_forms_dispatch_queued() {
	$queue = devbase_forms_dispatch_later();

	if ( ! $queue ) {
		return;
	}

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}

	ignore_user_abort( true );

	foreach ( $queue as $post_id ) {
		// The retry event may have got there first on a very slow request.
		if ( 'pending' === get_post_meta( $post_id, '_devbase_dispatched', true ) ) {
			devbase_forms_dispatch( $post_id );
		}
	}
}

// The retry: a destination that failed, or a submission whose request died before sending.
add_action( DEVBASE_FORMS_RETRY_HOOK, function ( $post_id ) {
	$post_id = (int) $post_id;
	$state   = get_post_meta( $post_id, '_devbase_dispatched', true );

	if ( 'form_data' !== get_post_type( $post_id ) || ! in_array( $state, array( 'pending', 'retry' ), true ) ) {
		return;
	}

	devbase_forms_dispatch( $post_id );
} );

/**
 * What tells one destination row from another across tries: its kind and where it sends to (and
 * which of several identical rows it is). Not its position alone, which changes when an
 * administrator reorders the rows between two tries.
 *
 * @param array $row  A destination row.
 * @param array $seen Keys already given out in this run (by reference): identical rows get -2, -3...
 */
function devbase_forms_destination_key( array $row, array &$seen ) {
	$type   = (string) ( $row['type'] ?? '' );
	$target = 'smtp' === $type ? (string) ( $row['to_email'] ?? '' ) : (string) ( $row['url'] ?? '' );
	$base   = md5( $type . '|' . strtolower( trim( $target ) ) );

	$seen[ $base ] = ( $seen[ $base ] ?? 0 ) + 1;

	return 1 === $seen[ $base ] ? $base : $base . '-' . $seen[ $base ];
}

/**
 * The stored dispatch log of a submission, by destination key (entries from before 1.39.0, and the
 * "no destination" notes, have no key and are left out).
 *
 * @return array<string, array>
 */
function devbase_forms_dispatch_log_by_key( $post_id ) {
	$log   = json_decode( (string) get_post_meta( $post_id, '_devbase_dispatch_log', true ), true );
	$keyed = array();

	foreach ( is_array( $log ) ? $log : array() as $entry ) {
		if ( is_array( $entry ) && ! empty( $entry['key'] ) ) {
			$keyed[ (string) $entry['key'] ] = $entry;
		}
	}

	return $keyed;
}

/**
 * Reads every configured destination (inc/theme-settings/destinations/),
 * finds the ones whose "Applies To" list includes this submission's
 * form_name, and sends it to each one that has not received it yet. Schedules another try when one
 * failed and has tries left; otherwise marks the submission done.
 *
 * @param int $post_id A form_data post ID.
 */
function devbase_forms_dispatch( $post_id ) {
	$form_name = get_post_meta( $post_id, 'form_name', true );
	$content   = json_decode( (string) get_post_meta( $post_id, 'content', true ), true );

	if ( empty( $form_name ) || ! is_array( $content ) ) {
		devbase_forms_dispatch_finish( $post_id, 0 );
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
		devbase_forms_dispatch_finish( $post_id, 0 );
		return;
	}

	$results  = array();
	$previous = devbase_forms_dispatch_log_by_key( $post_id );
	$max      = devbase_forms_dispatch_max_attempts();
	$seen     = array();
	$retry_at = 0; // The fewest tries made by a destination that still has some left (0: none to retry).

	foreach ( $destinations as $row ) {
		$applies_to = ! empty( $row['apply_to'] ) && is_array( $row['apply_to'] ) ? $row['apply_to'] : array();
		$type       = $row['type'] ?? '';

		if ( ! in_array( $form_name, $applies_to, true ) || ! in_array( $type, array( 'smtp', 'webhook' ), true ) ) {
			continue;
		}

		$key    = devbase_forms_destination_key( $row, $seen );
		$before = $previous[ $key ] ?? null;
		$tries  = (int) ( $before['attempts'] ?? 0 );

		// Already received it, or out of tries: what was recorded stands, nothing is sent.
		if ( $before && ( ! empty( $before['success'] ) || $tries >= $max ) ) {
			$results[] = $before;
			continue;
		}

		$result = 'smtp' === $type
			? devbase_forms_dispatch_smtp( $row, $form_name, $content )
			: devbase_forms_dispatch_webhook( $row, $form_name, $content );

		$result['key']      = $key;
		$result['attempts'] = $tries + 1;

		if ( empty( $result['success'] ) && $result['attempts'] < $max ) {
			$retry_at = 0 === $retry_at ? $result['attempts'] : min( $retry_at, $result['attempts'] );
		}

		$results[] = $result;
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
	devbase_forms_dispatch_finish( $post_id, $retry_at );
}

/**
 * Closes one run of devbase_forms_dispatch(): another try is scheduled, or the submission is done.
 *
 * @param int $post_id  A form_data post ID.
 * @param int $retry_at Tries made by the destination to try again soonest; 0 when nothing is to be retried.
 */
function devbase_forms_dispatch_finish( $post_id, $retry_at ) {
	$post_id = (int) $post_id;

	wp_clear_scheduled_hook( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) );

	if ( $retry_at > 0 ) {
		update_post_meta( $post_id, '_devbase_dispatched', 'retry' );
		wp_schedule_single_event( time() + devbase_forms_dispatch_retry_delay( $retry_at ), DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) );
		return;
	}

	update_post_meta( $post_id, '_devbase_dispatched', 'done' );
}

/**
 * Sends a submission again to every destination that applies to it, now, from the start: the old
 * log and tries are cleared. For an administrator who knows a notification went missing (sent to spam,
 * a webhook that answered 200 and lost it) or who fixed a destination after its tries ran out.
 *
 * @param int $post_id A form_data post ID.
 * @return bool False when the post is not a submission.
 */
function devbase_forms_resend( $post_id ) {
	$post_id = (int) $post_id;

	if ( 'form_data' !== get_post_type( $post_id ) ) {
		return false;
	}

	wp_clear_scheduled_hook( DEVBASE_FORMS_RETRY_HOOK, array( $post_id ) );
	delete_post_meta( $post_id, '_devbase_dispatch_log' );
	update_post_meta( $post_id, '_devbase_dispatched', 'pending' );
	update_post_meta( $post_id, '_devbase_resent_at', time() );

	devbase_forms_dispatch( $post_id );

	return true;
}

/** The address of the Resend link on a submission (a GET with a nonce, like WordPress's own Trash link). */
function devbase_forms_resend_url( $post_id ) {
	return wp_nonce_url(
		add_query_arg( array( 'action' => 'devbase_forms_resend', 'post' => (int) $post_id ), admin_url( 'admin-post.php' ) ),
		'devbase_forms_resend_' . (int) $post_id
	);
}

add_action( 'admin_post_devbase_forms_resend', function () {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

	check_admin_referer( 'devbase_forms_resend_' . $post_id );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to resend this submission.', 'devbase_parent_theme' ), 403 );
	}

	$done = devbase_forms_resend( $post_id );

	wp_safe_redirect( add_query_arg( 'devbase_resent', $done ? '1' : '0', get_edit_post_link( $post_id, 'url' ) ?: admin_url() ) );
	exit;
} );

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
 * @param array $results Array of ['success' => bool, 'label' => string, 'message' => string], and for a
 *                       destination also 'key' (devbase_forms_destination_key()) and 'attempts'.
 */
function devbase_forms_save_dispatch_log( $post_id, array $results ) {
	update_post_meta( $post_id, '_devbase_dispatch_log', wp_slash( wp_json_encode( $results ) ) );
}
