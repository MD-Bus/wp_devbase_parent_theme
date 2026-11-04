<?php

// Backups. Brand Relay asks (its signed ping names `backup`), WPvivid Backup makes the backup,
// and THIS file moves it to Brand Relay and, the other way, brings one back. See
// inc/backups/AGENT.md for the whole story.
//
// A backup is a JOB (one option, devbase_backup_job) that moves through states, a little at a
// time, because no web request can be trusted to live long enough for a 5 GB site:
//
//   backup:    new -> engine -> declare -> uploading -> completing -> done | failed
//   download:  downloading -> done | failed
//
// Each step runs in a signed loopback request to admin-ajax.php (WPvivid only sets itself up
// in an admin request, so the backup MUST start there), does as much as fits in a time budget,
// and starts the next one when it runs out. WP-Cron is only the safety net (and WPvivid's own
// resume events need it). The site never delays a run of its own: when Brand Relay asks, it runs.

/** The one job in progress or last finished: { id, type, state, task_id, relay_id, files, ... }. Not autoloaded. */
const DEVBASE_BACKUP_JOB = 'devbase_backup_job';

/** When the last backup was completed at Brand Relay (a timestamp); reported in the health report. */
const DEVBASE_BACKUP_LAST = 'devbase_backup_last_at';

/**
 * The largest slice of the site's files, in MB, WPvivid may put in one archive file for OUR backups.
 * Not larger: its zip engine (PclZip) has no ZIP64, so an archive cannot pass 4 GiB, and 4080 MB of
 * source files compress to less than that (it is also WPvivid's own biggest preset). Brand Relay takes
 * at most 10 files per backup, so this covers sites of roughly 36 GB; past that the backup is refused
 * up front with a plain message instead of failing at the end.
 */
const DEVBASE_BACKUP_PART_MB = 4080;

/** Brand Relay's limit on the number of files in one backup. */
const DEVBASE_BACKUP_MAX_FILES = 10;

/** The single-step lock. */
const DEVBASE_BACKUP_LOCK = 'devbase_backup_step_lock';

/** The WP-Cron safety net. */
const DEVBASE_BACKUP_CRON = 'devbase_backup_poll';

/** The saved job, or null. */
function devbase_backup_job() {
	$job = get_option( DEVBASE_BACKUP_JOB );

	return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
}

/** Saves a job (and stamps it) and returns it. */
function devbase_backup_save( array $job ) {
	$job['updated_at'] = time();
	update_option( DEVBASE_BACKUP_JOB, $job, false );

	return $job;
}

/** Whether a job is under way. One that has not moved for six hours is stuck, not active. */
function devbase_backup_job_active( $job = false ) {
	$job = false === $job ? devbase_backup_job() : $job;

	return is_array( $job ) && ! in_array( $job['state'] ?? '', array( 'done', 'failed' ), true ) && time() - (int) ( $job['updated_at'] ?? 0 ) < 6 * HOUR_IN_SECONDS;
}

/** A fresh job, saved. */
function devbase_backup_new_job( $type, array $extra = array() ) {
	return devbase_backup_save( array_merge( array(
		'id'         => wp_generate_password( 16, false ),
		'type'       => $type,
		'state'      => 'download' === $type ? 'downloading' : 'new',
		'created_at' => time(),
		'attempts'   => 0,
		'error'      => '',
	), $extra ) );
}

/** The folder WPvivid keeps its backups in (with a trailing slash). */
function devbase_backup_dir() {
	$local = (array) get_option( 'wpvivid_local_setting', array() );
	$dir   = ! empty( $local['path'] ) ? trim( (string) $local['path'], '/\\' ) : 'wpvividbackups';

	return (string) apply_filters( 'devbase/backup/dir', trailingslashit( WP_CONTENT_DIR ) . $dir . '/' );
}

/** Seconds one step may work before handing on to the next. */
function devbase_backup_budget() {
	return max( 1, (int) apply_filters( 'devbase/backup/step_budget', 20 ) );
}

// --- starting: the ping --------------------------------------------------------------------------

