<?php

/**
 * User Role Manager: role service.
 *
 * Reads and writes roles through one code path. Every write:
 *  1. builds the final roles array in memory,
 *  2. runs the guard rules,
 *  3. fires `asenha_role_manager_before_write` (Pro uses it to record undo history),
 *  4. saves the roles with a single update_option() on the core roles option,
 *  5. fires `asenha_role_manager_role_updated`.
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
 * Role service.
 */
class Role_Manager_Role_Service {
    /**
     * Maximum number of capabilities accepted in one list of a request.
     */
    const MAX_CAPS_PER_LIST = 3000;

    /**
     * Get the roles as stored right now.
     *
     * @return array Role slug => array( name, capabilities ).
     */
    public static function get_roles_data() {
        $roles_data = array();
        foreach ( wp_roles()->roles as $slug => $role ) {
            $capabilities = array();
            foreach ( (array) $role['capabilities'] as $cap => $granted ) {
                $capabilities[(string) $cap] = (bool) $granted;
            }
            $roles_data[(string) $slug] = array(
                'name'         => ( isset( $role['name'] ) ? (string) $role['name'] : (string) $slug ),
                'capabilities' => $capabilities,
            );
        }
        return $roles_data;
    }

    /**
     * Get the number of users per role.
     *
     * @return array Role slug => int.
     */
    private static function get_user_counts() {
        $counts = count_users();
        return ( isset( $counts['avail_roles'] ) ? (array) $counts['avail_roles'] : array() );
    }

    /**
     * Build the overview entry of one role.
     *
     * @param string $slug        Role slug.
     * @param array  $role        Role data (name, capabilities).
     * @param array  $roles_meta  Stored role metadata.
     * @param array  $user_counts Users per role.
     * @return array
     */
    private static function build_overview_item(
        $slug,
        array $role,
        array $roles_meta,
        array $user_counts
    ) {
        $granted = 0;
        $denied = 0;
        foreach ( $role['capabilities'] as $cap => $is_granted ) {
            if ( 0 === strpos( $cap, 'level_' ) ) {
                continue;
                // Legacy levels are not counted.
            }
            if ( $is_granted ) {
                ++$granted;
            } else {
                ++$denied;
            }
        }
        $changed = false;
        if ( Role_Manager_Guard::is_core_role( $slug ) ) {
            $baseline = Role_Manager_Capability_Catalog::get_baseline_caps( $slug );
            $current = $role['capabilities'];
            ksort( $baseline );
            ksort( $current );
            $changed = $baseline !== $current;
        }
        $meta = ( isset( $roles_meta[$slug] ) && is_array( $roles_meta[$slug] ) ? $roles_meta[$slug] : array() );
        return array(
            'slug'        => $slug,
            'name'        => translate_user_role( $role['name'] ),
            'rawName'     => $role['name'],
            'description' => ( isset( $meta['description'] ) ? (string) $meta['description'] : '' ),
            'source'      => ( isset( $meta['source'] ) ? (string) $meta['source'] : '' ),
            'isCore'      => Role_Manager_Guard::is_core_role( $slug ),
            'isDefault'   => get_option( 'default_role' ) === $slug,
            'isProtected' => 'administrator' === $slug,
            'isManaged'   => !empty( $meta['created'] ),
            'users'       => ( isset( $user_counts[$slug] ) ? (int) $user_counts[$slug] : 0 ),
            'granted'     => $granted,
            'denied'      => $denied,
            'modified'    => $changed,
            'usersUrl'    => esc_url_raw( add_query_arg( 'role', $slug, admin_url( 'users.php' ) ) ),
        );
    }

    /**
     * Get the overview of all roles.
     *
     * @return array List of role overview entries.
     */
    public static function get_overview() {
        $roles_meta = Role_Manager_Storage::get_roles_meta();
        $user_counts = self::get_user_counts();
        $overview = array();
        foreach ( self::get_roles_data() as $slug => $role ) {
            $overview[] = self::build_overview_item(
                $slug,
                $role,
                $roles_meta,
                $user_counts
            );
        }
        return $overview;
    }

