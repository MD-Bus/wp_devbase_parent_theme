<?php

/**
 * Rollback admin/actions class.
 *
 * @since 8.7.3
 */
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
/**
 * Class ASENHA_Rollback_Admin
 */
class ASENHA_Rollback_Admin {
    /**
     * Helper instance.
     *
     * @var ASENHA_Rollback_Helper
     */
    private $helper;

    /**
     * Archives instance. Null when local archives are unavailable.
     *
     * @var ASENHA_Rollback_Archives|null
     */
    private $archives;

    /**
     * Versions instance.
     *
     * @var ASENHA_Rollback_Versions
     */
    private $versions;

    /**
     * Logger instance.
     *
     * @var ASENHA_Rollback_Logger
     */
    private $logger;

    /**
     * Update-count handler. Null when premium code is unavailable.
     *
     * @var ASENHA_Rollback_Update_Count|null
     */
    private $update_count;

    /**
     * Page slug.
     *
     * @var string
     */
    private $page_slug = 'asenha-rollback';

    /**
     * Constructor.
     *
     * @param ASENHA_Rollback_Helper              $helper       Helper instance.
     * @param ASENHA_Rollback_Archives|null       $archives     Archives instance.
     * @param ASENHA_Rollback_Versions            $versions     Versions instance.
     * @param ASENHA_Rollback_Logger              $logger       Logger instance.
     * @param ASENHA_Rollback_Update_Count|null   $update_count Update-count handler.
     */
    public function __construct(
        $helper,
        $archives,
        $versions,
        $logger,
        $update_count = null
    ) {
        $this->helper = $helper;
        $this->archives = $archives;
        $this->versions = $versions;
        $this->logger = $logger;
        $this->update_count = $update_count;
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    public function init() {
        add_filter(
            'plugin_action_links',
            array($this, 'add_plugin_action_link'),
            10,
            4
        );
        add_action( 'admin_post_asenha_run_rollback', array($this, 'handle_run_rollback') );
        add_action( 'admin_post_asenha_update_rollback_log_note', array($this, 'handle_update_rollback_log_note') );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_theme_browser_assets') );
    }

    /**
     * Add a rollback action link to plugin rows.
     *
     * @param array  $actions     Existing actions.
     * @param string $plugin_file Plugin basename.
     * @param array  $plugin_data Plugin data.
     * @param string $context     List table context.
     * @return array
     */
    public function add_plugin_action_link(
        $actions,
        $plugin_file,
        $plugin_data,
        $context
    ) {
        if ( 'all' !== $context || !current_user_can( 'manage_options' ) ) {
            return $actions;
        }
        $allow_rollback = $this->versions->is_plugin_hosted_on_wordpress_org( $plugin_file, $plugin_data );
        if ( !$allow_rollback ) {
            return $actions;
        }
        $rollback_url = add_query_arg( array(
            'page'       => $this->page_slug,
            'tab'        => 'plugins',
            'asset_type' => 'plugin',
            'asset_key'  => $plugin_file,
            'open_modal' => 1,
        ), admin_url( 'tools.php' ) );
        $label = __( 'Rollback', 'admin-site-enhancements' );
        $suffix = '';
        $suffix = wp_kses( $suffix, array(
            'span' => array(
                'class' => true,
            ),
        ) );
        $actions['asenha_rollback'] = sprintf(
            '<a href="%1$s">%2$s</a>%3$s',
            esc_url( $rollback_url ),
            esc_html( $label ),
            $suffix
        );
        return $actions;
    }

    /**
     * Enqueue theme browser integration assets.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    public function enqueue_theme_browser_assets( $hook ) {
        if ( 'themes.php' !== $hook || !current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_enqueue_style(
            'asenha-rollback-admin',
            ASENHA_ROLLBACK_URL . 'assets/css/rollback-admin.css',
            array(),
            ASENHA_ROLLBACK_VERSION
        );
        wp_enqueue_script(
            'asenha-rollback-admin',
            ASENHA_ROLLBACK_URL . 'assets/js/rollback-admin.js',
            array('jquery'),
            ASENHA_ROLLBACK_VERSION,
            true
        );
        $script_data = array(
            'rollbackPageUrl'    => admin_url( 'tools.php?page=' . $this->page_slug ),
            'eligibleThemeSlugs' => $this->versions->get_eligible_theme_slugs(),
            'autoOpen'           => array(
                'enabled'       => false,
                'assetType'     => '',
                'assetKey'      => '',
                'targetVersion' => '',
            ),
            'strings'            => array(
                'rollback'       => __( 'Rollback', 'admin-site-enhancements' ),
                'noVersions'     => __( 'No rollback versions are currently available for this item.', 'admin-site-enhancements' ),
                'selectVersion'  => __( 'Select version', 'admin-site-enhancements' ),
                'currentVersion' => __( 'Installed version:', 'admin-site-enhancements' ),
                'wordpressOrg'   => __( 'WordPress.org', 'admin-site-enhancements' ),
                'localArchive'   => __( 'Local archive', 'admin-site-enhancements' ),
            ),
        );
        wp_localize_script( 'asenha-rollback-admin', 'asenhaRollback', $script_data );
    }

    /**
     * Handle rollback execution.
     *
     * @return void
     */
    public function handle_run_rollback() {
        if ( !current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'admin-site-enhancements' ) );
        }
        check_admin_referer( 'asenha_run_rollback', 'asenha_rollback_nonce' );
        $asset_type = ( isset( $_POST['asset_type'] ) ? sanitize_key( $_POST['asset_type'] ) : 'plugin' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $asset_key = ( isset( $_POST['asset_key'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_key'] ) ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $target_version = ( isset( $_POST['target_version'] ) ? sanitize_text_field( wp_unslash( $_POST['target_version'] ) ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $return_tab = ( isset( $_POST['return_tab'] ) ? sanitize_key( $_POST['return_tab'] ) : 'plugins' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $note_html = ( isset( $_POST['rollback_note'] ) ? wp_kses_post( wp_unslash( $_POST['rollback_note'] ) ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( '' === $asset_key || '' === $target_version ) {
            $this->redirect_with_notice( 'error', __( 'Please choose a rollback version first.', 'admin-site-enhancements' ), $return_tab );
        }
        $asset_context = $this->versions->get_asset_context( $asset_type, $asset_key );
        if ( empty( $asset_context ) ) {
            $this->redirect_with_notice( 'error', __( 'The selected plugin or theme could not be found.', 'admin-site-enhancements' ), $return_tab );
        }
        $allow_rollback = !empty( $asset_context['is_wordpress_org'] );
        if ( !$allow_rollback ) {
            $this->redirect_with_notice( 'error', __( 'The selected rollback version is no longer available.', 'admin-site-enhancements' ), $return_tab );
        }
        $result = $this->execute_rollback( $asset_context, $target_version, $note_html );
        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice(
                'error',
                $result->get_error_message(),
                $return_tab,
                $asset_type,
                $asset_key
            );
        }
        $message = sprintf( 
            /* translators: 1: asset name, 2: version number */
            __( '%1$s was rolled back to version %2$s.', 'admin-site-enhancements' ),
            $asset_context['name'],
            $target_version
         );
        $this->redirect_with_notice( 'success', $message, $return_tab );
    }

    /**
     * Handle rollback log note edits.
     *
     * @return void
     */
    public function handle_update_rollback_log_note() {
        if ( !current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'admin-site-enhancements' ) );
        }
        check_admin_referer( 'asenha_update_rollback_log_note', 'asenha_log_note_nonce' );
        $post_id = ( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $note_html = ( isset( $_POST['log_note'] ) ? wp_kses_post( wp_unslash( $_POST['log_note'] ) ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $post_id > 0 ) {
            $this->logger->update_log_note( $post_id, $note_html );
        }
        $this->redirect_with_notice( 'success', __( 'The rollback note was updated.', 'admin-site-enhancements' ), 'logs' );
    }

    /**
     * Execute a rollback for the selected asset and version.
     *
     * @param array  $asset_context Asset context.
     * @param string $target_version Target version.
     * @param string $note_html      Rollback note HTML.
     * @return true|WP_Error
     */
    private function execute_rollback( $asset_context, $target_version, $note_html ) {
        $source = $this->versions->resolve_rollback_source( $asset_context['asset_type'], $asset_context['asset_key'], $target_version );
        if ( is_wp_error( $source ) ) {
            return $source;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skins.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $skin = new Automatic_Upgrader_Skin();
        if ( 'plugin' === $asset_context['asset_type'] ) {
            require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            $is_active = is_plugin_active( $asset_context['asset_key'] );
            $is_network_active = is_plugin_active_for_network( $asset_context['asset_key'] );
            if ( $is_active || $is_network_active ) {
                deactivate_plugins( $asset_context['asset_key'], true, $is_network_active );
            }
            $upgrader = new Plugin_Upgrader($skin);
            $result = $upgrader->install( $source['package'], array(
                'overwrite_package'  => true,
                'clear_update_cache' => true,
            ) );
            if ( true === $result && ($is_active || $is_network_active) ) {
                $activation_result = activate_plugin(
                    $asset_context['asset_key'],
                    '',
                    $is_network_active,
                    true
                );
                if ( is_wp_error( $activation_result ) ) {
                    return $activation_result;
                }
            }
        } else {
            require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
            $active_stylesheet = get_stylesheet();
            $active_template = get_template();
            $is_active_theme = $active_stylesheet === $asset_context['asset_key'] || $active_template === $asset_context['asset_key'];
            $upgrader = new Theme_Upgrader($skin);
            $result = $upgrader->install( $source['package'], array(
                'overwrite_package'  => true,
                'clear_update_cache' => true,
            ) );
            if ( true === $result && $is_active_theme ) {
                switch_theme( $active_stylesheet );
            }
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( false === $result ) {
            if ( is_wp_error( $skin->result ) ) {
                return $skin->result;
            }
            return new WP_Error('asenha_rollback_failed', __( 'The rollback could not be completed.', 'admin-site-enhancements' ));
        }
        $updated_context = $this->versions->get_asset_context( $asset_context['asset_type'], $asset_context['asset_key'] );
        $this->logger->log_rollback( array(
            'asset_type'   => $asset_context['asset_type'],
            'asset_name'   => $asset_context['name'],
            'asset_slug'   => $asset_context['slug'],
            'from_version' => $asset_context['current_version'],
            'to_version'   => ( !empty( $updated_context['current_version'] ) ? $updated_context['current_version'] : $target_version ),
            'note_html'    => $note_html,
            'source'       => $source['source'],
            'user_id'      => get_current_user_id(),
        ) );
        return true;
    }

    /**
     * Resolve the current asset context from an upgrader hook payload.
     *
     * @param string $source     Unpacked source path.
     * @param array  $hook_extra Upgrader hook payload.
     * @return array
     */
    private function resolve_asset_context_for_upgrader( $source, $hook_extra ) {
        $asset_type = ( isset( $hook_extra['type'] ) ? sanitize_key( $hook_extra['type'] ) : '' );
        if ( '' === $asset_type ) {
            if ( !empty( $hook_extra['plugin'] ) ) {
                $asset_type = 'plugin';
            } elseif ( !empty( $hook_extra['theme'] ) ) {
                $asset_type = 'theme';
            }
        }
        if ( !in_array( $asset_type, array('plugin', 'theme'), true ) ) {
            return array();
        }
        if ( 'plugin' === $asset_type && !empty( $hook_extra['plugin'] ) ) {
            return $this->versions->get_asset_context( 'plugin', $hook_extra['plugin'] );
        }
        if ( 'theme' === $asset_type && !empty( $hook_extra['theme'] ) ) {
            return $this->versions->get_asset_context( 'theme', $hook_extra['theme'] );
        }
        return $this->versions->infer_existing_asset_from_source( $asset_type, $source );
    }

    /**
     * Redirect back to the rollback page with a notice.
     *
     * @param string $status     Notice status.
     * @param string $message    Notice message.
     * @param string $tab        Target tab.
     * @param string $asset_type Optional asset type.
     * @param string $asset_key  Optional asset key.
     * @return void
     */
    private function redirect_with_notice(
        $status,
        $message,
        $tab,
        $asset_type = '',
        $asset_key = ''
    ) {
        $query_args = array(
            'page'                    => $this->page_slug,
            'tab'                     => $tab,
            'asenha_rollback_status'  => $status,
            'asenha_rollback_message' => $message,
        );
        if ( '' !== $asset_type && '' !== $asset_key ) {
            $query_args['asset_type'] = $asset_type;
            $query_args['asset_key'] = $asset_key;
            $query_args['open_modal'] = 1;
        }
        wp_safe_redirect( add_query_arg( $query_args, admin_url( 'tools.php' ) ) );
        exit;
    }

}
