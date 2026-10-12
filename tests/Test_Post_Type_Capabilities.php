<?php
// Covers inc/forms/post-types.php's capability mapping (registered_form
// and form_data restricted to manage_options).
//
// The regression this file exists to catch: mapping a CPT's SINGULAR
// meta caps (edit_post/read_post/delete_post) directly to an existing,
// real WordPress capability string (like 'manage_options') makes
// WordPress register a global reverse-lookup —
// $post_type_meta_caps['manage_options'] — that hijacks EVERY
// 'manage_options' check on the entire site, not just this CPT. This was
// hit for real while building this feature: after including those three
// keys, map_meta_cap('manage_options', $user_id) returned ['do_not_allow']
// site-wide, which would have broken Theme Settings and anything else
// gated by manage_options. The fix was to only map the PLURAL/primitive
// caps (edit_posts, edit_others_posts, etc.) and leave the three singular
// ones out entirely — WordPress's own built-in handling for
// edit_post/read_post/delete_post already falls back to
// edit_others_posts/read_private_posts/delete_others_posts, which ARE
// safely mapped, achieving the same restriction without the collision.

class Test_Post_Type_Capabilities extends WP_UnitTestCase {

	public function test_manage_options_is_not_hijacked_by_post_type_registration() {
		global $post_type_meta_caps;
		$this->assertArrayNotHasKey(
			'manage_options',
			(array) $post_type_meta_caps,
			'Registering registered_form/form_data must never create a global reverse-lookup entry for the literal "manage_options" capability string — that would hijack every manage_options check on the whole site, not just these two CPTs.'
		);

		// The actual symptom this collision produced, confirmed live while
		// building this feature: map_meta_cap() no longer resolving
		// 'manage_options' correctly for ANYONE, admin included.
		$this->assertSame( array( 'manage_options' ), map_meta_cap( 'manage_options', 1 ) );
	}

	public function test_administrator_can_access_both_cpts() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		foreach ( array( 'registered_form', 'form_data' ) as $post_type ) {
			$obj = get_post_type_object( $post_type );
			$this->assertTrue( current_user_can( $obj->cap->edit_posts ), "Administrator should be able to list {$post_type}." );
		}

		$post_id = self::factory()->post->create( array( 'post_type' => 'form_data' ) );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ), 'Administrator should be able to edit a specific form_data post.' );
	}

	public function test_editor_cannot_access_either_cpt() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		foreach ( array( 'registered_form', 'form_data' ) as $post_type ) {
			$obj = get_post_type_object( $post_type );
			$this->assertFalse( current_user_can( $obj->cap->edit_posts ), "Editor should NOT be able to list {$post_type} — these can hold submitted PII." );
		}

		$post_id = self::factory()->post->create( array( 'post_type' => 'form_data' ) );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ), 'Editor should NOT be able to edit a specific form_data post, even though edit_posts alone would normally allow it under the default "post" capability_type.' );
	}

	// registered_form posts mirror registry/registered-forms.json — see
	// registry/sync.php's devbase_forms_prune_deregistered(), the only
	// supported way to remove one. Nobody, administrators included, may
	// delete one from the admin UI; form_data (plain submission logs) is
	// unaffected and stays deletable by manage_options holders, same as
	// before.
	public function test_administrator_cannot_delete_registered_form() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = self::factory()->post->create( array( 'post_type' => 'registered_form' ) );

		$obj = get_post_type_object( 'registered_form' );
		$this->assertFalse( current_user_can( $obj->cap->delete_posts ), 'Even an administrator should not have delete_posts on registered_form.' );
		$this->assertFalse( current_user_can( 'delete_post', $post_id ), 'Even an administrator should not be able to delete a specific registered_form post from the admin UI.' );
	}

	public function test_administrator_can_still_delete_form_data() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = self::factory()->post->create( array( 'post_type' => 'form_data' ) );

		$this->assertTrue( current_user_can( 'delete_post', $post_id ), 'form_data deletion is unrestricted for manage_options holders — only registered_form gained the delete lockout.' );
	}

	// form_data posts are submission logs — the only supported way one gets
	// created is devbase_form_log_submission() from a real front-end
	// submission (inc/helpers/forms/). WordPress itself checks
	// current_user_can( $obj->cap->create_posts ) to decide whether to show
	// "Add New" and to gate post-new.php, so locking this out is sufficient
	// to hide/block manual creation without any separate UI-hiding code.
	public function test_administrator_cannot_manually_create_form_data() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$obj = get_post_type_object( 'form_data' );
		$this->assertFalse( current_user_can( $obj->cap->create_posts ), 'Even an administrator should not be able to manually add a form_data post — these are submission logs, not hand-authored content.' );
	}

	// A manually-created registered_form post would save with an empty
	// form_name (the CPT only supports 'title', no field to set form_name
	// by hand) and devbase_forms_prune_deregistered() explicitly skips
	// empty form_names — combined with the delete lockout, that would be a
	// permanent, unremovable orphan. So unlike form_data (where only
	// create_posts is locked out) or registered_form's own original delete
	// lockout, this CPT now blocks both create_posts and every delete cap.
	public function test_administrator_cannot_manually_create_registered_form() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$obj = get_post_type_object( 'registered_form' );
		$this->assertFalse( current_user_can( $obj->cap->create_posts ), 'Even an administrator should not be able to manually add a registered_form post — the only supported source is registry/sync.php mirroring registered-forms.json.' );
	}
}
