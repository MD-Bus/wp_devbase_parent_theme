<?php

/**
 * Rollback admin page class.
 *
 * @since 8.7.3
 */
// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
/**
 * Class ASENHA_Rollback_Admin_Page
 */
class ASENHA_Rollback_Admin_Page {
    /**
     * Page slug.
     *
     * @var string
     */
    private $page_slug = 'asenha-rollback';

    /**
     * Helper instance.
     *
     * @var ASENHA_Rollback_Helper
     */
    private $helper;

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
     * Constructor.
     *
     * @param ASENHA_Rollback_Helper            $helper       Helper instance.
     * @param ASENHA_Rollback_Versions          $versions     Versions instance.
     * @param ASENHA_Rollback_Logger            $logger       Logger instance.
     * @param ASENHA_Rollback_Update_Count|null $update_count Update-count handler.
     */
    public function __construct(
        $helper,
        $versions,
        $logger,
        $update_count = null
    ) {
        $this->helper = $helper;
        $this->versions = $versions;
        $this->logger = $logger;
        $this->update_count = $update_count;
    }

    /**
     * Initialize the page hooks.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_menu', array($this, 'add_admin_menu'), 11 );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_scripts') );
    }

    /**
     * Add the Tools > Rollback submenu page.
     *
     * @return void
     */
    public function add_admin_menu() {
        $hook_suffix = add_submenu_page(
            'tools.php',
            __( 'Plugins and Themes Rollback', 'admin-site-enhancements' ),
            __( 'Rollback', 'admin-site-enhancements' ),
            'manage_options',
            $this->page_slug,
            array($this, 'render_page')
        );
        if ( false !== $hook_suffix ) {
            add_action( 'load-' . $hook_suffix, array($this, 'register_screen_options_filter') );
        }
    }

    /**
     * Register the screen options filter.
     *
     * @return void
     */
    public function register_screen_options_filter() {
        add_filter(
            'screen_options_show_screen',
            array($this, 'hide_screen_options'),
            10,
            2
        );
    }

    /**
     * Hide Screen Options on the rollback page only.
     *
     * @param bool      $show_screen Whether screen options should be shown.
     * @param WP_Screen $screen      Screen object.
     * @return bool
     */
    public function hide_screen_options( $show_screen, $screen ) {
        if ( is_object( $screen ) && isset( $screen->id ) && 'tools_page_' . $this->page_slug === $screen->id ) {
            return false;
        }
        return $show_screen;
    }

