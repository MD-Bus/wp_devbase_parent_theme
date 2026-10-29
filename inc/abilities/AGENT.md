# inc/abilities/ — this theme's own MCP content abilities

```
inc/abilities/
  categories.php     — registers the 'devbase' ability category
  helpers.php         — shared validation/shaping used by every ability below
  post-types.php       — devbase/list-post-types, get-post-type-schema
  posts.php             — devbase/list-posts, get-post, create-post, update-post
  options-pages.php      — devbase/list-option-pages, get-option-page-schema,
                            get-options, update-options
```

## What this is, and what it isn't

`inc/dependencies/mcp-adapter/` (has its own AGENT.md) is purely a
transport — it takes whatever WordPress core Abilities API abilities exist
anywhere on the site and exposes them as MCP tools. It registers zero
abilities of its own. `inc/dependencies/scf/` gets SCF's own ~48 abilities
working (its own AGENT.md documents a real `plugins_loaded` bug fixed to
make that happen), but those cover field/post-type/taxonomy/options-page
**structure** — defining what fields exist, not touching real content.

This folder is the other half: **actual content operations** — list posts,
read one, create one, update one, same for options-page values — generic
across every post type on the site (including any CPT added in the
future, with zero code changes needed here) rather than one ability per
type. That "generic, parameterized" shape was a deliberate choice over
"one ability per post type" (which was seriously considered — see the
alternative in the git history/conversation this was built in): it means
`discover-abilities` stays a small, stable list forever, at the cost of
needing one extra round-trip (`list-post-types` before anything else) for
an MCP client to learn what post types actually exist. SCF's own abilities
already use this same parameterized shape (e.g. `scf/get-post-type` takes
an ID/key, not a separate ability per post type) — this folder matches
that precedent.

## The core design decision: reuse WP's own REST layer, don't reimplement it

`vendor/secure-custom-fields/includes/rest-api/class-acf-rest-api.php`
already registers an `acf` REST field on every REST-exposed post type —
`/wp/v2/{rest_base}` already reads AND writes real SCF field values,
fully tested, with SCF's own capability/validation logic. So instead of
hand-rolling `wp_insert_post()`/`update_field()` wiring (which is exactly
where two real bugs were found in SCF's own hand-rolled Abilities code —
see below), every content ability's `execute_callback` builds a
`WP_REST_Request` and dispatches it via `rest_do_request()` through
`devbase_abilities_rest_dispatch()` in `helpers.php`, then translates the
REST response into the ability's output. This reuses WP core's own
posts-controller (validation, capability checks, status handling,
pagination) and SCF's already-correct field read/write logic wholesale —
far less custom logic to get wrong. It runs as the currently authenticated
user (already established by the incoming MCP request's Application
Password auth), so the dispatched request's own capability checks apply
exactly as they would for a direct REST API call — genuine defense in
depth, not just this folder's own permission_callbacks.

Options pages have no REST controller to reuse (SCF options pages aren't
WP objects), so `options-pages.php` goes direct to SCF's PHP API instead:
`acf_get_options_pages()` (list), `acf_get_field_groups(['options_page' =>
$slug])` + `acf_get_fields()` (schema), `get_field_objects('option')` /
`update_field($key, $value, 'option')` (values). Every options (sub-)page
in this theme shares the single `'option'` storage context — none of
`inc/theme-settings/*/settings.php` pass a custom `post_id` to
`acf_add_options_sub_page()` — so which fields belong to which page is
resolved by each field group's `options_page` location rule, not by a
per-page storage key. This is why `devbase_abilities_options_page_fields()`
(the flat key/name list used for value read/write) and
`devbase_abilities_options_page_schema()` (the full nested schema) both
independently resolve field groups per-slug rather than trusting a
storage-key boundary that doesn't actually exist.

## `helpers.php` — the shared pieces every ability depends on

- **`devbase_abilities_content_post_types()`** — the single source of
  truth every post-type-related ability calls to validate an incoming
  `post_type` argument. Three layers of filtering, in order: (1)
  `show_in_rest => true, public => true` — `show_in_rest` alone also
  matches WordPress's own internal site-editor post types (`wp_navigation`,
  `wp_template`, `wp_font_family`, ...), a real bug caught during testing
  (`list-post-types` was leaking them) and fixed by requiring `public =>
  true` too; (2) `devbase_abilities_excluded_post_types()` — hardcoded off
  regardless of anything else: `registered_form`/`form_data`
  (`inc/forms/post-types.php`), already `manage_options`-gated
  submission-log data, never real authored content, filterable via the
  `devbase_abilities_excluded_post_types` filter for a child theme to
  extend; (3) the **opt-in MCP allow-list** — `get_field(
  'mcp_allowed_post_types', 'option' )`, set via Theme Settings >
  Miscellaneous > MCP Access (`inc/theme-settings/miscellaneous/AGENT.md`).
  Nothing is exposed until explicitly checked on there — a fresh CPT (or a
  site just adding this feature) starts fully locked down.

