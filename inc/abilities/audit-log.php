<?php

// A record of every change made through an ability (what MCP clients call): which ability, by which
// user, through which application password, on what, and for an options page the values the fields had
// before. So "who changed the phone number on the Contact page last Tuesday" has an answer, and a
// wrong change can be put back by hand.
//
// Hooked on the Abilities API itself (wp_before_execute_ability / wp_after_execute_ability, WordPress
// 6.9+), so it covers every ability, ours and any plugin's, whatever called it. Read-only abilities
// (annotated `readonly`, or named get-* / list-*) are not recorded. wp_after_execute_ability fires only
// when the ability succeeded, so a refused or failed call leaves no entry: nothing was changed.
//
// Stored in one option, newest first, capped at DEVBASE_ABILITIES_AUDIT_KEEP entries and 90 days.
// Shown on Miscellaneous > MCP Access. Kept on the site only: it is not in the health report.

const DEVBASE_ABILITIES_AUDIT_OPTION = 'devbase_mcp_audit_log';
const DEVBASE_ABILITIES_AUDIT_KEEP   = 200;

/** Whether an ability changes something (and so is recorded). */
function devbase_abilities_audit_records( $ability_name, $ability = null ) {
	$annotations = array();

	if ( is_object( $ability ) && method_exists( $ability, 'get_meta' ) ) {
		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
	}

	$records = empty( $annotations['readonly'] ) && ! preg_match( '~/(get|list|search|find|read)-~', (string) $ability_name );

	return (bool) apply_filters( 'devbase/abilities/audit_records', $records, $ability_name, $ability );
}

/**
 * What a call is about, from its input (and its result, for a new post or file).
 *
 * @return string e.g. "page 12", "options contact-settings", "media 40".
 */
function devbase_abilities_audit_target( $ability_name, $input, $result = null ) {
	$input = is_array( $input ) ? $input : array();

	if ( ! empty( $input['menu_slug'] ) ) {
		return 'options ' . sanitize_key( $input['menu_slug'] );
	}

	if ( ! empty( $input['id'] ) ) {
		return trim( sanitize_key( $input['post_type'] ?? 'post' ) . ' ' . (int) $input['id'] );
	}

	if ( is_array( $result ) && ! empty( $result['id'] ) ) {
		$kind = 'devbase/upload-media' === $ability_name ? 'media' : sanitize_key( $input['post_type'] ?? 'post' );

		return $kind . ' ' . (int) $result['id'];
	}

	return '';
}

/** One value as stored text, cut to a size a log can hold. */
function devbase_abilities_audit_value( $value ) {
	$text = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );

	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 1000 ) : substr( $text, 0, 1000 );
}

/**
 * Holds what devbase_abilities_audit_before() read until the call has finished.
 *
 * @param string     $ability_name The ability.
 * @param array|null $before       What to hold; null takes (and forgets) what is held.
 * @return array
 */
function devbase_abilities_audit_held( $ability_name, $before = null ) {
	static $held = array();

	if ( null === $before ) {
		$taken = $held[ $ability_name ] ?? array();
		unset( $held[ $ability_name ] );

		return $taken;
	}

	$held[ $ability_name ] = $before;

	return $before;
}

/**
 * The values about to be replaced, read before the ability runs: an options page's fields, and a
 * post's title and status (the post's own revisions keep its content).
 */
function devbase_abilities_audit_before( $ability_name, $input = null, $ability = null ) {
	if ( ! devbase_abilities_audit_records( $ability_name, $ability ) || ! is_array( $input ) ) {
		return array();
	}

	$before = array();

	if ( ! empty( $input['menu_slug'] ) && ! empty( $input['fields'] ) && is_array( $input['fields'] ) && function_exists( 'devbase_abilities_options_page_fields' ) ) {
		$post_id = devbase_abilities_options_page_post_id( $input['menu_slug'] );

		foreach ( devbase_abilities_options_page_fields( $input['menu_slug'] ) as $field ) {
			if ( array_key_exists( $field['name'], $input['fields'] ) ) {
				$before[ $field['name'] ] = devbase_abilities_audit_value( get_field( $field['key'], $post_id, false ) );
			}
		}
	} elseif ( ! empty( $input['id'] ) && get_post( (int) $input['id'] ) ) {
		$post   = get_post( (int) $input['id'] );
		$before = array( 'title' => devbase_abilities_audit_value( $post->post_title ), 'status' => $post->post_status );
	}

	return devbase_abilities_audit_held( $ability_name, $before );
}
add_action( 'wp_before_execute_ability', 'devbase_abilities_audit_before', 10, 3 );

