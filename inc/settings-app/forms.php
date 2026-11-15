<?php

// The Forms and Submissions sections of the Theme Settings app: read-only replacements for the Registered Forms and Form
// Data list screens. They draw their own screens (no SCF form) from the same data: registered_form posts, form_data posts
// and their postmeta (form_name, content, _devbase_dispatched, _devbase_dispatch_log, _devbase_resent_at). Nothing here
// stores anything; the one action, Resend, is the existing devbase_forms_resend() behind its existing nonce.
//
// A section is left out while its Miscellaneous > Management switch hides that menu (devbase_forms_ui_hidden()).

add_filter( 'devbase/app/sections', function ( $sections ) {
	if ( ! is_array( $sections ) ) {
		return $sections;
	}

	if ( post_type_exists( 'registered_form' ) && ! devbase_forms_ui_hidden( 'registered_form' ) ) {
		$sections['forms'] = array(
			'title'       => __( 'Registered Forms', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-forms',
			'description' => __( 'Every form on the site, how many submissions it has and who is notified.', 'devbase_parent_theme' ),
			'group'       => 'forms',
			'render'      => 'devbase_app_render_forms',
		);
	}

	if ( post_type_exists( 'form_data' ) && ! devbase_forms_ui_hidden( 'form_data' ) ) {
		$sections['submissions'] = array(
			'title'       => __( 'Submissions', 'devbase_parent_theme' ),
			'icon'        => 'dashicons-email-alt',
			'description' => __( 'What visitors sent through the site\'s forms, and whether each one was delivered.', 'devbase_parent_theme' ),
			'group'       => 'forms',
			'render'      => 'devbase_app_render_submissions',
		);
	}

	return $sections;
}, 5 );

/** A small coloured label. $tone: ok, bad, warn or muted. */
function devbase_app_chip( $text, $tone = 'muted' ) {
	return '<span class="devbase-chip -' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
}

/** "Nov 15, 2026 4:55 pm" in the site's own format and time zone, from a local 'Y-m-d H:i:s' string. */
function devbase_app_format_local_time( $mysql_local ) {
	$ts = strtotime( (string) $mysql_local );

	return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts, wp_timezone() ) : '—';
}

/** Rows per page on the list screens. Filter `devbase/app/per_page` ( int, string $screen: forms or submissions ). */
function devbase_app_per_page( $screen ) {
	return max( 1, (int) apply_filters( 'devbase/app/per_page', 20, $screen ) );
}

/**
 * The footer under a list: "N items" and the page links. Draws nothing for an empty list.
 *
 * @param int    $total    How many rows match, across every page.
 * @param int    $pages    How many pages that is.
 * @param int    $current  The page shown.
 * @param string $section  The section the links point at.
 * @param array  $args     The other query arguments to keep (filters), empty ones left out.
 * @param array  $nouns    Singular and plural noun, e.g. array( 'submission', 'submissions' ).
 */
function devbase_app_pager( $total, $pages, $current, $section, array $args, array $nouns ) {
	if ( $total < 1 ) {
		return;
	}

	$links = $pages > 1 ? paginate_links( array(
		'base'      => add_query_arg( 'paged', '%#%', devbase_app_url( $section, array_filter( $args, function ( $value ) {
			return '' !== (string) $value;
		} ) ) ),
		'format'    => '',
		'current'   => $current,
		'total'     => $pages,
		'prev_text' => '‹',
		'next_text' => '›',
	) ) : '';

	echo '<div class="devbase-pager"><span>' . esc_html( number_format_i18n( $total ) . ' ' . ( 1 === $total ? $nouns[0] : $nouns[1] ) ) . '</span>' . ( $links ? '<span class="devbase-pager__links">' . $links . '</span>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() escapes its own output.
}

// --- Registered Forms ------------------------------------------------------------------------------------------------

/**
 * Per form name: how many submissions and when the newest arrived. One query for all forms.
 *
 * @return array<string,array{count:int,last:string}>
 */
function devbase_app_form_stats() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT m.meta_value AS form_name, COUNT(*) AS total, MAX(p.post_date) AS last_at
		FROM {$wpdb->postmeta} m
		INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
		WHERE m.meta_key = 'form_name' AND p.post_type = 'form_data' AND p.post_status = 'publish'
		GROUP BY m.meta_value"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- no user input; read-only aggregate.

	$stats = array();

	foreach ( (array) $rows as $row ) {
		$stats[ (string) $row->form_name ] = array(
			'count' => (int) $row->total,
			'last'  => (string) $row->last_at,
		);
	}

	return $stats;
}

