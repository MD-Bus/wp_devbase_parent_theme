# inc/forms/ — centralized form logging + notification dispatch

Lets any form built anywhere in a site on this theme skip implementing its
own submission handling, spam gating, or notification logic. A form
author calls two functions — `devbase_form_build_content()` then
`devbase_form_log_submission()`, both in `inc/helpers/forms/`, not here
— and gets: automatic CAPTCHA gating, a logged submission, and — if an
admin has configured a destination that applies to that form name —
automatic email and/or webhook notification. No per-form plumbing. This
directory is the internal machinery those two calls trigger, not
something a form author needs to read.

```
inc/forms/
  AGENT.md                            — this file
  post-types.php                          — registers registered_form + form_data
                                              (this theme's first two CPTs) —
                                              foundational, everything below
                                              depends on it
  form-data-admin-display.php               — read-only meta box on form_data
  registered-form-admin-display.php           — read-only meta box on registered_form
  retention.php                                 — data retention: schedules + runs
                                                    the daily purge (off by default)
  test-form.php                                   — proof-of-concept test form, standalone
  registry/                                         — JSON registry -> registered_form
                                                        posts, + the shared "which forms
                                                        exist" choices lookup
    AGENT.md                                           — full breakdown, see there
    registered-forms.json
    sync.php
    choices.php
  dispatch/                                       — the send side: orchestrator +
                                                        one sender per channel
    AGENT.md                                         — full breakdown, see there
    orchestrator.php
    smtp.php
    webhook.php
```

`post-types.php` sits at this root level rather than inside a subfolder
because it's foundational — shared across `registry/` and `dispatch/`,
the same role `inc/theme-settings/theme-settings.php` plays for its own
subfolders. `test-form.php` sits here too because it's a standalone proof
of concept, not part of a growing subsystem (same reasoning `inc/AGENT.md`
gives for `inc/timber.php` staying at its own root). The two
`*-admin-display.php` files are single-file, single-purpose (each just
registers one read-only meta box for one CPT) — not enough to warrant
their own subfolders, same "more than a file or two" threshold every
other subfolder decision in this theme follows.

**Where the public entry points live:** `inc/helpers/forms/`, not here —
`devbase_form_build_content()` and `devbase_form_log_submission()`. See
`inc/helpers/forms/AGENT.md`. This directory only ever reacts to the
latter's `wp_insert_post()` via the `save_post_form_data` hook; nothing
here is called directly by a form author.

**Where destinations live:** not in this directory at all —
`inc/theme-settings/destinations/`. That page owns one repeater where
each row is a self-contained destination (SMTP or webhook, picked by a
per-row Type field) plus an "Applies To" multi-select naming which
registered form(s) route there. `dispatch/orchestrator.php` reads that
one field directly; nothing in `inc/forms/` stores or looks up
destinations itself. See that folder's `AGENT.md` for the full field
breakdown, and the "Dispatch matching" section below for how the two sides
connect.

## The core idea

1. A dev building a new form adds one entry to `registry/registered-forms.json`
   — `{"name": "...", "label": "..."}` — in the same commit that builds the
   form. `name` is the STABLE machine key the form's code passes to
   `devbase_form_log_submission()`; `label` is just the initial admin
   post title. See `registry/AGENT.md`.
2. `registry/sync.php` syncs that JSON into a `registered_form` post on
   every `init` (cheaply), so the form shows up as a choice on the Form
   Destinations page even before its first submission. The reverse also
   holds: removing an entry from the JSON deletes its `registered_form`
   post on the next `init`, and strips that form's name from every Form
   Destinations/Retention Policy row's `apply_to` list — see
   `registry/AGENT.md`.
3. An admin adds (or edits) a row on `inc/theme-settings/destinations/` —
   Type (SMTP/webhook), the fields for that type, and an **Applies To**
   multi-select picking which registered form(s) route there.
