# inc/forms/registry/ — form-name registry

Turns `registered-forms.json` into wp-admin posts a dev never has to
create by hand. See `inc/forms/AGENT.md` for how this fits into the whole
feature.

```
registry/
  AGENT.md                — this file
  registered-forms.json      — the static form-name registry
  sync.php                     — JSON -> registered_form posts, on init
  choices.php                    — shared "which forms exist" lookup
```

## `registered-forms.json`

```json
{
  "forms": [
    { "name": "devbase_test_form", "label": "Boostway Test Form" }
  ]
}
```

- `name` — the STABLE machine key, snake_case, exactly what a form author
  passes as `$form_name` to `devbase_form_log_submission()`, and exactly
  what a Form Destinations row's `apply_to` multi-select stores per form
  (see `inc/theme-settings/destinations/AGENT.md`).
- `label` — human-readable, used only as the initial post_title when a
  Registered Form post is auto-created. Renaming the post title afterward
  in wp-admin never breaks anything — matching (both here and in
  dispatch) is always by `name`, never by title.

A dev adding a new form in code adds one entry here in the same commit
that builds the form — no separate PHP registration call needed.

**But a CLIENT SITE's forms do not go in this file.** This theme ships
identically to every site, so a form added here appears on every other
client's site too. A child theme contributes its own via the
`devbase/forms/registered_forms` filter instead — see
`devbase_forms_get_registry()` below. The only entry that belongs in this
JSON is one genuinely shared by every site built on this theme
(currently just `devbase_test_form`, the proof-of-concept dev tool).

## `devbase_forms_get_registry()`

The resolved registry — this file's JSON, plus whatever a child theme adds
through the `devbase/forms/registered_forms` filter. Everything else
works from this, never from the JSON directly.

A child contributes with a plain top-level `add_filter()` in its own
`functions.php`. That file is included BEFORE the parent's (WordPress puts
the stylesheet ahead of the template in
`wp_get_active_and_valid_themes()`), so the filter is always registered by
the time this runs on `init` — the load order works in the child's favour
here. It would NOT be safe for a child to *call* a parent function at that
point, only to register a hook; see the child starter's root `AGENT.md`.

## `sync.php`

Hooked on `init` at priority 20 (after `post-types.php`'s registration at
the default priority 10, so the CPT is guaranteed to exist first,
regardless of `functions.php` require order drifting later). Responsibility:

1. Call `devbase_forms_get_registry()` (the JSON plus any child-theme
   contributions).
2. Skip the DB work entirely unless the **resolved** registry changed
   since the last sync: `md5()` of the encoded resolved list, compared
   against a stored option (`devbase_registered_forms_synced_hash`) —
   this runs on every `init`, so it needs to be cheap on the common case
   (nothing changed), not run a `WP_Query` on every single front-end
   request.

   This used to be `md5_file()` on the JSON alone, which was correct until
   child themes existed and then silently wrong: a child adding, renaming
   or removing a form changes nothing about this theme's own file, so a
   file hash would never notice and the child's forms would never sync at
   all. Caught while building the parent/child split, before it shipped.
3. On a hash change: for each `{name, label}`, look up an existing
   `registered_form` post by `form_name` postmeta; if none exists,
   `wp_insert_post()` one (`post_title => label`, `post_status =>
   'publish'`, `meta_input => ['form_name' => name]`).
4. Call `devbase_forms_prune_deregistered()` (see below) to remove any
   `registered_form` post whose name is no longer in the JSON.
5. Update the stored hash option.

Additive for anything still IN the JSON, matching the theme's "fill gaps,
never overwrite" rule — an existing Registered Form post's title or status
are never touched once created, even if the JSON `label` later changes.
NOT additive-only overall: an entry REMOVED from the JSON does get its
post deleted, by design — see below.

## `devbase_forms_prune_deregistered( array $names_in_json )`