/** The Form Destinations rows whose "Applies To" includes the form. */
function devbase_app_destinations_for( $form_name ) {
	$rows    = get_field( 'destinations', 'option' );
	$matches = array();

	if ( ! is_array( $rows ) ) {
		return $matches;
	}

	foreach ( $rows as $row ) {
		$applies_to = ! empty( $row['apply_to'] ) && is_array( $row['apply_to'] ) ? $row['apply_to'] : array();

		if ( in_array( $form_name, $applies_to, true ) ) {
			$matches[] = $row;
		}
	}

	return $matches;
}

function devbase_app_render_forms() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$args  = array(
		'post_type'        => 'registered_form',
		'post_status'      => 'publish',
		'posts_per_page'   => devbase_app_per_page( 'forms' ),
		'paged'            => $paged,
		'orderby'          => 'title',
		'order'            => 'ASC',
		'suppress_filters' => true,
	);
	$query = new WP_Query( $args );

	// A page past the end: show the last page (WP_Query reports no total for an empty page, so ask page 1).
	if ( ! $query->have_posts() && $paged > 1 ) {
		$first = new WP_Query( array_merge( $args, array( 'paged' => 1 ) ) );

		if ( $first->have_posts() ) {
			$paged = max( 1, (int) $first->max_num_pages );
			$query = $paged > 1 ? new WP_Query( array_merge( $args, array( 'paged' => $paged ) ) ) : $first;
		}
	}

	$forms = $query->posts;

	if ( ! $forms ) {
		echo '<p class="devbase-empty">' . esc_html__( 'No forms are registered yet. A developer adds one by listing it in registered-forms.json.', 'devbase_parent_theme' ) . '</p>';

		return;
	}

	$stats        = devbase_app_form_stats();
	$sections     = devbase_app_sections();
	$can_destinations = isset( $sections['destinations'] );
	$can_subs     = isset( $sections['submissions'] );

	echo '<table class="devbase-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Form', 'devbase_parent_theme' ) . '</th>';
	echo '<th>' . esc_html__( 'Submissions', 'devbase_parent_theme' ) . '</th>';
	echo '<th>' . esc_html__( 'Last received', 'devbase_parent_theme' ) . '</th>';
	echo '<th>' . esc_html__( 'Sent to', 'devbase_parent_theme' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $forms as $form ) {
		$name  = (string) get_post_meta( $form->ID, 'form_name', true );
		$stat  = $stats[ $name ] ?? array( 'count' => 0, 'last' => '' );
		$dests = '' === $name ? array() : devbase_app_destinations_for( $name );

		echo '<tr><td><strong>' . esc_html( get_the_title( $form ) ) . '</strong><br><code>' . esc_html( '' !== $name ? $name : '—' ) . '</code></td>';

		echo '<td>';
		if ( $stat['count'] && $can_subs ) {
			printf( '<a href="%s">%s</a>', esc_url( devbase_app_url( 'submissions', array( 'form' => $name ) ) ), esc_html( number_format_i18n( $stat['count'] ) ) );
		} else {
			echo esc_html( number_format_i18n( $stat['count'] ) );
		}
		echo '</td>';

		echo '<td>' . esc_html( $stat['last'] ? devbase_app_format_local_time( $stat['last'] ) : '—' ) . '</td>';

		echo '<td>';
		if ( $dests ) {
			foreach ( $dests as $dest ) {
				echo devbase_app_chip( ( '' !== (string) ( $dest['label'] ?? '' ) ? $dest['label'] : '—' ) . ' · ' . strtoupper( (string) ( $dest['type'] ?? '' ) ), 'ok' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		} else {
			echo devbase_app_chip( __( 'Nobody is notified', 'devbase_parent_theme' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</td></tr>';
	}

	echo '</tbody></table>';

	devbase_app_pager( (int) $query->found_posts, (int) $query->max_num_pages, $paged, 'forms', array(), array( __( 'form', 'devbase_parent_theme' ), __( 'forms', 'devbase_parent_theme' ) ) );

	if ( $can_destinations ) {
		printf( '<p class="devbase-app__footnote"><a class="button" href="%s">%s</a></p>', esc_url( devbase_app_url( 'destinations' ) ), esc_html__( 'Manage Form Destinations', 'devbase_parent_theme' ) );
	}
}

// --- Submissions -----------------------------------------------------------------------------------------------------

/**
 * What happened to a submission's notifications.
 *
 * @return array{0:string,1:string,2:string} Key, label, chip tone.
 */
function devbase_app_submission_status( $post_id ) {
	$state = (string) get_post_meta( $post_id, '_devbase_dispatched', true );
	$log   = json_decode( (string) get_post_meta( $post_id, '_devbase_dispatch_log', true ), true );

	if ( 'retry' === $state ) {
		return array( 'retry', __( 'Retrying', 'devbase_parent_theme' ), 'warn' );
	}

	if ( is_array( $log ) && $log ) {
		$unrouted = false;

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['success'] ) ) {
				continue;
			}

			// A real destination's entry carries its `key`. One without it ("No destinations configured", "No matching
			// destination") is a routing gap, not a delivery that failed: nothing was sent because nothing was set up.
			if ( isset( $entry['key'] ) ) {
				return array( 'failed', __( 'Failed', 'devbase_parent_theme' ), 'bad' );
			}

			$unrouted = true;
		}

		return $unrouted
			? array( 'unrouted', __( 'Nobody notified', 'devbase_parent_theme' ), 'warn' )
			: array( 'sent', __( 'Sent', 'devbase_parent_theme' ), 'ok' );
	}

	if ( 'pending' === $state ) {
		return array( 'pending', __( 'Sending', 'devbase_parent_theme' ), 'muted' );
	}

	return array( 'logged', __( 'Logged only', 'devbase_parent_theme' ), 'muted' );
}

/** The submitted fields as label => string, in order. */
function devbase_app_submission_fields( $post_id ) {
	$content = json_decode( (string) get_post_meta( $post_id, 'content', true ), true );
	$out     = array();

	if ( ! is_array( $content ) ) {
		return $out;
	}

	foreach ( $content as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$value = $field['value'] ?? '';
		$value = is_array( $value ) ? implode( "\n", array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value;

		$out[] = array( (string) ( $field['aria_label'] ?? '' ), $value );
	}

	return $out;
}

/** A form's title from its registered_form post, else its name, else a dash. */
function devbase_app_form_label( $name ) {
	if ( '' === (string) $name ) {
		return '—';
	}

	$found = get_posts( array(
		'post_type'        => 'registered_form',
		'post_status'      => 'publish',
		'posts_per_page'   => 1,
		'meta_key'         => 'form_name', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'       => $name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'suppress_filters' => true,
	) );

	return $found ? get_the_title( $found[0] ) : (string) $name;
}

/** Reads the list filters from the address, each cleaned. */
function devbase_app_submission_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

	return array(
		'form'   => isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '',
		'status' => in_array( $status, array( 'attention' ), true ) ? $status : '',
		'q'      => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
		'paged'  => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/** The WP_Query arguments for the list: published submissions, newest first, filtered. */
function devbase_app_submission_query_args( array $f, $per_page = 0 ) {
	$per_page = $per_page ?: devbase_app_per_page( 'submissions' );
	$meta = array();

	if ( '' !== $f['form'] ) {
		$meta[] = array( 'key' => 'form_name', 'value' => $f['form'] );
	}

	if ( 'attention' === $f['status'] ) {
		$meta[] = array(
			'relation' => 'OR',
			array( 'key' => '_devbase_dispatched', 'value' => 'retry' ),
			array( 'key' => '_devbase_dispatch_log', 'value' => '"success":false', 'compare' => 'LIKE' ),
		);
	}

	if ( '' !== $f['q'] ) {
		// The content is stored as JSON, which escapes quotes, slashes and non-ASCII: search for the text as typed
		// AND as JSON would have written it.
		$encoded = trim( (string) wp_json_encode( $f['q'] ), '"' );
		$text    = array( 'relation' => 'OR', array( 'key' => 'content', 'value' => $f['q'], 'compare' => 'LIKE' ) );

		if ( $encoded !== $f['q'] ) {
			$text[] = array( 'key' => 'content', 'value' => $encoded, 'compare' => 'LIKE' );
		}

		$meta[] = $text;
	}

	$args = array(
		'post_type'        => 'form_data',
		'post_status'      => 'publish',
		'posts_per_page'   => $per_page,
		'paged'            => $f['paged'],
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => true,
	);

	if ( $meta ) {
		$meta['relation']  = 'AND';
		$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin-only screen over a table that retention keeps small.
	}

	return $args;
}

function devbase_app_render_submissions() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$one = isset( $_GET['sub'] ) ? absint( $_GET['sub'] ) : 0;

	if ( $one ) {
		devbase_app_render_submission( $one );

		return;
	}

	$f     = devbase_app_submission_filters();
	$query = new WP_Query( devbase_app_submission_query_args( $f ) );

	// A page past the end (a bookmark after submissions were deleted): show the last page, not "no submissions".
	// WP_Query reports found_posts as 0 for a page with no rows, so the real page count is read from page 1.
	if ( ! $query->have_posts() && $f['paged'] > 1 ) {
		$first = new WP_Query( devbase_app_submission_query_args( array_merge( $f, array( 'paged' => 1 ) ) ) );

		if ( $first->have_posts() ) {
			$f['paged'] = max( 1, (int) $first->max_num_pages );
			$query      = $f['paged'] > 1 ? new WP_Query( devbase_app_submission_query_args( $f ) ) : $first;
		}
	}

	$names = array();

	foreach ( get_posts( array( 'post_type' => 'registered_form', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true ) ) as $form ) {
		$names[ (string) get_post_meta( $form->ID, 'form_name', true ) ] = get_the_title( $form );
	}

	// A filter bar: a GET form, so every filter is in the address and survives paging and a reload.
	echo '<form method="get" class="devbase-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="devbase-app"><input type="hidden" name="section" value="submissions">';
	echo '<input type="search" name="q" value="' . esc_attr( $f['q'] ) . '" placeholder="' . esc_attr__( 'Search what was submitted…', 'devbase_parent_theme' ) . '" aria-label="' . esc_attr__( 'Search submissions', 'devbase_parent_theme' ) . '">';
	echo '<select name="form" aria-label="' . esc_attr__( 'Form', 'devbase_parent_theme' ) . '"><option value="">' . esc_html__( 'All forms', 'devbase_parent_theme' ) . '</option>';
	foreach ( $names as $name => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $name ), selected( $f['form'], $name, false ), esc_html( $label ) );
	}
	echo '</select>';
	echo '<select name="status" aria-label="' . esc_attr__( 'Delivery', 'devbase_parent_theme' ) . '"><option value="">' . esc_html__( 'Any delivery', 'devbase_parent_theme' ) . '</option><option value="attention"' . selected( $f['status'], 'attention', false ) . '>' . esc_html__( 'Needs attention (failed, retrying or nobody notified)', 'devbase_parent_theme' ) . '</option></select>';
	echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'devbase_parent_theme' ) . '</button>';
	if ( '' !== $f['q'] || '' !== $f['form'] || '' !== $f['status'] ) {
		echo '<a class="devbase-filters__reset" href="' . esc_url( devbase_app_url( 'submissions' ) ) . '">' . esc_html__( 'Clear', 'devbase_parent_theme' ) . '</a>';
	}
	echo '</form>';

	if ( ! $query->have_posts() ) {
		echo '<p class="devbase-empty">' . esc_html( '' !== $f['q'] || '' !== $f['form'] || '' !== $f['status']
			? __( 'No submissions match these filters.', 'devbase_parent_theme' )
			: __( 'No submissions yet. They appear here as visitors send the site\'s forms.', 'devbase_parent_theme' ) ) . '</p>';

		return;
	}

	echo '<table class="devbase-table"><thead><tr><th>' . esc_html__( 'Received', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Form', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'What they sent', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Delivery', 'devbase_parent_theme' ) . '</th></tr></thead><tbody>';

	foreach ( $query->posts as $post ) {
		$name    = (string) get_post_meta( $post->ID, 'form_name', true );
		$summary = array();

		foreach ( devbase_app_submission_fields( $post->ID ) as $pair ) {
			if ( '' !== trim( $pair[1] ) && count( $summary ) < 3 ) {
				$summary[] = ( '' !== $pair[0] ? $pair[0] . ': ' : '' ) . wp_html_excerpt( str_replace( "\n", ' ', $pair[1] ), 60, '…' );
			}
		}

		$status = devbase_app_submission_status( $post->ID );
		$url    = devbase_app_url( 'submissions', array( 'sub' => $post->ID ) );

		echo '<tr>';
		echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( devbase_app_format_local_time( $post->post_date ) ) . '</a></td>';
		echo '<td>' . esc_html( $names[ $name ] ?? ( '' !== $name ? $name : '—' ) ) . '</td>';
		echo '<td class="devbase-table__summary">' . esc_html( $summary ? implode( '  ·  ', $summary ) : '—' ) . '</td>';
		echo '<td>' . devbase_app_chip( $status[1], $status[2] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tr>';
	}

	echo '</tbody></table>';

	devbase_app_pager( (int) $query->found_posts, (int) $query->max_num_pages, $f['paged'], 'submissions', array( 'form' => $f['form'], 'status' => $f['status'], 'q' => $f['q'] ), array( __( 'submission', 'devbase_parent_theme' ), __( 'submissions', 'devbase_parent_theme' ) ) );
}