/**
 * Whether the engine (WPvivid Backup) is there to use: true, or the reason it is not. It installs it
 * on the spot when the plan has just started including backups and no admin has loaded a page yet.
 *
 * @return true|string
 */
function devbase_backup_engine_ready() {
	$ready = apply_filters( 'devbase/backup/engine_ready', null );
	if ( null !== $ready ) {
		return true === $ready ? true : (string) $ready;
	}

	$def = devbase_bundled_plugins()['wpvivid-backuprestore'] ?? null;
	if ( ! $def ) {
		return 'WPvivid Backup is not part of this theme.';
	}

	if ( ! devbase_bundled_plugin_is_active( $def['file'] ) && devbase_bundled_plugins_pending() ) {
		devbase_sync_bundled_plugins();
	}

	if ( devbase_bundled_plugin_is_active( $def['file'] ) ) {
		return true;
	}

	$errors = (array) get_option( DEVBASE_BUNDLED_PLUGINS_ERRORS, array() );

	return (string) ( $errors['wpvivid-backuprestore'] ?? 'WPvivid Backup is not installed on this site yet.' );
}

/**
 * The `backup` service of a Brand Relay ping: start a backup job and say so. The work happens after
 * the answer, in loopback requests; the answer is `started`, and how it ends is on the Backups page,
 * in Brand Relay's own backup list and in the health report's `last_backup_at`.
 *
 * @return array{status: string, detail: string}
 */
function devbase_backup_run() {
	if ( devbase_backup_job_active() ) {
		return array( 'status' => 'idle', 'detail' => 'a backup or a download is already running' );
	}

	$ready = devbase_backup_engine_ready();
	if ( true !== $ready ) {
		return array( 'status' => 'error', 'detail' => $ready );
	}

	if ( devbase_wpvivid_is_busy() ) {
		return array( 'status' => 'error', 'detail' => 'WPvivid Backup is busy with another task' );
	}

	devbase_backup_new_job( 'backup' );
	devbase_backup_spawn();

	return array( 'status' => 'started', 'detail' => '' );
}

// --- the loopback and the clock ------------------------------------------------------------------

/** What proves a loopback request is ours. */
function devbase_backup_token( $job_id ) {
	return hash_hmac( 'sha256', 'devbase-backup|' . $job_id, wp_salt( 'auth' ) );
}

/**
 * Starts the next step in a request of its own, without waiting for it. Also sets the WP-Cron safety
 * net, for a host that blocks the site from calling itself.
 */
function devbase_backup_spawn() {
	$job = devbase_backup_job();
	if ( ! $job ) {
		return;
	}

	$args = array(
		'timeout'   => 0.01,
		'blocking'  => false,
		'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		'body'      => array(
			'action' => 'devbase_backup_step',
			'job'    => $job['id'],
			'token'  => devbase_backup_token( $job['id'] ),
		),
	);

	devbase_backup_schedule_poll( 120 );

	if ( false === apply_filters( 'devbase/backup/spawn', true, $args ) ) {
		return; // Tests.
	}

	wp_remote_post( admin_url( 'admin-ajax.php' ), $args );
}

/** (Re)sets the single WP-Cron event that looks at the job again. */
function devbase_backup_schedule_poll( $seconds ) {
	wp_clear_scheduled_hook( DEVBASE_BACKUP_CRON );
	wp_schedule_single_event( time() + (int) $seconds, DEVBASE_BACKUP_CRON );
}

/** The loopback request: checks the token, then runs steps until the budget is used. */
function devbase_backup_ajax_step() {
	ignore_user_abort( true );
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$job   = devbase_backup_job();
	$given = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$id    = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( ! $job || $job['id'] !== $id || ! hash_equals( devbase_backup_token( $job['id'] ), $given ) ) {
		status_header( 403 );
		die();
	}

	devbase_backup_step( true );
	die();
}
add_action( 'wp_ajax_nopriv_devbase_backup_step', 'devbase_backup_ajax_step' );
add_action( 'wp_ajax_devbase_backup_step', 'devbase_backup_ajax_step' );

