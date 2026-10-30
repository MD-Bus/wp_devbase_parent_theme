<?php

// devbase/list-blocks and devbase/get-block-schema, plus the encoder and
// decoder that let devbase/create-post, update-post and get-post (posts.php)
// write and read a page as a list of blocks:
//
//   [ { "block": "acf/item-list", "fields": { "heading": "...", "items": [ {...} ] } } ]
//
// The parent knows no site's blocks. Everything is discovered from what the
// active child registers: the block registry, filtered by the editor's own
// allow-list (allowed_block_types_all), and each block's SCF field group
// (location rule "Block == acf/<name>").
//
// Only ACF blocks can be written: their content is their field values, which
// this file turns into the block comment the editor would have saved. Core
// blocks (paragraph, image, ...) carry their content as HTML, which is not
// something to build from a field list; they are listed and read back, never
// written; they are read back as { block, markup }.
//
// Writing reuses SCF rather than reimplementing its storage. ACF keeps block
// values flat, with a hidden reference beside each and repeater rows
// flattened (items: 2, items_0_title, _items_0_title: field_..., ...).
// acf_setup_meta() given field-key => value pairs runs SCF's own
// acf_update_values() and captures what it would have written instead of
// writing it (ACF_Local_Meta::capture(), the same path the block editor
// takes), so repeaters, groups, flexible content and every field type's own
// update_value come out exactly as the editor saves them. What SCF cannot do
// is refuse input: a misspelt name or sub-field is silently dropped. So every
// name, at every depth, is checked against the field group first.

/**
 * Block names the editor allows for a post. Runs the editor's own
 * allow-list filter (allowed_block_types_all), so a child's restriction
 * (OCWOF: its acf/* blocks only) applies to MCP exactly as to the inserter.
 *
 * @param WP_Post|null $post The post being edited, or null for a new one.
 * @param string       $post_type Used for the stand-in post when $post is null.
 * @return string[]
 */
function devbase_abilities_allowed_block_names( $post = null, $post_type = 'page' ) {
	if ( ! $post instanceof WP_Post ) {
		$post = new WP_Post( (object) array(
			'ID'          => 0,
			'post_type'   => $post_type,
			'post_status' => 'auto-draft',
			'filter'      => 'raw',
		) );
	}

	$registered = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
	$allowed    = get_allowed_block_types( new WP_Block_Editor_Context( array( 'name' => 'core/edit-post', 'post' => $post ) ) );

	if ( true === $allowed ) {
		return $registered;
	}

	if ( ! is_array( $allowed ) ) {
		return array();
	}

	return array_values( array_intersect( $registered, $allowed ) );
}

/**
 * Whether a block is one this file can write: an ACF block (its content is
 * its field values). Field groups are not required; a block with no fields
 * is written with empty data.
 */
function devbase_abilities_is_field_block( $block_name ) {
	return is_string( $block_name ) && str_starts_with( $block_name, 'acf/' ) && function_exists( 'acf_get_block_fields' );
}

/**
 * A block's raw SCF field configs, from every field group located on it.
 *
 * @return array[]
 */
function devbase_abilities_block_field_configs( $block_name ) {
	if ( ! devbase_abilities_is_field_block( $block_name ) ) {
		return array();
	}

	return (array) acf_get_block_fields( array( 'name' => $block_name ) );
}

/**
 * Field configs at one level, keyed by name, without tab/message (they hold
 * no value).
 *
 * @return array<string, array>
 */
function devbase_abilities_fields_by_name( array $configs ) {
	$by_name = array();

	foreach ( $configs as $config ) {
		if ( in_array( $config['type'], array( 'tab', 'message' ), true ) || '' === (string) $config['name'] ) {
			continue;
		}

		$by_name[ $config['name'] ] = $config;
	}

	return $by_name;
}

