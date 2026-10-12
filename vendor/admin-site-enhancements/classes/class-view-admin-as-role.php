<?php

namespace ASENHA\Classes;

/**
 * Class for View Admin as Role module
 *
 * @since 6.9.5
 */
class View_Admin_As_Role {

    /**
     * User meta key for hashed recovery token.
     *
     * @var string
     */
    const RESET_TOKEN_META_KEY = '_asenha_view_admin_as_reset_token';

    /**
     * User meta key for plain recovery token (settings display only).
     *
     * @var string
     */
    const PLAIN_TOKEN_META_KEY = '_asenha_view_admin_as_reset_plain';

    /**
     * Transient key prefix for displaying recovery URL after switching roles.
     *
     * @var string
     */
    const RECOVERY_URL_TRANSIENT_PREFIX = 'asenha_view_admin_as_recovery_';

    /**
     * Query argument for lockout recovery URL.
     *
     * @var string
     */
    const RECOVERY_QUERY_ARG = 'asenha-role-reset';

    /**
     * User meta key for pending recovery URL refreshed admin notice.
     *
     * @var string
     */
    const RECOVERY_NOTICE_URL_META_KEY = '_asenha_view_admin_as_recovery_notice_url';

    /**
     * HttpOnly cookie that restores the administrator role after the login session ends.
     *
     * The cookie does not authenticate the user.
     *
     * @var string
     */
    const RECOVERY_COOKIE_NAME = 'asenha_view_admin_as_restore';

    /**
     * User meta key for the HMAC of the role-restore cookie.
     *
     * @var string
     */
    const RECOVERY_COOKIE_HASH_META_KEY = '_asenha_view_admin_as_restore_cookie';

    /**
     * Query argument for the login notice shown after an automatic role restore.
     *
     * @var string
     */
    const ROLE_RESTORED_QUERY_ARG = 'asenha-role-restored';

    /**
     * sessionStorage key for the recovery URL copy result after a role switch.
     *
     * @var string
     */
    const RECOVERY_COPY_STORAGE_KEY = 'asenha_view_admin_as_recovery_copy';

    /**
     * How long the role-restore cookie remains valid, in days.
     *
     * @var int
     */
    const RECOVERY_COOKIE_LIFETIME_DAYS = 30;

    /**
     * Refresh the remembered login when fewer than this many days remain.
     *
     * @var int
     */
    const SESSION_REFRESH_THRESHOLD_DAYS = 7;

    /**
     * Whether the current request is performing a plugin-initiated role switch.
     *
     * @var bool
     */
    private static $is_internal_role_switch = false;

    /**
     * Whether this request already reissued the auth cookie.
     *
     * Prevents a second refresh on the same request as a role switch.
     *
     * @var bool
     */
    private static $did_reissue_auth_cookie = false;

    /**
     * Add menu bar item to view admin as one of the user roles
     *
     * @param $wp_admin_bar The WP_Admin_Bar instance
     * @link https://developer.wordpress.org/reference/hooks/admin_bar_menu/
     * @link https://developer.wordpress.org/reference/classes/wp_admin_bar/
     * @since 1.8.0
     */
    public function view_admin_as_admin_bar_menu( $wp_admin_bar ) {

        $options = get_option( ASENHA_SLUG_U, array() );
        $usernames = isset( $options['viewing_admin_as_role_are'] ) ? $options['viewing_admin_as_role_are'] : array();
        
        $current_user = wp_get_current_user();
        $current_user_roles = array_values( $current_user->roles ); // indexed array
        $current_user_username = $current_user->user_login;

        // Get which role slug is currently set to "View as"
        $viewing_admin_as = get_user_meta( get_current_user_id(), '_asenha_viewing_admin_as', true );

        if ( empty( $viewing_admin_as ) ) {
            update_user_meta( get_current_user_id(), '_asenha_viewing_admin_as', 'administrator' );
        }

        // Get the role name, translated if available, from the role slug
        $wp_roles = wp_roles()->roles;

        foreach ( $wp_roles as $wp_role_slug => $wp_role_info ) {

            if ( $wp_role_slug == $viewing_admin_as ) {

                $viewing_admin_as_role_name = $wp_role_info['name'];

            }

        }

        if ( ! isset( $viewing_admin_as_role_name ) ) {

            $viewing_admin_as_role_name = $viewing_admin_as;

        }

        $translated_name_for_viewing_admin_as = ucfirst( $viewing_admin_as_role_name );

        // Add parent menu based on the role being set to "View as"

        if ( 'administrator' == $viewing_admin_as ) {

            if ( in_array( 'administrator', $current_user_roles ) ) {

                // Add parent menu for administrators
                $wp_admin_bar->add_menu( array(
                    'id'        => 'asenha-view-admin-as-role',
                    'parent'    => 'top-secondary',
                    'title'     => 'View as <span style="font-size:0.8125em;">&#9660;</span>',
                    'href'      => '#',
                    'meta'      => array(
                        'title' => 'View admin pages and the site (logged-in) as one of the following user roles.'
                    ),
                ) );

            }

        } else {

            // Limit to users performing role switching only. i.e. Don't show role switcher to regularly logging in users.
            if ( in_array( $current_user_username, $usernames ) ) {

                // Add parent menu
                $wp_admin_bar->add_menu( array(
                    'id'        => 'asenha-view-admin-as-role',
                    'parent'    => 'top-secondary',
                    'title'     => 'Viewing as ' . $translated_name_for_viewing_admin_as . ' <span style="font-size:0.8125em;">&#9660;</span>',
                    'href'      => '#',
                ) );
                
            }

        }

        // Get available role(s) to switch to
        $roles_to_switch_to = $this->get_roles_to_switch_to();

        // Add role(s) to switch to as sub-menu

        if ( 'administrator' == $viewing_admin_as ) {

            if ( in_array( 'administrator', $current_user_roles ) ) {
                
                // Add submenu for each role other than Administrator

                $i = 1;

                foreach ( $roles_to_switch_to as $role_slug => $data ) {

                    $wp_admin_bar->add_menu( array(
                        'id'        => 'role' . $i . '_' . $role_slug, // id based on role slug, e.g. role1_editor, role5_shop_manager
                        'parent'    => 'asenha-view-admin-as-role',
                        'title'     => $data['role_name'], // role name, e.g. Editor, Shop Manager
                        'href'      => $data['nonce_url'], // nonce URL for each role
                    ) );

                    $i++;

                }

            }

        } else {

            // Add submenu to switch back to Administrator role
            // Limit to users performing role switching only. i.e. Don't show role switcher to regularly logging in users.

            if ( in_array( $current_user_username, $usernames ) ) {

                foreach ( $roles_to_switch_to as $role_slug => $data ) {

                    $wp_admin_bar->add_menu( array(
                        'id'        => 'role_' . $role_slug, // id based on role slug, e.g. role1_editor, role5_shop_manager
                        'parent'    => 'asenha-view-admin-as-role',
                        'title'     => 'Switch back to ' . $data['role_name'], // role name, e.g. Editor, Shop Manager
                        'href'      => $data['nonce_url'], // nonce URL for each role

                    ) );
                
                }
                
            }

        }

    }

