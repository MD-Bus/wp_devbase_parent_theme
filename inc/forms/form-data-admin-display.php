<?php

// The form_data CPT only supports 'title' (see post-types.php) — the
// actual submission lives entirely in postmeta ('form_name', 'content'),
// so opening a submission in wp-admin would otherwise show a blank edit
// screen with nothing but the title field. This adds a read-only meta box
// showing the submitted fields and, once dispatch has run, what happened
// when it tried to notify each configured destination.
add_action( 'add_meta_boxes_form_data', function () {
	add_meta_box(
		'devbase_form_data_submission',
		__( 'Submission', 'devbase_parent_theme' ),
		'devbase_forms_render_submission_meta_box',
		'form_data',
		'normal',
		'high'
	);
} );

function devbase_forms_render_submission_meta_box( $post ) {
	$form_name = get_post_meta( $post->ID, 'form_name', true );
	$content   = json_decode( (string) get_post_meta( $post->ID, 'content', true ), true );
	$log       = json_decode( (string) get_post_meta( $post->ID, '_devbase_dispatch_log', true ), true );

	echo '<p><strong>' . esc_html__( 'Form', 'devbase_parent_theme' ) . ':</strong> ' . esc_html( $form_name ?: '—' ) . '</p>';

	echo '<h3>' . esc_html__( 'Submitted Fields', 'devbase_parent_theme' ) . '</h3>';

	if ( empty( $content ) || ! is_array( $content ) ) {
		echo '<p>' . esc_html__( 'No submitted data recorded on this entry.', 'devbase_parent_theme' ) . '</p>';
	} else {
		echo '<table class="widefat striped"><tbody>';
		foreach ( $content as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = isset( $field['aria_label'] ) ? $field['aria_label'] : '';
			$value = isset( $field['value'] ) ? $field['value'] : '';
			echo '<tr><td style="width:200px;font-weight:600;">' . esc_html( $label ) . '</td><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '<h3>' . esc_html__( 'Dispatch Log', 'devbase_parent_theme' ) . '</h3>';

	if ( empty( $log ) || ! is_array( $log ) ) {
		echo '<p>' . esc_html__( 'Not dispatched yet.', 'devbase_parent_theme' ) . '</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr><th style="width:120px;">' . esc_html__( 'Status', 'devbase_parent_theme' ) . '</th><th style="width:200px;">' . esc_html__( 'Destination', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Detail', 'devbase_parent_theme' ) . '</th></tr></thead><tbody>';
	foreach ( $log as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$success = ! empty( $entry['success'] );
		$badge   = $success
			? '<span style="color:#008a20;font-weight:600;">' . esc_html__( 'Sent', 'devbase_parent_theme' ) . '</span>'
			: '<span style="color:#d63638;font-weight:600;">' . esc_html__( 'Failed', 'devbase_parent_theme' ) . '</span>';
		echo '<tr><td>' . $badge . '</td><td>' . esc_html( $entry['label'] ?? '' ) . '</td><td>' . esc_html( $entry['message'] ?? '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</tbody></table>';
}
