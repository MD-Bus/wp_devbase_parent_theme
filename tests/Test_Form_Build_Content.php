<?php
// Covers devbase_form_build_content() (inc/helpers/forms/build-content.php):
// the plain string-label shorthand, the array-spec form with a custom
// sanitizer, the default sanitizer when none is specified, and that a
// missing $_POST key becomes an empty string rather than a PHP notice.

class Test_Form_Build_Content extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_POST['_devbase_form_nonce'] );
		parent::tear_down();
	}

	public function test_string_label_shorthand_uses_default_sanitizer() {
		$content = devbase_form_build_content( array( 'name' => '  Jane <script>  ' ), array(
			'name' => 'Name',
		) );

		$this->assertSame( 'Name', $content['name']['aria_label'] );
		// sanitize_text_field() strips tags and trims — proves the default
		// sanitizer actually ran, not just passthrough.
		$this->assertSame( 'Jane', $content['name']['value'] );
	}

	public function test_array_spec_uses_its_own_sanitizer() {
		$content = devbase_form_build_content( array( 'email' => 'A@B.COM ' ), array(
			'email' => array( 'label' => 'Email Address', 'sanitize' => 'sanitize_email' ),
		) );

		$this->assertSame( 'Email Address', $content['email']['aria_label'] );
		$this->assertSame( 'A@B.COM', $content['email']['value'] );
	}

	public function test_array_spec_without_sanitize_key_falls_back_to_default() {
		$content = devbase_form_build_content( array( 'notes' => '  padded  ' ), array(
			'notes' => array( 'label' => 'Notes' ),
		) );

		$this->assertSame( 'padded', $content['notes']['value'] );
	}

	public function test_missing_post_key_becomes_empty_string() {
		$content = devbase_form_build_content( array(), array(
			'phone' => 'Phone',
		) );

		$this->assertSame( '', $content['phone']['value'] );
	}

	public function test_multiple_fields_shape_matches_log_submission_contract() {
		$post = array( 'name' => 'Jane', 'email' => 'jane@example.com', 'message' => "Line1\nLine2" );

		$content = devbase_form_build_content( $post, array(
			'name'    => 'Name',
			'email'   => array( 'label' => 'Email Address', 'sanitize' => 'sanitize_email' ),
			'message' => array( 'label' => 'Message', 'sanitize' => 'sanitize_textarea_field' ),
		) );

		// Feed straight into the real logging function — proves the shape
		// this helper produces is exactly what devbase_form_log_submission()
		// expects, not just structurally similar.
		$_POST['_devbase_form_nonce'] = wp_create_nonce( 'devbase_form_devbase_test_form' );
		$post_id                       = devbase_form_log_submission( 'devbase_test_form', $content );

		$this->assertIsInt( $post_id );
		$this->assertSame( $content, json_decode( get_post_meta( $post_id, 'content', true ), true ) );
	}
}
