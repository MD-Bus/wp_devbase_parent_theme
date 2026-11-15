<?php

// Loads Admin Site Enhancements from vendor/, same pattern as inc/dependencies/scf.php.
// ASE computes its asset URL/path with plugins_url()/plugin_dir_path(),
// which only resolves correctly for files under wp-content/plugins/. Since
// it's loaded from the theme's vendor/ folder instead, pre-define its own
// constants (ASENHA_URL, ASENHA_PATH) before requiring it — ASE's own
// define() calls are unconditional, but PHP keeps the first-defined value
// and just emits a harmless warning on the second, so ours wins.
// The site already runs Admin Site Enhancements as a plugin: use that one (see ../foreign.php).
if ( ! devbase_dependency_is_foreign( 'ase' ) && file_exists( get_template_directory() . '/vendor/admin-site-enhancements/admin-site-enhancements.php' ) ) {
	define( 'ASENHA_URL', get_template_directory_uri() . '/vendor/admin-site-enhancements/' );
	define( 'ASENHA_PATH', get_template_directory() . '/vendor/admin-site-enhancements/' );

	// ASE's own (now-skipped) define() calls would otherwise emit a harmless
	// "Constant already defined" E_WARNING for both constants above — silence
	// just that, not error reporting generally, then restore it immediately.
	$previous_error_reporting = error_reporting( error_reporting() & ~E_WARNING );
	require_once get_template_directory() . '/vendor/admin-site-enhancements/admin-site-enhancements.php';
	error_reporting( $previous_error_reporting );
}

/**
 * Corrects plugins_url() for a file inside the theme's vendor/. A plugin loaded from there that builds an address with
 * plugins_url( $path, __DIR__ ) (ASE does for its "no featured image" placeholder) otherwise gets
 * /wp-content/plugins/<the server's file path>, a broken image. The address is rebuilt from the theme's own URL.
 *
 * @param string $url    The URL WordPress built.
 * @param string $path   The path relative to the plugin.
 * @param string $plugin The file or folder the path is relative to (often __DIR__ or __FILE__).
 * @return string
 */
function devbase_vendor_plugins_url( $url, $path, $plugin ) {
	$vendor = wp_normalize_path( get_template_directory() . '/vendor/' );
	$plugin = wp_normalize_path( (string) $plugin );

	if ( '' === $plugin || 0 !== strpos( $plugin, $vendor ) ) {
		return $url;
	}

	// As plugins_url() does: always the parent of what is passed, so __DIR__ of a plugin's classes/ folder means the
	// plugin's own folder, and __FILE__ of its main file means the same.
	$folder = dirname( $plugin );
	$folder = ltrim( substr( trailingslashit( $folder ), strlen( $vendor ) ), '/' );

	return get_template_directory_uri() . '/vendor/' . $folder . ltrim( (string) $path, '/' );
}
add_filter( 'plugins_url', 'devbase_vendor_plugins_url', 10, 3 );

/**
 * Takes ASE's "Featured Image" column off a list where featured images cannot be set. ASE adds it for every post type
 * that supports `thumbnail`, which WordPress gives pages by default; but without the theme's `post-thumbnails`
 * support the editor has no Featured image box, so the column could only ever show ASE's placeholder. A child theme
 * that adds post-thumbnails support (for all types or for this one) keeps the column.
 *
 * @param array $columns The list's columns.
 * @return array
 */
function devbase_ase_featured_image_column( $columns ) {
	if ( ! is_array( $columns ) || ! isset( $columns['asenha-featured-image'] ) ) {
		return $columns;
	}

	$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$post_type = $screen && ! empty( $screen->post_type ) ? (string) $screen->post_type : 'post';

	if ( ! current_theme_supports( 'post-thumbnails', $post_type ) ) {
		unset( $columns['asenha-featured-image'] );
	}

	return $columns;
}

/**
 * Takes ASE's "Excerpt" column off lists that are already full. ASE gives the column no width, so on WooCommerce's
 * product list (ten columns of its own) it was squeezed to one letter wide and the short description ran down the page
 * one character per line. Other lists keep it. Filter `devbase/ase/no_excerpt_column` (post types).
 *
 * @param array $columns The list's columns.
 * @return array
 */
function devbase_ase_excerpt_column( $columns ) {
	if ( ! is_array( $columns ) || ! isset( $columns['asenha-excerpt'] ) ) {
		return $columns;
	}

	$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$post_type = $screen && ! empty( $screen->post_type ) ? (string) $screen->post_type : 'post';

	if ( in_array( $post_type, (array) apply_filters( 'devbase/ase/no_excerpt_column', array( 'product' ) ), true ) ) {
		unset( $columns['asenha-excerpt'] );
	}

	return $columns;
}

// After ASE's own column filters (999 for the image; the excerpt's is at 10), for every post type.
add_action( 'admin_init', function () {
	foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
		add_filter( "manage_{$post_type}_posts_columns", 'devbase_ase_featured_image_column', 1000 );
		add_filter( "manage_{$post_type}_posts_columns", 'devbase_ase_excerpt_column', 1000 );
	}
}, 20 );
