<?php
// Covers inc/theme-settings/schema/: the Organization Details fields and their merge into The SEO
// Framework's Organization entity in its JSON-LD graph. Name, URL, logo and profile links REPLACE TSF's when
// filled; everything else is only added.

class Test_Organization_Schema extends WP_UnitTestCase {

	const PREFIX = 'field_devbase_schema_organization_';

	public function set_up() {
		parent::set_up();
		acf_get_store( 'values' )->reset(); // ACF keeps read values per request; a test that writes the options directly needs a clean one.
	}

	public function tear_down() {
		acf_get_store( 'values' )->reset();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'options\\_organization\\_%' OR option_name LIKE '\\_options\\_organization\\_%' OR option_name LIKE 'options\\_person\\_%' OR option_name LIKE '\\_options\\_person\\_%'" );
		wp_cache_flush();
		parent::tear_down();
	}

	private function save( $key, $value ) {
		update_field( self::PREFIX . $key, $value, 'option' );
	}

	private function graph() {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'WebSite', '@id' => 'https://x.test/#/schema/WebSite', 'publisher' => array( '@id' => 'https://x.test/#/schema/Organization' ) ),
				array( '@type' => 'Organization', '@id' => 'https://x.test/#/schema/Organization', 'name' => 'TSF name', 'url' => 'https://x.test/' ),
			),
		);
	}

	private function merged( array $graph ) {
		return apply_filters( 'the_seo_framework_schema_graph_data', $graph, null );
	}

	public function test_the_page_holds_every_organization_field_and_not_the_old_enable_switch() {
		$this->assertIsArray( acf_get_field_group( 'group_devbase_schema' ) );
		$names = wp_list_pluck( acf_get_fields( 'group_devbase_schema' ), 'name' );

		foreach ( array( 'organization_type', 'organization_name', 'organization_url', 'organization_logo', 'organization_same_as', 'organization_description', 'organization_slogan', 'organization_founding_date', 'organization_knows_about', 'organization_locations', 'person_image', 'person_job_title' ) as $name ) {
			$this->assertContains( $name, $names );
		}
		$this->assertNotContains( 'organization_enabled', $names, 'TSF\'s knowledge-graph switch is the master switch.' );
	}

	public function test_nothing_is_changed_when_nothing_is_filled() {
		$this->assertSame( $this->graph(), $this->merged( $this->graph() ) );
	}

	public function test_added_fields_fill_gaps_and_never_replace_a_key_tsf_printed() {
		$this->save( 'description', 'We make widgets.' );
		$this->save( 'slogan', 'Quality' );
		$this->save( 'founding_date', '2010-01-15' );

		$graph = $this->graph();
		$graph['@graph'][1]['slogan'] = 'TSF slogan'; // pretend TSF printed one

		$org = $this->merged( $graph )['@graph'][1];

		$this->assertSame( 'We make widgets.', $org['description'] );
		$this->assertSame( '2010-01-15', $org['foundingDate'] );
		$this->assertSame( 'TSF slogan', $org['slogan'], 'A key TSF printed is not replaced by an added field.' );
		$this->assertArrayNotHasKey( 'description', $this->merged( $this->graph() )['@graph'][0], 'Only the Organization is touched.' );
	}

	public function test_a_filled_name_and_url_replace_tsfs_and_empty_ones_leave_them() {
		$org = $this->merged( $this->graph() )['@graph'][1];
		$this->assertSame( 'TSF name', $org['name'] );

		$this->save( 'name', 'Our Name' );
		$this->save( 'url', 'https://ours.test/' );
		$org = $this->merged( $this->graph() )['@graph'][1];

		$this->assertSame( 'Our Name', $org['name'] );
		$this->assertSame( 'https://ours.test/', $org['url'] );
		$this->assertSame( 'https://x.test/#/schema/Organization', $org['@id'], 'The id other nodes point at is kept.' );
	}

	public function test_our_profile_links_replace_tsfs_list() {
		$graph                           = $this->graph();
		$graph['@graph'][1]['sameAs']    = array( 'https://tsf.example/profile' );
		$this->save( 'same_as', array( array( 'url' => 'https://www.linkedin.com/company/x' ), array( 'url' => '' ), array( 'url' => 'https://www.facebook.com/x' ) ) );

		$this->assertSame( array( 'https://www.linkedin.com/company/x', 'https://www.facebook.com/x' ), $this->merged( $graph )['@graph'][1]['sameAs'] );
	}

	public function test_an_empty_profile_list_keeps_tsfs() {
		$graph                        = $this->graph();
		$graph['@graph'][1]['sameAs'] = array( 'https://tsf.example/profile' );

		$this->assertSame( array( 'https://tsf.example/profile' ), $this->merged( $graph )['@graph'][1]['sameAs'] );
	}

	public function test_a_logo_becomes_an_image_object_that_replaces_tsfs() {
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$graph      = $this->graph();
		$graph['@graph'][1]['logo'] = array( '@type' => 'ImageObject', 'url' => 'https://tsf.example/logo.png' );
		$this->save( 'logo', $attachment );

		$logo = $this->merged( $graph )['@graph'][1]['logo'];

		$this->assertSame( 'ImageObject', $logo['@type'] );
		$this->assertSame( wp_get_attachment_url( $attachment ), $logo['url'] );
		$this->assertSame( $logo['url'], $logo['contentUrl'] );
	}

	public function test_the_key_order_tsf_printed_is_kept() {
		$this->save( 'name', 'Our Name' );

		$this->assertSame( array( '@type', '@id', 'name', 'url' ), array_keys( $this->merged( $this->graph() )['@graph'][1] ) );
	}

	public function test_tsfs_schema_settings_box_points_at_our_page() {
		ob_start();
		do_action( 'the_seo_framework_schema_metabox_before' );
		$html = ob_get_clean();

		$this->assertStringContainsString( admin_url( 'admin.php?page=schema-settings' ), $html );
		$this->assertStringContainsString( 'Organization', $html );
	}

	public function test_an_organization_inlined_in_its_referrer_is_merged_too() {
		$this->save( 'slogan', 'Inline' );
		$graph = array( '@graph' => array(
			array( '@type' => 'WebSite', '@id' => 'https://x.test/#/schema/WebSite', 'publisher' => array( '@type' => 'Organization', '@id' => 'https://x.test/#/schema/Organization', 'name' => 'N' ) ),
		) );

		$this->assertSame( 'Inline', $this->merged( $graph )['@graph'][0]['publisher']['slogan'] );
	}

	public function test_a_person_site_gets_nothing() {
		$this->save( 'slogan', 'Nope' );
		$graph = array( '@graph' => array( array( '@type' => 'Person', '@id' => 'https://x.test/#/schema/Person', 'name' => 'P' ) ) );

		$this->assertSame( $graph, $this->merged( $graph ) );
	}

	public function test_topics_become_knows_about_and_blank_rows_are_dropped() {
		$this->save( 'knows_about', array( array( 'topic' => 'SEO' ), array( 'topic' => '  ' ), array( 'topic' => 'Widgets' ) ) );

		$this->assertSame( array( 'SEO', 'Widgets' ), $this->merged( $this->graph() )['@graph'][1]['knowsAbout'] );
	}

	public function test_the_main_location_becomes_the_address() {
		$this->save( 'locations', array(
			array( 'label' => 'Branch', 'is_main' => 0, 'street_address' => '2 Side St', 'address_locality' => 'Laval', 'address_country' => 'CA' ),
			array( 'label' => 'Head', 'is_main' => 1, 'street_address' => '1 Main St', 'address_locality' => 'Montreal', 'address_region' => 'QC', 'postal_code' => 'H1H 1H1', 'address_country' => 'CA' ),
		) );

		$address = $this->merged( $this->graph() )['@graph'][1]['address'];

		$this->assertSame( 'PostalAddress', $address['@type'] );
		$this->assertSame( '1 Main St', $address['streetAddress'] );
		$this->assertSame( 'Montreal', $address['addressLocality'] );
	}

	public function test_without_a_main_flag_the_first_location_is_used() {
		$this->save( 'locations', array( array( 'is_main' => 0, 'street_address' => 'First St' ) ) );

		$this->assertSame( 'First St', $this->merged( $this->graph() )['@graph'][1]['address']['streetAddress'] );
	}

	public function test_a_location_with_only_an_admin_label_adds_no_address() {
		$this->save( 'locations', array( array( 'label' => 'Only an admin label' ) ) );

		$this->assertArrayNotHasKey( 'address', $this->merged( $this->graph() )['@graph'][1] );
	}

	public function test_a_date_saved_without_dashes_is_written_as_iso() {
		update_option( 'options_organization_founding_date', '20100115' );

		$this->assertSame( '2010-01-15', $this->merged( $this->graph() )['@graph'][1]['foundingDate'] );
	}

	public function test_values_saved_before_the_theme_rename_are_still_read() {
		// A site saved under the old name has `field_boostway_...` in the reference row next to the value.
		update_option( 'options_organization_knows_about', 1 );
		update_option( 'options_organization_knows_about_0_topic', 'Legacy topic' );
		update_option( '_options_organization_knows_about', 'field_boostway_schema_organization_knows_about' );
		update_option( '_options_organization_knows_about_0_topic', 'field_boostway_schema_organization_knows_about_topic' );

		$this->assertSame( array( 'Legacy topic' ), $this->merged( $this->graph() )['@graph'][1]['knowsAbout'] );
	}

	// --- Organization or Person ---------------------------------------------------------

	private function person_graph() {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'WebSite', '@id' => 'https://x.test/#/schema/WebSite', 'publisher' => array( '@id' => 'https://x.test/#/schema/Person' ) ),
				array( '@type' => 'Person', '@id' => 'https://x.test/#/schema/Person', 'name' => 'TSF person', 'url' => 'https://x.test/' ),
				array( '@type' => 'Person', '@id' => 'https://x.test/#/schema/Person/a1b2c3', 'name' => 'A post author' ),
			),
		);
	}

	private function set_type( $type ) {
		update_option( 'options_organization_type', $type );
		acf_get_store( 'values' )->reset();
	}

	public function test_the_select_defaults_to_organization_and_each_type_shows_only_its_own_fields() {
		$fields = array_column( acf_get_fields( 'group_devbase_schema' ), null, 'name' );

		$this->assertSame( array( 'organization' => $fields['organization_type']['choices']['organization'], 'person' => $fields['organization_type']['choices']['person'] ), $fields['organization_type']['choices'] );
		$this->assertSame( 'organization', $fields['organization_type']['default_value'] );

		foreach ( array( 'organization_logo', 'organization_slogan', 'organization_founding_date', 'organization_locations' ) as $name ) {
			$this->assertSame( '!=', $fields[ $name ]['conditional_logic'][0][0]['operator'], "$name is for an organization." );
			$this->assertSame( 'person', $fields[ $name ]['conditional_logic'][0][0]['value'] );
		}
		foreach ( array( 'person_image', 'person_job_title' ) as $name ) {
			$this->assertSame( '==', $fields[ $name ]['conditional_logic'][0][0]['operator'], "$name is for a person." );
		}
		foreach ( array( 'organization_name', 'organization_url', 'organization_same_as', 'organization_description', 'organization_knows_about' ) as $name ) {
			$this->assertEmpty( $fields[ $name ]['conditional_logic'] ?? array(), "$name is shared by both." );
		}
	}

	public function test_a_person_gets_the_shared_fields_a_photo_and_a_job_title() {
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->set_type( 'person' );
		$this->save( 'name', 'Jane Doe' );
		$this->save( 'description', 'A consultant.' );
		$this->save( 'knows_about', array( array( 'topic' => 'SEO' ) ) );
		$this->save( 'same_as', array( array( 'url' => 'https://www.linkedin.com/in/jane' ) ) );
		update_field( 'field_devbase_schema_person_job_title', 'Consultant', 'option' );
		update_field( 'field_devbase_schema_person_image', $attachment, 'option' );

		$person = $this->merged( $this->person_graph() )['@graph'][1];

		$this->assertSame( 'Jane Doe', $person['name'] );
		$this->assertSame( 'A consultant.', $person['description'] );
		$this->assertSame( array( 'SEO' ), $person['knowsAbout'] );
		$this->assertSame( array( 'https://www.linkedin.com/in/jane' ), $person['sameAs'] );
		$this->assertSame( 'Consultant', $person['jobTitle'] );
		$this->assertSame( 'ImageObject', $person['image']['@type'] );
		$this->assertSame( wp_get_attachment_url( $attachment ), $person['image']['url'] );
		$this->assertSame( 'https://x.test/#/schema/Person', $person['@id'] );
	}

	public function test_a_person_never_gets_the_organization_only_fields() {
		$this->set_type( 'person' );
		$this->save( 'slogan', 'Quality' );
		$this->save( 'founding_date', '2010-01-15' );
		$this->save( 'locations', array( array( 'is_main' => 1, 'street_address' => '1 Main St' ) ) );

		$person = $this->merged( $this->person_graph() )['@graph'][1];

		foreach ( array( 'slogan', 'foundingDate', 'address', 'logo' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $person );
		}
	}

	public function test_a_post_author_is_not_the_site_person() {
		$this->set_type( 'person' );
		$this->save( 'name', 'Jane Doe' );

		$author = $this->merged( $this->person_graph() )['@graph'][2];

		$this->assertSame( 'A post author', $author['name'], 'An id of #/schema/Person/{hash} is an author, not the site.' );
	}

	public function test_a_person_node_is_left_alone_when_the_page_says_organization() {
		$this->save( 'name', 'Old organization name' );

		$this->assertSame( $this->person_graph(), $this->merged( $this->person_graph() ), 'Never saved, or saved as organization: only an Organization is touched.' );
	}

	public function test_an_organization_node_is_left_alone_when_the_page_says_person() {
		$this->set_type( 'person' );
		$this->save( 'name', 'Jane Doe' );

		$this->assertSame( $this->graph(), $this->merged( $this->graph() ) );
	}

	public function test_the_select_drives_tsfs_type_only_once_it_was_saved() {
		$tsf = array( 'knowledge_type' => 'person', 'knowledge_name' => 'X' ); // a site's own TSF, set to Person

		$this->assertSame( 'person', apply_filters( 'the_seo_framework_get_options', $tsf, 'autodescription-site-settings', false )['knowledge_type'], 'Never saved: TSF keeps its own choice.' );

		$this->set_type( 'organization' );
		$this->assertSame( 'organization', apply_filters( 'the_seo_framework_get_options', $tsf, 'autodescription-site-settings', false )['knowledge_type'] );

		$this->set_type( 'person' );
		$this->assertSame( 'person', apply_filters( 'the_seo_framework_get_options', array( 'knowledge_type' => 'organization' ), 'autodescription-site-settings', false )['knowledge_type'] );
		$this->assertSame( 'X', apply_filters( 'the_seo_framework_get_options', $tsf, 'autodescription-site-settings', false )['knowledge_name'], 'Nothing else is touched.' );
	}

	public function test_an_unknown_saved_type_changes_nothing() {
		update_option( 'options_organization_type', 'robot' );

		$this->assertSame( 'person', apply_filters( 'the_seo_framework_get_options', array( 'knowledge_type' => 'person' ), 'x', false )['knowledge_type'] );
		$this->assertSame( 'organization', devbase_schema_site_type() );
	}
}