4. The form's code calls `devbase_form_build_content()` then
   `devbase_form_log_submission( 'the_form_name', $content )` on submit
   (`inc/helpers/forms/`). The latter gates on whichever CAPTCHA provider
   is active (`devbase_security_verify_request()`, from
   `inc/theme-settings/security/verify.php`), then inserts a `form_data`
   post.
5. Inserting that post fires `save_post_form_data`
   (`dispatch/orchestrator.php`), which reads every configured
   destination, keeps the ones whose `apply_to` includes this form's
   name, and sends to each — HTML email via `wp_mail()` for SMTP
   destinations, a JSON `wp_remote_post()` for webhook destinations. See
   `dispatch/AGENT.md`.

## Dispatch matching — by form_name string, not by ID/reference

`devbase_forms_dispatch()` never queries a `registered_form` post at
all. It reads `get_field( 'destinations', 'option' )`, and for each row
checks `in_array( $form_name, $row['apply_to'], true )` — a plain string
match against whatever the form passed to `devbase_form_log_submission()`.
The `registered_form` CPT exists purely to feed the Form Destinations
page's "Applies To" choices (so an admin picks from real registered
names, not free text) — it plays no role in dispatch matching itself.
One consequence worth knowing: dispatch would still work even for a form
name that was never added to `registered-forms.json`, as long as some
destination's `apply_to` happens to list that exact string — the registry
is there for UI discoverability and typo-safety, not as an enforcement
gate.

## Why two CPTs, not a plain options-table

`form_data` is a straightforward one-post-per-submission log —
`public => false`, `show_in_rest => false` (submissions can hold real
PII — names, emails, free-text messages — no reason to expose that via
REST), `has_archive => false`, no public template, only `title` +
postmeta. `registered_form` is the same shape, existing solely as the
"Applies To" picker's source of truth. Both get their own top-level
wp-admin menu.

**Capabilities: admin-only (`manage_options`), not the default
`edit_posts`.** Both CPTs' `'capabilities'` array (in `post-types.php`)
maps every PLURAL primitive cap (`edit_posts`, `edit_others_posts`,
`edit_private_posts`, `edit_published_posts`, `publish_posts`,
`read_private_posts`, `delete_posts`, `delete_others_posts`,
`delete_private_posts`, `delete_published_posts`) to `manage_options` —
the same bar Theme Settings itself already requires — so an Author or
Editor (anyone with plain `edit_posts` on the built-in `post` type) can
no longer see submitted PII.

**`registered_form`'s delete caps go further: `do_not_allow`, not
`manage_options`.** A `registered_form` post is a 1:1 mirror of an entry
in `registry/registered-forms.json` — it isn't user content, so nobody,
administrators included, may delete one from wp-admin (row action, bulk
action, or the edit screen's Delete link all resolve through these same
caps and are blocked identically). The only supported way to remove one
is deregistering it in the JSON: `registry/sync.php`'s
`devbase_forms_prune_deregistered()` deletes the orphaned post
programmatically via `wp_delete_post()`, which is unaffected by this
lockout — capability checks only happen in admin-UI/REST/bulk-action
handlers, never inside `wp_delete_post()` itself. `form_data` keeps its
delete caps at `manage_options` (an admin may legitimately need to purge
a submission by hand for PII reasons) — this restriction is
`registered_form`-only. Covered by
`tests/Test_Post_Type_Capabilities.php::test_administrator_cannot_delete_registered_form`
and `::test_administrator_can_still_delete_form_data`, and confirmed live
against this real dev site: a real administrator gets `false` from both
`current_user_can( $obj->cap->delete_posts )` and
`current_user_can( 'delete_post', $post_id )` on a real `registered_form`
post.

