# bin/ — dev-only scripts

Executable helper scripts for working on this theme. Nothing here runs
on a live site — WordPress never loads `bin/`, and `build-zip.sh`
excludes this folder from the zip it produces (see below), so it's
strictly a local/dev-machine convenience.

```
bin/
  AGENT.md          — this file
  build-zip.sh         — packages this theme into a distributable .zip
```

## `build-zip.sh`

The only supported way to turn this theme into an installable `.zip`, and
the artifact uploaded to Warden as a release on the base theme's track —
see the root `AGENT.md`, "Packaging a distributable zip," for the full
writeup of what it does and why (runs the test suite as a release gate,
strips `tests/`/`phpunit.xml.dist`/dev-only Composer packages, installs
production-only `vendor/` via `composer install --no-dev`).

## Two release gates, not one

**1. The version-drift check** (runs first, before the tests). For each
Composer-managed dependency it compares the version on disk against
`composer.lock`, and refuses to build if they differ.

This exists because of an asymmetry that is genuinely easy to ship
wrong. `rsync` excludes `vendor/` entirely, then:

- **SCF, ASE, Timber, Twig** are rebuilt by `composer install` — i.e. from
  `composer.lock`. **The working copy is irrelevant.**
- **MCP Adapter** is copied straight from the working copy, since it isn't
  in `composer.json` at all.

So for SCF/ASE, committing the newer copy that the live auto-updater put
on disk accomplishes nothing — `composer install` quietly rebuilds the
older locked version into the zip, and every site that updates gets pushed
backwards. The fix is to bump the pin (`composer require
wpackagist-plugin/<slug>:<version>`), which the check prints for you. For
MCP Adapter, committing the working copy IS the correct action.

Caught for real while documenting this, not hypothetically: ASE had
auto-updated itself to 9.2.0 on this dev site while `composer.lock` still
pinned 9.0.0, so a release built at that moment would have shipped 9.0.0
to every site. Verified the guard fires on that exact drift, then verified
that after `composer require wpackagist-plugin/admin-site-enhancements:9.2.0`
the build succeeds and the resulting zip really does contain 9.2.0
(checked by extracting the plugin header out of the built zip, not by
trusting the script).

**2. The test suite.** `php tests/vendor/bin/phpunit` must pass or no zip
is produced. See `tests/AGENT.md`.

## The release runbook

```
# 1. Let the dependency check run (or call devbase_run_dependency_check()),
#    then bring composer.lock up to whatever it installed:
composer require wpackagist-plugin/secure-custom-fields:<version>
composer require wpackagist-plugin/admin-site-enhancements:<version>

# 2. Timber/Twig have NO auto-update path — they only ever move here:
composer update timber/timber

# 3. Bump Version: in style.css. Warden compares versions; without a bump,
#    no site is ever offered the release.

# 4. Build (both gates run), commit, upload the zip to Warden.
./bin/build-zip.sh
```

Step 3 is not automated and is the easiest to forget — the script reads
the version from `style.css` but never changes it, so forgetting it
produces a zip that overwrites the previous release at the same version
number and reaches nobody.

Run it from the theme root, not from inside `bin/`:

```
cd wp-content/themes/devbase_parent_theme
./bin/build-zip.sh
```

Running `./build-zip.sh` from inside `bin/` itself won't work — the
script's relative paths assume the working directory is wherever you
invoke it from being irrelevant to `THEME_DIR` (it resolves that from
its own file location), but the command still needs the correct relative
path to the script from wherever your shell actually is.

If a new dev-only script gets added here later, it gets documented in
this file in the same change — same rule as everywhere else in this
theme.
