# inc/dependencies/scf/ — Secure Custom Fields

```
inc/dependencies/scf/
  scf.php   — loads SCF from vendor/, fixes its asset URLs, boots its
              Abilities API integration (see below — this is the part
              that needed a real fix, not just a path override)
```

See `../AGENT.md` for why this (and every dependency here) is loaded from
`vendor/` via `require_once` rather than as a real WordPress plugin,
for `dependency-updater.php`'s auto-update mechanism, which manages this
one, and for why `vendor/secure-custom-fields/` is committed directly to
this theme's own git repo rather than left to `composer install` alone.

### Asset URL fix

Secure Custom Fields (SCF) — the WordPress.org fork/successor to ACF, same
maintainers, same filter API — computes its own asset URL via
`plugin_dir_url()`, which only resolves correctly for files under
`wp-content/plugins/`. Since it's loaded from `vendor/` instead, that
computation returns a garbage URL and its admin CSS/JS 404 silently.

Fix: SCF exposes `acf/settings/path` and `acf/settings/url` filters
(inherited from ACF's long-stable public filter API — this is documented,
not an internal we're reaching into), overridden here to point at the
real `vendor/secure-custom-fields/` location before the file is required.

### The `plugins_loaded` bug — SCF's own Abilities API integration silently never ran

SCF ships its own integration with WordPress core's Abilities API
(`vendor/secure-custom-fields/includes/abilities/class-scf-abilities-integration.php`)
— when active, it registers ~48 MCP-exposable abilities covering full
CRUD on SCF post types, taxonomies, options pages, field groups, and
fields (see `inc/abilities/AGENT.md` for the theme's own, separate
content abilities that build on top of this).

That integration class hooks its setup on `plugins_loaded`, priority 20 —
but as documented in `../AGENT.md`, `plugins_loaded` fires **before** the
active theme's `functions.php` is even loaded, so by the time this file's
`require_once` boots SCF, that action has already fired. SCF's callback
never runs, and its abilities silently never register — no error, no
warning, `discover-abilities` via MCP just shows 3 core abilities and
nothing from SCF. This is the exact same class of bug that got FileBird
dropped entirely (see `../AGENT.md`), just resurfacing inside a dependency
we can't simply drop.

**The fix**, at the bottom of `scf.php`: replicate what
`SCF_Abilities_Integration::init()` would have done — `acf_include()` the
same six ability class files directly — rather than patching vendor/ code
the auto-updater would overwrite on the next approved-version swap. This
is safe regardless of exactly when it runs: each of those files defers its
real `wp_register_ability()` calls to the `wp_abilities_api_init` action,
which WordPress core fires *lazily* (on first access to the abilities
registry, always after `init`, confirmed via reading
`wp-includes/abilities-api/class-wp-abilities-registry.php`) — not tied to
plugin/theme load order the way `plugins_loaded` is. `acf_include()`
itself is a no-op if the target file doesn't exist
(`includes/acf-utility-functions.php`), so a future SCF release
restructuring these files degrades to "SCF abilities just don't register
again," not a fatal error.

Verified live via the real MCP protocol (not just code review): before
the fix, `discover-abilities` showed 3 abilities; after, ~48-51 (the exact
count depends on how many real field groups/fields currently exist on the
site, since some SCF abilities are per-instance).

### A related, separately-confirmed SCF bug — NOT fixed here (upstream, out of our control)

While verifying the above, `scf_field_groups` — a REST field SCF adds to
`/wp/v2/types/{post_type}` describing a post type's attached custom
fields — was found to never actually appear in real REST responses,
despite being correctly registered (confirmed present in
`$wp_rest_additional_fields['type']`) and its callback (`get_scf_fields()`)
returning correct data when called directly. A sibling field registered
the identical way, `scf_post_id`, works fine. Root cause not fully
isolated (traced into WordPress core's `add_additional_fields_to_object()`
/ `rest_is_field_included()` field-inclusion filtering, but not resolved
further) — this is a genuine upstream SCF/core REST bug, unrelated to
anything in this theme. `inc/abilities/AGENT.md` documents the workaround:
`devbase/get-post-type-schema` calls the same underlying
`acf_get_field_groups()`/`acf_get_fields()` functions directly instead of
trusting this specific REST field.

## Where to go from here

- **Shared dependency-loading rationale + the auto-updater:** `../AGENT.md`
- **Admin Site Enhancements:** `../ase/AGENT.md`
- **WordPress MCP Adapter:** `../mcp-adapter/AGENT.md`
- **The theme's own content abilities (built on top of SCF's + MCP
  Adapter's abilities):** `../../abilities/AGENT.md`
