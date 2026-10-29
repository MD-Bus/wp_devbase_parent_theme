# devbase_parent_theme

The base WordPress theme every custom site starts from. Timber/Twig
for templating, SCF for custom fields, ASE for admin cleanup/hardening —
not a live client site, and never activated directly on one.

**This is a PARENT theme.** Each client site runs a child theme
(`../wp_devbase_child_theme/` is the starter to copy) that holds that
site's design and client-specific code, with this theme underneath
providing everything shared. Two consequences run through everything
below:

- **This theme's directory name is fixed.** A child binds to it with
  `Template: devbase_parent_theme` in its own `style.css`. The old model of
  copying and renaming this folder per project is gone — that fixed name
  is exactly what lets one release update every client site at once.
- **Use `get_template_*()`, never `get_stylesheet_*()`, in this theme.**
  `get_stylesheet_directory()` resolves to the active *child*. Every path
  in here was changed when the split was made; `scf.php`'s unconditional
  `require_once` through the wrong one is a fatal error on every page load,
  and the `file_exists()`-guarded ones degrade silently instead, which is
  worse to diagnose.

**Start here, then go deeper:** this file is the entry point. The real
detail lives in `inc/AGENT.md` and the `AGENT.md` in every subfolder below
it — each one documents just its own area, and every parent links down to
its children rather than duplicating content. Read this file for the
big-picture shape of the theme; read the linked files for how any specific
piece actually works.

```
wp_devbase_parent_theme/
  AGENT.md              — this file
  style.css                — theme header only (WP requires this + index.php)
  LICENSE                     — GPLv2+ (required — vendor/ bundles GPLv2+ code)
  index.php                  — WP's required fallback template; renders a minimal
                                 branded placeholder via Timber/Twig — this theme's
                                 ONLY front-end template so far, see views/AGENT.md
  functions.php                — the only place things get require_once'd from
  composer.json                  — pins exact runtime dependency versions only —
                                     PHPUnit and friends live in tests/composer.json
                                     instead, see tests/AGENT.md for why
  composer.lock
  phpunit.xml.dist                 — PHPUnit config, see tests/AGENT.md
  vendor/                          — Timber, SCF, ASE, MCP Adapter — committed
                                       directly to git (not gitignored — see
                                       inc/dependencies/AGENT.md), loaded via
                                       require_once, not as WP plugins
  views/
    AGENT.md                          — the front-end template(s), see there
    index.twig                          — the placeholder page index.php renders
  assets/
    icons/                            — e.g. devbase-b-icon.svg, used as the Theme
                                          Settings admin menu icon (and the front-end
                                          placeholder's logo)
  languages/
    AGENT.md                             — how translation works in this theme
    i18n.php                               — loads the .mo file for the current locale
    devbase_parent_theme.pot                  — template of every translatable string
    fr_FR.po / fr_FR.mo                        — French (France)
    fr_CA.po / fr_CA.mo                        — French (Canada)
    fr_BE.po / fr_BE.mo                        — French (Belgium)
  tests/
    AGENT.md                             — automated tests: setup, real bugs hit
                                             building the harness, how to run/extend it
  bin/
    AGENT.md                             — dev-only scripts
    build-zip.sh                           — packages a distributable .zip
  inc/
    AGENT.md                              — index for everything below; start here
                                             for how the theme actually boots
```

The client child theme is a **separate repo** with its own `AGENT.md`,
its own Warden track and its own `bin/build-zip.sh` — see
`../wp_devbase_child_theme/AGENT.md`. Nothing in this theme packages,
versions or knows about it, beyond detecting it at runtime as a second
update track.

## Why this theme looks the way it does

A few decisions run through the whole codebase, worth knowing before
touching anything:

- **Nothing here is a real WordPress plugin.** SCF and ASE are Composer
  dependencies loaded directly from `vendor/` via `require_once` in
  `functions.php` — never installed to `wp-content/plugins/`, never
  activated/deactivated through WordPress's own plugin lifecycle. This
  was arrived at after real, repeated bugs trying the conventional
  approach. Full story in `inc/dependencies/AGENT.md`.
