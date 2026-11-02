<?php

namespace ASENHA\Classes;

/**
 * Class for Disable Updates module
 *
 * @since 6.9.5
 */
class Disable_Updates {
    /**
     * Whether HTTP blocking and cron filters have already been added.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $http_cron_filters_added = false;

    /**
     * Block core version-check HTTP requests.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $block_http_core = false;

    /**
     * Block plugin update-check HTTP requests.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $block_http_plugins = false;

    /**
     * Block theme update-check HTTP requests.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $block_http_themes = false;

    /**
     * Prevent wp_version_check from being scheduled.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $block_cron_core = false;

    /**
     * Prevent wp_update_plugins from being scheduled.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $block_cron_plugins = false;

    /**
     * Prevent wp_update_themes from being scheduled.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $block_cron_themes = false;

    /**
     * Prevent wp_maybe_auto_update from being scheduled.
     *
     * @since 9.1.1
     * @var bool
     */
    protected $block_cron_auto_update = false;

    /**
     * Which admin_init disables to apply for the Pro granular path.
     *
     * @since 9.1.1
     * @var array
     */
    protected $granular_admin_init = array();

    /**
     * Disable updates and related functionalities (free all-in-one path).
     *
     * @since 4.0.0
     */
    public function disable_update_notices_version_checks() {
        $this->disable_update_notices();
        $this->disable_core_update_checks();
        $this->disable_theme_update_checks();
        $this->disable_plugin_update_checks();
        $this->disable_auto_update_cron();
        add_filter( 'site_status_tests', [$this, 'disable_update_checks_in_site_health'] );
    }

    /**
     * Remove update and maintenance nags in wp-admin.
     *
     * @since 9.1.1
     */
    public function disable_update_notices() {
        remove_action( 'admin_notices', 'update_nag', 3 );
        remove_action( 'network_admin_notices', 'update_nag', 3 );
        remove_action( 'admin_notices', 'maintenance_nag' );
        remove_action( 'network_admin_notices', 'maintenance_nag' );
    }

    /**
     * Stop WordPress core version checks.
     *
     * @since 9.1.1
     */
    public function disable_core_update_checks() {
        remove_action( 'wp_version_check', 'wp_version_check' );
        remove_action( 'admin_init', 'wp_version_check' );
        remove_action( 'admin_init', '_maybe_update_core' );
        wp_clear_scheduled_hook( 'wp_version_check' );
    }

    /**
     * Stop theme version checks.
     *
     * @since 9.1.1
     */
    public function disable_theme_update_checks() {
        remove_action( 'wp_update_themes', 'wp_update_themes' );
        remove_action( 'admin_init', '_maybe_update_themes' );
        wp_clear_scheduled_hook( 'wp_update_themes' );
        remove_action( 'load-themes.php', 'wp_update_themes' );
        remove_action( 'load-update.php', 'wp_update_themes' );
        remove_action( 'load-update-core.php', 'wp_update_themes' );
    }

    /**
     * Stop plugin version checks.
     *
     * @since 9.1.1
     */
    public function disable_plugin_update_checks() {
        remove_action( 'wp_update_plugins', 'wp_update_plugins' );
        remove_action( 'admin_init', '_maybe_update_plugins' );
        wp_clear_scheduled_hook( 'wp_update_plugins' );
        remove_action( 'load-plugins.php', 'wp_update_plugins' );
        remove_action( 'load-update.php', 'wp_update_plugins' );
        remove_action( 'load-update-core.php', 'wp_update_plugins' );
    }

    /**
     * Stop the automatic updater cron and related admin_init callbacks.
     *
     * @since 9.1.1
     */
    public function disable_auto_update_cron() {
        wp_clear_scheduled_hook( 'wp_maybe_auto_update' );
        remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
        remove_action( 'admin_init', 'wp_maybe_auto_update' );
        remove_action( 'admin_init', 'wp_auto_update_core' );
    }

    /**
     * Override version check info stored in transients named update_core, update_plugins, update_themes.
     *
     * @since 4.0.0
     */
    public function override_version_check_info() {
        if ( function_exists( 'wp_get_wp_version' ) ) {
            $wp_version = wp_get_wp_version();
        } else {
            include ABSPATH . WPINC . '/version.php';
            // get $wp_version from here
        }
        $current = new \stdClass();
        $current->updates = array();
        $current->response = array();
        $current->no_update = array();
        $current->translations = array();
        $current->checked = array();
        $current->version_checked = $wp_version;
        $current->last_checked = time();
        return $current;
    }

