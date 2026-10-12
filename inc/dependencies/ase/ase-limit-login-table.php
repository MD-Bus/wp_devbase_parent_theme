<?php

// ASE's Limit Login Attempts module reads/writes a custom DB table
// (wp_asenha_failed_logins) that it normally creates via
// register_activation_hook() on real plugin activation. Since ASE is loaded
// directly from vendor/ instead of going through WP's plugin system (see
// inc/dependencies/ase.php), that hook never fires and the table never gets created —
// without it, the module's blocking logic silently doesn't work at all.
//
// This creates it ourselves, once, the same way ASE's own Activation class
// would. Guarded carefully: ASE's create_failed_logins_log_table() does
// DROP TABLE IF EXISTS before recreating it, so calling it more than once
// would wipe any accumulated log data — only call it if the table doesn't
// already exist, and remember that we've checked so we don't re-query on
// every admin page load.
add_action( 'admin_init', function () {
	if ( ! class_exists( 'ASENHA\Classes\Activation' ) ) {
		return;
	}

	$options = get_option( 'admin_site_enhancements', array() );

	if ( empty( $options['limit_login_attempts'] ) ) {
		return;
	}

	if ( get_option( 'devbase_asenha_failed_logins_table_ready' ) ) {
		return;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'asenha_failed_logins';
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

	if ( ! $table_exists ) {
		( new ASENHA\Classes\Activation() )->create_failed_logins_log_table();
	}

	update_option( 'devbase_asenha_failed_logins_table_ready', true );
} );
