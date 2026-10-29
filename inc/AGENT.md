# inc/ — how this theme's bootstrap works

This directory holds everything `functions.php` loads beyond Timber's basic
setup. It's organized as one subfolder per subsystem, each with its own
`AGENT.md` — this file is the index, not the full detail.

```
inc/
  AGENT.md                    — this file
  timber.php                     — Timber::init()
  hide-admin-menu-items.php       — hides Posts/Comments menu items
  dependencies/                     — SCF, ASE, MCP Adapter, and the self-updater
    AGENT.md                          — full breakdown, see there
    dependency-updater.php              — shared/cross-cutting, stays at this level
    ase/                                 — Admin Site Enhancements, see ase/AGENT.md
    scf/                                 — Secure Custom Fields, see scf/AGENT.md
    mcp-adapter/                         — WordPress MCP Adapter, see mcp-adapter/AGENT.md
  abilities/                         — this theme's own MCP content abilities
    AGENT.md                           — full breakdown, see there
  updates/                           — Warden-delivered theme updates, for
                                        this theme AND the active child theme
    AGENT.md                           — full breakdown, see there
    warden.php
    theme-updates.php
  theme-settings/                   — the admin settings area
    AGENT.md                          — full breakdown, see there
    theme-settings.php
    licence/                            — Licence sub-page (Warden config,
                                            licence keys, update status,
                                            manual check button)
      AGENT.md                            — full breakdown, see there
      settings.php
      status.php
      check-ajax.php
      check.js
    smtp/                               — SMTP Settings sub-page
      AGENT.md                            — full breakdown, see there
      settings.php
      mailer.php
      test.php
      test.js
    destinations/                       — Form Destinations sub-page
      AGENT.md                            — full breakdown, see there
      settings.php
    miscellaneous/                      — catch-all sub-page (Retention Policy,
                                           System Status, MCP Access)
      AGENT.md                            — full breakdown, see there
      settings.php
      purge-ajax.php
      purge.js
    security/                          — Security Settings sub-page
      AGENT.md                            — full breakdown, see there
      settings.php
      verify.php
      security.js
    tracking/                           — Tracking sub-page
      AGENT.md                            — full breakdown, see there
      settings.php
      inject.php
    schema/                             — Schema Markup sub-page
      AGENT.md                            — full breakdown, see there
      settings.php
      organization/                          — the Organization schema type
        AGENT.md                                — full breakdown, see there
        settings.php
        inject.php
      website/                               — the Website schema type
        AGENT.md                                — full breakdown, see there
        settings.php
        inject.php
      breadcrumb/                             — the Breadcrumb schema type
        AGENT.md                                — full breakdown, see there
        settings.php
        inject.php
  archive/                           — CPT archive fields + link picker support
    AGENT.md                           — full breakdown, see there
    settings.php
    link-query.php
  forms/                             — centralized form logging + notification
                                        dispatch (this theme's first two CPTs)
    AGENT.md                           — full breakdown, see there
    post-types.php
    form-data-admin-display.php
    registered-form-admin-display.php
    retention.php
    test-form.php
    registry/                             — JSON registry -> registered_form posts,
                                              + the shared "which forms exist" lookup
      AGENT.md                                — full breakdown, see there
      registered-forms.json
      sync.php
      choices.php
    dispatch/                             — the send side: orchestrator + senders
      AGENT.md                                — full breakdown, see there
      orchestrator.php
      smtp.php
      webhook.php
      status.php
  helpers/                           — public, dev-facing helper functions
    AGENT.md                           — full breakdown, see there
    forms/                                — helpers fronting inc/forms/
      AGENT.md                              — full breakdown, see there
      log-submission.php                      — devbase_form_log_submission()
      build-content.php                         — devbase_form_build_content()
      nonce-field.php                             — devbase_form_nonce_field()
```

**Read `inc/dependencies/AGENT.md`** for: why SCF/ASE/MCP Adapter are
loaded via `vendor/` + `require_once` instead of as real WordPress plugins
(and what that decision cost — the FileBird `plugins_loaded` bug, the
whole activation/deactivation saga that led here), the full self-updating
system that keeps SCF/ASE current via a curated Cloudflare Worker approval
gate, downloading straight from WordPress.org, and (in
`mcp-adapter/AGENT.md`) the MCP transport itself — the official WordPress
core team plugin, ungated and updated separately since it isn't on
WordPress.org at all.

