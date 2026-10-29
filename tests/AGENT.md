# tests/ — automated testing (dev-only, not shipped)

Before this existed, everything non-trivial in this theme (mutual
exclusivity in Security Settings, the dependency updater's staged swap,
SMTP's conditional-required fields) was verified by hand, one-off, via a
`php -d mysqli.default_socket=... -r "..."` script re-typed every time
something changed. This folder automates that instead — same underlying
technique (bootstrap real WordPress against a real database, then check
real behavior), but repeatable and run with one command.

```
tests/
  AGENT.md                          — this file
  composer.json                       — PHPUnit's own dependencies, see below
  composer.lock
  vendor/                                — gitignored; `composer install` here once
  bootstrap.php                            — boots the WP core test suite, forces this theme active
  wp-tests-config.php                        — DB connection + ABSPATH for the test run
  Test_Security_Settings.php                   — first real test, covers Security Settings
  Test_Form_Submission.php                         — covers devbase_form_log_submission(),
                                                        including its nonce (CSRF) gate
  Test_Form_Build_Content.php                        — covers devbase_form_build_content()
  Test_Form_Dispatch.php                               — covers the save_post_form_data dispatch
                                                            orchestrator + double-fire guard
  Test_Post_Type_Capabilities.php                        — covers registered_form/form_data's
                                                              admin-only capability mapping,
                                                              incl. registered_form's delete lockout
  Test_Registry_Prune.php                                  — covers registry/sync.php's
                                                                devbase_forms_prune_deregistered()
                                                                + devbase_forms_strip_apply_to()
  Test_Retention_Policy.php                                — covers the data-retention purge
  Test_System_Status.php                                     — covers the System Status tab's
                                                                  three data sources
  Test_MCP_Abilities_Post_Types.php                            — covers inc/abilities/post-types.php
  Test_MCP_Abilities_Posts.php                                   — covers inc/abilities/posts.php
  Test_MCP_Abilities_Options_Pages.php                             — covers inc/abilities/options-pages.php
  Test_Dependency_Updater.php                                       — covers the (now ungated)
                                                                        SCF/ASE updater
  Test_Warden_Updates.php                                             — covers inc/updates/ — the
                                                                          signed Warden client and
                                                                          the theme-update wiring
  Test_ASE_Overrides.php                                                — covers the two child-theme
                                                                            ASE override hooks
  Test_Child_CPT_Integration.php                                          — covers what a child-registered
                                                                              CPT gets for free
  Test_MCP_Child_CPT.php                                                    — covers MCP content ops
                                                                                against a child CPT
```

## Why `tests/composer.json` is separate from the theme root's `composer.json`

PHPUnit and its dependencies used to live in the theme root's own
`composer.json`, under `require-dev`. They were moved here entirely —
not just excluded from a shared install — because the theme root's
`vendor/` is now **committed directly to git** (see `inc/dependencies/AGENT.md`
for the full reasoning: a fresh clone must work with zero `composer
install` ever required, even if a WordPress.org listing or GitHub release
vanishes someday). That property is fundamentally incompatible with
PHPUnit sharing the same `vendor/autoload.php`: Composer's generated
autoloader unconditionally `require`s every package's "files"-type
autoload entries at boot (not lazily — confirmed by reading the generated
`autoload_real.php` directly), so a `--no-dev`-clean autoloader
structurally cannot know PHPUnit's classes exist at all, dev-inclusive or
not. One shared file can't correctly serve both "always installable
offline" and "PHPUnit runs" at the same time. Splitting them into two
separate `composer.json`/`vendor/` pairs resolves it cleanly: the theme
root's `vendor/` stays pure runtime (safe to commit, never references a
dev-only package), and `tests/vendor/` stays exactly what it always
effectively was — dev-machine-only tooling, gitignored, installed once
with its own `composer install` run from inside this folder.

## `Test_Form_Build_Content.php`

Covers `inc/helpers/forms/build-content.php` (`devbase_form_build_content()`):
the plain-string-label shorthand defaulting to `sanitize_text_field()`,
an array spec with a custom `sanitize` callable, an array spec that omits
`sanitize` falling back to the default, a missing `$_POST` key becoming
`''` rather than a notice, and — the test that actually matters most —
feeding this function's output straight into
`devbase_form_log_submission()` and asserting the round-trip, which
proves the two functions are shape-compatible rather than just visually
similar.