- **Dependency checks run on WP-Cron, not `admin_init`** — so a client
  site nobody logs into still keeps itself current. That was a real gap:
  `admin_init` only fires in wp-admin, and most client sites get front-end
  traffic daily but an admin visit once a quarter. Their last-run state is
  visible on Theme Settings > Miscellaneous > System Status, because
  WP-Cron is pseudo-cron and a check that silently stops has no other
  signal. See `inc/dependencies/AGENT.md`.
- **SCF/ASE track WordPress.org's current stable release, with no
  approval gate.** There used to be one — a Cloudflare Worker
  (`devbase-dependency-worker`) serving a curated allow-list of tested
  versions, with URL failover and rollback-by-disapproval. It was removed
  with the move to parent/child + Warden, because version control now
  happens in one place instead of two: what ships inside a parent theme
  release. Full story, and the tradeoff this accepts, in
  `inc/dependencies/AGENT.md`.
- **Updates are delivered by Warden, installed by WordPress.** Both this
  theme and the client child theme have their own Warden release track.
  `inc/updates/` asks Warden what's available (verifying an Ed25519
  signature before trusting a word of it) and hands the answer to
  WordPress's own `Theme_Upgrader`, which does the downloading and
  swapping. See `inc/updates/AGENT.md`.
- **A child theme extends this one through filters, never by forking it.**
  `devbase/ase/defaults`, `devbase/ase/enforced`,
  `devbase/forms/registered_forms`, `devbase/admin_menu/hidden_items`
  and `devbase/schema/tabs` are the current extension points. If a child
  needs to change something that isn't filterable yet, add the filter
  *here* rather than copying parent code into the child.
- **Every subsystem gets its own subfolder once it's more than a file or
  two, and every subfolder gets its own `AGENT.md`.** `inc/dependencies/`
  and `inc/theme-settings/` (with `inc/theme-settings/smtp/` nested inside
  it) are the current examples — copy that pattern for anything new
  rather than letting `inc/` become a flat, undifferentiated pile.