/** WP-Cron: if the job has not moved for a while (the loopback never came, or a step died), move it. */
add_action( DEVBASE_BACKUP_CRON, function () {
	$job = devbase_backup_job();

	if ( devbase_backup_job_active( $job ) && time() - (int) $job['updated_at'] >= 90 ) {
		devbase_backup_step( false );
	}
} );

/** One step lock; a lock older than ten minutes is stale. */
function devbase_backup_lock() {
	if ( add_option( DEVBASE_BACKUP_LOCK, time(), '', false ) ) {
		return true;
	}

	if ( time() - (int) get_option( DEVBASE_BACKUP_LOCK ) > 10 * MINUTE_IN_SECONDS ) {
		delete_option( DEVBASE_BACKUP_LOCK );

		return add_option( DEVBASE_BACKUP_LOCK, time(), '', false );
	}

	return false;
}

function devbase_backup_unlock() {
	delete_option( DEVBASE_BACKUP_LOCK );
}

/**
 * Runs the job forward until the budget is used, the job waits, or it ends. Safe to call from
 * anywhere (`wp eval 'devbase_backup_step( true );'`); one at a time.
 *
 * Each handler answers `next` (go on now), `chain` (out of time: start another request),
 * `wait` (nothing to do for a minute) or `stop` (finished or failed).
 */
function devbase_backup_step( $admin_request = false ) {
	if ( ! devbase_backup_job_active() || ! devbase_backup_lock() ) {
		return devbase_backup_job();
	}

	$deadline = time() + devbase_backup_budget();
	$outcome  = 'stop';

	try {
		for ( $i = 0; $i < 200; $i++ ) {
			$job = devbase_backup_job();
			if ( ! devbase_backup_job_active( $job ) ) {
				$outcome = 'stop';
				break;
			}

			$outcome = 'download' === $job['type']
				? devbase_backup_download_step( $job, $deadline )
				: devbase_backup_backup_step( $job, $deadline, $admin_request );

			if ( 'next' !== $outcome ) {
				break;
			}
		}
	} finally {
		devbase_backup_unlock();
	}

	if ( 'chain' === $outcome ) {
		devbase_backup_spawn();
	} elseif ( 'wait' === $outcome ) {
		devbase_backup_schedule_poll( 60 );
	}

	return devbase_backup_job();
}

// --- the backup job ------------------------------------------------------------------------------

/** WPvivid's backup() always ends with die(); this carries the job on afterwards. */
function devbase_backup_after_engine() {
	devbase_backup_step( true );
}

/**
 * Makes WPvivid prepare a backup task: files and database, kept on this site only (never sent to
 * ITS remote storage), merged into one archive, and without running ITS retention (their local
 * backups are not ours to delete).
 *
 * @return string|WP_Error The task id.
 */
function devbase_backup_engine_prepare() {
	global $wpvivid_plugin;

	if ( ! class_exists( 'WPvivid_Public_Interface' ) || ! is_object( $wpvivid_plugin ) ) {
		return new WP_Error( 'engine', 'WPvivid Backup did not load in this request.' );
	}

	add_filter( 'wpvivid_need_clean_oldest_backup', '__return_false' );

	// The split size is read once, when the task is made, and saved in the task: raising it only while we
	// prepare changes OUR backup and never the client's own WPvivid setting.
	add_filter( 'option_wpvivid_compress_setting', 'devbase_backup_raise_part_size' );
	add_filter( 'wpvivid_get_backup_exclude_regex', 'devbase_backup_exclude_content', 20, 2 );

	try {
		$interface = new WPvivid_Public_Interface();
		$result    = $interface->prepare_backup( array(
			'backup_files' => 'files+db',
			'local'        => '1',
			'remote'       => '0',
			'ismerge'      => '1',
			'lock'         => '0',
		) );
	} finally {
		remove_filter( 'option_wpvivid_compress_setting', 'devbase_backup_raise_part_size' );
		remove_filter( 'wpvivid_get_backup_exclude_regex', 'devbase_backup_exclude_content', 20 );
	}

	if ( empty( $result['task_id'] ) ) {
		return new WP_Error( 'prepare', (string) ( $result['error'] ?? 'WPvivid Backup could not prepare the backup.' ) );
	}

	// WPvivid's "files+db" always adds a copy of WordPress itself (wp-admin, wp-includes, about 95 MB raw,
	// 23 MB zipped). A restore never needs it, so take that part out of the task before it runs.
	if ( class_exists( 'WPvivid_taskmanager' ) ) {
		$task = WPvivid_taskmanager::get_task( $result['task_id'] );
		if ( is_array( $task ) && isset( $task['options']['backup_options'] ) ) {
			WPvivid_taskmanager::update_task_options( $result['task_id'], 'backup_options', devbase_backup_without_core( $task['options']['backup_options'] ) );
		}
	}

	// How many files that makes, from WPvivid's own size estimate: refuse now, not after the work is done.
	$bytes = (int) ( $result['check']['file_size']['sum_size'] ?? 0 );
	if ( devbase_backup_files_needed( $bytes ) > DEVBASE_BACKUP_MAX_FILES ) {
		if ( class_exists( 'WPvivid_taskmanager' ) ) {
			WPvivid_taskmanager::delete_ready_task();
		}

		return new WP_Error( 'too_large', sprintf(
			'This site (%s) would need more than %d files, which is more than Brand Relay takes in one backup.',
			size_format( $bytes ),
			DEVBASE_BACKUP_MAX_FILES
		) );
	}

	return (string) $result['task_id'];
}

