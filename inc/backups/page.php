<?php

// The Backups tab of Theme Settings > Licence: what Brand Relay holds for this site, a button to back up
// now, and a Download button per backup that brings it back into WPvivid Backup's folder so it can be
// restored from WPvivid's own screen. (It was a page of its own in 1.27.0 to 1.30.1; the old address
// redirects here.)
//
// It also answers the disaster-recovery case: a brand-new install that connects with the same site
// key lists the backups Brand Relay already holds for it, because the list lives at Brand Relay and
// is read with the key.
//
// The tab's content is NOT built when the Licence page is drawn. Reading Brand Relay takes a network call,
// and the page's other tabs should not wait for it, so inc/backups/page.js asks for it (the `render` action
// below) the first time the tab is shown. A minute at most is cached.

/**
 * The old page's address, now a tab: send a bookmark there. It must happen on `admin_menu`, not `admin_init`:
 * WordPress refuses an unregistered page ("not allowed to access this page") right after `admin_menu`
 * and before `admin_init` ever runs.
 */
add_action( 'admin_menu', function () {
	if ( isset( $_GET['page'] ) && 'backups-settings' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( admin_url( 'admin.php?page=licence-settings' ) );
		exit;
	}
} );

/** Brand Relay's list of this site's backups, newest first, kept a minute. WP_Error with a plain-words reason when it cannot be read. */
function devbase_backups_remote_list( $force = false ) {
	$cached = get_transient( 'devbase_backups_remote_list' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}

	$list = devbase_backup_api( 'GET', '/v1/site/backups' );

	if ( is_wp_error( $list ) ) {
		return new WP_Error( 'relay', $list->get_error_message() );
	}
	if ( 403 === $list['code'] ) {
		return new WP_Error( 'feature_off', __( 'This site\'s plan does not include backups, so Brand Relay will not list or give back its backups.', 'devbase_parent_theme' ) );
	}
	if ( 200 !== $list['code'] ) {
		/* translators: %d: HTTP status. */
		return new WP_Error( 'relay', sprintf( __( 'Brand Relay answered HTTP %d.', 'devbase_parent_theme' ), $list['code'] ) );
	}

	$backups = array_values( (array) ( $list['data']['backups'] ?? array() ) );
	set_transient( 'devbase_backups_remote_list', $backups, MINUTE_IN_SECONDS );

	return $backups;
}

/**
 * A time from Brand Relay as a timestamp in seconds, or false. It sends epoch MILLISECONDS (1793823580262); an
 * ISO date and plain seconds are understood too.
 *
 * @param mixed $value
 * @return int|false
 */
function devbase_backup_time( $value ) {
	if ( is_numeric( $value ) ) {
		$number = (float) $value;

		return $number > 100000000000 ? (int) floor( $number / 1000 ) : (int) $number;
	}

	return is_string( $value ) && '' !== $value ? strtotime( $value ) : false;
}

