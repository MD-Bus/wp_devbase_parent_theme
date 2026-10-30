<?php
// Sites that ran 1.11.0 or 1.13.0 still hold the removed MCP sign-in's data: the
// registered clients, each user's connected apps (with their refresh tokens)
// and the on/off setting. Nothing reads them any more. Delete them, once.
//
// Safe if the sign-in ever comes back: this does nothing while that code is
// loaded (its constant exists), and it never runs again after the first clean
// run, so it cannot remove data a later sign-in has created.
// Short-lived access and code transients expire on their own within the hour.

function devbase_legacy_mcp_oauth_cleanup() {
	if ( get_option( 'devbase_legacy_mcp_oauth_cleaned' ) ) {
		return false;
	}

	// A later release may ship sign-in again. Its data is live then, not legacy.
	if ( apply_filters( 'devbase/legacy_mcp_oauth_cleanup/code_loaded', defined( 'DEVBASE_MCP_OAUTH_CLIENTS' ) ) ) {
		return false;
	}

	delete_option( 'devbase_mcp_oauth_clients' );
	delete_metadata( 'user', 0, 'devbase_mcp_oauth_grants', '', true );
	delete_option( 'options_mcp_oauth_enabled' );
	delete_option( '_options_mcp_oauth_enabled' );

	update_option( 'devbase_legacy_mcp_oauth_cleaned', 1 );

	return true;
}
add_action( 'init', 'devbase_legacy_mcp_oauth_cleanup' );