    /**
     * Get one role with its capabilities.
     *
     * @param string $slug Role slug.
     * @return array|WP_Error
     */
    public static function get_role( $slug ) {
        $roles_data = self::get_roles_data();
        if ( !isset( $roles_data[$slug] ) ) {
            return new WP_Error('asenha_role_manager_unknown_role', __( 'That role does not exist.', 'admin-site-enhancements' ), array(
                'status' => 404,
            ));
        }
        $item = self::build_overview_item(
            $slug,
            $roles_data[$slug],
            Role_Manager_Storage::get_roles_meta(),
            self::get_user_counts()
        );
        $item['capabilities'] = (object) $roles_data[$slug]['capabilities'];
        return $item;
    }

    /**
     * Get the full state used to render the page.
     *
     * @return array
     */
    public static function get_state() {
        $roles_data = self::get_roles_data();
        return array(
            'roles'       => self::get_overview(),
            'catalog'     => Role_Manager_Capability_Catalog::get_catalog( $roles_data ),
            'defaultRole' => (string) get_option( 'default_role' ),
            'viewLevel'   => self::get_view_level(),
        );
    }

    /**
     * Get the view level saved for the current user.
     *
     * Essentials and By area are available in every version. All capabilities is Pro;
     * a saved "all" level falls back to By area when premium code is not allowed.
     *
     * @return string essentials | areas | all
     */
    public static function get_view_level() {
        $level = get_user_meta( get_current_user_id(), 'asenha_role_manager_view_level', true );
        if ( !in_array( $level, array('essentials', 'areas', 'all'), true ) ) {
            return 'essentials';
        }
        if ( 'all' !== $level ) {
            return $level;
        }
        $allow_all = false;
        if ( $allow_all ) {
            return 'all';
        }
        return 'areas';
    }

    /**
     * Save the view level of the current user.
     *
     * @param string $level essentials | areas | all. "all" is accepted only when premium code is allowed.
     * @return bool
     */
    public static function set_view_level( $level ) {
        $allowed = array('essentials', 'areas');
        if ( !in_array( $level, $allowed, true ) ) {
            return false;
        }
        update_user_meta( get_current_user_id(), 'asenha_role_manager_view_level', $level );
        return true;
    }

    /**
     * Whether a value looks like a capability we can safely store as an array key.
     *
     * @param mixed $cap Value.
     * @return bool
     */
    public static function is_storable_cap( $cap ) {
        return is_string( $cap ) && 1 === preg_match( '/^[A-Za-z0-9_\\-:.\\/ ]{1,120}$/', $cap );
    }

