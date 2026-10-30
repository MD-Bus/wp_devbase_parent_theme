<?php

namespace ASENHA\Classes;

/**
 * Clean Media Filenames module — strict upload filename sanitization.
 *
 * @since 8.8.1
 */
class Clean_Media_Filenames {

	/**
	 * Prefilter for wp_handle_upload_prefilter and wp_handle_sideload_prefilter.
	 *
	 * @since 8.8.1
	 *
	 * @param array $file {
	 *     @type string $name    Filename.
	 *     @type string $tmp_name
	 *     @type int    $error
	 *     @type int    $size
	 * }
	 * @return array
	 */
	public function filter_upload_prefilter( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) || ! is_string( $file['name'] ) ) {
			return $file;
		}

		$original_name = $file['name'];
		$cleaned       = $this->clean_filename_strict( $original_name );

		if ( '' !== $cleaned && $cleaned !== $original_name ) {
			$file['name'] = $cleaned;
		}

		return $file;
	}

	/**
	 * Apply remove_accents, normalize plus to hyphen before sanitize_file_name, then strict stem rules.
	 *
	 * @since 8.8.1
	 *
	 * @param string $filename Original upload filename (may include path segments in edge cases).
	 * @return string Sanitized basename safe for storage.
	 */
	private function clean_filename_strict( $filename ) {
		$basename = wp_basename( (string) $filename );
		if ( '' === $basename ) {
			return '';
		}

		$basename = remove_accents( $basename );
		// Plus signs are removed by sanitize_file_name(); turn them into hyphens first so segments stay separated.
		$basename = str_replace( '+', '-', $basename );
		$basename = sanitize_file_name( $basename );
		if ( '' === $basename ) {
			return '';
		}

		$dot_pos = strrpos( $basename, '.' );
		if ( false === $dot_pos || 0 === $dot_pos ) {
			$stem = $basename;
			$ext  = '';
		} else {
			$stem = substr( $basename, 0, $dot_pos );
			$ext  = substr( $basename, $dot_pos );
		}

		$stem = strtolower( $stem );
		$stem = str_replace( '_', '-', $stem );
		// Dots in the stem only (extension uses the last dot); inner dots become hyphens.
		$stem = str_replace( '.', '-', $stem );

		// Remove any character that is not a-z, 0-9, or hyphen.
		$stem = preg_replace( '/[^a-z0-9-]+/', '-', $stem );
		$stem = preg_replace( '/-+/', '-', $stem );
		$stem = trim( $stem, '-' );

		if ( '' === $stem ) {
			$stem = 'file';
		}

		$ext_clean = '';
		if ( '' !== $ext ) {
			$ext_body = strtolower( substr( $ext, 1 ) );
			$ext_body = preg_replace( '/[^a-z0-9]+/', '', $ext_body );
			if ( '' !== $ext_body ) {
				$ext_clean = '.' . $ext_body;
			}
		}

		return $stem . $ext_clean;
	}
}
