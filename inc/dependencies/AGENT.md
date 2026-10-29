# inc/dependencies/ — vendored third-party code, and keeping it updated

```
inc/dependencies/
  dependency-updater.php   — keeps SCF/ASE on an approved WordPress.org
                              version, see below (cross-cutting, not owned
                              by any one dependency — stays at this level)
  ase/                      — Admin Site Enhancements, see ase/AGENT.md
  scf/                      — Secure Custom Fields, see scf/AGENT.md
  mcp-adapter/              — WordPress MCP Adapter, see mcp-adapter/AGENT.md
```

Originally a flat folder mixing all three dependencies' files together.
Reorganized into one subfolder per dependency, matching the pattern every
other multi-file feature in this theme already uses (`inc/forms/`,
`inc/theme-settings/`) — each subfolder's own AGENT.md covers that
dependency specifically; this file covers what's genuinely shared across
all of them.

## Three different update mechanisms — know which applies to what

Worth having straight before touching any of this, because "how does X get
updated" has four different answers in this theme:

| Dependency | Updated by | Reaches a live site |
| --- | --- | --- |
| SCF | `dependency-updater.php` → WordPress.org | daily WP-Cron, any request |
| ASE | `dependency-updater.php` → WordPress.org | daily WP-Cron, any request |
| MCP Adapter | `mcp-adapter/mcp-adapter-updater.php` → GitHub Releases | daily WP-Cron, any request |
| Timber (+ Twig, Symfony polyfills) | `composer update` on a dev machine | **only in a theme release** |

"Any request" matters: these run on a recurring WP-Cron event, not on
`admin_init`, specifically so a client site nobody logs into still keeps
itself current. See "The check cadence" below for the full story.

**Timber is the blind spot.** The first three keep themselves current with
no human involved. Timber, Twig and the Symfony polyfills have no
auto-update path at all — they move only when someone runs `composer
update` and cuts a release, and nothing on a live site or in this repo will
tell you a new version exists. If a Twig security advisory lands, the only
thing that ships the fix is a parent theme release.

### Composer constraints are not auto-updates

Worth stating plainly, because it's an easy and costly assumption:
**a caret constraint does not make anything update itself.** Composer is
never installed on a client site and never runs there. `"timber/timber":
"^2.5"` only decides what `composer update` resolves to *on a dev
machine* — it has no effect at runtime, on any site, ever.

Loosening the constraint to `^2.0` was considered specifically to get
"auto-update within the same major version", and rejected: both `^2.5` and
`^2.0` allow every 2.x release, and `composer update` picks the highest
allowed either way, so the two resolve **identically** (verified with
`composer update --dry-run`: with `^2.5` in place, Timber already resolved
to the latest 2.x with no update pending). The only practical difference
is that `^2.0` would additionally permit a *downgrade* to 2.0.x if some
other constraint ever forced one. So `^2.5` is the same behaviour plus a
floor stating which version's features this theme actually relies on.
Keep it.

What `^2.5` genuinely gives you is that **one `composer update` command
takes every compatible release across the whole tree** — which is how
Twig went 3.28.0 → 3.30.0 and `symfony/polyfill-mbstring` 1.38.2 → 1.43.0
on 2026-10-11, verified afterwards with a real front-end request through
Timber (200, child template rendering, no Twig exceptions), the full test
suite, and `composer audit`. Timber's own `twig/twig: ^3.27` requirement is
what made that safe, and it's enforced by Composer — which is precisely
the guarantee that would be lost by splitting these into independently
updated pieces (see `../updates/AGENT.md`).

What's still missing is **notification**: nothing tells you a new release
exists. `composer outdated` and `composer audit` are in the release
runbook (`../../bin/AGENT.md`) for that reason, but they're manual. GitHub
Dependabot (`package-ecosystem: "composer"`) would turn this into a PR
when Timber or Twig move; not set up yet — there is no `.github/` in this
repo at all, and no CI either (see `tests/AGENT.md`).

`composer audit` run during development reported no known advisories for
the current lock, but that's a point-in-time check, not a monitor. Worth
running before each release.

## The core idea: none of these are installed as WordPress plugins