/**
 * What our backup is: the database and wp-content (themes, plugins, uploads and the rest). WordPress core
 * is not part of it, so the part WPvivid adds for it is removed.
 *
 * @param array $options A WPvivid task's backup_options.
 * @return array
 */
function devbase_backup_without_core( $options ) {
	unset( $options['backup']['backup_core'] );

	return $options;
}

/**
 * Folders of wp-content that are never worth keeping. WPvivid's own list for the "content" part skips other
 * backup plugins' folders, the cache and `upgrade`, but not these: a leftover of a failed update, WPvivid's
 * own working folder and the debug log (which only ever grows).
 *
 * @param array  $exclude Regexes WPvivid already excludes.
 * @param string $type    The part being listed.
 * @return array
 */
function devbase_backup_exclude_content( $exclude, $type ) {
	if ( 'backup_content' !== $type ) {
		return $exclude;
	}

	$root = str_replace( '\\', '/', WP_CONTENT_DIR ) . '/';
	foreach ( array( 'upgrade-temp-backup', 'wpvivid_uploads', 'debug.log' ) as $name ) {
		$exclude[] = '#^' . preg_quote( $root . $name, '/' ) . '#';
	}

	return $exclude;
}

/** The compress setting with the split size raised to the most WPvivid's zip engine can write. */
function devbase_backup_raise_part_size( $setting ) {
	$setting                  = is_array( $setting ) ? $setting : array();
	$setting['max_file_size'] = (string) DEVBASE_BACKUP_PART_MB;

	return $setting;
}

/** How many archive files a site of this many source bytes comes to (the database rides in the last one). */
function devbase_backup_files_needed( $bytes ) {
	return max( 1, (int) ceil( (int) $bytes / ( DEVBASE_BACKUP_PART_MB * MB_IN_BYTES ) ) );
}

/**
 * Where a WPvivid task stands.
 *
 * @return string completed | running | missing | the error text
 */
function devbase_backup_engine_status( $task_id ) {
	$task = ( (array) get_option( 'wpvivid_task_list', array() ) )[ $task_id ] ?? null;

	if ( ! is_array( $task ) ) {
		return 'missing';
	}

	$status = (string) ( $task['status']['str'] ?? '' );

	if ( 'completed' === $status ) {
		return 'completed';
	}

	if ( in_array( $status, array( 'running', 'no_responds', 'ready', 'waiting' ), true ) ) {
		return 'running';
	}

	return (string) ( $task['status']['error'] ?? ( '' !== $status ? $status : 'unknown' ) );
}

/**
 * The files of a finished WPvivid backup: name, size, path.
 *
 * @return array[]|WP_Error
 */