    /**
     * Enqueue rollback admin page assets.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    public function enqueue_scripts( $hook ) {
        if ( 'tools_page_' . $this->page_slug !== $hook ) {
            return;
        }
        wp_enqueue_editor();
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
        $auto_open = array(
            'enabled'       => ( isset( $_GET['open_modal'] ) ? (bool) absint( $_GET['open_modal'] ) : false ),
            'assetType'     => ( isset( $_GET['asset_type'] ) ? sanitize_key( $_GET['asset_type'] ) : '' ),
            'assetKey'      => ( isset( $_GET['asset_key'] ) ? sanitize_text_field( wp_unslash( $_GET['asset_key'] ) ) : '' ),
            'targetVersion' => ( isset( $_GET['target_version'] ) ? sanitize_text_field( wp_unslash( $_GET['target_version'] ) ) : '' ),
        );
        $script_data = array(
            'rollbackPageUrl' => admin_url( 'tools.php?page=' . $this->page_slug ),
            'autoOpen'        => $auto_open,
            'strings'         => array(
                'noVersions'     => __( 'No rollback versions are currently available for this item.', 'admin-site-enhancements' ),
                'selectVersion'  => __( 'Select version', 'admin-site-enhancements' ),
                'rollback'       => __( 'Rollback', 'admin-site-enhancements' ),
                'currentVersion' => __( 'Installed version:', 'admin-site-enhancements' ),
                'wordpressOrg'   => __( 'WordPress.org', 'admin-site-enhancements' ),
                'localArchive'   => __( 'Local archive', 'admin-site-enhancements' ),
            ),
        );
        wp_localize_script( 'asenha-rollback-admin', 'asenhaRollback', $script_data );
    }

    /**
     * Render the rollback admin page.
     *
     * @return void
     */
    public function render_page() {
        $current_tab = $this->get_current_tab();
        ?>
		<div class="wrap asenha-rollback-wrap">
			<h1><?php 
        esc_html_e( 'Plugins and Themes Rollback', 'admin-site-enhancements' );
        ?></h1>
			<?php 
        $this->render_notice();
        ?>
			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php 
        echo ( 'plugins' === $current_tab ? 'nav-tab-active' : '' );
        ?>" href="<?php 
        echo esc_url( admin_url( 'tools.php?page=' . $this->page_slug . '&tab=plugins' ) );
        ?>"><?php 
        esc_html_e( 'Plugins', 'admin-site-enhancements' );
        ?></a>
				<a class="nav-tab <?php 
        echo ( 'themes' === $current_tab ? 'nav-tab-active' : '' );
        ?>" href="<?php 
        echo esc_url( admin_url( 'tools.php?page=' . $this->page_slug . '&tab=themes' ) );
        ?>"><?php 
        esc_html_e( 'Themes', 'admin-site-enhancements' );
        ?></a>
				<a class="nav-tab <?php 
        echo ( 'logs' === $current_tab ? 'nav-tab-active' : '' );
        ?>" href="<?php 
        echo esc_url( admin_url( 'tools.php?page=' . $this->page_slug . '&tab=logs' ) );
        ?>"><?php 
        esc_html_e( 'Logs', 'admin-site-enhancements' );
        ?></a>
			</h2>
			<div class="asenha-rollback-page-content">
				<?php 
        switch ( $current_tab ) {
            case 'themes':
                $this->render_assets_table( 'theme' );
                break;
            case 'logs':
                $this->render_logs_tab();
                break;
            case 'plugins':
            default:
                $this->render_assets_table( 'plugin' );
                break;
        }
        ?>
			</div>
			<?php 
        $this->render_rollback_modal();
        $this->render_log_note_modal();
        ?>
		</div>
		<?php 
    }

