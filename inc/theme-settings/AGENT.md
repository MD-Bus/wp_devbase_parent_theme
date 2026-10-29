# inc/theme-settings/ — the admin settings area

Everything under **Theme Settings** in wp-admin. One parent options page,
registered via SCF (ACF-compatible), plus sub-pages (Licence, SMTP
Settings, Form Destinations, Miscellaneous, Security, Tracking, Schema
Markup) with more expected to be added over time.

```
inc/theme-settings/
  theme-settings.php   — parent "Theme Settings" options page
  AGENT.md               — this file
  licence/                 — the Licence sub-page (Warden + theme updates)
    AGENT.md                 — full breakdown of this sub-page, see there
    settings.php
    status.php
    check-ajax.php
    check.js
  smtp/                    — the SMTP Settings sub-page
    AGENT.md                 — full breakdown of this sub-page, see there
    settings.php
    mailer.php
    test.php
    test.js
  destinations/            — the Form Destinations sub-page
    AGENT.md                 — full breakdown of this sub-page, see there
    settings.php
  miscellaneous/           — catch-all sub-page (Retention Policy,
                               System Status, MCP Access)
    AGENT.md                 — full breakdown of this sub-page, see there
    settings.php
    purge-ajax.php
    purge.js
  security/                — the Security Settings sub-page
    AGENT.md                 — full breakdown of this sub-page, see there
    settings.php
    verify.php
    security.js
  tracking/                 — the Tracking sub-page
    AGENT.md                  — full breakdown of this sub-page, see there
    settings.php
    inject.php
  schema/                   — the Schema Markup sub-page
    AGENT.md                  — full breakdown, see there (indexes its own
                                 organization/, website/, breadcrumb/ types)
    settings.php
```

## The pattern: one subfolder per sub-menu

`smtp/` is the template to copy for any future sub-menu under Theme
Settings, not a one-off. When adding a new one:

1. Create `inc/theme-settings/<name>/`, with its own `AGENT.md` inside
   documenting just that sub-menu — the same way `smtp/AGENT.md` does.
2. Inside it, at minimum a `settings.php` that registers the sub-page
   (`acf_add_options_sub_page()`) and its field group
   (`acf_add_local_field_group()`), both hooked to `acf/init`.