- **`devbase_abilities_is_options_page_allowed( $menu_slug )`** — the
  options-page equivalent of the allow-list check, kept as a separate
  function from `devbase_abilities_is_registered_options_page()` (in
  `options-pages.php` — "does this page exist at all") so the two failure
  modes stay distinguishable: an unknown slug gets a different error than
  a real slug that's just not opted in.

- **`devbase_abilities_shape_fields( $fields )`** — recursively turns raw
  ACF/SCF field configs into the simplified schema shape every schema
  ability returns, understanding **repeater/group `sub_fields`** and
  **flexible_content `layouts`** (each layout's own `sub_fields`,
  recursively) and **`conditional_logic`** (resolved to readable field
  names via a sibling key→name map, not raw internal field keys — e.g.
  `retention_policies` correctly shows `conditional_on: [[{field:
  "retention_enabled", operator: "==", value: "1"}]]`). This was NOT
  originally correct — the first version flattened every field to just
  `{name, label, type}`, silently dropping nested structure and
  conditional logic entirely, meaning a repeater showed up as an opaque
  `type: "repeater"` with no way to know what belongs inside it. Fixed and
  verified against this theme's own real `retention_policies` repeater
  (which conveniently already has both nesting and conditional logic) and
  against a real, purpose-built flexible_content field group (two layouts,
  each with its own sub-field) — both round-trip correctly now, including
  real saved values via `devbase/get-post`.

- **`devbase_abilities_rest_dispatch( $method, $route, $params )`** — the
  REST-reuse mechanism described above, returning a plain `[$data, $error]`
  pair so callers don't duplicate REST-response inspection.

- **`devbase_abilities_post_field_names( $post_type, $post_id = null )`**
  — the field-name validation used by `create-post`/`update-post` (below).

## Real bugs found and fixed while building this (worth knowing before touching this code again)

1. **`get-post`'s permission check used `edit_post`, not `read_post`** —
   wrong capability for a read operation, would have wrongly blocked a
   user who can legitimately read a post but not edit it. Fixed.
2. **Options-page abilities didn't validate `menu_slug` before proceeding**
   — a typo silently returned empty data (`acf_get_field_groups()`/
   `acf_get_fields()` return nothing for an unknown slug rather than
   erroring) instead of a clear "unknown options page" error, unlike the
   post-type abilities which already validated `post_type` this way. Fixed
   via `devbase_abilities_is_registered_options_page()`.
3. **A leftover bug from an earlier fix**: `devbase_abilities_shape_fields()`'s
   flexible_content `layouts` branch still called an unverified
   `acf_prepare_fields_for_export()` that had already been removed from
   the repeater/group branch in the same edit — missed because it
   references a different variable (`$layout['sub_fields']` vs
   `$field['sub_fields']`), so a simple find-and-replace didn't catch
   both. Untested until a real flexible_content field group was
   deliberately created to verify it — would have broken the moment
   anyone used flexible content. Fixed and then actually verified against
   real data, not just code review.
4. **`create-post`/`update-post` silently accepted unknown field names** —
   passing a bogus field name reported success with the field simply
   absent from the response, no error at all (SCF's own REST `acf` field
   silently drops unrecognized keys). Confirmed via a real test call. This
   is the same failure shape as a bug found independently in SCF's own
   `scf/create-field` ability (reported success while silently failing to
   link a field to its parent group) — same lesson, different layer: an
   operation reporting success while silently doing something other than
   what was asked is worse than an error. Fixed by validating field names
   against `devbase_abilities_post_field_names()` before dispatching,
   returning a clear `devbase_unknown_field` error instead.
