<?php
// Covers the 1.33.0 security fixes: who may open Theme Settings and what a save may change
// (inc/theme-settings/access.php), pinned Warden keys and verified theme packages
// (inc/theme-settings/licence/settings.php, inc/updates/theme-updates.php), the pages and fields
// MCP never reaches (inc/abilities/helpers.php), the CAPTCHA hostname and action rules
// (inc/theme-settings/security/verify.php), array input to string sanitizers
// (inc/helpers/forms/build-content.php) and the fresh-nonce field (inc/helpers/forms/fields.php).

class Test_Security_Hardening extends WP_UnitTestCase {

	private $http_filter = null;

	public function tear_down() {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}

		remove_all_filters( 'devbase/warden/pinned_keys' );
		remove_all_filters( 'devbase/licence/hide_remote_setup' );
		remove_all_filters( 'devbase/security/allowed_hostnames' );
		update_field( 'turnstile_enabled', 0, 'option' );
		update_field( 'turnstile_secret_key', '', 'option' );
		update_field( 'mcp_allowed_options_pages', array(), 'option' );

		parent::tear_down();
	}

	// --- who may open Theme Settings ------------------------------------------------------------

	public function test_every_theme_settings_page_needs_manage_options() {
		foreach ( array( 'theme-settings', 'licence-settings', 'smtp-settings', 'security-settings', 'tracking-settings', 'schema-settings', 'destinations-settings', 'miscellaneous-settings' ) as $slug ) {
			$page = acf_get_options_page( $slug );

			$this->assertIsArray( $page, $slug );
			$this->assertSame( 'manage_options', $page['capability'], "{$slug} must not fall back to SCF's edit_posts." );
		}
	}

	public function test_the_archive_pages_need_edit_pages() {
		do_action( 'wp_loaded' );

		$this->assertSame( 'edit_pages', acf_get_options_page( 'archive-settings' )['capability'] );
	}

	public function test_a_page_a_child_adds_under_theme_settings_gets_the_same_capability() {
		acf_add_options_sub_page( array(
			'page_title'  => 'Client page',
			'menu_slug'   => 'client-extra-settings',
			'parent_slug' => 'theme-settings',
		) );

		$this->assertSame( 'manage_options', acf_get_options_page( 'client-extra-settings' )['capability'] );
	}

	public function test_a_contributor_cannot_reach_the_licence_page() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );

		$this->assertTrue( current_user_can( 'edit_posts' ), 'The capability SCF would have used by default.' );
		$this->assertFalse( current_user_can( acf_get_options_page( 'licence-settings' )['capability'] ) );
	}

	// --- what a save from the screen may change -------------------------------------------------

	public function test_a_form_save_never_carries_the_provisioned_licence_values() {
		$kept = devbase_settings_filter_submission( array(
			'field_devbase_warden_base_url'   => 'https://attacker.example',
			'field_devbase_warden_public_key' => base64_encode( str_repeat( 'k', 32 ) ),
			'field_devbase_warden_licence_key' => 'WRD-EVIL',
			'field_devbase_smtp_host'         => 'smtp.example.com',
		) );

		$this->assertSame( array( 'field_devbase_smtp_host' => 'smtp.example.com' ), $kept );
	}

	public function test_the_remote_setup_fields_are_not_taken_from_a_form_while_the_tab_is_hidden() {
		add_filter( 'devbase/licence/hide_remote_setup', '__return_true' );

		$kept = devbase_settings_filter_submission( array(
			'field_devbase_relay_base_url' => 'https://attacker.example',
			'field_devbase_relay_site_key' => 'brs_evil',
		) );

		$this->assertSame( array(), $kept );
	}

	public function test_the_remote_setup_fields_are_saved_while_the_tab_is_shown_unless_set_in_code() {
		add_filter( 'devbase/licence/hide_remote_setup', '__return_false' );

		$kept = devbase_settings_filter_submission( array(
			'field_devbase_relay_base_url' => 'https://relay.example.com',
			'field_devbase_relay_site_key' => 'brs_new',
		) );

		// Other test classes define DEVBASE_RELAY_URL / _SITE_KEY (constants last for the whole run):
		// a value set in code is never taken from a form.
		if ( defined( 'DEVBASE_RELAY_URL' ) ) {
			$this->assertArrayNotHasKey( 'field_devbase_relay_base_url', $kept );
		} else {
			$this->assertSame( 'https://relay.example.com', $kept['field_devbase_relay_base_url'] );
		}

		if ( defined( 'DEVBASE_RELAY_SITE_KEY' ) ) {
			$this->assertArrayNotHasKey( 'field_devbase_relay_site_key', $kept );
		} else {
			$this->assertSame( 'brs_new', $kept['field_devbase_relay_site_key'] );
		}
	}

	public function test_an_empty_secret_keeps_the_stored_one_and_a_new_one_replaces_it() {
		$this->assertArrayNotHasKey( 'field_devbase_smtp_password', devbase_settings_filter_submission( array( 'field_devbase_smtp_password' => '' ) ) );
		$this->assertSame( 'new-password', devbase_settings_filter_submission( array( 'field_devbase_smtp_password' => 'new-password' ) )['field_devbase_smtp_password'] );
	}

	public function test_a_secret_is_not_drawn_back_into_the_page() {
		$field = apply_filters( 'acf/prepare_field', array(
			'key'   => 'field_devbase_smtp_password',
			'name'  => 'acf[field_devbase_smtp_password]',
			'type'  => 'password',
			'value' => 'hunter2',
		) );

		$this->assertSame( '', $field['value'] );
		$this->assertNotEmpty( $field['placeholder'] );
	}

	public function test_relay_sync_still_writes_the_provisioned_values() {
		// The form filter must not touch update_field(), which is how the sync (and WP-CLI) write.
		update_field( 'warden_base_url', 'https://warden.test', 'option' );

		$this->assertSame( 'https://warden.test', devbase_warden_base_url() );
	}

	// --- pinned Warden keys ---------------------------------------------------------------------

	public function test_a_pinned_key_wins_over_the_provisioned_field() {
		$pinned = base64_encode( str_repeat( 'p', 32 ) );
		update_field( 'warden_public_key', base64_encode( str_repeat( 'd', 32 ) ), 'option' );
		add_filter( 'devbase/warden/pinned_keys', function () use ( $pinned ) {
			return array( $pinned );
		} );

		$this->assertSame( $pinned, devbase_warden_public_key() );
		$this->assertSame( array( $pinned ), devbase_warden_trusted_keys() );
	}

	public function test_without_a_pinned_key_the_provisioned_one_is_trusted() {
		$stored = base64_encode( str_repeat( 'd', 32 ) );
		update_field( 'warden_public_key', $stored, 'option' );

		$this->assertSame( array( $stored ), devbase_warden_trusted_keys() );
	}

	// --- verified theme packages ----------------------------------------------------------------

	/** A package file, its release (signed with a pinned key) and the key pair. */
	private function release( $tamper = '' ) {
		$pair = sodium_crypto_sign_keypair();
		add_filter( 'devbase/warden/pinned_keys', function () use ( $pair ) {
			return array( base64_encode( sodium_crypto_sign_publickey( $pair ) ) );
		} );

		$file = wp_tempnam( 'package.zip' );
		file_put_contents( $file, 'PK theme package bytes' );
		$sha = hash_file( 'sha256', $file );

		$release = array(
			'version'      => '9.9.9',
			'download_url' => 'https://warden.test/download/t/abc',
			'sha256'       => $sha,
			'signature'    => base64_encode( sodium_crypto_sign_detached( $sha, sodium_crypto_sign_secretkey( $pair ) ) ),
		);

		if ( 'bytes' === $tamper ) {
			file_put_contents( $file, 'PK something else' );
		} elseif ( 'signature' === $tamper ) {
			$other                = sodium_crypto_sign_keypair();
			$release['signature'] = base64_encode( sodium_crypto_sign_detached( $sha, sodium_crypto_sign_secretkey( $other ) ) );
		} elseif ( 'no_sha' === $tamper ) {
			unset( $release['sha256'] );
		}

		return array( $file, $release );
	}

	public function test_a_package_matching_its_signed_checksum_is_accepted() {
		list( $file, $release ) = $this->release();

		$this->assertTrue( devbase_update_verify_package( $file, $release ) );
	}

	public function test_a_package_with_other_bytes_is_refused() {
		list( $file, $release ) = $this->release( 'bytes' );

		$this->assertSame( 'devbase_package_mismatch', devbase_update_verify_package( $file, $release )->get_error_code() );
	}

	public function test_a_package_signed_by_another_key_is_refused() {
		list( $file, $release ) = $this->release( 'signature' );

		$this->assertSame( 'devbase_package_signature', devbase_update_verify_package( $file, $release )->get_error_code() );
	}

	public function test_a_release_without_a_checksum_is_refused() {
		list( $file, $release ) = $this->release( 'no_sha' );

		$this->assertSame( 'devbase_package_unverified', devbase_update_verify_package( $file, $release )->get_error_code() );
	}

	public function test_packages_that_are_not_ours_are_left_to_core() {
		$this->assertFalse( devbase_verify_theme_package_download( false, 'https://downloads.wordpress.org/theme/twentytwentyfive.zip', null, array( 'theme' => 'twentytwentyfive' ) ) );
	}

	// --- MCP never reaches secrets --------------------------------------------------------------

	public function test_sensitive_pages_are_never_allowed_through_mcp_even_when_ticked() {
		update_field( 'mcp_allowed_options_pages', array( 'licence-settings', 'smtp-settings', 'tracking-settings' ), 'option' );

		$this->assertFalse( devbase_abilities_is_options_page_allowed( 'licence-settings' ) );
		$this->assertFalse( devbase_abilities_is_options_page_allowed( 'smtp-settings' ) );
		$this->assertTrue( devbase_abilities_is_options_page_allowed( 'tracking-settings' ) );
	}

	public function test_secret_fields_are_protected_from_mcp_on_any_page() {
		foreach ( array( 'warden_base_url', 'warden_public_key', 'relay_site_key', 'smtp_password', 'turnstile_secret_key', 'recaptcha_secret_key', 'destinations' ) as $name ) {
			$this->assertContains( $name, devbase_abilities_protected_option_fields() );
		}
	}

	// --- CAPTCHA --------------------------------------------------------------------------------

	private function turnstile_answers( array $answer ) {
		update_field( 'turnstile_enabled', 1, 'option' );
		update_field( 'turnstile_secret_key', 'test-secret', 'option' );

		$this->http_filter = function () use ( $answer ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $answer ), 'headers' => array() );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	public function test_a_turnstile_token_from_this_site_passes() {
		$this->turnstile_answers( array( 'success' => true, 'hostname' => wp_parse_url( home_url(), PHP_URL_HOST ) ) );

		$this->assertTrue( devbase_security_verify( 'token' ) );
	}

	public function test_a_turnstile_token_earned_on_another_site_is_refused() {
		$this->turnstile_answers( array( 'success' => true, 'hostname' => 'other-site.example' ) );

		$this->assertFalse( devbase_security_verify( 'token' ) );
	}

	public function test_an_extra_hostname_can_be_allowed() {
		add_filter( 'devbase/security/allowed_hostnames', function ( $hosts ) {
			$hosts[] = 'staging.example';
			return $hosts;
		} );
		$this->turnstile_answers( array( 'success' => true, 'hostname' => 'staging.example' ) );

		$this->assertTrue( devbase_security_verify( 'token' ) );
	}

	// --- forms ----------------------------------------------------------------------------------

	public function test_an_array_posted_to_a_text_field_is_emptied_not_a_fatal_error() {
		$content = devbase_form_build_content( array( 'name' => array( 'x', 'y' ), 'email' => array( 'a@b.c' ) ), array(
			'name'  => 'Name',
			'email' => array( 'label' => 'Email', 'sanitize' => 'sanitize_email' ),
		) );

		$this->assertSame( '', $content['name']['value'] );
		$this->assertSame( '', $content['email']['value'] );
	}

	public function test_a_custom_sanitizer_still_receives_a_list() {
		$content = devbase_form_build_content( array( 'topics' => array( 'a', 'b' ) ), array(
			'topics' => array( 'label' => 'Topics', 'sanitize' => function ( $value ) {
				return array_map( 'sanitize_text_field', (array) $value );
			} ),
		) );

		$this->assertSame( array( 'a', 'b' ), $content['topics']['value'] );
	}

	public function test_the_nonce_field_says_where_to_ask_for_a_fresh_one() {
		$html = devbase_form_fields( 'devbase_test_form', array( 'script' => false, 'captcha' => false ) );

		$this->assertStringContainsString( 'data-devbase-nonce="devbase_test_form"', $html );
		$this->assertStringContainsString( 'admin-ajax.php', $html );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_devbase_form_nonce' ) );
	}

	public function test_the_test_form_is_not_served_outside_development() {
		$this->assertSame( 'production', wp_get_environment_type() );
		$this->assertFalse( has_action( 'admin_post_nopriv_devbase_test_form_submit' ), 'The debug form must not take public submissions on a live site.' );
	}

	/**
	 * Runs a webhook destination. The request itself is faked, but the fake also asks WordPress's own
	 * URL check (the one wp_safe_remote_post applies) about the address, while the theme's port
	 * allowance is in place, and returns that verdict as the response.
	 */
	private function run_webhook( $url ) {
		$fake = static function ( $pre, $args, $u ) {
			$ok = (bool) wp_http_validate_url( $u );
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $ok ? 200 : 403, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		};
		add_filter( 'pre_http_request', $fake, 10, 3 );
		$result = devbase_forms_dispatch_webhook( array( 'label' => 'T', 'url' => $url ), 'f', array() );
		remove_filter( 'pre_http_request', $fake, 10 );
		return $result;
	}

	public function test_a_webhook_on_a_public_address_works_on_any_port() {
		$this->assertTrue( $this->run_webhook( 'http://93.184.216.34:5678/hook' )['success'] );
		$this->assertTrue( $this->run_webhook( 'https://93.184.216.34/hook' )['success'] );
		$this->assertFalse( has_filter( 'http_allowed_safe_ports' ), 'The port allowance must not outlive the request.' );
		$this->assertFalse( (bool) wp_http_validate_url( 'http://93.184.216.34:5678/hook' ), 'Other code keeps WordPress\'s default port rule.' );
	}

	public function test_a_webhook_cannot_reach_a_private_or_local_address() {
		$this->assertFalse( $this->run_webhook( 'http://127.0.0.1:5678/hook' )['success'] );
		$this->assertFalse( $this->run_webhook( 'http://192.168.1.5/hook' )['success'] );
		$this->assertFalse( $this->run_webhook( 'http://169.254.169.254/latest/meta-data' )['success'] );
	}
}
