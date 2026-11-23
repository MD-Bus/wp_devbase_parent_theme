<?php
/**
 * User Role Manager: WordPress default (baseline) core roles and capabilities.
 *
 * Used to reset a core role to its WordPress default, to power the "Changed"
 * filter, to offer role presets and (Pro) to diff a site's roles against the
 * defaults during migration. Values reflect a single-site WordPress install.
 *
 * @package ASENHA\UserRoleManager
 * @since   9.2.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$asenha_urm_subscriber = array(
	'read',
	'level_0',
);

$asenha_urm_contributor = array(
	'delete_posts',
	'edit_posts',
	'read',
	'level_1',
	'level_0',
);

$asenha_urm_author = array(
	'upload_files',
	'edit_posts',
	'edit_published_posts',
	'publish_posts',
	'delete_posts',
	'delete_published_posts',
	'read',
	'level_2',
	'level_1',
	'level_0',
);

$asenha_urm_editor = array(
	'moderate_comments',
	'manage_categories',
	'manage_links',
	'upload_files',
	'unfiltered_html',
	'edit_posts',
	'edit_others_posts',
	'edit_published_posts',
	'publish_posts',
	'edit_pages',
	'read',
	'level_7',
	'level_6',
	'level_5',
	'level_4',
	'level_3',
	'level_2',
	'level_1',
	'level_0',
	'edit_others_pages',
	'edit_published_pages',
	'publish_pages',
	'delete_pages',
	'delete_others_pages',
	'delete_published_pages',
	'delete_posts',
	'delete_others_posts',
	'delete_published_posts',
	'delete_private_posts',
	'edit_private_posts',
	'read_private_posts',
	'delete_private_pages',
	'edit_private_pages',
	'read_private_pages',
);

$asenha_urm_administrator = array_merge(
	$asenha_urm_editor,
	array(
		'switch_themes',
		'edit_themes',
		'activate_plugins',
		'edit_plugins',
		'edit_users',
		'edit_files',
		'manage_options',
		'import',
		'level_10',
		'level_9',
		'level_8',
		'delete_users',
		'create_users',
		'unfiltered_upload',
		'edit_dashboard',
		'update_plugins',
		'delete_plugins',
		'install_plugins',
		'update_themes',
		'install_themes',
		'update_core',
		'list_users',
		'remove_users',
		'promote_users',
		'edit_theme_options',
		'delete_themes',
		'export',
		'manage_privacy_options',
		'resume_plugins',
		'resume_themes',
	)
);

return array(
	'administrator' => array(
		'name' => 'Administrator',
		'caps' => array_values( array_unique( $asenha_urm_administrator ) ),
	),
	'editor'        => array(
		'name' => 'Editor',
		'caps' => $asenha_urm_editor,
	),
	'author'        => array(
		'name' => 'Author',
		'caps' => $asenha_urm_author,
	),
	'contributor'   => array(
		'name' => 'Contributor',
		'caps' => $asenha_urm_contributor,
	),
	'subscriber'    => array(
		'name' => 'Subscriber',
		'caps' => $asenha_urm_subscriber,
	),
);