**Read `inc/abilities/AGENT.md`** for: this theme's own MCP content
abilities — list/get/create/update posts and options-page values, generic
across every post type, plus the admin-configurable opt-in allow-list
(the "MCP Access" tab in `inc/theme-settings/miscellaneous/`) that
controls exactly what gets exposed. Distinct from SCF's own abilities
(covered in `inc/dependencies/scf/AGENT.md`), which describe field/
post-type/taxonomy/options-page *structure* rather than content.

**Read `inc/updates/AGENT.md`** for: how this theme and the client child
theme on top of it keep themselves current — the signed Warden API client
(Ed25519 verification, nonce replay protection, 12-hour cache, fail-soft
on an outage) and why the actual install goes through WordPress's own
`Theme_Upgrader` rather than this theme's existing swap code. Its
settings live on the Licence sub-page below.

**Read `inc/theme-settings/AGENT.md`** for: the Theme Settings options
page, the pattern for adding new sub-menus (one subfolder each, following
the SMTP Settings example), and (in `inc/theme-settings/smtp/AGENT.md`)
the SMTP Settings sub-page itself.

**Read `inc/archive/AGENT.md`** for: attaching fields to a CPT archive
(SCF/ACF has no location rule for one, so it's done via a dedicated
options page instead) and getting CPT archives to show up in the internal
link picker (a `wp_link_query` filter — archives aren't real posts, so
core's own search never finds them otherwise).

**Read `inc/forms/AGENT.md`** for: this theme's first two CPTs
(`registered_form`, `form_data`), the JSON-file form-name registry, the
`save_post_form_data`-triggered dispatch to whichever
`inc/theme-settings/destinations/` row(s) apply to a given form, the
scheduled data-retention purge, and the admin-only capability model — the
last of which surfaced a real, serious bug worth reading about before
touching capabilities on any CPT in this theme again.

**Read `inc/helpers/AGENT.md`** for: the convention this theme uses for
public, dev-facing functions (as opposed to a feature's internal
machinery) — currently `devbase_form_nonce_field()`,
`devbase_form_build_content()`, and `devbase_form_log_submission()`,
the three calls any form uses to turn `$_POST` into CSRF protection,
logging, spam-gating, and notification dispatch.

**Read `languages/AGENT.md`** for: how this theme's translations actually
work (`.po` vs `.mo`, why it's pure string-matching with no CPT/page
awareness, a real `.mo`-naming bug that silently broke every translation,
and the `i18n.php` file that loads it all) — lives in `languages/`, not
here, since it's really part of the translation system rather than a
general bootstrap concern.

Load order follows `functions.php` top to bottom — get the exact current
order from that file directly rather than trusting a copy here that could
drift out of sync as files are added.

## The standalone files

These don't have their own subfolder — small enough, and not part of a
growing subsystem the way `dependencies/` and `theme-settings/` are.

### `inc/timber.php`
Just `Timber\Timber::init()`. Registers Twig locations and template
functions/filters, defaulting to a `views/` directory at the theme root
(`Timber::$dirname`, unconfigured here). Nothing else in this theme
depends on load order here beyond "before any template tries to render."
`views/index.twig` (rendered by the root `index.php`) is this theme's
first actual use of it — see `views/AGENT.md`.

### `inc/hide-admin-menu-items.php`
Removes "Posts" and "Comments" from the wp-admin sidebar while this theme
is active — most sites built on this theme don't use the built-in
blog/comments. Purely presentational: `remove_menu_page()` on `admin_menu`,
nothing stored anywhere.

**Filterable via `devbase/admin_menu/hidden_items`** (a list of menu
slugs), because this is one of the few parent-theme defaults that is
genuinely wrong for *some* client sites — a site that really does run a
blog needs Posts and Comments back. A child theme adjusts it with a plain
top-level `add_filter()`; returning an empty array hides nothing. Note it
hides the menu item only — it does not restrict capabilities, so the URL
remains reachable directly. Switching away from or deleting this theme
restores the default menu automatically with nothing to clean up — unlike
almost everything in `dependencies/` and `theme-settings/`, there's no
persistent state to worry about here.