**`form_data`'s create cap goes the same route: `create_posts` →
`do_not_allow`.** A `form_data` post is a submission log — the only
supported way one gets created is `devbase_form_log_submission()`
inserting it from a real front-end submission (`inc/helpers/forms/`). Left
unset, `create_posts` defaults to whatever `edit_posts` resolves to, which
was already `manage_options` — so any admin could hit "Add New" and create
a fake submission indistinguishable from a real one. This is exactly what
WordPress itself checks to decide whether to show the "Add New"
button/admin-menu link and to gate `post-new.php` directly, so no separate
UI-hiding code is needed. `wp_insert_post()` (what
`devbase_form_log_submission()` actually calls) never checks capabilities
itself, so real submissions are unaffected — confirmed live, including a
direct `wp_insert_post()` call for `form_data` succeeding while
`current_user_can( $obj->cap->create_posts )` for the same user returns
`false`. `registered_form`'s `create_posts` was also locked out shortly
after, for the same reason plus one more specific to that CPT — see
"Round 8" below. Covered by
`tests/Test_Post_Type_Capabilities.php::test_administrator_cannot_manually_create_form_data`.

**Deliberately does NOT map the three SINGULAR/object meta caps**
(`edit_post`, `read_post`, `delete_post`). This was a real bug caught
while building this, not a stylistic choice: WordPress registers a global
reverse-lookup, `$post_type_meta_caps[ $value ] = '<meta cap>'`, for
whatever string each of those three resolves to. Mapping them directly to
the literal string `'manage_options'` hijacked that global entry for
**every** `manage_options` check on the entire site — Theme Settings
included — not just these two CPTs, since `manage_options` is a real,
pre-existing WordPress capability name, not something scoped to a post
type. Confirmed live: with all three included, `map_meta_cap(
'manage_options', $user_id )` returned `['do_not_allow']` site-wide,
for every user, admin included. Leaving those three out is sufficient —
WordPress's own built-in handling for `edit_post`/`read_post`/`delete_post`
already falls back to `edit_others_posts`/`read_private_posts`/
`delete_others_posts` for a post that isn't the checking user's own, and
those ARE safely mapped, achieving the identical restriction without ever
touching the global capability string. Covered by
`tests/Test_Post_Type_Capabilities.php`, including a regression test that
asserts `$post_type_meta_caps` never gains a `'manage_options'` key.

## The `$content` contract — keyed by stable field identifier, not label

```php
[
    'email'   => [ 'aria_label' => 'Email Address', 'value' => 'a@b.com' ],
    'message' => [ 'aria_label' => 'Message',       'value' => 'Hello'   ],
]
```

`email`/`message` are the STABLE keys every form author must use — never
the aria-label text. This is deliberate: if a form's field label is
reworded later ("Email" → "Your Email Address"), that must never silently
break stored submissions or the destination formatting, which is exactly
what would happen if the aria-label text itself were the storage key.
`aria_label` is carried through purely for display (the SMTP formatter
uses it as the row label in the notification email, and
`form-data-admin-display.php`'s Submission meta box uses it the same
way). This contract is shared by every file that touches `$content`
(`inc/helpers/forms/build-content.php` producing it,
`inc/helpers/forms/log-submission.php` storing it, `dispatch/smtp.php`
formatting it, `dispatch/webhook.php` passing it through raw) — it's
documented here because it's the one thing that has to stay consistent
across all of them.

## `form-data-admin-display.php` / `registered-form-admin-display.php`

Both CPTs only `supports => array('title')`, so without these, opening
either in wp-admin would show nothing but a title field. Each registers
one read-only meta box:

- **`form-data-admin-display.php`** (`form_data`): the submitted fields
  (from `content` meta, rendered generically — same reasoning as the SMTP
  formatter, no per-form templates) and, once dispatch has run, the
  `_devbase_dispatch_log` results (destination, sent/failed, detail).
- **`registered-form-admin-display.php`** (`registered_form`): the
  form's stable `form_name`, and — since the destination↔form
  relationship is inverted (a destination names which forms it applies
  to, not the other way around) — a reverse lookup of every destination
  on `inc/theme-settings/destinations/` whose `apply_to` currently
  includes this form. This is the one place "where does this form's data
  go" is answerable without visiting the Form Destinations page and
  scanning every row's `apply_to` list by hand.