function devbase_backup_engine_files( $task_id ) {
	$entry = ( (array) get_option( 'wpvivid_backup_list', array() ) )[ $task_id ] ?? null;
	$list  = is_array( $entry ) ? (array) ( $entry['backup']['files'] ?? array() ) : array();
	$files = array();

	foreach ( $list as $file ) {
		$name = (string) ( $file['file_name'] ?? '' );
		$path = devbase_backup_dir() . $name;

		if ( '' === $name || ! is_readable( $path ) ) {
			return new WP_Error( 'missing_file', 'WPvivid finished, but its backup file is missing: ' . $name );
		}

		$files[] = array( 'name' => $name, 'size' => (int) filesize( $path ), 'path' => $path );
	}

	return $files ? $files : new WP_Error( 'no_files', 'WPvivid finished without a backup file.' );
}

/** Whether one entry of Brand Relay's list is a finished backup (it says `complete`; older notes said `completed`). */
function devbase_backup_is_complete( $backup ) {
	return in_array( $backup['status'] ?? '', array( 'complete', 'completed' ), true );
}

/** Ends a job badly: abort at Brand Relay, remove our local copy, say why. */
function devbase_backup_fail( array $job, $message ) {
	if ( ! empty( $job['relay_id'] ) ) {
		devbase_backup_api( 'POST', '/v1/site/backups/' . rawurlencode( $job['relay_id'] ) . '/abort' );
	}

	if ( 'backup' === $job['type'] ) {
		devbase_backup_delete_local( $job );
	} else {
		devbase_backup_discard_download( $job );
	}

	$job['state'] = 'failed';
	$job['error'] = (string) $message;
	devbase_backup_save( $job );
	devbase_backup_note( 'error', (string) $message );

	return 'stop';
}

/**
 * A download that fails removes everything it put in WPvivid's folder: the half file AND the parts that
 * had already arrived. WPvivid files parts of one backup together by their task id and would list a set with
 * missing parts as a backup (it does not flag it incomplete), so a later rescan must never find one.
 */