## `Test_Form_Submission.php` + `Test_Form_Dispatch.php`

Cover `inc/helpers/forms/log-submission.php` (`devbase_form_log_submission()`)
and `inc/forms/` — see `inc/helpers/forms/AGENT.md` and `inc/forms/AGENT.md`
for the feature itself. `Test_Form_Submission` checks the CSRF nonce gate
first — a missing nonce blocks, and (the case that actually proves the
per-form namespacing works, not just "any nonce") a nonce valid for a
DIFFERENT form name also blocks — then the spam gate blocks logging (no
`form_data` post created) when a CAPTCHA provider is active and no token
is submitted, and that a clean submission (with a valid nonce) round-trips
`form_name`/`content` correctly through postmeta. `Test_Form_Dispatch`
checks a destination whose "Applies To" doesn't include the submitted
form is skipped rather than firing, that two destinations with different
`apply_to` values only fire the matching one, and — the one genuinely
tricky case — that the `_devbase_dispatched` guard actually prevents a
second webhook call on a manual re-save, verified via WordPress's
`pre_http_request` filter faking the network call and counting how many
times it fired (same "test against real WP hook behavior, not a
hand-rolled mock" approach `Test_Security_Settings.php` already uses for
`acf/validate_save_post`, and `smtp/test.php` uses for `wp_mail_failed`).

`Test_Form_Submission.php` also has
`test_content_with_literal_quotes_round_trips_correctly` — a real
regression test for a real bug: `update_post_meta()`/`meta_input` both
call `wp_unslash()` internally, which silently strips the `\"` escapes
`wp_json_encode()` adds around a literal quote character, corrupting
stored JSON unless the encoded string is `wp_slash()`'d first. See
`inc/forms/dispatch/AGENT.md`'s "A real bug this surfaced" section for the
full story — this pattern (`wp_slash( wp_json_encode( $x ) )` before any
`update_post_meta()`/`meta_input` call) applies to any future code in
this theme that stores encoded JSON as postmeta, not just forms.

These two files validate logic only. The full pipeline was also verified
manually end to end against this real dev site (real HTTP POST through
`test-form.php`'s `admin-post.php` handler → real DB row → real
`wp_remote_post()` reaching a local capture server with the correct JSON,
plus a real submission with a missing nonce redirecting to `blocked=1`
and one with the correct nonce redirecting to `submitted=1`) — see
`inc/forms/AGENT.md`'s "Verified end to end" section for exactly what
was checked and how.

## `Test_Post_Type_Capabilities.php`

Covers `inc/forms/post-types.php`'s admin-only (`manage_options`)
capability mapping on `registered_form`/`form_data`. Exists specifically
as a regression test for a real, serious bug caught while building this:
mapping the CPTs' singular meta caps (`edit_post`/`read_post`/`delete_post`)
directly to the literal string `'manage_options'` made WordPress register
a global reverse-lookup that hijacked EVERY `manage_options` check on the
entire site, not just these two CPTs — `map_meta_cap('manage_options',
$user_id)` returned `['do_not_allow']` for every user, administrators
included. `test_manage_options_is_not_hijacked_by_post_type_registration`
asserts the global `$post_type_meta_caps` array never gains a
`'manage_options'` key, plus that `map_meta_cap()` still resolves
`manage_options` to itself. Confirmed this test actually catches the
regression by deliberately reintroducing the bug and re-running it before
reverting — it failed exactly as expected. The other two tests confirm
the practical effect: a real administrator can list and edit a specific
post on both CPTs, a real Editor can do neither, checked via
`current_user_can()` at both the list level and the specific-post level.
Also covers `registered_form`'s delete-capability lockout added
afterward (`test_administrator_cannot_delete_registered_form`,
`test_administrator_can_still_delete_form_data`) — see
`inc/forms/AGENT.md`'s "Round 6" — `form_data`'s create-capability
lockout added after that (`test_administrator_cannot_manually_create_form_data`)
— see "Round 7" — and `registered_form`'s own create-capability lockout
added right after (`test_administrator_cannot_manually_create_registered_form`,
replacing an earlier test that had asserted the opposite) — see "Round 8".

## `Test_Registry_Prune.php`