## CSRF — automatic, same framing as the CAPTCHA gate

`devbase_form_log_submission()` (in `inc/helpers/forms/`, not here) now
verifies a per-form nonce automatically, before anything else — see
`inc/helpers/forms/AGENT.md`. Not documented in depth here since it lives
entirely in the helper; mentioned so this file's picture of "what happens
on submit" stays accurate: nonce check, then CAPTCHA gate, then logging,
then dispatch.

## `retention.php` — data retention, off by default

Reads the Retention Policy tab on `inc/theme-settings/miscellaneous/`
and permanently deletes `form_data` posts older than their configured
period. Schedules a daily WP-Cron event on `init` (standard
`wp_next_scheduled()`/`wp_schedule_event()` idiom), unschedules on
`switch_theme`. The callback itself checks `retention_enabled` and bails
immediately if off, so leaving the cron scheduled even while disabled is
harmless. A form covered by zero policy rows is retained forever; a form
covered by more than one row is purged at whichever row's period is
shortest, since each row runs independently. Full breakdown, including
why there's a manual "Run Purge Now" button and a "last run" staleness
indicator (WP-Cron only fires on a page load, and some hosts disable it
entirely): `inc/theme-settings/miscellaneous/AGENT.md`.

## `test-form.php` — proof of concept, safe to delete

A minimal, zero-setup test form: `?devbase_test_form=1` on any front-end
URL (via `template_redirect`, raw HTML, no theme header/footer/Twig — this
theme now has a first real Twig template, `views/index.twig`, but this
feature predates it and stayed plain PHP deliberately: a dev tool with
its own throwaway markup shouldn't depend on whatever the real front-end
templates happen to look like), posting to
`admin-post.php`. Registered under `devbase_test_form` in
`registry/registered-forms.json`. Exists purely to prove the whole
pipeline works end to end; delete this file + its `functions.php` require
(and its JSON registry entry) any time — nothing else depends on it.

## Verified end to end (2026-08-24)

Confirmed against this real dev site (`dev-blueprint.local`), not just
`php -l`. Three rounds so far:

**Round 1 (original design, since restructured):** both CPTs registering,
the registry auto-creating a Registered Form post from JSON, a real HTTP
POST through `test-form.php` → real DB row → real `wp_remote_post()`
reaching a local capture server. Surfaced and fixed a real bug:
`update_post_meta()`/`meta_input` both `wp_unslash()` their value before
storing, silently corrupting a `wp_json_encode()`'d string's `\"` escapes
around any literal quote in submitted text — fixed with `wp_slash()`, see
`dispatch/AGENT.md`.

**Round 2:** the webhook field shape (Endpoint URL / Password-Key / Header
Name, a plain static auth header) and the admin Submission meta box.

**Round 3 (this restructure — SMTP + Webhook merged into one
`destinations` repeater on `inc/theme-settings/destinations/`, matching
inverted to `apply_to` instead of a per-Registered-Form picker):**
- A destination row saved via `update_field('destinations', [...], 'option')`
  round-trips exactly, including the per-type conditional fields.
- The "Applies To" choices reflect real `registered_form` posts.
- A real HTTP submission through `test-form.php` correctly matched a
  destination whose `apply_to` included `devbase_test_form`, and the
  configured `X-API-Key` header + JSON body arrived at a real local
  capture server — confirmed via the raw received request, not assumed.
- A destination whose `apply_to` did NOT include the submitted form
  correctly did not fire, and the dispatch log recorded
  `"No destination applies to form ..."` rather than failing silently.
