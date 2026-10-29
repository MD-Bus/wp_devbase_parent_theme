# languages/ — translations for this theme

Everything related to translating this theme's user-facing strings (field
labels, options page titles, error/success messages) into other
languages. Currently translated: **French (France, `fr_FR`)**, **French
(Canada, `fr_CA`)**, and **French (Belgium, `fr_BE`)**.

```
languages/
  AGENT.md                    — this file
  i18n.php                      — loads the .mo file for the current site locale
  devbase_parent_theme.pot         — template listing every translatable string
  fr_FR.po / fr_FR.mo               — French (France) source + compiled translation
  fr_CA.po / fr_CA.mo               — French (Canada) source + compiled translation
  fr_BE.po / fr_BE.mo               — French (Belgium) source + compiled translation
```

## The mechanism: it's just string matching, not CPT/page-aware

Every translatable string in this theme is wrapped in `__( 'string',
'devbase_parent_theme' )` or `_e( ... )` — that's gettext, the underlying
system. It has **no concept of "options page," "field," or "CPT."** At
runtime it just looks at the literal English text passed to `__()` for
the `devbase_parent_theme` domain and swaps in a matching translation if
one exists — nothing structural about where that string is used matters.

That's why one `fr_FR.po` file covers SMTP Settings, Security, and
Tracking with zero per-page logic: every `__( 'Site Key',
'devbase_parent_theme' )` call anywhere in the theme resolves against the
same `msgid "Site Key"` entry, whichever tab or page it's rendered on.

The tradeoff: it's an **exact** string match. Change capitalization,
punctuation, or even trailing whitespace in the English string and it
becomes a *different* `msgid` — silently falls back to English until a
new `.po` entry is added for the new exact text. If a change to a
label's English text is ever made, the corresponding entries in
`fr_FR.po`/`fr_CA.po` need updating in the same change, and the `.mo`
recompiled (see below) — otherwise that one string quietly reverts to
English while everything else stays translated.

## `.po` vs `.mo`

- **`.po`** ("Portable Object") — the human-editable source. Plain text,
  one `msgid`/`msgstr` pair per string. This is what a translator (or
  Poedit, or Loco Translate) actually opens and edits.
- **`.mo`** ("Machine Object") — a compiled binary built *from* the
  `.po`. This is what WordPress actually reads at runtime; not meant to
  be hand-edited.

**Editing a `.po` file alone does nothing** — the `.mo` has to be
recompiled afterward, or WordPress keeps serving the old translation (or
falls back to English if none existed yet). Ways to compile:

- **`msgfmt`** (part of GNU gettext, installed via Homebrew on this
  machine) — `msgfmt -o fr_FR.mo fr_FR.po`. What was actually used to
  build the `.mo` files currently in this folder.
- **WP-CLI** — `wp i18n make-mo languages/fr_FR.po` (equivalent).
- **Loco Translate** (a normal wp-admin plugin, not a dependency of this
  theme) — compiles automatically on save through its UI, no terminal
  needed. Convenient for a non-technical translator.
- **Poedit** (a desktop `.po` editor) — also auto-compiles on save.

## `i18n.php`

```php
add_action( 'after_setup_theme', function () {
	load_theme_textdomain( 'devbase_parent_theme', get_template_directory() . '/languages' );
} );
```

Required for any `__()`/`_e()` call in this theme to pick up a
translation at all — without this running, a `.mo` file existing changes
nothing. Lives in this folder (not `inc/`) because it's really part of
the translation system, not a general bootstrap concern.

## The file naming rule — and a real bug this caused

WordPress matches `.mo` files by the site's configured language
(Settings > General > Site Language, or per-user). The naming convention
**depends on where the file lives**:

- **Centrally-managed language packs**, in
  `wp-content/languages/themes/` (where WordPress.org auto-downloads
  theme translations to), are named `{domain}-{locale}.mo` — e.g.
  `devbase_parent_theme-fr_FR.mo`.
