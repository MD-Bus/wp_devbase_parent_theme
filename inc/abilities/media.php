<?php

// devbase/upload-media: adds one file to the media library and returns its
// attachment ID, which is what a block's image/file field holds (see
// blocks.php). Without it an MCP client could build a page from blocks but
// never give one a new photo.
//
// It widens what an AI client can do to a live site, so it is opt-in on its
// own: Theme Settings > Miscellaneous > MCP Access > "Allow Media Uploads",
// off by default, and protected from MCP itself
// (devbase_abilities_protected_option_fields()). The caller also needs
// upload_files, and WordPress's own checks decide which file types are
// allowed (media_handle_sideload() refuses anything the user couldn't upload
// in the admin).
//
// Two sources: a public URL (fetched with download_url(), which uses
// wp_safe_remote_get(), so private and local addresses are refused), or the
// file itself as base64 for a client that has the bytes but no URL.

/**
 * Largest upload accepted as base64, in bytes (decoded size). The site's own
 * upload limit still applies on top.
 */
function devbase_abilities_media_max_bytes() {
	return (int) apply_filters( 'devbase_abilities_media_max_bytes', 15 * MB_IN_BYTES );
}

function devbase_abilities_media_upload_allowed() {
	return (bool) get_field( 'mcp_allow_media_upload', 'option' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'devbase/upload-media', array(
		'label'               => __( 'Upload Media', 'devbase_parent_theme' ),
		'description'         => __( 'Adds one file to the media library, from a public URL or as base64 data, and returns its attachment ID (what a block\'s image field takes). Only available when media uploads are switched on in Theme Settings > Miscellaneous > MCP Access.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'url'      => array(
					'type'        => 'string',
					'description' => __( 'A public http(s) URL to fetch the file from. Give this or data.', 'devbase_parent_theme' ),
				),
				'data'     => array(
					'type'        => 'string',
					'description' => __( 'The file itself, base64-encoded. Give this or url; filename is then required.', 'devbase_parent_theme' ),
				),
				'filename' => array(
					'type'        => 'string',
					'description' => __( 'File name with extension, e.g. dolphins.jpg. Defaults to the URL\'s.', 'devbase_parent_theme' ),
				),
				'alt'      => array(
					'type'        => 'string',
					'description' => __( 'Alternative text for an image.', 'devbase_parent_theme' ),
				),
				'title'    => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			if ( ! devbase_abilities_media_upload_allowed() ) {
				return new WP_Error( 'devbase_media_upload_disabled', __( 'Media uploads through MCP are switched off on this site (Theme Settings > Miscellaneous > MCP Access).', 'devbase_parent_theme' ) );
			}

			$url  = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
			$data = isset( $input['data'] ) ? (string) $input['data'] : '';

			if ( ( '' === $url ) === ( '' === $data ) ) {
				return new WP_Error( 'devbase_invalid_media', __( 'Give exactly one of url or data.', 'devbase_parent_theme' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			if ( '' !== $url ) {
				if ( ! wp_http_validate_url( $url ) ) {
					return new WP_Error( 'devbase_invalid_media', __( 'url must be a public http(s) address.', 'devbase_parent_theme' ) );
				}

				$filename = $input['filename'] ?? wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$tmp      = download_url( $url, 30 );

				if ( is_wp_error( $tmp ) ) {
					return $tmp;
				}
			} else {
				if ( empty( $input['filename'] ) ) {
					return new WP_Error( 'devbase_invalid_media', __( 'filename is required with data.', 'devbase_parent_theme' ) );
				}

				$bytes = base64_decode( preg_replace( '/^data:[^,]*,/', '', $data ), true );

				if ( false === $bytes || '' === $bytes ) {
					return new WP_Error( 'devbase_invalid_media', __( 'data is not valid base64.', 'devbase_parent_theme' ) );
				}

				if ( strlen( $bytes ) > devbase_abilities_media_max_bytes() ) {
					return new WP_Error( 'devbase_invalid_media', __( 'The file is too large.', 'devbase_parent_theme' ) );
				}

				$filename = $input['filename'];
				$tmp      = wp_tempnam( $filename );
				file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			$file = array(
				'name'     => sanitize_file_name( wp_basename( (string) $filename ) ),
				'tmp_name' => $tmp,
			);

			$post_data = isset( $input['title'] ) ? array( 'post_title' => sanitize_text_field( $input['title'] ) ) : array();
			$id        = media_handle_sideload( $file, 0, null, $post_data );

			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			if ( isset( $input['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
			}

			$meta = wp_get_attachment_metadata( $id );

			return array(
				'id'        => $id,
				'url'       => wp_get_attachment_url( $id ),
				'mime_type' => get_post_mime_type( $id ),
				'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : null,
				'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		'meta'                => array(
			'public'      => true,
			'annotations' => array( 'destructive' => false, 'idempotent' => false ),
		),
	) );
} );
