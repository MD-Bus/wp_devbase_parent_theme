<?php

// Aggregates recent dispatch outcomes across form_data submissions, for
// the Miscellaneous > System Status tab. Every submission already
// carries its own _devbase_dispatch_log (see orchestrator.php's
// devbase_forms_save_dispatch_log()) — this reads the most recent
// window of them and rolls the per-submission logs up into one summary,
// so "is anything actually broken right now" is answerable without
// opening submissions one at a time.

/**
 * @param int $window How many of the most recent form_data submissions
 *                     to look at. Deliberately a recent window, not "all
 *                     time" — an old failure that's since been fixed
 *                     shouldn't keep showing as a current problem.
 * @return array{
 *     checked: int,
 *     success: int,
 *     failed: int,
 *     recent_failures: array<array{when: string, form_name: string, label: string, message: string}>
 * }
 */
function devbase_forms_get_recent_dispatch_summary( $window = 50 ) {
	$summary = array(
		'checked'         => 0,
		'success'         => 0,
		'failed'          => 0,
		'recent_failures' => array(),
	);

	$posts = get_posts( array(
		'post_type'      => 'form_data',
		'post_status'    => 'any',
		'posts_per_page' => $window,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	foreach ( $posts as $post ) {
		$log = json_decode( (string) get_post_meta( $post->ID, '_devbase_dispatch_log', true ), true );

		if ( empty( $log ) || ! is_array( $log ) ) {
			continue;
		}

		$form_name = get_post_meta( $post->ID, 'form_name', true );

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			++$summary['checked'];

			if ( ! empty( $entry['success'] ) ) {
				++$summary['success'];
				continue;
			}

			++$summary['failed'];

			if ( count( $summary['recent_failures'] ) < 10 ) {
				$summary['recent_failures'][] = array(
					'when'      => get_the_date( '', $post ),
					'form_name' => $form_name,
					'label'     => isset( $entry['label'] ) ? $entry['label'] : '',
					'message'   => isset( $entry['message'] ) ? $entry['message'] : '',
				);
			}
		}
	}

	return $summary;
}

/**
 * Human-readable dispatch health line + failure table (HTML) for the
 * System Status tab.
 *
 * @return string
 */
function devbase_forms_dispatch_status_message() {
	$summary = devbase_forms_get_recent_dispatch_summary();

	if ( 0 === $summary['checked'] ) {
		return esc_html__( 'No dispatch attempts recorded yet.', 'devbase_parent_theme' );
	}

	if ( 0 === $summary['failed'] ) {
		return sprintf(
			/* translators: %d: number of dispatch attempts checked */
			esc_html__( 'All %d recent dispatch attempts succeeded.', 'devbase_parent_theme' ),
			$summary['checked']
		);
	}

	$message = sprintf(
		/* translators: 1: number of failures, 2: number of dispatch attempts checked */
		esc_html__( '%1$d of the last %2$d dispatch attempts failed:', 'devbase_parent_theme' ),
		$summary['failed'],
		$summary['checked']
	);

	$rows = '';
	foreach ( $summary['recent_failures'] as $failure ) {
		$rows .= sprintf(
			'<tr><td style="white-space:nowrap;">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $failure['when'] ),
			esc_html( $failure['form_name'] ),
			esc_html( $failure['label'] ),
			esc_html( $failure['message'] )
		);
	}

	$table = sprintf(
		'<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>',
		esc_html__( 'When', 'devbase_parent_theme' ),
		esc_html__( 'Form', 'devbase_parent_theme' ),
		esc_html__( 'Destination', 'devbase_parent_theme' ),
		esc_html__( 'Detail', 'devbase_parent_theme' ),
		$rows
	);

	return $message . $table;
}
