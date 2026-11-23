<?php

/**
 * User Role Manager: REST API.
 *
 * Namespace: asenha-role-manager/v1
 *
 * Every route uses check_permissions(): capability check, `wp_rest` nonce check
 * and same-origin enforcement. Writes go through Role_Manager_Role_Service, which
 * enforces the guard rules again on the server. Pro routes are registered from
 * Pro code and reuse check_permissions().
 *
 * @package ASENHA\UserRoleManager
 * @since   9.2.0
 */
namespace ASENHA\UserRoleManager;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
/**
 * REST API.
 */
class Role_Manager_REST_API {
    /**
     * REST namespace.
     */
    const REST_NAMESPACE = 'asenha-role-manager/v1';

    /**
     * Regular expression matching a role slug in a route.
     */
    const SLUG_PATTERN = '[A-Za-z0-9_\\-]+';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init() {
        add_action( 'rest_api_init', array($this, 'register_routes') );
    }

    /**
     * Shared permission callback for all routes of this namespace.
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error
     */
    public static function check_permissions( $request ) {
        if ( !is_user_logged_in() || !current_user_can( Role_Manager_Guard::get_page_capability() ) ) {
            return new WP_Error('asenha_role_manager_forbidden', __( 'You are not allowed to manage user roles.', 'admin-site-enhancements' ), array(
                'status' => rest_authorization_required_code(),
            ));
        }
        // Nonce: cookie authentication needs a valid X-WP-Nonce header.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( empty( $nonce ) || !wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error('asenha_role_manager_bad_nonce', __( 'Your session has expired. Reload the page and try again.', 'admin-site-enhancements' ), array(
                'status' => 403,
            ));
        }
        // Same-origin enforcement: a present Origin or Referer must belong to this site.
        $allowed_hosts = array_filter( array(wp_parse_url( site_url(), PHP_URL_HOST ), wp_parse_url( home_url(), PHP_URL_HOST )) );
        foreach ( array('HTTP_ORIGIN', 'HTTP_REFERER') as $header ) {
            if ( empty( $_SERVER[$header] ) ) {
                continue;
            }
            $host = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER[$header] ) ), PHP_URL_HOST );
            if ( !$host || !in_array( $host, $allowed_hosts, true ) ) {
                return new WP_Error('asenha_role_manager_bad_origin', __( 'Invalid request origin.', 'admin-site-enhancements' ), array(
                    'status' => 403,
                ));
            }
            break;
            // Origin takes precedence over Referer.
        }
        return true;
    }

    /**
     * Register the free routes.
     *
     * @return void
     */
    public function register_routes() {
        $permission = array(__CLASS__, 'check_permissions');
        $slug_route = '(?P<slug>' . self::SLUG_PATTERN . ')';
        register_rest_route( self::REST_NAMESPACE, '/state', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_state'),
            'permission_callback' => $permission,
        ) );
        register_rest_route( self::REST_NAMESPACE, '/roles', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'create_role'),
            'permission_callback' => $permission,
        ) );
        register_rest_route( self::REST_NAMESPACE, '/roles/' . $slug_route, array(array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_role'),
            'permission_callback' => $permission,
        ), array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'update_role'),
            'permission_callback' => $permission,
        )) );
        register_rest_route( self::REST_NAMESPACE, '/roles/' . $slug_route . '/delete', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'delete_role'),
            'permission_callback' => $permission,
        ) );
        register_rest_route( self::REST_NAMESPACE, '/roles/' . $slug_route . '/reset', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'reset_role'),
            'permission_callback' => $permission,
        ) );
        register_rest_route( self::REST_NAMESPACE, '/default-role', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'set_default_role'),
            'permission_callback' => $permission,
        ) );
        register_rest_route( self::REST_NAMESPACE, '/view-level', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'set_view_level'),
            'permission_callback' => $permission,
        ) );
    }

    /**
     * Get the JSON body of a request as an array.
     *
     * @param WP_REST_Request $request Request.
     * @return array
     */
    private function get_params( $request ) {
        $params = $request->get_json_params();
        return ( is_array( $params ) ? $params : array() );
    }

    /**
     * Convert a service result to a REST response.
     *
     * @param mixed $result Result or WP_Error.
     * @return WP_REST_Response|WP_Error
     */
    private function respond( $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    /**
     * GET /state
     *
     * @return WP_REST_Response
     */
    public function get_state() {
        return rest_ensure_response( Role_Manager_Role_Service::get_state() );
    }

    /**
     * GET /roles/{slug}
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_role( $request ) {
        return $this->respond( Role_Manager_Role_Service::get_role( Role_Manager_Guard::sanitize_slug( $request['slug'] ) ) );
    }

    /**
     * POST /roles
     *
     * Body: name, slug, description, clone_from (optional role slug), preset (optional core role slug).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_role( $request ) {
        $params = $this->get_params( $request );
        $name = Role_Manager_Role_Service::sanitize_role_name( ( isset( $params['name'] ) ? $params['name'] : '' ) );
        $slug = Role_Manager_Guard::sanitize_slug( ( isset( $params['slug'] ) && '' !== $params['slug'] ? $params['slug'] : $name ) );
        $description = Role_Manager_Role_Service::sanitize_role_description( ( isset( $params['description'] ) ? $params['description'] : '' ) );
        $clone_from = Role_Manager_Guard::sanitize_slug( ( isset( $params['clone_from'] ) ? $params['clone_from'] : '' ) );
        $preset = Role_Manager_Guard::sanitize_slug( ( isset( $params['preset'] ) ? $params['preset'] : '' ) );
        $caps = array();
        if ( '' !== $clone_from ) {
            $roles_data = Role_Manager_Role_Service::get_roles_data();
            if ( !isset( $roles_data[$clone_from] ) ) {
                return new WP_Error('asenha_role_manager_unknown_role', __( 'The role to clone does not exist.', 'admin-site-enhancements' ), array(
                    'status' => 404,
                ));
            }
            $caps = $roles_data[$clone_from]['capabilities'];
        } elseif ( '' !== $preset ) {
            $caps = Role_Manager_Capability_Catalog::get_baseline_caps( $preset );
        }
        return $this->respond( Role_Manager_Role_Service::create_role(
            $name,
            $slug,
            $description,
            $caps
        ) );
    }

    /**
     * POST /roles/{slug}
     *
     * Body: name, description, grant[], deny[], unset[].
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_role( $request ) {
        $params = $this->get_params( $request );
        $slug = Role_Manager_Guard::sanitize_slug( $request['slug'] );
        $grant = Role_Manager_Role_Service::sanitize_cap_list( ( isset( $params['grant'] ) ? $params['grant'] : array() ) );
        $deny = Role_Manager_Role_Service::sanitize_cap_list( ( isset( $params['deny'] ) ? $params['deny'] : array() ) );
        $unset = Role_Manager_Role_Service::sanitize_cap_list( ( isset( $params['unset'] ) ? $params['unset'] : array() ) );
        foreach ( array($grant, $deny, $unset) as $list ) {
            if ( is_wp_error( $list ) ) {
                return $list;
            }
        }
        $name = ( isset( $params['name'] ) ? Role_Manager_Role_Service::sanitize_role_name( $params['name'] ) : null );
        $description = ( isset( $params['description'] ) ? Role_Manager_Role_Service::sanitize_role_description( $params['description'] ) : null );
        return $this->respond( Role_Manager_Role_Service::update_role(
            $slug,
            $name,
            $description,
            $grant,
            $deny,
            $unset
        ) );
    }

    /**
     * POST /roles/{slug}/delete
     *
     * Body: reassign_to.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_role( $request ) {
        $params = $this->get_params( $request );
        $reassign_to = Role_Manager_Guard::sanitize_slug( ( isset( $params['reassign_to'] ) ? $params['reassign_to'] : '' ) );
        return $this->respond( Role_Manager_Role_Service::delete_role( Role_Manager_Guard::sanitize_slug( $request['slug'] ), $reassign_to ) );
    }

    /**
     * POST /roles/{slug}/reset
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function reset_role( $request ) {
        return $this->respond( Role_Manager_Role_Service::reset_role( Role_Manager_Guard::sanitize_slug( $request['slug'] ) ) );
    }

    /**
     * POST /default-role
     *
     * Body: slug.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function set_default_role( $request ) {
        $params = $this->get_params( $request );
        $result = Role_Manager_Role_Service::set_default_role( Role_Manager_Guard::sanitize_slug( ( isset( $params['slug'] ) ? $params['slug'] : '' ) ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array(
            'defaultRole' => (string) get_option( 'default_role' ),
        ) );
    }

    /**
     * POST /custom-caps
     *
     * Body: cap, label, description, grant_to.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function add_custom_cap( $request ) {
        $params = $this->get_params( $request );
        $cap = Role_Manager_Guard::sanitize_slug( ( isset( $params['cap'] ) ? $params['cap'] : '' ) );
        $label = Role_Manager_Role_Service::sanitize_role_name( ( isset( $params['label'] ) ? $params['label'] : '' ) );
        $description = Role_Manager_Role_Service::sanitize_role_description( ( isset( $params['description'] ) ? $params['description'] : '' ) );
        $grant_to = Role_Manager_Guard::sanitize_slug( ( isset( $params['grant_to'] ) ? $params['grant_to'] : '' ) );
        return $this->respond( Role_Manager_Role_Service::add_custom_cap(
            $cap,
            $label,
            $description,
            $grant_to
        ) );
    }

    /**
     * POST /custom-caps/delete
     *
     * Body: caps[].
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_caps( $request ) {
        $params = $this->get_params( $request );
        $caps = Role_Manager_Role_Service::sanitize_cap_list( ( isset( $params['caps'] ) ? $params['caps'] : array() ) );
        if ( is_wp_error( $caps ) ) {
            return $caps;
        }
        if ( empty( $caps ) ) {
            return new WP_Error('asenha_role_manager_no_caps', __( 'Select at least one capability to remove.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        return $this->respond( Role_Manager_Role_Service::remove_caps_everywhere( $caps ) );
    }

    /**
     * POST /view-level
     *
     * Body: level.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function set_view_level( $request ) {
        $params = $this->get_params( $request );
        $level = Role_Manager_Guard::sanitize_slug( ( isset( $params['level'] ) ? $params['level'] : '' ) );
        if ( !Role_Manager_Role_Service::set_view_level( $level ) ) {
            return new WP_Error('asenha_role_manager_bad_level', __( 'Unknown view level.', 'admin-site-enhancements' ), array(
                'status' => 400,
            ));
        }
        return rest_ensure_response( array(
            'viewLevel' => $level,
        ) );
    }

}
