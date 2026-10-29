# inc/archive/ — fields on CPT archives, and archive links in the link picker

Two unrelated WordPress gaps, fixed independently, that both show up when a
site has a CPT with an archive:

1. SCF/ACF has no location rule for "post type archive" — you cannot attach
   a field group to one, and there's no admin UI for it even if you could.
2. The internal link picker (used by ACF/SCF link fields and the classic
   editor's link dialog) only ever searches real rows in `wp_posts` — an
   archive isn't a post, so it can never show up there, however you search.

```
archive/
  settings.php     — parent "Archive" options page + one sub-page per CPT
                       archive, so field groups have somewhere to attach
  link-query.php     — injects each CPT archive into the link picker's
                         results
  AGENT.md               — this file
```

## `settings.php` — the options-page approach

Rather than inventing a real post ("virtual archive post") to hang fields
off, this registers a plain options page per archive — the same mechanism
`inc/theme-settings/` already uses for Security/SMTP/Tracking, just applied
to content instead of theme config. No proxy CPT, no syncing rows to CPTs,
no risk of an editor accidentally creating a stray "archive" post.

- A parent page (`menu_slug: archive-settings`) is registered empty, same
  pattern as `inc/theme-settings/theme-settings.php`.
- On **`wp_loaded`**, not `acf/init` like every other options page in this
  theme, it loops `get_post_types(['public' => true, 'has_archive' =>
  true])` and adds one sub-page per result, with `menu_slug:
  archive-{post_type}`. This was tested and got it wrong the first time:
  `acf/init` fires from inside WP's own `init` hook at **priority 5**, so a
  CPT registered the ordinary way — `register_post_type()` on `init` at
  the default priority 10, the common case for a hand-coded or
  plugin-registered CPT — doesn't exist yet when an `acf/init`-hooked
  callback runs, and silently gets no archive sub-page. `wp_loaded` runs
  after every `init`-priority registration has happened. This is safe
  because `acf_add_options_page()`/`acf_add_options_sub_page()` only queue
  config into an array; SCF doesn't actually call `add_submenu_page()`
  until `admin_menu` priority 99 (see
  `vendor/secure-custom-fields/includes/admin/class-acf-admin-options-page.php`),
  so anything registered before then — `wp_loaded` included — works.
- Sub-pages start empty, deliberately. To add fields for a specific
  archive, add a field group elsewhere (wherever that CPT itself is
  defined/customized) with location `options_page == archive-{post_type}`,
  then read it with `get_field( 'field_name', 'archive-{post_type}' )`.
  This file doesn't own any field group itself — it only owns the pages.
- **Each sub-page is registered with `'post_id' => $menu_slug` explicitly
  — this is not optional.** Every SCF/ACF options page defaults to
  `post_id => 'options'`, one data store shared by every page that doesn't
  override it (this is why `inc/theme-settings/`'s sub-pages all read with
  `get_field( 'x', 'option' )`, not their own menu_slug — see
  `inc/theme-settings/schema/AGENT.md`). Without this override here, two
  different CPT archives with a same-named field would silently read and
  write the exact same value. Verified directly: registered two test CPTs
  with archives, gave each a field group with an identically-named
  `intro_text` field, saved different values to each, and confirmed they
  came back distinct only once `post_id` was added — before that fix, the
  same test collided.

**Verified with a child-registered CPT**, which is the real use case:
`tests/Test_Child_CPT_Integration.php` registers one on `init` at the
default priority and asserts the archive sub-page appears with its own
`post_id`. Also confirmed live against this dev site with a real `service`
CPT declared in the child theme's own `inc/content/post-types.php` — the
`archive-service` page registered with isolated storage, and the archive
appeared in the link picker with a negative synthetic ID. That's the
`wp_loaded`-not-`acf/init` decision above actually paying off rather than
just being asserted.

**Unlike `inc/theme-settings/`'s sub-pages, this one is not "one subfolder
per sub-menu."** Theme Settings sub-pages are hand-written, one per
feature (Security, SMTP, Tracking) — there's a fixed, known set of them.
Archive sub-pages are generated from whatever CPTs happen to be registered
on a given site, which this base theme doesn't know in advance, so they're
all produced by one loop in one file instead.

## `link-query.php` — making archives linkable

Hooks the `wp_link_query` filter (`wp-includes/class-wp-editor.php`,
`_WP_Editors::wp_link_query()`) — the same filter WordPress core itself uses
to shape what the internal link dialog's AJAX search returns. For every
public CPT with an archive whose label or slug matches the current search
term (or always, when there's no search term yet), it appends a synthetic
result: `get_post_type_archive_link()` as the permalink, the post type's
plural label as the title, and `'Archive'` as the info column — visually
indistinguishable from a real post in the picker.

Two things worth knowing if this ever misbehaves:

- **Only added on the first page of results.** The filter receives
  `$query['offset']` but not the raw `pagenum` — a non-zero offset means
  we're past page 1, so archive entries only get injected once rather than
  re-appended on every page of a paginated search.
- **Fake IDs are negative.** Real post IDs are always positive, so
  `'ID' => -1 * (crc32($post_type_name) % 1000000)` can't collide with an
  actual post — the exact value doesn't matter beyond "stable and never
  positive."
