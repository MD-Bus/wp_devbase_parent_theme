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
# ⚠ BEFORE CUTTING A RELEASE: refresh the vendor/ baseline.
# This zip carries vendor/ (SCF, ASE, MCP Adapter, Timber), and a theme
# update overwrites the whole theme directory — so whatever versions this
# zip carries become what every updated site runs, REVERTING any newer copy
# their own daily dependency check had already installed. The site
# re-updates itself within a day, but for that day it runs whatever
# shipped, which could be an older release with a known issue.
#
# HOW the zip gets its vendor/ differs per dependency, and this is the part
# that bites. rsync excludes vendor/ entirely, then:
#
#   SCF / ASE / Timber / Twig  — come from `composer install` below, i.e.
#       from composer.lock. Your WORKING COPY IS IRRELEVANT. Committing a
#       newer copy that the live auto-updater put on disk does nothing;
#       you have to bump the pin:
#           composer require wpackagist-plugin/admin-site-enhancements:9.2.0
#       The version-drift check below refuses to build until you do.
#
#   MCP Adapter — copied straight from the working copy (it isn't in
#       composer.json at all), so for this one, committing what the
#       auto-updater installed IS the correct action.
#
# See inc/dependencies/AGENT.md, "committed to git".

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

# Version-drift guard. The daily dependency updater swaps vendor/ folders at
# the filesystem level and never touches Composer's bookkeeping, so a
# long-running dev site's working copy routinely gets ahead of composer.lock.
# That drift is fine day to day (see inc/dependencies/AGENT.md) but it must
# never reach a release: `composer install` below would quietly rebuild the
# OLDER locked version into the zip, and every site that updated would be
# pushed backwards. Caught for real while documenting this: ASE had
# auto-updated to 9.2.0 on disk while composer.lock still pinned 9.0.0.
echo "==> Checking vendor/ versions against composer.lock"
DRIFT=0
while IFS='|' read -r SLUG MAIN_FILE; do
	[ -z "$SLUG" ] && continue

	PLUGIN_FILE="$THEME_DIR/vendor/$SLUG/$MAIN_FILE"
	DISK_VERSION=""
	if [ -f "$PLUGIN_FILE" ]; then
		DISK_VERSION="$(grep -m1 -i '^[[:space:]*]*Version:' "$PLUGIN_FILE" | sed 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '\r')"
	fi

	LOCK_VERSION="$(php -r '
		$lock = json_decode( file_get_contents( $argv[1] ), true );
		foreach ( $lock["packages"] as $p ) {
			if ( "wpackagist-plugin/" . $argv[2] === $p["name"] ) { echo $p["version"]; return; }
		}
	' "$THEME_DIR/composer.lock" "$SLUG")"

	if [ -n "$DISK_VERSION" ] && [ -n "$LOCK_VERSION" ] && [ "$DISK_VERSION" != "$LOCK_VERSION" ]; then
		echo "    DRIFT: $SLUG is $DISK_VERSION on disk but $LOCK_VERSION in composer.lock"
		echo "           -> composer require wpackagist-plugin/$SLUG:$DISK_VERSION"
		DRIFT=1
	else
		echo "    ok: $SLUG $LOCK_VERSION"
	fi
done <<-DEPS
	secure-custom-fields|secure-custom-fields.php
	admin-site-enhancements|admin-site-enhancements.php
DEPS

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