SCF, ASE, and MCP Adapter all live under `vendor/` — SCF and ASE placed
there by Composer (see `composer.json`, which pulls them from
WordPress.org via the WPackagist mirror), MCP Adapter placed there
manually (it isn't on WordPress.org at all, see `mcp-adapter/AGENT.md`) —
and are loaded with a plain `require_once` from theme code. They never
appear on the Plugins screen, never get activated/deactivated, have no
`register_activation_hook()` callback fired.

This was a deliberate, hard-won decision, not the default. Loading them as
real files in `wp-content/plugins/` (which was tried first, with a plugin
called FileBird) ran into repeated, genuine bugs:

- `plugins_loaded` fires **before** the active theme's `functions.php` is
  even loaded — a plugin that defers its real init to
  `add_action('plugins_loaded', ...)` never actually initializes if loaded
  from theme code. FileBird did this, and was dropped entirely rather than
  fixed. **This exact bug resurfaced with SCF itself** — see
  `scf/AGENT.md` for how it was caught and fixed without patching
  vendor/ code directly.
- WordPress's plugin-folder lifecycle (activation, deactivation on theme
  switch, "reactivating an already-active theme has no Activate link so
  `after_switch_theme` never fires") turned out to be much more fragile
  than expected — the short version is `wp-content/plugins/` loading
  caused more bugs than it solved.

Loading directly from `vendor/` with `require_once` sidesteps all of that
— the code just runs whenever the theme's `functions.php` runs, full stop,
no lifecycle to get wrong. The tradeoff is that a dependency computing its
own asset URLs (assuming it lives under `wp-content/plugins/`) needs a
small compatibility shim — documented per-dependency in each subfolder's
own AGENT.md.

## `vendor/{ase,secure-custom-fields,mcp-adapter}/` are committed to git — a cold-start baseline, not a live mirror

All three are tracked in this theme's own git repo (`.gitignore` excludes
`vendor/*` broadly, then explicitly un-ignores each of these three) —
deliberately, not an oversight. The reasoning: a fresh clone/install must
never fail just because a WordPress.org listing or a GitHub release
vanishes someday. SCF and ASE are Composer-manageable (pulled from
WordPress.org via the WPackagist mirror, pinned in `composer.json`) and
MCP Adapter isn't (see `mcp-adapter/AGENT.md`), but that distinction
turned out not to matter for this question — Composer regenerating a
package from a manifest is only as reliable as the thing it's pulling
from staying available, and a single WordPress.org listing disappearing
is exactly the failure mode being protected against. Timber stays
Composer-only/gitignored — it's on Packagist proper, a meaningfully more
durable registry than a single plugin listing, so this concern doesn't
apply to it the same way.

**Every path here uses `get_template_directory()`, not
`get_stylesheet_directory()`** — this theme is now a PARENT theme, and
`get_stylesheet_directory()` resolves to the active CHILD. `scf.php`'s
unconditional `require_once` through that path would have been a fatal
error on every page load the moment a child was activated, and ASE and MCP
Adapter (both `file_exists()`-guarded) would have silently stopped loading
entirely. Changed throughout when the parent/child split was made; if you
add code here, use `get_template_*`.

**⚠ A parent theme update overwrites this folder.** The theme's zip
carries `vendor/`, and a theme update replaces the whole theme directory —
so every Warden release reverts SCF/ASE/MCP Adapter to whatever versions
that release was built from, discarding newer copies this updater had
already installed. It self-heals on the next daily check, but for up to a
day the site runs what the zip shipped. Concretely observed: the committed
baseline was MCP Adapter 0.6.1 while this dev site had auto-updated itself
to 0.7.0. The mitigation is procedural and written into
`bin/build-zip.sh` as a release-time warning — refresh the committed
baseline before cutting a release. See `../updates/AGENT.md`.

**This is a baseline, not something that has to stay in sync with a live
site.** `dependency-updater.php`/`mcp-adapter-updater.php` (below) update
a live site's `vendor/` purely at the filesystem level — they never touch
git, never run Composer, never update `composer.lock`. A site that's been
running for a while will have a `vendor/` that's genuinely diverged from
what's committed here, and that's expected, not a bug to fix — git's job
is only "a fresh checkout always has a *working* starting point," not
"always mirror whatever's currently live everywhere." Refreshing the
git-committed baseline to a newer approved version is a deliberate,
occasional maintenance action (bump it, verify, commit), not something
that needs to happen after every single live auto-update.

## `dependency-updater.php`, in detail

**What it does:** keeps `vendor/secure-custom-fields/` and
`vendor/admin-site-enhancements/` on WordPress.org's current stable
release. It never runs Composer; Composer is a dev-machine-only tool in
this whole system. **MCP Adapter is deliberately NOT managed by this
file** — it isn't on WordPress.org, so there's nothing here to target it
at; see `mcp-adapter/AGENT.md` for its own updater.

### There is no approval gate any more

