<?php

/**
 * Plugins and Themes Rollback module bootstrap.
 *
 * WordPress.org rollback ships in the free plugin. Local archive rollback for
 * plugins and themes that are not hosted on WordPress.org is loaded only when
 * premium code is available.
 *
 * @since 8.7.3
 */
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
define( 'ASENHA_ROLLBACK_VERSION', ASENHA_VERSION );
define( 'ASENHA_ROLLBACK_PATH', trailingslashit( dirname( __FILE__ ) ) );
define( 'ASENHA_ROLLBACK_URL', ASENHA_URL . 'includes/rollback/' );
require_once ASENHA_ROLLBACK_PATH . 'classes/class-rollback-helper.php';
require_once ASENHA_ROLLBACK_PATH . 'classes/class-rollback-versions.php';
require_once ASENHA_ROLLBACK_PATH . 'classes/class-rollback-logger.php';
require_once ASENHA_ROLLBACK_PATH . 'classes/class-rollback-admin.php';
require_once ASENHA_ROLLBACK_PATH . 'classes/class-rollback-admin-page.php';
/**
 * Initialize the rollback module.
 *
 * @return void
 */
function asenha_rollback_init() {
    $helper = new ASENHA_Rollback_Helper();
    $archives = null;
    $update_count = null;
    $versions = new ASENHA_Rollback_Versions($helper, $archives);
    $logger = new ASENHA_Rollback_Logger($helper);
    $admin = new ASENHA_Rollback_Admin(
        $helper,
        $archives,
        $versions,
        $logger,
        $update_count
    );
    $logger->init();
    $admin->init();
    // Move legacy rollback data out of the autoloaded extra option on the first admin request.
    add_action( 'admin_init', array($helper, 'get_module_data') );
    if ( is_admin() ) {
        $admin_page = new ASENHA_Rollback_Admin_Page(
            $helper,
            $versions,
            $logger,
            $update_count
        );
        $admin_page->init();
    }
}

asenha_rollback_init();