Covers `inc/forms/registry/sync.php`'s
`devbase_forms_prune_deregistered()` and
`devbase_forms_strip_apply_to()` — the half of the registry sync that
deletes a `registered_form` post once its name is no longer in
`registered-forms.json`, and cleans up the dangling `apply_to` reference
that would otherwise leave on Form Destinations/Retention Policy. Calls
both functions directly with an explicit `$names_in_json` array rather
than exercising the full `devbase_forms_sync_registry()` against the
real JSON file (that file is production data, not something a test
should edit). Checks: a form still in the list survives, one removed
from the list gets force-deleted (not trashed), a no-op prune changes
nothing, and — the case that matters most — a destination/retention row
with multiple forms in `apply_to` keeps the surviving ones and only loses
the deregistered one, while a row that ONLY applied to the deregistered
form ends up with an empty `apply_to` rather than erroring.

## `Test_Retention_Policy.php`

Covers `inc/forms/retention.php`. The day-count map matches the
documented values; a disabled toggle is a genuine no-op (a matching,
backdated submission survives); a purge only deletes submissions both
past their applicable cutoff AND covered by that policy row's
`apply_to` — a recent submission and an old submission for an unrelated
form both survive in the same test; a form covered by two rows with
different periods is purged at the shorter one; and the "last run"
recording only fires when the job actually executes (never for a
disabled toggle), correctly records a real no-op run (0 deleted, not
"never run"), and the message flags a run older than ~36 hours as
possibly stale. Also verified manually against the real dev site: a
genuinely backdated (10-day-old) submission plus a genuinely recent one,
a real `1_week` policy, running the real purge function and confirming
via direct `get_post()` checks afterward — exactly one deleted, the
recent one survived.

