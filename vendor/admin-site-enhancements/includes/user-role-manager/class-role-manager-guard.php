<?php

/**
 * User Role Manager: guard rules.
 *
 * Server-side safety rules that are enforced on every write, regardless of what
 * the browser sends: the Administrator role keeps a protected minimum, users
 * cannot grant capabilities they do not hold, core roles cannot be deleted and
 * the current user cannot lock themselves out of the role manager.
 *
 * @package ASENHA\UserRoleManager
 * @since   9.2.0
 */
namespace ASENHA\UserRoleManager;

use WP_Error;
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
/**
 * Guard rules.
 */
class Role_Manager_Guard {
    /**
     * Capabilities the Administrator role must always keep.
     */
    const PROTECTED_ADMIN_CAPS = array(
        'manage_options',
        'list_users',
        'edit_users',
        'create_users',
        'delete_users',
        'promote_users',
        'activate_plugins'
    );

    /**
     * Slugs of roles that ship with WordPress core.
     */
    const CORE_ROLES = array(
        'administrator',
        'editor',
        'author',
        'contributor',
        'subscriber'
    );

    /**
     * Maximum length of a role slug.
     */
    const MAX_SLUG_LENGTH = 40;

    /**
     * Capability required to use the User Role Manager.
     *
     * @return string
     */
    public static function get_page_capability() {
        /**
         * Filters the capability required to use the User Role Manager page and REST API.
         *
         * @since 9.2.0
         *
         * @param string $capability Capability. Default `manage_options`.
         */
        $capability = apply_filters( 'asenha_role_manager_capability', 'manage_options' );
        return ( is_string( $capability ) && '' !== $capability ? $capability : 'manage_options' );
    }

    /**
     * Get the capabilities the Administrator role must keep.
     *
     * @return array
     */
    public static function get_protected_admin_caps() {
        /**
         * Filters the capabilities that the Administrator role must always keep.
         *
         * @since 9.2.0
         *
         * @param array $caps Capability slugs.
         */
        $caps = apply_filters( 'asenha_role_manager_protected_admin_caps', self::PROTECTED_ADMIN_CAPS );
        return array_values( array_unique( array_filter( (array) $caps, 'is_string' ) ) );
    }

    /**
     * Whether a role ships with WordPress core.
     *
     * @param string $slug Role slug.
     * @return bool
     */
    public static function is_core_role( $slug ) {
        return in_array( $slug, self::CORE_ROLES, true );
    }

    /**
     * Sanitize a role slug or capability slug.
     *
     * Only lowercase letters, digits, underscores and hyphens are allowed.
     *
     * @param mixed $raw Raw value.
     * @return string Sanitized slug. May be an empty string.
     */
    public static function sanitize_slug( $raw ) {
        if ( !is_scalar( $raw ) ) {
            return '';
        }
        return sanitize_key( (string) $raw );
    }

    /**
     * Whether a role slug is acceptable for a new role.
     *
     * @param string $slug Sanitized slug.
     * @return bool
     */
    public static function is_valid_role_slug( $slug ) {
        return 1 === preg_match( '/^[a-z][a-z0-9_-]{1,' . (self::MAX_SLUG_LENGTH - 1) . '}$/', $slug );
    }

    /**
     * Whether a capability slug is acceptable for a new capability.
     *
     * @param string $cap Sanitized slug.
     * @return bool
     */
    public static function is_valid_cap_slug( $cap ) {
        return 1 === preg_match( '/^[a-z][a-z0-9_-]{1,59}$/', $cap );
    }

    /**
     * Get the merged capabilities a user would have from the given roles.
     *
     * Mirrors WP_User::get_role_caps(): later roles override earlier ones and
     * capabilities stored on the user override role capabilities.
     *
     * @param \WP_User $user       User.
     * @param array    $roles_data Role slug => array( name, capabilities ) to use in place of the stored roles.
     * @return array Capability => bool.
     */
    public static function get_effective_caps( $user, array $roles_data ) {
        $all_caps = array();
        foreach ( (array) $user->roles as $role_slug ) {
            if ( isset( $roles_data[$role_slug]['capabilities'] ) ) {
                $all_caps = array_merge( $all_caps, (array) $roles_data[$role_slug]['capabilities'] );
            }
        }
        // Capabilities stored directly on the user (meta `wp_capabilities`) include role flags; they override role caps.
        return array_merge( $all_caps, (array) $user->caps );
    }

