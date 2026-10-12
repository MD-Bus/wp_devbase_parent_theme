<?php

// Boots the official WordPress MCP Adapter (github.com/WordPress/mcp-adapter)
// from vendor/, same "never a real activated plugin" pattern as SCF/ASE.
// Unlike those, it needs no acf/settings-style path/url override: it's a
// headless API bridge (Abilities API -> MCP) with no admin UI assets that
// call plugin_dir_url(), and no register_activation_hook() to skip.
//
// Guarded with file_exists() — vendor/mcp-adapter/ is committed directly
// to this theme's own git repo (unlike SCF/ASE, which are Composer-
// managed and gitignored — see this folder's AGENT.md for why that
// distinction is deliberate), so it's present the moment the theme is,
// with no install step of its own. The guard is just cheap insurance
// against a partial checkout or manual deletion — same reasoning as the
// file_exists() guards throughout inc/dependencies/ase/ for ASE's
// undocumented internals: degrade to "MCP features aren't available"
// rather than a fatal error on every single page load.
if ( file_exists( get_template_directory() . '/vendor/mcp-adapter/mcp-adapter.php' ) ) {
	require_once get_template_directory() . '/vendor/mcp-adapter/mcp-adapter.php';
}
