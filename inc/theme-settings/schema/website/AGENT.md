# inc/theme-settings/schema/website/ — the Website schema type

See `inc/theme-settings/schema/AGENT.md` for the parent page and the
`devbase/schema/tabs` mechanism that turns this subfolder into its own
tab (alongside Organization and Breadcrumb) on the Schema Markup page.

```
website/
  settings.php   — contributes the Website tab (just two toggles)
  inject.php       — reads them, prints the JSON-LD in wp_head
  AGENT.md            — this file
```

## Why this one has almost no fields

Website was picked (alongside Organization and Breadcrumb) specifically
because it's cheap: `name`, `url`, and `description` all already exist as
real data — `get_bloginfo('name')`, `home_url('/')`, and either
Organization's own description (if Organization schema is enabled) or the
site tagline. There's nothing here for an admin to duplicate-enter, so the
field group is just two `true_false` toggles: enable it, and optionally
enable a Sitelinks Search Box (`potentialAction` / `SearchAction`, using
this site's own `?s=` search).

**`publisher` links to Organization rather than repeating its fields.**
If Organization schema is enabled and has a name, `inject.php` builds a
compact `{"@type": "Organization", "name": ..., "url": ..., "logo": ...}`
reference and sets it as WebSite's `publisher` — this is why Organization
loads before Website in `functions.php`... actually it doesn't need to:
both hooks read from the shared `'option'` data store independently at
`wp_head` time, not from each other's PHP state, so load order between
the two `inject.php` files doesn't matter. What *does* matter is that
Organization's fields already exist under the names `organization_name`,
`organization_url`, `organization_logo` — if those are ever renamed,
`website/inject.php`'s `publisher` block needs updating too.

## Verified against a real request

Tested via a real WordPress main query (not just `do_action('wp_head')`
in isolation) with Organization enabled and Sitelinks Search enabled: the
front page produced a `WebSite` block with both `publisher` and
`potentialAction` populated correctly. See
`inc/theme-settings/schema/breadcrumb/AGENT.md` for why a *real* main
query was necessary to test any of this properly — the same harness was
used for both.
