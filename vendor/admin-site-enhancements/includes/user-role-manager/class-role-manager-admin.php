<?php

/**
 * User Role Manager: admin page.
 *
 * Registers the Users > User Role Manager page, renders its shell (heading, optional
 * tab bar and app container) and enqueues assets. The interface itself is rendered
 * by assets/js/user-role-manager.js.
 *
 * Extension points used by the Pro version:
 * - filter `asenha_role_manager_tabs`            Add tabs. The free version has a single tab, so no tab bar is shown.
 * - filter `asenha_role_manager_heading_actions` Add buttons next to the page heading.
 * - action `asenha_role_manager_render_tab_{tab}` Render the content of an extra tab.
 * - action `asenha_role_manager_enqueue_assets`   Enqueue extra assets.
 *
 * @package ASENHA\UserRoleManager
 * @since   9.2.0
 */
namespace ASENHA\UserRoleManager;

// If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
/**
 * Admin page.
 */
class Role_Manager_Admin {
    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'asenha-user-role-manager';

    /**
     * Hook suffix of the page.
     *
     * @var string
     */
    private $hook_suffix = '';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_menu', array($this, 'register_menu') );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_assets') );
    }

    /**
     * Register the page under the Users menu.
     *
     * @return void
     */
    public function register_menu() {
        $this->hook_suffix = (string) add_users_page(
            __( 'User Role Manager', 'admin-site-enhancements' ),
            __( 'Roles', 'admin-site-enhancements' ),
            Role_Manager_Guard::get_page_capability(),
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    /**
     * Get the URL of the page.
     *
     * @param array $args Optional query arguments.
     * @return string
     */
    public static function get_page_url( array $args = array() ) {
        return add_query_arg( array_merge( array(
            'page' => self::PAGE_SLUG,
        ), $args ), admin_url( 'users.php' ) );
    }

    /**
     * Get the available tabs.
     *
     * @return array Tab slug => label.
     */
    private function get_tabs() {
        $tabs = array(
            'roles' => __( 'Roles', 'admin-site-enhancements' ),
        );
        /**
         * Filters the tabs of the User Role Manager page.
         *
         * @since 9.2.0
         *
         * @param array $tabs Tab slug => label.
         */
        $tabs = apply_filters( 'asenha_role_manager_tabs', $tabs );
        return ( is_array( $tabs ) && !empty( $tabs ) ? $tabs : array(
            'roles' => __( 'Roles', 'admin-site-enhancements' ),
        ) );
    }

    /**
     * Get the active tab slug.
     *
     * @return string
     */
    private function get_active_tab() {
        $tabs = $this->get_tabs();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a tab slug to display a page, not processing a form.
        $tab = ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'roles' );
        return ( isset( $tabs[$tab] ) ? $tab : 'roles' );
    }

    /**
     * Render the page.
     *
     * @return void
     */
    public function render_page() {
        if ( !current_user_can( Role_Manager_Guard::get_page_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'admin-site-enhancements' ) );
        }
        $tabs = $this->get_tabs();
        $active_tab = $this->get_active_tab();
        /**
         * Filters the buttons shown next to the page heading.
         *
         * @since 9.2.0
         *
         * @param array $actions List of arrays with `label` and `url`.
         */
        $heading_actions = apply_filters( 'asenha_role_manager_heading_actions', array() );
        ?>
		<div class="wrap asenha-urm">
			<h1 class="wp-heading-inline"><?php 
        esc_html_e( 'User Role Manager', 'admin-site-enhancements' );
        ?></h1>
			<?php 
        foreach ( (array) $heading_actions as $action ) {
            ?>
				<?php 
            if ( empty( $action['url'] ) || empty( $action['label'] ) ) {
                continue;
            }
            ?>
				<a href="<?php 
            echo esc_url( $action['url'] );
            ?>" class="page-title-action"><?php 
            echo esc_html( $action['label'] );
            ?></a>
			<?php 
        }
        ?>
			<hr class="wp-header-end">

			<?php 
        if ( count( $tabs ) > 1 ) {
            ?>
				<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php 
            esc_attr_e( 'User Role Manager sections', 'admin-site-enhancements' );
            ?>">
					<?php 
            foreach ( $tabs as $slug => $label ) {
                ?>
						<a href="<?php 
                echo esc_url( self::get_page_url( ( 'roles' === $slug ? array() : array(
                    'tab' => $slug,
                ) ) ) );
                ?>"
							class="nav-tab<?php 
                echo ( $slug === $active_tab ? ' nav-tab-active' : '' );
                ?>"
							<?php 
                echo ( $slug === $active_tab ? 'aria-current="page"' : '' );
                ?>><?php 
                echo esc_html( $label );
                ?></a>
					<?php 
            }
            ?>
				</nav>
			<?php 
        }
        ?>

			<div id="asenha-urm-notices" class="urm-notices" role="region" aria-label="<?php 
        esc_attr_e( 'Notifications', 'admin-site-enhancements' );
        ?>"></div>

			<div id="asenha-urm-app" class="urm-app" data-tab="<?php 
        echo esc_attr( $active_tab );
        ?>">
				<?php 
        if ( 'roles' === $active_tab ) {
            ?>
					<p class="urm-loading"><span class="spinner is-active"></span> <?php 
            esc_html_e( 'Loading roles…', 'admin-site-enhancements' );
            ?></p>
				<?php 
        } else {
            ?>
					<?php 
            /**
             * Fires to render the content of an extra tab.
             *
             * The dynamic portion of the hook name, `$active_tab`, is the tab slug.
             *
             * @since 9.2.0
             */
            do_action( 'asenha_role_manager_render_tab_' . $active_tab );
            ?>
				<?php 
        }
        ?>
			</div>
			<noscript>
				<p><?php 
        esc_html_e( 'The User Role Manager needs JavaScript to be enabled.', 'admin-site-enhancements' );
        ?></p>
			</noscript>
		</div>
		<?php 
    }

    /**
     * Enqueue assets on the page.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     * @return void
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
            return;
        }
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'asenha-user-role-manager',
            ASENHA_USER_ROLE_MANAGER_URL . 'assets/css/user-role-manager.css',
            array('dashicons'),
            ASENHA_VERSION
        );
        wp_enqueue_script(
            'asenha-user-role-manager',
            ASENHA_USER_ROLE_MANAGER_URL . 'assets/js/user-role-manager.js',
            array('wp-api-fetch', 'wp-a11y'),
            ASENHA_VERSION,
            true
        );
        $is_pro = false;
        wp_localize_script( 'asenha-user-role-manager', 'asenhaUserRoleManager', array(
            'tab'   => $this->get_active_tab(),
            'flags' => array(
                'isPro'        => $is_pro,
                'isMultisite'  => is_multisite(),
                'canSuperUser' => is_super_admin(),
            ),
            'urls'  => array(
                'pageUrl' => self::get_page_url(),
                'users'   => admin_url( 'users.php' ),
            ),
            'state' => Role_Manager_Role_Service::get_state(),
            'i18n'  => self::get_i18n_strings(),
        ) );
        /**
         * Fires when the assets of the User Role Manager page are enqueued.
         *
         * @since 9.2.0
         *
         * @param string $tab Active tab slug.
         */
        do_action( 'asenha_role_manager_enqueue_assets', $this->get_active_tab() );
    }

    /**
     * Get the translatable strings used by the interface.
     *
     * @return array Key => string.
     */
    public static function get_i18n_strings() {
        $strings = array(
            'genericError'                => __( 'Something went wrong. Please try again.', 'admin-site-enhancements' ),
            'dismiss'                     => __( 'Dismiss this notice', 'admin-site-enhancements' ),
            'close'                       => __( 'Close', 'admin-site-enhancements' ),
            'cancel'                      => __( 'Cancel', 'admin-site-enhancements' ),
            'confirm'                     => __( 'Confirm', 'admin-site-enhancements' ),
            'noRoles'                     => __( 'No roles found.', 'admin-site-enhancements' ),
            'rolesLabel'                  => __( 'Roles', 'admin-site-enhancements' ),
            'usersCount'                  => __( '%d users', 'admin-site-enhancements' ),
            'capsCount'                   => __( '%d capabilities', 'admin-site-enhancements' ),
            'viewUsers'                   => __( 'View users (%d)', 'admin-site-enhancements' ),
            'badgeCore'                   => __( 'Core', 'admin-site-enhancements' ),
            'badgeCustom'                 => __( 'Custom', 'admin-site-enhancements' ),
            'badgeDefault'                => __( 'Default for new users', 'admin-site-enhancements' ),
            'badgeModified'               => __( 'Modified', 'admin-site-enhancements' ),
            'badgeProtected'              => __( 'Protected', 'admin-site-enhancements' ),
            'badgeSensitive'              => __( 'Sensitive', 'admin-site-enhancements' ),
            'badgeDenied'                 => __( 'Denied', 'admin-site-enhancements' ),
            'badgeDeniedShort'            => __( 'Denied', 'admin-site-enhancements' ),
            'badgeUnrecognized'           => __( 'Unrecognized', 'admin-site-enhancements' ),
            'roleActions'                 => __( 'Role actions', 'admin-site-enhancements' ),
            'actionClone'                 => __( 'Clone', 'admin-site-enhancements' ),
            'actionSetDefault'            => __( 'Set as default role', 'admin-site-enhancements' ),
            'actionReset'                 => __( 'Reset to WordPress defaults', 'admin-site-enhancements' ),
            'actionDelete'                => __( 'Delete role', 'admin-site-enhancements' ),
            'roleDetails'                 => __( 'Role name and description', 'admin-site-enhancements' ),
            'roleName'                    => __( 'Role name', 'admin-site-enhancements' ),
            'roleSlug'                    => __( 'Role slug', 'admin-site-enhancements' ),
            'roleSlugHelp'                => __( 'Lowercase letters, numbers, hyphens and underscores. It cannot be changed later.', 'admin-site-enhancements' ),
            'roleSlugFixed'               => __( 'Role slug (cannot be changed):', 'admin-site-enhancements' ),
            'roleDescription'             => __( 'Description', 'admin-site-enhancements' ),
            'roleDescriptionHelp'         => __( 'Optional note that only shows here, e.g. what the role is for.', 'admin-site-enhancements' ),
            'capabilitiesHeading'         => __( 'Capabilities', 'admin-site-enhancements' ),
            'viewLevel'                   => __( 'View level', 'admin-site-enhancements' ),
            'viewEssentials'              => __( 'Essentials', 'admin-site-enhancements' ),
            'viewEssentialsHelp'          => __( 'The most common permissions as simple switches', 'admin-site-enhancements' ),
            'viewAreas'                   => __( 'By area', 'admin-site-enhancements' ),
            'viewAreasHelp'               => __( 'Permissions grouped by content type and area', 'admin-site-enhancements' ),
            'searchLabel'                 => __( 'Search capabilities', 'admin-site-enhancements' ),
            'searchPlaceholder'           => __( 'Search capabilities…', 'admin-site-enhancements' ),
            'essentialsHint'              => __( 'Need more control? Switch to "By area" above.', 'admin-site-enhancements' ),
            'matrixHint'                  => __( 'Tick a row or a column header to grant a whole set at once.', 'admin-site-enhancements' ),
            'columnPostType'              => __( 'Content type', 'admin-site-enhancements' ),
            'columnTaxonomy'              => __( 'Taxonomy', 'admin-site-enhancements' ),
            'sharesCaps'                  => __( 'Uses the same capabilities as %s', 'admin-site-enhancements' ),
            'notApplicable'               => __( 'Not applicable', 'admin-site-enhancements' ),
            'groupContent'                => __( 'Content', 'admin-site-enhancements' ),
            'groupTaxonomies'             => __( 'Taxonomies', 'admin-site-enhancements' ),
            'groupCustom'                 => __( 'Custom capabilities', 'admin-site-enhancements' ),
            'groupUnrecognized'           => __( 'Other capabilities', 'admin-site-enhancements' ),
            'thirdPartyHint'              => __( 'Capabilities added by a plugin, grouped by the plugin they most likely belong to.', 'admin-site-enhancements' ),
            'customHint'                  => __( 'Capabilities you added with this tool.', 'admin-site-enhancements' ),
            'unrecognizedHint'            => __( 'Found on roles, but not defined by WordPress core, a content type or an active plugin. They may be left behind by a plugin that was removed.', 'admin-site-enhancements' ),
            'showLegacy'                  => __( 'Show legacy user levels', 'admin-site-enhancements' ),
            'toggleGroup'                 => __( 'Grant or clear all: %s', 'admin-site-enhancements' ),
            'expandAll'                   => __( 'Expand all', 'admin-site-enhancements' ),
            'collapseAll'                 => __( 'Collapse all', 'admin-site-enhancements' ),
            'sectionTools'                => __( 'Capability sections', 'admin-site-enhancements' ),
            'expandSection'               => __( 'Expand %s', 'admin-site-enhancements' ),
            'collapseSection'             => __( 'Collapse %s', 'admin-site-enhancements' ),
            'sectionCount'                => __( '%1$d / %2$d', 'admin-site-enhancements' ),
            'sectionCountPartial'         => __( '~%1$d / %2$d', 'admin-site-enhancements' ),
            'expandSectionCount'          => __( 'Expand %1$s, %2$d / %3$d granted', 'admin-site-enhancements' ),
            'collapseSectionCount'        => __( 'Collapse %1$s, %2$d / %3$d granted', 'admin-site-enhancements' ),
            'expandSectionCountPartial'   => __( 'Expand %1$s, about %2$d / %3$d granted', 'admin-site-enhancements' ),
            'collapseSectionCountPartial' => __( 'Collapse %1$s, about %2$d / %3$d granted', 'admin-site-enhancements' ),
            'toggleColumn'                => __( 'Grant or clear for all content types: %s', 'admin-site-enhancements' ),
            'toggleRow'                   => __( 'Grant or clear all for %s', 'admin-site-enhancements' ),
            'lockedCap'                   => __( 'The Administrator role must keep this capability.', 'admin-site-enhancements' ),
            'pendingCount'                => __( '%d unsaved changes', 'admin-site-enhancements' ),
            'noPending'                   => __( 'No unsaved changes', 'admin-site-enhancements' ),
            'reviewChanges'               => __( 'Review changes', 'admin-site-enhancements' ),
            'reviewTitle'                 => __( 'Review changes to %s', 'admin-site-enhancements' ),
            'reviewDetails'               => __( 'Role details', 'admin-site-enhancements' ),
            'reviewName'                  => __( 'Name: "%1$s" becomes "%2$s"', 'admin-site-enhancements' ),
            'reviewDescription'           => __( 'The description will be updated.', 'admin-site-enhancements' ),
            'reviewGranted'               => __( 'Will be granted', 'admin-site-enhancements' ),
            'reviewDenied'                => __( 'Will be denied', 'admin-site-enhancements' ),
            'reviewRemoved'               => __( 'Will be cleared (neither granted nor denied)', 'admin-site-enhancements' ),
            'saveChanges'                 => __( 'Save changes', 'admin-site-enhancements' ),
            'saving'                      => __( 'Saving…', 'admin-site-enhancements' ),
            'saved'                       => __( 'Changes saved.', 'admin-site-enhancements' ),
            'discard'                     => __( 'Discard', 'admin-site-enhancements' ),
            'discardTitle'                => __( 'Discard unsaved changes?', 'admin-site-enhancements' ),
            'discardMessage'              => __( 'Your unsaved changes to this role will be lost.', 'admin-site-enhancements' ),
            'changesDiscarded'            => __( 'Unsaved changes were discarded.', 'admin-site-enhancements' ),
            'sensitiveTitle'              => __( 'Grant sensitive capabilities?', 'admin-site-enhancements' ),
            'sensitiveMessage'            => __( 'These capabilities can change or expose important parts of your site. Are you sure you want to grant them to the %s role?', 'admin-site-enhancements' ),
            'grantAnyway'                 => __( 'Grant', 'admin-site-enhancements' ),
            'andMore'                     => __( '…and %d more', 'admin-site-enhancements' ),
            'addRole'                     => __( 'Add role', 'admin-site-enhancements' ),
            'addRoleTitle'                => __( 'Add a new role', 'admin-site-enhancements' ),
            'cloneTitle'                  => __( 'Clone the %s role', 'admin-site-enhancements' ),
            'cloneName'                   => __( '%s (copy)', 'admin-site-enhancements' ),
            'startFrom'                   => __( 'Start from', 'admin-site-enhancements' ),
            'startFromHelp'               => __( 'You can change any capability afterwards.', 'admin-site-enhancements' ),
            'startEmpty'                  => __( 'Nothing (no capabilities)', 'admin-site-enhancements' ),
            'startPreset'                 => __( 'Preset: %s', 'admin-site-enhancements' ),
            'startClone'                  => __( 'Copy of the %s role', 'admin-site-enhancements' ),
            'roleCreated'                 => __( 'The %s role was created.', 'admin-site-enhancements' ),
            'preset_subscriber'           => __( 'Subscriber-like', 'admin-site-enhancements' ),
            'preset_contributor'          => __( 'Contributor-like', 'admin-site-enhancements' ),
            'preset_author'               => __( 'Author-like', 'admin-site-enhancements' ),
            'preset_editor'               => __( 'Editor-like', 'admin-site-enhancements' ),
            'deleteTitle'                 => __( 'Delete the %s role?', 'admin-site-enhancements' ),
            'deleteMessage'               => __( 'The %1$s role will be deleted. It has %2$d users, who will be moved to the role you choose below.', 'admin-site-enhancements' ),
            'deleteReassign'              => __( 'Move users to', 'admin-site-enhancements' ),
            'deleteReassignHelp'          => __( 'Required. Users who have other roles as well keep those roles.', 'admin-site-enhancements' ),
            'deleteDone'                  => __( 'The %s role was deleted.', 'admin-site-enhancements' ),
            'resetTitle'                  => __( 'Reset the %s role?', 'admin-site-enhancements' ),
            'resetMessage'                => __( 'The role goes back to the capabilities WordPress gives it by default. Capabilities added by plugins and any denied capabilities are removed from it. This is saved immediately.', 'admin-site-enhancements' ),
            'resetDone'                   => __( 'The %s role was reset to WordPress defaults.', 'admin-site-enhancements' ),
            'defaultSet'                  => __( '%s is now the default role for new users.', 'admin-site-enhancements' ),
        );
        /**
         * Filters the strings passed to the User Role Manager script.
         *
         * @since 9.2.0
         *
         * @param array $strings Key => translated string.
         */
        return apply_filters( 'asenha_role_manager_i18n', $strings );
    }

}
