# inc/theme-settings/tracking/ — Tracking sub-page

Lets a site configure Google Tag Manager / GA4 and arbitrary raw
head/footer scripts (Meta Pixel, Hotjar, anything GTM can't cover cleanly)
without editing template code per project.

```
tracking/
  settings.php   — registers the sub-page + its three-tab field group
  inject.php       — actually outputs the configured scripts on the front end
  AGENT.md            — this file
```

Same split as `smtp/settings.php` + `smtp/mailer.php`: `settings.php`
only defines fields, `inject.php` is the only file that echoes anything
(GTM/GA4/custom scripts).

## `settings.php`

Sub-page (`menu_slug: tracking-settings`, under `theme-settings`) with
two tabs:

- **Analytics** — `gtm_id` (Google Tag Manager container ID) and `ga4_id`
  (GA4 measurement ID), both optional text fields. Either, both, or
  neither can be set — `inject.php` only outputs what's actually filled
  in.
- **Custom Scripts** — `header_scripts` and `footer_scripts`, raw
  textareas output verbatim (see the escaping note below).

## No cookie consent (removed 2026-10-11)

A third **Cookies** tab and a `consent/` subfolder used to live here — a
vanilla-cookieconsent banner wired to Google Consent Mode v2, gating the
Analytics tracking this file outputs. Both are gone, along with
the extracted module's own copy of `Test_Cookie_Consent.php`.

**Why:** not every client needs a consent banner, and the ones that don't
were still carrying it — code to load, CSS to keep working across library
versions, and a tab to explain. For a base theme that ships to every site,
that's the wrong trade. Consent is also the kind of requirement that
varies by jurisdiction and client appetite in ways a single fixed
implementation can't serve well.

**Where it went:** extracted whole, with its docs, to
`~/Desktop/devbase-cookie-consent/` (and a zip beside it). That package
is self-contained — it includes the Cookies tab's field definitions, which
`consent.php` reads by name and without which the module is inert — and
its `README.md` has the reinstall steps plus the eight gotchas that were
learned the hard way (the paired-colour rule, the `padding` shorthand
collision, the flex `min-height: 0`, the forced-small preferences panel,
and so on).

**Per project, pick one:** reinstall that module, use a real plugin
(Complianz / CookieYes / Cookiebot — the right answer if a client needs a
consent audit trail, geo rules or scanning, none of which the module did),
or build it for that site. Not a base-theme concern either way.

**One thing this leaves behind:** the Analytics tracking below is now
ungated. GTM/GA4 load unconditionally when their IDs are set. On a site
that needs consent, gating is part of whatever consent solution that
project adopts — `inject.php` has no opinion about it and never did (it
was always Consent Mode signalling, never blocking the snippet).

## `inject.php`

Three hooks:

- **`wp_head`** — GTM's standard `<head>` snippet (if `gtm_id` is set),
  GA4's `gtag.js` snippet (if `ga4_id` is set), then `header_scripts`
  verbatim.
- **`wp_body_open`** — GTM's `<noscript>` fallback, per Google's own
  installation instructions (it belongs right after `<body>`, not in
  `<head>`). Fires for real: `views/index.twig` calls
  `{{ function('wp_body_open') }}` right after its opening `<body>` tag
  (see `views/AGENT.md`) — confirmed live with a real GTM ID configured,
  the `<noscript>` snippet actually appears in the rendered page. Any
  future project-specific template needs the same call
  (`{{ function('wp_body_open') }}` in Twig, or `wp_body_open();` in
  plain PHP) right after its own `<body>` tag, or this goes back to
  firing nothing for that template.
- **`wp_footer`** — `footer_scripts` verbatim, right before `</body>`.

**Deliberately unescaped output.** `header_scripts`/`footer_scripts` are
echoed as raw HTML/JS on purpose — that's the entire point of a "paste a
tracking snippet here" field, and escaping it would break every real-world
snippet (which is always `<script>` tags). This is only as safe as who has
access to Theme Settings: anyone who can edit these fields can already run
arbitrary JS on the site, the same trust boundary as anyone who can edit
theme files directly.