5. **A genuine upstream SCF/WordPress-core REST bug, NOT fixed here** (out
   of this theme's control) — see `inc/dependencies/scf/AGENT.md` for the
   full story: SCF's `scf_field_groups` REST field never actually appears
   in `/wp/v2/types/{post_type}` responses despite being correctly
   registered and returning correct data when called directly.
   `get-post-type-schema` works around it by calling the same underlying
   `acf_get_field_groups()`/`acf_get_fields()` functions directly instead
   of trusting that REST field.
6. **Self-escalation via the MCP allow-list itself** — see
   `inc/theme-settings/miscellaneous/AGENT.md`'s MCP Access section for
   the full story: `mcp_allowed_post_types`/`mcp_allowed_options_pages`
   are themselves ACF fields, and once their containing options page is
   allowed, they were readable/writable like any other field — meaning an
   MCP client could rewrite its own allow-list. Confirmed exploitable,
   fixed by hardcoding an exclusion for these two field names in
   `devbase_abilities_options_page_fields()` and
   `devbase_abilities_options_page_schema()` (`options-pages.php`),
   regardless of which options page they're checked from.

## Child-theme CPTs work, with one requirement

A post type registered by a client child theme (the normal case — see
`../../wp_devbase_child_theme/inc/content/AGENT.md`) is fully supported
with no change here: the abilities are parameterised over `post_type`, so
they never need to know a type exists ahead of time.

The requirement is **`show_in_rest => true`**. Everything in `posts.php`
dispatches through `rest_do_request()`, so a post type without a REST
controller isn't degraded, it's unusable — which is why
`devbase_abilities_content_post_types()` filters on `show_in_rest` AND
`public` before the allow-list is even consulted, and such a type is never
offered as a choice on the MCP Access tab.

Verified end to end in `tests/Test_MCP_Child_CPT.php` against a CPT
registered the way a child theme does it (`init`, priority 10): schema
resolution, create, read, update, list, plus SCF custom field read/write
through its own field group, plus the unknown-field guard still erroring
rather than silently dropping. And separately confirmed on the real dev
site with the child theme active.

## Don't build the REST server early

A constraint this folder creates for the rest of the theme, worth knowing
before adding a feature that touches REST.

WordPress builds the REST server lazily — the first call to
`rest_get_server()` constructs it and fires `rest_api_init`, whose
priority-99 `create_initial_rest_routes()` enumerates every registered post
type **at that moment**. The route table is then fixed for the request.

`rest_do_request()` calls `rest_get_server()`. Everything here does so only
inside an ability's `execute_callback`, which runs on an incoming MCP
request — long after `init`, so every CPT (including a child theme's) is
already registered and gets a route. That's why child-theme CPTs work at
all; verified with a real HTTP request to `/wp/v2/service` for a CPT
declared in the child.

**But a future feature that called `rest_do_request()` during `init`, or
on `plugins_loaded`, or anywhere earlier, would freeze the route table
before child-theme CPTs register** — silently removing them from the REST
API and therefore from MCP entirely, on every site. If something needs a
REST dispatch outside a request-time callback, defer it to `wp_loaded` or
later. The same hazard, in its test-suite form, is documented in
`../../tests/AGENT.md`.

## A real, documented limitation (not a bug) — post-type-level schema vs. per-post fields

`get-post-type-schema` resolves field groups via
`acf_get_field_groups(['post_type' => $post_type])` — this only catches
field groups whose location rule references the post type generically
(e.g. "Post Type == page"). A field group scoped to one *specific* post
(a location rule like "Page == 31", not "Post Type == page") will **not**
appear in `get-post-type-schema`'s output, even though it's real — verified
empirically: a real field group scoped to a single page returned zero
matches from `acf_get_field_groups(['post_type' => 'page'])`. But
`devbase/get-post` on that specific post **does** correctly show it (via
SCF's REST `acf` field, which resolves field groups against the actual
post — template, ID, everything — not just the post type generically) —
verified with three real pages, one with a per-post field: `get-post` on
that one correctly showed the field, the other two correctly didn't. This
is documented directly in `get-post-type-schema`'s own ability
`description` so an MCP client sees the caveat before relying on it, not
just here.

## Where to go from here

- **The MCP Adapter that exposes these abilities:** `../dependencies/mcp-adapter/AGENT.md`
- **SCF's own abilities (field/post-type structure, and the `plugins_loaded`
  bug that had to be fixed to get them registering at all):** `../dependencies/scf/AGENT.md`
- **The admin-configurable MCP Access allow-list (Theme Settings >
  Miscellaneous):** `../theme-settings/miscellaneous/AGENT.md`
- **Back up to how the theme boots overall:** `../AGENT.md`


## Options pages with their own storage (1.2.4)

Archive pages (inc/archive/settings.php) have `post_id` = their menu slug so same-named fields don't share a
value. `devbase/get-options` / `update-options` use `devbase_abilities_options_page_post_id()`: the page's own
post_id, else the shared store. They used to hardcode `'option'`, so MCP writes to an archive landed where no
template reads them (and the archives overwrote each other) while the read-back, from the same wrong place,
looked fine. `tests/Test_MCP_Abilities_Options_Pages.php` covers it with two pages that share a field name.
