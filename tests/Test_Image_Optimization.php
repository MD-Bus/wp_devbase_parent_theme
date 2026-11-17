<?php
// inc/media/image-optimization.php: WebP output and quality for generated image sizes.

class Test_Image_Optimization extends WP_UnitTestCase {

	public function test_jpeg_and_png_sizes_become_webp_when_the_server_can_write_it() {
		if ( ! devbase_image_webp_supported() ) {
			$this->markTestSkipped( 'This PHP cannot write WebP.' );
		}

		$formats = apply_filters( 'image_editor_output_format', array(), '/x/photo.jpg', 'image/jpeg' );

		$this->assertSame( 'image/webp', $formats['image/jpeg'] );
		$this->assertSame( 'image/webp', $formats['image/png'] );
	}

	public function test_a_nr_file_is_left_alone() {
		$formats = apply_filters( 'image_editor_output_format', array(), '/x/photo-nr.jpg', 'image/jpeg' );

		$this->assertSame( array(), $formats );
	}

	public function test_an_existing_mapping_is_never_overridden() {
		$formats = apply_filters( 'image_editor_output_format', array( 'image/jpeg' => 'image/avif' ), '/x/photo.jpg', 'image/jpeg' );

		$this->assertSame( 'image/avif', $formats['image/jpeg'] );
	}

	public function test_a_child_can_turn_webp_off() {
		add_filter( 'devbase/image_webp', '__return_false' );

		$this->assertSame( array(), apply_filters( 'image_editor_output_format', array(), '/x/photo.jpg', 'image/jpeg' ) );

		remove_filter( 'devbase/image_webp', '__return_false' );
	}

	public function test_quality_is_75_for_jpeg_and_webp_only() {
		$this->assertSame( 75, apply_filters( 'wp_editor_set_quality', 82, 'image/jpeg' ) );
		$this->assertSame( 75, apply_filters( 'wp_editor_set_quality', 82, 'image/webp' ) );
		$this->assertSame( 82, apply_filters( 'wp_editor_set_quality', 82, 'image/png' ) );
	}

	public function test_a_silly_quality_falls_back_to_75() {
		$fn = function () { return 500; };
		add_filter( 'devbase/image_quality', $fn );

		$this->assertSame( 75, devbase_image_quality() );

		remove_filter( 'devbase/image_quality', $fn );
	}

	// End to end: a real upload's generated sizes are WebP files on disk.
	public function test_a_real_upload_gets_webp_sizes() {
		if ( ! devbase_image_webp_supported() ) {
			$this->markTestSkipped( 'This PHP cannot write WebP.' );
		}

		$id   = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image-large.jpg' );
		$meta = wp_get_attachment_metadata( $id );

		$this->assertNotEmpty( $meta['sizes'] );
		foreach ( $meta['sizes'] as $size ) {
			$this->assertSame( 'image/webp', $size['mime-type'] );
		}
	}
}