    /**
     * Validate the final capability set of a role before it is written.
     *
     * @param string $slug         Role slug.
     * @param array  $before_caps  Capability => bool before the change. Empty for new roles.
     * @param array  $after_caps   Capability => bool after the change.
     * @return true|WP_Error
     */
    public static function check_role_caps( $slug, array $before_caps, array $after_caps ) {
        // The Administrator role keeps its protected minimum.
        if ( 'administrator' === $slug ) {
            foreach ( self::get_protected_admin_caps() as $protected_cap ) {
                if ( empty( $after_caps[$protected_cap] ) ) {
                    return new WP_Error('asenha_role_manager_protected_cap', sprintf( 
                        /* translators: %s is a capability slug, e.g. manage_options */
                        __( 'The Administrator role must keep the "%s" capability.', 'admin-site-enhancements' ),
                        $protected_cap
                     ), array(
                        'status' => 400,
                    ));
                }
            }
        }
        // A user cannot grant capabilities they do not hold (super admins are exempt).
        if ( !is_super_admin() ) {
            foreach ( $after_caps as $cap => $granted ) {
                if ( !$granted ) {
                    continue;
                }
                if ( !empty( $before_caps[$cap] ) ) {
                    continue;
                    // Already granted before this change.
                }
                if ( !current_user_can( $cap ) ) {
                    return new WP_Error('asenha_role_manager_cannot_grant', sprintf( 
                        /* translators: %s is a capability slug, e.g. install_plugins */
                        __( 'You cannot grant the "%s" capability because your own account does not have it.', 'admin-site-enhancements' ),
                        $cap
                     ), array(
                        'status' => 403,
                    ));
                }
            }
        }
        return true;
    }

    /**
     * Make sure the current user still has access to the role manager after roles are changed.
     *
     * @param array $roles_after Role slug => array( name, capabilities ) as it would be stored after the change.
     * @return true|WP_Error
     */
    public static function check_current_user_access( array $roles_after ) {
        if ( is_super_admin() ) {
            return true;
        }
        $user = wp_get_current_user();
        if ( !$user || !$user->exists() ) {
            return new WP_Error('asenha_role_manager_no_user', __( 'You must be logged in.', 'admin-site-enhancements' ), array(
                'status' => 401,
            ));
        }
        $effective = self::get_effective_caps( $user, $roles_after );
        $required = self::get_page_capability();
        if ( empty( $effective[$required] ) ) {
            return new WP_Error('asenha_role_manager_lockout', __( 'This change would remove your own access to the User Role Manager, so it was not saved.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        return true;
    }

    /**
     * Validate the deletion of a role.
     *
     * @param string $slug        Role slug.
     * @param string $reassign_to Slug of the role users are moved to.
     * @return true|WP_Error
     */
    public static function check_delete_role( $slug, $reassign_to ) {
        if ( self::is_core_role( $slug ) ) {
            return new WP_Error('asenha_role_manager_core_role', __( 'WordPress core roles cannot be deleted. You can reset them to their defaults instead.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        $roles = wp_roles();
        if ( !$roles->is_role( $slug ) ) {
            return new WP_Error('asenha_role_manager_unknown_role', __( 'That role does not exist.', 'admin-site-enhancements' ), array(
                'status' => 404,
            ));
        }
        $allow_any_custom = false;
        if ( !$allow_any_custom ) {
            $roles_meta = Role_Manager_Storage::get_roles_meta();
            $created = ( isset( $roles_meta[$slug]['created'] ) ? (int) $roles_meta[$slug]['created'] : 0 );
            if ( $created < 1 ) {
                return new WP_Error('asenha_role_manager_not_managed', __( 'Only roles created with User Role Manager can be deleted.', 'admin-site-enhancements' ), array(
                    'status' => 400,
                ));
            }
        }
        if ( '' === $reassign_to || $reassign_to === $slug || !$roles->is_role( $reassign_to ) ) {
            return new WP_Error('asenha_role_manager_reassign_required', __( 'Choose another existing role to move the users of this role to.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        return true;
    }

}