/** One plain sentence about the job in progress or the last one. */
function devbase_backup_job_summary( $job = false ) {
	$job = false === $job ? devbase_backup_job() : $job;

	if ( ! $job ) {
		return '';
	}

	$ago = human_time_diff( (int) ( $job['updated_at'] ?? time() ) );

	if ( 'failed' === $job['state'] ) {
		/* translators: 1: what went wrong, 2: how long ago. */
		return sprintf( __( 'Failed: %1$s (%2$s ago)', 'devbase_parent_theme' ), $job['error'], $ago );
	}

	if ( 'done' === $job['state'] ) {
		/* translators: %s: how long ago. */
		if ( 'download' === $job['type'] ) {
			return devbase_wpvivid_ui_hidden()
				? sprintf( __( 'Downloaded %s ago. It is ready to restore: ask your developer to restore it.', 'devbase_parent_theme' ), $ago )
				: sprintf( __( 'Downloaded %s ago. Open WPvivid Backup, choose it under Backups & Restore and press Restore.', 'devbase_parent_theme' ), $ago );
		}

		return sprintf( __( 'Backed up and sent to Brand Relay %s ago.', 'devbase_parent_theme' ), $ago );
	}

	if ( 'download' === $job['type'] ) {
		return __( 'Downloading the backup from Brand Relay…', 'devbase_parent_theme' );
	}

	switch ( $job['state'] ) {
		case 'new':
			return __( 'Starting…', 'devbase_parent_theme' );
		case 'engine':
			return __( 'WPvivid Backup is making the backup…', 'devbase_parent_theme' );
		case 'declare':
			return __( 'Preparing to send the backup to Brand Relay…', 'devbase_parent_theme' );
		case 'uploading':
			$total = 0;
			$sent  = 0;
			foreach ( (array) ( $job['uploads'] ?? array() ) as $name => $upload ) {
				$total += (int) $upload['parts'];
				$sent  += max( 0, (int) ( $job['next_part'][ $name ] ?? 1 ) - 1 );
			}

			/* translators: 1: parts sent, 2: parts in all. */
			return sprintf( __( 'Sending to Brand Relay: part %1$d of %2$d…', 'devbase_parent_theme' ), min( $sent + 1, max( $total, 1 ) ), $total );
		default:
			return __( 'Finishing…', 'devbase_parent_theme' );
	}
}

