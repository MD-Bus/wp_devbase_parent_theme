<?php
// Covers inc/dependencies/ase/ase.php: ASE's "Featured Image" list column had a broken placeholder (plugins_url() for a
// file inside the theme's vendor/ gave /wp-content/plugins/<server path>) and showed on Pages, where the theme offers no
// featured image at all.

class Test_ASE_Featured_Column extends WP_UnitTestCase {

	public function tear_down() {
		remove_theme_support( 'post-thumbnails' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_plugins_url_for_a_vendored_file_points_into_the_theme() {
		$dir = get_template_directory() . '/vendor/admin-site-enhancements/classes';

		$this->assertSame(
			get_template_directory_uri() . '/vendor/admin-site-enhancements/assets/img/default_featured_image.jpg',
			plugins_url( 'assets/img/default_featured_image.jpg', $dir )
		);
		$this->assertSame(
			get_template_directory_uri() . '/vendor/admin-site-enhancements/assets/x.css',
			plugins_url( 'assets/x.css', get_template_directory() . '/vendor/admin-site-enhancements/admin-site-enhancements.php' ),
			'A file, as __FILE__ is passed: its folder is used.'
		);
	}

	public function test_plugins_url_for_a_real_plugin_is_left_alone() {
		$this->assertSame( WP_PLUGIN_URL . '/akismet/x.js', plugins_url( 'x.js', WP_PLUGIN_DIR . '/akismet/akismet.php' ) );
		$this->assertSame( WP_PLUGIN_URL . '/x.js', plugins_url( 'x.js' ) );
	}

	public function test_the_column_is_dropped_where_the_theme_has_no_featured_images() {
		set_current_screen( 'edit-page' );
		$columns = array( 'cb' => '', 'asenha-featured-image' => 'Featured Image', 'title' => 'Title' );

		$this->assertSame( array( 'cb' => '', 'title' => 'Title' ), devbase_ase_featured_image_column( $columns ) );
	}

	public function test_the_column_stays_where_the_theme_supports_featured_images() {
		set_current_screen( 'edit-page' );
		$columns = array( 'asenha-featured-image' => 'Featured Image', 'title' => 'Title' );

		add_theme_support( 'post-thumbnails', array( 'page' ) );
		$this->assertArrayHasKey( 'asenha-featured-image', devbase_ase_featured_image_column( $columns ), 'Supported for pages.' );

		remove_theme_support( 'post-thumbnails' );
		add_theme_support( 'post-thumbnails', array( 'post' ) );
		$this->assertArrayNotHasKey( 'asenha-featured-image', devbase_ase_featured_image_column( $columns ), 'Supported for posts only.' );

		remove_theme_support( 'post-thumbnails' );
		add_theme_support( 'post-thumbnails' );
		$this->assertArrayHasKey( 'asenha-featured-image', devbase_ase_featured_image_column( $columns ), 'Supported everywhere.' );
	}

	public function test_the_excerpt_column_is_dropped_on_the_product_list_only() {
		$columns = array( 'name' => 'Name', 'asenha-excerpt' => 'Excerpt' );

		set_current_screen( 'edit-product' );
		get_current_screen()->post_type = 'product';
		$this->assertArrayNotHasKey( 'asenha-excerpt', devbase_ase_excerpt_column( $columns ) );

		set_current_screen( 'edit-post' );
		get_current_screen()->post_type = 'post';
		$this->assertArrayHasKey( 'asenha-excerpt', devbase_ase_excerpt_column( $columns ) );
	}
}
