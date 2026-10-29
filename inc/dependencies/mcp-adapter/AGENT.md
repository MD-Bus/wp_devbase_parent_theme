# inc/dependencies/mcp-adapter/ — WordPress MCP Adapter

```
inc/dependencies/mcp-adapter/
  mcp-adapter.php           — loads MCP Adapter from vendor/
  mcp-adapter-updater.php   — its own, separate GitHub-Releases updater
                               (NOT dependency-updater.php — see below)
```

See `../AGENT.md` for why this (and every dependency here) is loaded from
`vendor/` via `require_once` rather than as a real WordPress plugin.

### What it actually is

The official WordPress core team's MCP Adapter
(github.com/WordPress/mcp-adapter, Packagist `wordpress/mcp-adapter`) — the
bridge between WordPress core's Abilities API (WP 7.1+, `wp_register_ability()`)
and the Model Context Protocol. It takes whatever abilities are registered
anywhere on the site and exposes them as MCP tools over a REST endpoint,
`/wp-json/mcp/mcp-adapter-default-server`, reachable via Application
Password auth.

It registers **zero abilities of its own** — it's purely transport. The
actual content exposed through it comes from elsewhere: SCF's ~48
abilities (`../scf/AGENT.md`) and this theme's own abilities in
`inc/abilities/` (`../../abilities/AGENT.md`). MCP Adapter is the pipe;
`inc/abilities/` is most of what's flowing through it.

### `mcp-adapter.php` — why this one needed zero compatibility shim

Unlike ASE (`define()`d path constants) or SCF (`plugin_dir_url()` asset
URLs — see `../ase/AGENT.md` and `../scf/AGENT.md`), MCP Adapter needed no
path/URL override at all. Before vendoring it, its source was inspected
specifically for the two bug classes that hit the other two dependencies
under this theme's `require_once`-from-`vendor/` loading model:

- No `register_activation_hook()` — nothing to skip.
- No `plugins_loaded`-dependent init (the bug that silently killed SCF's
  Abilities API integration, see `../scf/AGENT.md`) and no
  `plugin_dir_url()` asset calls — it's a headless API bridge with no
  admin UI assets to break in the first place.

It was originally installed the normal way — downloaded from
`https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip`,
activated via `activate_plugin()`, and tested end-to-end over a real MCP
protocol connection (a real Claude Code MCP client doing
`initialize` → `tools/list` → `tools/call`) — before being moved into
`vendor/mcp-adapter/` and switched to `require_once`, once the source
inspection above confirmed the move was safe. Verified again after the
move: the same session-handshake test and the same `tools/list` response
came back identical, confirming the vendoring changed nothing about how it
boots or behaves.

Guarded with `file_exists()` before the `require_once` regardless — cheap
insurance against a partial checkout or manual deletion, same reasoning
as the `file_exists()` guards throughout `../ase/` for ASE's undocumented
internals.

### How `vendor/mcp-adapter/` actually gets there in the first place — committed directly to git

`vendor/mcp-adapter/` is tracked in this theme's own git repo — see
`../AGENT.md`'s "committed to git" section for the general policy (now
shared by SCF and ASE too: a fresh clone/install must never fail just
because a WordPress.org listing or GitHub release vanishes someday).
Worth recording specifically here since MCP Adapter is what surfaced the
whole question: two approaches were tried, in order.

1. **First**: a `bin/install-mcp-adapter.php` script wired into
   `composer.json`'s `post-install-cmd`/`post-update-cmd`, downloading and
   extracting the latest GitHub release automatically after every
   `composer install`. Worked, tested end-to-end (fresh install, already-
   installed skip, and a simulated download failure, all in an isolated
   sandbox) — but added real complexity (a whole bootstrap script, a
   network dependency during setup, failure modes to handle) for a
   problem with a much simpler fix, and only solved it for this one
   dependency.
