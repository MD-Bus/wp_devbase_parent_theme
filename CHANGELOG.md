# Changelog

All notable changes to this theme are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The version in `style.css` is what the update system compares against —
see the README's Versioning section. Every release needs a section here.

## [Unreleased]

## [1.8.1] — 2026-10-29

### Fixed

- **CI failed on PHP 8.2**: the test tooling's `tests/composer.lock` had been resolved on PHP 8.4 and pinned
  doctrine/instantiator 2.1.0, which requires PHP 8.4. The lock is removed (and ignored), so the test tools
  resolve for whichever PHP runs them. Tooling only; no code change on sites.

## [1.8.0] — 2026-10-29

### Added

- **Continuous integration** (`.github/workflows/tests.yml`), on every push to `main`, pull request and
  release tag. **Tests**: a fresh, latest WordPress, a MySQL service, `composer install` from `composer.lock`
  (so a Dependabot pull request is tested with the versions it proposes) and the suite on PHP 8.2 and 8.4.
  **Release-ready**: `bin/check-vendor-drift.sh`, red on a Dependabot pull request until `vendor/` is
  committed. CI only reports; `bin/release.sh` stays the gate, since Warden publishes a tag on the push.
  Rehearsed locally in a fresh WordPress 7.1.4, including a real pending update (symfony/polyfill-mbstring
  1.43.0 -> 1.43.3): drift reported, suite passing with the new version. Tooling only; no code change on sites.

## [1.7.0] — 2026-10-29

### Removed

- **Sites no longer update Secure Custom Fields, Admin Site Enhancements or the MCP Adapter themselves.** The
  daily checks (`inc/dependencies/dependency-updater.php`, `inc/dependencies/mcp-adapter/mcp-adapter-updater.php`)
  are gone. A theme update replaces the whole theme folder, `vendor/` included, so whenever a release carried an
  older copy than a site had fetched for itself, installing it downgraded that plugin (1.6.0 did), and a site
  could run plugin versions never tested with the theme. The bundled dependencies now change only in a theme
  release: every site runs exactly what the release shipped and the test suite passed with.
- Their status lines on Theme Settings > Miscellaneous > System Status, and their part in **Check for Updates
  Now** (which now checks Warden only).

### Added

- **System Status > Bundled Dependencies**: the versions this release ships (SCF, ASE, MCP Adapter, Timber),
  read from `vendor/`.
- A one-off cleanup on `init` that removes the two retired cron events and their stored status from sites that
  ran an earlier version.
- **Dependabot now covers SCF and ASE too**, alongside Timber, Twig and Symfony, and checks **daily** (was
  weekly, with the two plugins ignored).
- `.github/workflows/mcp-adapter-release.yml`: a daily GitHub workflow that opens an issue when the MCP Adapter
  (not on Packagist, so invisible to Dependabot) has a newer release than the committed copy. One issue per
  version, open or closed, so closing an issue skips that version. `.github/` is not in the package.
- 5 tests (`tests/Test_Bundled_Versions.php`); the 21 tests of the removed updaters are removed with them.
  Checked on the OCWOF site: one request on the new code cleared both retired events and their records.

## [1.6.1] — 2026-10-29

### Fixed

- **1.6.0 shipped older plugins than sites already run, so installing it downgraded them.** A theme update
  replaces the whole theme folder, `vendor/` included, and 1.6.0 carried Secure Custom Fields 6.9.5, Admin Site
  Enhancements 9.2.1 and MCP Adapter 0.7.0, while each site's daily dependency check had already moved them to
  6.9.7, 9.3.0 and 0.7.1. Installing 1.6.0 moved them back until the next daily check (a newer plugin's
  database changes running under an older one). This release ships the current versions: SCF 6.9.7, ASE 9.3.0,
  MCP Adapter 0.7.1. Full suite passing; checked on the OCWOF site.

## [1.6.0] — 2026-10-29

### Added

