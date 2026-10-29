# inc/dependencies/ase/ — Admin Site Enhancements

```
inc/dependencies/ase/
  ase.php                     — loads ASE from vendor/, defines its path/URL constants first
  ase-settings.php            — this theme's standard ASE module defaults
  ase-limit-login-table.php   — creates a DB table ASE needs, since its activation hook never fires
```

See `../AGENT.md` for why this (and every dependency here) is loaded from
`vendor/` via `require_once` rather than as a real WordPress plugin,
for `dependency-updater.php`'s auto-update mechanism, which manages this
one, and for why `vendor/admin-site-enhancements/` is committed directly
to this theme's own git repo rather than left to `composer install`
alone.

### `ase.php`
ASE has no filter-based path/URL override — it computes its URL/path into
two plain constants, `ASENHA_URL` and `ASENHA_PATH`, via unconditional
`define()` calls inside its own main file, which only resolve correctly
under `wp-content/plugins/`. Since PHP keeps the *first* definition of a
constant and only emits a harmless warning on a second attempt to define
it, pre-defining both constants with the correct `vendor/` path before
requiring the file makes ASE's own (now-losing) `define()` calls no-ops.
The expected warning is suppressed by narrowly toggling `E_WARNING` off
just around that one `require_once`, not globally.

### `ase-settings.php`
Defines `devbase_ase_default_settings()` — every ASE module/setting this
theme turns on by default, one at a time, each with a comment explaining
why. Read the function directly for the current list; highlights include
SVG upload for admins, missed-schedule auto-publish, a cleaned-up admin
bar, disabled comments, disabled author archives (username leak via
`?author=1`), obfuscated author slugs, Limit Login Attempts, and a
deliberately curated subset of ASE's "Disable Smaller Components" list —
several sub-options were evaluated and *rejected* (resource version
numbers, frontend Dashicons, jQuery Migrate, disabling lazy loading,
disabling Application Passwords, disabling the user's own password-change
notification) because each carries a real risk of breaking something or
is actually a security regression, not an improvement. The comment block
in the code explains each rejection.

### Two hooks a child theme overrides this with

`devbase_ase_resolved_defaults()` and `devbase_ase_enforced_settings()`
wrap the two filters a client child theme uses. Which one to pick is the
only real decision here, and the wrong choice fails silently:

- **`devbase/ase/defaults`** — adjusts the baseline, still subject to the
  fill-gaps merge below. So it sets the value on a fresh site and does
  **nothing** on a site where that ASE key already has a stored value.
- **`devbase/ase/enforced`** — applied *after* the merge and deliberately
  overwrites what's stored, reasserted on every run. Empty in this theme
  by design.

Why `enforced` exists here but is empty: the "never reassert" rule below
exists because THIS theme cannot tell a deliberate change apart from ASE's
own stale-form-save reset. A child theme naming one specific key removes
exactly that ambiguity — it's an explicit per-client statement, not an
ambient default — so the original reasoning stays intact rather than being
quietly overridden. Cost: an enforced key can no longer be changed from
ASE's own settings page (it reverts on the next admin page load). See the
child starter's `inc/overrides/AGENT.md`.

**Important behavior:** the merge that applies these defaults
(`array_merge(devbase_ase_resolved_defaults(), $current)`) only ever fills
in keys that don't already exist. It never overwrites a value that's
already set — including a value ASE's own giant settings-page form reset
to `false` on a stale save (a real, repeatedly-hit issue: ASE registers
every module as a field in one form, so saving that form with a stale
page open resets every field not currently checked). This is intentional:
there's no way to distinguish "a client deliberately turned this off"
from "an unrelated ASE form save reset it," and silently re-enabling
something a client disabled is worse than leaving an accidental reset in
place. If a default you added doesn't seem to be applying, check whether
the key already exists in the `admin_site_enhancements` option — if so,
the fix has to be applied once, deliberately (e.g. via `update_option()`),
not by adding to this function.

Runs on both `admin_init` (every admin page load) and `after_switch_theme`
(theme reactivation) — both call the same shared closure.

### Verified against the real option, not just in tests

`tests/Test_ASE_Overrides.php` covers all nine behaviours. It was also
confirmed end to end against this dev site's actual
`admin_site_enhancements` option (178 stored keys) with the child theme
really active, by temporarily putting real overrides in the child's
`inc/overrides/ase.php` and firing a genuine `admin_init`:

- `defaults` filled `login_attempts_limit` (absent from the DB) with `3`. ✓
- `defaults` asking for `disable_plugin_theme_editor => false` on a key
  already stored as `true` correctly **did nothing**. ✓
- `enforced` flipped `enable_svg_upload` from `true` to `false`. ✓
- An admin setting `enable_svg_upload` back to `true` was **reverted on the
  next admin page load**. ✓
- Unrelated keys untouched. ✓

Both the child stub and the option were restored afterwards, verified byte-
identical and back to 178 keys.

One harness note from that pass: `do_action( 'admin_init' )` from a bare
CLI script fatals inside ASE on `add_settings_section()`, a wp-admin-only
function. Load `ABSPATH . 'wp-admin/includes/admin.php'` first — a real
admin request already has. Not a bug in this theme.

### `ase-limit-login-table.php`
ASE's Limit Login Attempts module reads/writes a custom table,
`wp_asenha_failed_logins`, normally created via `register_activation_hook()`.
Since that hook never fires under this theme's loading model, the table
never gets created and the module's blocking logic silently does nothing.

This creates it directly, using ASE's own
`Activation::create_failed_logins_log_table()` method — but guarded
carefully: that method does `DROP TABLE IF EXISTS` before recreating it,
so calling it more than once would destroy accumulated log data. Only
runs if the table doesn't already exist, and remembers via an option
(`devbase_asenha_failed_logins_table_ready`) so it doesn't re-query on
every page load once confirmed present.

## Where to go from here

- **Shared dependency-loading rationale + the auto-updater:** `../AGENT.md`
- **Secure Custom Fields:** `../scf/AGENT.md`
- **WordPress MCP Adapter:** `../mcp-adapter/AGENT.md`
