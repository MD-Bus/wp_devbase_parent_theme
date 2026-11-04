<?php
// Covers inc/backups/: the backup job (started by Brand Relay's ping, made by WPvivid Backup, declared,
// uploaded in parts, completed, cleaned up), its failures and retries, the resumable download that
// brings a stored backup back, and the guards around them. Brand Relay is faked with pre_http_request
// and the part upload / range download filters; WPvivid is faked with the options it keeps.

class Test_Backups extends WP_UnitTestCase {

	private $dir;
	private $api      = array();   // [ 'METHOD /path' => [ code, body ] ] overrides
	private $calls    = array();   // every Brand Relay call: [ method, path, body ]
	private $parts    = array();   // every part sent: [ path, offset, length ]
	private $put_code = 200;
	private $spawns   = 0;
	private $payload  = '0123456789abcdefghijklmno'; // 25 bytes: three parts of 10, 10, 5
	private $headers  = array();   // headers the fake range download reports
	private $fail_range_after = null; // the fake range download refuses every request after this many
	private $range_calls      = 0;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( ! defined( 'DEVBASE_RELAY_URL' ) ) {
			define( 'DEVBASE_RELAY_URL', 'https://relay.test' );
		}
		if ( ! defined( 'DEVBASE_RELAY_SITE_KEY' ) ) {
			define( 'DEVBASE_RELAY_SITE_KEY', 'brs_testkey' );
		}
	}

	public function set_up() {
		parent::set_up();

		$this->dir = trailingslashit( get_temp_dir() ) . 'devbase-backup-test-' . wp_generate_password( 6, false ) . '/';
		wp_mkdir_p( $this->dir );
		$this->api = $this->calls = $this->parts = $this->headers = array();
		$this->fail_range_after = null;
		$this->range_calls      = 0;
		$this->put_code = 200;
		$this->spawns   = 0;

		$this->reset_options();
		update_option( 'options_hide_wpvivid_ui', '0' ); // these tests are about the page with WPvivid reachable; the hidden wording is in Test_WPvivid_Ui
		devbase_relay_store_plan( array( 'licence' => array( 'id' => 'sub_1', 'plan' => 'Pro', 'status' => 'active', 'services' => array( 'backup' ), 'limits' => array() ) ) );

		add_filter( 'devbase/backup/dir', array( $this, 'dir' ) );
		add_filter( 'devbase/backup/engine_ready', '__return_true' );
		add_filter( 'devbase/backup/engine_prepare', array( $this, 'engine_prepare' ) );
		add_filter( 'devbase/backup/engine_run', '__return_true' );
		add_filter( 'devbase/backup/spawn', array( $this, 'spawn' ) );
		add_filter( 'devbase/backup/put_part', array( $this, 'put_part' ), 10, 5 );
		add_filter( 'devbase/backup/get_range', array( $this, 'get_range' ), 10, 5 );
		add_filter( 'devbase/backup/get_range_headers', array( $this, 'range_headers' ) );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
	}

	public function tear_down() {
		foreach ( array( 'devbase/backup/dir' => 'dir', 'devbase/backup/engine_prepare' => 'engine_prepare', 'devbase/backup/spawn' => 'spawn', 'devbase/backup/range_headers' => 'range_headers' ) as $hook => $method ) {
			remove_filter( $hook, array( $this, $method ) );
		}
		remove_filter( 'devbase/backup/engine_ready', '__return_true' );
		remove_filter( 'devbase/backup/engine_run', '__return_true' );
		remove_filter( 'devbase/backup/put_part', array( $this, 'put_part' ), 10 );
		remove_filter( 'devbase/backup/get_range', array( $this, 'get_range' ), 10 );
		remove_filter( 'devbase/backup/get_range_headers', array( $this, 'range_headers' ) );
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		$this->reset_options();
		delete_option( 'options_hide_wpvivid_ui' );
		$this->delete_dir( $this->dir );
		wp_clear_scheduled_hook( DEVBASE_BACKUP_CRON );

		parent::tear_down();
	}

	private function reset_options() {
		foreach ( array( DEVBASE_BACKUP_JOB, DEVBASE_BACKUP_LAST, DEVBASE_BACKUP_LOCK, DEVBASE_MAINTENANCE_STATE, DEVBASE_RELAY_PLAN_OPTION, 'wpvivid_task_list', 'wpvivid_backup_list' ) as $option ) {
			delete_option( $option );
		}
	}

	private function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	// --- the fakes -----------------------------------------------------------------------------

	public function dir() {
		return $this->dir;
	}

	public function spawn() {
		++$this->spawns;

		return false;
	}

	/** WPvivid "makes" a backup: the file, its task and its list entry. */
	public function engine_prepare() {
		file_put_contents( $this->dir . 'site_backup_all.zip', $this->payload );
		update_option( 'wpvivid_task_list', array( 'task1' => array( 'status' => array( 'str' => 'completed' ) ) ) );
		update_option( 'wpvivid_backup_list', array( 'task1' => array( 'backup' => array( 'files' => array( array( 'file_name' => 'site_backup_all.zip', 'size' => strlen( $this->payload ) ) ) ) ) ) );

		return 'task1';
	}

	public function put_part( $null, $path, $file, $offset, $length ) {
		$this->parts[] = array( $path, $offset, $length, substr( (string) file_get_contents( $file ), $offset, $length ) );

		return $this->put_code;
	}

	public function get_range( $null, $path, $handle, $from, $to ) {
		if ( null !== $this->fail_range_after && ++$this->range_calls > $this->fail_range_after ) {
			return 500; // refused, and nothing written
		}

		fwrite( $handle, substr( $this->payload, $from, $to - $from + 1 ) );

		return 206;
	}

	public function range_headers() {
		return $this->headers;
	}

	public function http( $pre, $args, $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$key  = $args['method'] . ' ' . $path;
		$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;

		$this->calls[] = array( $args['method'], $path, $body );

		if ( isset( $this->api[ $key ] ) ) {
			return array( 'response' => array( 'code' => $this->api[ $key ][0] ), 'body' => wp_json_encode( $this->api[ $key ][1] ), 'headers' => array() );
		}

		if ( 'POST /v1/site/backups' === $key ) {
			$parts = (int) ceil( $body['files'][0]['size'] / 10 );
			$data  = array( 'ok' => true, 'backup' => array(
				'id'        => 'bkp_1',
				'part_size' => 10,
				'files'     => array( array( 'name' => $body['files'][0]['name'], 'size' => $body['files'][0]['size'], 'parts' => $parts, 'upload' => '/v1/site/backups/bkp_1/files/' . $body['files'][0]['name'] . '/parts/{n}' ) ),
				'complete'  => '/v1/site/backups/bkp_1/complete',
			) );

			return array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( $data ), 'headers' => array() );
		}

		if ( 'POST /v1/site/backups/bkp_1/complete' === $key ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ), 'headers' => array() );
		}

		return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
	}

	private function called( $method, $path ) {
		return count( array_filter( $this->calls, function ( $c ) use ( $method, $path ) {
			return $c[0] === $method && $c[1] === $path;
		} ) );
	}

	// --- starting ------------------------------------------------------------------------------

	public function test_a_ping_for_backup_starts_a_job_and_answers_started() {
		$result = devbase_backup_run();

		$this->assertSame( 'started', $result['status'] );
		$this->assertSame( 'new', devbase_backup_job()['state'] );
		$this->assertSame( 1, $this->spawns, 'The next step is started in a request of its own.' );
		$this->assertNotFalse( wp_next_scheduled( DEVBASE_BACKUP_CRON ), 'And WP-Cron is the safety net.' );
	}

	public function test_a_second_ask_while_a_job_runs_answers_idle_and_starts_nothing() {
		devbase_backup_run();
		$job = devbase_backup_job();

		$this->assertSame( 'idle', devbase_backup_run()['status'] );
		$this->assertSame( $job['id'], devbase_backup_job()['id'] );
	}

	public function test_no_engine_is_an_error_not_a_silent_skip() {
		remove_filter( 'devbase/backup/engine_ready', '__return_true' );
		add_filter( 'devbase/backup/engine_ready', function () {
			return 'WPvivid Backup is not installed on this site yet.';
		} );

		$result = devbase_backup_run();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'not installed', $result['detail'] );
		$this->assertNull( devbase_backup_job() );
	}

	public function test_a_task_wpvivid_is_running_itself_is_reported_not_run_over() {
		update_option( 'wpvivid_task_list', array( 'theirs' => array( 'status' => array( 'str' => 'running' ) ) ) );

		$this->assertSame( 'error', devbase_backup_run()['status'] );
		$this->assertNull( devbase_backup_job() );
	}

	public function test_it_is_wired_into_the_maintenance_services_and_the_busy_check() {
		$this->assertSame( 'devbase_backup_run', devbase_maintenance_services()['backup'] );

		$this->assertFalse( devbase_wpvivid_is_busy() );
		devbase_backup_new_job( 'backup' );
		$this->assertTrue( devbase_wpvivid_is_busy(), 'The plugin is never removed from under our own job.' );
	}

	// --- the whole backup ----------------------------------------------------------------------

	public function test_a_backup_runs_from_wpvivid_to_a_completed_backup_at_brand_relay() {
		devbase_backup_new_job( 'backup' );

		$job = devbase_backup_step( true );

		$this->assertSame( 'done', $job['state'], (string) $job['error'] );

		$declared = $this->calls[0];
		$this->assertSame( array( 'POST', '/v1/site/backups' ), array( $declared[0], $declared[1] ) );
		$this->assertSame( 'site_backup_all.zip', $declared[2]['files'][0]['name'] );
		$this->assertSame( strlen( $this->payload ), $declared[2]['files'][0]['size'] );
		$this->assertSame( hash( 'sha256', $this->payload ), $declared[2]['files'][0]['sha256'] );

		$this->assertSame( array( array( 0, 10 ), array( 10, 10 ), array( 20, 5 ) ), array_map( function ( $p ) {
			return array( $p[1], $p[2] );
		}, $this->parts ), 'Parts of exactly the declared size, the last one the rest.' );
		$this->assertStringEndsWith( '/parts/1', $this->parts[0][0] );
		$this->assertSame( $this->payload, implode( '', array_column( $this->parts, 3 ) ) );

		$this->assertSame( 1, $this->called( 'POST', '/v1/site/backups/bkp_1/complete' ) );
		$this->assertGreaterThan( time() - 5, (int) get_option( DEVBASE_BACKUP_LAST ) );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.zip', 'Our local copy is removed once it is safe at Brand Relay.' );
		$this->assertSame( 'ok', devbase_maintenance_state()['services']['backup']['status'] );
		$this->assertArrayHasKey( 'last_backup_at', devbase_heartbeat_report() );
	}

	public function test_a_step_that_is_not_a_loopback_request_cannot_start_the_engine() {
		devbase_backup_new_job( 'backup' );

		$job = devbase_backup_step( false );

		$this->assertSame( 'failed', $job['state'] );
		$this->assertStringContainsString( 'call itself', $job['error'] );
		$this->assertSame( array(), $this->calls );
	}

	public function test_a_wpvivid_error_fails_the_job_with_its_message_and_declares_nothing() {
		devbase_backup_new_job( 'backup' );
		remove_filter( 'devbase/backup/engine_prepare', array( $this, 'engine_prepare' ) );
		add_filter( 'devbase/backup/engine_prepare', function () {
			update_option( 'wpvivid_task_list', array( 'task1' => array( 'status' => array( 'str' => 'error', 'error' => 'Disk full' ) ) ) );

			return 'task1';
		} );

		$job = devbase_backup_step( true );

		$this->assertSame( 'failed', $job['state'] );
		$this->assertStringContainsString( 'Disk full', $job['error'] );
		$this->assertSame( 0, $this->called( 'POST', '/v1/site/backups' ) );
		$this->assertSame( 'error', devbase_maintenance_state()['services']['backup']['status'] );
	}

	public function test_a_backup_still_running_waits_and_does_not_fail() {
		devbase_backup_new_job( 'backup', array( 'state' => 'engine', 'task_id' => 'task1' ) );
		update_option( 'wpvivid_task_list', array( 'task1' => array( 'status' => array( 'str' => 'running' ) ) ) );

		$job = devbase_backup_step( true );

		$this->assertSame( 'engine', $job['state'] );
		$this->assertNotFalse( wp_next_scheduled( DEVBASE_BACKUP_CRON ), 'Looked at again in a minute.' );
	}

	// --- Brand Relay says no -------------------------------------------------------------------

	public function test_the_plans_daily_limit_fails_the_job_in_words_and_removes_the_local_copy() {
		$this->api['POST /v1/site/backups'] = array( 429, array( 'error' => 'daily_limit' ) );
		devbase_backup_new_job( 'backup' );

		$job = devbase_backup_step( true );

		$this->assertSame( 'failed', $job['state'] );
		$this->assertStringContainsString( 'one backup', $job['error'] );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.zip' );
	}

	public function test_a_plan_without_backups_fails_the_job_in_words() {
		$this->api['POST /v1/site/backups'] = array( 403, array( 'error' => 'feature_off' ) );
		devbase_backup_new_job( 'backup' );

		$this->assertStringContainsString( 'does not include backups', devbase_backup_step( true )['error'] );
	}

	public function test_a_part_that_fails_is_tried_again_later_then_the_backup_is_aborted() {
		$this->put_code = 500;
		devbase_backup_new_job( 'backup' );

		$job = devbase_backup_step( true );
		$this->assertSame( 'uploading', $job['state'] );
		$this->assertSame( 1, $job['attempts'] );
		$this->assertSame( 1, (int) $job['next_part']['site_backup_all.zip'], 'Still on the first part.' );

		for ( $i = 0; $i < 5; $i++ ) {
			$job = devbase_backup_step( true );
		}

		$this->assertSame( 'failed', $job['state'] );
		$this->assertSame( 1, $this->called( 'POST', '/v1/site/backups/bkp_1/abort' ), 'Brand Relay is told to drop the unfinished upload.' );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.zip' );
	}

	public function test_it_resumes_from_the_next_part_not_from_the_start() {
		devbase_backup_new_job( 'backup' );
		devbase_backup_step( true );
		$this->reset_job_to( 'uploading', 2 );

		$this->parts = array();
		devbase_backup_step( true );

		$this->assertSame( array( 10, 20 ), array_column( $this->parts, 1 ), 'Parts 2 and 3 only.' );
	}

	private function reset_job_to( $state, $next_part ) {
		file_put_contents( $this->dir . 'site_backup_all.zip', $this->payload );
		$job              = devbase_backup_job();
		$job['state']     = $state;
		$job['next_part'] = array( 'site_backup_all.zip' => $next_part );
		devbase_backup_save( $job );
	}

	public function test_out_of_time_it_hands_on_to_another_request() {
		devbase_backup_new_job( 'backup' );
		devbase_backup_step( true );
		$this->reset_job_to( 'uploading', 1 );

		$this->assertSame( 'chain', devbase_backup_backup_step( devbase_backup_job(), time() - 1, true ) );
		$this->assertSame( 1, (int) devbase_backup_job()['next_part']['site_backup_all.zip'], 'Nothing was sent past the deadline.' );
	}

	// --- bringing a backup back ----------------------------------------------------------------

	private function stored_backup() {
		$this->api['GET /v1/site/backups'] = array( 200, array( 'backups' => array(
			array( 'id' => 'bkp_9', 'label' => 'Nightly', 'status' => 'complete', 'files' => array( array( 'name' => 'site_backup_all.zip', 'size' => strlen( $this->payload ) ) ) ),
			array( 'id' => 'bkp_8', 'label' => 'Unfinished', 'status' => 'uploading', 'files' => array() ),
		) ) );
	}

	public function test_a_download_brings_the_files_into_the_folder_and_checks_the_checksum() {
		$this->stored_backup();
		$this->headers = array( 'x-checksum-sha256' => hash( 'sha256', $this->payload ) );

		$job = devbase_backup_start_download( 'bkp_9' );
		$this->assertSame( 'downloading', $job['state'] );

		$job = devbase_backup_step( true );

		$this->assertSame( 'done', $job['state'], (string) $job['error'] );
		$this->assertSame( $this->payload, file_get_contents( $this->dir . 'site_backup_all.zip' ) );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.zip.part' );
	}

	public function test_a_download_that_does_not_match_its_checksum_is_discarded() {
		$this->stored_backup();
		$this->headers = array( 'x-checksum-sha256' => str_repeat( 'a', 64 ) );
		devbase_backup_start_download( 'bkp_9' );

		$job = devbase_backup_step( true );

		$this->assertSame( 'failed', $job['state'] );
		$this->assertStringContainsString( 'checksum', $job['error'] );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.zip' );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.zip.part' );
	}

	public function test_a_half_downloaded_file_is_continued_not_started_over() {
		$this->stored_backup();
		devbase_backup_start_download( 'bkp_9' );
		file_put_contents( $this->dir . 'site_backup_all.zip.part', substr( $this->payload, 0, 12 ) );

		$requested = array();
		add_filter( 'devbase/backup/get_range', function ( $null, $path, $handle, $from ) use ( &$requested ) {
			$requested[] = $from;

			return null;
		}, 5, 4 );

		devbase_backup_step( true );

		$this->assertSame( array( 12 ), $requested );
		$this->assertSame( $this->payload, file_get_contents( $this->dir . 'site_backup_all.zip' ) );
	}

	public function test_a_backup_that_is_not_listed_or_not_finished_cannot_be_downloaded() {
		$this->stored_backup();

		$this->assertWPError( devbase_backup_start_download( 'nope' ) );
		$this->assertWPError( devbase_backup_start_download( 'bkp_8' ) );
		$this->assertNull( devbase_backup_job() );
	}

	public function test_a_lapsed_plan_says_so_when_listing() {
		$this->api['GET /v1/site/backups'] = array( 403, array( 'error' => 'feature_off' ) );

		$error = devbase_backup_start_download( 'bkp_9' );

		$this->assertWPError( $error );
		$this->assertStringContainsString( 'does not include backups', $error->get_error_message() );
	}

	public function test_only_one_job_at_a_time() {
		$this->stored_backup();
		devbase_backup_new_job( 'backup' );

		$this->assertWPError( devbase_backup_start_download( 'bkp_9' ) );
	}

	// --- the loopback's proof ------------------------------------------------------------------

	public function test_the_loopback_token_belongs_to_one_job() {
		$this->assertSame( devbase_backup_token( 'a' ), devbase_backup_token( 'a' ) );
		$this->assertNotSame( devbase_backup_token( 'a' ), devbase_backup_token( 'b' ) );
	}

	// --- the page ------------------------------------------------------------------------------

	public function test_the_page_says_so_when_the_plan_has_no_backups() {
		devbase_relay_store_plan( array( 'licence' => array( 'status' => 'active', 'services' => array( 'uptime' ), 'limits' => array() ) ) );

		$html = devbase_backups_page_html();

		$this->assertStringContainsString( 'are not part of this site', $html );
		$this->assertStringContainsString( 'plan at the moment', $html );
		$this->assertStringContainsString( 'get in touch with your developer', $html, 'Calm, and a way forward.' );
		$this->assertStringNotContainsString( 'data-devbase-backup', $html, 'No buttons for something the plan does not have.' );
		$this->assertSame( array(), $this->calls, 'No call to Brand Relay for a plan without backups.' );
	}

	public function test_the_page_lists_what_brand_relay_holds_including_backups_from_an_earlier_install() {
		$this->api['GET /v1/site/backups'] = array( 200, array( 'backups' => array(
			array( 'id' => 'bkp_9', 'label' => 'Nightly 2026-11-01', 'status' => 'complete', 'size' => 5 * MB_IN_BYTES, 'completed_at' => '2026-11-01T03:00:00.000Z', 'files' => array() ),
			array( 'id' => 'bkp_8', 'label' => 'Half done', 'status' => 'uploading', 'size' => 0, 'files' => array() ),
		) ) );
		delete_transient( 'devbase_backups_remote_list' );

		$html = devbase_backups_page_html();

		$this->assertStringContainsString( 'Nightly 2026-11-01', $html );
		$this->assertStringContainsString( 'data-id="bkp_9"', $html );
		$this->assertStringNotContainsString( 'data-id="bkp_8"', $html, 'Only a finished backup can be downloaded.' );
		$this->assertStringContainsString( 'including any made before this installation', $html );
		$this->assertStringContainsString( 'page=WPvivid', $html, 'It points at WPvivid for the restore itself.' );
	}

	public function test_the_page_reports_a_lapsed_plan_from_brand_relay_in_words() {
		$this->api['GET /v1/site/backups'] = array( 403, array( 'error' => 'feature_off' ) );
		delete_transient( 'devbase_backups_remote_list' );

		$this->assertStringContainsString( 'does not include backups', devbase_backups_page_html() );
	}

	public function test_the_list_is_cached_for_a_minute_and_the_refresh_button_clears_it() {
		delete_transient( 'devbase_backups_remote_list' );
		devbase_backups_remote_list();
		devbase_backups_remote_list();
		$this->assertSame( 1, $this->called( 'GET', '/v1/site/backups' ) );

		devbase_backups_remote_list( true );
		$this->assertSame( 2, $this->called( 'GET', '/v1/site/backups' ) );
	}

	public function test_the_job_is_described_in_plain_words_at_each_stage() {
		$this->assertSame( '', devbase_backup_job_summary() );

		devbase_backup_new_job( 'backup', array( 'state' => 'engine' ) );
		$this->assertStringContainsString( 'WPvivid Backup is making', devbase_backup_job_summary() );

		$job = devbase_backup_job();
		$job['state']     = 'uploading';
		$job['uploads']   = array( 'a.zip' => array( 'parts' => 14 ) );
		$job['next_part'] = array( 'a.zip' => 6 );
		$this->assertStringContainsString( 'part 6 of 14', devbase_backup_job_summary( $job ) );

		$job['state'] = 'failed';
		$job['error'] = 'Disk full';
		$this->assertStringContainsString( 'Failed: Disk full', devbase_backup_job_summary( $job ) );

		$job['state'] = 'done';
		$this->assertStringContainsString( 'sent to Brand Relay', devbase_backup_job_summary( $job ) );
		$job['type'] = 'download';
		$this->assertStringContainsString( 'press Restore', devbase_backup_job_summary( $job ) );
	}

	public function test_backups_is_a_tab_of_the_licence_page_not_a_page_of_its_own() {
		$this->assertEmpty( acf_get_options_page( 'backups-settings' ), 'No separate page, no extra menu entry.' );

		$this->assertSame( 'tab', acf_get_field( 'field_devbase_licence_tab_backups' )['type'] );

		$keys = array_column( acf_get_fields( 'group_devbase_licence' ), 'key' );
		$this->assertLessThan( array_search( 'field_devbase_licence_tab_keys', $keys, true ), array_search( 'field_devbase_licence_tab_backups', $keys, true ), 'Second tab, right after Status.' );

		// The tab does not call Brand Relay when the Licence page is drawn: it only holds a placeholder.
		$this->assertStringContainsString( 'devbase-backups-tab', acf_get_field( 'field_devbase_licence_backups' )['message'] );
		$this->assertSame( array(), $this->calls );
	}

	// --- how many files the backup comes to ----------------------------------------------------

	public function test_our_backups_split_at_the_most_the_zip_engine_can_write_not_at_the_clients_setting() {
		$raised = devbase_backup_raise_part_size( array( 'compress_type' => 'zip', 'max_file_size' => '200', 'no_compress' => true ) );

		$this->assertSame( '4080', $raised['max_file_size'], 'Under 4 GiB: PclZip has no ZIP64.' );
		$this->assertSame( 'zip', $raised['compress_type'], 'Everything else of theirs is kept.' );
		$this->assertSame( '4080', devbase_backup_raise_part_size( false )['max_file_size'] );
		$this->assertLessThan( 4096, DEVBASE_BACKUP_PART_MB );
	}

	public function test_how_many_files_a_site_comes_to() {
		$this->assertSame( 1, devbase_backup_files_needed( 0 ) );
		$this->assertSame( 1, devbase_backup_files_needed( 185 * MB_IN_BYTES ) );
		$this->assertSame( 2, devbase_backup_files_needed( 5 * GB_IN_BYTES ), 'A 5 GB site is two files, not twenty-five.' );
		$this->assertSame( 10, devbase_backup_files_needed( 39 * GB_IN_BYTES ) );
		$this->assertSame( 11, devbase_backup_files_needed( 41 * GB_IN_BYTES ), 'Past roughly 40 GB it is refused up front.' );
	}

	public function test_more_files_than_brand_relay_takes_fail_clearly_before_anything_is_sent() {
		devbase_backup_new_job( 'backup', array( 'state' => 'declare', 'task_id' => 'task1' ) );
		$entries = array();
		for ( $i = 1; $i <= 11; $i++ ) {
			$name = sprintf( 'site_part%03d.zip', $i );
			file_put_contents( $this->dir . $name, 'x' );
			$entries[] = array( 'file_name' => $name, 'size' => 1 );
		}
		update_option( 'wpvivid_backup_list', array( 'task1' => array( 'backup' => array( 'files' => $entries ) ) ) );

		$job = devbase_backup_step( true );

		$this->assertSame( 'failed', $job['state'] );
		$this->assertStringContainsString( 'takes at most 10', $job['error'] );
		$this->assertSame( 0, $this->called( 'POST', '/v1/site/backups' ), 'Nothing was declared.' );
		$this->assertFileDoesNotExist( $this->dir . 'site_part001.zip', 'Our local files are removed.' );
	}

	public function test_a_backup_of_several_files_is_declared_and_sent_file_by_file() {
		devbase_backup_new_job( 'backup', array( 'state' => 'declare', 'task_id' => 'task1' ) );
		file_put_contents( $this->dir . 'a.zip', '0123456789ab' );
		update_option( 'wpvivid_backup_list', array( 'task1' => array( 'backup' => array( 'files' => array( array( 'file_name' => 'a.zip', 'size' => 12 ) ) ) ) ) );

		$this->assertSame( 'done', devbase_backup_step( true )['state'] );
		$this->assertSame( 2, count( $this->parts ), 'A 12-byte file at a 10-byte part size is two parts.' );
	}

	// --- a backup in several files, coming back ------------------------------------------------

	private function stored_split_backup( $names ) {
		$files = array();
		foreach ( $names as $name ) {
			$files[] = array( 'name' => $name, 'size' => strlen( $this->payload ) );
		}
		$this->api['GET /v1/site/backups'] = array( 200, array( 'backups' => array( array( 'id' => 'bkp_9', 'label' => 'Split', 'status' => 'complete', 'files' => $files ) ) ) );
	}

	public function test_every_part_of_a_split_backup_comes_back_before_wpvivid_is_asked_to_look() {
		$this->stored_split_backup( array( 'site_backup_all.part001.zip', 'site_backup_all.part002.zip', 'site_backup_all.part003.zip' ) );
		$rescans = 0;
		add_action( 'wpvivid_rebuild_backup_list', function () use ( &$rescans ) {
			$rescans++;
			// The moment WPvivid looks, ALL the parts must already be in place: it files whatever is there as the backup.
			foreach ( array( '001', '002', '003' ) as $n ) {
				$this->assertFileExists( $this->dir . "site_backup_all.part{$n}.zip" );
			}
		} );

		devbase_backup_start_download( 'bkp_9' );
		$job = devbase_backup_step( true );

		$this->assertSame( 'done', $job['state'], (string) $job['error'] );
		$this->assertSame( 1, $rescans, 'Asked once, at the end.' );
		$this->assertSame( array( '', '', '' ), array_map( function ( $n ) {
			return glob( $this->dir . "*{$n}.zip.part" ) ? 'half' : '';
		}, array( '001', '002', '003' ) ) );
	}

	public function test_a_download_that_fails_part_way_leaves_no_part_of_the_backup_behind() {
		$this->stored_split_backup( array( 'site_backup_all.part001.zip', 'site_backup_all.part002.zip' ) );
		$this->headers          = array();
		$this->fail_range_after = 1; // part 001 arrives; part 002 is refused, every time

		devbase_backup_start_download( 'bkp_9' );
		// Four tries is what a part gets before the job gives up.
		for ( $i = 0; $i < 6; $i++ ) {
			$job = devbase_backup_step( true );
		}

		$this->assertSame( 'failed', $job['state'] );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.part001.zip', 'A set with a missing part must not stay where a rescan finds it.' );
		$this->assertFileDoesNotExist( $this->dir . 'site_backup_all.part002.zip.part' );
	}

	public function test_brand_relays_times_in_milliseconds_are_understood_as_well_as_dates() {
		$this->assertSame( 1793823581, devbase_backup_time( 1793823581156 ), 'Epoch milliseconds, as Brand Relay really sends them.' );
		$this->assertSame( 1793823581, devbase_backup_time( '1793823581156' ) );
		$this->assertSame( 1793823581, devbase_backup_time( 1793823581 ), 'Plain seconds.' );
		$this->assertSame( strtotime( '2026-11-01T03:00:00Z' ), devbase_backup_time( '2026-11-01T03:00:00.000Z' ) );
		$this->assertFalse( devbase_backup_time( '' ) );
		$this->assertFalse( devbase_backup_time( null ) );
	}

	public function test_the_list_shows_a_date_for_a_backup_whose_times_are_milliseconds() {
		$this->api['GET /v1/site/backups'] = array( 200, array( 'backups' => array(
			array( 'id' => 'bkp_1', 'label' => 'Real shape', 'status' => 'complete', 'size' => 1048576, 'created_at' => 1793823580262, 'completed_at' => 1793823581156, 'files' => array() ),
		) ) );
		delete_transient( 'devbase_backups_remote_list' );

		$html = devbase_backups_page_html();

		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), 1793823581 ), $html );
		$this->assertStringNotContainsString( '<td>—</td>', $html );
	}
}
