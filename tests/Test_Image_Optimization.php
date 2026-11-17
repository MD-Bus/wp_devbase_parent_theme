<?php
// inc/media/image-optimization.php: WebP output and quality for generated image sizes.

class Test_Image_Optimization extends WP_UnitTestCase {

	/** @var int[] Attachments a test uploaded: deleted with their files afterwards. */
	private $uploaded = array();

	public function tear_down() {
		foreach ( $this->uploaded as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->uploaded = array();
		parent::tear_down();
	}

	private function upload( $file ) {
		$id               = self::factory()->attachment->create_upload_object( $file );
		$this->uploaded[] = $id;
		return $id;
	}

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

	public function test_names_wordpress_derives_from_a_nr_file_are_left_alone_too() {
		foreach ( array( 'photo-nr-1.jpg', 'photo-nr-scaled.jpg', 'photo-nr-2-scaled.jpg', 'photo-nr-rotated.png' ) as $name ) {
			$this->assertSame( array(), apply_filters( 'image_editor_output_format', array(), '/x/' . $name, 'image/jpeg' ), $name );
		}
		// "-nr" must end the name: these are ordinary files.
		if ( ! devbase_image_webp_supported() ) {
			return;
		}
		foreach ( array( 'corner-nr-photo.jpg', 'snr.jpg' ) as $name ) {
			$this->assertArrayHasKey( 'image/jpeg', apply_filters( 'image_editor_output_format', array(), '/x/' . $name, 'image/jpeg' ), $name );
		}
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

		$id   = $this->upload( DIR_TESTDATA . '/images/test-image-large.jpg' );
		$meta = wp_get_attachment_metadata( $id );

		$this->assertNotEmpty( $meta['sizes'] );
		foreach ( $meta['sizes'] as $size ) {
			$this->assertSame( 'image/webp', $size['mime-type'] );
		}
	}

	// End to end: WordPress converts the full-size file too (wp_create_image_subsizes() follows the same mapping), and keeps the file as uploaded as original_image.
	public function test_a_real_upload_keeps_the_uploaded_file_as_original_image() {
		if ( ! devbase_image_webp_supported() ) {
			$this->markTestSkipped( 'This PHP cannot write WebP.' );
		}

		$id = $this->upload( DIR_TESTDATA . '/images/test-image-large.jpg' );

		// (The attachment's post_mime_type stays image/jpeg: WordPress does not change it.)
		$this->assertStringEndsWith( '.webp', get_attached_file( $id ) );
		$this->assertStringEndsWith( '.jpg', wp_get_original_image_path( $id ) );
		$this->assertFileExists( wp_get_original_image_path( $id ) );
	}

	// End to end: a -nr upload stays JPEG, the full size AND every generated size (WordPress asks for
	// the output format of a size with no file name, so the skip must remember the file being processed).
	public function test_a_real_nr_upload_stays_jpeg_everywhere() {
		$tmp = get_temp_dir() . 'devbase-' . wp_generate_password( 8, false ) . '-nr.jpg';
		copy( DIR_TESTDATA . '/images/test-image-large.jpg', $tmp );

		$id   = $this->upload( $tmp );
		$meta = wp_get_attachment_metadata( $id );

		$this->assertStringEndsWith( '.jpg', get_attached_file( $id ) );
		$this->assertNotEmpty( $meta['sizes'] );
		foreach ( $meta['sizes'] as $size ) {
			$this->assertSame( 'image/jpeg', $size['mime-type'] );
		}
		@unlink( $tmp );
	}
}