/**
 * Turns name-keyed input into the field-key-keyed shape acf_update_values()
 * takes, rejecting anything that isn't in the field group: unknown names at
 * any depth, a value of the wrong shape for a repeater/group/flexible field,
 * a choice that isn't one, and an image/file/gallery value that isn't an
 * attachment.
 *
 * @param array  $configs Field configs at this level.
 * @param mixed  $values  The caller's values at this level (an object).
 * @param string $path    Where we are, for error messages (e.g. blocks[2].items[0]).
 * @return array|WP_Error
 */
function devbase_abilities_encode_block_values( array $configs, $values, $path ) {
	if ( ! is_array( $values ) || ( $values && array_is_list( $values ) ) ) {
		return devbase_abilities_block_error( 'devbase_invalid_block_value', $path, __( 'must be an object of field names and values', 'devbase_parent_theme' ) );
	}

	$by_name = devbase_abilities_fields_by_name( $configs );
	$keyed   = array();

	foreach ( $values as $name => $value ) {
		$field_path = "{$path}.{$name}";

		if ( ! isset( $by_name[ $name ] ) ) {
			return devbase_abilities_block_error( 'devbase_unknown_field', $field_path, __( 'is not a field here', 'devbase_parent_theme' ) );
		}

		$encoded = devbase_abilities_encode_block_value( $by_name[ $name ], $value, $field_path );

		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		$keyed[ $by_name[ $name ]['key'] ] = $encoded;
	}

	return $keyed;
}

/**
 * One field's value, checked and (for nested fields) re-keyed. See
 * devbase_abilities_encode_block_values().
 *
 * @return mixed|WP_Error
 */
function devbase_abilities_encode_block_value( array $field, $value, $path ) {
	// null clears a field, as in the editor.
	if ( null === $value ) {
		return $value;
	}

	switch ( $field['type'] ) {
		case 'repeater':
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				return devbase_abilities_block_error( 'devbase_invalid_block_value', $path, __( 'must be a list of rows', 'devbase_parent_theme' ) );
			}

			$rows = array();

			foreach ( $value as $i => $row ) {
				$encoded = devbase_abilities_encode_block_values( (array) ( $field['sub_fields'] ?? array() ), $row, "{$path}[{$i}]" );

				if ( is_wp_error( $encoded ) ) {
					return $encoded;
				}

				$rows[] = $encoded;
			}

			return $rows;

		case 'group':
			return devbase_abilities_encode_block_values( (array) ( $field['sub_fields'] ?? array() ), $value, $path );

		case 'flexible_content':
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				return devbase_abilities_block_error( 'devbase_invalid_block_value', $path, __( 'must be a list of rows', 'devbase_parent_theme' ) );
			}

			$layouts = array();

			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				$layouts[ $layout['name'] ] = $layout;
			}

			$rows = array();

			foreach ( $value as $i => $row ) {
				$layout_name = is_array( $row ) ? ( $row['acf_fc_layout'] ?? null ) : null;

				if ( ! isset( $layouts[ $layout_name ] ) ) {
					return devbase_abilities_block_error(
						'devbase_unknown_field',
						"{$path}[{$i}].acf_fc_layout",
						/* translators: %s: comma-separated layout names. */
						sprintf( __( 'must be one of: %s', 'devbase_parent_theme' ), implode( ', ', array_keys( $layouts ) ) )
					);
				}

				unset( $row['acf_fc_layout'] );
				$encoded = devbase_abilities_encode_block_values( (array) ( $layouts[ $layout_name ]['sub_fields'] ?? array() ), $row, "{$path}[{$i}]" );

				if ( is_wp_error( $encoded ) ) {
					return $encoded;
				}

				$rows[] = array_merge( array( 'acf_fc_layout' => $layout_name ), $encoded );
			}

			return $rows;

		case 'select':
		case 'radio':
		case 'button_group':
		case 'checkbox':
			$choices = array_map( 'strval', array_keys( (array) ( $field['choices'] ?? array() ) ) );

			if ( ! $choices || ! empty( $field['allow_custom'] ) || ! empty( $field['other_choice'] ) ) {
				return $value;
			}

			foreach ( (array) $value as $choice ) {
				if ( ! in_array( (string) $choice, $choices, true ) ) {
					return devbase_abilities_block_error(
						'devbase_invalid_block_value',
						$path,
						/* translators: %s: comma-separated allowed values. */
						sprintf( __( 'must be one of: %s', 'devbase_parent_theme' ), implode( ', ', $choices ) )
					);
				}
			}

			return $value;

		case 'image':
		case 'file':
		case 'gallery':
			$ids = 'gallery' === $field['type'] ? $value : array( $value );

			if ( ! is_array( $ids ) ) {
				return devbase_abilities_block_error( 'devbase_invalid_block_value', $path, __( 'must be a list of attachment IDs', 'devbase_parent_theme' ) );
			}

			foreach ( $ids as $id ) {
				if ( '' === $id || 0 === $id ) {
					continue;
				}

				if ( ! is_numeric( $id ) || 'attachment' !== get_post_type( (int) $id ) ) {
					return devbase_abilities_block_error( 'devbase_invalid_block_value', $path, __( 'must be the ID of an attachment in the media library (see devbase/upload-media)', 'devbase_parent_theme' ) );
				}
			}

			return 'gallery' === $field['type'] ? array_map( 'intval', $ids ) : (int) $value;
	}

	return $value;
}

