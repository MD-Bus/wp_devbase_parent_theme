# Changelog

All notable changes to this theme are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The version in `style.css` is what the update system compares against —
see the README's Versioning section. Every release needs a section here.

## [Unreleased]

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