Deletes every `registered_form` post whose `form_name` postmeta isn't in
`$names_in_json`, via `wp_delete_post( $post_id, true )` (force-deleted,
not trashed — this CPT has no real trash workflow). This is the ONLY
supported way a `registered_form` post gets removed: `post-types.php`
maps `registered_form`'s delete capabilities to `do_not_allow` (not
`manage_options`, unlike everything else on this CPT), so nobody —
administrators included — can delete one from wp-admin. That lockout
doesn't affect this function, because `wp_delete_post()` never checks
capabilities itself; only admin-UI/REST/bulk-action handlers do. See
`inc/forms/AGENT.md`'s capabilities section for the full reasoning and
the real bug (manual deletion desyncing from the JSON) this closes.

A post with an empty `form_name` is explicitly skipped (`'' === $name`
short-circuits before the `in_array` check) rather than pruned — every
post the JSON sync itself creates always has one, so an empty value would
only happen from a manually-created post, and the intent is to leave a
real edge case alone rather than silently delete something unexpected.
This is also exactly why `registered_form`'s `create_posts` is locked to
`do_not_allow` too (`post-types.php`): a manually-created post can't have
its `form_name` set at all (no field exists for it — see
`registered-form-admin-display.php`), so combined with the delete lockout
above, one that slipped through would have been a permanent, unremovable
orphan — never pruned (empty name), never deletable (locked delete caps).
Closed before it happened for real, not after — see `inc/forms/AGENT.md`'s
"Round 8".

Before deleting, calls `devbase_forms_strip_apply_to()` (below) once for
Form Destinations and once for Retention Policy, so the deleted form's
name doesn't linger as a dangling reference in either. Both dispatch
(`dispatch/orchestrator.php`) and retention (`inc/forms/retention.php`)
match purely by `form_name` string against `apply_to`, never by
post/reference — nothing in either enforces "this name still corresponds
to a real registered_form post" on its own, so this cleanup is the only
thing that keeps a deregistered form's name from sitting there forever
pointing at nothing.

## `devbase_forms_strip_apply_to( $field_name, $options_page_slug, $form_name )`

Reads the `$field_name` repeater from the shared `'option'` store (both
Form Destinations' `destinations` and Retention Policy's
`retention_policies` use the generic ACF options store, not a per-page
one — see `inc/theme-settings/destinations/AGENT.md`), removes
`$form_name` from every row's `apply_to` array, and writes back via
`update_field()` only if something actually changed. A row left with an
empty `apply_to` after this isn't specially flagged or disabled — it's
already inert, since both consumers' `in_array( $form_name, $row['apply_to'], true )`
check never matches an empty array.

Guarded with `function_exists( 'get_field' )`/`'update_field'` since ACF
may not be loaded in every context this could run in.

Covered by `tests/Test_Registry_Prune.php` (calls both functions directly
with an explicit `$names_in_json` list, rather than driving the full
`devbase_forms_sync_registry()` through the real
`registered-forms.json` — that file is production data, not a test
fixture) — see `inc/forms/AGENT.md`'s "Round 6" for the real end-to-end
verification against this dev site's actual registry file and DB.

## `choices.php`

`devbase_forms_get_registered_form_choices()` — one `get_posts()` query
returning `form_name => post_title` for every registered form. Used by
`acf/load_field` filters in TWO places: Form Destinations'
`apply_to` (`inc/theme-settings/destinations/settings.php`) and
Retention Policy's `apply_to`
(`inc/theme-settings/miscellaneous/settings.php`). Factored out here
(registry-owned data, not settings-page-specific) so both stay in sync
automatically and neither duplicates the query — a form's choice would
otherwise need to be added/removed correctly in two places by hand.

## What this CPT is (and isn't) used for

A `registered_form` post exists purely to give admin-facing "Applies To"
multi-selects (Form Destinations, Retention Policy) something real to
pick from via `choices.php` above — an admin picks a known registered
name, not free text. It plays no role in dispatch or retention matching
itself: both `dispatch/orchestrator.php` and `retention.php` match purely
on the `form_name` string, never by querying this CPT — see
`inc/forms/AGENT.md`'s "Dispatch matching" section.