function devbase_backup_discard_download( array $job ) {
	foreach ( (array) ( $job['files'] ?? array() ) as $file ) {
		foreach ( array( $file['name'], $file['name'] . '.part' ) as $name ) {
			$path = devbase_backup_dir() . $name;

			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}

/** Removes OUR local copy of a backup (never anything else of WPvivid's): through WPvivid so its list stays right. */
function devbase_backup_delete_local( array $job ) {
	if ( ! empty( $job['task_id'] ) && class_exists( 'WPvivid_Public_Interface' ) && isset( ( (array) get_option( 'wpvivid_backup_list', array() ) )[ $job['task_id'] ] ) ) {
		$interface = new WPvivid_Public_Interface();
		$interface->delete_backup( $job['task_id'], true );
	}

	foreach ( (array) ( $job['files'] ?? array() ) as $file ) {
		if ( ! empty( $file['path'] ) && file_exists( $file['path'] ) && 0 === strpos( $file['path'], devbase_backup_dir() ) ) {
			@unlink( $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}

/** Records the last outcome where the maintenance check keeps its own (System Status shows it). */
function devbase_backup_note( $status, $detail = '' ) {
	$state = devbase_maintenance_state();

	$state['services']['backup'] = array_merge( (array) ( $state['services']['backup'] ?? array() ), array(
		'status'       => $status,
		'detail'       => $detail,
		'attempted_at' => time(),
	) );

	update_option( DEVBASE_MAINTENANCE_STATE, $state, true );
}

/** A failed call to Brand Relay: worth trying again later, or not. */
function devbase_backup_retry_or_fail( array $job, $what, $code = 0 ) {
	if ( in_array( (int) $code, array( 400, 401, 403, 404, 409, 413 ), true ) || (int) $job['attempts'] >= 4 ) {
		return devbase_backup_fail( $job, sprintf( '%s failed (%s).', $what, $code ? 'HTTP ' . $code : 'no answer' ) );
	}

	$job['attempts'] = (int) $job['attempts'] + 1;
	devbase_backup_save( $job );

	return 'wait';
}

/** Brand Relay's own answers to a declaration, in words. */
function devbase_backup_refusal( $code, array $data ) {
	$error = (string) ( $data['error'] ?? '' );

	if ( 403 === $code && 'feature_off' === $error ) {
		return 'This site\'s plan does not include backups.';
	}
	if ( 429 === $code && 'daily_limit' === $error ) {
		return 'Brand Relay allows one backup in the plan\'s interval, and one was already started.';
	}
	if ( 429 === $code ) {
		return 'Brand Relay already has two backups in progress for this site.';
	}
	if ( 413 === $code ) {
		return 'The backup is larger than the plan allows.';
	}

	return sprintf( 'Brand Relay refused the backup (HTTP %d%s).', $code, '' !== $error ? ', ' . $error : '' );
}

/**
 * One state of a backup job.
 *
 * @return string next | chain | wait | stop
 */
function devbase_backup_backup_step( array $job, $deadline, $admin_request ) {
	switch ( $job['state'] ) {
		case 'new':
			if ( ! $admin_request ) {
				return devbase_backup_fail( $job, 'The site could not call itself, which WPvivid Backup needs to start (a host that blocks loopback requests).' );
			}

			$ready = devbase_backup_engine_ready();
			if ( true !== $ready ) {
				return devbase_backup_fail( $job, $ready );
			}

			$task = apply_filters( 'devbase/backup/engine_prepare', null );
			$task = null === $task ? devbase_backup_engine_prepare() : $task;
			if ( is_wp_error( $task ) ) {
				return devbase_backup_fail( $job, $task->get_error_message() );
			}

			$job['task_id'] = $task;
			$job['state']   = 'engine';
			devbase_backup_save( $job );

			if ( null === apply_filters( 'devbase/backup/engine_run', null, $task ) ) {
				// backup() ends the request with die(), which skips our own cleanup: release the lock first
				// and carry on from a shutdown function.
				devbase_backup_unlock();
				register_shutdown_function( 'devbase_backup_after_engine' );

				global $wpvivid_plugin;
				$wpvivid_plugin->backup( $task );
			}

			return 'next';

		case 'engine':
			$status = devbase_backup_engine_status( $job['task_id'] );

			if ( 'completed' === $status ) {
				$job['state'] = 'declare';
				devbase_backup_save( $job );

				return 'next';
			}

			if ( 'running' === $status ) {
				// WPvivid resumes itself (its own cron events); look again in a minute. Three hours is too long.
				return time() - (int) $job['created_at'] > 3 * HOUR_IN_SECONDS ? devbase_backup_fail( $job, 'WPvivid Backup did not finish in three hours.' ) : 'wait';
			}

			return devbase_backup_fail( $job, 'WPvivid Backup failed: ' . $status );

		case 'declare':
			$files = devbase_backup_engine_files( $job['task_id'] );
			if ( is_wp_error( $files ) ) {
				return devbase_backup_fail( $job, $files->get_error_message() );
			}

			foreach ( $files as &$file ) {
				$file['sha256'] = (string) hash_file( 'sha256', $file['path'] );
			}
			unset( $file );

			// Known to the job before anything can go wrong, so a failure can remove our local copy.
			$job['files'] = $files;
			devbase_backup_save( $job );

			if ( count( $files ) > DEVBASE_BACKUP_MAX_FILES ) {
				return devbase_backup_fail( $job, sprintf( 'WPvivid made %d files, and Brand Relay takes at most %d in one backup.', count( $files ), DEVBASE_BACKUP_MAX_FILES ) );
			}

			$declared = devbase_backup_api( 'POST', '/v1/site/backups', array(
				'label' => 'Backup ' . gmdate( 'Y-m-d H:i' ) . ' UTC',
				'files' => array_map( function ( $f ) {
					return array( 'name' => $f['name'], 'size' => $f['size'], 'sha256' => $f['sha256'] );
				}, $files ),
			) );

			if ( is_wp_error( $declared ) || 5 === (int) floor( $declared['code'] / 100 ) ) {
				return devbase_backup_retry_or_fail( $job, 'Declaring the backup', is_wp_error( $declared ) ? 0 : $declared['code'] );
			}

			$backup = $declared['data']['backup'] ?? null;
			if ( 201 !== $declared['code'] || ! is_array( $backup ) ) {
				return devbase_backup_fail( $job, devbase_backup_refusal( $declared['code'], $declared['data'] ) );
			}

			$uploads = array();
			foreach ( (array) $backup['files'] as $file ) {
				$uploads[ $file['name'] ] = array( 'upload' => $file['upload'], 'parts' => (int) $file['parts'] );
			}

			$job['relay_id']  = $backup['id'];
			$job['part_size'] = (int) $backup['part_size'];
			$job['complete']  = $backup['complete'];
			$job['uploads']   = $uploads;
			$job['next_part'] = array_fill_keys( array_keys( $uploads ), 1 );
			$job['attempts']  = 0;
			$job['state']     = 'uploading';
			devbase_backup_save( $job );

			return 'next';

		case 'uploading':
			foreach ( $job['files'] as $file ) {
				$total = (int) $job['uploads'][ $file['name'] ]['parts'];
				$part  = (int) $job['next_part'][ $file['name'] ];

				while ( $part <= $total ) {
					if ( time() >= $deadline ) {
						return 'chain';
					}

					$offset = ( $part - 1 ) * $job['part_size'];
					$length = min( $job['part_size'], $file['size'] - $offset );
					$path   = str_replace( '{n}', (string) $part, $job['uploads'][ $file['name'] ]['upload'] );
					$code   = devbase_backup_put_part( $path, $file['path'], $offset, $length );

					if ( 200 !== $code ) {
						return devbase_backup_retry_or_fail( $job, sprintf( 'Sending part %d of %s', $part, $file['name'] ), is_int( $code ) ? $code : 0 );
					}

					$job['next_part'][ $file['name'] ] = ++$part;
					$job['attempts']                   = 0;
					devbase_backup_save( $job );
				}
			}

			$job['state'] = 'completing';
			devbase_backup_save( $job );

			return 'next';

		case 'completing':
			$done = devbase_backup_api( 'POST', $job['complete'] );

			if ( is_wp_error( $done ) || 5 === (int) floor( $done['code'] / 100 ) ) {
				return devbase_backup_retry_or_fail( $job, 'Completing the backup', is_wp_error( $done ) ? 0 : $done['code'] );
			}

			if ( 200 !== $done['code'] ) {
				return devbase_backup_fail( $job, sprintf( 'Brand Relay would not complete the backup (HTTP %d, %s).', $done['code'], (string) ( $done['data']['error'] ?? '' ) ) );
			}

			update_option( DEVBASE_BACKUP_LAST, time(), false );
			devbase_backup_delete_local( $job );

			$job['state'] = 'done';
			devbase_backup_save( $job );
			devbase_backup_note( 'ok' );

			return 'stop';
	}

	return devbase_backup_fail( $job, 'The backup job is in an unknown state: ' . $job['state'] );
}

// --- the download job (restore: bring a backup back into WPvivid's folder) -----------------------

/**
 * Starts bringing a stored backup back: its files go into WPvivid's backup folder, and WPvivid is then
 * told to rescan that folder, so the backup shows in WPvivid's own Backup & Restore list ready for
 * its Restore button. Nothing is restored here.
 *
 * @param string $relay_id Brand Relay's id of the backup.
 * @return array|WP_Error The job.
 */
function devbase_backup_start_download( $relay_id ) {
	if ( devbase_backup_job_active() ) {
		return new WP_Error( 'busy', 'A backup or a download is already running.' );
	}

	$ready = devbase_backup_engine_ready();
	if ( true !== $ready ) {
		return new WP_Error( 'engine', $ready );
	}

	$list = devbase_backup_api( 'GET', '/v1/site/backups' );
	if ( is_wp_error( $list ) ) {
		return new WP_Error( 'relay', $list->get_error_message() );
	}
	if ( 200 !== $list['code'] ) {
		return new WP_Error( 'relay', 403 === $list['code'] ? 'This site\'s plan does not include backups, so its backups cannot be listed or downloaded.' : 'Brand Relay answered HTTP ' . $list['code'] . '.' );
	}

	foreach ( (array) ( $list['data']['backups'] ?? array() ) as $backup ) {
		if ( $backup['id'] !== $relay_id || ! devbase_backup_is_complete( $backup ) ) {
			continue;
		}

		$files = array();
		$total = 0;
		foreach ( (array) $backup['files'] as $file ) {
			$files[] = array( 'name' => (string) $file['name'], 'size' => (int) $file['size'] );
			$total  += (int) $file['size'];
		}

		wp_mkdir_p( devbase_backup_dir() );
		$free = @disk_free_space( devbase_backup_dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $free < $total + 100 * MB_IN_BYTES ) {
			return new WP_Error( 'disk', sprintf( 'Not enough free disk space: %s needed, %s free.', size_format( $total ), size_format( $free ) ) );
		}

		$job = devbase_backup_new_job( 'download', array(
			'relay_id' => $relay_id,
			'label'    => (string) ( $backup['label'] ?? '' ),
			'files'    => $files,
			'sums'     => array(),
		) );
		devbase_backup_spawn();

		return $job;
	}

	return new WP_Error( 'not_found', 'That backup is not in Brand Relay\'s list (or it is not finished).' );
}

/**
 * The download job: each file in 50 MiB ranges, resumable (a half file is kept as .part), checked
 * against Brand Relay's checksum, then renamed into place.
 *
 * @return string next | chain | wait | stop
 */
function devbase_backup_download_step( array $job, $deadline ) {
	$dir = devbase_backup_dir();

	foreach ( $job['files'] as $file ) {
		$final = $dir . $file['name'];
		$part  = $final . '.part';

		if ( file_exists( $final ) && filesize( $final ) === $file['size'] ) {
			continue;
		}

		clearstatcache( true, $part );
		$have = file_exists( $part ) ? (int) filesize( $part ) : 0;

		while ( $have < $file['size'] ) {
			if ( time() >= $deadline ) {
				return 'chain';
			}

			$handle = fopen( $part, 'ab' );
			if ( ! $handle ) {
				return devbase_backup_fail( $job, 'The backup folder is not writable.' );
			}

			$headers = array();
			$code    = devbase_backup_get_range(
				'/v1/site/backups/' . rawurlencode( $job['relay_id'] ) . '/files/' . rawurlencode( $file['name'] ),
				$handle,
				$have,
				min( $have + 50 * MB_IN_BYTES, $file['size'] ) - 1,
				$headers
			);
			fclose( $handle );

			if ( 200 !== $code && 206 !== $code ) {
				return devbase_backup_retry_or_fail( $job, 'Downloading ' . $file['name'], is_int( $code ) ? $code : 0 );
			}

			clearstatcache( true, $part );
			$now = (int) filesize( $part );

			if ( $now <= $have || ( 200 === $code && $have > 0 ) ) {
				@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
				return devbase_backup_fail( $job, 'Brand Relay did not answer the range request properly while downloading ' . $file['name'] . '.' );
			}

			if ( ! empty( $headers['x-checksum-sha256'] ) ) {
				$job['sums'][ $file['name'] ] = strtolower( $headers['x-checksum-sha256'] );
			}

			$have            = $now;
			$job['attempts'] = 0;
			devbase_backup_save( $job );
		}

		if ( ! empty( $job['sums'][ $file['name'] ] ) && ! hash_equals( $job['sums'][ $file['name'] ], strtolower( (string) hash_file( 'sha256', $part ) ) ) ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return devbase_backup_fail( $job, $file['name'] . ' did not match its checksum, so it was discarded.' );
		}

		rename( $part, $final );
	}

	// Tell WPvivid to look at its folder again (its own "scan" button does the same).
	if ( has_action( 'wpvivid_rebuild_backup_list' ) ) {
		do_action( 'wpvivid_rebuild_backup_list' );
	}

	$job['state'] = 'done';
	devbase_backup_save( $job );

	return 'stop';
}

// --- wiring --------------------------------------------------------------------------------------

/** The health report's `last_backup_at`. */
add_filter( 'devbase/maintenance/last_backup_at', function ( $value ) {
	$at = (int) get_option( DEVBASE_BACKUP_LAST, 0 );

	return $at ? gmdate( 'c', $at ) : $value;
} );