/** The page. */
function devbase_backups_page_html() {
	if ( ! devbase_relay_enabled() ) {
		return '<div class="notice notice-info inline"><p>' . esc_html__( 'This site is not connected to the management service yet, so there are no backups to show. Your developer can set that up.', 'devbase_parent_theme' ) . '</p></div>';
	}

	if ( ! devbase_relay_service_in_force( 'backup' ) ) {
		// Calm and plain: nothing is wrong, it is simply not part of the plan, and there is a way forward.
		return '<div class="notice notice-info inline"><p>' . esc_html__( 'Automatic backups are not part of this site\'s plan at the moment. If you would like this site backed up regularly, please get in touch with your developer and they will set it up.', 'devbase_parent_theme' ) . '</p></div>';
	}

	$html = '<div id="devbase-backups">';

	$ready = devbase_backup_engine_ready();
	if ( true !== $ready ) {
		$html .= '<div class="notice notice-warning inline"><p>' . esc_html( $ready ) . '</p></div>';
	}

	$job    = devbase_backup_job();
	$active = devbase_backup_job_active( $job );
	$last   = (int) get_option( DEVBASE_BACKUP_LAST, 0 );

	$html .= '<p><button type="button" class="button button-primary" data-devbase-backup="start_backup"' . ( $active ? ' disabled' : '' ) . '>' . esc_html__( 'Back up now', 'devbase_parent_theme' ) . '</button> '
		. '<button type="button" class="button" data-devbase-backup="refresh">' . esc_html__( 'Refresh the list', 'devbase_parent_theme' ) . '</button> '
		. '<span id="devbase-backup-status" data-active="' . ( $active ? '1' : '0' ) . '" style="display:inline-block;vertical-align:middle;margin-left:8px;">' . esc_html( devbase_backup_job_summary( $job ) ) . '</span></p>';

	$html .= '<p class="description">' . ( $last
		/* translators: %s: how long ago. */
		? esc_html( sprintf( __( 'Last backup sent to Brand Relay: %s ago.', 'devbase_parent_theme' ), human_time_diff( $last ) ) )
		: esc_html__( 'No backup has been sent from this installation yet.', 'devbase_parent_theme' ) ) . '</p>';

	$list = devbase_backups_remote_list();

	if ( is_wp_error( $list ) ) {
		$html .= '<div class="notice notice-error inline"><p>' . esc_html( $list->get_error_message() ) . '</p></div>';
	} elseif ( ! $list ) {
		$html .= '<p>' . esc_html__( 'Brand Relay holds no backups for this site yet.', 'devbase_parent_theme' ) . '</p>';
	} else {
		$html .= '<p>' . esc_html( sprintf(
			/* translators: %d: how many backups. */
			_n( 'Brand Relay holds %d backup for this site, including any made before this installation.', 'Brand Relay holds %d backups for this site, including any made before this installation.', count( $list ), 'devbase_parent_theme' ),
			count( $list )
		) ) . '</p>';

		$html .= '<table class="widefat striped" style="max-width:820px"><thead><tr><th>' . esc_html__( 'Backup', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Date', 'devbase_parent_theme' ) . '</th><th>' . esc_html__( 'Size', 'devbase_parent_theme' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $list as $backup ) {
			$done = devbase_backup_is_complete( $backup );
			$when = ! empty( $backup['completed_at'] ) ? $backup['completed_at'] : ( $backup['created_at'] ?? '' );
			$time = devbase_backup_time( $when );

			$html .= '<tr><td>' . esc_html( (string) ( $backup['label'] ?? $backup['id'] ) ) . ( $done ? '' : ' <em>(' . esc_html( (string) ( $backup['status'] ?? '' ) ) . ')</em>' ) . '</td>'
				. '<td>' . esc_html( $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '—' ) . '</td>'
				. '<td>' . esc_html( size_format( (int) ( $backup['size'] ?? 0 ) ) ) . '</td>'
				. '<td>' . ( $done ? '<button type="button" class="button" data-devbase-backup="start_download" data-id="' . esc_attr( $backup['id'] ) . '"' . ( $active || true !== $ready ? ' disabled' : '' ) . '>' . esc_html__( 'Download to WPvivid', 'devbase_parent_theme' ) . '</button>' : '' ) . '</td></tr>';
		}

		$html .= '</tbody></table>';
	}

	$html .= '<p class="description" style="max-width:820px;margin-top:14px;">' . ( devbase_wpvivid_ui_hidden()
		? esc_html__( 'To restore a backup, press Download to WPvivid and ask your developer to restore it.', 'devbase_parent_theme' )
		: wp_kses(
			sprintf(
				/* translators: %s: link to the WPvivid page. */
				__( 'To restore: press <strong>Download to WPvivid</strong>, then open %s, choose that backup under <em>Backups &amp; Restore</em> and press <strong>Restore</strong>. WPvivid does the restore itself.', 'devbase_parent_theme' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=WPvivid' ) ) . '">WPvivid Backup</a>'
			),
			array( 'strong' => array(), 'em' => array(), 'a' => array( 'href' => array() ) )
		) ) . '</p></div>';

	return $html;
}

/** The page's buttons. */
add_action( 'wp_ajax_devbase_backups_action', function () {
	check_ajax_referer( 'devbase_backups' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'devbase_parent_theme' ) ) );
	}

	$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

	if ( 'render' === $action ) {
		wp_send_json_success( array( 'html' => devbase_backups_page_html() ) );
	}

	if ( 'start_backup' === $action ) {
		$result = devbase_backup_run();

		if ( 'started' !== $result['status'] ) {
			wp_send_json_error( array( 'message' => '' !== $result['detail'] ? ucfirst( $result['detail'] ) . '.' : __( 'A backup is already running.', 'devbase_parent_theme' ) ) );
		}
	} elseif ( 'start_download' === $action ) {
		$job = devbase_backup_start_download( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '' );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
	} elseif ( 'refresh' === $action ) {
		delete_transient( 'devbase_backups_remote_list' );
	}

	wp_send_json_success( array(
		'active'  => devbase_backup_job_active(),
		'message' => devbase_backup_job_summary(),
	) );
} );

add_action( 'admin_enqueue_scripts', function () {
	if ( ! isset( $_GET['page'] ) || 'licence-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	wp_enqueue_script( 'devbase-backups', get_template_directory_uri() . '/inc/backups/page.js', array(), wp_get_theme( get_template() )->get( 'Version' ), true );
	wp_localize_script( 'devbase-backups', 'devbaseBackups', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'devbase_backups' ),
		'error'   => __( 'That did not work. Try again.', 'devbase_parent_theme' ),
	) );
} );
