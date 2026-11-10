<?php

// Form submissions in WordPress's own personal data tools (Tools > Export Personal Data and
// Tools > Erase Personal Data, WordPress 4.9.6+). A submission belongs to an address when one of its
// submitted values IS that address (compared without case), so a request for jane@example.com finds
// every form she filled in with it, whatever the field was called.
//
// Export: one item per submission, with the form, the date and every submitted field.
// Erase: the submission is deleted outright (the post, its fields and its dispatch log). It is the
// person's own message, so there is nothing left worth keeping once their data is taken out of it.
//
// Finding them: a LIKE on the stored JSON narrows the rows, then each row is decoded and checked for an
// exact match, so "an@example.com" never matches "jean@example.com".

/** How many submissions one page of an export or erasure handles. */
const DEVBASE_FORMS_PRIVACY_PAGE = 50;

add_filter( 'wp_privacy_personal_data_exporters', function ( $exporters ) {
	$exporters['devbase-form-submissions'] = array(
		'exporter_friendly_name' => __( 'Form submissions', 'devbase_parent_theme' ),
		'callback'               => 'devbase_forms_privacy_export',
	);

	return $exporters;
} );

add_filter( 'wp_privacy_personal_data_erasers', function ( $erasers ) {
	$erasers['devbase-form-submissions'] = array(
		'eraser_friendly_name' => __( 'Form submissions', 'devbase_parent_theme' ),
		'callback'             => 'devbase_forms_privacy_erase',
	);

	return $erasers;
} );

/**
 * Every submission that belongs to an address, oldest first. IDs only: the LIKE narrows the rows to
 * those that mention the address, which for one person is a handful.
 *
 * @param string $email An address.
 * @return int[]
 */
function devbase_forms_privacy_find( $email ) {
	$email = strtolower( trim( (string) $email ) );

	if ( '' === $email || ! is_email( $email ) ) {
		return array();
	}

	$candidates = get_posts( array(
		'post_type'        => 'form_data',
		'post_status'      => 'any',
		'fields'           => 'ids',
		'posts_per_page'   => -1,
		'orderby'          => 'ID',
		'order'            => 'ASC',
		'suppress_filters' => true,
		'meta_query'       => array(
			array(
				'key'     => 'content',
				'value'   => $email,
				'compare' => 'LIKE',
			),
		),
	) );

	return array_values( array_filter( array_map( 'intval', $candidates ), function ( $id ) use ( $email ) {
		return devbase_forms_privacy_belongs( $id, $email );
	} ) );
}

/** Whether one of a submission's values is exactly this address. */
function devbase_forms_privacy_belongs( $post_id, $email ) {
	$content = json_decode( (string) get_post_meta( $post_id, 'content', true ), true );

	foreach ( is_array( $content ) ? $content : array() as $field ) {
		$values = is_array( $field ) && isset( $field['value'] ) ? (array) $field['value'] : array();

		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && strtolower( trim( (string) $value ) ) === $email ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * The exporter.
 *
 * @param string $email An address.
 * @param int    $page  1-based page.
 */
function devbase_forms_privacy_export( $email, $page = 1 ) {
	$all   = devbase_forms_privacy_find( $email );
	$page  = max( 1, (int) $page );
	$ids   = array_slice( $all, ( $page - 1 ) * DEVBASE_FORMS_PRIVACY_PAGE, DEVBASE_FORMS_PRIVACY_PAGE );
	$items = array();

	foreach ( $ids as $post_id ) {
		$data = array(
			array( 'name' => __( 'Form', 'devbase_parent_theme' ), 'value' => (string) get_post_meta( $post_id, 'form_name', true ) ),
			array( 'name' => __( 'Submitted', 'devbase_parent_theme' ), 'value' => (string) get_post_time( 'c', true, $post_id ) ),
		);

		$content = json_decode( (string) get_post_meta( $post_id, 'content', true ), true );

		foreach ( is_array( $content ) ? $content : array() as $name => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$value  = $field['value'] ?? '';
			$data[] = array(
				'name'  => '' !== (string) ( $field['aria_label'] ?? '' ) ? (string) $field['aria_label'] : (string) $name,
				'value' => is_array( $value ) ? implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value,
			);
		}

		$items[] = array(
			'group_id'    => 'devbase-form-submissions',
			'group_label' => __( 'Form submissions', 'devbase_parent_theme' ),
			'item_id'     => 'form-submission-' . $post_id,
			'data'        => $data,
		);
	}

	return array( 'data' => $items, 'done' => count( $all ) <= $page * DEVBASE_FORMS_PRIVACY_PAGE );
}

/**
 * The eraser: the first page of what is left each time, since what was erased is no longer in the list.
 *
 * @param string $email An address.
 * @param int    $page  Ignored (see above).
 */
function devbase_forms_privacy_erase( $email, $page = 1 ) {
	$all     = devbase_forms_privacy_find( $email );
	$ids     = array_slice( $all, 0, DEVBASE_FORMS_PRIVACY_PAGE );
	$removed = false;
	$kept    = false;
	$notes   = array();

	foreach ( $ids as $post_id ) {
		wp_clear_scheduled_hook( DEVBASE_FORMS_RETRY_HOOK, array( (int) $post_id ) );

		if ( wp_delete_post( $post_id, true ) ) {
			$removed = true;
		} else {
			$kept = true;
			/* translators: %d: a submission's ID. */
			$notes[] = sprintf( __( 'Form submission %d could not be deleted.', 'devbase_parent_theme' ), $post_id );
		}
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => $kept,
		'messages'       => $notes,
		// One that could not be deleted would be found again on every page: stop rather than loop.
		'done'           => count( $all ) <= DEVBASE_FORMS_PRIVACY_PAGE || $kept,
	);
}
