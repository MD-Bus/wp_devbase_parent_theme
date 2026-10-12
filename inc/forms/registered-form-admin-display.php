<?php

// The registered_form CPT only supports 'title' (see post-types.php) and
// carries no field group of its own — destinations are configured
// elsewhere (inc/theme-settings/destinations/) since the inversion that
// moved routing off this CPT and onto each destination's "Applies To"
// picker. Without this file, opening a Registered Form in wp-admin shows
// nothing but the title, which reads as broken rather than "there's just
// nothing to configure here." This adds a read-only meta box showing the
// form's stable name and — since that inversion means this is otherwise
// the one place you CAN'T see "where does this form's data go" — a
// reverse lookup of every destination whose "Applies To" currently
// includes this form.
add_action( 'add_meta_boxes_registered_form', function () {
	add_meta_box(
		'devbase_registered_form_details',
		__( 'Details', 'devbase_parent_theme' ),
		'devbase_forms_render_registered_form_meta_box',
		'registered_form',
		'normal',
		'high'
	);
} );

function devbase_forms_render_registered_form_meta_box( $post ) {
	$form_name = get_post_meta( $post->ID, 'form_name', true );

	echo '<p><strong>' . esc_html__( 'Form Name', 'devbase_parent_theme' ) . ':</strong> <code>' . esc_html( $form_name ?: '—' ) . '</code>';
	echo ' <span style="color:#787c82;">(' . esc_html__( 'pass this to devbase_form_log_submission()', 'devbase_parent_theme' ) . ')</span></p>';

	echo '<h3>' . esc_html__( 'Destinations Applying To This Form', 'devbase_parent_theme' ) . '</h3>';

	$destinations = get_field( 'destinations', 'option' );
	$matches      = array();

	if ( is_array( $destinations ) ) {
		foreach ( $destinations as $row ) {
			$applies_to = ! empty( $row['apply_to'] ) && is_array( $row['apply_to'] ) ? $row['apply_to'] : array();
			if ( in_array( $form_name, $applies_to, true ) ) {
				$matches[] = $row;
			}
		}
	}

	if ( empty( $matches ) ) {
		echo '<p>' . esc_html__( 'No destination currently applies to this form — submissions will be logged but nobody will be notified.', 'devbase_parent_theme' ) . '</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Type', 'devbase_parent_theme' ) . '</th></tr></thead><tbody>';
		foreach ( $matches as $row ) {
			echo '<tr><td>' . esc_html( $row['label'] ?? '' ) . '</td><td>' . esc_html( strtoupper( $row['type'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	printf(
		'<p style="margin-top:12px;"><a href="%s" class="button">%s</a></p>',
		esc_url( admin_url( 'admin.php?page=destinations-settings' ) ),
		esc_html__( 'Manage Form Destinations', 'devbase_parent_theme' )
	);
}