- `php vendor/bin/phpunit` — `Test_Form_Submission.php` (unchanged) and a
  rewritten `Test_Form_Dispatch.php` (5 tests, including one specifically
  asserting a two-destination, two-different-`apply_to` scenario only
  fires the matching one) both pass — 22 tests total, same 2 pre-existing
  unrelated `Test_Cookie_Consent` failures. (Those two were later
  root-caused as English-literal assertions — not unrelated at all. See
  `tests/AGENT.md`, "Never assert on an English literal". That file no
  longer exists; cookie consent was removed from this theme on
  2026-10-11.)

**Round 4 (`devbase_form_build_content()` added; `registered_form`'s
empty edit screen fixed):**
- Confirmed the empty-edit-screen complaint's actual cause: `registered_form`
  had no field group or meta box at all after Round 3 deleted its old
  Destinations picker and nothing replaced it — not a WordPress bug, a
  real gap. `registered-form-admin-display.php` fixes it.
- The new meta box's `add_meta_boxes_registered_form` hook and its
  `form_data` counterpart both confirmed to register correctly
  (`isset($wp_meta_boxes[...])` true) once a real admin screen context
  exists (`set_current_screen()`) — an initial CLI check without that
  context showed `_invalid` as the screen key, which was a test-harness
  artifact (no `$current_screen` set, same as it would be for ANY
  `add_meta_box()` call run outside `wp-admin/post.php`), not a bug in
  either file.
- `test-form.php` rewritten to call `devbase_form_build_content()`
  instead of hand-building the `$content` array. A real HTTP submission
  with deliberately padded/uppercase input (`"  Helper Tester  "`,
  `"HELPER@EXAMPLE.COM"`) confirmed `sanitize_text_field()` trimmed the
  name and `sanitize_email()` ran on the email exactly as configured, and
  the resulting webhook body matched byte-for-byte.
- `php vendor/bin/phpunit` — new `Test_Form_Build_Content.php` (5 tests,
  including one that feeds the helper's output straight into
  `devbase_form_log_submission()` to prove shape-compatibility, not just
  a visual match) — 27 tests total, same 2 pre-existing unrelated
  `Test_Cookie_Consent` failures.

**Round 5 (retention policy, CSRF hardening, capability hardening):**
- Retention: a real backdated (10-day-old) submission plus a real recent
  one, a real `1_week` policy — purge deleted exactly the old one, left
  the recent one, confirmed via direct DB checks after running the real
  purge function (not the test suite — this was a separate manual pass).
- CSRF: a real HTTP submission missing the nonce field redirected to
  `blocked=1`; the same submission with the correct
  `devbase_form_nonce_field()`-rendered nonce redirected to
  `submitted=1` — both over real HTTP through `admin-post.php`, proving
  the gate blocks the bad case without breaking the working one.
- Capability: **caught a real, serious bug this way** — see the
  "Capabilities" section above. Manual CLI checks first showed `admin`
  users failing `current_user_can('manage_options')` site-wide, which
  looked like a testing-harness artifact at first (matching the earlier
  `add_meta_box()` screen-context false alarm) but traced to a genuine
  collision via WordPress's `$post_type_meta_caps` global. Fixed, then
  reconfirmed: `map_meta_cap('manage_options', ...)` resolves normally
  again site-wide, a real administrator can edit a specific `form_data`
  post, and a real Editor cannot (checked both at the list level and via
  `current_user_can('edit_post', $post_id)` on a specific post).
- `php vendor/bin/phpunit` — new `Test_Retention_Policy.php` (6 tests)
  and `Test_Post_Type_Capabilities.php` (3 tests, including the
  regression test for the capability collision — confirmed it actually
  fails if the bug is reintroduced, by deliberately reverting the fix
  and re-running it) — 38 tests total, same 2 pre-existing unrelated
  `Test_Cookie_Consent` failures.

Not yet re-verified against real SMTP creds specifically (this dev
environment's SMTP Settings aren't configured) — `dispatch/smtp.php`
reuses `mailer.php`'s existing, already-verified transport unchanged, so
this is lower-risk than the webhook path, but worth a real pass before
relying on it for a client's actual go-live.

