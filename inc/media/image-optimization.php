<?php

// Lighter images, in the theme and nowhere else. Admin Site Enhancements'
// Image Upload Control (see inc/dependencies/ase/ase-settings.php) shrinks the
// ORIGINAL of a new upload; this file handles the smaller sizes WordPress
// generates from it, which are the files visitors actually download:
//
//   - JPEG and PNG sizes are written as WebP (about 25-35% lighter), when the
//     server's image library can write WebP.
//   - Their quality is 75 instead of WordPress's 82, which is hard to see on a photo.
//
// It only uses WordPress's own filters, so it never reads or edits ASE's code
// and keeps working whatever ASE version is bundled. Only newly generated sizes
// are affected; existing media changes when it is regenerated (plain
// "wp media regenerate", not --only-missing). Full guide: inc/media/AGENT.md.
//
// A file named like photo-nr.jpg ("no resize", ASE's own convention) is left as
// uploaded. Child themes can turn either part off:
//
//   add_filter( 'devbase/image_webp', '__return_false' );
//   add_filter( 'devbase/image_quality', fn () => 82 );

/**
 * Can this server write WebP? Asked once per request.
 */
function devbase_image_webp_supported() {
	static $supported = null;

	if ( null === $supported ) {
		$supported = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	return $supported;
}

/**
 * The quality for generated JPEG and WebP sizes.
 */
function devbase_image_quality() {
	$quality = (int) apply_filters( 'devbase/image_quality', 75 );

	return ( $quality >= 1 && $quality <= 100 ) ? $quality : 75;
}

add_filter(
	'image_editor_output_format',
	function ( $formats, $filename = '' ) {
		if ( ! is_array( $formats ) || ! apply_filters( 'devbase/image_webp', true ) ) {
			return $formats;
		}

		if ( is_string( $filename ) && false !== strpos( wp_basename( $filename ), '-nr.' ) ) {
			return $formats;
		}

		if ( ! devbase_image_webp_supported() ) {
			return $formats;
		}

		// Never override a mapping someone else already made.
		foreach ( array( 'image/jpeg', 'image/png' ) as $mime ) {
			if ( ! isset( $formats[ $mime ] ) ) {
				$formats[ $mime ] = 'image/webp';
			}
		}

		return $formats;
	},
	10,
	2
);

add_filter(
	'wp_editor_set_quality',
	function ( $quality, $mime_type = '' ) {
		return in_array( $mime_type, array( 'image/jpeg', 'image/webp' ), true ) ? devbase_image_quality() : $quality;
	},
	10,
	2
);