This used to be the most elaborate machinery in the theme. A separate
Cloudflare Worker (`devbase-dependency-worker`) served a curated
allow-list of versions that had actually been tested, and this file would
only ever move to a version on that list — with a shared access key, a
stored list of the last three known-good Worker URLs with automatic
promotion of whichever responded, a `next_check_url` self-migration
mechanism, and rollback-by-disapproval (removing a version from the list
moved sites off it).

**All of it was removed** when the theme moved to a parent/child model
with Warden-delivered theme updates. The reason is simply that version
control now happens in one place instead of two: what ships inside a
parent theme release. A second, independent opinion about SCF/ASE versions
earned its complexity when there was no other gate; with Warden deciding
what reaches a site, it was duplicating a decision made elsewhere.

What replaced it is three functions and no external service:
`devbase_managed_dependencies()` (slug → main file),
`devbase_get_latest_wporg_version()` (WordPress.org's own
plugin-information API), and a `version_compare()` forward-only check.

**Know the tradeoff this accepts.** A bad SCF or ASE release now reaches
sites on the next daily check with nothing standing in its way. That's the
same bet `mcp-adapter-updater.php` has always made, now extended to two
dependencies where a bad update is genuinely more disruptive (SCF/ASE can
break admin UI or content management site-wide, where MCP Adapter is a
headless bridge nothing else depends on). The mitigation is the committed
`vendor/` baseline plus a Warden release — see below.

**It only ever updates, never installs.** `devbase_run_dependency_check()`
skips any dependency with no installed version at all, because
`devbase_apply_dependency_update()`'s first step renames the live folder
aside and fails immediately if there isn't one. A fresh install gets its
starting copy from the committed `vendor/` baseline.

### The check cadence — and a reversed decision worth understanding

**A recurring daily WP-Cron event**, scheduled from `init` with the
standard `wp_next_scheduled()` idiom and unscheduled on `switch_theme` —
the same shape `inc/forms/retention.php` already uses, and the same
mechanism WordPress core itself uses for `wp_update_plugins` /
`wp_update_themes`.

**This reverses an earlier decision, which was wrong.** It used to hook
`admin_init` with a stored-timestamp gate, and the comment here argued
explicitly against a recurring event: *"a low-traffic site might go days
without a visit, and a recurring cron event just silently never fires
until something triggers WP-Cron anyway, so there's no reliability
advantage."*

The flaw: **both** approaches need a page load, but they count *different*
page loads.

- `admin_init` fires only in wp-admin — plus `admin-ajax.php`, which does
  its own `do_action( 'admin_init' )` (verified in
  `wp-admin/admin-ajax.php`). It does **not** fire from `wp-cron.php`
  (zero references there).
- A recurring event fires from **any** request, because core hooks
  `wp_cron()` to `init` in `wp-includes/default-filters.php`. Front-end
  traffic spawns the cron runner.

So the old version only ever checked when somebody logged into the back
end. A typical client site gets front-end traffic daily and an admin visit
maybe once a quarter — meaning dependency updates effectively stopped
happening on exactly the sites that most needed them. A recurring event is
strictly better here, and it also keeps working on hosts that set
`DISABLE_WP_CRON` and point a real system cron at `wp-cron.php`, which the
`admin_init` version got no benefit from at all.

`wp_schedule_event( time(), 'daily', ... )` uses `time()` as the first
occurrence, so a fresh install checks on its very next request rather than
waiting a day.

**Verified, not assumed.** Both hooks were cleared and both last-run
records deleted to simulate a site that has never checked. A single
anonymous front-end `GET /` — no login, no admin — then (a) scheduled both
events and (b) actually *executed* both, recorded in their last-run
options with a matching timestamp. That's the whole claim demonstrated end
to end.

**Still not a guarantee.** WP-Cron is pseudo-cron: a site with literally
zero traffic and no system cron will not check. That is exactly why the
last-run record below exists.

### The last-run record

`devbase_dependency_last_check` stores `{time, updated, errors}` on
**every** run, including one that changed nothing — "it ran and found
nothing to do" is what proves the schedule is alive, and is
indistinguishable from "it never ran" without it.
`devbase_dependency_last_check_message()` renders that for the **System
Status** tab (`../theme-settings/miscellaneous/AGENT.md`), flagging
anything older than 48 hours with a pointer at WP-Cron. Same reasoning,
and same shape, as the retention policy's staleness indicator.

`devbase_apply_dependency_update()` returns `bool` so the caller can tell
a genuine failure from "nothing needed doing" — previously it returned
nothing and only `error_log()`'d, which meant a site could fail to update
for weeks with no visible signal anywhere.

