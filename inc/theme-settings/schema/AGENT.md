# inc/theme-settings/schema/ — the Schema Markup sub-page

Lets a site emit schema.org JSON-LD structured data sitewide without
touching a template. One parent sub-page (`schema-settings`, under Theme
Settings), with each schema type living in its own subfolder underneath.

**This is a deliberately short, curated list of types, not a general
mapping engine.** The alternative — a generic "pick any of schema.org's
867 types, detect the fields already on this content, map them yourself"
system — was seriously considered (SCF's own vendored `AI\GEO` engine
already does something close to that; see the "SCF's native schema
engine" section below) and rejected for this base theme: it's a
substantial thing to build and an ongoing one to maintain correctly
against schema.org's spec. Instead, this theme ships hand-picked types
that are (a) needed on nearly every site and (b) cheap to build well.
Anything else (Product, Recipe, FAQ, Review, industry-specific types) is
a per-project addition, not a base-theme concern.

```
schema/
  settings.php     — registers the "Schema Markup" sub-page itself, empty
  AGENT.md            — this file
  organization/         — the Organization schema type
    AGENT.md               — full breakdown, see there
    settings.php
    inject.php
  website/              — the Website schema type
    AGENT.md               — full breakdown, see there
    settings.php
    inject.php
  breadcrumb/            — the Breadcrumb (BreadcrumbList) schema type
    AGENT.md               — full breakdown, see there
    settings.php
    inject.php
```

## The pattern: one subfolder per schema type, one tab each

Same rationale as `inc/theme-settings/`'s "one subfolder per sub-menu"
(see the parent `inc/theme-settings/AGENT.md`), one level deeper: Schema
Markup is a single sub-*page*, not a sub-*menu* per schema type — WordPress
doesn't get a new admin menu item for each one. Each type gets its own
**tab** on that one page (like Security's Turnstile/reCAPTCHA tabs —
`inc/theme-settings/security/settings.php`), not a separate stacked field
group box: three independent `acf_add_local_field_group()` calls would
render as three separate boxes stacked vertically, since ACF's `tab`
field only groups fields *within* one field group.

To make that work while still keeping "one subfolder per type," each
type's `settings.php` doesn't call `acf_add_local_field_group()` itself —
it registers a **`devbase/schema/tabs` filter contribution** instead:
`add_filter( 'devbase/schema/tabs', function ( $tabs ) { $tabs[] =
array( 'label' => '...', 'fields' => array( ... ) ); return $tabs; } )`.
This file's own `settings.php` collects every contribution on `acf/init`
priority 20 (deliberately later than the default 10), prepends a `tab`
field ahead of each type's fields automatically, and registers the single
merged field group (`group_devbase_schema`) itself. **Contributions must
use a plain top-level `add_filter()` call, not one wrapped inside an
`acf/init` callback** — `add_filter()` just registers a callback, so it
has to already be registered by the time this file's priority-20 callback
runs and applies the filter; wrapping it in `acf/init` at the default
priority 10 still runs *before* priority 20 today, but a top-level call
is the actually-guaranteed-safe form, independent of hook priorities.

When adding a new schema type (on a project basis — this base theme's own
list is Organization, Website, and Breadcrumb, deliberately):

1. Create `inc/theme-settings/schema/<type>/`, with its own `AGENT.md`.
2. `settings.php` inside it registers a `devbase/schema/tabs` filter
   contribution (see the pattern above) — not its own field group, and
   not a `tab` field of its own (the assembler adds that). Some types
   need no fields at all beyond an enable toggle (Breadcrumb is the
   extreme example — it's entirely computed, not entered).
3. `inject.php` inside it reads those fields (or the current request, for
   something computed like Breadcrumb) and prints that type's own
   `<script type="application/ld+json">` block in `wp_head`. Each type
   prints its own separate `<script>` tag rather than merging into one
   JSON-LD object — keeps types fully independent (one being disabled or
   broken can't affect another's output).
4. Require both files explicitly from `functions.php`. Require order
   relative to the other types and to this file's own `settings.php`
   doesn't matter — filter registration and filter application are
   already decoupled by the mechanism above.
5. Link the new subfolder's `AGENT.md` from this file's list below.
6. If the new type's output depends on conditional tags
   (`is_singular()`, `is_front_page()`, etc.) rather than just field
   values, read the testing note in
   `inc/theme-settings/schema/breadcrumb/AGENT.md` first — a bare
   `do_action('wp_head')` from the CLI will not exercise those correctly.

## `settings.php` — the parent page and tab assembler

Registers the top-level "Schema Markup" sub-page via
`acf_add_options_sub_page()` (`parent_slug: theme-settings`), then
collects every `devbase/schema/tabs` contribution and registers them as
one field group with a `tab` field ahead of each type — see the pattern
above. This file owns the *only* `acf_add_local_field_group()` call for
the whole Schema Markup page; no type's own `settings.php` should call it
directly, or it'll render as a separate stacked box instead of a tab.

## Organization

See **`inc/theme-settings/schema/organization/AGENT.md`** for the full
breakdown: the field list (including a **Locations** repeater with a
per-row "Main" toggle, not a single address group — a business can have
more than one location), why it's printed in `wp_head` and only when
actually valid, and a real `post_id`/`'option'` storage bug this surfaced
(values were being saved under the shared options store but read back
from the wrong one, so the toggle would have silently done nothing).

## Website

See **`inc/theme-settings/schema/website/AGENT.md`**: why this type needs
almost no fields (name/url/description all already exist as WordPress or
Organization data), the optional Sitelinks Search Box, and how
`publisher` links to Organization instead of duplicating its fields.

## Breadcrumb

See **`inc/theme-settings/schema/breadcrumb/AGENT.md`**: why it has zero
content fields (a `BreadcrumbList` is computed from the page's position
in WordPress's own hierarchy, not entered by an admin), exactly which
cases it covers and which it deliberately doesn't (no category-based
trails for blog posts — that's a per-project decision), and a real testing
pitfall this surfaced: `do_action('wp_head')` alone from a CLI script
does **not** make `is_singular()`/`is_front_page()`/etc. reflect a
specific URL — `wp()` has to be called explicitly first.

## SCF's native schema engine — why this theme doesn't use it (yet)

`vendor/secure-custom-fields/src/AI/GEO/` (SCF 6.9.5, a port of ACF PRO
6.8's feature) already provides a per-field "Schema.org Property" mapping
UI backed by the full schema.org vocabulary (867 types / 1,509
properties), reusable via `GEO::process_fields()` and
`GEO::render_jsonld_script()`. It's genuinely real, not vaporware — but
it's flagged `'enable_schema' => false` by default and marked
"Experimental" throughout, and its automatic `wp_head` output only covers
singular pages of an SCF-UI-registered post type and ACF blocks — there's
no built-in options-page output path, which is what every type in this
folder actually needs (sitewide data, not per-post data). Revisit this if
that engine matures and gains options-page support; for now, hand-written
`inject.php` per type (with the same JSON-LD escaping discipline SCF's
own `render_jsonld_script()` uses) is the simpler, working path.