- **Defaults are applied by filling gaps, never by overwriting.** Several
  places in this theme (ASE's settings, most notably) write default
  configuration into options that already exist elsewhere (ASE's own
  settings form, a site admin's manual changes). The rule throughout is:
  fill in what's missing, never silently override what's already set —
  because there's no reliable way to tell "an admin deliberately changed
  this" apart from "something else reset it," and silently re-asserting a
  default risks undoing a deliberate choice. Explained in more depth
  (with the exact bug that taught this lesson) in
  `inc/dependencies/AGENT.md`.
- **Everything non-trivial was verified against real infrastructure, not
  just syntax-checked.** Real Cloudflare Worker deploys, real
  WordPress.org downloads, real SMTP connections, real timing
  measurements, a real site actually set to `WPLANG=fr_FR` — several real
  bugs in this theme were only found this way (a stale-cache illusion, a
  Composer flag that doesn't mean what it sounds like, network throttling
  masquerading as slow code, a `.mo` filename convention that fails
  silently, a PHPUnit-version incompatibility that fatals every test
  until pinned correctly — see `languages/AGENT.md` and `tests/AGENT.md`).
  If you're extending this theme, hold the same bar: a passing `php -l`
  is not the same as confirming the thing actually works.
- **Automated tests are dev-only tooling, not part of what ships.**
  `tests/` — including `phpunit/phpunit`, `wp-phpunit/wp-phpunit`, and
  `yoast/phpunit-polyfills`, which live in their own `tests/composer.json`
  and gitignored `tests/vendor/`, entirely separate from the theme root's
  own `composer.json`/`vendor/` — runs against an isolated database
  (never the real site's) and never loads on an actual site. See
  `tests/AGENT.md` for why a separate database is non-negotiable (the
  test suite drops and recreates core tables), why PHPUnit's dependencies
  live in their own separate `composer.json` (short version: the theme
  root's `vendor/` is committed to git as a pure-runtime baseline — see
  below — which is structurally incompatible with also hosting PHPUnit),
  and the setup pitfalls hit building it.
- **This theme is GPLv2 or later — not a stylistic choice.** `vendor/`
  bundles Secure Custom Fields and Admin Site Enhancements, both
  WordPress.org plugins under GPLv2+, loaded directly at runtime rather
  than just referenced. Distributing this theme (even privately, to a
  client — GPL's copyleft isn't limited to public/WordPress.org
  distribution) without a GPL-compatible license would put the theme's
  own license in conflict with code it actually bundles. `style.css`'s
  `License`/`License URI` header fields and the root `LICENSE` file both
  declare this — see `LICENSE` for the other bundled/loaded components
  (Timber — MIT) and their own licenses. If a future dependency gets added,
  check its license is GPL-compatible before adding it, same as SCF/ASE
  already are.

## Rules for working in this theme

These apply to every change, not just big ones — a one-line fix still
needs its comment/doc updated if it changes what the comment/doc claims.

1. **When a folder grows past a file or two doing related work, group it
   into a subdirectory** — don't let `inc/` (or any subfolder of it)
   become a flat pile. `inc/dependencies/` and `inc/theme-settings/smtp/`
   are the reference examples: one subsystem, one folder, one `AGENT.md`
   inside it.
2. **Every subdirectory gets its own `AGENT.md`.** If you create a new
   subfolder, create its `AGENT.md` in the same change — not as a
   follow-up, not "later."
3. **After every change, update the `AGENT.md` of every file/folder it
   touches** — not just the immediate one. If a change affects behavior
   described in a parent's summary too (e.g. a new file added to a
   subfolder that the parent's directory tree/index lists), update the
   parent as well. Treat a change as incomplete until the docs describing
   the changed code match reality again — an `AGENT.md` that's out of
   date is worse than no `AGENT.md`, because it actively misleads whoever
   reads it next.
4. **New subfolder → also add it to its parent's tree/index and link
   list**, the same way `inc/AGENT.md` lists and links `dependencies/`
   and `theme-settings/`, and this file lists and links `inc/`.
5. **Nothing client-specific goes in this theme.** It ships byte-identical
   to every site. A client's forms, post types, schema types, design, or
   deviations from these defaults belong in that site's child theme. If
   you're tempted to add a conditional for one client, you want a filter
   instead.
6. **`get_template_*()`, never `get_stylesheet_*()`** — see the top of
   this file. There is one deliberate exception, `languages/i18n.php`,
   which was already correct.
7. **A child theme may register hooks at its top level but must never call
   a parent function there.** WordPress includes the child's
   `functions.php` *before* this theme's, so no `devbase_*` function
   exists yet at that point. `function_exists()` guards make it fail
   silently rather than loudly, which is worse. Documented for child
   authors in `../wp_devbase_child_theme/AGENT.md`; worth knowing here
   too, since it constrains how extension points can be designed —
   anything a child needs must be reachable as a hook, not a call.

## No per-folder update tracks (considered, then rejected)

Giving each folder its own Warden track — `inc/`, `languages/`, `views/`,
and `vendor/timber|twig|symfony` separately — was considered and rejected.
Two reasons, the first decisive: a theme update **recursively deletes the
entire theme directory** before installing
(`Theme_Upgrader::delete_old_theme()`), so a subdirectory of a theme can
neither be independently updated nor survive an update, however it's
tracked. And more tracks would have *added* version-skew risk rather than
removing it — Warden checks versions per licence, it does not resolve
dependencies, and Timber's `twig/twig: ^3.27` constraint is enforced today
only by Composer. Full reasoning, including the accepted tradeoff:
`inc/updates/AGENT.md`.

## Packaging a distributable zip

`bin/build-zip.sh` — the only supported way to turn this into an
installable `.zip`, and the artifact uploaded to Warden as a release on
**this theme's** track. Do not hand-zip the folder; it's easy to forget a
step (dev-only `vendor/` packages, `tests/`) and ship something that
either exposes test tooling or is bloated with it for no reason on a
live client site.

The child theme has its own, much simpler `bin/build-zip.sh` in its own
repo, for its own track. This script knows nothing about it.

**⚠ Two gates run before a zip is produced**: a version-drift check
(vendor/ on disk vs `composer.lock`) and then the full test suite. The
first exists because the zip's SCF/ASE/Timber come from `composer.lock`,
**not** from your working copy — so a release cut while the live
auto-updater has moved vendor/ ahead of the lock would ship the *older*
version and push every site backwards. The full release runbook, including
the step that's easy to forget (bumping `Version:` in `style.css`, without
which Warden offers the release to nobody), is in `bin/AGENT.md`.

There are four distinct update mechanisms across the dependencies, and
Timber/Twig notably have **no** auto-update path — they reach a site only
in a theme release. Table and reasoning in `inc/dependencies/AGENT.md`.

What it does, in order: **runs the full test suite first and refuses to
build if anything fails** (this is the actual release gate — see
`tests/AGENT.md`), then copies the theme into a temp directory excluding
`tests/`, `phpunit.xml.dist`, `.phpunit.result.cache`, `bin/`, and
`.git`, runs `composer install --no-dev` inside that copy (so `vendor/`
only ends up with Timber/SCF/ASE — `phpunit`, `wp-phpunit`, and
`yoast/phpunit-polyfills` never make it in), **separately copies
`vendor/mcp-adapter/` in afterward** (it isn't in `composer.json` at all
— see `inc/dependencies/mcp-adapter/AGENT.md` for why — so the
`composer install` step never touches it; a real bug caught by actually
building and unzipping a real package rather than just reading the
script: without this step, MCP Adapter was silently missing from every
shipped zip, which would fatal-error on every page load since
`inc/dependencies/mcp-adapter/mcp-adapter.php` does an unconditional
`require_once` on that missing file), and zips the result as
`devbase_parent_theme-{version}.zip` (version read from `style.css`) next
to the theme folder.

**Real, verified size**: the dev working copy is ~64MB, but that's almost
entirely `vendor/`'s dev-only test tooling (`wp-phpunit` + `phpunit` +
their support libraries, ~27MB combined) that never ships. The actual
packaged zip — confirmed by building one and inspecting its contents
directly — is ~8MB compressed / ~34MB uncompressed, normal for a theme
bundling a full ACF/SCF-class plugin (SCF alone is the single largest
piece at ~23MB in the dev copy, mostly bundled block-editor JS/assets —
typical for that class of plugin) plus a full admin-enhancement suite,
Twig, and MCP Adapter. Any future dependency added to `vendor/` outside
Composer's management (like MCP Adapter) needs the same explicit-copy
treatment here, or it will silently vanish from the shipped zip the same
way — this is exactly the kind of gap that only building and unzipping a
real package catches, not reading the script.

```
cd wp-content/themes/devbase_parent_theme
./bin/build-zip.sh
```

If a new file/folder should never ship in a distributable zip (another
dev-only tool, a local secrets file, etc.), add it to the `rsync
--exclude` list in `bin/build-zip.sh` in the same change that adds it —
same rule as everything else in this theme: the thing that could go
stale gets updated alongside whatever made it stale.

## Where to go from here

- **The client child theme — what goes there, and how to start a new
  site:** `../wp_devbase_child_theme/AGENT.md`
- **How the theme boots, file by file:** `inc/AGENT.md`
- **Warden-delivered updates for both themes:** `inc/updates/AGENT.md`
- **The Licence sub-page (Warden config, keys, update status):** `inc/theme-settings/licence/AGENT.md`
- **SCF, ASE, MCP Adapter, and the self-updating system:** `inc/dependencies/AGENT.md`
- **MCP content abilities — list/get/create/update posts and options-page
  values via MCP, generic across every post type:** `inc/abilities/AGENT.md`
- **The Theme Settings admin area:** `inc/theme-settings/AGENT.md`
- **The SMTP Settings sub-page specifically:** `inc/theme-settings/smtp/AGENT.md`
- **The Schema Markup sub-page (Organization, Website, Breadcrumb):** `inc/theme-settings/schema/AGENT.md`
- **CPT archive fields + archive links in the link picker:** `inc/archive/AGENT.md`
- **Centralized form logging + notification dispatch (Registered Forms, Form Data, Form Destinations, data retention, admin-only capabilities):** `inc/forms/AGENT.md`
- **The Form Destinations sub-page specifically:** `inc/theme-settings/destinations/AGENT.md`
- **The Miscellaneous sub-page (Retention Policy, System Status, MCP Access):** `inc/theme-settings/miscellaneous/AGENT.md`
- **Public, dev-facing helper functions (`devbase_form_nonce_field()`, `devbase_form_build_content()`, `devbase_form_log_submission()`):** `inc/helpers/AGENT.md`
- **How translations work, and adding a new one:** `languages/AGENT.md`
- **The front-end placeholder template (this theme's only Twig template so far):** `views/AGENT.md`
- **Automated tests: setup, how to run/extend them:** `tests/AGENT.md`
- **Dev-only scripts (packaging a zip):** `bin/AGENT.md`
