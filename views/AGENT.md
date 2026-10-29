# views/ — Twig templates

This theme's front-end templates. Currently just one — a minimal branded
placeholder, since this is a base theme copied and renamed per project,
not a live site: nothing client-specific belongs here, but an entirely
blank front end reads as broken rather than "not built yet."

```
views/
  AGENT.md            — this file
  index.twig             — the placeholder page
```

## `index.twig`

Rendered by the root `index.php` (WordPress's required fallback
template — the last resort in the template hierarchy, and since this
theme has no `header.php`/`page.php`/`single.php`/etc. yet, currently the
ONLY thing that renders for any front-end URL). Shows the Boostway "B"
mark (`assets/icons/devbase-b-icon.svg`, the same file used as the
Theme Settings admin menu icon), a heading, and "Boostway Official
WordPress Development Base Theme." Light/dark aware via
`prefers-color-scheme`. No header/footer split, no nav — deliberately
one file, since splitting into partials now would just be structure a
real project will replace anyway.

**Calls `wp_head()`, `wp_footer()`, `body_class()`, and — importantly —
`wp_body_open()`** via Timber's `{{ function(...) }}` Twig helper (calls
the named PHP function/action; works for actions that echo directly, not
just ones that return a value — Twig's own template rendering is itself
output-buffered, so the echoed output lands in the right place). This
matters beyond just "don't break plugins that hook these": Tracking
Settings' GTM `<noscript>` fallback (`inc/theme-settings/tracking/inject.php`)
had been sitting on the `wp_body_open` hook with nothing ever calling it,
documented as "does nothing until a real header template exists." This
file is that template — confirmed live, with a real GTM ID configured,
the `<noscript>` snippet actually renders in the page.

## A child theme's templates win over these

Timber resolves template names against the child theme first:
`LocationManager::get_locations()` searches
`[ get_stylesheet_directory(), get_template_directory() ]`, so a client
child theme's `views/index.twig` takes precedence over this one
automatically, with no registration or configuration. Verified directly
with a child theme really activated on this dev site — Twig resolved
`index.twig` to the child's copy, and a real HTTP request rendered the
child's markup rather than this placeholder.

That means this file is what renders only on a site with no child theme,
or one whose child doesn't override it. It still has to be correct.

**`theme.uri`, not `theme.link`, for this theme's own assets** — a real
bug this split surfaced. Timber's theme object exposes both and they
differ:

- `theme.link` → `get_stylesheet_directory_uri()` → the **child** theme.
- `theme.uri` → `get_template_directory_uri()` → **this parent** theme.

This template originally used `theme.link` for its logo, which was correct
while this was the active theme and silently became a 404 the moment a
child was activated, since the icon lives here in the parent. Confirmed by
reading Timber's own `src/Theme.php` rather than assuming
(`$this->uri = $this->theme->get_template_directory_uri()` versus
`public function link() { return $this->theme->get_stylesheet_directory_uri(); }`).
Anything a *child* theme owns uses `theme.link` — see the child starter's
`views/AGENT.md`.

## Why this exists now, and why it's this minimal

The theme's own admin-facing surface (forms, SMTP, tracking, schema,
security, retention) had gotten fairly built out with nothing at all on
the front end — visiting a fresh copy of this theme showed a blank white
page, since `index.php` was previously empty and Twig, though
bootstrapped via `inc/timber.php` since early on, had zero actual
`.twig` files anywhere. That's a deliberate sequencing choice, not an
oversight — a base theme's front-end templates are exactly the part
every project replaces, so there was no reason to build them speculatively.
This page exists purely to close the "blank white screen" gap, not to
start building a real template system — no header/footer partials, no
page templates, no block patterns. That's the next real project's job,
copying this theme and building from here.

## Verified

A real HTTP request to the dev site's front page returned `200`, with
`wp_head()`'s output (block-library CSS, etc.)
correctly appearing before this template's own `<style>` block,
`body_class()` correctly rendering real classes
(`class="home blog wp-theme-devbase_parent_theme"`), `wp_footer()`'s
output correctly appearing before
`</body>`, `site.language_attributes` correctly reflecting the real site
locale (`lang="fr-CA"`), and the logo `<img>` resolving to a real `200`
on its own. Separately, with a real GTM ID temporarily configured, the
GTM `<noscript>` snippet appeared in the actual rendered output — proof
`wp_body_open()` fires, not just that the markup looks right.
