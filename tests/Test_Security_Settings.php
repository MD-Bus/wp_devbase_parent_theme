<?php
// Automates what was previously verified by hand via a one-off WP-CLI-style
// script every time inc/theme-settings/security/ changed (see the session
// history in AGENT.md) — the mutual-exclusivity guarantee between Turnstile
// and reCAPTCHA, and the devbase_security_* functions forms call.

class Test_Security_Settings extends WP_UnitTestCase {

	public function tear_down() {
		update_field( 'turnstile_enabled', 0, 'option' );
		update_field( 'recaptcha_enabled', 0, 'option' );
		parent::tear_down();
	}

	public function test_no_provider_active_by_default() {
		$this->assertNull( devbase_security_active_provider() );
		$this->assertSame( '', devbase_security_widget_html() );
		$this->assertTrue( devbase_security_verify( 'anything' ) );
	}

	public function test_turnstile_becomes_active_provider_when_enabled() {
		update_field( 'turnstile_enabled', 1, 'option' );
		update_field( 'turnstile_site_key', '1x00000000000000000000AA', 'option' );

		$this->assertSame( 'turnstile', devbase_security_active_provider() );
		$this->assertStringContainsString( '1x00000000000000000000AA', devbase_security_widget_html() );
	}

	public function test_recaptcha_becomes_active_provider_when_enabled() {
		update_field( 'recaptcha_enabled', 1, 'option' );
		update_field( 'recaptcha_site_key', 'test-site-key', 'option' );

		$this->assertSame( 'recaptcha', devbase_security_active_provider() );
		$this->assertStringContainsString( 'test-site-key', devbase_security_widget_html() );
	}

	public function test_both_enabled_is_rejected_on_save() {
		$_POST['acf'] = array(
			'field_devbase_turnstile_enabled' => '1',
			'field_devbase_recaptcha_enabled' => '1',
		);

		acf_reset_validation_errors();
		do_action( 'acf/validate_save_post' );
		$errors = acf_get_validation_errors();

		unset( $_POST['acf'] );

		$this->assertNotEmpty( $errors, 'Saving both providers enabled at once should be blocked.' );
	}

	public function test_verify_fails_without_active_secret() {
		update_field( 'turnstile_enabled', 1, 'option' );
		// Deliberately no secret key set.

		$this->assertFalse( devbase_security_verify( 'some-token' ) );
	}
}