/**
 * A WP_Error naming the exact place in the input that is wrong.
 */
function devbase_abilities_block_error( $code, $path, $problem ) {
	/* translators: 1: input path such as blocks[0].items[1].title, 2: what is wrong with it. */
	return new WP_Error( $code, sprintf( __( '%1$s %2$s.', 'devbase_parent_theme' ), $path, $problem ) );
}

/**
 * Builds post content from a list of { block, fields } entries: the same
 * block comments the editor saves. Every block must be allowed in the
 * editor for this post and be an ACF block.
 *
 * @param array        $blocks    The caller's list.
 * @param WP_Post|null $post      The post being updated, or null for a new one.
 * @param string       $post_type The post type (for a new post).
 * @return string|WP_Error
 */
function devbase_abilities_serialize_blocks( $blocks, $post, $post_type ) {
	if ( ! is_array( $blocks ) || ! array_is_list( $blocks ) ) {
		return new WP_Error( 'devbase_invalid_block_value', __( 'blocks must be a list of { block, fields } objects.', 'devbase_parent_theme' ) );
	}

	$allowed = devbase_abilities_allowed_block_names( $post, $post_type );
	$markup  = array();

	foreach ( $blocks as $i => $entry ) {
		$path = "blocks[{$i}]";
		$name = is_array( $entry ) ? ( $entry['block'] ?? null ) : null;

		if ( ! is_string( $name ) || ! in_array( $name, $allowed, true ) ) {
			return devbase_abilities_block_error( 'devbase_unknown_block', "{$path}.block", __( 'is not a block the editor allows here (see devbase/list-blocks)', 'devbase_parent_theme' ) );
		}

		if ( ! devbase_abilities_is_field_block( $name ) ) {
			return devbase_abilities_block_error( 'devbase_unsupported_block', "{$path}.block", __( 'is not an ACF block; only blocks built from fields can be written', 'devbase_parent_theme' ) );
		}

		$unknown = array_diff( array_keys( $entry ), array( 'block', 'fields' ) );

		if ( $unknown ) {
			return devbase_abilities_block_error( 'devbase_invalid_block_value', $path, __( 'may only have "block" and "fields"', 'devbase_parent_theme' ) );
		}

		$keyed = devbase_abilities_encode_block_values( devbase_abilities_block_field_configs( $name ), $entry['fields'] ?? array(), "{$path}.fields" );

		if ( is_wp_error( $keyed ) ) {
			return $keyed;
		}

		$markup[] = serialize_block( array(
			'blockName'    => $name,
			'attrs'        => array(
				'name' => $name,
				'data' => devbase_abilities_capture_block_data( $keyed ),
				'mode' => 'preview',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		) );
	}

	return implode( "\n\n", $markup );
}

/**
 * Field-key => value pairs to the flat data array SCF stores in a block
 * comment, by letting SCF itself "save" them into a throwaway local meta
 * store (see the header comment). Nothing is written to the database.
 *
 * @return array
 */
function devbase_abilities_capture_block_data( array $keyed ) {
	if ( ! $keyed ) {
		return array();
	}

	$block_id = devbase_abilities_scratch_block_id();
	$data     = acf_setup_meta( $keyed, $block_id );
	acf_reset_meta( $block_id );

	return (array) $data;
}

/**
 * A block id no other block in this request uses, so SCF's value cache can
 * never hand one block another's values.
 */
function devbase_abilities_scratch_block_id() {
	static $n = 0;
	++$n;

	return 'block_devbase_mcp_' . $n . '_' . wp_rand();
}

/**
 * Reads post content back as a list of blocks: ACF blocks as
 * { block, fields } with fields nested by name (the same shape the writer
 * takes, so a read can be edited and written back); any other block as
 * { block, markup }, its saved markup.
 *
 * @return array{built_from_blocks: bool, blocks: array[]}
 */
function devbase_abilities_describe_blocks( $content ) {
	$content = (string) $content;

	if ( ! has_blocks( $content ) ) {
		return array( 'built_from_blocks' => false, 'blocks' => array() );
	}

	$described = array();

	foreach ( parse_blocks( $content ) as $block ) {
		// The whitespace between blocks parses as a nameless block.
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		if ( devbase_abilities_is_field_block( $block['blockName'] ) ) {
			$described[] = array(
				'block'  => $block['blockName'],
				'fields' => devbase_abilities_decode_block_data( $block['blockName'], (array) ( $block['attrs']['data'] ?? array() ) ),
			);
		} else {
			$described[] = array(
				'block' => $block['blockName'],
				'markup' => serialize_block( $block ),
			);
		}
	}

	return array( 'built_from_blocks' => true, 'blocks' => $described );
}

/**
 * A block's stored data back to name-keyed, nested values: only the fields
 * actually stored (an absent field renders with its default, and writing it
 * back absent keeps that).
 *
 * @return array
 */
function devbase_abilities_decode_block_data( $block_name, array $data ) {
	if ( ! $data ) {
		return array();
	}

	$block_id = devbase_abilities_scratch_block_id();
	acf_setup_meta( $data, $block_id );

	$fields = array();

	foreach ( devbase_abilities_fields_by_name( devbase_abilities_block_field_configs( $block_name ) ) as $name => $config ) {
		if ( ! array_key_exists( $name, $data ) && ! array_key_exists( $config['key'], $data ) ) {
			continue;
		}

		$fields[ $name ] = devbase_abilities_decode_block_value( $config, acf_get_value( $block_id, $config ) );
	}

	acf_reset_meta( $block_id );

	return $fields;
}

/**
 * One raw (unformatted) SCF value to the writer's shape: nested rows keyed
 * by sub-field name instead of key.
 *
 * @return mixed
 */
function devbase_abilities_decode_block_value( array $field, $value ) {
	switch ( $field['type'] ) {
		case 'repeater':
			$rows = array();

			foreach ( is_array( $value ) ? $value : array() as $row ) {
				$rows[] = devbase_abilities_decode_block_row( (array) ( $field['sub_fields'] ?? array() ), (array) $row );
			}

			return $rows;

		case 'group':
			return devbase_abilities_decode_block_row( (array) ( $field['sub_fields'] ?? array() ), is_array( $value ) ? $value : array() );

		case 'flexible_content':
			$layouts = array();

			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				$layouts[ $layout['name'] ] = $layout;
			}

			$rows = array();

			foreach ( is_array( $value ) ? $value : array() as $row ) {
				$layout_name = $row['acf_fc_layout'] ?? '';
				$rows[]      = array_merge(
					array( 'acf_fc_layout' => $layout_name ),
					devbase_abilities_decode_block_row( (array) ( $layouts[ $layout_name ]['sub_fields'] ?? array() ), (array) $row )
				);
			}

			return $rows;

		case 'true_false':
			return (bool) $value;

		case 'image':
		case 'file':
			return $value ? (int) $value : null;

		case 'gallery':
			return array_map( 'intval', is_array( $value ) ? $value : array() );
	}

	return $value;
}

