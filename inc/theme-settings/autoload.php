<?php

// Keeps the Theme Settings values that every front-end page reads autoloaded, so they come with WordPress's one
// "all options" query instead of one query each.
//
// WHY. SCF saves every options-page value with autoload OFF (its global `autoload` setting; only its own classic page
// save switches it, for that page). A page then paid one query per value: measured 2026-11-15 on dev-blueprint, about
// 26 per page for Organization Details (each repeater row is a value of its own) and Tracking alone.
//
// HOW. Not by changing how anything saves: the settings app, the classic pages, MCP's update_field(), WP-CLI and tests
// all write through update_option() with SCF's flag. After any write of one of these options, WordPress's own
// added_option / updated_option actions fire, and the option's autoload is switched on there. Existing rows are
// switched once, and again whenever the set of names changes (Organization Details only exists while The SEO
// Framework is on, so switching TSF on adds its fields). Values that leave the set stay autoloaded: a few short
// strings, harmless.
//
// WHAT. Only values read on public pages: every field of Organization Details (schema) and Tracking, plus two from
// Miscellaneous. Not secrets (they are not read on public pages, and autoloaded options are in every request's memory),
// and not long admin-only text such as the MCP site guide. Filter `devbase/settings/autoload_fields` to add a child
// theme's own front-end page.

/** Option that records which set of names was last switched on. Autoloaded itself, so checking it costs nothing. */
const DEVBASE_AUTOLOAD_STAMP = 'devbase_settings_autoload_stamp';

/**
 * The top-level field names whose values are autoloaded.
 *
 * @return string[]
 */
function devbase_settings_autoload_fields() {
	$names = array( 'enable_tsf', 'enable_filebird', 'credit_brand_url' );

	if ( function_exists( 'acf_get_fields' ) && function_exists( 'acf_get_field_group' ) ) {
		foreach ( array( 'group_devbase_schema', 'group_devbase_tracking' ) as $group ) {
			if ( ! acf_get_field_group( $group ) ) {
				continue;
			}

			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( ! empty( $field['name'] ) ) {
					$names[] = (string) $field['name'];
				}
			}
		}
	}

	$filtered = apply_filters( 'devbase/settings/autoload_fields', $names );
	$names    = is_array( $filtered ) ? $filtered : $names;

	$names = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
	sort( $names );

	return $names;
}

/**
 * Whether an option row belongs to one of those fields: `options_{name}` (the value), `_options_{name}` (SCF's
 * reference to the field key, read with it), and the same with `_{row}_{sub}` after it for a repeater's rows.
 *
 * @param string   $option An option name.
 * @param string[] $names  devbase_settings_autoload_fields().
 */
function devbase_settings_autoload_wanted( $option, array $names ) {
	$option = (string) $option;
	$option = 0 === strpos( $option, '_' ) ? substr( $option, 1 ) : $option;

	if ( 0 !== strpos( $option, 'options_' ) ) {
		return false;
	}

	$rest = substr( $option, strlen( 'options_' ) );

	foreach ( $names as $name ) {
		if ( $rest === $name || 0 === strpos( $rest, $name . '_' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Switches autoload on for an option just written, when it is one of ours.
 *
 * @param string $option The option name.
 */
function devbase_settings_autoload_after_write( $option ) {
	static $names = null;

	if ( 0 !== strpos( (string) $option, 'options_' ) && 0 !== strpos( (string) $option, '_options_' ) ) {
		return;
	}

	// Worked out once per request, after SCF has registered the groups (a write before that is switched by the
	// one-time pass instead).
	if ( null === $names ) {
		if ( ! did_action( 'acf/init' ) ) {
			return;
		}

		$names = devbase_settings_autoload_fields();
	}

	if ( devbase_settings_autoload_wanted( $option, $names ) ) {
		wp_set_option_autoload( (string) $option, true );
	}
}
add_action( 'added_option', 'devbase_settings_autoload_after_write' );
add_action( 'updated_option', 'devbase_settings_autoload_after_write' );

/**
 * The one-time pass: switches every existing row of these fields to autoload. Runs when the set of names differs from
 * the last pass (first run, TSF switched on, a field added in a release), so normally it only reads its stamp.
 *
 * @return int How many rows were looked at (0 when nothing had to be done).
 */
function devbase_settings_autoload_sync() {
	global $wpdb;

	$names = devbase_settings_autoload_fields();
	$stamp = md5( implode( ',', $names ) );

	if ( get_option( DEVBASE_AUTOLOAD_STAMP ) === $stamp ) {
		return 0;
	}

	$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'options\\_%' OR option_name LIKE '\\_options\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = array_values( array_filter( (array) $rows, function ( $option ) use ( $names ) {
		return devbase_settings_autoload_wanted( $option, $names );
	} ) );

	if ( $rows ) {
		wp_set_options_autoload( $rows, true );
	}

	update_option( DEVBASE_AUTOLOAD_STAMP, $stamp, true );

	return count( $rows );
}

// After SCF's acf/init (init priority 5) has registered the groups.
add_action( 'init', function () {
	devbase_settings_autoload_sync();
}, 99 );