- **Files bundled inside the theme's own directory** (this folder,
  passed to `load_theme_textdomain()` as `$path`) must be named **just
  `{locale}.mo`** — e.g. `fr_FR.mo`, no domain prefix. WordPress's
  `_load_textdomain_just_in_time()` decides which rule applies based on
  whether the registered path starts with the theme's own directory —
  since `languages/` is inside `get_template_directory()`, it's always
  this second, no-prefix case.

Using the wrong naming scheme **doesn't error** —
`load_theme_textdomain()` still returns `true` — it just silently never
resolves any translation, and every string quietly falls back to
English. This actually happened building this theme: the `.mo` files
were first written as `devbase_parent_theme-fr_FR.mo` (the
WordPress.org-style name), and nothing translated even with the site's
`WPLANG` genuinely set to `fr_FR`. Confirmed and fixed by renaming to
`fr_FR.mo` / `fr_CA.mo` and re-testing against a real WordPress bootstrap
with the site locale actually set to each value (not just `switch_to_locale()`
mid-script — that has its own footgun, see below).

**Files in this folder must stay named `{locale}.mo`** — not
`devbase_parent_theme-{locale}.mo` — or they silently stop working the
same way.

## A testing footgun worth remembering

Verifying translations by calling `switch_to_locale()` +
`unload_textdomain()` + `load_theme_textdomain()` repeatedly in one PHP
process (to test multiple locales without restarting) looks reasonable
but **doesn't reflect reality**: `unload_textdomain()` marks the domain
as "not reloadable" (`$l10n_unloaded`), which then blocks WordPress's own
just-in-time reloading from working — producing a false failure that
looks exactly like the naming bug above, but isn't. The reliable way to
verify a locale actually works is to set it as the real site
locale (`update_option( 'WPLANG', 'fr_FR' )`) and bootstrap WordPress
fresh in a new process — no `switch_to_locale()`/`unload_textdomain()`
juggling — since that's what an actual page load does.

## Three French locales, not one

`fr_FR`, `fr_CA`, and `fr_BE` are treated as **completely separate
locales** by WordPress — there's no automatic fallback between them, so
each needs its own `.mo` file even where the strings are identical.
France and Québec French differ in some everyday vocabulary relevant to
this theme's actual strings — France uses "e-mail"/"indésirables",
Québec conventionally uses "courriel"/"pourriels" — reflected in the
small wording differences between `fr_FR.po` and `fr_CA.po`. Belgian
French matches France French for this kind of technical/administrative
vocabulary (no "courriel"-style divergence the way Québec has), so
`fr_BE.po` is currently identical in content to `fr_FR.po` — still a
separate file, because WordPress needs one to ever load a translation
for a site whose locale is `fr_BE`, but nothing here artificially
invents a wording difference that doesn't exist.

**Setting a site to `fr_BE` for testing needs one extra step the other
two didn't**: `update_option( 'WPLANG', 'fr_BE' )` silently fails
(returns `false`, value doesn't change) unless WordPress core's own
`fr_BE` language pack is already installed — `WPLANG`'s sanitizer
restricts to `get_available_languages()`, and a locale having a `.mo` in
*this* theme's `languages/` folder doesn't put it on that list; that list
is WordPress core's own installed UI translations, a completely separate
thing from this theme's translations. Trigger the download first (same
as picking it from Settings > General > Site Language in wp-admin would):

```php
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/translation-install.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
wp_download_language_pack( 'fr_BE' );
```

After that, `fr_BE` appears in `get_available_languages()` and
`update_option( 'WPLANG', 'fr_BE' )` works normally. This is a one-time
thing per site (or per test-DB) — once the core pack is downloaded it
stays available.

## Adding another language