- **MCP can build and read pages from the site's blocks** (`inc/abilities/blocks.php`). The parent knows no
  site's blocks: it discovers what the active child registers.
  - `devbase/list-blocks`: the blocks the editor allows for a post type (its own `allowed_block_types_all`
    allow-list), with title, description, category and whether each can be written.
  - `devbase/get-block-schema`: one block's fields from its field group, with required, default, choices,
    repeater min/max, sub-fields and the shape of each value (image = attachment ID, link = object, ...).
  - `devbase/create-post` and `update-post` take `blocks`, `[{ "block": "acf/<name>", "fields": {...} }]`, as the
    page's whole content (not combined with `content`). The markup is produced by Secure Custom Fields' own
    save logic captured in memory (`acf_setup_meta()`), so the flat storage (`items: 2`, `items_0_title`,
    `_items_0_title`) is exactly what the editor writes. Everything is checked against the field group first:
    an unknown or disallowed block, a non-ACF block, an unknown field or sub-field at any depth, a choice that
    isn't one, a repeater that isn't a list, an image that isn't an attachment, all refused with the exact
    path, and nothing is saved.
  - `devbase/get-post`, `create-post` and `update-post` responses carry `built_from_blocks` and `blocks`, ACF
    blocks as `{ block, fields }` in the shape the writer takes, other blocks as `{ block, markup }`. Content
    written this way round-trips identically; a page saved in the editor renders the same after a round trip
    (every OCWOF page checked). Sub-fields never stored are left out of a read, so a round trip doesn't add
    empty values.
- **`devbase/upload-media`** (`inc/abilities/media.php`): adds one file from a public URL (fetched with
  `wp_safe_remote_get`, so private and local addresses are refused) or base64 data, with alt text, and returns
  its attachment ID. **Off by default**: Theme Settings > Miscellaneous > MCP Access > **Allow Media Uploads**.
  Needs `upload_files`; WordPress decides which file types are allowed. Base64 limited to 15 MB
  (filter `devbase_abilities_media_max_bytes`).
- The new switch is protected like the allow-lists: never readable or writable through MCP
  (`devbase_abilities_protected_option_fields()`, now the single list both options abilities use).
- 29 tests (`tests/Test_MCP_Abilities_Blocks.php`). Also checked on the OCWOF site's real blocks and pages.

### Changed

- `devbase_abilities_shape_fields()` takes an optional `$detailed` flag (used by the block schema). Without it
  the output of the existing schema abilities is unchanged.
- `tests/bootstrap.php` creates SCF's local meta store before any test runs. Created inside a test, it lost its
  hooks when the test framework restored them, and block values read back as defaults in later tests. Test-only.

## [1.5.0] — 2026-10-29

### Added

- **Dependabot** (`.github/dependabot.yml`): a weekly pull request when Timber, Twig or their Symfony
  dependencies have a new version (grouped into one), and a monthly one for the test tooling in `tests/`.
  The two wpackagist plugins are ignored, since sites update those daily on their own. `.github/` is not in
  the package.
