<?php

/**
 * ASE Module: User Role Manager
 *
 * Create, edit, clone and delete roles and manage their capabilities from
 * Users > User Role Manager. The free version includes Essentials and By area.
 * Pro adds the All capabilities view, adding custom capabilities, migration
 * from other role plugins, per-user capability overrides, role comparison,
 * undo history, role export / import and an audit log.
 *
 * @package ASENHA\UserRoleManager
 * @since   9.2.0
 */
namespace ASENHA\UserRoleManager;

// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
if ( !defined( 'ASENHA_USER_ROLE_MANAGER_PATH' ) ) {
    define( 'ASENHA_USER_ROLE_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
}
if ( !defined( 'ASENHA_USER_ROLE_MANAGER_URL' ) ) {
    define( 'ASENHA_USER_ROLE_MANAGER_URL', plugin_dir_url( __FILE__ ) );
}
require_once ASENHA_USER_ROLE_MANAGER_PATH . 'class-role-manager-storage.php';
require_once ASENHA_USER_ROLE_MANAGER_PATH . 'class-role-manager-guard.php';
require_once ASENHA_USER_ROLE_MANAGER_PATH . 'class-role-manager-capability-catalog.php';
require_once ASENHA_USER_ROLE_MANAGER_PATH . 'class-role-manager-role-service.php';
require_once ASENHA_USER_ROLE_MANAGER_PATH . 'class-role-manager-rest-api.php';
require_once ASENHA_USER_ROLE_MANAGER_PATH . 'class-role-manager-admin.php';
/**
 * Initialize the module.
 *
 * @return void
 */
function init_asenha_user_role_manager() {
    ( new Role_Manager_REST_API() )->init();
    if ( is_admin() ) {
        ( new Role_Manager_Admin() )->init();
    }
}

init_asenha_user_role_manager();