Copy `devbase_parent_theme.pot`, translate each `msgstr`, save as
`{locale}.po` in this folder, then compile it (see the `.po` vs `.mo`
section above). If using Loco Translate, double-check the filename it
writes — it sometimes defaults to the domain-prefixed convention meant
for the centralized `wp-content/languages/themes/` location; if it writes
`devbase_parent_theme-{locale}.mo` into *this* folder, rename it to just
`{locale}.mo` per the rule above, or it won't take effect.

If the site runs a multilingual plugin instead (WPML, Polylang, etc.),
those typically manage their own string translation UI and may not need
a `.mo` file here at all — check that plugin's own documentation for how
it handles theme strings wrapped in `__()`.

## Keeping the `.pot`/`.po` files current — no `wp i18n make-pot` here

WP-CLI isn't installed on this machine, so there's no automated
extraction tool — every string added to the theme in `inc/forms/`,
`inc/helpers/`, and `inc/theme-settings/destinations/`+`miscellaneous/`
(the form-logging/notification/retention feature) was found by grepping
for `__(`/`_e(`/`_n(` across those directories, added to
`devbase_parent_theme.pot` (English source, empty `msgstr`) and all three
`.po` files, then recompiled with `msgfmt`. If WP-CLI is ever installed,
`wp i18n make-pot . languages/devbase_parent_theme.pot` would do this
extraction automatically and is worth switching to — but be aware it
regenerates the `.pot` from scratch (including file/line-number comments
this repo's `.pot` doesn't currently carry), so diff carefully before
overwriting.

The Licence sub-page, the update-status strings
(`inc/theme-settings/licence/`) and the dependency/MCP last-run status
messages (`inc/dependencies/`) were added the same way — grepped for `__(`/`esc_html__(` across that
folder, added to the `.pot` and all three `.po` files, recompiled with
`msgfmt`, and then verified by actually resolving several of them against
this dev site's real `fr_CA` locale rather than assuming the compile
worked. Note `inc/updates/` itself contains no translatable strings by
design — it logs to `error_log()` for developers and renders nothing.

**`test-form.php` is deliberately NOT translated** — its markup is plain
hardcoded English, not wrapped in `__()`. It's a standalone proof-of-concept
dev tool (see `inc/forms/AGENT.md`), not part of the theme's real
user-facing surface, so it doesn't need to follow the same translation
discipline as everything else.

**`_n()` (plural forms) note**: `%d submission deleted.` /
`%d submissions deleted.` (the "Run Purge Now" result) is this theme's
first use of `_n()`. The `.po` files' existing
`"Plural-Forms: nplurals=2; plural=(n > 1);\n"` header (French: 0 and 1
are both singular, unlike English) was already correct for this — no
header change needed, just the `msgid`/`msgid_plural`/`msgstr[0]`/`msgstr[1]`
block itself.

## A real test bug this surfaced — assert on the translated string, not the English literal

Adding these translations exposed a genuine, previously-mischaracterized
bug in the cookie-consent tests (that feature was removed from this theme on 2026-10-11; see
`tests/AGENT.md`): two tests asserted `assertStringContainsString( 'We use
cookies', $js )` / `'Learn more'` — the literal **English** fallback
text — against a theme function that calls `__()` on that exact text.
This dev site's real locale is genuinely `fr_CA` (confirmed via
`get_locale()`, not assumed), and now that real French translations exist
and load, that fallback text renders in French, not English — so those
two assertions failed. They'd been treated as "2 pre-existing unrelated
failures" throughout this whole feature's build (several prior commit
messages describe them that way) without ever being root-caused. Once
translations for `We use cookies`/`Learn more` actually existed to
trigger the mismatch, the real cause became obvious and was fixed:
compare against `__( 'We use cookies', 'devbase_parent_theme' )` /
`__( 'Learn more', 'devbase_parent_theme' )` (locale-independent by
construction — always matches whatever `get_locale()` currently resolves
to) rather than a hardcoded English string. **Lesson for any future test
in this theme**: never assert on a literal English string for text that
passes through `__()`/`_e()`/`_n()` — call the same translation function
in the assertion, the same way this file's own tests do for translated
config values.