    /** 
     * Get roles availble to switch to
     *
     * @since 1.8.0
     */
    private function get_roles_to_switch_to() {

        $current_user = wp_get_current_user();
        $current_user_role_slugs = $current_user->roles; // indexed array of current user role slug(s)

        // Get full list of roles defined in WordPress
        $wp_roles = wp_roles()->roles;

        $roles_to_switch_to = array();

        // Get which role slug is currently active for viewing
        $viewing_admin_as = get_user_meta( get_current_user_id(), '_asenha_viewing_admin_as', true );

        if ( 'administrator' == $viewing_admin_as ) {

             // Exclude 'Administrator' from the "View as" menu

            foreach ( $wp_roles as $wp_role_slug => $wp_role_info ) {

                if ( ! in_array( $wp_role_slug,$current_user_role_slugs ) ) {

                    $roles_to_switch_to[$wp_role_slug] = array( 
                        'role_name' => $wp_role_info['name'], // role name, e.g. Editor, Shop Manager
                        'nonce_url' => wp_nonce_url(
                                            add_query_arg( array(
                                                'action'    => 'switch_role_to',
                                                'role'      => $wp_role_slug,
                                            ) ), // add query parameters to current URl, this is the $actionurl that will be appended with the nonce action
                                            'asenha_view_admin_as_' . $wp_role_slug, // the nonce $action name
                                            'nonce' // the nonce url parameter name
                                        ) // will result in a URL that looks like https://www.example.com/wp-admin/index.php?action=switch_role_to&role=editor&nonce=2ced3a40df
                    );

                }

            }

        } else {

            // Only show switch back to Administrator in the "View as" menu

            $roles_to_switch_to['administrator'] = array( 
                'role_name' => 'Administrator', // role name, e.g. Editor, Shop Manager
                'nonce_url' => wp_nonce_url(
                                    add_query_arg( array(
                                        'action'    => 'switch_back_to_administrator',
                                        'role'      => 'administrator',
                                    ) ), // add query parameters to current URl, this is the $actionurl that will be appended with the nonce action
                                    'asenha_view_admin_as_administrator', // the nonce $action name
                                    'nonce' // the nonce url parameter name
                                ) // will result in a URL that looks like https://www.example.com/wp-admin/index.php?action=switch_role_to&role=editor&nonce=2ced3a40df
            );
        }

        return $roles_to_switch_to; // array of $role_slug => $nonce_url

    }