**The swap itself, in `devbase_apply_dependency_update()`:**
1. `download_url()` (WP core) fetches the zip from
   `https://downloads.wordpress.org/plugin/<slug>.<version>.zip` — WordPress.org
   supports versioned download URLs for any historical release, so no
   separate "get me the latest" lookup is needed once we have a target
   version string. (`mcp-adapter/AGENT.md`'s updater reuses this same
   function with a GitHub Releases URL instead — the function itself
   doesn't care where a URL/version came from.)
2. Extracted to `vendor/.tmp-<slug>/`, not the live path.
3. Sanity-checked: the extracted package's main plugin file must exist.
   If not, abort and clean up — never touch the live folder based on an
   incomplete or wrong download.
4. **Staged swap, not delete-then-copy:** the live folder is `rename()`'d
   to `vendor/.backup-<slug>/` first, then the new folder is `rename()`'d
   into the live path. If that second rename fails (disk full, permissions),
   the backup is renamed straight back — the site is never left with
   neither an old nor a new working copy. `rename()` is atomic on the same
   filesystem, which is why this pattern (not a wholesale delete-then-write)
   was chosen.
5. Both the temp and backup folders are deleted once the swap succeeds.

**This function is NOT used for theme updates.** It's built for
`vendor/<slug>/` subfolders; using it on a theme would mean renaming the
directory of the theme currently executing, and reimplementing maintenance
mode, filesystem credentials and rollback that core already has. Theme
updates go through WordPress's own `Theme_Upgrader` — see
`../updates/AGENT.md`.

**Reading "what's currently installed":** `devbase_get_installed_version()`
reads the plugin's own file header directly (`get_file_data()`) — **not**
Composer's `vendor/composer/installed.json`. This was a real bug caught
during testing: this updater's own swap logic never touches Composer's
bookkeeping (it just renames folders), so `installed.json` goes stale the
moment this updater does its job, and would make every subsequent check
think the old version was still installed. The file header is always
accurate regardless of whether Composer or this updater put the files
there. All paths here use `get_template_directory()`, not
`get_stylesheet_directory()` — see the "parent theme" note below.

### Verified

`tests/Test_Dependency_Updater.php` — 10 tests covering the slug→main-file
map, the WordPress.org version lookup (and that an outage, a malformed
body or a non-200 all resolve to `null` rather than a guess that would
trigger a bogus swap), reading the installed version from a real file
header, and the forward-only rule: no download when already current, no
download when WordPress.org somehow reports an older version, and a
correctly-constructed versioned download URL when there genuinely is a
newer release. Network calls are faked with `pre_http_request`, and the
one test that lets a download start deliberately fails it so the real
`vendor/` folder is never touched.

### Operational notes / lessons learned this session (worth not re-learning)

- **`composer update --lock` does not do what it sounds like.** It means
  "refresh the lock file hash without updating any package version" — the
  opposite of "update normally and save the lock file." Use plain
  `composer update <package>` to actually change a version.
- **After this updater swaps a version, `composer.lock` and the real files
  are out of sync on purpose** (the updater bypasses Composer entirely).
  If you need to bring a dev/staging environment back in line with a
  specific `composer.json`, a plain `composer install`/`update` won't
  necessarily notice drift caused by this updater — you may need to
  `rm -rf vendor/<slug>` and reinstall to force a clean re-extraction.
- **Cloudflare Worker deploys are not instantaneous at the edge.** Kept
  because it still applies to Warden, which is also a Worker (see
  `../updates/AGENT.md`): a `wrangler deploy` that reports success can
  still serve the previous version for several (sometimes 10-15+) seconds
  afterward from some edge nodes. Don't trust an immediate curl after
  deploying — wait, or use a cache-busting query param and be patient.
- Every non-trivial change to this file was verified against real
  WordPress.org downloads at least once during development — not just PHP
  syntax checks. If you're extending this system, do the same: mock only
  what you must (e.g. a `pre_http_request` filter to simulate a specific
  failure), and prefer a real end-to-end round-trip wherever possible.

## Where to go from here

- **Admin Site Enhancements specifically:** `ase/AGENT.md`
- **Secure Custom Fields specifically (including the `plugins_loaded`
  timing bug and its fix):** `scf/AGENT.md`
- **WordPress MCP Adapter specifically (why it's ungated, its own
  updater):** `mcp-adapter/AGENT.md`
- **Back up to how the theme boots overall:** `inc/AGENT.md`
- **The content abilities MCP Adapter exposes:** `inc/abilities/AGENT.md`
- **Theme updates (Warden), a separate system that overwrites this
  folder:** `../updates/AGENT.md`