    /**
     * Sanitize a list of capability slugs from a request.
     *
     * @param mixed $list Raw list.
     * @return array|WP_Error
     */
    public static function sanitize_cap_list( $list ) {
        if ( empty( $list ) ) {
            return array();
        }
        if ( !is_array( $list ) || count( $list ) > self::MAX_CAPS_PER_LIST ) {
            return new WP_Error('asenha_role_manager_bad_caps', __( 'The list of capabilities is invalid or too long.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        $clean = array();
        foreach ( $list as $cap ) {
            if ( !self::is_storable_cap( $cap ) ) {
                return new WP_Error('asenha_role_manager_bad_cap', __( 'One of the capability names is not valid.', 'admin-site-enhancements' ), array(
                    'status' => 400,
                ));
            }
            $clean[$cap] = true;
        }
        return array_keys( $clean );
    }

    /**
     * Sanitize a role display name.
     *
     * @param mixed $name Raw name.
     * @return string
     */
    public static function sanitize_role_name( $name ) {
        $name = ( is_scalar( $name ) ? sanitize_text_field( (string) $name ) : '' );
        return ( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 ) );
    }

    /**
     * Sanitize a role description.
     *
     * @param mixed $description Raw description.
     * @return string
     */
    public static function sanitize_role_description( $description ) {
        $description = ( is_scalar( $description ) ? sanitize_textarea_field( (string) $description ) : '' );
        return ( function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 300 ) : substr( $description, 0, 300 ) );
    }

    /**
     * Write a set of role changes.
     *
     * @param array  $changes Role slug => array( name, capabilities ) to set, or null to delete the role.
     * @param string $action  Short action identifier, e.g. `update`, `create`, `delete`, `reset`, `import`.
     * @param array  $context Extra context passed to hooks (e.g. label, source).
     * @return array|WP_Error The patch that was applied.
     */
    public static function commit( array $changes, $action, array $context = array() ) {
        $before = self::get_roles_data();
        $after = $before;
        $patch = array(
            'action'  => $action,
            'context' => $context,
            'roles'   => array(),
        );
        foreach ( $changes as $slug => $new_role ) {
            $slug = (string) $slug;
            if ( null === $new_role ) {
                if ( !isset( $before[$slug] ) ) {
                    continue;
                }
                unset($after[$slug]);
                $patch['roles'][$slug] = array(
                    'before' => $before[$slug],
                    'after'  => null,
                );
                continue;
            }
            $old_caps = ( isset( $before[$slug] ) ? $before[$slug]['capabilities'] : array() );
            $guard = Role_Manager_Guard::check_role_caps( $slug, $old_caps, $new_role['capabilities'] );
            if ( is_wp_error( $guard ) ) {
                return $guard;
            }
            $after[$slug] = $new_role;
            $patch['roles'][$slug] = array(
                'before' => ( isset( $before[$slug] ) ? $before[$slug] : null ),
                'after'  => $new_role,
            );
            // Skip no-op changes.
            if ( $patch['roles'][$slug]['before'] === $patch['roles'][$slug]['after'] ) {
                unset($patch['roles'][$slug]);
            }
        }
        if ( empty( $patch['roles'] ) ) {
            return $patch;
        }
        if ( !isset( $after['administrator'] ) ) {
            return new WP_Error('asenha_role_manager_protected_role', __( 'The Administrator role cannot be removed.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        $access = Role_Manager_Guard::check_current_user_access( $after );
        if ( is_wp_error( $access ) ) {
            return $access;
        }
        /**
         * Fires right before roles are written.
         *
         * @since 9.2.0
         *
         * @param array $patch Array with `action`, `context` and `roles` (slug => array( before, after )). Each of before / after is null or array( name, capabilities ).
         */
        do_action( 'asenha_role_manager_before_write', $patch );
        $wp_roles = wp_roles();
        update_option( $wp_roles->role_key, $after );
        $wp_roles->for_site( get_current_blog_id() );
        /**
         * Fires after roles were written.
         *
         * @since 9.2.0
         *
         * @param array $patch Same structure as in `asenha_role_manager_before_write`.
         */
        do_action( 'asenha_role_manager_role_updated', $patch );
        return $patch;
    }

    /**
     * Make the stored roles match a target state, in one write.
     *
     * Roles that differ are updated, missing ones are created and, when allowed,
     * custom roles absent from the target are deleted. Core roles are never deleted.
     *
     * @param array  $target       Role slug => array( name, capabilities ).
     * @param string $action       Action identifier passed to the hooks.
     * @param array  $context      Context passed to the hooks.
     * @param bool   $allow_delete Whether custom roles absent from the target are deleted.
     * @return array|WP_Error The applied patch.
     */
    public static function apply_target_state(
        array $target,
        $action,
        array $context = array(),
        $allow_delete = true
    ) {
        $current = self::get_roles_data();
        $changes = array();
        foreach ( $target as $slug => $role ) {
            if ( !isset( $current[$slug] ) || $current[$slug] !== $role ) {
                $changes[$slug] = $role;
            }
        }
        if ( $allow_delete ) {
            foreach ( $current as $slug => $role ) {
                if ( !isset( $target[$slug] ) && !Role_Manager_Guard::is_core_role( $slug ) ) {
                    $changes[$slug] = null;
                }
            }
        }
        return self::commit( $changes, $action, $context );
    }

    /**
     * Create a role.
     *
     * @param string $name        Display name.
     * @param string $slug        Role slug.
     * @param string $description Optional description.
     * @param array  $caps        Capability => bool map to start with.
     * @param string $source      Optional source label stored in the role metadata.
     * @return array|WP_Error Role payload.
     */
    public static function create_role(
        $name,
        $slug,
        $description = '',
        array $caps = array(),
        $source = ''
    ) {
        if ( '' === $name ) {
            return new WP_Error('asenha_role_manager_name_required', __( 'Enter a name for the role.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        if ( !Role_Manager_Guard::is_valid_role_slug( $slug ) ) {
            return new WP_Error('asenha_role_manager_bad_slug', __( 'The role slug must start with a letter and can only contain lowercase letters, numbers, hyphens and underscores (2 to 40 characters).', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        if ( wp_roles()->is_role( $slug ) ) {
            return new WP_Error('asenha_role_manager_role_exists', __( 'A role with that slug already exists.', 'admin-site-enhancements' ), array(
                'status' => 409,
            ));
        }
        $patch = self::commit( array(
            $slug => array(
                'name'         => $name,
                'capabilities' => $caps,
            ),
        ), 'create', array(
            'label' => $name,
        ) );
        if ( is_wp_error( $patch ) ) {
            return $patch;
        }
        Role_Manager_Storage::update_role_meta( $slug, array(
            'description' => $description,
            'source'      => $source,
            'created'     => time(),
        ) );
        return self::get_role( $slug );
    }

    /**
     * Update a role: name, description and a capability diff.
     *
     * @param string      $slug        Role slug.
     * @param string|null $name        New display name, or null to keep it.
     * @param string|null $description New description, or null to keep it.
     * @param array       $grant       Capabilities to grant.
     * @param array       $deny        Capabilities to explicitly deny. Ignored unless premium code is allowed.
     * @param array       $unset       Capabilities to remove from the role.
     * @return array|WP_Error Role payload.
     */
    public static function update_role(
        $slug,
        $name,
        $description,
        array $grant,
        array $deny,
        array $unset
    ) {
        $allow_deny = false;
        // Explicit deny is part of the Pro All capabilities view.
        if ( !$allow_deny ) {
            $deny = array();
        }
        $roles_data = self::get_roles_data();
        if ( !isset( $roles_data[$slug] ) ) {
            return new WP_Error('asenha_role_manager_unknown_role', __( 'That role does not exist.', 'admin-site-enhancements' ), array(
                'status' => 404,
            ));
        }
        if ( array_intersect( $grant, $deny ) || array_intersect( $grant, $unset ) || array_intersect( $deny, $unset ) ) {
            return new WP_Error('asenha_role_manager_conflicting_caps', __( 'A capability cannot be in more than one list.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        $role = $roles_data[$slug];
        // New capabilities must have a well-formed, lowercase slug. Existing ones may be anything already stored.
        $known_caps = array();
        foreach ( $roles_data as $other_role ) {
            $known_caps += $other_role['capabilities'];
        }
        foreach ( array_merge( $grant, $deny ) as $cap ) {
            if ( !isset( $known_caps[$cap] ) && !Role_Manager_Guard::is_valid_cap_slug( $cap ) && !self::is_catalog_cap( $cap ) ) {
                return new WP_Error('asenha_role_manager_bad_cap', sprintf( 
                    /* translators: %s is a capability name */
                    __( '"%s" is not a valid capability name.', 'admin-site-enhancements' ),
                    $cap
                 ), array(
                    'status' => 400,
                ));
            }
        }
        foreach ( $grant as $cap ) {
            $role['capabilities'][$cap] = true;
        }
        foreach ( $deny as $cap ) {
            $role['capabilities'][$cap] = false;
        }
        foreach ( $unset as $cap ) {
            unset($role['capabilities'][$cap]);
        }
        if ( null !== $name && '' !== $name ) {
            $role['name'] = $name;
        }
        $patch = self::commit( array(
            $slug => $role,
        ), 'update', array(
            'label' => $role['name'],
        ) );
        if ( is_wp_error( $patch ) ) {
            return $patch;
        }
        if ( null !== $description ) {
            Role_Manager_Storage::update_role_meta( $slug, array(
                'description' => $description,
            ) );
        }
        return self::get_role( $slug );
    }

    /**
     * Whether a capability is one that WordPress core, a post type or a taxonomy defines.
     *
     * @param string $cap Capability.
     * @return bool
     */
    private static function is_catalog_cap( $cap ) {
        static $index = null;
        if ( null === $index ) {
            $index = array();
            $catalog = Role_Manager_Capability_Catalog::get_catalog( array() );
            foreach ( $catalog['groups'] as $group ) {
                foreach ( $group['caps'] as $entry ) {
                    $index[$entry['cap']] = true;
                }
            }
            foreach ( array_merge( $catalog['postTypes'], $catalog['taxonomies'] ) as $object ) {
                foreach ( $object['caps'] as $object_cap ) {
                    $index[$object_cap] = true;
                }
            }
            foreach ( $catalog['custom'] as $entry ) {
                $index[$entry['cap']] = true;
            }
        }
        return isset( $index[$cap] );
    }

    /**
     * Delete a custom role and move its users to another role.
     *
     * @param string $slug        Role slug.
     * @param string $reassign_to Slug of the role the users move to.
     * @return array|WP_Error Summary.
     */
    public static function delete_role( $slug, $reassign_to ) {
        $check = Role_Manager_Guard::check_delete_role( $slug, $reassign_to );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        $label = wp_roles()->roles[$slug]['name'];
        $patch = self::commit( array(
            $slug => null,
        ), 'delete', array(
            'label' => $label,
        ) );
        if ( is_wp_error( $patch ) ) {
            return $patch;
        }
        // Move users. The role is gone, but users still carry its slug in their capabilities meta.
        $moved = 0;
        for ($pass = 0; $pass < 200; $pass++) {
            $user_ids = get_users( array(
                'role'   => $slug,
                'fields' => 'ID',
                'number' => 200,
            ) );
            if ( empty( $user_ids ) ) {
                break;
            }
            foreach ( $user_ids as $user_id ) {
                $user = new \WP_User((int) $user_id);
                $user->remove_role( $slug );
                if ( empty( $user->roles ) ) {
                    $user->add_role( $reassign_to );
                }
                ++$moved;
            }
        }
        if ( get_option( 'default_role' ) === $slug ) {
            update_option( 'default_role', $reassign_to );
        }
        Role_Manager_Storage::update_role_meta( $slug, null );
        return array(
            'deleted'     => $slug,
            'reassigned'  => $moved,
            'reassign_to' => $reassign_to,
        );
    }

    /**
     * Reset a core role to its WordPress default capabilities.
     *
     * @param string $slug Core role slug.
     * @return array|WP_Error Role payload.
     */
    public static function reset_role( $slug ) {
        if ( !Role_Manager_Guard::is_core_role( $slug ) ) {
            return new WP_Error('asenha_role_manager_not_core', __( 'Only WordPress core roles can be reset to their defaults.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        $roles_data = self::get_roles_data();
        $baseline = Role_Manager_Capability_Catalog::get_baseline();
        if ( !isset( $roles_data[$slug] ) || !isset( $baseline[$slug] ) ) {
            return new WP_Error('asenha_role_manager_unknown_role', __( 'That role does not exist.', 'admin-site-enhancements' ), array(
                'status' => 404,
            ));
        }
        $patch = self::commit( array(
            $slug => array(
                'name'         => $roles_data[$slug]['name'],
                'capabilities' => Role_Manager_Capability_Catalog::get_baseline_caps( $slug ),
            ),
        ), 'reset', array(
            'label' => $roles_data[$slug]['name'],
        ) );
        if ( is_wp_error( $patch ) ) {
            return $patch;
        }
        return self::get_role( $slug );
    }

    /**
     * Set the default role for new users.
     *
     * @param string $slug Role slug.
     * @return true|WP_Error
     */
    public static function set_default_role( $slug ) {
        if ( !wp_roles()->is_role( $slug ) ) {
            return new WP_Error('asenha_role_manager_unknown_role', __( 'That role does not exist.', 'admin-site-enhancements' ), array(
                'status' => 404,
            ));
        }
        if ( 'administrator' === $slug ) {
            return new WP_Error('asenha_role_manager_admin_default', __( 'For security, the Administrator role cannot be the default role for new users.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        update_option( 'default_role', $slug );
        return true;
    }

    /**
     * Register a custom capability and optionally grant it to a role.
     *
     * @param string $cap         Capability slug.
     * @param string $label       Label.
     * @param string $description Description.
     * @param string $grant_to    Optional role slug to grant the capability to.
     * @return array|WP_Error Summary.
     */
    public static function add_custom_cap(
        $cap,
        $label,
        $description,
        $grant_to = ''
    ) {
        if ( !Role_Manager_Guard::is_valid_cap_slug( $cap ) ) {
            return new WP_Error('asenha_role_manager_bad_cap', __( 'The capability slug must start with a letter and can only contain lowercase letters, numbers, hyphens and underscores (2 to 60 characters).', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        $roles_data = self::get_roles_data();
        $catalog = Role_Manager_Capability_Catalog::get_catalog( $roles_data );
        $existing = array();
        foreach ( $catalog['groups'] as $group ) {
            foreach ( $group['caps'] as $entry ) {
                $existing[$entry['cap']] = true;
            }
        }
        foreach ( array_merge( $catalog['postTypes'], $catalog['taxonomies'] ) as $object ) {
            foreach ( $object['caps'] as $object_cap ) {
                $existing[$object_cap] = true;
            }
        }
        foreach ( $roles_data as $role ) {
            $existing += $role['capabilities'];
        }
        if ( isset( $existing[$cap] ) || isset( Role_Manager_Storage::get_custom_caps()[$cap] ) ) {
            return new WP_Error('asenha_role_manager_cap_exists', __( 'That capability already exists.', 'admin-site-enhancements' ), array(
                'status' => 409,
            ));
        }
        Role_Manager_Storage::add_custom_cap( $cap, $label, $description );
        if ( '' !== $grant_to ) {
            if ( !isset( $roles_data[$grant_to] ) ) {
                return new WP_Error('asenha_role_manager_unknown_role', __( 'That role does not exist.', 'admin-site-enhancements' ), array(
                    'status' => 404,
                ));
            }
            $role = $roles_data[$grant_to];
            $role['capabilities'][$cap] = true;
            $patch = self::commit( array(
                $grant_to => $role,
            ), 'add_cap', array(
                'label' => $cap,
            ) );
            if ( is_wp_error( $patch ) ) {
                return $patch;
            }
        }
        return array(
            'cap'     => $cap,
            'granted' => $grant_to,
        );
    }

    /**
     * Remove custom and unrecognized capabilities from every role.
     *
     * Capabilities defined by WordPress core, post types or taxonomies are never removed.
     *
     * @param array $caps Capabilities to remove.
     * @return array|WP_Error Summary.
     */
    public static function remove_caps_everywhere( array $caps ) {
        $roles_data = self::get_roles_data();
        $catalog = Role_Manager_Capability_Catalog::get_catalog( $roles_data );
        $removable = array();
        foreach ( $catalog['custom'] as $entry ) {
            $removable[$entry['cap']] = true;
        }
        foreach ( $catalog['unrecognized'] as $entry ) {
            $removable[$entry['cap']] = true;
        }
        // Capabilities attributed to a plugin can be removed too, e.g. those left behind by a removed plugin. The interface asks for confirmation first.
        foreach ( $catalog['thirdParty'] as $group ) {
            foreach ( $group['caps'] as $entry ) {
                $removable[$entry['cap']] = true;
            }
        }
        $protected = array_flip( Role_Manager_Guard::get_protected_admin_caps() );
        foreach ( $caps as $cap ) {
            if ( !isset( $removable[$cap] ) || isset( $protected[$cap] ) ) {
                return new WP_Error('asenha_role_manager_cap_not_removable', sprintf( 
                    /* translators: %s is a capability name */
                    __( 'The "%s" capability cannot be removed because it is defined by WordPress core or a content type.', 'admin-site-enhancements' ),
                    $cap
                 ), array(
                    'status' => 400,
                ));
            }
        }
        $changes = array();
        foreach ( $roles_data as $slug => $role ) {
            $changed = false;
            foreach ( $caps as $cap ) {
                if ( array_key_exists( $cap, $role['capabilities'] ) ) {
                    unset($role['capabilities'][$cap]);
                    $changed = true;
                }
            }
            if ( $changed ) {
                $changes[$slug] = $role;
            }
        }
        if ( !empty( $changes ) ) {
            $patch = self::commit( $changes, 'remove_caps', array(
                'label' => implode( ', ', $caps ),
            ) );
            if ( is_wp_error( $patch ) ) {
                return $patch;
            }
        }
        Role_Manager_Storage::remove_custom_caps( $caps );
        return array(
            'removed' => array_values( $caps ),
            'roles'   => count( $changes ),
        );
    }

}
