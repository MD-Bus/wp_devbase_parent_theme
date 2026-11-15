<?php
// Covers inc/settings-app/: the section registry (groups, order, per-section capability, the filter), that every field section
// points at a real field group and a real classic page, devbase_settings_page_is() on the classic page AND the app, the
// admin-only rule on the screen, what a render contains, the Forms and Submissions screens (status, filters, pagination), and a
// source guard so no page-gated code goes back to reading $_GET['page'] directly (that would silently stop firing in the app).

class Test_Settings_App extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_GET['page'], $_GET['section'], $_GET['saved'], $_GET['sub'], $_GET['paged'], $_GET['form'], $_GET['status'], $_GET['q'] );
		remove_all_filters( 'devbase/app/sections' );
		remove_all_filters( 'devbase/app/per_page' );
		remove_all_filters( 'devbase/settings/capability' );
		delete_option( 'options_hide_registered_forms_ui' );
		delete_option( 'options_hide_form_data_ui' );

		parent::tear_down();
	}

	private function as_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** The classic-page sections only (the ones the page-gate maps to a classic slug and an SCF group). */
	private function field_sections() {
		return array_filter( devbase_app_sections(), function ( $s ) {
			return empty( $s['render'] ) && ! empty( $s['classic'] ) && 0 !== strpos( $s['classic'], 'archive-' );
		} );
	}

	private function submission( $form, $name, array $extra = array() ) {
		$id = self::factory()->post->create( array(
			'post_type'   => 'form_data',
			'post_status' => 'publish',
			'post_title'  => $form . ' test',
			'post_date'   => isset( $extra['date'] ) ? $extra['date'] : current_time( 'mysql' ),
			'meta_input'  => array_merge(
				array(
					'form_name' => $form,
					'content'   => wp_slash( wp_json_encode( array( array( 'aria_label' => 'Name', 'value' => $name ), array( 'aria_label' => 'Email', 'value' => 'x@example.com' ) ) ) ),
				),
				array()
			),
		) );

		// Creating it runs the real dispatch (save_post_form_data), which writes its own log. Start each test from a clean
		// state, then set what the test asks for.
		delete_post_meta( $id, '_devbase_dispatch_log' );
		delete_post_meta( $id, '_devbase_dispatched' );

		foreach ( isset( $extra['meta'] ) ? $extra['meta'] : array() as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	// --- the registry ---------------------------------------------------------------------------

	public function test_the_settings_sections_are_registered_in_order() {
		$this->as_admin();

		$this->assertSame(
			array( 'smtp', 'security', 'tracking', 'destinations', 'miscellaneous', 'licence', 'organization' ),
			array_values( array_intersect( array_keys( devbase_app_sections() ), array( 'smtp', 'security', 'tracking', 'organization', 'destinations', 'miscellaneous', 'licence' ) ) ),
			'The six settings sections in this order, then Organization Details in the SEO group (left out while TSF is off).'
		);
	}

	public function test_forms_and_submissions_are_sections_of_the_forms_group_after_settings() {
		$this->as_admin();

		$sections = devbase_app_sections();

		$this->assertArrayHasKey( 'forms', $sections );
		$this->assertArrayHasKey( 'submissions', $sections );
		$this->assertSame( 'forms', $sections['forms']['group'] );

		$ids = array_keys( $sections );
		$this->assertGreaterThan( array_search( 'licence', $ids, true ), array_search( 'forms', $ids, true ), 'The Forms group comes after Settings.' );
	}

	public function test_every_field_section_names_real_field_groups_with_fields() {
		$this->as_admin();

		foreach ( $this->field_sections() as $id => $section ) {
			$this->assertNotEmpty( $section['title'], $id );
			$this->assertNotEmpty( $section['groups'], "{$id} has no field groups." );

			foreach ( $section['groups'] as $key ) {
				$group = acf_get_field_group( $key );

				$this->assertIsArray( $group, "{$id}: field group {$key} does not exist." );
				$this->assertNotEmpty( acf_get_fields( $group ), "{$id}: field group {$key} has no fields." );
			}
		}
	}

	public function test_every_field_section_maps_to_a_registered_classic_page_under_theme_settings() {
		$this->as_admin();

		foreach ( $this->field_sections() as $id => $section ) {
			$page = acf_get_options_page( $section['classic'] );

			$this->assertIsArray( $page, "{$id}: classic page {$section['classic']} is not registered." );
			$this->assertSame( 'manage_options', $page['capability'], $id );
		}
	}

	public function test_each_field_section_renders_the_group_that_its_classic_page_is_located_on() {
		$this->as_admin();

		foreach ( $this->field_sections() as $id => $section ) {
			$located = false;

			foreach ( acf_get_field_groups( array( 'options_page' => $section['classic'] ) ) as $group ) {
				if ( in_array( $group['key'], $section['groups'], true ) ) {
					$located = true;
				}
			}

			$this->assertTrue( $located, "{$id}: its group is not the one shown on {$section['classic']}." );
		}
	}

	public function test_a_child_can_add_hide_and_reorder_sections_through_the_filter() {
		$this->as_admin();

		add_filter( 'devbase/app/sections', function ( $sections ) {
			unset( $sections['tracking'] );
			$sections['mine'] = array( 'title' => 'Mine', 'groups' => array( 'group_devbase_security' ), 'classic' => 'security-settings' );

			return $sections;
		} );

		$ids = array_keys( devbase_app_sections() );

		$this->assertContains( 'mine', $ids );
		$this->assertNotContains( 'tracking', $ids );
	}

	public function test_a_section_with_no_registered_group_is_left_out() {
		$this->as_admin();
		$before = count( devbase_app_sections() );

		add_filter( 'devbase/app/sections', function ( $sections ) {
			$sections['ghost'] = array( 'title' => 'Ghost', 'groups' => array( 'group_does_not_exist' ), 'classic' => 'ghost-settings' );

			return $sections;
		} );

		$this->assertNotContains( 'ghost', array_keys( devbase_app_sections() ) );
		$this->assertCount( $before, devbase_app_sections(), 'The built-in sections must be unaffected.' );
	}

	public function test_a_screen_section_whose_callable_does_not_exist_is_left_out() {
		$this->as_admin();

		add_filter( 'devbase/app/sections', function ( $sections ) {
			$sections['broken'] = array( 'title' => 'Broken', 'render' => 'no_such_function_anywhere' );

			return $sections;
		} );

		$this->assertNotContains( 'broken', array_keys( devbase_app_sections() ) );
	}

	public function test_a_filter_returning_junk_falls_back_to_the_built_in_sections() {
		$this->as_admin();
		add_filter( 'devbase/app/sections', '__return_false' );

		$this->assertContains( 'smtp', array_keys( devbase_app_sections() ) );
	}

	public function test_sections_are_grouped_in_group_order_whatever_order_they_were_added_in() {
		$this->as_admin();

		add_filter( 'devbase/app/sections', function ( $sections ) {
			$sections['late_settings'] = array( 'title' => 'Late', 'groups' => array( 'group_devbase_security' ), 'group' => 'settings' );

			return $sections;
		}, 99 );

		$rank   = array_flip( array_keys( devbase_app_groups() ) );
		$groups = array_map( function ( $s ) use ( $rank ) {
			return $rank[ $s['group'] ] ?? 99;
		}, array_values( devbase_app_sections() ) );
		$sorted = $groups;
		sort( $sorted );

		$this->assertSame( $sorted, $groups );
	}

	// --- who sees which section ------------------------------------------------------------------

	public function test_a_section_is_listed_only_for_users_with_its_capability() {
		add_filter( 'devbase/app/sections', function ( $sections ) {
			$sections['for_editors'] = array( 'title' => 'Editors', 'groups' => array( 'group_devbase_security' ), 'capability' => 'edit_pages', 'group' => 'content' );

			return $sections;
		} );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertContains( 'for_editors', array_keys( devbase_app_sections() ) );

		foreach ( devbase_app_sections() as $id => $section ) {
			$this->assertTrue( current_user_can( $section['capability'] ), "{$id} is listed for an Editor who lacks {$section['capability']}." );
			$this->assertNotSame( devbase_settings_capability(), $section['capability'], "{$id}: an admin-only section is listed for an Editor." );
		}

		$this->as_admin();
		$this->assertContains( 'for_editors', array_keys( devbase_app_sections() ) );
		$this->assertContains( 'smtp', array_keys( devbase_app_sections() ) );
	}

	public function test_an_editor_cannot_reach_an_admin_section_by_asking_for_it() {
		add_filter( 'devbase/app/sections', function ( $sections ) {
			$sections['for_editors'] = array( 'title' => 'Editors', 'groups' => array( 'group_devbase_security' ), 'capability' => 'edit_pages', 'group' => 'content' );

			return $sections;
		} );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_GET['section'] = 'smtp';
		$current = devbase_app_current_section();
		$this->assertNotSame( 'smtp', $current, 'Asking for smtp must never land on smtp.' );
		$this->assertSame( 'edit_pages', devbase_app_sections()[ $current ]['capability'] );

		$_GET['page'] = 'devbase-app';
		$this->assertFalse( devbase_settings_page_is( 'smtp-settings' ), 'And the page-gate must not treat it as the SMTP page.' );
	}

	public function test_the_screen_is_refused_when_nothing_is_allowed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), devbase_app_sections() );

		$refused = false;
		ob_start();
		try {
			devbase_app_render();
		} catch ( WPDieException $e ) {
			$refused = true;
		}
		$this->assertStringNotContainsString( 'devbase-app__nav', ob_get_clean() );
		$this->assertTrue( $refused );
	}

	public function test_the_capability_filter_applies_to_the_settings_sections() {
		$this->as_admin();
		add_filter( 'devbase/settings/capability', function () {
			return 'do_not_exist';
		} );

		$this->assertNotContains( 'smtp', array_keys( devbase_app_sections() ) );
	}

	// --- which section is current ---------------------------------------------------------------

	public function test_the_current_section_is_the_requested_one_else_the_first() {
		$this->as_admin();
		$first = array_key_first( devbase_app_sections() );

		$this->assertSame( $first, devbase_app_current_section() );

		$_GET['section'] = 'licence';
		$this->assertSame( 'licence', devbase_app_current_section() );

		$_GET['section'] = 'nope';
		$this->assertSame( $first, devbase_app_current_section(), 'An unknown section must not render nothing.' );

		$_GET['section'] = array( 'x' );
		$this->assertSame( $first, devbase_app_current_section() );
	}

	// --- devbase_settings_page_is() -------------------------------------------------------------

	public function test_page_is_is_true_on_the_classic_page_and_false_elsewhere() {
		$this->assertFalse( devbase_settings_page_is( 'smtp-settings' ), 'No page at all.' );

		$_GET['page'] = 'smtp-settings';
		$this->assertTrue( devbase_settings_page_is( 'smtp-settings' ) );
		$this->assertFalse( devbase_settings_page_is( 'security-settings' ) );

		$_GET['page'] = 'some-other-page';
		$this->assertFalse( devbase_settings_page_is( 'smtp-settings' ) );
	}

	public function test_page_is_follows_the_section_open_in_the_app() {
		$this->as_admin();
		$_GET['page'] = 'devbase-app';

		foreach ( $this->field_sections() as $id => $section ) {
			$_GET['section'] = $id;

			foreach ( $this->field_sections() as $other => $other_section ) {
				$this->assertSame(
					$id === $other,
					devbase_settings_page_is( $other_section['classic'] ),
					"On section {$id}, page_is({$other_section['classic']}) is wrong."
				);
			}
		}
	}

	public function test_page_is_is_false_on_a_screen_section() {
		$this->as_admin();
		$_GET['page']    = 'devbase-app';
		$_GET['section'] = 'submissions';

		foreach ( $this->field_sections() as $section ) {
			$this->assertFalse( devbase_settings_page_is( $section['classic'] ) );
		}
	}

	public function test_the_licence_and_credit_save_hooks_fire_from_the_app_but_not_from_other_screens() {
		$this->as_admin();
		$_GET['page']    = 'devbase-app';
		$_GET['section'] = 'licence';
		$this->assertTrue( devbase_settings_page_is( 'licence-settings' ), 'relay-sync.php would stop pulling on save.' );

		$_GET['section'] = 'miscellaneous';
		$this->assertTrue( devbase_settings_page_is( 'miscellaneous-settings' ), 'credit.php would stop downloading on save.' );
		$this->assertFalse( devbase_settings_page_is( 'licence-settings' ) );
	}

	public function test_the_page_url_helper_points_into_the_app_and_falls_back_to_the_classic_page() {
		$this->as_admin();

		$this->assertSame( devbase_app_url( 'destinations' ), devbase_settings_page_url( 'destinations-settings' ) );
		$this->assertSame( admin_url( 'admin.php?page=unknown-settings' ), devbase_settings_page_url( 'unknown-settings' ) );
	}

	// --- what it draws --------------------------------------------------------------------------

	public function test_every_section_renders_its_nav_and_a_body_for_an_admin() {
		$this->as_admin();

		foreach ( devbase_app_sections() as $id => $section ) {
			$_GET['section'] = $id;

			ob_start();
			devbase_app_render();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'devbase-app__nav', $html, $id );
			$this->assertStringContainsString( 'devbase-app__body', $html, $id );
			$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ), "{$id}: not exactly one active nav item." );
			$this->assertStringNotContainsString( 'classic page', $html, "{$id}: the classic-page link is gone on purpose." );

			if ( empty( $section['render'] ) ) {
				$this->assertStringContainsString( 'id="devbase-app-form"', $html, $id );
				$this->assertStringContainsString( __( 'Save changes', 'devbase_parent_theme' ), $html, $id );
			}
		}
	}

	/**
	 * acf_form() filters posted values through wp_kses_post by default; the classic options pages do not. With it on, saving
	 * Tracking in the app removed the <script> tags from the header/footer codes (found in a real save, 2026-11-15).
	 */
	public function test_the_form_saves_values_unfiltered_like_the_classic_pages() {
		$this->as_admin();
		$_GET['section'] = 'tracking';

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->assertSame( 1, preg_match( '/name="_acf_form" value="([^"]+)"/', $html, $m ), 'No _acf_form field.' );
		$form = json_decode( acf_decrypt( html_entity_decode( $m[1] ) ), true );

		$this->assertIsArray( $form );
		$this->assertArrayHasKey( 'kses', $form );
		$this->assertFalse( $form['kses'], 'kses on: the app would strip <script> from the Tracking codes.' );

		// SCF's front-end honeypot field would be saved with the rest, as an empty `options__validate_email`.
		$this->assertStringNotContainsString( '_validate_email', $html, 'The honeypot field is drawn, so a save would store it.' );
	}

	// --- pages added under Theme Settings later ------------------------------------------------------

	/** Registers an SCF sub-page under Theme Settings with one text field on it; removed again by the caller. */
	private function add_page( $slug, array $extra = array() ) {
		acf_add_options_sub_page( array_merge( array(
			'page_title'  => 'Page ' . $slug,
			'menu_slug'   => $slug,
			'parent_slug' => 'theme-settings',
			'capability'  => 'manage_options',
		), $extra ) );

		acf_add_local_field_group( array(
			'key'      => 'group_' . $slug,
			'title'    => $slug,
			'fields'   => array( array( 'key' => 'field_' . $slug, 'name' => str_replace( '-', '_', $slug ), 'label' => 'X', 'type' => 'text' ) ),
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $slug ) ) ),
		) );
	}

	private function remove_pages( array $slugs ) {
		foreach ( $slugs as $slug ) {
			unset( acf_options_page()->pages[ $slug ] );
			acf_remove_local_field_group( 'group_' . $slug );
		}
	}

	public function test_a_page_added_under_theme_settings_becomes_a_section_on_its_own() {
		$this->as_admin();
		$this->add_page( 't-new-settings', array( 'post_id' => 't_store', 'capability' => 'edit_pages' ) );

		$sections = devbase_app_all_sections();
		$this->remove_pages( array( 't-new-settings' ) );

		$this->assertArrayHasKey( 't-new-settings', $sections );
		$s = $sections['t-new-settings'];
		$this->assertSame( 'Page t-new-settings', $s['title'] );
		$this->assertSame( 'settings', $s['group'], 'No devbase_app: it goes in Settings.' );
		$this->assertSame( 't_store', $s['post_id'], 'Its own store, so the save guard checks against it.' );
		$this->assertSame( devbase_settings_capability(), $s['capability'], 'access.php gives every page under Theme Settings the settings capability, whatever the call said.' );
		$this->assertSame( array( 'group_t-new-settings' ), $s['groups'] );
		$this->assertSame( 't-new-settings', $s['classic'] );
	}

	public function test_a_page_says_where_it_goes_with_devbase_app() {
		$this->as_admin();
		$this->add_page( 't-after-settings', array( 'devbase_app' => array( 'after' => 'tracking', 'icon' => 'dashicons-art', 'description' => 'D' ) ) );
		$this->add_page( 't-group-settings', array( 'devbase_app' => array( 'group' => 'Brand', 'title' => 'Brand kit' ) ) );

		$sections = devbase_app_all_sections();

		$_GET['section'] = 't-group-settings';
		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->remove_pages( array( 't-after-settings', 't-group-settings' ) );
		$ids = array_keys( $sections );

		$this->assertSame( array_search( 'tracking', $ids, true ) + 1, array_search( 't-after-settings', $ids, true ), 'after: right behind Tracking.' );
		$this->assertSame( 'settings', $sections['t-after-settings']['group'] );
		$this->assertSame( 'dashicons-art', $sections['t-after-settings']['icon'] );
		$this->assertSame( 'D', $sections['t-after-settings']['description'] );
		$this->assertSame( 'Brand', $sections['t-group-settings']['group'], 'A group the registry does not know is its own heading.' );
		$this->assertSame( 'Brand kit', $sections['t-group-settings']['title'] );
		$this->assertSame( 't-group-settings', end( $ids ), 'An unknown group goes last.' );
		$this->assertStringContainsString( 'devbase-app__group">Brand<', $html );
		$this->assertStringContainsString( '<h2>Brand kit</h2>', $html, 'The page opens as its own section.' );
	}

	public function test_pages_outside_theme_settings_are_not_picked_up() {
		$this->as_admin();
		acf_add_options_page( array( 'page_title' => 'Elsewhere', 'menu_slug' => 't-elsewhere', 'capability' => 'manage_options' ) );

		$sections = devbase_app_all_sections();
		unset( acf_options_page()->pages['t-elsewhere'] );

		$this->assertArrayNotHasKey( 't-elsewhere', $sections );
		$this->assertArrayNotHasKey( 'theme-settings', $sections, 'The parent page itself is not a section.' );
	}

	public function test_organization_details_has_its_own_seo_group() {
		$groups = devbase_app_groups();

		$this->assertArrayHasKey( 'seo', $groups );
		$this->assertSame( array( 'settings', 'seo', 'forms', 'content' ), array_keys( $groups ) );

		$sections = devbase_app_all_sections();

		if ( isset( $sections['organization'] ) ) {
			$this->assertSame( 'seo', $sections['organization']['group'] );
		}
	}

	public function test_the_nav_is_grouped_with_headings() {
		$this->as_admin();

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'devbase-app__group">' . esc_html__( 'Settings', 'devbase_parent_theme' ) . '<', $html );
		$this->assertStringContainsString( 'devbase-app__group">' . esc_html__( 'Forms', 'devbase_parent_theme' ) . '<', $html );
	}

	public function test_the_nav_links_stay_inside_the_app() {
		$this->as_admin();

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		foreach ( array_keys( devbase_app_sections() ) as $id ) {
			$this->assertStringContainsString( 'page=devbase-app&#038;section=' . $id, $html );
		}
	}

	public function test_the_saved_banner_only_shows_after_a_save() {
		$this->as_admin();
		$_GET['section'] = 'tracking';

		ob_start();
		devbase_app_render();
		$this->assertStringNotContainsString( esc_html__( 'Settings saved.', 'devbase_parent_theme' ), ob_get_clean() );

		$_GET['saved'] = '1';
		ob_start();
		devbase_app_render();
		$this->assertStringContainsString( esc_html__( 'Settings saved.', 'devbase_parent_theme' ), ob_get_clean() );
	}

	public function test_secrets_are_not_printed_into_the_app_page() {
		$this->as_admin();
		update_field( 'smtp_password', 'hunter2-secret', 'option' );
		update_field( 'turnstile_secret_key', 'ts-secret-value', 'option' );

		foreach ( array( 'smtp', 'security' ) as $id ) {
			$_GET['section'] = $id;
			ob_start();
			devbase_app_render();
			$html = ob_get_clean();

			$this->assertStringNotContainsString( 'hunter2-secret', $html, $id );
			$this->assertStringNotContainsString( 'ts-secret-value', $html, $id );
		}

		update_field( 'smtp_password', '', 'option' );
		update_field( 'turnstile_secret_key', '', 'option' );
	}

	// --- a save from the app goes through access.php ---------------------------------------------

	public function test_a_save_from_the_app_cannot_change_locked_keys_and_keeps_secrets() {
		$posted = devbase_settings_filter_submission( array(
			'field_devbase_smtp_host'          => 'smtp.example.com',
			'field_devbase_smtp_password'      => '',
			'field_devbase_warden_licence_key' => 'forged',
			'field_devbase_warden_base_url'    => 'https://evil.example',
		) );

		$this->assertArrayHasKey( 'field_devbase_smtp_host', $posted );
		$this->assertArrayNotHasKey( 'field_devbase_smtp_password', $posted, 'An empty secret must keep the stored one.' );
		$this->assertArrayNotHasKey( 'field_devbase_warden_licence_key', $posted );
		$this->assertArrayNotHasKey( 'field_devbase_warden_base_url', $posted );
	}

	public function test_the_save_hook_filters_an_options_save_whichever_screen_posted_it() {
		$_POST['acf'] = array(
			'field_devbase_warden_base_url' => 'https://evil.example',
			'field_devbase_smtp_host'       => 'smtp.example.com',
		);

		do_action( 'acf/save_post', 'options' );

		$this->assertArrayNotHasKey( 'field_devbase_warden_base_url', $_POST['acf'] );
		$this->assertArrayHasKey( 'field_devbase_smtp_host', $_POST['acf'] );

		unset( $_POST['acf'] );
	}

	// --- Submissions: status, filters, pagination -----------------------------------------------

	public function test_a_submission_status_tells_a_failed_delivery_from_a_routing_gap() {
		$id = $this->submission( 'f', 'A' );

		$this->assertSame( 'logged', devbase_app_submission_status( $id )[0] );

		update_post_meta( $id, '_devbase_dispatch_log', wp_slash( wp_json_encode( array( array( 'success' => true, 'label' => 'x', 'key' => 'k' ) ) ) ) );
		$this->assertSame( 'sent', devbase_app_submission_status( $id )[0] );

		update_post_meta( $id, '_devbase_dispatch_log', wp_slash( wp_json_encode( array( array( 'success' => false, 'label' => 'No destinations configured' ) ) ) ) );
		$this->assertSame( 'unrouted', devbase_app_submission_status( $id )[0], 'No destination set up is not a delivery that failed.' );

		update_post_meta( $id, '_devbase_dispatch_log', wp_slash( wp_json_encode( array( array( 'success' => false, 'label' => 'Mail', 'key' => 'k' ) ) ) ) );
		$this->assertSame( 'failed', devbase_app_submission_status( $id )[0] );

		update_post_meta( $id, '_devbase_dispatch_log', wp_slash( wp_json_encode( array( array( 'success' => false, 'label' => 'No matching destination' ), array( 'success' => false, 'label' => 'Mail', 'key' => 'k' ) ) ) ) );
		$this->assertSame( 'failed', devbase_app_submission_status( $id )[0], 'A real failure outranks a routing gap.' );

		update_post_meta( $id, '_devbase_dispatched', 'retry' );
		$this->assertSame( 'retry', devbase_app_submission_status( $id )[0] );
	}

	public function test_the_submission_list_is_paginated_newest_first() {
		$this->as_admin();
		add_filter( 'devbase/app/per_page', function () {
			return 5;
		} );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->submission( 'pg', 'Person ' . $i, array( 'date' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $i * 3600 ) ) );
		}

		$_GET['section'] = 'submissions';

		ob_start();
		devbase_app_render();
		$page1 = ob_get_clean();

		$this->assertSame( 5, substr_count( $page1, '<td class="devbase-table__summary">' ), 'Page 1 shows 5 rows.' );
		$this->assertStringContainsString( 'Person 0', $page1, 'Newest first.' );
		$this->assertStringNotContainsString( 'Person 6', $page1 );
		$this->assertStringContainsString( '12 ' . esc_html__( 'submissions', 'devbase_parent_theme' ), $page1 );
		$this->assertStringContainsString( 'paged=2', $page1, 'There is a link to page 2.' );

		$_GET['paged'] = '3';
		ob_start();
		devbase_app_render();
		$page3 = ob_get_clean();

		$this->assertSame( 2, substr_count( $page3, '<td class="devbase-table__summary">' ), 'The last page holds the remainder.' );
		$this->assertStringContainsString( 'Person 11', $page3 );
	}

	public function test_a_page_past_the_end_shows_the_last_page_not_an_empty_message() {
		$this->as_admin();
		add_filter( 'devbase/app/per_page', function () {
			return 5;
		} );

		for ( $i = 0; $i < 7; $i++ ) {
			$this->submission( 'pg', 'Person ' . $i );
		}

		$_GET['section'] = 'submissions';
		$_GET['paged']   = '99';

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->assertSame( 2, substr_count( $html, '<td class="devbase-table__summary">' ) );
		$this->assertStringNotContainsString( esc_html__( "No submissions yet. They appear here as visitors send the site's forms.", 'devbase_parent_theme' ), $html );
	}

	public function test_the_pager_keeps_the_filters_in_its_links() {
		$this->as_admin();
		add_filter( 'devbase/app/per_page', function () {
			return 2;
		} );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->submission( 'keep_me', 'Person ' . $i );
		}

		$_GET['section'] = 'submissions';
		$_GET['form']    = 'keep_me';

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'form=keep_me', $html );
		$this->assertStringContainsString( 'paged=2', $html );
	}

	public function test_search_finds_text_including_quotes_and_non_ascii_and_ignores_markup() {
		$this->as_admin();
		$this->submission( 'srch', 'Chloé Dubois' );
		$this->submission( 'srch', 'Ben "Quote" Lee' );
		$this->submission( 'srch', 'Plain Person' );
		$_GET['section'] = 'submissions';

		$rows = function ( $q ) {
			$_GET['q'] = $q;
			ob_start();
			devbase_app_render();

			return substr_count( ob_get_clean(), '<td class="devbase-table__summary">' );
		};

		$this->assertSame( 1, $rows( 'Chloé' ), 'Accented text is stored escaped in the JSON; it must still be found.' );
		$this->assertSame( 1, $rows( '"Quote"' ) );
		$this->assertSame( 1, $rows( 'Plain' ) );
		$this->assertSame( 0, $rows( 'zzz-not-there' ) );
		$this->assertSame( 3, $rows( '<script>alert(1)</script>' ), 'Markup is stripped from the query, not run: it behaves as an empty search.' );
	}

	public function test_the_needs_attention_filter_lists_failed_and_retrying_only() {
		$this->as_admin();
		$ok     = $this->submission( 'att', 'Fine', array( 'meta' => array( '_devbase_dispatch_log' => wp_slash( wp_json_encode( array( array( 'success' => true, 'label' => 'x', 'key' => 'k' ) ) ) ) ) ) );
		$failed = $this->submission( 'att', 'Broken', array( 'meta' => array( '_devbase_dispatch_log' => wp_slash( wp_json_encode( array( array( 'success' => false, 'label' => 'x', 'key' => 'k' ) ) ) ) ) ) );
		$retry  = $this->submission( 'att', 'Waiting', array( 'meta' => array( '_devbase_dispatched' => 'retry' ) ) );

		$_GET['section'] = 'submissions';
		$_GET['status']  = 'attention';

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Broken', $html );
		$this->assertStringContainsString( 'Waiting', $html );
		$this->assertStringNotContainsString( 'Fine', $html );
		unset( $ok, $failed, $retry );
	}

	public function test_a_submission_opens_with_its_fields_and_log_and_an_unknown_one_says_so() {
		$this->as_admin();
		$id = $this->submission( 'one', 'Ada <b>Lovelace</b>' );
		update_post_meta( $id, '_devbase_dispatch_log', wp_slash( wp_json_encode( array( array( 'success' => false, 'label' => 'Office', 'message' => 'Boom', 'key' => 'k', 'attempts' => 2 ) ) ) ) );

		$_GET['section'] = 'submissions';
		$_GET['sub']     = (string) $id;

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Ada &lt;b&gt;Lovelace&lt;/b&gt;', $html, 'Submitted text is escaped.' );
		$this->assertStringNotContainsString( '<b>Lovelace</b>', $html );
		$this->assertStringContainsString( 'Boom', $html );
		$this->assertStringContainsString( sprintf( __( '(tried %d times)', 'devbase_parent_theme' ), 2 ), $html );
		$this->assertStringContainsString( 'back=app', $html, 'Resend returns to the app.' );

		foreach ( array( 999999, self::factory()->post->create() ) as $bad ) {
			$_GET['sub'] = (string) $bad;
			ob_start();
			devbase_app_render();
			$this->assertStringContainsString( esc_html__( 'That submission does not exist.', 'devbase_parent_theme' ), ob_get_clean(), 'An id that is not a submission must not be shown.' );
		}
	}

	public function test_resend_from_the_app_comes_back_to_the_app() {
		$this->as_admin();
		$id = $this->submission( 'one', 'Ada' );

		$this->assertStringContainsString( 'back=app', add_query_arg( 'back', 'app', devbase_forms_resend_url( $id ) ) );
	}

	// --- Registered Forms --------------------------------------------------------------------------

	public function test_the_forms_screen_is_paginated_and_shows_counts_and_who_is_notified() {
		$this->as_admin();
		add_filter( 'devbase/app/per_page', function () {
			return 3;
		} );

		$names = array();

		for ( $i = 1; $i <= 7; $i++ ) {
			$name    = 'pgform_' . $i;
			$names[] = $name;
			self::factory()->post->create( array( 'post_type' => 'registered_form', 'post_status' => 'publish', 'post_title' => 'PG Form ' . $i, 'meta_input' => array( 'form_name' => $name ) ) );
		}

		$this->submission( 'pgform_1', 'A' );
		$this->submission( 'pgform_1', 'B' );

		$_GET['section'] = 'forms';

		ob_start();
		devbase_app_render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'paged=2', $html, 'More forms than a page: there is a link to the next.' );
		$this->assertMatchesRegularExpression( '/\d+ ' . preg_quote( esc_html__( 'forms', 'devbase_parent_theme' ), '/' ) . '/u', $html );

		$_GET['paged'] = '99';
		ob_start();
		devbase_app_render();
		$this->assertStringNotContainsString( esc_html__( 'No forms are registered yet. A developer adds one by listing it in registered-forms.json.', 'devbase_parent_theme' ), ob_get_clean(), 'A page past the end shows the last page.' );
	}

	public function test_form_stats_count_published_submissions_per_form() {
		$this->submission( 'stat_a', 'x' );
		$this->submission( 'stat_a', 'y' );
		$this->submission( 'stat_b', 'z' );

		$stats = devbase_app_form_stats();

		$this->assertSame( 2, $stats['stat_a']['count'] );
		$this->assertSame( 1, $stats['stat_b']['count'] );
	}

	// --- the source guard -----------------------------------------------------------------------

	public function test_no_page_gated_code_reads_the_classic_slug_from_get_page_directly() {
		$this->as_admin();

		$root    = dirname( __DIR__ ) . '/inc';
		$slugs   = array();
		$offence = array();

		foreach ( $this->field_sections() as $section ) {
			$slugs[] = $section['classic'];
		}

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/dependencies/' ) ) {
				continue;
			}

			foreach ( file( $file->getPathname() ) as $n => $line ) {
				foreach ( $slugs as $slug ) {
					if ( false !== strpos( $line, "'{$slug}'" ) && false !== strpos( $line, "\$_GET['page']" ) ) {
						$offence[] = substr( $file->getPathname(), strlen( $root ) ) . ':' . ( $n + 1 );
					}
				}
			}
		}

		$this->assertSame( array(), $offence, 'Use devbase_settings_page_is() so the code also runs inside the app.' );
	}
}