**Round 6 (registered_form: delete lockout + JSON-removal auto-prune):**
Closes a real gap: `registered_form` posts could previously be deleted by
hand in wp-admin, desyncing them from `registered-forms.json` (the sync
loop only fills gaps, it never recreates a post the next sync pass), and
nothing ever cleaned up a deleted/deregistered form's dangling references
on Form Destinations/Retention Policy. Fixed in `post-types.php` (delete
caps → `do_not_allow` for `registered_form` only) and `registry/sync.php`
(`devbase_forms_prune_deregistered()` + `devbase_forms_strip_apply_to()`
— see `registry/AGENT.md`).
- `tests/Test_Post_Type_Capabilities.php` and the new
  `tests/Test_Registry_Prune.php` — `php tests/vendor/bin/phpunit`, 75
  tests, all passing.
- Reconfirmed live against this real dev site, not just the test suite: a
  real administrator's `current_user_can()` returns `false` for both
  `delete_posts` and a specific `registered_form` post's `delete_post`
  meta cap; a throwaway `registered_form` post plus a destination row
  whose `apply_to` named it were both cleaned up correctly by
  `devbase_forms_prune_deregistered()` called directly; and — the real
  end-to-end case — temporarily adding then removing an entry from the
  real `registered-forms.json` and firing the real `init` hook
  (`do_action('init')`) showed the post get created on the add and
  deleted on the removal, both through the actual production sync path,
  not a substitute. The real registry file and DB were left exactly as
  found afterward.

**Round 7 (`form_data`: block manual creation):** Same reasoning as Round
6, applied to the other end of `form_data`'s lifecycle — a submission log
should only ever be created by a real submission, never hand-added in
wp-admin. Fixed in `post-types.php` (`create_posts` → `do_not_allow` for
`form_data` only; `registered_form`'s `create_posts` left untouched at
this point — see Round 8, it didn't stay that way).
- `tests/Test_Post_Type_Capabilities.php::test_administrator_cannot_manually_create_form_data` —
  `php tests/vendor/bin/phpunit`, 77 tests, all passing.
- Reconfirmed live against this real dev site: a real administrator's
  `current_user_can( $obj->cap->create_posts )` returns `false` for
  `form_data`; a direct `wp_insert_post()` call for `form_data` — the
  same call `devbase_form_log_submission()` makes for a real submission —
  still succeeded and was cleaned up afterward, confirming the lockout
  only affects the admin-UI/`post-new.php` path, not real submissions.

**Round 8 (`registered_form`: block manual creation too):** A follow-up
review right after Round 7 caught a sharper version of the same gap on
`registered_form` itself: its `create_posts` was still `manage_options`
at that point, and this CPT only supports `title` with no field to set
`form_name` by hand (`registered-form-admin-display.php`'s meta box is
read-only) — so a manually-created post would save with an empty
`form_name`. `devbase_forms_prune_deregistered()` (`registry/AGENT.md`)
explicitly skips posts with an empty `form_name` (`'' === $name` short-
circuits before the `in_array` check), and Round 6 already made this
CPT's delete caps `do_not_allow` — so a manually-created post would have
been a permanent, unremovable orphan: not just clutter, a state nothing
built so far could clean up. Fixed the same way as Round 7:
`create_posts` → `do_not_allow` for `registered_form` too, in
`post-types.php`.
- `tests/Test_Post_Type_Capabilities.php::test_administrator_cannot_manually_create_registered_form`
  (replaces the Round 7 test that had asserted the opposite) —
  `php tests/vendor/bin/phpunit`, 77 tests, all passing.
- Reconfirmed live: a real administrator's
  `current_user_can( $obj->cap->create_posts )` on `registered_form` now
  returns `false`; a direct `wp_insert_post()` call for `registered_form`
  — the same call `registry/sync.php` makes when syncing the JSON —
  still succeeded and was cleaned up afterward, confirming the lockout
  only affects the admin-UI/`post-new.php` path, not the real sync.
