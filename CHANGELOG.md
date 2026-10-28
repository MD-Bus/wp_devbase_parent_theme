# Changelog

All notable changes to this theme are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The version in `style.css` is what the update system compares against —
see the README's Versioning section. Every release needs a section here.

## [Unreleased]

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
