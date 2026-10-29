# inc/theme-settings/schema/organization/ — the Organization schema type

One of three schema types on the Schema Markup sub-page's tabbed field
group — see `inc/theme-settings/schema/AGENT.md` for the parent page, the
`devbase/schema/tabs` mechanism that turns each subfolder into its own
tab, and the other two types (Website, Breadcrumb).

```
organization/
  settings.php   — contributes the Organization tab
  inject.php       — reads the fields, prints the JSON-LD in wp_head
  AGENT.md            — this file
```

## `settings.php`

Registers a `devbase/schema/tabs` filter contribution — not its own
field group (that would render as a separate stacked box instead of a
tab; see the parent `AGENT.md` for why). Its `fields` array becomes the
Organization tab's contents: an enable toggle, then
Name/URL (required only while enabled — same required-field-skipped-
when-hidden mechanism as SMTP/Security, see
`inc/theme-settings/smtp/AGENT.md`), Description, Logo, Slogan, Founding
Date, an "Areas of Expertise" repeater (→ schema.org `knowsAbout`), a
"Social / Profile Links" repeater (→ `sameAs`), and a **Locations**
repeater.

**Logo is restricted to `mime_types: 'jpg,jpeg,png'`, on purpose.** Schema
consumers (Google's Knowledge Panel notably) don't reliably support SVG
logos, so this field intentionally cannot accept the same SVG a site might
use in its actual header — forcing PNG/JPG here avoids a broken schema
logo that looks fine in wp-admin.

**Locations is a repeater with a "Main" toggle per row, not a single
address group.** This was deliberately changed after an earlier version
of this file *did* ship a single `organization_address` group — a
business can have more than one location (see the "Company Info" lesson
in `inc/theme-settings/AGENT.md`: multi-location is common enough that a
single-address field is the wrong shape), so Locations now holds all of
them, each with its own admin-only `Label` for telling them apart. Only
the row flagged **Main** feeds this Organization's schema `address` (see
`inject.php` below) — the other rows are captured for the site's own
future use (a locations page, `LocalBusiness` schema per branch, etc.)
but deliberately not output as part of Organization's JSON-LD, which has
exactly one `address`.

## `inject.php`

Hooked on `wp_head`, sitewide. Only prints once the toggle is on and both
Name and URL are non-empty (URL falls back to `home_url('/')`) — every
other field is optional and only added to the JSON-LD object when
present. `knowsAbout` and `sameAs` are built by filtering each repeater
row down to non-empty values; `address` comes from whichever Locations
row has `is_main` truthy, falling back to the first row if none is
flagged, and is entirely omitted if no Locations rows exist or all
address sub-fields on the chosen row are empty (avoids emitting a bare
`{"@type": "PostalAddress"}`).

**A deliberately-tested XSS-adjacent detail**: `wp_json_encode()` is called
*without* `JSON_UNESCAPED_SLASHES`. That's not an oversight — PHP's default
slash-escaping (`/` → `\/`) is what stops a literal `</script>` typed into
the free-text Description field from closing the `<script>` tag early.
Verified directly: a description containing `</script>` was saved and
confirmed to render as `<\/script>` in the actual output, with exactly one
real closing tag.

## Where field values actually live — read this before adding a field

**Every SCF/ACF options page defaults to `post_id => 'options'`, a single
data store shared by every options page that doesn't override it.**
Nothing in `inc/theme-settings/schema/settings.php` sets `post_id`
explicitly, matching every other sub-page in `inc/theme-settings/`
(Security, SMTP, Tracking) — which is why every `get_field()`/
`update_field()` call in this folder uses the literal string `'option'`,
not `'schema-settings'`. This was caught by testing, not inspection: an
early version called `get_field( 'organization_enabled', 'schema-settings' )`,
which silently always returned empty — confirmed via a live save/read
round-trip before treating this as done. (`inc/archive/` uses a
*different*, equally deliberate pattern — its own `menu_slug` as
`post_id` — because each CPT archive genuinely needs isolated storage; see
`inc/archive/AGENT.md`. Don't copy that pattern here, it would just be
inconsistent with the rest of `theme-settings/` for no benefit.)
