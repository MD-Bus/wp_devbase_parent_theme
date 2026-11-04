<?php

// Who counts as "the developer" on a site: the logins that see the settings a
// client has no use for (the Credit tab, the Licence page's Remote setup tab).
//
// This theme ships to anyone, so it holds NO logins itself. A child theme (or
// wp-config.php) names them once:
//
//   add_filter( 'devbase/developer_logins', function () { return array( 'jane', 'john' ); } );
//   define( 'DEVBASE_DEVELOPER_LOGINS', array( 'jane', 'john' ) );   // wp-config.php
//
// While the list is empty nothing is hidden, so a fresh install works. Once it
// has logins, those developer-only settings are hidden from everyone else.

/**
 * The developer logins: the constant, then the filter.
 *
 * @return string[]
 */
function devbase_developer_logins() {
	$logins = defined( 'DEVBASE_DEVELOPER_LOGINS' ) ? (array) DEVBASE_DEVELOPER_LOGINS : array();
	$logins = (array) apply_filters( 'devbase/developer_logins', $logins );

	return array_values( array_filter( array_map( 'strval', $logins ), 'strlen' ) );
}

/**
 * Whether developer-only settings should be hidden from the current user: a list
 * of developers exists and this user is not on it.
 */
function devbase_hide_from_non_developers() {
	$logins = devbase_developer_logins();

	if ( ! $logins ) {
		return false;
	}

	$user = wp_get_current_user();

	return ! ( $user && in_array( $user->user_login, $logins, true ) );
}