- `bin/check-vendor-drift.sh` now also checks the other direction: every package in `composer.lock` must be
  in `vendor/` at the same version (read from Composer's `vendor/composer/installed.json`), and nothing extra.
  A Dependabot pull request changes the lock but not the committed `vendor/`, which is what a tag ships, so
  `bin/release.sh` and `bin/build-zip.sh` now refuse until `composer install` has been run and `vendor/`
  committed with the lock.

### Changed

- When the tag already exists, `bin/release.sh` now suggests `minor` for anything added and `patch` for
  fixes only, instead of always `patch`.

## [1.4.2] — 2026-10-29

### Added

- `bin/release.sh`, the gate before a release tag. It refuses unless the tree is clean and on `main`,
  `style.css` has an X.Y.Z version newer than every existing tag (and not already tagged locally or on
  `origin`), `CHANGELOG.md` has a dated, non-empty section for it directly under an empty `[Unreleased]`,
  the branch is not behind `origin`, `vendor/` matches `composer.lock`, and the test suite passes. Then,
  after a confirmation (`--yes` to skip, `--dry-run` to tag nothing), it creates the annotated tag and
  pushes the branch and the tag in one atomic push; a failed push removes the local tag. Tooling only:
  `bin/` is not in the package, so this release changes no code on a site.

### Changed

- The vendor drift check moved from `bin/build-zip.sh` into `bin/check-vendor-drift.sh`, shared by
  both scripts. Same check, same output.

## [1.4.1] — 2026-10-29

### Changed

- **reCAPTCHA no longer loads with the page.** Google's script is about 350 KiB, plus a frame, a stylesheet and a
  font, and it was loaded on every page that has a form, including a newsletter box in the footer. It now loads the
  first time a visitor touches a form that has a token field (focus, click, touch or key press), and a submit sent
  before a token has arrived is held for a moment, the token fetched, and the submit sent again. Measured in a real
  browser: a page view makes no request to Google at all (before, four or more, about 450 KiB); clicking into the form
  loads it. Only `assets/js/recaptcha-v3.js` is enqueued now, deferred, in the footer; Google's `api.js` is no longer
  enqueued by PHP. This also stops Google's own console message ("requestStorageAccess: Permission denied") appearing on
  every page: it comes from Google's frame and now only shows once a form is used.
- 2 tests changed to match.

## [1.4.0] — 2026-10-29

### Added

- **SEO tab on every post type archive.** An archive has no post, so no SEO plugin can attach a title and
  description to it. Each Archive options page now gets an **SEO** tab with **Search Title** (`seo_title`) and
  **Search Description** (`seo_description`): a real tab inside the field group that already targets the page,
  or a small "SEO" box of its own where there is none (`inc/archive/seo.php`).
- The theme prints them on the archive's own URL, only when filled. The title replaces the document title
  (with " – Page N" after the first page). The description is printed on the first page only, by buffering
  `wp_head` for archive requests and replacing any existing description tag, or adding one, so there is never
  a duplicate whether or not an SEO plugin is active. Open Graph and Twitter title and description tags that
  something has already printed are updated to match; none are added.
- The fields are visible to MCP (`devbase/update-options` on `archive-{post_type}`).
- Filter `devbase/archive_seo/enabled` to switch it off for a post type.
- 18 tests.

## [1.3.0] — 2026-10-29

### Changed

- **reCAPTCHA is now v3 (score-based, invisible) instead of the v2 checkbox. A v2 key no longer works.**
  A site that uses reCAPTCHA must enter a v3 key on Theme Settings > Security > reCAPTCHA (or switch to
  Turnstile). The theme rendered the v2 checkbox widget, so a v3 key, or any key made in Google Cloud as
  score-based or Enterprise, showed "ERROR for site owner: Invalid key type".
- Forms now carry a hidden `g-recaptcha-response` field filled by the new `assets/js/recaptcha-v3.js`, which
  keeps a fresh one-use token in it (refreshed every 90 seconds, when the tab returns to the front, and after
  each submit). Google's script is loaded as `api.js?render=<site key>`.
- The server accepts a token only when Google reports success, the token's action matches (`submit`,
  filterable: `devbase/security/recaptcha_action`), and the score is at least the minimum. An answer with
  no score fails.

### Added

- **Minimum Score** setting on the reCAPTCHA tab (0.1 to 1, default 0.5).
- 13 tests for the v3 widget, script and verification.

## [1.2.8] — 2026-10-29

### Changed

- `screenshot.png` now says "Built by Momar" at the bottom right. No code change.

## [1.2.7] — 2026-10-29

### Added

- `screenshot.png` (1200x900): the preview shown for the theme in Appearance > Themes. It was missing, so the
  theme showed an empty box. It shows the parent/child layering, with no version number so it never goes stale.

## [1.2.6] — 2026-10-29

### Changed

- The theme's display name is now "Dev Base Parent Theme (DO NOT REMOVE)", so nobody deletes it from
  Appearance > Themes thinking it is unused: the active child theme depends on it. Display name only; the
  folder name `wp_devbase_parent_theme`, which is what the child's `Template:` header and Warden use, is
  unchanged.

## [1.2.5] — 2026-10-29

### Changed

- A release tag now publishes the same files `bin/build-zip.sh` does. Warden publishes GitHub's archive of the
  tag, so the development files are marked `export-ignore` in a new `.gitattributes`: `tests/`, `bin/`,
  `docs/`, `.github/`, `phpunit.xml.dist`, the development `README`/`CHANGELOG`/`ROADMAP`/`SECURITY`,
  translation sources and the dotfiles. Before, a tag shipped the whole repository to client sites, so those
  files were publicly readable on them. `License.md` still ships. No code change.