/**
 * One row (repeater/flexible row or group value) keyed by sub-field key, to
 * name-keyed values. SCF loads a sub-field that was never stored as null;
 * it is left out, like an absent top-level field, so writing the row back
 * doesn't store an empty value the editor never saved (seen on OCWOF: rows
 * saved without their optional link came back as link: null, then "").
 */
function devbase_abilities_decode_block_row( array $sub_fields, array $row ) {
	$decoded = array();

	foreach ( devbase_abilities_fields_by_name( $sub_fields ) as $name => $config ) {
		if ( array_key_exists( $config['key'], $row ) ) {
			$raw = $row[ $config['key'] ];
		} elseif ( array_key_exists( $name, $row ) ) {
			$raw = $row[ $name ];
		} else {
			continue;
		}

		if ( null !== $raw ) {
			$decoded[ $name ] = devbase_abilities_decode_block_value( $config, $raw );
		}
	}

	return $decoded;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'devbase/list-blocks', array(
		'label'               => __( 'List Blocks', 'devbase_parent_theme' ),
		'description'         => __( 'Read devbase/get-site-guide first: it says how this site\'s pages are built. Lists the blocks the editor allows on this site (name, title, description, category), and whether each can be written through devbase/create-post and update-post (ACF blocks, built from fields). Use devbase/get-block-schema for one block\'s fields.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'The post type the blocks are for, since the editor can allow different blocks per type. Defaults to page.', 'devbase_parent_theme' ),
				),
			),
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'execute_callback'    => function ( $input = array() ) {
			$post_type = $input['post_type'] ?? 'page';
			$registry  = WP_Block_Type_Registry::get_instance();
			$results   = array();

			foreach ( devbase_abilities_allowed_block_names( null, $post_type ) as $name ) {
				$type = $registry->get_registered( $name );

				$results[] = array(
					'name'        => $name,
					'title'       => $type && $type->title ? $type->title : $name,
					'description' => $type ? (string) $type->description : '',
					'category'    => $type ? (string) $type->category : '',
					'writable'    => devbase_abilities_is_field_block( $name ),
				);
			}

			return $results;
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array( 'public' => true ),
	) );

	wp_register_ability( 'devbase/get-block-schema', array(
		'label'               => __( 'Get Block Schema', 'devbase_parent_theme' ),
		'description'         => __( 'Describes one block\'s fields: name, type, whether required, default, choices, and the shape of the value to send (image fields take an attachment ID, see devbase/upload-media). Repeaters and groups list their sub_fields. Send these as { "block": name, "fields": { ... } } in the blocks list of devbase/create-post or update-post. A field left out of fields renders with its default.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'block'     => array(
					'type'        => 'string',
					'description' => __( 'The block name, from devbase/list-blocks (e.g. acf/home-hero).', 'devbase_parent_theme' ),
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'Defaults to page.', 'devbase_parent_theme' ),
				),
			),
			'required'   => array( 'block' ),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => function ( $input ) {
			$name = $input['block'];

			if ( ! in_array( $name, devbase_abilities_allowed_block_names( null, $input['post_type'] ?? 'page' ), true ) ) {
				return new WP_Error( 'devbase_unknown_block', __( 'Unknown block, or not one the editor allows (see devbase/list-blocks).', 'devbase_parent_theme' ) );
			}

			$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

			return array(
				'name'        => $name,
				'title'       => $type && $type->title ? $type->title : $name,
				'description' => $type ? (string) $type->description : '',
				'writable'    => devbase_abilities_is_field_block( $name ),
				'fields'      => devbase_abilities_shape_fields( devbase_abilities_block_field_configs( $name ), true ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array( 'public' => true ),
	) );
} );