**The "0 deleted" assertion is intentionally NOT a literal string match**
— `devbase_forms_retention_last_run_message()`'s text passes through
`__()`, so (same lesson "Never assert on an English literal" documents
further down this file) the test checks the underlying
`get_option( 'devbase_retention_last_run' )`
data directly, plus a locale-safe regex for the digit `0` in the
rendered message (digits aren't translated by `%d`), rather than
asserting the English word "deleted" appears.

## `Test_System_Status.php`

Covers `devbase_smtp_last_test_message()` (SMTP),
`devbase_forms_get_recent_dispatch_summary()`/`devbase_forms_dispatch_status_message()`
(Form Notifications), reusing `Test_Retention_Policy.php`'s coverage for
the Retention Policy line. Checks: SMTP status text differs correctly
for disabled/never-tested/succeeded/failed; the dispatch summary counts
successes and failures across multiple submissions and caps its failure
list; the "no data yet" and "all succeeded" messages render correctly.

**Every assertion compares against the actual translated string, not a
hardcoded English literal** — this file was written AFTER the
`Test_Retention_Policy.php` lesson (see `languages/AGENT.md`), and still hit the exact same bug on first write:
five of its assertions initially checked for English substrings like
"disabled" or "succeeded", which failed against this dev site's real
`fr_CA` locale the moment real translations existed for those specific
strings. Fixed by rebuilding the *exact* expected string with `__()`/
`esc_html__()` and the same `sprintf()` arguments the real function
uses (`assertSame`, not `assertStringContainsString`) wherever the
message is deterministic, and only asserting on raw untranslated data
(a destination label, an HTTP status string — never sentence text) where
it isn't. **The pattern to actually follow going forward, restated
once more because it's been missed twice already**: never assert on a
literal English string for text that passes through
`__()`/`_e()`/`_n()`/`esc_html__()` in this theme.

## `Test_Dependency_Updater.php`

Replaced `Test_Dependency_Updater_Version_Picker.php`, which covered
`devbase_pick_target_version()` — a function deleted along with the whole
Cloudflare Worker approval gate (see `inc/dependencies/AGENT.md`). Covers
what the updater does now: the slug→main-file map, WordPress.org's
plugin-information API lookup, and the forward-only rule.

The three failure cases matter most — an unreachable WordPress.org, a
malformed body, and a non-200 all resolve to `null` rather than a guessed
version, because a guess here would trigger a real folder swap. And the
one test that lets a download begin deliberately returns a `WP_Error` for
the download URL, so `devbase_apply_dependency_update()` bails at its own
`is_wp_error()` check and the real `vendor/` folder is never touched by
the test suite.

Also covers the **scheduling**, which is the part most likely to regress
into silence: both checks must be recurring `daily` events registered from
`init` (not one-off events, and not `admin_init` — see
`inc/dependencies/AGENT.md`'s "The check cadence" for why that distinction
decides whether a client site ever updates at all), scheduling must be
idempotent across repeated `init` firings, and `switch_theme` must clear
both. Plus the last-run record: a run that changed nothing is still
recorded, failures are recorded as errors, and the status message
distinguishes never-run / up-to-date / updated / stale.

Those status assertions compare against `__()`-resolved strings or raw
non-translated data (a slug, a version number, the `⚠` marker), never an
English literal — this dev site's real locale is `fr_CA` and these strings
ship real translations. See "Never assert on an English
literal" further down for the bug that rule exists for.

And MCP Adapter's forward-only check: a GitHub release *older* than what's
installed must not trigger a downgrade swap. That was a real latent bug —
the comparison was string equality until the scheduling rework.

## `Test_Warden_Updates.php`

Covers `inc/updates/`. Notable for using a **real Ed25519 keypair
generated with libsodium**, signing real response bodies with it, rather
than stubbing signature verification out — that check is the only thing
between this theme and a hostile network handing it a theme download URL,
so a test that mocked it would assert nothing about the property that
actually matters. Each rejection path is driven by returning a genuinely
wrong value: signed with a different keypair, echoing the wrong nonce,
naming a different licence key, or carrying no signature header at all.

Also covers the documented request shape and nonce length bounds, a fresh
nonce per request, fail-soft on both a timeout and a 503 (the last known
good response stands, with the error recorded separately), the 12-hour
cache suppressing a second request, downgrade refusal, no offer on an
invalid licence, transient injection, the `no_update` entry that makes the
auto-update toggle appear, and that auto-update behaviour for unrelated
themes passes through untouched.

Not covered here, and worth a real pass before a client go-live: an
actual signed round-trip against a real Warden deployment. This dev site
has no Warden URL or licence key configured, so the client has only ever
been exercised against a locally-generated keypair.

## `Test_ASE_Overrides.php`

Covers `devbase/ase/defaults` and `devbase/ase/enforced` — the mechanism
that lets every client site share one identical base theme while running
different ASE security settings. Each semantic gets its own test because
this is the one extension point where picking the wrong hook fails
**silently**: a `defaults` override of a key the site has already saved
does nothing at all, which reads exactly like a broken filter.

Nine tests: the parent enforcing nothing by default, a default filling an
absent key, a default *failing* to change a stored key (the trap, asserted
deliberately), enforced overwriting a stored key, enforced being
reasserted after an admin changes it back, enforced beating a conflicting
default, unrelated keys staying untouched, the parent's own baseline
surviving a pass-through child filter (the shipped stub's shape), and a
filter returning a non-array being ignored rather than fatal.

**It calls the applier's logic directly rather than firing `admin_init`.**
Firing the real hook also runs ASE's own `register_sections_fields()`,
which calls the wp-admin-only `add_settings_section()` and fatals outside
a real admin request — a harness artifact in the same family as the
`add_meta_box()` screen-context and `wp()` conditional-tag ones documented
elsewhere in this file, not a bug. Hit for real while verifying this
against the live dev site; the fix there was
`require_once ABSPATH . 'wp-admin/includes/admin.php'` before
`do_action( 'admin_init' )`, which is what a real admin request loads
first.

## `Test_Child_CPT_Integration.php`

Covers the seam a client child theme leans on most: register a post type in
`inc/content/post-types.php` and three separate base-theme features pick it
up with no further wiring — an Archive options sub-page
(`inc/archive/settings.php`), its archive in the internal link picker
(`inc/archive/link-query.php`), and an MCP Access choice that stays
unexposed until opted in (`inc/abilities/helpers.php`). One file rather
than additions to each, because what's under test is the integration, not
any one feature.

The valuable assertion is the **timing** one. `inc/archive/` runs on
`wp_loaded` rather than `acf/init` precisely because `acf/init` fires
inside `init` at priority 5 — before a CPT registered the ordinary way at
priority 10 exists. Get that wrong and a client's archive options page
silently never appears, which is exactly the kind of failure nobody
notices for months.

**Three harness traps hit while writing this**, all worth knowing before
adding to it:

1. **Post type names are capped at 20 characters.** The first draft used
   `devbase_test_service` (21), so `register_post_type()` refused — and it
   does that via `_doing_it_wrong()`, not an exception, so the CPT simply
   never existed and all six tests failed in confusing ways rather than
   pointing at the cause.
2. **Don't re-fire `acf/init`.** SCF already ran it during bootstrap; a
   second run re-registers its `acf/field` block binding source, which
   WordPress reports through `_doing_it_wrong()` and wp-phpunit converts
   into a failure. Firing `wp_loaded` alone is sufficient here, because
   `acf_add_options_sub_page()` only queues config into an array that
   `acf_get_options_pages()` reads straight back.
3. **This environment uses plain permalinks**, so
   `get_post_type_archive_link()` returns `?post_type=…` rather than the
   rewrite slug. Assert on the link-picker result's `title`/`info`, not on
   the URL shape.

Also verified outside the suite, against the real dev site with the child
theme active and a real `service` CPT temporarily registered from the
child's own file: the archive page appeared with isolated storage
(`post_id: 'archive-service'`), the archive showed in the link picker as
`http://dev-blueprint.local/services/` with a negative synthetic ID, and
`service` was offered as an MCP choice while correctly staying out of
`devbase_abilities_content_post_types()`. The child file was restored
byte-identical afterwards.

## `Test_MCP_Child_CPT.php`

"Appears as a choice under MCP Access" and "MCP can actually edit it" are
two different claims. `Test_Child_CPT_Integration.php` covers the first;
this file covers the second — the full content cycle against a post type
the base theme has never heard of: `list-post-types`,
`get-post-type-schema`, `create-post`, `get-post`, `update-post`,
`list-posts`, and SCF custom field read/write on top.

It matters because `inc/abilities/posts.php` never touches post types
directly — it builds a `WP_REST_Request` and dispatches through
`rest_do_request()`, reusing core's posts controller and SCF's own `acf`
REST field. So a client CPT only works through MCP if it is genuinely
REST-exposed, and nothing in the base theme can guarantee that on the
child's behalf. Hence `test_a_child_cpt_that_is_NOT_rest_exposed_cannot_be_opted_in`:
`show_in_rest => false` isn't a degraded experience, it's unusable, so such
a type is never offered as a choice at all.

**Two return shapes pinned here**, because they're easy to get backwards
and an MCP client depends on them:

- `get-post-type-schema` puts the post type key in **`slug`**; `name` is
  the human label.
- `get-post` returns WordPress's REST shape **verbatim**, so
  `title`/`content`/`excerpt` arrive as `{rendered: …}` and not as flat
  strings. That's a direct consequence of the reuse-REST decision in
  `inc/abilities/AGENT.md`, not an oversight. SCF values ride along under
  `acf`.

Both were found by writing the assertions wrong first and probing the real
output — worth repeating rather than guessing if these abilities grow.

**One more harness trap, and this one only appears in the full suite.**
A post type registered in `set_up()` *after* an earlier test file already
caused the REST server to be built gets no route for itself, so anything
going through `rest_do_request()` fails with `rest_no_route` (404) — while
the same file passes 13/13 in isolation, because then it runs first.

Use the two helpers in `bootstrap.php` rather than retyping the fix:

```php
public function set_up() {
    parent::set_up();
    register_post_type( self::CPT, array( /* … */ ) );
    devbase_tests_rebuild_rest_server();
}

public function tear_down() {
    devbase_tests_reset_rest_server();
    parent::tear_down();
}
```

**Any future test that registers a post type and then exercises anything
REST-backed needs this** — and needs running both ways, because passing in
isolation proves nothing. There is no CI, so "run the full suite" is a
human habit, not an enforced one; this exact bug was caught only because
the full suite happened to be run afterwards.

**Why production is not affected** — verified from core, not assumed.
WordPress builds the REST server *lazily*: `rest_get_server()` constructs
it only `if ( empty( $wp_rest_server ) )`, and that call is what fires the
`rest_api_init` action whose priority-99 `create_initial_rest_routes()`
enumerates post types. The `add_action( 'init', 'rest_api_init' )` in
`wp-includes/default-filters.php` hooks a function that merely adds
rewrites and a query var — it does **not** build the server. Nothing does
until a request needs it, which is always after `init`.

Confirmed empirically too: with a real `service` CPT declared in the child
theme's own `inc/content/post-types.php`, a real HTTP request to
`/wp/v2/service` returned **200**, and all four of its routes appeared in
the REST index. Removing it from the child made them disappear again.

The field tests also confirm the unknown-field guard still holds for a
child's own field group: SCF's REST `acf` field silently ignores keys it
doesn't recognise, which once meant create/update reported success while
doing nothing, so `inc/abilities/` validates names up front and returns
`devbase_unknown_field`.

## Never assert on an English literal

**The single most repeated mistake in this suite.** Made at least three
times, and twice mischaracterised as an unrelated pre-existing failure
rather than root-caused.

This dev site's real locale is `fr_CA` (confirmed with `get_locale()`, not
assumed) and this theme ships real `fr_FR`/`fr_CA`/`fr_BE` translations.
So any assertion against the English source of text that passes through
`__()`/`_e()`/`_n()`/`esc_html__()` **fails the moment a translation for
that exact string exists** — and passes until then, which is what makes it
such a good trap.

```php
// Wrong — passes until someone translates the string, then fails.
$this->assertStringContainsString( 'disabled', $message );

// Right — resolves to whatever the current locale renders.
$this->assertSame( __( 'Disabled.', 'devbase_parent_theme' ), $message );
```

Where a message is assembled with `sprintf()`, rebuild the *exact*
expected string with the same `__()` call and the same arguments, and use
`assertSame`. Where it isn't deterministic, assert only on raw
untranslated data — a slug, a version number, an HTTP status, a
destination label, a `⚠` marker — never on sentence text.

**The history, so it isn't repeated a fourth time.** The original instance
was in the cookie-consent tests (that feature was removed from this theme
on 2026-10-11 — see `inc/theme-settings/tracking/AGENT.md`), which
asserted `'We use cookies'` and `'Learn more'` against a function calling
`__()` on exactly those strings. Those two failures were reported as "2
pre-existing unrelated failures" across several commit messages before
anyone traced them. `Test_Retention_Policy.php` then hit the same thing
and was written to check the underlying option data plus a locale-safe
regex instead. `Test_System_Status.php` was written *after* both lessons
were documented and **still** shipped five English-literal assertions in
its first draft.

Plus `phpunit.xml.dist` at the theme root (PHPUnit's config file — kept
at the root because that's where `phpunit` expects to find it by
default, not inside `tests/`).

## Why this is dev-only, not part of the theme's runtime

`phpunit/phpunit`, `wp-phpunit/wp-phpunit`, and `yoast/phpunit-polyfills`
live entirely in `tests/composer.json` — not the theme root's
`composer.json` at all (see "Why `tests/composer.json` is separate,"
above). `tests/vendor/` is gitignored; nothing here is ever committed to
the theme root's git-tracked `vendor/`. WordPress never scans or loads
`tests/` on its own either way, so even if it did end up on a live site,
it would sit inert — but there's no reason to ship it, and `build-zip.sh`
excludes the whole folder regardless.

## Why a separate database — not the real dev site's `local` DB

The WP core test suite doesn't just connect to a database — on first run
it **drops and recreates every WordPress core table** in whatever
database it's pointed at, then wraps each individual test in a
transaction it rolls back afterward. It treats the database as fully
disposable. Pointing it at this site's real `local` database (the actual
`dev-blueprint.local` site, with real content) would wipe it on the
first run.

