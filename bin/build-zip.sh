#!/usr/bin/env bash
# Packages this theme into a clean, installable .zip — no tests/, no
# phpunit.xml.dist, no AGENT.md. See AGENT.md "Packaging a distributable
# zip" and tests/AGENT.md for the full story. The theme root's vendor/ is
# committed to git as pure runtime deps already (no dev-only packages
# live there at all — PHPUnit/wp-phpunit/yoast are in tests/vendor/, its
# own tests/composer.json, specifically so this is true), so there's
# nothing dev-only left to strip out of it.
#
# The two --exclude='/languages/*.po[t]' lines are anchored with a leading
# slash on purpose: they strip THIS theme's own .po/.pot translation
# SOURCES (WordPress only ever reads the compiled .mo) while leaving SCF's
# and ASE's bundled translations under vendor/ untouched. An unanchored
# '*.po' would delete those too — harmless functionally, but it would mean
# shipping a quietly-modified copy of someone else's plugin.
#
# Refuses to build if the test suite fails — this is the theme's actual
# release gate: a zip only gets produced from code that currently passes
# every test. See tests/AGENT.md for who's expected to run this and when.
#
# The zip this produces is the artifact uploaded to Warden as a release on
# the BASE THEME's track. The client child theme is a separate repo with
# its own build-zip.sh and its own Warden track — this script knows nothing
# about it.
#
# The zip carries vendor/ (SCF, ASE, MCP Adapter, Timber), and since 1.7.0
# that is the ONLY way those reach a site: sites no longer update them
# themselves. Dependabot / the MCP Adapter release workflow say when one is
# due (see inc/dependencies/AGENT.md).
#
# HOW the zip gets its vendor/ differs per dependency:
#
#   SCF / ASE / Timber / Twig  — come from `composer install` below, i.e.
#       from composer.lock, not from the working copy. The drift check
#       (bin/check-vendor-drift.sh) refuses to build while the two differ.
#
#   MCP Adapter — copied straight from the working copy (it isn't in
#       composer.json at all), so committing the new release in
#       vendor/mcp-adapter/ is the correct action.
#
# (Warden publishes GitHub's archive of a tag, not this zip, so the tag
# ships the committed vendor/ as is; see bin/release.sh.)

# Repository-only files are stripped: README, CHANGELOG, ROADMAP, SECURITY
# and docs/ (which carries screenshots — ~1.9MB that has no business on a
# client's server). The excludes are anchored with a leading slash so they
# only match this theme's own copies, never third-party ones inside vendor/.
#
# LICENSE is deliberately KEPT. This theme is GPLv2+ and bundles GPLv2+ code
# (Secure Custom Fields, Admin Site Enhancements); the GPL requires the
# licence text to travel with the distributed work. The licence files inside
# vendor/ stay for the same reason — they are other projects' licences and
# removing them would be a compliance problem, not a tidy-up.
#
set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
THEME_SLUG="wp_devbase_parent_theme"
VERSION="$(grep -m1 '^Version:' "$THEME_DIR/style.css" | sed 's/Version: *//' | tr -d '\r')"

cd "$THEME_DIR"

if [ ! -x "tests/vendor/bin/phpunit" ]; then
	echo "BUILD ABORTED: tests/vendor/bin/phpunit not found."
	echo "Run 'composer install' inside tests/ once (its own tests/composer.json — see tests/AGENT.md), then try again."
	exit 1
fi

# Version-drift guard: vendor/ on disk must match composer.lock, or the zip
# would rebuild the OLDER locked version. The check itself lives in
# bin/check-vendor-drift.sh, shared with bin/release.sh.
DRIFT=0
"$THEME_DIR/bin/check-vendor-drift.sh" || DRIFT=1

if [ "$DRIFT" -ne 0 ]; then
	echo ""
	echo "BUILD ABORTED: vendor/ is ahead of composer.lock."
	echo "The zip's SCF/ASE come from composer.lock, not from your working copy,"
	echo "so building now would ship the OLDER version and push every updated"
	echo "site backwards. Run the command(s) above, re-test, commit, then rebuild."
	echo "(Deliberately shipping the older version? Roll vendor/ back to match"
	echo " the lock rather than bypassing this check.)"
	exit 1
fi

echo "==> Running the test suite (this is the release gate — see tests/AGENT.md)"
if ! php tests/vendor/bin/phpunit --configuration phpunit.xml.dist; then
	echo ""
	echo "BUILD ABORTED: tests are failing. Fix them before packaging a zip."
	exit 1
fi

echo "==> Tests passed. Building $THEME_SLUG-$VERSION.zip"

BUILD_ROOT="$(mktemp -d)"
BUILD_DIR="$BUILD_ROOT/$THEME_SLUG"
trap 'rm -rf "$BUILD_ROOT"' EXIT

mkdir -p "$BUILD_DIR"

rsync -a "$THEME_DIR/" "$BUILD_DIR/" \
	--exclude='.git' \
	--exclude='.DS_Store' \
	--exclude='vendor' \
	--exclude='tests' \
	--exclude='phpunit.xml.dist' \
	--exclude='.phpunit.result.cache' \
	--exclude='bin' \
	--exclude='*.zip' \
	--exclude='AGENT.md' \
	--exclude='/README.md' \
	--exclude='/CHANGELOG.md' \
	--exclude='/ROADMAP.md' \
	--exclude='/SECURITY.md' \
	--exclude='/docs' \
	--exclude='.gitignore' \
	--exclude='/languages/*.po' \
	--exclude='/languages/*.pot'

echo "==> Reinstalling dependencies into the build copy (a real verification that composer.lock still reproduces what's committed — --no-dev is now a no-op, root composer.json has no require-dev section at all, kept defensively)"
composer install --no-dev --optimize-autoloader --working-dir="$BUILD_DIR" --no-interaction --quiet

echo "==> Copying vendor/mcp-adapter/ (not Composer-managed — it isn't on Packagist/WordPress.org, see inc/dependencies/mcp-adapter/AGENT.md — so 'composer install' above never touches it)"
cp -R "$THEME_DIR/vendor/mcp-adapter" "$BUILD_DIR/vendor/mcp-adapter"

OUTPUT_ZIP="$THEME_DIR/../$THEME_SLUG-$VERSION.zip"
rm -f "$OUTPUT_ZIP"

( cd "$BUILD_ROOT" && zip -rq "$OUTPUT_ZIP" "$THEME_SLUG" )

echo "==> Done: $(cd "$(dirname "$OUTPUT_ZIP")" && pwd)/$(basename "$OUTPUT_ZIP")"
echo "    Contains vendor/ with production deps only (Timber, SCF, ASE) — no tests/, no dev tooling, no AGENT.md."
