# inc/theme-settings/licence/ — Licence sub-page

Where a site's Warden connection and licence keys are entered, and where
"is this site actually receiving updates" is answerable.

```
licence/
  AGENT.md       — this file
  settings.php      — registers the sub-page, its three-tab field group,
                        and the accessors inc/updates/ reads
  status.php          — renders the read-only Status tab
  check-ajax.php        — the "Check for Updates Now" AJAX handler
  check.js                — that button's click handling
```

Same self-registering `acf/init` + `acf_add_options_sub_page()` +
`acf_add_local_field_group()` pattern as every other sub-page under Theme
Settings (`../smtp/AGENT.md` is the reference example), and the same split
within the folder: `settings.php` defines fields and reads values,
`status.php` only presents.

**`status.php` must be required BEFORE `settings.php`** in `functions.php`
— `settings.php` calls `devbase_licence_status_html()` while building its
field group, so the function has to exist by then. This is the one load
order in this folder that isn't arbitrary.

Everything that acts on these values lives in `inc/updates/` — see
`../../updates/AGENT.md`. Nothing else in the theme reads these options
directly.

## The fields

### Connection tab

- **Warden URL** (`warden_base_url`) — base URL of the Warden deployment,
  no trailing path.
- **Warden Public Key** (`warden_public_key`) — base64 Ed25519 public key
  from that licence's page in Warden. Every response is verified against
  it; **with no key, update checks are skipped entirely** rather than
  trusted unverified.
- There is **no auto-update switch**: theme updates always install themselves
  (`devbase_enable_theme_auto_updates()` returns true). The field
  `warden_auto_update` was removed; a leftover stored value is ignored.

### Licence Keys tab

- **Base Theme Licence Key** (`warden_parent_licence`), shown masked (an ACF `password` field)
- **Child Theme Licence Key** (`warden_child_licence`)

Two fields rather than a repeater because there are exactly two themes in
this model — parent and child — and `inc/updates/` detects which is which
from `get_template()`/`get_stylesheet()` rather than from configuration.
An empty key disables checks for that theme, which is the correct state
for the base theme on a site that only licences the child, or for a dev
site with no Warden at all.

### Status tab

A **Check for Updates Now** button, then a read-only table recomputed on
every page load.

#### The button