/** One submission: its fields, its delivery log, and Resend. */
function devbase_app_render_submission( $post_id ) {
	$post = get_post( $post_id );

	echo '<p class="devbase-back"><a href="' . esc_url( devbase_app_url( 'submissions' ) ) . '">← ' . esc_html__( 'All submissions', 'devbase_parent_theme' ) . '</a></p>';

	if ( ! $post || 'form_data' !== $post->post_type || 'publish' !== $post->post_status ) {
		echo '<p class="devbase-empty">' . esc_html__( 'That submission does not exist.', 'devbase_parent_theme' ) . '</p>';

		return;
	}

	$name   = (string) get_post_meta( $post->ID, 'form_name', true );
	$fields = devbase_app_submission_fields( $post->ID );
	$log    = json_decode( (string) get_post_meta( $post->ID, '_devbase_dispatch_log', true ), true );
	$status = devbase_app_submission_status( $post->ID );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only says what the Resend link did.
	if ( isset( $_GET['resent'] ) ) {
		echo '<div class="devbase-app__saved" role="status">' . esc_html( '1' === $_GET['resent'] ? __( 'Sent again to every destination. The log below is the new attempt.', 'devbase_parent_theme' ) : __( 'That submission could not be sent again.', 'devbase_parent_theme' ) ) . '</div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	echo '<div class="devbase-meta"><div><span>' . esc_html__( 'Form', 'devbase_parent_theme' ) . '</span><strong>' . esc_html( devbase_app_form_label( $name ) ) . '</strong></div>';
	echo '<div><span>' . esc_html__( 'Received', 'devbase_parent_theme' ) . '</span><strong>' . esc_html( devbase_app_format_local_time( $post->post_date ) ) . '</strong></div>';
	echo '<div><span>' . esc_html__( 'Delivery', 'devbase_parent_theme' ) . '</span>' . devbase_app_chip( $status[1], $status[2] ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	echo '<h3 class="devbase-h3">' . esc_html__( 'What they sent', 'devbase_parent_theme' ) . '</h3>';

	if ( ! $fields ) {
		echo '<p class="devbase-empty">' . esc_html__( 'No submitted data recorded on this entry.', 'devbase_parent_theme' ) . '</p>';
	} else {
		echo '<dl class="devbase-fields">';
		foreach ( $fields as $pair ) {
			echo '<dt>' . esc_html( $pair[0] ) . '</dt><dd>' . nl2br( esc_html( $pair[1] ) ) . '</dd>';
		}
		echo '</dl>';
	}

	echo '<h3 class="devbase-h3">' . esc_html__( 'Delivery log', 'devbase_parent_theme' ) . '</h3>';

	if ( '' !== $name && current_user_can( devbase_settings_capability() ) ) {
		$resent = (int) get_post_meta( $post->ID, '_devbase_resent_at', true );

		echo '<p><a class="button" href="' . esc_url( add_query_arg( 'back', 'app', devbase_forms_resend_url( $post->ID ) ) ) . '">' . esc_html__( 'Resend', 'devbase_parent_theme' ) . '</a> ';
		echo '<span class="description">' . esc_html( $resent
			/* translators: %s: a date and time. */
			? sprintf( __( 'Sends it again to every destination that applies to its form. Last resent %s.', 'devbase_parent_theme' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $resent ) )
			: __( 'Sends it again to every destination that applies to its form.', 'devbase_parent_theme' ) ) . '</span></p>';
	}

	if ( ! is_array( $log ) || ! $log ) {
		echo '<p class="devbase-empty">' . esc_html__( 'Nothing has been sent for this submission.', 'devbase_parent_theme' ) . '</p>';

		return;
	}

	echo '<table class="devbase-table"><thead><tr><th>' . esc_html__( 'Status', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Destination', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Detail', 'devbase_parent_theme' ) . '</th></tr></thead><tbody>';

	foreach ( $log as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$ok    = ! empty( $entry['success'] );
		$gap   = ! $ok && ! isset( $entry['key'] );
		$tries = (int) ( $entry['attempts'] ?? 0 );
		/* translators: %d: how many times the destination was tried. */
		$more  = $tries > 1 ? ' ' . sprintf( __( '(tried %d times)', 'devbase_parent_theme' ), $tries ) : '';

		echo '<tr><td>' . devbase_app_chip( $ok ? __( 'Sent', 'devbase_parent_theme' ) : ( $gap ? __( 'Not sent', 'devbase_parent_theme' ) : __( 'Failed', 'devbase_parent_theme' ) ), $ok ? 'ok' : ( $gap ? 'warn' : 'bad' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . esc_html( (string) ( $entry['label'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['message'] ?? '' ) . $more ) . '</td></tr>';
	}

	echo '</tbody></table>';

	if ( 'retry' === get_post_meta( $post->ID, '_devbase_dispatched', true ) ) {
		$next = wp_next_scheduled( DEVBASE_FORMS_RETRY_HOOK, array( (int) $post->ID ) );

		echo '<p class="description">' . esc_html( $next
			/* translators: %s: how long from now, e.g. "5 mins". */
			? sprintf( __( 'A destination that failed will be tried again in about %s.', 'devbase_parent_theme' ), human_time_diff( time(), $next ) )
			: __( 'A destination that failed will be tried again.', 'devbase_parent_theme' ) ) . '</p>';
	}
}