    /**
     * Render the plugins or themes table.
     *
     * @param string $asset_type Asset type.
     * @return void
     */
    private function render_assets_table( $asset_type ) {
        $rows = $this->versions->get_assets_for_tab( $asset_type );
        $empty_message = ( 'theme' === $asset_type ? __( 'No installed themes were found.', 'admin-site-enhancements' ) : __( 'No installed plugins were found.', 'admin-site-enhancements' ) );
        ?>
		<table class="widefat striped asenha-rollback-assets-table">
			<thead>
				<tr>
					<th><?php 
        esc_html_e( 'Name', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Slug', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Author', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Installed Version', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Latest Version', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Source', 'admin-site-enhancements' );
        ?></th>
					<th
					<?php 
        ?>
					><?php 
        esc_html_e( 'Actions', 'admin-site-enhancements' );
        ?></th>
				</tr>
			</thead>
			<tbody>
				<?php 
        if ( empty( $rows ) ) {
            ?>
					<tr>
						<?php 
            $empty_colspan = 7;
            ?>
						<td colspan="<?php 
            echo esc_attr( $empty_colspan );
            ?>"><?php 
            echo esc_html( $empty_message );
            ?></td>
					</tr>
				<?php 
        } else {
            ?>
					<?php 
            foreach ( $rows as $row ) {
                ?>
						<tr class="asenha-rollback-row" data-asset-type="<?php 
                echo esc_attr( $row['asset_type'] );
                ?>" data-asset-key="<?php 
                echo esc_attr( $row['asset_key'] );
                ?>" data-asset-name="<?php 
                echo esc_attr( $row['name'] );
                ?>" data-current-version="<?php 
                echo esc_attr( $row['current_version'] );
                ?>">
							<td><?php 
                echo esc_html( $row['name'] );
                ?></td>
							<td><code><?php 
                echo esc_html( $row['slug'] );
                ?></code></td>
							<td><?php 
                echo esc_html( $row['author'] );
                ?></td>
							<td class="asenha-rollback-version"><?php 
                echo esc_html( $row['current_version'] );
                ?></td>
							<?php 
                $latest_is_newer = '' !== $row['latest_version'] && version_compare( (string) $row['latest_version'], (string) $row['current_version'], '>' );
                ?>
							<td class="asenha-rollback-version<?php 
                echo ( $latest_is_newer ? ' asenha-rollback-version-newer' : '' );
                ?>">
								<?php 
                if ( '' !== $row['latest_version'] ) {
                    ?>
									<?php 
                    echo esc_html( $row['latest_version'] );
                    ?>
								<?php 
                } else {
                    ?>
									<span class="asenha-rollback-latest-unavailable" aria-label="<?php 
                    esc_attr_e( 'Not available', 'admin-site-enhancements' );
                    ?>">&mdash;</span>
								<?php 
                }
                ?>
							</td>
							<td>
								<?php 
                if ( !empty( $row['is_wordpress_org'] ) ) {
                    ?>
									<span class="asenha-rollback-badge asenha-rollback-badge-repo"><?php 
                    esc_html_e( 'WordPress.org versions', 'admin-site-enhancements' );
                    ?></span>
								<?php 
                } else {
                    ?>
									<span class="asenha-rollback-badge asenha-rollback-badge-local"><?php 
                    esc_html_e( 'Local archives only', 'admin-site-enhancements' );
                    ?></span>
								<?php 
                }
                ?>
							</td>
							<td>
								<div class="asenha-rollback-inline-actions">
									<span class="asenha-rollback-versions-data" aria-hidden="true">
										<select class="asenha-rollback-select" tabindex="-1">
											<option value=""><?php 
                esc_html_e( 'Select version', 'admin-site-enhancements' );
                ?></option>
											<?php 
                foreach ( $row['available_versions'] as $version_option ) {
                    ?>
												<option value="<?php 
                    echo esc_attr( $version_option['version'] );
                    ?>" data-source="<?php 
                    echo esc_attr( $version_option['source'] );
                    ?>">
													<?php 
                    echo esc_html( $version_option['label'] );
                    ?>
												</option>
											<?php 
                }
                ?>
										</select>
									</span>
									<button
										type="button"
										class="button button-primary asenha-open-rollback-modal"
										<?php 
                if ( empty( $row['available_versions'] ) ) {
                    /* translators: Explains why the Rollback button is disabled when no rollback targets exist. */
                    $no_versions_notice = __( 'No rollback versions are currently available for this item.', 'admin-site-enhancements' );
                    ?>
										aria-label="<?php 
                    echo esc_attr( $no_versions_notice );
                    ?>"
										title="<?php 
                    echo esc_attr( $no_versions_notice );
                    ?>"
											<?php 
                }
                disabled( empty( $row['available_versions'] ) );
                ?>
									>
										<?php 
                esc_html_e( 'Rollback', 'admin-site-enhancements' );
                ?>
									</button>
								</div>
							</td>
							<?php 
                ?>
						</tr>
					<?php 
            }
            ?>
				<?php 
        }
        ?>
			</tbody>
		</table>
		<?php 
    }

    /**
     * Render the logs tab.
     *
     * @return void
     */
    private function render_logs_tab() {
        $paged = ( isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $query = $this->logger->get_logs( $paged );
        ?>
		<table class="widefat striped asenha-rollback-logs-table">
			<thead>
				<tr>
					<th><?php 
        esc_html_e( 'Asset', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Slug', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Change', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Performed By', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Note', 'admin-site-enhancements' );
        ?></th>
					<th><?php 
        esc_html_e( 'Date', 'admin-site-enhancements' );
        ?></th>
				</tr>
			</thead>
			<tbody>
				<?php 
        if ( !$query->have_posts() ) {
            ?>
					<tr>
						<td colspan="6"><?php 
            esc_html_e( 'No rollback activity has been logged yet.', 'admin-site-enhancements' );
            ?></td>
					</tr>
				<?php 
        } else {
            ?>
					<?php 
            foreach ( $query->posts as $post ) {
                ?>
						<?php 
                $log_row = $this->logger->get_log_row( $post );
                ?>
						<tr>
							<td>
								<strong><?php 
                echo esc_html( $log_row['asset_name'] );
                ?></strong><br>
								<span class="description"><?php 
                echo esc_html( ucfirst( $log_row['asset_type'] ) );
                ?></span>
							</td>
							<td><code><?php 
                echo esc_html( $log_row['asset_slug'] );
                ?></code></td>
							<td><?php 
                echo esc_html( $log_row['from_version'] . ' -> ' . $log_row['to_version'] );
                ?></td>
							<td><?php 
                echo esc_html( $log_row['user_name'] );
                ?></td>
							<td>
								<?php 
                if ( !empty( $log_row['excerpt'] ) ) {
                    ?>
									<?php 
                    echo esc_html( $log_row['excerpt'] );
                    ?>
								<?php 
                } else {
                    ?>
									<span class="description"><?php 
                    esc_html_e( 'No note added.', 'admin-site-enhancements' );
                    ?></span>
								<?php 
                }
                ?>
								<br>
								<button type="button" class="button-link asenha-open-log-note-modal" data-post-id="<?php 
                echo esc_attr( $log_row['post_id'] );
                ?>" data-title="<?php 
                echo esc_attr( $log_row['asset_name'] );
                ?>">
									<?php 
                esc_html_e( 'View or edit note', 'admin-site-enhancements' );
                ?>
								</button>
								<textarea class="asenha-log-note-value" hidden><?php 
                echo esc_textarea( $log_row['note_html'] );
                ?></textarea>
							</td>
							<td><?php 
                echo esc_html( $log_row['date'] . ' ' . $log_row['time'] );
                ?></td>
						</tr>
					<?php 
            }
            ?>
				<?php 
        }
        ?>
			</tbody>
		</table>
		<?php 
        if ( $query->max_num_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post( paginate_links( array(
                'base'      => add_query_arg( array(
                    'page'  => $this->page_slug,
                    'tab'   => 'logs',
                    'paged' => '%#%',
                ), admin_url( 'tools.php' ) ),
                'format'    => '',
                'current'   => max( 1, $paged ),
                'total'     => (int) $query->max_num_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) ) );
            echo '</div></div>';
        }
        wp_reset_postdata();
    }

    /**
     * Render the rollback action modal.
     *
     * @return void
     */
    private function render_rollback_modal() {
        $editor_settings = $this->helper->get_editor_settings();
        ?>
		<div class="asenha-modal-overlay asenha-rollback-modal-overlay is-hidden" id="asenha-rollback-modal-overlay">
			<div class="asenha-modal asenha-rollback-modal" role="dialog" aria-modal="true" aria-labelledby="asenha-rollback-modal-title">
				<div class="asenha-modal-header">
					<h2 id="asenha-rollback-modal-title"><?php 
        esc_html_e( 'Rollback', 'admin-site-enhancements' );
        ?></h2>
					<button type="button" class="button-link asenha-close-modal" aria-label="<?php 
        esc_attr_e( 'Close modal', 'admin-site-enhancements' );
        ?>">&times;</button>
				</div>
				<form method="post" action="<?php 
        echo esc_url( admin_url( 'admin-post.php' ) );
        ?>" id="asenha-rollback-form">
					<div class="asenha-modal-body">
						<p class="asenha-rollback-summary">
							<strong class="asenha-rollback-asset-name"></strong><br>
							<span class="asenha-rollback-current-version-label"></span>
						</p>
						<p>
							<label for="asenha-rollback-target-version"><strong><?php 
        esc_html_e( 'Rollback to', 'admin-site-enhancements' );
        ?></strong></label>
							<select id="asenha-rollback-target-version" name="target_version"></select>
						</p>
						<?php 
        ?>
						<div class="asenha-rollback-note-editor">
							<label for="asenha-rollback-note"><strong><?php 
        esc_html_e( 'Rollback notes', 'admin-site-enhancements' );
        ?></strong></label>
							<?php 
        wp_editor( '', 'asenha_rollback_note', array_merge( $editor_settings, array(
            'textarea_name' => 'rollback_note',
        ) ) );
        ?>
						</div>
					</div>
					<div class="asenha-modal-footer">
						<input type="hidden" name="action" value="asenha_run_rollback">
						<input type="hidden" name="asset_type" id="asenha-rollback-asset-type" value="">
						<input type="hidden" name="asset_key" id="asenha-rollback-asset-key" value="">
						<input type="hidden" name="return_tab" id="asenha-rollback-return-tab" value="<?php 
        echo esc_attr( $this->get_current_tab() );
        ?>">
						<?php 
        wp_nonce_field( 'asenha_run_rollback', 'asenha_rollback_nonce' );
        ?>
						<button type="button" class="button asenha-close-modal"><?php 
        esc_html_e( 'Cancel', 'admin-site-enhancements' );
        ?></button>
						<button type="submit" class="button button-primary asenha-submit-rollback"><?php 
        esc_html_e( 'Rollback Now', 'admin-site-enhancements' );
        ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php 
    }

    /**
     * Render the log note editing modal.
     *
     * @return void
     */
    private function render_log_note_modal() {
        $editor_settings = $this->helper->get_editor_settings();
        ?>
		<div class="asenha-modal-overlay asenha-log-note-modal-overlay is-hidden" id="asenha-log-note-modal-overlay">
			<div class="asenha-modal asenha-log-note-modal" role="dialog" aria-modal="true" aria-labelledby="asenha-log-note-modal-title">
				<div class="asenha-modal-header">
					<h2 id="asenha-log-note-modal-title"><?php 
        esc_html_e( 'Rollback Note', 'admin-site-enhancements' );
        ?></h2>
					<button type="button" class="button-link asenha-close-modal" aria-label="<?php 
        esc_attr_e( 'Close modal', 'admin-site-enhancements' );
        ?>">&times;</button>
				</div>
				<form method="post" action="<?php 
        echo esc_url( admin_url( 'admin-post.php' ) );
        ?>" id="asenha-log-note-form">
					<div class="asenha-modal-body">
						<p class="asenha-log-note-title"></p>
						<?php 
        wp_editor( '', 'asenha_rollback_log_note', array_merge( $editor_settings, array(
            'textarea_name' => 'log_note',
        ) ) );
        ?>
					</div>
					<div class="asenha-modal-footer">
						<input type="hidden" name="action" value="asenha_update_rollback_log_note">
						<input type="hidden" name="post_id" id="asenha-log-note-post-id" value="">
						<input type="hidden" name="return_tab" value="logs">
						<?php 
        wp_nonce_field( 'asenha_update_rollback_log_note', 'asenha_log_note_nonce' );
        ?>
						<button type="button" class="button asenha-close-modal"><?php 
        esc_html_e( 'Cancel', 'admin-site-enhancements' );
        ?></button>
						<button type="submit" class="button button-primary"><?php 
        esc_html_e( 'Save note', 'admin-site-enhancements' );
        ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php 
    }

    /**
     * Get the current page tab.
     *
     * @return string
     */
    private function get_current_tab() {
        $current_tab = ( isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'plugins' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( !in_array( $current_tab, array('plugins', 'themes', 'logs'), true ) ) {
            $current_tab = 'plugins';
        }
        return $current_tab;
    }

    /**
     * Render a success or error notice from redirect query args.
     *
     * @return void
     */
    private function render_notice() {
        $status = ( isset( $_GET['asenha_rollback_status'] ) ? sanitize_key( $_GET['asenha_rollback_status'] ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $message = ( isset( $_GET['asenha_rollback_message'] ) ? sanitize_text_field( wp_unslash( $_GET['asenha_rollback_message'] ) ) : '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( '' === $status || '' === $message ) {
            return;
        }
        $notice_class = ( 'success' === $status ? 'notice-success' : 'notice-error' );
        ?>
		<div class="notice <?php 
        echo esc_attr( $notice_class );
        ?> is-dismissible">
			<p><?php 
        echo esc_html( $message );
        ?></p>
		</div>
		<?php 
    }

}