**No client site needs this.** Every check it runs is already on a daily
WP-Cron event that fires on any front-end request (see
`../../dependencies/AGENT.md`'s "The check cadence"), and theme updates
install themselves through WordPress's own auto-update. A site left
completely alone stays current.

It exists for **us**: verifying a licence, or a release published a minute
ago, without waiting up to 24 hours for the next cron run — or 12 hours
for Warden's own response cache, which the button deliberately bypasses by
passing `$force = true` to `devbase_warden_check()`. A "check now" that
returned a cached answer would be worse than no button, because it would
look like confirmation while telling you nothing.

Same framing as SMTP Settings' "Send Test Email" and the Retention
Policy's "Run Purge Now", and the same important property: it calls
**exactly** the functions the cron jobs call — `devbase_run_dependency_check()`,
`devbase_run_mcp_update_check()`, `devbase_warden_check()` — so a success
here is real evidence about the scheduled path, not a separate code route
that might behave differently.

It reports one line per check: both vendored-dependency checks, then each
theme track (no licence key / unreadable version / check failed with the
real reason / update available / licence invalid / up to date). The
dependency lines reuse the same `*_last_check_message()` functions the
System Status tab shows, so both screens agree.

**It also clears `update_themes` and re-runs `wp_update_themes()`.** Without
that, a newly-discovered update would sit in our own state but not appear
on Appearance > Themes or Dashboard > Updates until core's own twice-daily
check happened to run — which is indistinguishable from the button having
done nothing.

Uses **saved** settings, not whatever is typed into the form but unsaved —
same as the SMTP test button, same reasoning (serialising in-progress form
state into the request adds real complexity for a marginal convenience).

#### The status table

Read-only, recomputed on every page load — a live view, never a saved
setting, and it never triggers a check itself. One row per track: the
theme, its installed version, its licence state (validity, status, expiry,
any message Warden returned), and whether an update is pending.

**Why this tab exists rather than trusting Appearance > Themes:** an
update that never appears has several completely different causes — no
licence key entered, a key that's expired or suspended, a Warden URL typo,
a signing key mismatch, or simply already being on the latest release.
From the themes screen all of those look identical to "nothing to do".
Same reasoning as the Retention Policy tab's staleness indicator
(`../miscellaneous/AGENT.md`): without visibility you cannot tell "nothing
needed doing" apart from "this silently stopped working weeks ago".

A row also surfaces a *stale* error alongside a good cached response —
that's the fail-soft path in `../../updates/warden.php` having kicked in,
and it should be visible rather than looking like a healthy check. The
cached licence state deliberately keeps displaying even after that
response has stopped authorising installs (see the offer window in
`../../updates/AGENT.md`), because "here's what we last knew, and we
can't currently reach Warden" is more useful than a blank row.

## Why the URL and signing key are fields, not constants

Both could have been `define()`d in code, like `DEVBASE_CHECK_ACCESS_KEY`
once was for the old dependency Worker. They're fields because a Warden
deployment's Worker URL can be migrated and its signing key can be
rotated, and **neither should require shipping a new theme release to
every client site** — which is exactly the chicken-and-egg problem of
hardcoding the address of your own update service into the thing that
service updates.

The old dependency Worker solved the same problem with a far more
elaborate mechanism: a stored list of the last three known-good URLs,
most-recently-good first, with automatic promotion of whichever responded
and a `next_check_url` field for self-migration. That was removed along
with the rest of that system. Two editable fields do the same job here,
because unlike that Worker there's an admin UI to put them in.

## Verified

Confirmed live on this dev site with the child theme actually activated:
the sub-page registers under Theme Settings, all seven fields and three
tabs are present in the field group, and the Status tab renders a row for
both the parent and child theme. Both rows correctly reported "No licence
key entered" — the true state here, since this dev site has no Warden
deployment to point at.

`tests/Test_Warden_Updates.php` sets these fields with
`update_field( ..., 'option' )` and exercises everything that reads them;
see `../../updates/AGENT.md`'s "Verified" section.

The **button** was verified end to end the way WordPress's own
`WP_Ajax_UnitTestCase` does it — as a real administrator, with a real
nonce, firing `wp_ajax_devbase_check_updates` and filtering
`wp_die_ajax_handler` to throw so the JSON response could be read instead
of the process exiting. Both paths checked: the normal one (reporting both
dependency checks current and both themes having no licence key, which is
this site's true state) and the failure one, with a deliberately
unresolvable Warden host configured — which correctly surfaced
`cURL error 6: Could not resolve host` per track rather than a generic
"check failed", and left the previous state intact per the fail-soft rule.

**Not yet verified against a real Warden deployment** — no URL or licence
key exists for this dev site, so the signed round-trip has only been
exercised against a locally-generated keypair, never against the real
Worker. Worth a real end-to-end pass before a client go-live, same bar as
SMTP's real-credentials caveat in `../smtp/AGENT.md`.

## Where field values actually live

The literal `'option'` store, like every other sub-page under
`inc/theme-settings/` — not this page's own `menu_slug`. See
`../schema/organization/AGENT.md` for the real bug caused by getting that
wrong once (values saved to the shared store but read back from a
page-specific one, so a toggle silently did nothing).

The page opens with a red warning ("If you are not the developer, please do not edit
anything on this page"): an ACF `message` field above the tabs.

The two licence keys are `password` fields: masked on screen so they are not
read off the page, stored and read exactly as before (`get_field()` is unchanged).
The Warden public key stays visible on purpose: it is public.

## Where to go from here

- **What actually consumes all of this:** `../../updates/AGENT.md`
- **The pattern this sub-page follows:** `../smtp/AGENT.md`
- **Back up to the Theme Settings admin area:** `../AGENT.md`


## Brand Relay tab and locked fields (1.1.0)

A fourth tab, **Brand Relay**, holds the only two editable fields: `relay_base_url` and `relay_site_key`.
The four Warden fields (URL, public key, both licence keys) are rendered `disabled` by an
`acf/prepare_field` filter in `settings.php`, matched on the field KEY. They are filled by
`inc/updates/relay-sync.php`; see `../../updates/AGENT.md`. If `DEVBASE_RELAY_URL` or
`DEVBASE_RELAY_SITE_KEY` is defined, its field is locked too. `status.php` shows a sync line above the table.