## [1.2.4] — 2026-10-29

### Fixed

- **MCP options abilities wrote to the wrong storage for the Archive pages.** `devbase/update-options`
  and `devbase/get-options` always used the shared `option` store, but the Archive pages created for each
  post type with an archive keep their values under their own storage (their menu slug), which is what
  their templates read. A value written through MCP to an Archive page was saved where nothing reads it,
  the same-named fields of different archives (`hero_title`, `hero_image`, ...) overwrote each other there,
  and the call reported success because it read the value back from the same wrong place. Both abilities
  now use the page's own storage, and other options pages are unaffected. Nothing saved by the old
  behaviour is migrated: write the Archive pages' values again through MCP (or the admin).
- 4 new tests that fail on the old code.

## [1.2.3] — 2026-10-29

### Fixed

- The credit markup now matches Brand Relay's reference snippets: `message <a href rel>text</a>`, with
  `rel` only when non-empty and no `target`. It previously added `target="_blank"` and `rel="noopener"`
  to every link.
- A `status: "unavailable"` answer now keeps the last good credit instead of being stored, and a
  `show: false` answer without a link now replaces the old credit instead of being rejected (which left
  a credit the brand had turned off showing).

## [1.2.2] — 2026-10-29

### Removed

- The "Current Credit" status block on Theme Settings > Miscellaneous > Credit ("Downloaded … ago",
  the credit preview, the last error). The tab is now just the one field; the credit downloads and is
  used without anything about it appearing in the admin. A failed download is still written to the PHP
  error log.

## [1.2.1] — 2026-10-29

### Added

- `DEVBASE_CREDIT_BRAND_URL` constant: sets the credit's brand address in code and removes the
  Credit tab from Theme Settings > Miscellaneous, so a client with admin access doesn't see it. The
  credit itself is unchanged. Filters `devbase/credit/brand_url` and `devbase/credit/hide_settings`.

## [1.2.0] — 2026-10-29

### Added

- **Brand credit** (`inc/theme-settings/miscellaneous/credit.php`). Theme Settings > Miscellaneous >
  **Credit** takes one setting, a brand's public Brand Relay address. The theme downloads the
  brand's public details (on save, shortly after first set, then daily; Brand Relay's `ttl` is a
  minimum gap), keeps a local copy in one non-autoloaded option, and builds the credit from it.
  `devbase_credit()` returns the data and `devbase_credit_html()` returns the escaped markup.
- Pages never wait on the network: rendering reads the saved copy only. A failed download keeps the
  last good copy; a brand marked `show: false` shows no credit; a changed address drops the old copy.
- The saved copy is built from an allowlist of fields, never stored whole, so `params`, `rules`,
  `embed` and private values cannot reach it, and remote HTML/CSS is never printed.
- 17 PHPUnit tests, including one proving a licence key in the response never reaches the stored copy.

## [1.1.0] — 2026-10-29

### Added