`tests/wp-tests-config.php` instead points at
**`devbase_parent_theme_tests`**, a separate database created once on the
same local MySQL server:

```
CREATE DATABASE IF NOT EXISTS devbase_parent_theme_tests;
```

`ABSPATH`, on the other hand, **does** point at this site's real
WordPress core files (`dirname( __DIR__, 4 )`, i.e. the actual
`app/public` install) — the test suite only needs an isolated database,
not an isolated copy of WordPress itself.

## Two real bugs hit setting this up (worth remembering)

**1. The socket path can't just be a `php.ini` setting.** wp-phpunit's
bootstrap spawns a *separate PHP subprocess* (via the `WP_PHP_BINARY`
constant) to run the initial database install. That subprocess doesn't
inherit `phpunit.xml`'s `<php><ini>` overrides — only the main PHPUnit
process gets those. The fix: embed the socket directly in `DB_HOST`
using `wpdb`'s own supported syntax, `"localhost:/path/to/socket"`
(`wpdb::parse_db_host()` peels off anything after the first `:/` as the
socket) — that value travels with `DB_HOST` into the subprocess correctly,
where an ini setting wouldn't.

**2. Test file names have to match their class name.** PHPUnit's legacy
`TestSuiteLoader` (still used here because wp-phpunit's base test case
predates PHPUnit's newer attribute-based discovery) requires the
filename (minus `.php`) to be a case-insensitive suffix match of the
declared class's short name. A file named `test-security-settings.php`
containing `class Test_Security_Settings` fails silently with "Class ...
cannot be found" — dashes in the filename never match the class's
underscores. Test files in this folder must be named to match their
class exactly, e.g. `Test_Security_Settings.php` for
`class Test_Security_Settings`.

**3. `phpunit.xml.dist`'s `<directory suffix=".php">tests</directory>`
means exactly what it says — every `.php` file under `tests/`, recursively.**
Harmless before `tests/vendor/` existed; once PHPUnit's own dependencies
moved here (see above), PHPUnit started trying to load files deep inside
`tests/vendor/nikic/php-parser/` as candidate test files, hit one that
legitimately declares the same class under two names (a deprecated-alias
compatibility shim, `require`-based, not `require_once`), and fatal-erred
with "Cannot redeclare class." Root-caused by reading PHPUnit's own
loading code and the actual stack trace — the initial, wrong assumption
was a broken upstream package version; the real cause was test-discovery
scope. Fixed with `<exclude>tests/vendor</exclude>` in `phpunit.xml.dist`.
Any future top-level folder added under `tests/` that isn't itself a
test file (another vendor dir, a fixtures folder, etc.) needs the same
explicit exclude, or it silently becomes part of the test-discovery scope.

## Why PHPUnit 9, not 10

`wp-phpunit`'s `abstract-testcase.php` calls
`PHPUnit\Util\Test::parseTestMethodAnnotations()` unconditionally in
`setUp()` (via `expectDeprecated()`) — a PHPUnit-internal method that
existed for annotation-based testing and was removed entirely in
PHPUnit 10 (which moved to attribute-based test metadata). That makes
every single test fatal immediately under PHPUnit 10, regardless of
what the test itself does. `yoast/phpunit-polyfills` bridges most
version differences but doesn't cover this specific internal method.
Fix: pin `phpunit/phpunit` to `^9.6` in `tests/composer.json` — confirmed
working end-to-end. If a future WordPress core test suite update drops
this call, PHPUnit 10+ can be revisited — don't upgrade PHPUnit without
re-testing against a real run first, this failure mode doesn't show up
as an error until you actually run the suite.

## Running the tests

One-time setup (only needed once per checkout, or after `tests/composer.json`
changes):

```
cd tests && composer install && cd ..
```

Then, from the theme root, every time:

```
php tests/vendor/bin/phpunit
```

If MySQL connection fails with "Error establishing a database
connection," the socket path in `tests/wp-tests-config.php` doesn't match
your machine — either export `WP_TESTS_DB_SOCKET` with the real path
(`find ~/Library/Application\ Support/Local/run -name mysqld.sock` for
Local by Flywheel) or edit the fallback in that file directly.

## Who sees a failure — there's no CI here

There's no CI wired up yet (GitHub Actions or similar) running these on
every push — nobody sees a failing test automatically just by someone
else editing code. The one place a failure is guaranteed to be seen:
**`bin/build-zip.sh` runs the full suite first and refuses to produce a
`.zip` if anything fails** (see the root `AGENT.md`, "Packaging a
distributable zip"). That makes "tests pass" an actual precondition for
shipping a client-ready zip, not optional due diligence — but it only
catches a regression at packaging time, not the moment it's introduced.
Run `php tests/vendor/bin/phpunit` yourself after any change to
`inc/theme-settings/security/` (or whatever a future test file covers)
if you want to know sooner than that.

Wiring up CI to run `php tests/vendor/bin/phpunit` on every push (against
this theme's own git repo, which now exists — see the root `AGENT.md`)
is the natural next step — that's what would actually catch a regression
at the moment it's introduced instead of at packaging time. It would need
its own `composer install` step run inside `tests/` first, same one-time
setup documented above.

## Adding more tests

Follow `Test_Security_Settings.php` as the template: extend
`WP_UnitTestCase`, use `update_field( ..., 'option' )` to set up state
(this theme's options-page fields, same as production code reads),
assert against the actual `devbase_*` functions — not against `get_option()`
internals — and reset any state changed in `tear_down()` so tests stay
independent of run order. When a new sub-page under `theme-settings/`
gets meaningful logic (not just field registration), add a test file
here in the same change, the same way every sub-page gets its `AGENT.md`
in the same change.