/** The name of the application password this request signed in with, or '' (a browser session, WP-CLI). */
function devbase_abilities_audit_app_password() {
	if ( ! function_exists( 'rest_get_authenticated_app_password' ) || ! class_exists( 'WP_Application_Passwords' ) ) {
		return '';
	}

	$uuid = rest_get_authenticated_app_password();

	if ( ! $uuid ) {
		return '';
	}

	$item = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );

	return is_array( $item ) && ! empty( $item['name'] ) ? (string) $item['name'] : (string) $uuid;
}

/** Records one successful change. */
function devbase_abilities_audit_after( $ability_name, $input, $result = null, $ability = null ) {
	$before = devbase_abilities_audit_held( $ability_name );

	if ( ! devbase_abilities_audit_records( $ability_name, $ability ) ) {
		return;
	}

	$input  = is_array( $input ) ? $input : array();
	$fields = isset( $input['fields'] ) && is_array( $input['fields'] )
		? array_keys( $input['fields'] )
		: array_values( array_diff( array_keys( $input ), array( 'post_type', 'id', 'menu_slug', 'data' ) ) );
	$user   = wp_get_current_user();

	devbase_abilities_audit_add( array(
		'time'     => time(),
		'ability'  => (string) $ability_name,
		'user'     => $user && $user->exists() ? (string) $user->user_login : '',
		'password' => devbase_abilities_audit_app_password(),
		'target'   => devbase_abilities_audit_target( $ability_name, $input, $result ),
		'fields'   => array_slice( array_map( 'strval', $fields ), 0, 50 ),
		'before'   => $before,
	) );
}
add_action( 'wp_after_execute_ability', 'devbase_abilities_audit_after', 10, 4 );

/** Adds an entry, newest first, dropping what is past the cap or older than 90 days. */
function devbase_abilities_audit_add( array $entry ) {
	$cutoff = time() - 90 * DAY_IN_SECONDS;
	$log    = array_filter( devbase_abilities_audit_log(), function ( $e ) use ( $cutoff ) {
		return (int) ( $e['time'] ?? 0 ) >= $cutoff;
	} );

	array_unshift( $log, $entry );

	update_option( DEVBASE_ABILITIES_AUDIT_OPTION, array_slice( array_values( $log ), 0, DEVBASE_ABILITIES_AUDIT_KEEP ), false );
}

/** @return array<int, array> The log, newest first. */
function devbase_abilities_audit_log() {
	$log = get_option( DEVBASE_ABILITIES_AUDIT_OPTION );

	return is_array( $log ) ? array_values( $log ) : array();
}

/**
 * The last changes as an HTML table, for MCP Access. Every cell is escaped here.
 *
 * @param int $limit Rows.
 */
function devbase_abilities_audit_html( $limit = 20 ) {
	$log = array_slice( devbase_abilities_audit_log(), 0, $limit );

	if ( ! $log ) {
		return '<p>' . esc_html__( 'Nothing has been changed through MCP yet.', 'devbase_parent_theme' ) . '</p>';
	}

	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$rows   = '';

	foreach ( $log as $e ) {
		$before = '';

		foreach ( (array) ( $e['before'] ?? array() ) as $name => $value ) {
			$before .= '<code>' . esc_html( (string) $name ) . '</code>: ' . esc_html( function_exists( 'mb_strimwidth' ) ? mb_strimwidth( (string) $value, 0, 120, '…' ) : substr( (string) $value, 0, 120 ) ) . '<br>';
		}

		$rows .= '<tr>'
			. '<td>' . esc_html( wp_date( $format, (int) $e['time'] ) ) . '</td>'
			. '<td><code>' . esc_html( (string) $e['ability'] ) . '</code><br>' . esc_html( (string) ( $e['target'] ?? '' ) ) . '</td>'
			. '<td>' . esc_html( (string) ( $e['user'] ?? '' ) ) . ( ! empty( $e['password'] ) ? '<br><small>' . esc_html( (string) $e['password'] ) . '</small>' : '' ) . '</td>'
			. '<td>' . esc_html( implode( ', ', (array) ( $e['fields'] ?? array() ) ) ) . '</td>'
			. '<td>' . $before . '</td>'
			. '</tr>';
	}

	return '<table class="widefat striped"><thead><tr>'
		. '<th>' . esc_html__( 'When', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'What', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Who (application password)', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Fields', 'devbase_parent_theme' ) . '</th>'
		. '<th>' . esc_html__( 'Before', 'devbase_parent_theme' ) . '</th>'
		. '</tr></thead><tbody>' . $rows . '</tbody></table>';
}