    /**
     * Strip translation offers from an update transient without hiding other updates.
     *
     * @since 9.1.1
     *
     * @param mixed $transient Update transient object or other value.
     * @return mixed
     */
    public function strip_translations_from_transient( $transient ) {
        if ( is_object( $transient ) ) {
            $transient->translations = array();
        }
        return $transient;
    }

    /**
     * Disable Background Updates and Auto-Updates tests in Site Health tests
     *
     * @since 4.0.0
     */
    public function disable_update_checks_in_site_health( $tests ) {
        unset($tests['async']['background_updates']);
        unset($tests['direct']['plugin_theme_auto_updates']);
        return $tests;
    }

    /**
     * Remove Dashboard >> Updates menu item
     *
     * @since 4.0.0
     */
    public function remove_updates_menu() {
        remove_submenu_page( 'index.php', 'update-core.php' );
    }

    /**
     * Enable HTTP API and cron blocking for selected update components.
     *
     * @since 9.1.1
     *
     * @param array $blocks {
     *     @type bool $core        Block core version checks.
     *     @type bool $plugins     Block plugin update checks.
     *     @type bool $themes      Block theme update checks.
     *     @type bool $auto_update Block the maybe-auto-update cron.
     * }
     */
    public function enable_request_and_cron_blocks( $blocks ) {
        $this->block_http_core = !empty( $blocks['core'] );
        $this->block_http_plugins = !empty( $blocks['plugins'] );
        $this->block_http_themes = !empty( $blocks['themes'] );
        $this->block_cron_core = !empty( $blocks['core'] );
        $this->block_cron_plugins = !empty( $blocks['plugins'] );
        $this->block_cron_themes = !empty( $blocks['themes'] );
        $this->block_cron_auto_update = !empty( $blocks['auto_update'] );
        if ( $this->http_cron_filters_added ) {
            return;
        }
        if ( $this->block_http_core || $this->block_http_plugins || $this->block_http_themes ) {
            add_filter(
                'pre_http_request',
                [$this, 'block_update_http_requests'],
                10,
                3
            );
        }
        if ( $this->block_cron_core || $this->block_cron_plugins || $this->block_cron_themes || $this->block_cron_auto_update ) {
            add_filter( 'schedule_event', [$this, 'filter_cron_events'] );
        }
        $this->http_cron_filters_added = true;
    }

    /**
     * Short-circuit wordpress.org update-check HTTP requests.
     *
     * @since 9.1.1
     *
     * @param false|array|\WP_Error $pre  Whether to short-circuit. Default false.
     * @param array                 $args Request arguments.
     * @param string                $url  Request URL.
     * @return false|array|\WP_Error
     */
    public function block_update_http_requests( $pre, $args, $url ) {
        unset($args);
        if ( empty( $url ) || !is_string( $url ) ) {
            return $pre;
        }
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) || false === stripos( $host, 'api.wordpress.org' ) ) {
            return $pre;
        }
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( $this->block_http_core && (false !== stripos( $path, 'core/version-check' ) || false !== stripos( $path, 'browse-happy' ) || false !== stripos( $path, 'serve-happy' )) ) {
            return $this->get_blocked_http_response();
        }
        if ( $this->block_http_plugins && false !== stripos( $path, 'plugins/update-check' ) ) {
            return $this->get_blocked_http_response();
        }
        if ( $this->block_http_themes && false !== stripos( $path, 'themes/update-check' ) ) {
            return $this->get_blocked_http_response();
        }
        return $pre;
    }

    /**
     * Prevent selected update crons from being rescheduled.
     *
     * @since 9.1.1
     *
     * @param object|false $event Cron event object.
     * @return object|false
     */
    public function filter_cron_events( $event ) {
        if ( !is_object( $event ) || empty( $event->hook ) ) {
            return $event;
        }
        switch ( $event->hook ) {
            case 'wp_version_check':
                if ( $this->block_cron_core ) {
                    return false;
                }
                break;
            case 'wp_update_plugins':
                if ( $this->block_cron_plugins ) {
                    return false;
                }
                break;
            case 'wp_update_themes':
                if ( $this->block_cron_themes ) {
                    return false;
                }
                break;
            case 'wp_maybe_auto_update':
                if ( $this->block_cron_auto_update ) {
                    return false;
                }
                break;
        }
        return $event;
    }

    /**
     * Dummy HTTP response used when an update-check request is blocked.
     *
     * @since 9.1.1
     *
     * @return array
     */
    protected function get_blocked_http_response() {
        return array(
            'headers'  => array(),
            'body'     => '',
            'response' => array(
                'code'    => 200,
                'message' => 'OK',
            ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

}