- **Provisioning from Brand Relay** (`inc/updates/relay-sync.php`). The Warden URL, Warden public
  key and both licence keys are delivered by [Brand Relay](https://github.com/momardia54/brand-relay)
  and written into the Licence page, so a licence can be set or fixed from a distance and a value
  removed on the site is restored by the next sync. Brand Relay params use the same names as the
  fields (`warden_base_url`, `warden_public_key`, `warden_parent_licence`, `warden_child_licence`).
  The Warden URL and public key are fill-only: accepted when empty, never overwritten.
- **Ping endpoint** `POST /wp-json/devbase/v1/sync`. It carries no licence data and only makes the site
  pull from its configured Brand Relay. It requires the `Origin` to equal the Brand Relay URL and an
  HMAC-SHA256 signature (derived from the site key) over timestamp, nonce, origin and body, with a
  five-minute window and single-use nonces. Rate limited to 10 requests a minute per IP (signed or
  not) and one sync per 30 seconds. The route exists only when a Brand Relay URL and site key are set.
- Theme Settings > Licence > **Brand Relay** tab: Brand Relay URL and Brand Relay Site Key, the only
  two fields to fill in. `DEVBASE_RELAY_URL` and `DEVBASE_RELAY_SITE_KEY` constants win over the
  fields, which then show locked, so a site's users cannot clear them.
- A sync status line on the Licence page, and a sync step in "Check for Updates Now".
- 21 PHPUnit tests for the sync and the ping.

### Changed

- The four Warden fields on the Licence page are now always shown but not editable, and the red
  message reads "These fields are provisioned automatically. Please contact the developer for any
  licence-related issue."
- There is no scheduled sync. It runs only on a signed ping, when an administrator saves the Licence
  page, or on "Check for Updates Now".

## [1.0.1] — 2026-10-28

### Changed

- Theme Settings > Licence: the two licence keys are now masked (password fields), and the
  page opens with a red warning not to edit it and to contact the developer for any licence issue.

### Removed

- The "Install Updates Automatically" setting. Theme updates always install themselves; a
  leftover stored value is ignored.

### Fixed

- Updates from Warden were never offered: `devbase_warden_pending_update()`
  read `update.version` and `update.download_url` at the top level, but Warden
  documents (and sends) `update.available` with the release nested under
  `update.latest`. A real release showed as "Up to date" on the Licence status
  tab and in WordPress's update list. It now reads the documented shape, with
  tests. Sites already running the old code must install the fixed
  `inc/updates/warden.php` by hand once; after that updates flow normally.

## [1.0.0] — 2026-10-11

Initial release.

### Added

**Forms**

- `registered_form` and `form_data` post types, with an admin-only
  capability model — editors and authors cannot read submitted data.
- A JSON form registry that syncs into admin posts, extensible by a child
  theme through the `devbase/forms/registered_forms` filter.
- Three public helpers covering the whole submission path:
  `devbase_form_nonce_field()`, `devbase_form_build_content()` and
  `devbase_form_log_submission()`.
- Per-form CSRF nonces, verified before anything else.
- Automatic dispatch to email and webhook destinations, matched per form.
- An optional retention policy, off by default, with a manual run button
  and a staleness indicator.

**SMTP**

- SMTP transport with connection fields that are required only while the
  feature is enabled.
- A test-email button that reports the real PHPMailer error.

**Security**

- Cloudflare Turnstile and Google reCAPTCHA v2, mutually exclusive and
  enforced server-side.
- `devbase_security_widget_html()` and `devbase_security_verify_request()`
  so a form never needs to know which provider is active.

**Tracking**

- Google Tag Manager and GA4 IDs, plus raw header and footer script fields.

**Schema**

- Organization, Website and Breadcrumb JSON-LD, each independently
  toggleable, each emitting its own script tag.
- A `devbase/schema/tabs` filter so a child theme can contribute a type.

**Archives**

- An options page per CPT archive, with isolated storage per archive.
- CPT archives injected into the internal link picker.

**MCP**

- Content abilities — list, read, create and update posts and options
  values — generic across every post type.
- An opt-in allow-list; nothing is exposed until explicitly enabled.
- The allow-list fields are themselves excluded from those abilities, so an
  MCP client cannot widen its own access.

**Updates**

- Vendored Secure Custom Fields, Admin Site Enhancements and MCP Adapter,
  each tracking upstream on a daily schedule that runs from any request
  rather than only in wp-admin.
- Theme updates delivered from [Warden](https://github.com/momardia54/Warden), a self-hosted licensing
  and release service, verified
  with Ed25519 signatures and nonce replay protection, installed through
  WordPress's own upgrader.
- A Licence settings page with per-theme keys, a live status view and a
  manual check button.
- Visible last-run status for every scheduled check, because a check that
  silently stops otherwise looks identical to one with nothing to do.

**Child theme support**

- Filters covering admin-hardening defaults, hidden admin menu items, form
  registration and schema types, so a child theme adds what it needs and
  inherits everything else.

**Internationalisation**

- Full French translations: `fr_FR`, `fr_CA` and `fr_BE`.

**Tooling**

- `bin/build-zip.sh`, gated on both dependency-version drift and the test
  suite.
- `bin/bump-version.sh`, which refuses to move a version backwards.
- 142 PHPUnit tests running against a real WordPress install.

[Unreleased]: https://github.com/momardia54/devbase-parent-theme/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/momardia54/devbase-parent-theme/releases/tag/v1.0.0
