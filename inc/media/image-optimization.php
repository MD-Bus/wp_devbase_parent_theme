<?php

// Lighter images, in the theme and nowhere else. Admin Site Enhancements'
// Image Upload Control (see inc/dependencies/ase/ase-settings.php) shrinks a new
// upload; this file handles the files WordPress writes from it, which are the
// ones visitors actually download:
//
//   - A JPEG or PNG is written as WebP (about 25-35% lighter): the full size and
//     every generated size, when the server's image library can write WebP. The
//     upload itself is kept as the attachment's original_image.
//   - The quality is 75 instead of WordPress's 82, which is hard to see on a photo.
//
// It only uses WordPress's own filters, so it never reads or edits ASE's code
// and keeps working whatever ASE version is bundled. Only newly generated sizes
// are affected ("sizes" here and below means the full size too); existing media changes when it is regenerated (plain
// "wp media regenerate", not --only-missing). Full guide: the README, section Images.
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
 * Is this image opted out with ASE's "-nr" naming (photo-nr.jpg)?
 *
 * WordPress asks for the output format with the file's path when it opens an image, but
 * with no path at all when it writes each generated size. So the last path seen is
 * remembered, and a call without one is judged by it: without that, a -nr upload would
 * keep its own file but still get WebP sizes.
 *
 * @param string|null $filename The path WordPress passed, or null/'' for a generated size.
 */
function devbase_image_is_opted_out( $filename ) {
	static $last = '';

	if ( is_string( $filename ) && '' !== $filename ) {
		$last = $filename;
	}

	// photo-nr.jpg, and the names WordPress derives from it: photo-nr-1.jpg (a second upload of the same
	// name), photo-nr-scaled.jpg and photo-nr-rotated.jpg.
	return '' !== $last && (bool) preg_match( '/-nr(?:-\d+)?(?:-scaled|-rotated)?\.[a-z0-9]+$/i', wp_basename( $last ) );
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

		if ( devbase_image_is_opted_out( $filename ) ) {
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
