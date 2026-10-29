# inc/theme-settings/schema/breadcrumb/ — the Breadcrumb schema type

See `inc/theme-settings/schema/AGENT.md` for the parent page and the
`devbase/schema/tabs` mechanism that turns this subfolder into its own
tab (alongside Organization and Website) on the Schema Markup page.

```
breadcrumb/
  settings.php   — contributes the Breadcrumb tab: a single on/off toggle
  inject.php       — computes the trail and prints the JSON-LD in wp_head
  AGENT.md            — this file
```

## Why this one has no content fields at all

A `BreadcrumbList` isn't content an editor fills in — it's a reflection of
where the current page sits in WordPress's own hierarchy, computed fresh
on every request. `settings.php` is one `true_false` field; all the real
logic is in `inject.php`.

## What `inject.php` actually covers

Deliberately only the common, unambiguous cases — this is one of the
"always need it, cheap to build" types picked specifically because it
*doesn't* need to handle everything:

- **Hierarchical post types** (pages): walks every ancestor from the
  top down via `get_post_ancestors()` (which returns nearest-first, so
  it's reversed), then the current page as the final, url-less item.
- **Non-hierarchical post types with an archive** (a CPT, or `post`):
  `Home > {archive link} > {current post}`.
- **Post type archives**: `Home > {archive title}`.
- **Taxonomy term archives** (category/tag/custom taxonomy):
  `Home > {term name}`. Ancestor terms for hierarchical taxonomies are
  *not* walked — that's exactly the kind of "not always needed, build it
  per-project" case this theme drew the line at.
- **The posts page** (`is_home()`, when a static front page is set and a
  separate "Posts page" exists under Settings → Reading):
  `Home > {posts page title}`.
- **Skipped entirely** on the front page, search, and 404 — none of
  those have a meaningful fixed position in a content hierarchy.

**The last item in the trail never gets an `item` (url) key.** This
matches Google's own documented guidance: the final entry represents the
page the breadcrumb is already on, so a url there is redundant.

**Category-based breadcrumbs for blog posts were deliberately left out.**
The "common case, cheap to build" test this type was picked under doesn't
hold for `Home > Category > Post Title` — it requires deciding which
category wins when a post has several, which is exactly the kind of
per-project judgment call this type list was meant to avoid. A CPT's own
archive link is unambiguous; a post's categories are not.

## A real bug hit while testing this — read before trusting `wp_head` output in isolation

`wp_head` **does** fire on a real page load now — `views/index.twig`
calls it (see `views/AGENT.md`), and a client child theme's own templates
are expected to as well. That wasn't true when this type was built, and
the note below was originally written to explain why it couldn't be
tested through a real request at all; the testing pitfall it describes is
still entirely real, so it's kept.

Calling `do_action('wp_head')` directly from a bare PHP CLI script (as
earlier schema types were tested) works for toggles and static data, but
conditional tags like `is_singular()`, `is_front_page()`, and
`is_post_type_archive()` all depend on WordPress's *main query*, which is
normally parsed by `wp()` inside `wp-blog-header.php` — a file that's
never reached when you only `require wp-load.php` directly.

Concretely: an initial test that set `$_SERVER['REQUEST_URI']` to
different URLs before requiring `wp-load.php` produced `is_singular:
false` and `is_front_page: false` for *every* URL tested, including the
site's own front page — because the query was never actually parsed, so
every conditional tag fell back to its default (query-less) state. The
fix was calling `wp()` explicitly right after `wp-load.php`, which runs
the same request-parsing WordPress normally does — after that, each
URL's conditional tags matched reality, and the breadcrumb trails were
verified correct for a page, a nested child page, a CPT's archive, a
CPT's singular post, and a category archive.

**The practical implication**: any future schema type (or anything else)
whose output depends on conditional tags — not just static field
values — needs this same `wp()` call to test properly from the CLI. Don't
trust a bare `do_action('wp_head')` test for anything that checks
`is_singular()`/`is_front_page()`/etc. — it will silently report the
front-page/query-less state no matter what URL you think you're testing.