    /**
     * Get the nonce URL for switching back to the administrator role.
     *
     * @since 8.8.5
     * @return string
     */
    private function get_switch_back_nonce_url() {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'switch_back_to_administrator',
                    'role'   => 'administrator',
                )
            ),
            'asenha_view_admin_as_administrator',
            'nonce'
        );
    }

    /**
     * Generate and store a hashed recovery token for lockout recovery.
     *
     * @since 8.8.5
     * @param int $user_id User ID.
     * @return string Plain recovery token for URL construction.
     */
    private function generate_recovery_token( $user_id ) {
        $plain_token = wp_generate_password( 43, false, false );
        update_user_meta( $user_id, self::RESET_TOKEN_META_KEY, wp_hash_password( $plain_token ) );
        update_user_meta( $user_id, self::PLAIN_TOKEN_META_KEY, $plain_token );
        set_transient( self::RECOVERY_URL_TRANSIENT_PREFIX . $user_id, $plain_token, DAY_IN_SECONDS );

        return $plain_token;
    }

    /**
     * Return an existing recovery token or generate one for the current admin.
     *
     * @since 8.8.5
     * @param int $user_id User ID.
     * @return string Plain recovery token, or empty string when not allowed.
     */
    public function ensure_recovery_token( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || $user_id !== get_current_user_id() || ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        $options = get_option( ASENHA_SLUG_U, array() );

        if ( empty( $options['view_admin_as_role'] ) ) {
            return '';
        }

        $plain_token = get_user_meta( $user_id, self::PLAIN_TOKEN_META_KEY, true );

        if ( ! empty( $plain_token ) && is_string( $plain_token ) && $this->verify_reset_token( $user_id, $plain_token ) ) {
            return $plain_token;
        }

        return $this->generate_recovery_token( $user_id );
    }

    /**
     * Verify a submitted recovery token for a user.
     *
     * @since 8.8.5
     * @param int    $user_id User ID.
     * @param string $token   Plain recovery token.
     * @return bool
     */
    private function verify_reset_token( $user_id, $token ) {
        if ( empty( $token ) || ! is_numeric( $user_id ) || $user_id <= 0 ) {
            return false;
        }

        $stored_hash = get_user_meta( $user_id, self::RESET_TOKEN_META_KEY, true );

        if ( empty( $stored_hash ) || ! is_string( $stored_hash ) ) {
            return false;
        }

        return wp_check_password( $token, $stored_hash, $user_id );
    }

    /**
     * Build the lockout recovery URL for a plain token.
     *
     * @since 8.8.5
     * @param string $plain_token Plain recovery token.
     * @return string
     */
    public function get_recovery_url_for_token( $plain_token ) {
        return add_query_arg(
            self::RECOVERY_QUERY_ARG,
            rawurlencode( $plain_token ),
            site_url( '/' )
        );
    }

    /**
     * Get the recovery URL to display on the settings screen for the current user.
     *
     * @since 8.8.5
     * @param int $user_id User ID.
     * @return string Recovery URL or instructional text.
     */
    public function get_recovery_url_for_settings( $user_id ) {
        $plain_token = $this->ensure_recovery_token( $user_id );

        if ( ! empty( $plain_token ) ) {
            return $this->get_recovery_url_for_token( $plain_token );
        }

        return __( 'Enable this module to generate your secret recovery URL.', 'admin-site-enhancements' );
    }

    /**
     * Find a user ID in the allowlist that matches a recovery token.
     *
     * @since 8.8.5
     * @param string $token Plain recovery token.
     * @return int User ID or 0 when not found.
     */
    private function get_user_id_by_reset_token( $token ) {
        $options = get_option( ASENHA_SLUG_U, array() );
        $usernames = isset( $options['viewing_admin_as_role_are'] ) ? $options['viewing_admin_as_role_are'] : array();

        if ( empty( $usernames ) || ! is_array( $usernames ) ) {
            return 0;
        }

        foreach ( $usernames as $username ) {
            $user = get_user_by( 'login', $username );

            if ( ! $user ) {
                continue;
            }

            if ( $this->verify_reset_token( $user->ID, $token ) ) {
                return (int) $user->ID;
            }
        }

        return 0;
    }

    /**
     * Restore a user's original roles and clear recovery state.
     *
     * @since 8.8.5
     * @param \WP_User $user User object.
     * @return bool
     */
    private function restore_original_roles_for_user( $user ) {
        if ( ! $user instanceof \WP_User ) {
            return false;
        }

        $current_user_role_slugs = $user->roles;
        $original_role_slugs = get_user_meta( $user->ID, '_asenha_view_admin_as_original_roles', true );

        if ( empty( $original_role_slugs ) || ! is_array( $original_role_slugs ) ) {
            return false;
        }

        self::$is_internal_role_switch = true;

        foreach ( $current_user_role_slugs as $current_role_slug ) {
            $user->remove_role( $current_role_slug );
        }

        foreach ( $original_role_slugs as $original_role_slug ) {
            $user->add_role( $original_role_slug );
        }

        self::$is_internal_role_switch = false;

        update_user_meta( $user->ID, '_asenha_viewing_admin_as', 'administrator' );
        $this->clear_view_admin_as_recovery_state( $user->ID );

        return true;
    }

    /**
     * Redirect to the login page after a token-based recovery.
     *
     * @since 8.8.5
     * @return void
     */
    private function redirect_after_token_recovery() {
        $options = get_option( ASENHA_SLUG_U, array() );

        if ( array_key_exists( 'change_login_url', $options ) && $options['change_login_url'] ) {
            if ( array_key_exists( 'custom_login_slug', $options ) && ! empty( $options['custom_login_slug'] ) )  {
                $login_url = get_site_url( null, $options['custom_login_slug'] );
            }
        }

        if ( empty( $login_url ) ) {
            $login_url = wp_login_url();
        }

        ?>
        <script>
            window.location.href='<?php echo esc_url( $login_url ); ?>';
        </script>
        <?php
        exit;
    }

    /**
     * Switch user role to view admin and site
     *
     * @since 1.8.0
     */
    public function role_switcher_to_view_admin_as() {

        $current_user = wp_get_current_user();
        $current_user_role_slugs = $current_user->roles; // indexed array of current user role slug(s)
        $current_user_username = $current_user->user_login;

        $options = get_option( ASENHA_SLUG_U, array() );
        $usernames = isset( $options['viewing_admin_as_role_are'] ) ? $options['viewing_admin_as_role_are'] : array();

        if ( isset( $_REQUEST['action'] ) && isset( $_REQUEST['role'] ) && isset( $_REQUEST['nonce'] ) ) {

            $action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
            $new_role = sanitize_text_field( wp_unslash( $_REQUEST['role'] ) );
            $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) );
            
            if ( 'switch_role_to' === $action ) {

                // Check nonce validity and role existence

                $wp_roles = array_keys( wp_roles()->roles ); // indexed array of all WP roles

                if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                    return;
                }

                if ( ! wp_verify_nonce( $nonce, 'asenha_view_admin_as_' . $new_role ) || ! in_array( $new_role, $wp_roles, true ) ) {
                    return; // cancel role switching
                }

                // Get original roles (before role switching) of the current user
                $original_role_slugs = get_user_meta( get_current_user_id(), '_asenha_view_admin_as_original_roles', true );

                // Store original user role(s) before switching it to another role
                
                if ( empty( $original_role_slugs ) ) {

                    update_user_meta( get_current_user_id(), '_asenha_view_admin_as_original_roles', $current_user_role_slugs );

                }

                $this->ensure_recovery_token( get_current_user_id() );

                // Store current user's username in options
                if ( ! in_array( $current_user_username, $usernames, true ) ) {
                    $usernames[] = $current_user_username;
                }
                $options['viewing_admin_as_role_are'] = array_values( array_unique( $usernames ) );
                update_option( ASENHA_SLUG_U, $options, true );
                
                self::$is_internal_role_switch = true;

                // Remove all current roles from current user.
                foreach ( $current_user_role_slugs as $current_user_role_slug ) {

                    $current_user->remove_role( $current_user_role_slug );

                }

                // Add new role to current user
                $current_user->add_role( $new_role );

                self::$is_internal_role_switch = false;

                // Mark that the user has switched to a non-administrator role
                update_user_meta( get_current_user_id(), '_asenha_viewing_admin_as', $new_role );

                $switched_user_id = get_current_user_id();
                $this->reissue_remembered_auth_cookie( $switched_user_id );
                $this->issue_role_recovery_cookie( $switched_user_id );

                wp_safe_redirect( get_admin_url() );
                exit;

            }

            if ( 'switch_back_to_administrator' === $action ) {

                // Check nonce validity

                if ( ! is_user_logged_in() ) {
                    return;
                }

                if ( ! wp_verify_nonce( $nonce, 'asenha_view_admin_as_administrator' ) || ( $new_role != 'administrator' ) ) {
                    return; // cancel role switching
                }

                if ( ! in_array( $current_user_username, $usernames, true ) ) {
                    return;
                }

                self::$is_internal_role_switch = true;

                // Remove all current roles from current user.
                foreach ( $current_user_role_slugs as $current_role_slug ) {

                    $current_user->remove_role( $current_role_slug );

                }

                // Get original roles (before role switching) of the current user
                $original_role_slugs = get_user_meta( get_current_user_id(), '_asenha_view_admin_as_original_roles', true );
                
                // Add the original roles to the current user
                if ( ! empty( $original_role_slugs ) && is_array( $original_role_slugs ) ) {
                    foreach ( $original_role_slugs as $original_role_slug ) {

                        $current_user->add_role( $original_role_slug );

                    }
                }

                self::$is_internal_role_switch = false;

                // Mark that the user has switched back to an administrator role
                update_user_meta( get_current_user_id(), '_asenha_viewing_admin_as', 'administrator' );
                $switch_back_user_id = get_current_user_id();
                $this->clear_view_admin_as_recovery_state( $switch_back_user_id );
                $plain_token  = $this->generate_recovery_token( $switch_back_user_id );
                $recovery_url = $this->get_recovery_url_for_token( $plain_token );
                update_user_meta( $switch_back_user_id, self::RECOVERY_NOTICE_URL_META_KEY, $recovery_url );

                wp_safe_redirect( get_admin_url() );
                exit;

            }

        } elseif ( isset( $_REQUEST[ self::RECOVERY_QUERY_ARG ] ) ) {

            $reset_token = sanitize_text_field( wp_unslash( $_REQUEST[ self::RECOVERY_QUERY_ARG ] ) );
            $reset_user_id = $this->get_user_id_by_reset_token( $reset_token );

            if ( $reset_user_id > 0 ) {
                $reset_user = get_user_by( 'id', $reset_user_id );

                if ( $reset_user && $this->restore_original_roles_for_user( $reset_user ) ) {
                    $this->redirect_after_token_recovery();
                }
            }

        }

    }
    
    /**
     * Clear View Admin as Role recovery state for a user.
     *
     * @since 8.8.5
     * @param int $user_id User ID.
     * @return void
     */
    public function clear_view_admin_as_recovery_state( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return;
        }

        $user = get_user_by( 'id', $user_id );

        if ( ! $user ) {
            return;
        }

        $username = $user->user_login;
        $options = get_option( ASENHA_SLUG_U, array() );
        $usernames = isset( $options['viewing_admin_as_role_are'] ) ? $options['viewing_admin_as_role_are'] : array();

        if ( ! empty( $usernames ) && is_array( $usernames ) ) {
            foreach ( $usernames as $key => $stored_username ) {
                if ( $username === $stored_username ) {
                    unset( $usernames[ $key ] );
                }
            }

            $options['viewing_admin_as_role_are'] = array_values( $usernames );
            update_option( ASENHA_SLUG_U, $options, true );
        }

        delete_user_meta( $user_id, '_asenha_viewing_admin_as' );
        delete_user_meta( $user_id, '_asenha_view_admin_as_original_roles' );
        delete_user_meta( $user_id, self::RESET_TOKEN_META_KEY );
        delete_user_meta( $user_id, self::PLAIN_TOKEN_META_KEY );
        delete_user_meta( $user_id, self::RECOVERY_NOTICE_URL_META_KEY );
        delete_user_meta( $user_id, self::RECOVERY_COOKIE_HASH_META_KEY );
        delete_transient( self::RECOVERY_URL_TRANSIENT_PREFIX . $user_id );
        $this->expire_role_recovery_cookie();
    }

    /**
     * Clear recovery state when a user's role is changed outside this module.
     *
     * @since 8.8.5
     * @param int      $user_id   User ID.
     * @param string   $role      New role.
     * @param string[] $old_roles Previous roles.
     * @return void
     */
    public function maybe_clear_view_admin_as_on_external_role_change( $user_id, $role = '', $old_roles = array() ) {
        unset( $role, $old_roles );

        if ( self::$is_internal_role_switch ) {
            return;
        }

        $viewing_admin_as = get_user_meta( $user_id, '_asenha_viewing_admin_as', true );

        if ( 'administrator' !== $viewing_admin_as && ! empty( $viewing_admin_as ) ) {
            $this->clear_view_admin_as_recovery_state( $user_id );
        }
    }

    /**
     * When changing a user's role via their profile edit screen, maybe we sbould remove the user's username from a list of usernames that can switch back to the administrator role. This addresses a vulnerability in a rare scenario disclosed by Pathstack.
     * 
     * @since 7.6.3
     */
    public function maybe_prevent_switchback_to_administrator( $user_id ) {
        if ( self::$is_internal_role_switch ) {
            return;
        }

        $viewing_admin_as = get_user_meta( $user_id, '_asenha_viewing_admin_as', true );

        if ( 'administrator' != $viewing_admin_as && ! empty( $viewing_admin_as ) ) {
            $this->clear_view_admin_as_recovery_state( $user_id );
        }
    }
    
    /**
     * Reissue a remembered auth cookie so closing the browser does not end a view-as session.
     *
     * WordPress's normal remembered lifetime is 14 days. When an existing session
     * token is passed, core does not update that token's stored expiration, so
     * this method copies the expiration from the auth cookie and saves it on the token.
     *
     * Do not hook role restore to clear_auth_cookie. That action also runs while
     * a new auth cookie is being issued, and restoring roles there would end view-as mode.
     *
     * @since 9.2.0
     * @param int    $user_id User ID.
     * @param string $token   Existing session token. Empty creates a new session.
     * @return void
     */
    private function reissue_remembered_auth_cookie( $user_id, $token = '' ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || headers_sent() ) {
            return;
        }

        self::$did_reissue_auth_cookie = true;

        // Core does not update an existing session token when one is passed in.
        // Capture the expiration actually written into the auth cookie.
        $cookie_expiration  = 0;
        $capture_expiration = static function ( $auth_cookie, $expire, $expiration ) use ( &$cookie_expiration ) {
            unset( $auth_cookie, $expire );
            $cookie_expiration = (int) $expiration;
        };

        add_action( 'set_auth_cookie', $capture_expiration, 10, 3 );
        wp_set_auth_cookie( $user_id, true, '', $token );
        remove_action( 'set_auth_cookie', $capture_expiration, 10 );

        if ( '' === $token || $cookie_expiration <= 0 ) {
            return;
        }

        $manager = \WP_Session_Tokens::get_instance( $user_id );
        $session = $manager->get( $token );

        if ( ! is_array( $session ) ) {
            return;
        }

        $session['expiration'] = $cookie_expiration;
        $manager->update( $token, $session );
    }

    /**
     * Keep an active view-as login remembered while it is still in use.
     *
     * Refreshes the current session when fewer than 7 days remain. The new
     * lifetime is WordPress's normal 14-day remembered cookie, not an unlimited one.
     *
     * @since 9.2.0
     * @return void
     */
    public function maybe_extend_view_as_session() {
        if ( self::$did_reissue_auth_cookie || ! is_user_logged_in() || headers_sent() ) {
            return;
        }

        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        if ( isset( $_REQUEST['action'] ) && 'logout' === sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $user = wp_get_current_user();

        if ( ! $this->is_allowlisted_non_admin_view( $user ) ) {
            return;
        }

        $token = wp_get_session_token();

        if ( ! is_string( $token ) || '' === $token ) {
            return;
        }

        $manager = \WP_Session_Tokens::get_instance( $user->ID );
        $session = $manager->get( $token );

        if ( ! is_array( $session ) || empty( $session['expiration'] ) ) {
            return;
        }

        $remaining = (int) $session['expiration'] - time();

        if ( $remaining > ( self::SESSION_REFRESH_THRESHOLD_DAYS * DAY_IN_SECONDS ) ) {
            return;
        }

        $this->reissue_remembered_auth_cookie( $user->ID, $token );
    }

    /**
     * Whether this user is in an active non-administrator view-as session.
     *
     * @since 9.2.0
     * @param \WP_User $user User object.
     * @return bool
     */
    private function is_allowlisted_non_admin_view( $user ) {
        if ( ! $user instanceof \WP_User || $user->ID <= 0 ) {
            return false;
        }

        $viewing_admin_as = get_user_meta( $user->ID, '_asenha_viewing_admin_as', true );

        if ( empty( $viewing_admin_as ) || 'administrator' === $viewing_admin_as ) {
            return false;
        }

        $original_role_slugs = get_user_meta( $user->ID, '_asenha_view_admin_as_original_roles', true );

        if ( empty( $original_role_slugs ) || ! is_array( $original_role_slugs ) ) {
            return false;
        }

        $options   = get_option( ASENHA_SLUG_U, array() );
        $usernames = isset( $options['viewing_admin_as_role_are'] ) ? $options['viewing_admin_as_role_are'] : array();

        if ( empty( $usernames ) || ! is_array( $usernames ) ) {
            return false;
        }

        return in_array( $user->user_login, $usernames, true );
    }

    /**
     * HMAC a role-restore cookie token for storage and comparison.
     *
     * @since 9.2.0
     * @param string $token Plain cookie token.
     * @return string
     */
    private function hash_recovery_cookie_token( $token ) {
        return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
    }

    /**
     * Store and send the role-restore cookie for a user who just switched roles.
     *
     * @since 9.2.0
     * @param int $user_id User ID.
     * @return void
     */
    private function issue_role_recovery_cookie( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || headers_sent() ) {
            return;
        }

        $token = wp_generate_password( 43, false, false );
        update_user_meta( $user_id, self::RECOVERY_COOKIE_HASH_META_KEY, $this->hash_recovery_cookie_token( $token ) );
        $this->send_recovery_cookie( $token, time() + ( self::RECOVERY_COOKIE_LIFETIME_DAYS * DAY_IN_SECONDS ) );
        $_COOKIE[ self::RECOVERY_COOKIE_NAME ] = $token;
    }

    /**
     * Send the role-restore cookie on the logged-in cookie paths.
     *
     * @since 9.2.0
     * @param string $token   Cookie value. Empty when expiring the cookie.
     * @param int    $expires Unix timestamp, or a past timestamp to expire the cookie.
     * @return void
     */
    private function send_recovery_cookie( $token, $expires ) {
        $base = array(
            'expires'  => (int) $expires,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        $paths = array( COOKIEPATH );

        if ( SITECOOKIEPATH !== COOKIEPATH ) {
            $paths[] = SITECOOKIEPATH;
        }

        foreach ( $paths as $path ) {
            $cookie_args         = $base;
            $cookie_args['path'] = $path;
            setcookie( self::RECOVERY_COOKIE_NAME, $token, $cookie_args );
        }
    }

    /**
     * Expire the role-restore cookie in the current browser.
     *
     * @since 9.2.0
     * @return void
     */
    private function expire_role_recovery_cookie() {
        unset( $_COOKIE[ self::RECOVERY_COOKIE_NAME ] );

        if ( headers_sent() || 'cli' === PHP_SAPI ) {
            return;
        }

        $this->send_recovery_cookie( '', time() - YEAR_IN_SECONDS );
    }

    /**
     * Find the allowlisted view-as user that matches a role-restore cookie.
     *
     * @since 9.2.0
     * @param string $token Plain cookie token.
     * @return \WP_User|null
     */
    private function get_user_for_recovery_cookie( $token ) {
        if ( ! is_string( $token ) || 1 !== preg_match( '/\A[A-Za-z0-9]{43}\z/', $token ) ) {
            return null;
        }

        $options   = get_option( ASENHA_SLUG_U, array() );
        $usernames = isset( $options['viewing_admin_as_role_are'] ) ? $options['viewing_admin_as_role_are'] : array();

        if ( empty( $usernames ) || ! is_array( $usernames ) ) {
            return null;
        }

        $actual_hash = $this->hash_recovery_cookie_token( $token );

        foreach ( $usernames as $username ) {
            $user = get_user_by( 'login', $username );

            if ( ! $user instanceof \WP_User ) {
                continue;
            }

            $stored_hash = get_user_meta( $user->ID, self::RECOVERY_COOKIE_HASH_META_KEY, true );

            if ( ! is_string( $stored_hash ) || strlen( $stored_hash ) !== strlen( $actual_hash ) ) {
                continue;
            }

            if ( hash_equals( $stored_hash, $actual_hash ) && $this->is_allowlisted_non_admin_view( $user ) ) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Restore the administrator role from the role-restore cookie when logged out.
     *
     * Runs before login credentials are checked. Does not create a login session.
     *
     * @since 9.2.0
     * @return void
     */
    public function maybe_restore_administrator_from_recovery_cookie() {
        if ( is_user_logged_in() || wp_doing_cron() ) {
            return;
        }

        if ( ! isset( $_COOKIE[ self::RECOVERY_COOKIE_NAME ] ) ) {
            return;
        }

        $raw_token = wp_unslash( $_COOKIE[ self::RECOVERY_COOKIE_NAME ] );

        if ( ! is_string( $raw_token ) || '' === $raw_token ) {
            return;
        }

        $user = $this->get_user_for_recovery_cookie( sanitize_text_field( $raw_token ) );

        if ( ! $user instanceof \WP_User ) {
            return;
        }

        if ( ! $this->restore_original_roles_for_user( $user ) ) {
            return;
        }

        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || headers_sent() ) {
            return;
        }

        wp_safe_redirect( $this->get_role_restored_login_url() );
        exit;
    }

    /**
     * Restore the administrator role when a view-as user logs out.
     *
     * Does not create a new login session.
     *
     * @since 9.2.0
     * @param int $user_id User ID passed by wp_logout.
     * @return void
     */
    public function maybe_restore_administrator_on_logout( $user_id = 0 ) {
        $user = get_user_by( 'id', (int) $user_id );

        if ( ! $user instanceof \WP_User || ! $this->is_allowlisted_non_admin_view( $user ) ) {
            return;
        }

        $this->restore_original_roles_for_user( $user );
    }

    /**
     * Login URL for the notice shown after an automatic role restore.
     *
     * Includes the custom login slug when Change Login URL is enabled, so the
     * notice is not stripped by that module's redirect.
     *
     * @since 9.2.0
     * @return string
     */
    private function get_role_restored_login_url() {
        $options = get_option( ASENHA_SLUG_U, array() );

        if ( ! empty( $options['change_login_url'] ) && ! empty( $options['custom_login_slug'] ) ) {
            $custom_login_slug = sanitize_text_field( $options['custom_login_slug'] );

            // Keep the slug as a query key, matching Change Login URL, and reject values that would add extra parameters.
            if ( '' !== $custom_login_slug && ! preg_match( '/[&?#\s]/', $custom_login_slug ) ) {
                return site_url( 'wp-login.php?' . $custom_login_slug . '&redirect=false&' . self::ROLE_RESTORED_QUERY_ARG . '=1' );
            }
        }

        return add_query_arg( self::ROLE_RESTORED_QUERY_ARG, '1', wp_login_url() );
    }

    /**
     * Tell the logged-out user that the administrator role was restored.
     *
     * @since 9.2.0
     * @param string $message Existing login message HTML.
     * @return string
     */
    public function maybe_show_role_restored_login_message( $message ) {
        $message = is_string( $message ) ? $message : '';

        if ( ! isset( $_REQUEST[ self::ROLE_RESTORED_QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return $message;
        }

        $flag = sanitize_text_field( wp_unslash( $_REQUEST[ self::ROLE_RESTORED_QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( '1' !== $flag ) {
            return $message;
        }

        $message .= '<p class="message">' . esc_html__( 'Your administrator role was restored. Please log in with your usual password.', 'admin-site-enhancements' ) . '</p>';

        return $message;
    }

    /**
     * Whether the current user should see the switch-back control.
     *
     * @since 9.2.0
     * @return bool
     */
    private function should_show_switch_back_button() {
        if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
            return false;
        }

        $current_user = wp_get_current_user();

        return $this->is_allowlisted_non_admin_view( $current_user );
    }

    /**
     * Load front-end styles for the switch-back button.
     *
     * Admin screens already style #role-view-reset from the admin stylesheet.
     *
     * @since 9.2.0
     * @return void
     */
    public function enqueue_frontend_switch_back_styles() {
        if ( is_admin() || ! $this->should_show_switch_back_button() ) {
            return;
        }

        $css = '
            #role-view-reset {
                position: fixed;
                right: 20px;
                bottom: 20px;
                z-index: 999999;
                max-width: calc(100% - 40px);
                box-shadow: rgba(0, 0, 0, 0.19) 0px 10px 20px, rgba(0, 0, 0, 0.23) 0px 6px 6px;
            }
            #role-view-reset .asenha-role-view-reset-link {
                display: inline-block;
                background: #2271b1;
                border: 1px solid #2271b1;
                border-radius: 3px;
                color: #fff;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                font-size: 13px;
                line-height: 2.15384615;
                padding: 0 10px;
                text-decoration: none;
                cursor: pointer;
            }
            #role-view-reset .asenha-role-view-reset-link:hover,
            #role-view-reset .asenha-role-view-reset-link:focus {
                background: #135e96;
                border-color: #135e96;
                color: #fff;
            }
        ';

        wp_register_style( 'asenha-view-admin-as-role-switch-back', false, array(), ASENHA_VERSION );
        wp_enqueue_style( 'asenha-view-admin-as-role-switch-back' );
        wp_add_inline_style( 'asenha-view-admin-as-role-switch-back', $css );
    }

    /**
     * Offer switch-back when the temporary role cannot open an admin screen.
     *
     * @since 9.2.0
     * @return void
     */
    public function maybe_offer_switch_back_on_admin_access_denied() {
        if ( wp_doing_ajax() || ! $this->should_show_switch_back_button() ) {
            return;
        }

        $message  = '<p>' . esc_html__( 'This account is viewing the admin as another role and cannot open this screen.', 'admin-site-enhancements' ) . '</p>';
        $message .= '<p><a href="' . esc_url( $this->get_switch_back_nonce_url() ) . '">' . esc_html__( 'Switch back to Administrator', 'admin-site-enhancements' ) . '</a></p>';

        // The script reads sessionStorage only. It does not contain the recovery URL.
        $die_message = wp_kses_post( $message ) . $this->get_recovery_copy_notice_script_tag();

        wp_die(
            $die_message,
            esc_html__( 'Switch back to Administrator', 'admin-site-enhancements' ),
            array(
                'response' => 403,
            )
        );
    }

    /**
     * Whether the current user is an administrator who has not switched roles.
     *
     * @since 9.2.0
     * @return bool
     */
    private function is_administrator_ready_to_switch() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $viewing_admin_as = get_user_meta( get_current_user_id(), '_asenha_viewing_admin_as', true );

        if ( empty( $viewing_admin_as ) ) {
            $viewing_admin_as = 'administrator';
        }

        return 'administrator' === $viewing_admin_as;
    }

    /**
     * Copy the secret recovery URL when an administrator chooses a role to view as.
     *
     * The URL is the same token already shown in the module settings. It is printed
     * only for the current administrator, and only before the role changes.
     *
     * @since 9.2.0
     * @return void
     */
    public function enqueue_recovery_url_copy_script() {
        if ( wp_doing_ajax() || wp_doing_cron() || ! $this->is_administrator_ready_to_switch() ) {
            return;
        }

        $plain_token = $this->ensure_recovery_token( get_current_user_id() );

        if ( '' === $plain_token ) {
            return;
        }

        $recovery_url = $this->get_recovery_url_for_token( $plain_token );

        wp_register_script( 'asenha-view-admin-as-role-copy', false, array(), ASENHA_VERSION, true );
        wp_enqueue_script( 'asenha-view-admin-as-role-copy' );
        wp_localize_script(
            'asenha-view-admin-as-role-copy',
            'asenhaViewAdminAsRoleCopy',
            array(
                'recoveryUrl' => $recovery_url,
                'storageKey'  => self::RECOVERY_COPY_STORAGE_KEY,
            )
        );
        wp_add_inline_script( 'asenha-view-admin-as-role-copy', $this->get_recovery_url_copy_js() );
    }

    /**
     * Click handler that copies the recovery URL before the role-switch navigation.
     *
     * @since 9.2.0
     * @return string
     */
    private function get_recovery_url_copy_js() {
        $js = <<<'JS'
document.addEventListener('click', function (event) {
	if (!event.target || !event.target.closest) {
		return;
	}
	var link = event.target.closest('#wp-admin-bar-asenha-view-admin-as-role a');
	if (!link || !link.href || link.href.indexOf('action=switch_role_to') === -1) {
		return;
	}
	if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
		return;
	}
	var config = window.asenhaViewAdminAsRoleCopy;
	if (!config || !config.recoveryUrl || !config.storageKey) {
		return;
	}
	event.preventDefault();
	var recoveryUrl = config.recoveryUrl;
	var go = function (copied) {
		try {
			sessionStorage.setItem(config.storageKey, JSON.stringify({ ok: !!copied, url: recoveryUrl }));
		} catch (error) {}
		window.location.assign(link.href);
	};
	var copyWithTextarea = function () {
		var copied = false;
		var textarea = document.createElement('textarea');
		textarea.value = recoveryUrl;
		textarea.setAttribute('readonly', '');
		textarea.style.position = 'fixed';
		textarea.style.top = '0';
		textarea.style.left = '0';
		textarea.style.opacity = '0';
		document.body.appendChild(textarea);
		textarea.focus();
		textarea.select();
		try {
			copied = document.execCommand('copy');
		} catch (error) {
			copied = false;
		}
		document.body.removeChild(textarea);
		go(copied);
	};
	if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(recoveryUrl).then(function () {
			go(true);
		}).catch(copyWithTextarea);
		return;
	}
	copyWithTextarea();
});
JS;

        return $js;
    }

    /**
     * Translated strings for the one-time copy confirmation.
     *
     * Does not include the recovery URL.
     *
     * @since 9.2.0
     * @return array<string, string>
     */
    private function get_recovery_copy_notice_config() {
        return array(
            'storageKey'     => self::RECOVERY_COPY_STORAGE_KEY,
            'copiedMessage'  => __( 'The secret recovery URL was copied to your clipboard. Opening it will restore your Administrator role.', 'admin-site-enhancements' ),
            'blockedMessage' => __( 'The browser blocked copying the secret recovery URL. Copy it from here before continuing.', 'admin-site-enhancements' ),
            'copyLabel'      => __( 'Copy', 'admin-site-enhancements' ),
            'copiedLabel'    => __( 'Copied', 'admin-site-enhancements' ),
            'dismissLabel'   => __( 'Dismiss this notice.', 'admin-site-enhancements' ),
        );
    }

    /**
     * Show a one-time notice after switching roles, based on the clipboard result.
     *
     * @since 9.2.0
     * @return void
     */
    public function enqueue_recovery_copy_notice_script() {
        if ( wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        wp_register_script( 'asenha-view-admin-as-role-copy-notice', false, array(), ASENHA_VERSION, true );
        wp_enqueue_script( 'asenha-view-admin-as-role-copy-notice' );
        wp_localize_script(
            'asenha-view-admin-as-role-copy-notice',
            'asenhaViewAdminAsRoleCopyNotice',
            $this->get_recovery_copy_notice_config()
        );
        wp_add_inline_script( 'asenha-view-admin-as-role-copy-notice', $this->get_recovery_copy_notice_js() );

        if ( is_admin() ) {
            return;
        }

        $css = '
            .asenha-view-admin-as-role-recovery-copy-toast {
                position: fixed;
                top: 46px;
                left: 20px;
                right: 20px;
                z-index: 100000;
                max-width: 720px;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-left-width: 4px;
                border-left-color: #72aee6;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
                padding: 12px 16px;
            }
            .asenha-view-admin-as-role-recovery-copy-toast p {
                margin: 0;
            }
            .asenha-view-admin-as-role-recovery-copy-toast strong {
                word-break: break-all;
            }
            .asenha-view-admin-as-role-recovery-copy-toast .button {
                display: inline-block;
                margin-top: 8px;
                background: #f6f7f7;
                border: 1px solid #2271b1;
                border-radius: 3px;
                color: #2271b1;
                cursor: pointer;
                font-size: 13px;
                line-height: 2.15384615;
                padding: 0 10px;
            }
        ';

        wp_register_style( 'asenha-view-admin-as-role-copy-notice', false, array(), ASENHA_VERSION );
        wp_enqueue_style( 'asenha-view-admin-as-role-copy-notice' );
        wp_add_inline_style( 'asenha-view-admin-as-role-copy-notice', $css );
    }

    /**
     * Read the clipboard result from sessionStorage and show it once.
     *
     * @since 9.2.0
     * @return string
     */
    private function get_recovery_copy_notice_js() {
        $js = <<<'JS'
(function () {
	var config = window.asenhaViewAdminAsRoleCopyNotice;
	if (!config || !config.storageKey) {
		return;
	}
	var raw;
	try {
		raw = sessionStorage.getItem(config.storageKey);
		if (!raw) {
			return;
		}
		sessionStorage.removeItem(config.storageKey);
	} catch (error) {
		return;
	}
	var data;
	try {
		data = JSON.parse(raw);
	} catch (error) {
		return;
	}
	if (!data || typeof data.url !== 'string' || data.url === '') {
		return;
	}
	var notice = document.createElement('div');
	notice.id = 'asenha-view-admin-as-role-recovery-copy-notice';
	notice.className = 'notice notice-info is-dismissible asenha-view-admin-as-role-recovery-notice';
	var paragraph = document.createElement('p');
	if (data.ok) {
		paragraph.appendChild(document.createTextNode(config.copiedMessage || ''));
	} else {
		paragraph.appendChild(document.createTextNode(config.blockedMessage || ''));
		paragraph.appendChild(document.createElement('br'));
		var urlText = document.createElement('strong');
		urlText.textContent = data.url;
		paragraph.appendChild(urlText);
		paragraph.appendChild(document.createElement('br'));
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'button button-secondary';
		button.textContent = config.copyLabel || 'Copy';
		button.addEventListener('click', function () {
			var markCopied = function () {
				button.textContent = config.copiedLabel || 'Copied';
			};
			var copyWithTextarea = function () {
				var copied = false;
				var textarea = document.createElement('textarea');
				textarea.value = data.url;
				textarea.setAttribute('readonly', '');
				textarea.style.position = 'fixed';
				textarea.style.top = '0';
				textarea.style.left = '0';
				textarea.style.opacity = '0';
				document.body.appendChild(textarea);
				textarea.focus();
				textarea.select();
				try {
					copied = document.execCommand('copy');
				} catch (error) {
					copied = false;
				}
				document.body.removeChild(textarea);
				if (copied) {
					markCopied();
				}
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(data.url).then(markCopied).catch(copyWithTextarea);
				return;
			}
			copyWithTextarea();
		});
		paragraph.appendChild(button);
	}
	notice.appendChild(paragraph);
	var dismiss = document.createElement('button');
	dismiss.type = 'button';
	dismiss.className = 'notice-dismiss';
	var dismissText = document.createElement('span');
	dismissText.className = 'screen-reader-text';
	dismissText.textContent = config.dismissLabel || 'Dismiss this notice.';
	dismiss.appendChild(dismissText);
	dismiss.addEventListener('click', function () {
		if (notice.parentNode) {
			notice.parentNode.removeChild(notice);
		}
	});
	notice.appendChild(dismiss);
	var dieMessage = document.querySelector('.wp-die-message');
	var adminContent = document.getElementById('wpbody-content');
	if (dieMessage) {
		dieMessage.appendChild(notice);
	} else if (adminContent) {
		adminContent.insertBefore(notice, adminContent.firstChild);
	} else if (document.body) {
		notice.classList.add('asenha-view-admin-as-role-recovery-copy-toast');
		document.body.appendChild(notice);
	}
})();
JS;

        return $js;
    }

    /**
     * Print the copy-confirmation script for screens that do not load footer scripts.
     *
     * The recovery URL is not included. Translated strings are JSON-encoded.
     *
     * @since 9.2.0
     * @return string
     */
    private function get_recovery_copy_notice_script_tag() {
        $config = wp_json_encode(
            $this->get_recovery_copy_notice_config(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if ( ! is_string( $config ) ) {
            return '';
        }

        return '<script>window.asenhaViewAdminAsRoleCopyNotice = ' . $config . ';' . $this->get_recovery_copy_notice_js() . '</script>';
    }

    /**
     * Add floating button to reset the view/account back to the administrator
     * 
     * @since 6.1.3
     */
    public function add_floating_reset_button() {
        // Show for non-admins who are in an active view-as session.
        if ( ! $this->should_show_switch_back_button() ) {
            return;
        }

        $link_class = is_admin() ? 'button button-primary' : 'asenha-role-view-reset-link';
        ?>
        <div id="role-view-reset">
            <a href="<?php echo esc_url( $this->get_switch_back_nonce_url() ); ?>" class="<?php echo esc_attr( $link_class ); ?>"><?php esc_html_e( 'Switch back to Administrator', 'admin-site-enhancements' ); ?></a>
        </div>
        <?php
    }

    /**
     * Show admin notice with refreshed recovery URL after switching back while logged in.
     *
     * @since 8.8.5
     * @return void
     */
    public function maybe_show_recovery_url_refreshed_notice() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $recovery_url = get_user_meta( get_current_user_id(), self::RECOVERY_NOTICE_URL_META_KEY, true );

        if ( empty( $recovery_url ) || ! is_string( $recovery_url ) ) {
            return;
        }

        ?>
        <div class="notice notice-info is-dismissible asenha-view-admin-as-role-recovery-notice" id="asenha-view-admin-as-role-recovery-notice">
            <p>
                <?php
                echo esc_html__(
                    'You switched back to the administrator role. Your secret recovery URL has been refreshed. Bookmark the new URL below before testing again.',
                    'admin-site-enhancements'
                );
                ?>
                <br /><strong><?php echo esc_html( $recovery_url ); ?></strong>
            </p>
        </div>
        <?php
    }

    /**
     * Dismiss the recovery URL refreshed admin notice for the current administrator.
     *
     * @since 8.8.5
     * @return void
     */
    public function dismiss_recovery_url_refreshed_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Insufficient permissions.', 'admin-site-enhancements' ),
                ),
                403
            );
        }

        check_ajax_referer( 'asenha-dismiss-view-admin-as-recovery-notice', 'nonce' );

        delete_user_meta( get_current_user_id(), self::RECOVERY_NOTICE_URL_META_KEY );

        wp_send_json_success();
    }

    /**
     * Enqueue the dismiss handler for the recovery URL refreshed admin notice.
     *
     * @since 8.8.5
     * @return void
     */
    public function enqueue_recovery_url_refreshed_notice_script() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $recovery_url = get_user_meta( get_current_user_id(), self::RECOVERY_NOTICE_URL_META_KEY, true );

        if ( empty( $recovery_url ) ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        wp_add_inline_script(
            'jquery',
            'jQuery(function($){$(document).on("click","#asenha-view-admin-as-role-recovery-notice .notice-dismiss",function(){$.post(ajaxurl,{action:"asenha_dismiss_view_admin_as_recovery_notice",nonce:' . wp_json_encode( wp_create_nonce( 'asenha-dismiss-view-admin-as-recovery-notice' ) ) . '});});});'
        );
    }

    /**
     * Show custom error page on switch failure, which causes inability to view admin dashboard/pages
     *
     * @since 1.8.0
     */
    public function custom_error_page_on_switch_failure( $callback ) {

        ?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8; ?>" />
    <meta name="viewport" content="width=device-width">
    <title>WordPress Error</title>
    <style type="text/css">
        html {
            background: #f1f1f1;
        }
        body {
            background: #fff;
            border: 1px solid #ccd0d4;
            color: #444;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            margin: 2em auto;
            padding: 1em 2em;
            max-width: 700px;
            -webkit-box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
            box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
        }
        h1 {
            border-bottom: 1px solid #dadada;
            clear: both;
            color: #666;
            font-size: 24px;
            margin: 30px 0 0 0;
            padding: 0;
            padding-bottom: 7px;
        }
        #error-page {
            margin-top: 50px;
        }
        #error-page p,
        #error-page .wp-die-message {
            font-size: 14px;
            line-height: 1.5;
            margin: 20px 0;
        }
        #error-page code {
            font-family: Consolas, Monaco, monospace;
        }
        a {
            color: #0073aa;
        }
        a:hover,
        a:active {
            color: #006799;
        }
        a:focus {
            color: #124964;
            -webkit-box-shadow:
                0 0 0 1px #5b9dd9,
                0 0 2px 1px rgba(30, 140, 190, 0.8);
            box-shadow:
                0 0 0 1px #5b9dd9,
                0 0 2px 1px rgba(30, 140, 190, 0.8);
            outline: none;
        }
    </style>
</head>
<body id="error-page">
    <div class="wp-die-message">Something went wrong. Please try logging in.</div>
</body>
</html>
        <?php

    }
    
}
