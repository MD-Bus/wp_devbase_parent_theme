<?php

// devbase/get-site-guide — the brief an MCP client reads before it writes
// anything: who the site is for, the tone, and how its pages are put
// together (which block opens a page, which one closes it, what each is
// for). The other abilities describe WHAT can be written; this says what
// a good page on this particular site looks like, which no schema can.
//
// The text is the "Instructions for AI Assistants" field on Theme Settings >
// Miscellaneous > MCP Access (mcp_site_guide), so the site's owner edits it
// like any other setting. A child theme can seed it (OCWOF does, fill-only-
// if-empty). It is in devbase_abilities_protected_option_fields(): an MCP
// client can read its instructions but never rewrite them.

/**
 * The guide plus the basics a client would otherwise ask for separately.
 *
 * @return array{site_name:string, tagline:string, url:string, language:string, guide:string}
 */
function devbase_abilities_site_guide() {
	return array(
		'site_name' => (string) get_bloginfo( 'name' ),
		'tagline'   => (string) get_bloginfo( 'description' ),
		'url'       => home_url( '/' ),
		'language'  => get_bloginfo( 'language' ),
		'guide'     => trim( (string) get_field( 'mcp_site_guide', 'option' ) ),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'devbase/get-site-guide', array(
		'label'               => __( 'Get Site Guide', 'devbase_parent_theme' ),
		'description'         => __( 'Read this first, before creating or changing content. Returns the site\'s name, tagline, address and language, and the site owner\'s instructions for AI assistants: who the site is for, the tone to write in, and how pages are built (which blocks open and close a page, what each is for, how long text should be). Follow them when using devbase/create-post and update-post. An empty guide means the owner has written none.', 'devbase_parent_theme' ),
		'category'            => 'devbase',
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'site_name' => array( 'type' => 'string' ),
				'tagline'   => array( 'type' => 'string' ),
				'url'       => array( 'type' => 'string' ),
				'language'  => array( 'type' => 'string' ),
				'guide'     => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'devbase_abilities_site_guide',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta'                => array(
			'public'      => true,
			'annotations' => array( 'readonly' => true ),
		),
	) );
} );
