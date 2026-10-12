<?php
// Covers inc/forms/registry/sync.php's devbase_forms_prune_deregistered()
// and devbase_forms_strip_apply_to() — the pruning half of the registry
// sync, added alongside registered_form's delete-capability lockout (see
// Test_Post_Type_Capabilities.php) so removing a form from the JSON
// registry is once again the only way to get rid of its registered_form
// post. Tests call these two functions directly with an explicit
// $names_in_json list rather than driving the full devbase_forms_sync_registry()
// through the real registered-forms.json file, since that file is
// production data, not a test fixture.

class Test_Registry_Prune extends WP_UnitTestCase {

	private function make_registered_form( $form_name ) {
		$post_id = self::factory()->post->create( array( 'post_type' => 'registered_form' ) );
		update_post_meta( $post_id, 'form_name', $form_name );
		return $post_id;
	}

	public function test_prune_deletes_post_whose_form_is_no_longer_in_json() {
		$kept_id    = $this->make_registered_form( 'form_a' );
		$removed_id = $this->make_registered_form( 'form_b' );

		devbase_forms_prune_deregistered( array( 'form_a' ) );

		$this->assertInstanceOf( 'WP_Post', get_post( $kept_id ), 'A form still present in the JSON must survive the prune.' );
		$this->assertNull( get_post( $removed_id ), 'A form no longer present in the JSON must be deleted outright, not trashed.' );
	}

	public function test_prune_is_a_no_op_when_every_form_still_registered() {
		$post_id = $this->make_registered_form( 'form_a' );

		devbase_forms_prune_deregistered( array( 'form_a', 'form_never_created' ) );

		$this->assertInstanceOf( 'WP_Post', get_post( $post_id ) );
	}

	public function test_prune_strips_deleted_form_from_destinations_apply_to() {
		$this->make_registered_form( 'form_a' );
		$this->make_registered_form( 'form_b' );

		update_field( 'destinations', array(
			array(
				'label'    => 'Row 1',
				'type'     => 'smtp',
				'apply_to' => array( 'form_a', 'form_b' ),
			),
			array(
				'label'    => 'Row 2',
				'type'     => 'webhook',
				'apply_to' => array( 'form_b' ),
			),
		), 'option' );

		devbase_forms_prune_deregistered( array( 'form_a' ) );

		$rows = get_field( 'destinations', 'option' );

		$this->assertSame( array( 'form_a' ), array_values( $rows[0]['apply_to'] ), 'Row 1 should keep form_a and lose the deregistered form_b.' );
		$this->assertSame( array(), array_values( $rows[1]['apply_to'] ), 'Row 2 only applied to the deregistered form, so it should end up empty.' );
	}

	public function test_prune_strips_deleted_form_from_retention_policies_apply_to() {
		$this->make_registered_form( 'form_a' );
		$this->make_registered_form( 'form_b' );

		update_field( 'retention_policies', array(
			array(
				'period'   => '1_week',
				'apply_to' => array( 'form_a', 'form_b' ),
			),
		), 'option' );

		devbase_forms_prune_deregistered( array( 'form_a' ) );

		$rows = get_field( 'retention_policies', 'option' );

		$this->assertSame( array( 'form_a' ), array_values( $rows[0]['apply_to'] ) );
	}
}