2. **Then, the actual fix**: just commit `vendor/mcp-adapter/` directly to
   git instead — no install step, no network dependency, no failure mode
   to degrade from. This is what prompted extending the same reasoning to
   SCF/ASE right afterward: `.gitignore`'s blanket `vendor/*` exclusion
   only ever protected against *duplicating* what Composer regenerates,
   and that protection is worthless if the thing being regenerated from
   (a WordPress.org listing, in SCF/ASE's case) can itself disappear.

`mcp-adapter-updater.php` (below) is unaffected by this either way — it
was always designed to *update* an existing install, never to create one
from nothing (confirmed by reading its `devbase_apply_dependency_update()`
call: `rename( $live_path, $backup_path )` is its very first step, which
fails immediately if `$live_path` doesn't exist), so committing the
initial state to git and leaving the daily update check as the ongoing
mechanism is a clean split: git seeds it, GitHub Releases keeps it
current.

This whole investigation also closes a real bug caught by actually
building a distributable zip and unzipping it (not just reading the
packaging script): `build-zip.sh`'s `composer install --no-dev` step
never touched `vendor/mcp-adapter/` either, for the same underlying
reason — it now copies that folder in explicitly as a separate step,
regardless of git-tracking status, since that script's rsync excludes
all of `vendor/` before reinstalling via Composer. See the root
`AGENT.md`, "Packaging a distributable zip," for that half of the story.

### `mcp-adapter-updater.php` — why it's a separate, simpler updater

`dependency-updater.php` (see `../AGENT.md`) is hardcoded to
WordPress.org's versioned download URL pattern,
`https://downloads.wordpress.org/plugin/<slug>.<version>.zip`, gated behind
`devbase-dependency-worker`'s per-version approval list. MCP Adapter can't
use it: it isn't on the WordPress.org plugin directory at all — confirmed
directly, `curl` to `downloads.wordpress.org/plugin/mcp-adapter.zip`
returns a 404 and the plugins info API reports "Plugin not found." It only
ships via GitHub Releases, a structurally different source.

Rather than extending `dependency-updater.php` to understand a second
download-URL pattern, this got its own small file with a deliberately
simpler design — no approval gate at all:

- **Same scheduling as `dependency-updater.php`** — a recurring daily
  WP-Cron event, registered from `init` and unscheduled on `switch_theme`.
  This replaced an `admin_init` + stored-timestamp gate, for the reason
  documented at length in `../AGENT.md`'s "The check cadence": `admin_init`
  only fires in wp-admin, so on a client site nobody logs into this check
  simply never ran. A recurring event fires from any request, front-end
  included, because core hooks `wp_cron()` to `init`.
- **Records `devbase_mcp_last_check`** (`{time, updated, error}`) on every
  run, surfaced by `devbase_mcp_last_check_message()` on the System Status
  tab. Without it there is no way to tell "already current" from "stopped
  running weeks ago".
- Hits `https://api.github.com/repos/WordPress/mcp-adapter/releases/latest`
  with an explicit `User-Agent` header — GitHub's API requires one, and
  `wp_remote_get()` doesn't send one by default.
- Reads the release's `tag_name` and compares it against
  `devbase_get_installed_version('mcp-adapter', 'mcp-adapter.php')`
  **with `version_compare()`, forward-only**. This was a plain string
  equality check (`$latest === $installed`) until the scheduling rework,
  which meant that if GitHub's "latest" ever pointed at an *older* release
  — a yanked release, or a retagged one — this would dutifully downgrade
  the site. SCF/ASE already refused downgrades; this is now the same rule
  applied consistently. "No approval gate" was always meant as "take new
  releases without review", never "follow the tag backwards". Covered by
  `tests/Test_Dependency_Updater.php::test_mcp_adapter_is_not_downgraded_when_github_reports_an_older_release`.
  The function it reuses from `dependency-updater.php` — that function just reads
  the plugin's own file header via `get_file_data()` and genuinely doesn't
  care whether Composer, this updater, or the original manual install put
  the files there.
- If different, downloads from the *stable* `.../releases/latest/download/mcp-adapter.zip`
  URL — always resolves to whatever release is currently latest, so there's
  no need to construct a version-specific URL from `$release['assets']` —
  and calls `devbase_apply_dependency_update('mcp-adapter', 'mcp-adapter.php', $latest_version, $download_url)`,
  also reused directly from `dependency-updater.php`. That function is
  generic (slug, main file, version, download URL, staged rename-based
  swap) and doesn't care about the source either.

**No approval gate, on purpose — a deliberate user decision:** "vendor it
and since MCP is not likely to break, leave it as auto update. even if it
breaks, it does not really break the site." Always-latest, no manual
review step, accepted as a reasonable risk specifically because this is a
headless API bridge nothing else on the site depends on functioning —
unlike SCF/ASE, where a bad update can break admin UI or content
management site-wide.

### One thing worth re-checking if this ever looks broken

`devbase_apply_dependency_update()`'s swap logic assumes the downloaded
zip's top-level folder exactly matches the `slug` param — i.e. the zip
must extract to `mcp-adapter/...`. Before trusting this, the real GitHub
release zip was downloaded and inspected with `unzip -l` to confirm its
top-level folder really is `mcp-adapter/`, not something like
`WordPress-mcp-adapter-<hash>/` — which is how GitHub's *auto-generated
source archive* zips are named. The stable `/releases/latest/download/`
URL points at the project's own hand-built release *asset* zip, not that
auto-generated source archive, and it currently uses the right folder
name. That's a property of the project's release process, not a promise —
worth re-verifying with `unzip -l` if an update ever fails at the "main
plugin file must exist" sanity check in `devbase_apply_dependency_update()`.

## Where to go from here

- **Shared dependency-loading rationale + `dependency-updater.php` (the
  SCF/ASE approval-gate system this one deliberately isn't part of):**
  `../AGENT.md`
- **Admin Site Enhancements:** `../ase/AGENT.md`
- **Secure Custom Fields (including the `plugins_loaded` bug that this
  dependency was specifically checked against):** `../scf/AGENT.md`
- **The theme's own abilities — most of what MCP Adapter actually exposes
  through the MCP endpoint:** `../../abilities/AGENT.md`