3. If the sub-page needs its own logic beyond just storing settings (like
   SMTP's actual mail-sending), put that in its own file inside the same
   subfolder (`mailer.php` is SMTP's example) — keep "define the settings
   UI" and "act on the settings" as separate files even within one
   subfolder, so each file has one job.
4. Require every new file explicitly from `functions.php`, same as
   everything else in this theme — no directory auto-loading anywhere.
5. Link the new subfolder's `AGENT.md` from this file's list above.

This mirrors why `inc/dependencies/` exists as its own subfolder too (see
the parent `inc/AGENT.md`, and `inc/dependencies/AGENT.md`): once an area
has more than one or two files, grouping them by subsystem — with its own
doc — keeps things scannable instead of a flat, undifferentiated pile.

## `theme-settings.php` — the parent page

Registers the top-level "Theme Settings" options page via
`acf_add_options_page()`. Deliberately empty of fields — it exists only so
sub-pages have a parent to nest under (`parent_slug => 'theme-settings'`).

**The menu icon** is the Boostway "B" mark
(`assets/icons/devbase-b-icon.svg`), read from disk and passed as a
base64 `data:image/svg+xml;base64,...` URI via `icon_url`. WordPress admin
menu icons need an inline data URI, not a plain file URL — using a real
file path would work visually but costs an extra HTTP request for a tiny
sidebar icon on every admin page load, for no benefit. Guarded with
`file_exists()` first; falls back to an empty string (WordPress's own
default icon) if the SVG file is ever moved or deleted, rather than
breaking the menu.

**The title is wrapped in `__()`** for translatability (see
`languages/i18n.php` and `languages/AGENT.md` for how a translation
actually gets loaded) — this applies to every field label in this whole
directory, not just this one page; it's just documented once here.

## Licence

See **`inc/theme-settings/licence/AGENT.md`** for the full breakdown: the
Warden endpoint and signing key, one licence key per theme track (this
parent theme and the active child theme), the auto-update toggle, and the
read-only Status tab that makes "is this site actually receiving updates"
answerable, plus a "Check for Updates Now" button for forcing every check
immediately rather than waiting out the daily schedule. Everything that
acts on those values lives in `inc/updates/` — see
`inc/updates/AGENT.md`.

## SMTP Settings

See **`inc/theme-settings/smtp/AGENT.md`** for the full breakdown: the
settings UI, the required-field-only-when-enabled behavior, the mail
transport logic, the test-email button, and a real ~13-second bug this
surfaced (network throttling, not a code issue — worth reading before
assuming something built in this theme is slow).

## Form Destinations

See **`inc/theme-settings/destinations/AGENT.md`** for the full
breakdown: one repeater where each row is a self-contained destination
(SMTP or webhook, picked by a per-row Type field) plus an "Applies To"
multi-select naming which registered form(s) route there.

## Miscellaneous

See **`inc/theme-settings/miscellaneous/AGENT.md`** for the full
breakdown: the Retention Policy tab (off by default) that permanently
deletes old `form_data` submissions on a schedule, plus a
manual "Run Purge Now" button and a "last run" status message that flags
a stale run — worth reading for why that status message exists (WP-Cron
only fires on a page load, and some hosts disable it entirely).

## Security Settings

See **`inc/theme-settings/security/AGENT.md`** for the full breakdown:
the Turnstile/reCAPTCHA tabs, how "only one provider active at a time" is
actually enforced (server-side, not just the JS toggle), and the
`verify.php` functions any form in this theme should call instead of
talking to either provider directly.

## Tracking

See **`inc/theme-settings/tracking/AGENT.md`**: GTM/GA4 IDs and raw
head/footer script fields, and the `wp_body_open()` requirement for GTM's
`<noscript>` tag.

**No cookie consent here any more** — a banner and Google Consent Mode
wiring used to live in `tracking/consent/`, removed 2026-10-11. See the
Tracking doc for where it went and why.

## Schema Markup

One sub-page (`schema-settings`), with each schema type in its own
subfolder underneath: **Organization**, **Website**, and **Breadcrumb** —
a deliberately short, curated list ("always need it, cheap to build"),
not a general-purpose mapping system; see
**`inc/theme-settings/schema/AGENT.md`** for that reasoning and the
pattern for adding a new type on a per-project basis, and each type's own
`AGENT.md` for its specifics — notably Organization's **Locations**
repeater with a per-row "Main" toggle (the multi-location lesson noted
below, actually applied this time), a real `post_id`/`'option'` storage
bug it surfaced, and a real testing pitfall Breadcrumb surfaced (a bare
`do_action('wp_head')` from the CLI doesn't make `is_singular()` etc.
reflect a specific URL — `wp()` has to be called explicitly first).

## No Company Info sub-menu (tried, then removed)

A Company Info sub-page (name, phone, email, a repeater of locations for
multi-location businesses, social links) was built, iterated on, and then
deliberately removed. If this comes back later, remember what was
learned: address needs to be a repeater of full postal fields (a company
can have more than one location), not a single free-text field, and plain
WordPress shortcodes (`[company_phone]`, etc.) don't fit multi-value data
like a location repeater — that was tried and removed too.

## No Navigation sub-menu (on purpose)

A "Main Header Menu / Main Footer Menu repeater" sub-page was built and
then deliberately removed. Nav structure varies too much per client site
— some need a flat list, some need mega menus, dropdowns, multi-level
nesting — for one generic repeater to fit every site this theme gets
copied into. Use WordPress's own menu system (`register_nav_menus()` +
`wp_nav_menu()`, or Timber's menu object) per-site instead, built to that
site's actual needs, once a header/footer template exists to hold it.
