<?php
// Covers inc/abilities/blocks.php and media.php: devbase/list-blocks,
// get-block-schema, upload-media, and building/reading a page from blocks
// through devbase/create-post, update-post and get-post. The test block is
// registered the way a child theme registers one (an acf/* block type plus a
// local field group located on it), so nothing here knows any real site's
// blocks.

class Test_MCP_Abilities_Blocks extends WP_UnitTestCase {

	const BLOCK = 'acf/devbase-test';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_field( 'mcp_allowed_post_types', array( 'page' ), 'option' );

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			register_block_type( self::BLOCK, array(
				'title'       => 'Devbase Test',
				'description' => 'A block for the tests.',
				'category'    => 'text',
			) );
		}

		acf_add_local_field_group( array(
			'key'      => 'group_devbase_test_block',
			'title'    => 'Devbase Test Block',
			'fields'   => array(
				array( 'key' => 'field_dbt_heading', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text', 'default_value' => 'Default heading', 'required' => 1 ),
				array( 'key' => 'field_dbt_tab', 'name' => '', 'label' => 'Settings', 'type' => 'tab' ),
				array( 'key' => 'field_dbt_layout', 'name' => 'layout', 'label' => 'Layout', 'type' => 'select', 'choices' => array( 'rows' => 'Rows', 'tiles' => 'Tiles' ), 'default_value' => 'rows' ),
				array( 'key' => 'field_dbt_image', 'name' => 'image', 'label' => 'Image', 'type' => 'image', 'return_format' => 'id' ),
				array( 'key' => 'field_dbt_show', 'name' => 'show', 'label' => 'Show', 'type' => 'true_false' ),
				array(
					'key'        => 'field_dbt_items',
					'name'       => 'items',
					'label'      => 'Items',
					'type'       => 'repeater',
					'min'        => 1,
					'sub_fields' => array(
						array( 'key' => 'field_dbt_item_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
						array( 'key' => 'field_dbt_item_link', 'name' => 'link', 'label' => 'Link', 'type' => 'link', 'return_format' => 'array' ),
					),
				),
				array(
					'key'        => 'field_dbt_cta',
					'name'       => 'cta',
					'label'      => 'Call to action',
					'type'       => 'group',
					'sub_fields' => array(
						array( 'key' => 'field_dbt_cta_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text' ),
					),
				),
			),
			'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => self::BLOCK ) ) ),
		) );
	}

	public function tear_down() {
		update_field( 'mcp_allowed_post_types', array(), 'option' );
		update_field( 'mcp_allow_media_upload', 0, 'option' );
		acf_remove_local_field_group( 'group_devbase_test_block' );

		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			unregister_block_type( self::BLOCK );
		}

		parent::tear_down();
	}

	private function ability( $name, $input = array() ) {
		return wp_get_ability( $name )->execute( $input );
	}

	private function attachment() {
		return self::factory()->attachment->create_object( 'photo.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
	}

	private function create_page( array $blocks ) {
		return $this->ability( 'devbase/create-post', array( 'post_type' => 'page', 'title' => 'Built From Blocks', 'blocks' => $blocks ) );
	}

	// --- list-blocks / get-block-schema ---------------------------------------

	public function test_list_blocks_lists_registered_blocks_and_marks_acf_blocks_writable() {
		$blocks = array_column( $this->ability( 'devbase/list-blocks' ), null, 'name' );

		$this->assertArrayHasKey( self::BLOCK, $blocks );
		$this->assertSame( 'Devbase Test', $blocks[ self::BLOCK ]['title'] );
		$this->assertTrue( $blocks[ self::BLOCK ]['writable'] );
		$this->assertArrayHasKey( 'core/paragraph', $blocks );
		$this->assertFalse( $blocks['core/paragraph']['writable'] );
	}

	public function test_list_blocks_follows_the_editor_allow_list() {
		$only_test_block = function () {
			return array( self::BLOCK );
		};
		add_filter( 'allowed_block_types_all', $only_test_block );

		$names = wp_list_pluck( $this->ability( 'devbase/list-blocks' ), 'name' );

		remove_filter( 'allowed_block_types_all', $only_test_block );
		$this->assertSame( array( self::BLOCK ), $names );
	}

	public function test_get_block_schema_describes_fields_for_a_writer() {
		$schema = $this->ability( 'devbase/get-block-schema', array( 'block' => self::BLOCK ) );
		$fields = array_column( $schema['fields'], null, 'name' );

		$this->assertTrue( $schema['writable'] );
		$this->assertArrayNotHasKey( '', $fields, 'Tabs hold no value and must not be listed.' );
		$this->assertTrue( $fields['heading']['required'] );
		$this->assertSame( 'Default heading', $fields['heading']['default'] );
		$this->assertSame( array( 'rows' => 'Rows', 'tiles' => 'Tiles' ), $fields['layout']['choices'] );
		$this->assertStringContainsString( 'attachment ID', $fields['image']['value'] );
		$this->assertSame( 1, $fields['items']['min'] );
		$this->assertSame( array( 'title', 'link' ), wp_list_pluck( $fields['items']['sub_fields'], 'name' ) );
		$this->assertSame( 'label', $fields['cta']['sub_fields'][0]['name'] );
	}

	public function test_get_block_schema_rejects_an_unknown_or_disallowed_block() {
		$result = $this->ability( 'devbase/get-block-schema', array( 'block' => 'acf/does-not-exist' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_block', $result->get_error_code() );

		$no_blocks = function () {
			return array();
		};
		add_filter( 'allowed_block_types_all', $no_blocks );
		$result = $this->ability( 'devbase/get-block-schema', array( 'block' => self::BLOCK ) );
		remove_filter( 'allowed_block_types_all', $no_blocks );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_block', $result->get_error_code() );
	}

	public function test_detailed_schema_does_not_change_the_post_type_schema_shape() {
		$shaped = devbase_abilities_shape_fields( array(
			array( 'key' => 'field_x', 'name' => 'x', 'label' => 'X', 'type' => 'select', 'choices' => array( 'a' => 'A' ), 'required' => 1 ),
		) );

		$this->assertSame( array( array( 'name' => 'x', 'label' => 'X', 'type' => 'select' ) ), $shaped );
	}

	// --- writing ----------------------------------------------------------------

	public function test_create_post_from_blocks_writes_what_the_editor_would_save() {
		$image = $this->attachment();

		$created = $this->create_page( array(
			array(
				'block'  => self::BLOCK,
				'fields' => array(
					'heading' => 'Hello <em>sea</em>',
					'layout'  => 'tiles',
					'image'   => $image,
					'show'    => true,
					'items'   => array(
						array( 'title' => 'First', 'link' => array( 'title' => 'Go', 'url' => '/donate/', 'target' => '' ) ),
						array( 'title' => 'Second' ),
					),
					'cta'     => array( 'label' => 'Give' ),
				),
			),
		) );

		$this->assertIsArray( $created );
		$this->assertSame( 'draft', $created['status'] );

		$parsed = array_values( array_filter( parse_blocks( get_post( $created['id'] )->post_content ), function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		$this->assertCount( 1, $parsed );
		$this->assertSame( self::BLOCK, $parsed[0]['blockName'] );
		$this->assertSame( self::BLOCK, $parsed[0]['attrs']['name'] );

		$data = $parsed[0]['attrs']['data'];
		$this->assertSame( 'Hello <em>sea</em>', $data['heading'] );
		$this->assertSame( 'field_dbt_heading', $data['_heading'] );
		$this->assertSame( $image, (int) $data['image'] );
		$this->assertEquals( 2, $data['items'], 'A repeater stores its row count.' );
		$this->assertSame( 'First', $data['items_0_title'] );
		$this->assertSame( 'field_dbt_item_title', $data['_items_0_title'] );
		$this->assertSame( '/donate/', $data['items_0_link']['url'] );
		$this->assertSame( 'Second', $data['items_1_title'] );
		$this->assertSame( 'Give', $data['cta_label'] );

		// And SCF reads it back the way a block's render callback would.
		acf_setup_meta( $data, 'block_test_read', true );
		$items = get_field( 'items', 'block_test_read' );
		acf_reset_meta( 'block_test_read' );
		$this->assertSame( 'Second', $items[1]['title'] );
	}

	public function test_get_post_reads_blocks_back_in_the_shape_the_writer_takes() {
		$image  = $this->attachment();
		$fields = array(
			'heading' => 'Round trip',
			'layout'  => 'rows',
			'image'   => $image,
			'show'    => false,
			'items'   => array(
				array( 'title' => 'A', 'link' => array( 'title' => 'T', 'url' => 'https://example.org/', 'target' => '_blank' ) ),
			),
			'cta'     => array( 'label' => 'Go' ),
		);

		$created = $this->create_page( array( array( 'block' => self::BLOCK, 'fields' => $fields ) ) );
		$read    = $this->ability( 'devbase/get-post', array( 'post_type' => 'page', 'id' => $created['id'] ) );

		$this->assertTrue( $read['built_from_blocks'] );
		$this->assertCount( 1, $read['blocks'] );
		$this->assertSame( self::BLOCK, $read['blocks'][0]['block'] );
		$this->assertEquals( $fields, $read['blocks'][0]['fields'] );

		// Written back unchanged, it stores the same content.
		$before = get_post( $created['id'] )->post_content;
		$this->ability( 'devbase/update-post', array( 'post_type' => 'page', 'id' => $created['id'], 'blocks' => $read['blocks'] ) );
		$this->assertSame( $before, get_post( $created['id'] )->post_content );
	}

	public function test_fields_left_out_are_not_stored_so_they_render_with_their_default() {
		$created = $this->create_page( array( array( 'block' => self::BLOCK, 'fields' => array( 'layout' => 'tiles' ) ) ) );
		$read    = $this->ability( 'devbase/get-post', array( 'post_type' => 'page', 'id' => $created['id'] ) );

		$this->assertSame( array( 'layout' => 'tiles' ), $read['blocks'][0]['fields'] );
	}

	public function test_a_sub_field_never_stored_stays_absent_through_a_round_trip() {
		// What the editor saves for a row whose optional link was never set:
		// no items_0_link key at all. Found on the OCWOF pages.
		$data = array(
			'items'          => 1,
			'_items'         => 'field_dbt_items',
			'items_0_title'  => 'Only a title',
			'_items_0_title' => 'field_dbt_item_title',
		);
		$id   = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_content' => serialize_block( array( 'blockName' => self::BLOCK, 'attrs' => array( 'name' => self::BLOCK, 'data' => $data, 'mode' => 'preview' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ),
		) );

		$read = $this->ability( 'devbase/get-post', array( 'post_type' => 'page', 'id' => $id ) );
		$this->assertSame( array( array( 'title' => 'Only a title' ) ), $read['blocks'][0]['fields']['items'] );

		$this->ability( 'devbase/update-post', array( 'post_type' => 'page', 'id' => $id, 'blocks' => $read['blocks'] ) );
		$stored = parse_blocks( get_post( $id )->post_content )[0]['attrs']['data'];
		$this->assertArrayNotHasKey( 'items_0_link', $stored );
		$this->assertEqualsCanonicalizing( $data, $stored );
	}

	public function test_update_post_with_blocks_replaces_the_whole_content() {
		$created = $this->create_page( array(
			array( 'block' => self::BLOCK, 'fields' => array( 'heading' => 'One' ) ),
			array( 'block' => self::BLOCK, 'fields' => array( 'heading' => 'Two' ) ),
		) );

		$updated = $this->ability( 'devbase/update-post', array(
			'post_type' => 'page',
			'id'        => $created['id'],
			'blocks'    => array( array( 'block' => self::BLOCK, 'fields' => array( 'heading' => 'Only' ) ) ),
		) );

		$this->assertCount( 1, $updated['blocks'] );
		$this->assertSame( 'Only', $updated['blocks'][0]['fields']['heading'] );
	}

	public function test_post_responses_say_when_content_is_not_built_from_blocks() {
		$id   = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '<p>Classic</p>' ) );
		$read = $this->ability( 'devbase/get-post', array( 'post_type' => 'page', 'id' => $id ) );

		$this->assertFalse( $read['built_from_blocks'] );
		$this->assertSame( array(), $read['blocks'] );
	}

	public function test_core_blocks_are_read_back_as_markup() {
		$markup = "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->";
		$id     = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => $markup ) );
		$read   = $this->ability( 'devbase/get-post', array( 'post_type' => 'page', 'id' => $id ) );

		$this->assertSame( array( array( 'block' => 'core/paragraph', 'markup' => $markup ) ), $read['blocks'] );
	}

	// --- refusals ---------------------------------------------------------------

	/**
	 * @dataProvider provider_invalid_blocks
	 */
	public function test_invalid_blocks_are_refused_and_nothing_is_created( $blocks, $code, $path ) {
		$before = wp_count_posts( 'page' );
		$result = $this->create_page( $blocks );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertStringContainsString( $path, $result->get_error_message() );
		$this->assertEquals( $before, wp_count_posts( 'page' ) );
	}

	public function provider_invalid_blocks() {
		return array(
			'unknown block'           => array( array( array( 'block' => 'acf/nope' ) ), 'devbase_unknown_block', 'blocks[0].block' ),
			'core block'              => array( array( array( 'block' => 'core/paragraph' ) ), 'devbase_unsupported_block', 'blocks[0].block' ),
			'unknown top field'       => array( array( array( 'block' => self::BLOCK, 'fields' => array( 'headline' => 'x' ) ) ), 'devbase_unknown_field', 'blocks[0].fields.headline' ),
			'unknown sub-field'       => array( array( array( 'block' => self::BLOCK, 'fields' => array( 'items' => array( array( 'titel' => 'x' ) ) ) ) ), 'devbase_unknown_field', 'blocks[0].fields.items[0].titel' ),
			'unknown group sub-field' => array( array( array( 'block' => self::BLOCK, 'fields' => array( 'cta' => array( 'text' => 'x' ) ) ) ), 'devbase_unknown_field', 'blocks[0].fields.cta.text' ),
			'invalid choice'          => array( array( array( 'block' => self::BLOCK, 'fields' => array( 'layout' => 'grid' ) ) ), 'devbase_invalid_block_value', 'blocks[0].fields.layout' ),
			'repeater not a list'     => array( array( array( 'block' => self::BLOCK, 'fields' => array( 'items' => array( 'title' => 'x' ) ) ) ), 'devbase_invalid_block_value', 'blocks[0].fields.items' ),
			'image not attachment'    => array( array( array( 'block' => self::BLOCK, 'fields' => array( 'image' => 999999 ) ) ), 'devbase_invalid_block_value', 'blocks[0].fields.image' ),
			'extra entry key'         => array( array( array( 'block' => self::BLOCK, 'data' => array() ) ), 'devbase_invalid_block_value', 'blocks[0]' ),
			'second block bad'        => array( array( array( 'block' => self::BLOCK ), array( 'block' => self::BLOCK, 'fields' => array( 'x' => 1 ) ) ), 'devbase_unknown_field', 'blocks[1].fields.x' ),
		);
	}

	public function test_blocks_and_content_together_are_refused() {
		$result = $this->ability( 'devbase/create-post', array( 'post_type' => 'page', 'content' => '<p>x</p>', 'blocks' => array() ) );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_blocks_and_content', $result->get_error_code() );
	}

	public function test_a_block_the_editor_disallows_cannot_be_written() {
		$no_blocks = function () {
			return array();
		};
		add_filter( 'allowed_block_types_all', $no_blocks );
		$result = $this->create_page( array( array( 'block' => self::BLOCK ) ) );
		remove_filter( 'allowed_block_types_all', $no_blocks );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_unknown_block', $result->get_error_code() );
	}

	// --- upload-media -----------------------------------------------------------

	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	public function test_upload_media_is_off_until_switched_on() {
		$result = $this->ability( 'devbase/upload-media', array( 'data' => self::PNG, 'filename' => 'dot.png' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'devbase_media_upload_disabled', $result->get_error_code() );
	}

	public function test_upload_media_adds_an_attachment_a_block_can_use() {
		update_field( 'mcp_allow_media_upload', 1, 'option' );

		$result = $this->ability( 'devbase/upload-media', array( 'data' => self::PNG, 'filename' => 'dot.png', 'alt' => 'A dot' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'attachment', get_post_type( $result['id'] ) );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'A dot', $result['alt'] );

		$created = $this->create_page( array( array( 'block' => self::BLOCK, 'fields' => array( 'image' => $result['id'] ) ) ) );
		$this->assertIsArray( $created );
	}

	public function test_upload_media_refuses_bad_input() {
		update_field( 'mcp_allow_media_upload', 1, 'option' );

		foreach ( array(
			array(),
			array( 'data' => self::PNG, 'url' => 'https://example.org/a.png', 'filename' => 'a.png' ),
			array( 'data' => self::PNG ),
			array( 'data' => '%%%not base64%%%', 'filename' => 'a.png' ),
			array( 'url' => 'ftp://example.org/a.png' ),
		) as $input ) {
			$result = $this->ability( 'devbase/upload-media', $input );
			$this->assertWPError( $result );
			$this->assertSame( 'devbase_invalid_media', $result->get_error_code() );
		}
	}

	public function test_upload_media_refuses_a_file_type_wordpress_refuses() {
		update_field( 'mcp_allow_media_upload', 1, 'option' );

		$result = $this->ability( 'devbase/upload-media', array( 'data' => base64_encode( '<?php echo 1;' ), 'filename' => 'shell.php' ) );

		$this->assertWPError( $result );
	}

	public function test_upload_media_needs_upload_files() {
		update_field( 'mcp_allow_media_upload', 1, 'option' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->ability( 'devbase/upload-media', array( 'data' => self::PNG, 'filename' => 'dot.png' ) );

		$this->assertWPError( $result );
	}

	public function test_the_media_upload_switch_cannot_be_changed_through_mcp() {
		$this->assertContains( 'mcp_allow_media_upload', devbase_abilities_protected_option_fields() );
		$this->assertNotContains( 'mcp_allow_media_upload', wp_list_pluck( devbase_abilities_options_page_fields( 'miscellaneous-settings' ), 'name' ) );
	}
}
