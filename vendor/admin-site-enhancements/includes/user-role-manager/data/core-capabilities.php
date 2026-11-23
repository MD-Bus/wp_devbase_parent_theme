<?php
/**
 * User Role Manager: WordPress core capability definitions.
 *
 * Returns an array describing the non-post-type / non-taxonomy core capabilities
 * grouped logically, the curated "Essentials" switches, the sensitive capability
 * list, and the post type / taxonomy matrix columns. Labels are translatable, so
 * this file must only be included after the `init` action has fired.
 *
 * @package ASENHA\UserRoleManager
 * @since   9.2.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

return array(

	// Capability groups (excluding post types and taxonomies, which are derived from registered objects).
	'groups' => array(
		'media'      => array(
			'label'       => __( 'Media', 'admin-site-enhancements' ),
			'description' => __( 'Uploading files to the Media Library.', 'admin-site-enhancements' ),
			'caps'        => array(
				'upload_files'      => __( 'Upload files', 'admin-site-enhancements' ),
				'unfiltered_upload' => __( 'Upload files of any type (unfiltered)', 'admin-site-enhancements' ),
			),
		),
		'comments'   => array(
			'label'       => __( 'Comments', 'admin-site-enhancements' ),
			'description' => __( 'Moderating and editing comments.', 'admin-site-enhancements' ),
			'caps'        => array(
				'moderate_comments' => __( 'Moderate comments', 'admin-site-enhancements' ),
			),
		),
		'users'      => array(
			'label'       => __( 'Users', 'admin-site-enhancements' ),
			'description' => __( 'Viewing, creating, editing and deleting user accounts, and changing their roles.', 'admin-site-enhancements' ),
			'caps'        => array(
				'list_users'    => __( 'View the users list', 'admin-site-enhancements' ),
				'create_users'  => __( 'Add new users', 'admin-site-enhancements' ),
				'edit_users'    => __( 'Edit users', 'admin-site-enhancements' ),
				'delete_users'  => __( 'Delete users', 'admin-site-enhancements' ),
				'promote_users' => __( 'Change the role of users', 'admin-site-enhancements' ),
				'remove_users'  => __( 'Remove users', 'admin-site-enhancements' ),
				'add_users'     => __( 'Add existing users (multisite)', 'admin-site-enhancements' ),
			),
		),
		'appearance' => array(
			'label'       => __( 'Appearance', 'admin-site-enhancements' ),
			'description' => __( 'Themes, menus, widgets and the Customizer.', 'admin-site-enhancements' ),
			'caps'        => array(
				'switch_themes'      => __( 'Switch themes', 'admin-site-enhancements' ),
				'edit_theme_options' => __( 'Customize the site, menus and widgets', 'admin-site-enhancements' ),
				'install_themes'     => __( 'Install themes', 'admin-site-enhancements' ),
				'update_themes'      => __( 'Update themes', 'admin-site-enhancements' ),
				'delete_themes'      => __( 'Delete themes', 'admin-site-enhancements' ),
				'edit_themes'        => __( 'Edit theme files', 'admin-site-enhancements' ),
				'resume_themes'      => __( 'Resume paused themes', 'admin-site-enhancements' ),
			),
		),
		'plugins'    => array(
			'label'       => __( 'Plugins', 'admin-site-enhancements' ),
			'description' => __( 'Activating, installing, updating and editing plugins.', 'admin-site-enhancements' ),
			'caps'        => array(
				'activate_plugins' => __( 'Activate and deactivate plugins', 'admin-site-enhancements' ),
				'install_plugins'  => __( 'Install plugins', 'admin-site-enhancements' ),
				'update_plugins'   => __( 'Update plugins', 'admin-site-enhancements' ),
				'delete_plugins'   => __( 'Delete plugins', 'admin-site-enhancements' ),
				'edit_plugins'     => __( 'Edit plugin files', 'admin-site-enhancements' ),
				'resume_plugins'   => __( 'Resume paused plugins', 'admin-site-enhancements' ),
			),
		),
		'site'       => array(
			'label'       => __( 'Site', 'admin-site-enhancements' ),
			'description' => __( 'General access, settings, updates, import / export and other site-wide tools.', 'admin-site-enhancements' ),
			'caps'        => array(
				'read'                   => __( 'Access the dashboard and own profile', 'admin-site-enhancements' ),
				'edit_dashboard'         => __( 'Customize the dashboard', 'admin-site-enhancements' ),
				'manage_options'         => __( 'Manage site settings', 'admin-site-enhancements' ),
				'manage_privacy_options' => __( 'Manage privacy tools', 'admin-site-enhancements' ),
				'update_core'            => __( 'Update WordPress core', 'admin-site-enhancements' ),
				'import'                 => __( 'Import content', 'admin-site-enhancements' ),
				'export'                 => __( 'Export content', 'admin-site-enhancements' ),
				'unfiltered_html'        => __( 'Post unfiltered HTML', 'admin-site-enhancements' ),
				'manage_links'           => __( 'Manage links', 'admin-site-enhancements' ),
				'edit_files'             => __( 'Edit files (legacy)', 'admin-site-enhancements' ),
			),
		),
		'network'    => array(
			'label'       => __( 'Network (multisite)', 'admin-site-enhancements' ),
			'description' => __( 'Network administration. These are normally granted only to super admins.', 'admin-site-enhancements' ),
			'multisite'   => true,
			'caps'        => array(
				'manage_network'         => __( 'Access the network admin', 'admin-site-enhancements' ),
				'manage_sites'           => __( 'Manage sites', 'admin-site-enhancements' ),
				'create_sites'           => __( 'Create sites', 'admin-site-enhancements' ),
				'delete_sites'           => __( 'Delete sites', 'admin-site-enhancements' ),
				'manage_network_users'   => __( 'Manage network users', 'admin-site-enhancements' ),
				'manage_network_plugins' => __( 'Manage network plugins', 'admin-site-enhancements' ),
				'manage_network_themes'  => __( 'Manage network themes', 'admin-site-enhancements' ),
				'manage_network_options' => __( 'Manage network settings', 'admin-site-enhancements' ),
				'upgrade_network'        => __( 'Upgrade the network', 'admin-site-enhancements' ),
			),
		),
	),

	// Curated "Essentials" switches. Each switch maps to one or more capabilities.
	'essentials' => array(
		array(
			'id'    => 'content',
			'label' => __( 'Content', 'admin-site-enhancements' ),
			'items' => array(
				array(
					'id'    => 'ess_edit_posts',
					'label' => __( 'Write and edit own posts', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_posts' ),
				),
				array(
					'id'    => 'ess_publish_posts',
					'label' => __( 'Publish posts', 'admin-site-enhancements' ),
					'caps'  => array( 'publish_posts', 'edit_published_posts' ),
				),
				array(
					'id'    => 'ess_edit_others_posts',
					'label' => __( 'Edit posts by other users', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_others_posts' ),
				),
				array(
					'id'    => 'ess_delete_posts',
					'label' => __( 'Delete own posts', 'admin-site-enhancements' ),
					'caps'  => array( 'delete_posts', 'delete_published_posts' ),
				),
				array(
					'id'    => 'ess_delete_others_posts',
					'label' => __( 'Delete posts by other users', 'admin-site-enhancements' ),
					'caps'  => array( 'delete_others_posts' ),
				),
				array(
					'id'    => 'ess_private_posts',
					'label' => __( 'Read and edit private posts', 'admin-site-enhancements' ),
					'caps'  => array( 'read_private_posts', 'edit_private_posts', 'delete_private_posts' ),
				),
				array(
					'id'    => 'ess_edit_pages',
					'label' => __( 'Edit pages', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_pages', 'edit_published_pages' ),
				),
				array(
					'id'    => 'ess_publish_pages',
					'label' => __( 'Publish pages', 'admin-site-enhancements' ),
					'caps'  => array( 'publish_pages' ),
				),
				array(
					'id'    => 'ess_edit_others_pages',
					'label' => __( 'Edit pages by other users', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_others_pages' ),
				),
				array(
					'id'    => 'ess_delete_pages',
					'label' => __( 'Delete pages', 'admin-site-enhancements' ),
					'caps'  => array( 'delete_pages', 'delete_published_pages', 'delete_others_pages' ),
				),
				array(
					'id'    => 'ess_private_pages',
					'label' => __( 'Read and edit private pages', 'admin-site-enhancements' ),
					'caps'  => array( 'read_private_pages', 'edit_private_pages', 'delete_private_pages' ),
				),
				array(
					'id'    => 'ess_manage_categories',
					'label' => __( 'Manage categories and tags', 'admin-site-enhancements' ),
					'caps'  => array( 'manage_categories' ),
				),
			),
		),
		array(
			'id'    => 'media',
			'label' => __( 'Media', 'admin-site-enhancements' ),
			'items' => array(
				array(
					'id'    => 'ess_upload_files',
					'label' => __( 'Upload media files', 'admin-site-enhancements' ),
					'caps'  => array( 'upload_files' ),
				),
			),
		),
		array(
			'id'    => 'comments',
			'label' => __( 'Comments', 'admin-site-enhancements' ),
			'items' => array(
				array(
					'id'    => 'ess_moderate_comments',
					'label' => __( 'Moderate comments', 'admin-site-enhancements' ),
					'caps'  => array( 'moderate_comments' ),
				),
			),
		),
		array(
			'id'    => 'users',
			'label' => __( 'Users', 'admin-site-enhancements' ),
			'items' => array(
				array(
					'id'    => 'ess_list_users',
					'label' => __( 'View the users list', 'admin-site-enhancements' ),
					'caps'  => array( 'list_users' ),
				),
				array(
					'id'    => 'ess_create_users',
					'label' => __( 'Add new users', 'admin-site-enhancements' ),
					'caps'  => array( 'create_users' ),
				),
				array(
					'id'    => 'ess_edit_users',
					'label' => __( 'Edit users', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_users' ),
				),
				array(
					'id'    => 'ess_delete_users',
					'label' => __( 'Delete users', 'admin-site-enhancements' ),
					'caps'  => array( 'delete_users' ),
				),
				array(
					'id'    => 'ess_promote_users',
					'label' => __( 'Change the role of users', 'admin-site-enhancements' ),
					'caps'  => array( 'promote_users' ),
				),
			),
		),
		array(
			'id'    => 'appearance',
			'label' => __( 'Appearance', 'admin-site-enhancements' ),
			'items' => array(
				array(
					'id'    => 'ess_switch_themes',
					'label' => __( 'Switch themes', 'admin-site-enhancements' ),
					'caps'  => array( 'switch_themes' ),
				),
				array(
					'id'    => 'ess_theme_options',
					'label' => __( 'Customize the site, menus and widgets', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_theme_options' ),
				),
				array(
					'id'    => 'ess_manage_themes',
					'label' => __( 'Install, update and delete themes', 'admin-site-enhancements' ),
					'caps'  => array( 'install_themes', 'update_themes', 'delete_themes' ),
				),
				array(
					'id'    => 'ess_edit_themes',
					'label' => __( 'Edit theme files', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_themes' ),
				),
			),
		),
		array(
			'id'    => 'plugins',
			'label' => __( 'Plugins', 'admin-site-enhancements' ),
			'items' => array(
				array(
					'id'    => 'ess_activate_plugins',
					'label' => __( 'Activate and deactivate plugins', 'admin-site-enhancements' ),
					'caps'  => array( 'activate_plugins' ),
				),
				array(
					'id'    => 'ess_manage_plugins',
					'label' => __( 'Install, update and delete plugins', 'admin-site-enhancements' ),
					'caps'  => array( 'install_plugins', 'update_plugins', 'delete_plugins' ),
				),
				array(
					'id'    => 'ess_edit_plugins',
					'label' => __( 'Edit plugin files', 'admin-site-enhancements' ),
					'caps'  => array( 'edit_plugins' ),
				),
			),
		),
		array(
			'id'    => 'site',
			'label' => __( 'Site', 'admin-site-enhancements' ),
			'items' => array(
				array(
					'id'    => 'ess_read',
					'label' => __( 'Access the dashboard and own profile', 'admin-site-enhancements' ),
					'caps'  => array( 'read' ),
				),
				array(
					'id'    => 'ess_manage_options',
					'label' => __( 'Manage site settings', 'admin-site-enhancements' ),
					'caps'  => array( 'manage_options' ),
				),
				array(
					'id'    => 'ess_import_export',
					'label' => __( 'Import and export content', 'admin-site-enhancements' ),
					'caps'  => array( 'import', 'export' ),
				),
				array(
					'id'    => 'ess_update_core',
					'label' => __( 'Update WordPress core', 'admin-site-enhancements' ),
					'caps'  => array( 'update_core' ),
				),
				array(
					'id'    => 'ess_privacy',
					'label' => __( 'Manage privacy tools', 'admin-site-enhancements' ),
					'caps'  => array( 'manage_privacy_options' ),
				),
				array(
					'id'    => 'ess_unfiltered_html',
					'label' => __( 'Post unfiltered HTML', 'admin-site-enhancements' ),
					'caps'  => array( 'unfiltered_html' ),
				),
			),
		),
	),

	// Capabilities that are considered sensitive: granting them to a non-administrator role deserves a second look.
	'sensitive' => array(
		'manage_options',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'remove_users',
		'add_users',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'switch_themes',
		'edit_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_theme_options',
		'unfiltered_html',
		'unfiltered_upload',
		'update_core',
		'edit_files',
		'import',
		'export',
		'manage_privacy_options',
		'manage_network',
		'manage_sites',
		'create_sites',
		'delete_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'upgrade_network',
	),

	// Matrix columns for post types. Key => array( WP_Post_Type_Object->cap key, label ).
	'post_type_columns' => array(
		'read_private'     => array( 'read_private_posts', __( 'Read private', 'admin-site-enhancements' ) ),
		'create'           => array( 'create_posts', __( 'Create', 'admin-site-enhancements' ) ),
		'edit'             => array( 'edit_posts', __( 'Edit own', 'admin-site-enhancements' ) ),
		'edit_others'      => array( 'edit_others_posts', __( 'Edit others\'', 'admin-site-enhancements' ) ),
		'edit_published'   => array( 'edit_published_posts', __( 'Edit published', 'admin-site-enhancements' ) ),
		'edit_private'     => array( 'edit_private_posts', __( 'Edit private', 'admin-site-enhancements' ) ),
		'publish'          => array( 'publish_posts', __( 'Publish', 'admin-site-enhancements' ) ),
		'delete'           => array( 'delete_posts', __( 'Delete own', 'admin-site-enhancements' ) ),
		'delete_others'    => array( 'delete_others_posts', __( 'Delete others\'', 'admin-site-enhancements' ) ),
		'delete_published' => array( 'delete_published_posts', __( 'Delete published', 'admin-site-enhancements' ) ),
		'delete_private'   => array( 'delete_private_posts', __( 'Delete private', 'admin-site-enhancements' ) ),
	),

	// Matrix columns for taxonomies. Key => array( WP_Taxonomy->cap key, label ).
	'taxonomy_columns' => array(
		'manage_terms' => array( 'manage_terms', __( 'Manage', 'admin-site-enhancements' ) ),
		'edit_terms'   => array( 'edit_terms', __( 'Edit', 'admin-site-enhancements' ) ),
		'delete_terms' => array( 'delete_terms', __( 'Delete', 'admin-site-enhancements' ) ),
		'assign_terms' => array( 'assign_terms', __( 'Assign', 'admin-site-enhancements' ) ),
	),

	// Well-known third-party capability patterns: regex => label. Filterable via `asenha_role_manager_third_party_patterns`.
	'third_party_patterns' => array(
		'/(woocommerce|(^|_)(products?|shop_orders?|shop_coupons?)(_terms)?$)/' => 'WooCommerce',
		'/^(wpcf7_|cf7_)/'                               => 'Contact Form 7',
		'/^gravityforms_/'                               => 'Gravity Forms',
		'/^(wpseo_|.*_wpseo_)/'                          => 'Yoast SEO',
		'/^(rank_math|rankmath)/'                        => 'Rank Math SEO',
		'/^aioseo_/'                                     => 'All in One SEO',
		'/^wpforms_/'                                    => 'WPForms',
		'/^(tribe_|.*_tribe_)/'                          => 'The Events Calendar',
		'/^(learndash_|.*_learndash)/'                   => 'LearnDash',
		'/^ure_/'                                        => 'User Role Editor',
		'/^restrict_content$/'                           => 'Members',
		'/^(edit_roles|create_roles|delete_roles|list_roles)$/' => 'Members / WPFront User Role Editor',
		'/^(edit_role_menus|edit_nav_menu_permissions|(edit|delete)_content_shortcodes|(edit|delete)_login_redirects|bulk_edit_roles|edit_widget_permissions|(create|edit|delete)_(posttypes|taxonomies))$/' => 'WPFront User Role Editor',
		'/^manage_capabilities/'                         => 'PublishPress Capabilities',
		'/^(elementor_|.*_elementor)/'                   => 'Elementor',
		'/^(bbp_|keep_gate|participate|spectate|throttle|view_trash)/' => 'bbPress',
	),
);
