<?php

/**
 * The read-only status messages of the settings pages are built when the page is shown, not on every request.
 * Their field groups register on `acf/init`, which runs for every visitor too; a message built inside the group
 * array cost the front end about 16 queries a page (measured 2026-11-10). See devbase_message_when_shown().
 */
class Test_Settings_Messages extends WP_UnitTestCase {

	const DEFERRED = array(
		'field_devbase_licence_status',
		'field_devbase_heartbeat_status',
		'field_devbase_events_status',
		'field_devbase_retention_last_run',
		'field_devbase_status_smtp',
		'field_devbase_status_mail',
		'field_devbase_status_dispatch',
		'field_devbase_status_honeypot',
		'field_devbase_status_retention',
		'field_devbase_status_dependencies',
		'field_devbase_mcp_activity',
	);

	public function test_a_status_message_is_empty_as_registered_and_filled_when_the_field_is_shown() {
		foreach ( self::DEFERRED as $key ) {
			$field = acf_get_field( $key );

			$this->assertIsArray( $field, $key );
			$this->assertSame( 'message', $field['type'], $key );
			$this->assertSame( '', $field['message'], "$key is built while the group registers, so on every request." );

			$shown = acf_prepare_field( $field );

			if ( false === $shown ) {
				continue; // Hidden on this site (Remote setup), which is a decision of its own.
			}

			$this->assertNotSame( '', trim( wp_strip_all_tags( $shown['message'] ) ), "$key has no text when shown." );
		}
	}

	public function test_registering_the_groups_reads_no_status_option() {
		$read  = array();
		$watch = function ( $value, $option ) use ( &$read ) {
			$read[] = $option;
			return $value;
		};

		foreach ( array( 'devbase_mail_log', 'devbase_form_honeypot_drops', 'devbase_mcp_audit_log', 'devbase_retention_last_run' ) as $option ) {
			add_filter( "pre_option_{$option}", $watch, 10, 2 );
		}

		// Firing acf/init again also re-runs SCF's own block bindings registration, which WordPress notes.
		$this->setExpectedIncorrectUsage( 'WP_Block_Bindings_Registry::register' );

		acf_get_local_store( 'groups' )->reset();
		acf_get_local_store( 'fields' )->reset();
		do_action( 'acf/init' );

		$this->assertSame( array(), $read );
	}
}
