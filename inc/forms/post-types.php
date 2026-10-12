<?php

// Registers the two CPTs this feature is built on — the first CPTs this
// theme has ever registered. Both are admin-only logging/registry post
// types, never meant to be public:
//
// - registered_form — one post per form name (see registry.php, which
//   syncs these from registered-forms.json).
// - form_data        — one post per submission (see submit.php).
//
// Hooked on plain 'init' at the default priority, not 'acf/init'. ACF's
// own 'acf/init' fires from inside 'init' at priority 5 — earlier than a
// default-priority 'init' callback — but nothing here needs to exist that
// early: has_archive is false for both, so there's no archive-registration
// timing to race.
//
// Capabilities: both CPTs map the PLURAL/primitive capabilities directly
// to 'manage_options' — the same bar Theme Settings itself already
// requires — instead of the plain 'post' capability_type, which would let
// any Author/Editor (anyone with edit_posts) see submitted PII.
//
// Deliberately does NOT map the three SINGULAR/object meta caps
// (edit_post, read_post, delete_post). This isn't an oversight: WordPress
// registers a global reverse-lookup, $post_type_meta_caps[ $value ] =
// '<meta cap>', for whatever string each of those three resolves to — so
// mapping edit_post/read_post/delete_post directly to the literal string
// 'manage_options' hijacks that global entry for EVERY 'manage_options'
// check on the entire site (Theme Settings included), not just this CPT,
// since 'manage_options' is a real, pre-existing WordPress capability
// name, not something scoped to this post type. Confirmed this exact
// collision live: after registering with all three included,
// map_meta_cap( 'manage_options', $user_id ) returned ['do_not_allow']
// site-wide instead of resolving normally. Leaving these three OUT is
// sufficient: WP's own built-in case for edit_post/read_post/delete_post
// (map_meta_cap()'s core logic) already falls back to checking
// edit_others_posts/read_private_posts/delete_others_posts for a post
// that isn't the current user's own — and those three ARE safely mapped
// below, achieving the same "only manage_options-holders can touch this"
// result without ever touching the global capability string itself.
add_action( 'init', function () {
	$admin_only_capabilities = array(
		'edit_posts'              => 'manage_options',
		'edit_others_posts'       => 'manage_options',
		'edit_private_posts'      => 'manage_options',
		'edit_published_posts'    => 'manage_options',
		'publish_posts'           => 'manage_options',
		'read_private_posts'      => 'manage_options',
		'delete_posts'            => 'manage_options',
		'delete_others_posts'     => 'manage_options',
		'delete_private_posts'    => 'manage_options',
		'delete_published_posts'  => 'manage_options',
	);

	// registered_form posts are not user content — they're a 1:1 mirror of
	// registry/registered-forms.json, kept in sync by registry/sync.php.
	// Deleting one by hand in wp-admin would desync it from the JSON (the
	// next sync can't recreate it, since sync.php only fills gaps for names
	// it doesn't already have a post for — see sync.php) and would silently
	// break the "Applies To" pickers on Form Destinations/Retention Policy
	// referencing that form by name. So unlike every other cap here, the
	// delete caps are 'do_not_allow' instead of 'manage_options' — nobody,
	// administrators included, can delete this CPT from the UI. The only
	// supported deletion path is programmatic: registry/sync.php calling
	// wp_delete_post() directly when a form is removed from the JSON, which
	// is unaffected by this (wp_delete_post() doesn't check capabilities —
	// only admin-post.php/REST/bulk-action handlers do).
	// create_posts is locked out too: a dev adds a form by editing the JSON,
	// never by clicking "Add New" here — and the CPT only supports 'title'
	// with no field to set form_name on a manual post (the meta box is
	// read-only, see registered-form-admin-display.php), so a hand-created
	// one would save with an empty form_name. That's not just useless
	// clutter: devbase_forms_prune_deregistered() (registry/sync.php)
	// explicitly skips posts with an empty form_name, so combined with the
	// delete lockout below, a manually-created post would be a permanent,
	// unremovable orphan — caught and closed before it could happen for
	// real, not from an incident.
	$registered_form_capabilities                            = $admin_only_capabilities;
	$registered_form_capabilities['create_posts']             = 'do_not_allow';
	$registered_form_capabilities['delete_posts']            = 'do_not_allow';
	$registered_form_capabilities['delete_others_posts']     = 'do_not_allow';
	$registered_form_capabilities['delete_private_posts']    = 'do_not_allow';
	$registered_form_capabilities['delete_published_posts']  = 'do_not_allow';

	register_post_type( 'registered_form', array(
		'labels'              => array(
			'name'               => __( 'Registered Forms', 'devbase_parent_theme' ),
			'singular_name'      => __( 'Registered Form', 'devbase_parent_theme' ),
			'add_new_item'       => __( 'Add New Registered Form', 'devbase_parent_theme' ),
			'edit_item'          => __( 'Edit Registered Form', 'devbase_parent_theme' ),
			'all_items'          => __( 'Registered Forms', 'devbase_parent_theme' ),
			'search_items'       => __( 'Search Registered Forms', 'devbase_parent_theme' ),
			'not_found'          => __( 'No registered forms found.', 'devbase_parent_theme' ),
			'not_found_in_trash' => __( 'No registered forms found in Trash.', 'devbase_parent_theme' ),
		),
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_admin_bar'   => false,
		'show_in_nav_menus'   => false,
		'show_in_rest'        => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'has_archive'         => false,
		'query_var'           => false,
		'rewrite'             => false,
		'capability_type'     => 'post',
		'capabilities'        => $registered_form_capabilities,
		'map_meta_cap'        => true,
		'hierarchical'        => false,
		'supports'            => array( 'title' ),
		'menu_icon'           => 'dashicons-forms',
	) );

	// form_data posts are submission logs, never hand-authored content —
	// the only supported way one gets created is devbase_form_log_submission()
	// inserting it from a real front-end submission (inc/helpers/forms/).
	// A manually-added one via wp-admin would be a fake submission with no
	// real visitor behind it, indistinguishable in the list table from a
	// genuine one. 'create_posts' isn't in $admin_only_capabilities above
	// (WordPress defaults it to whatever 'edit_posts' resolves to when
	// left unset, so it was already implicitly 'manage_options' — any
	// admin could hit "Add New"), so it's overridden here to 'do_not_allow'.
	// This is what WordPress itself checks to decide whether to show the
	// "Add New" button/admin-menu link and to gate post-new.php directly —
	// no separate UI hiding needed. wp_insert_post() (what
	// devbase_form_log_submission() actually calls) never checks
	// capabilities itself, so this doesn't affect real submissions.
	$form_data_capabilities                  = $admin_only_capabilities;
	$form_data_capabilities['create_posts']  = 'do_not_allow';

	// show_in_rest is deliberately false: form_data posts can hold whatever
	// a visitor submitted — names, emails, free-text messages. There's no
	// reason to expose that over the REST API.
	register_post_type( 'form_data', array(
		'labels'              => array(
			'name'               => __( 'Form Data', 'devbase_parent_theme' ),
			'singular_name'      => __( 'Submission', 'devbase_parent_theme' ),
			'all_items'          => __( 'Form Data', 'devbase_parent_theme' ),
			'search_items'       => __( 'Search Submissions', 'devbase_parent_theme' ),
			'not_found'          => __( 'No submissions found.', 'devbase_parent_theme' ),
			'not_found_in_trash' => __( 'No submissions found in Trash.', 'devbase_parent_theme' ),
		),
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_admin_bar'   => false,
		'show_in_nav_menus'   => false,
		'show_in_rest'        => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'has_archive'         => false,
		'query_var'           => false,
		'rewrite'             => false,
		'capability_type'     => 'post',
		'capabilities'        => $form_data_capabilities,
		'map_meta_cap'        => true,
		'hierarchical'        => false,
		'supports'            => array( 'title' ),
		'menu_icon'           => 'dashicons-email-alt',
	) );
} );
