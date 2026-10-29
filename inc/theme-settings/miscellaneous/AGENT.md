# inc/theme-settings/miscellaneous/ — Miscellaneous sub-page

A deliberate catch-all for settings that don't warrant their own
top-level sub-page under Theme Settings — organized into tabs (ACF's
native `type => 'tab'` field, same mechanism `tracking/settings.php`
uses for its Analytics/Custom Scripts/Cookies tabs) so more can be added
later without restructuring. Four tabs so far: Retention Policy, System
Status, MCP Access, Credit.

```
miscellaneous/
  AGENT.md          — this file
  settings.php         — registers the sub-page + field group
  purge-ajax.php          — "Run Purge Now" AJAX handler
  purge.js                   — the button's click handling
  credit.php                    — the Credit tab's download, saved copy and accessors
```

## Tab: Retention Policy

Lets an admin decide how long submitted form data is kept. Off by
default — submissions are retained forever unless explicitly enabled.

- **Enable Retention Policy** (toggle, default off).
- **Retention Policies** (repeater, `layout => 'block'`, conditional on
  the toggle) — each row: **Delete After** (select: 1 Day / 1 Week /
  1 Month / 3 Months / 6 Months / 1 Year) and **Applies To** (multi-select
  of registered forms, same dynamic-choices mechanism as Form
  Destinations' `apply_to` — see `inc/forms/registry/choices.php`).
- **Run Purge Now** (button) + **Status** (message showing when the job
  last actually ran, and flagging a stale run) — see
  `inc/forms/retention.php`'s `devbase_forms_run_retention_purge()` and
  `devbase_forms_retention_last_run_message()` for the logic; this file
  is UI + the AJAX endpoint only.

**Semantics** (documented here since it's the thing an admin configuring
this needs to understand, not just the code): a form covered by zero
rows is retained forever. A form covered by more than one row is purged
at whichever row's period is shortest, since each row's purge runs
independently against whatever posts still exist when it runs — there's
no principled way to prefer one configured row over another.

## Why a manual "Run Purge Now" button, and a visible "Status" message

Two real, related concerns surfaced while building this:

1. **Verification** — the same reasoning as SMTP Settings' "Send Test
   Email" button: waiting a real day to confirm a daily cron job works
   isn't practical, and this theme's own bar is verifying against real
   infrastructure, not just reading the code.
2. **WP-Cron is pseudo-cron** — it only fires on a page load, and some
   hosts disable it entirely (`DISABLE_WP_CRON`) in favor of a real
   system cron hitting `wp-cron.php`. Without visibility, there'd be no
   way to tell "nothing needed deleting today" apart from "this silently
   stopped running three weeks ago." The Status message records every
   actual execution (cron OR the button — both call the same function)
   and flags anything older than ~36 hours as possibly stale, with a
   pointer toward pointing a real cron job at `wp-cron.php` if that
   turns out to be the case.

## Tab: System Status

Pulls five health signals that would otherwise each live somewhere
different (or nowhere at all) into one place — "is anything actually broken
right now" shouldn't require checking SMTP Settings, opening individual
Form Data submissions, and this page's own Retention Policy tab
separately.

- **SMTP** — `devbase_smtp_last_test_message()`
  (`inc/theme-settings/smtp/test.php`): whether SMTP is enabled, and if
  it's ever been tested, when and whether that test succeeded. Purely
  reads what the "Send Test Email" button last recorded (a
  `devbase_smtp_last_test` option, `{time, success, message}`, written
  right alongside that button's existing AJAX response) — this tab never
  triggers a test itself.
- **Form Notifications** — `devbase_forms_dispatch_status_message()`
  (`inc/forms/dispatch/status.php`): checks the most recent 50 dispatch
  attempts across ALL `form_data` submissions (reading each one's
  `_devbase_dispatch_log`, the same postmeta the Submission meta box
  already shows per-post — see `inc/forms/AGENT.md`) and rolls them up
  into a success/failure count plus a table of the most recent failures
  (destination, form, detail). Deliberately a recent WINDOW, not
  all-time — an old failure that's since been fixed shouldn't keep
  showing as a current problem.
- **Retention Policy** — reuses
  `devbase_forms_retention_last_run_message()` from the Retention
  Policy tab above, so the last-run status is visible without switching
  tabs.

- **Dependency Updates** — `devbase_dependency_last_check_message()`
  (`inc/dependencies/dependency-updater.php`): when the SCF/ASE check last
  ran, what it changed, and any failures.
- **MCP Adapter Updates** — `devbase_mcp_last_check_message()`
  (`inc/dependencies/mcp-adapter/mcp-adapter-updater.php`): the same, for
  the GitHub-tracked dependency.

Those last two matter more than they look. SCF, ASE and MCP Adapter keep
themselves current on a daily WP-Cron event, which only fires when the
site gets a request. If that stops — no traffic at all, or a host with
WP-Cron disabled and no system cron replacement — the site silently stops
receiving dependency updates, and **there is no other signal anywhere
that it happened**. This tab is the only place it's visible. Both flag a
run older than 48 hours, same as the Retention Policy line.

All five are read-only, recomputed on every page load — a live status
view, not a saved setting.

## Tab: MCP Access

An opt-in allow-list controlling exactly which post types and options
pages this theme's MCP content abilities (`inc/abilities/`) expose to an
MCP client (e.g. Claude Code connected via the WordPress MCP Adapter,
`inc/dependencies/mcp-adapter/`).

- **Post Types** (`mcp_allowed_post_types`) and **Options Pages**
  (`mcp_allowed_options_pages`) — both a `select` field with
  `multiple => 1, ui => 1`, the same shape as this file's own "Applies
  To" field above (`field_devbase_retention_apply_to`), reused
  deliberately for consistency rather than reaching for ACF's `checkbox`
  type.

**Why opt-in, not opt-out.** Nothing is exposed to MCP until an admin
explicitly checks items on in this tab. A freshly-registered CPT, or a
site just adding this feature, starts fully locked down rather than
automatically AI-writable. Deliberate safety default, not an oversight.

**Why one combined toggle per item, not separate read/write toggles.**
Simpler UI, and it matches the mental model an admin actually has —
"is this thing MCP-editable at all" — rather than doubling every
decision into a read/write pair.

**Choices are populated dynamically**, same pattern as "Applies To"
below:

- Post type choices (`acf/load_field/key=field_devbase_mcp_allowed_post_types`)
  come from `get_post_types(['show_in_rest' => true, 'public' => true],
  'objects')` minus `devbase_abilities_excluded_post_types()` (defined
  in `inc/abilities/helpers.php`) — `registered_form`/`form_data` are
  hardcoded off regardless of what's checked in this tab, since they're
  submission-log data, never real content.
- Options page choices (`acf/load_field/key=field_devbase_mcp_allowed_options_pages`)
  come from `acf_get_options_pages()`, keyed by `menu_slug`. Building
  this as a plain `$choices[$menu_slug] = $page['page_title']` loop
  incidentally also fixed a pre-existing, separately-discovered SCF
  quirk: the top-level "Theme Settings" parent page's own `menu_slug`
  was found to collide with the real "SMTP Settings" sub-page's slug —
  both report `menu_slug: 'smtp-settings'` from `acf_get_options_pages()`
  (an apparent SCF-side merge/precedence bug, not something in this
  theme's code). Since both entries share that exact array key, the
  second one processed (the real SMTP Settings page) naturally
  overwrites the first, so the picker shows one clean choice instead of
  a confusing duplicate.

**Enforcement lives in `inc/abilities/helpers.php`, not here** — this
file only defines the UI and storage for the two option values.
`devbase_abilities_content_post_types()` and
`devbase_abilities_is_options_page_allowed()` are what actually read
`mcp_allowed_post_types`/`mcp_allowed_options_pages` and gate the
abilities. See `inc/abilities/AGENT.md` for that half of the story
rather than duplicating it here.

### Security bug found and fixed: self-escalation via these two fields

Because `mcp_allowed_post_types`/`mcp_allowed_options_pages` are
themselves stored as regular ACF fields on this same Miscellaneous
options page, once an admin opts the Miscellaneous page itself into MCP
access (plausible — that's exactly where you'd want AI help managing
retention policy), those two fields would show up as normal
AI-readable-and-writable fields via the `devbase/get-options` and
`devbase/update-options` abilities. That means an MCP client could call
`update-options` and rewrite its own allow-list — self-escalating to
grant itself access to anything.

This was confirmed actually exploitable during testing: a real test call
successfully expanded the allow-list from `['page']` to `['page', 'post',
'attachment']` via `update-options`.

Fixed by hardcoding an exclusion for these two exact field names in
`inc/abilities/options-pages.php`'s `devbase_abilities_options_page_fields()`
and `devbase_abilities_options_page_schema()` — they're now invisible to
`get-options`/`get-option-page-schema` and rejected by name if
`update-options` tries to write them, regardless of which options page
they're checked from.

**Lesson for whoever touches this again:** any future setting that
controls MCP access itself needs the same exclusion, the moment it's
exposed through a page that's allowed into MCP.

## Dynamic choices for "Applies To"

`acf/load_field/key=field_devbase_retention_apply_to` calls
`devbase_forms_get_registered_form_choices()` (from
`inc/forms/registry/choices.php`) — the same shared lookup Form
Destinations' `apply_to` uses, so both stay in sync automatically as
forms are registered/removed.

## Verified

`tests/Test_System_Status.php` covers all three status functions —
including that every assertion compares against the actual translated
string (via `__()`/`esc_html__()`, rebuilding the same `sprintf()` the
real function does), not a hardcoded English literal, since this dev
site's real locale is `fr_CA` and this theme ships real translations
(see `languages/AGENT.md`'s note on the test bug this exact class of
mistake caused elsewhere in this theme). Also confirmed live: real
leftover test data (a dead webhook destination, deliberate dispatch
failures from earlier verification passes) correctly appeared in the
System Status tab's failure table before being cleaned up, and the tab
correctly showed "no data" messages afterward — proof the aggregation
reads real data, not a mock.

## Where to go from here

- **The MCP content abilities these two fields gate (list/get/create/update
  posts and options-page values via MCP):** `../../abilities/AGENT.md`
- **The WordPress MCP Adapter itself:** `../../dependencies/mcp-adapter/AGENT.md`
- **Registered forms, form data, dispatch, and retention internals:**
  `../../forms/AGENT.md`
- **The SMTP Settings sub-page (its "Send Test Email" button and
  `devbase_smtp_last_test_message()`):** `../smtp/AGENT.md`
- **Back up to the Theme Settings admin area overall:** `../AGENT.md`


## Tab: Credit (1.2.0)

One field, `credit_brand_url`: the brand's PUBLIC Brand Relay address. `credit.php` downloads it (on
save, shortly after first set, daily via WP-Cron, Brand Relay's `ttl` as a minimum gap), keeps ONE
non-autoloaded option `devbase_credit_data`, and `devbase_credit()` / `devbase_credit_html()` read only
that. Rendering never makes an HTTP request.

- **Allowlist, not a cache of the response.** `devbase_credit_clean()` builds a new array from named
  fields; every value is validated. `params` (where licences live on the site endpoint), `rules`,
  `embed` and unknown keys are dropped. `embed` HTML/CSS is deliberately never printed (a compromised
  Brand Relay account would otherwise inject markup into every client site). `tests/Test_Credit.php`
  has a test that feeds a fake `WRD-` licence in `params` and asserts the option contains no trace of it.
- **Public only.** No key is sent. The site-specific endpoint is NOT used here, on purpose: it carries
  private params. Per-site credit overrides would be a separate, allowlisted feature.
- **Fail soft:** a failed download keeps the last copy and records `last_error`; the copy is tied to
  the address it came from (`source`), so changing the address drops it.

- **Hiding the tab (1.2.1):** defining `DEVBASE_CREDIT_BRAND_URL` makes `devbase_credit_brand_url()` use
  it and removes the tab, field and status via `acf/prepare_field` (matched on KEY). Settings hidden,
  credit unchanged. This is the "invisible to clients" setup; the constant goes in wp-config.php or the
  child theme.

- **No status in the admin (1.2.2):** the Credit tab is only the one field. The "Current Credit" status block and
  `devbase_credit_status_html()` were removed on purpose (clients shouldn't see it). Errors go to the PHP log and
  `last_error` in the option.

- **Display follows Brand Relay's reference snippet (1.2.3):** `message <a href rel>text</a>`, `rel` only when
  non-empty, no target/noopener. The source of truth is `brand-relay/app/lib/snippets.ts` and
  `docs/credit-api.md`. `status: unavailable` = keep last good; `show: false` = store hidden. We still do NOT
  print `embed` (deliberate; see the allowlist note).
