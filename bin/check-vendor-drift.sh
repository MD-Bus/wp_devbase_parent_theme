#!/usr/bin/env bash
# Version-drift guard, shared by bin/build-zip.sh and bin/release.sh.
#
# The daily dependency updater swaps vendor/ folders at the filesystem level
# and never touches Composer's bookkeeping, so a long-running dev site's
# working copy routinely gets ahead of composer.lock. That drift is fine day
# to day (see inc/dependencies/AGENT.md) but it must never reach a release:
# the zip rebuilds SCF/ASE from composer.lock, and a tag ships whatever vendor/
# is committed while the lock says something else, so the next `composer
# install` would quietly move every site backwards. Caught for real while
# documenting this: ASE had auto-updated to 9.2.0 on disk while composer.lock
# still pinned 9.0.0.
#
# Exits 0 when every Composer-managed plugin on disk matches the lock, 1 when
# any differs (printing the `composer require` that fixes it).

set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

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

# The other way round: composer.lock ahead of vendor/. A Dependabot pull
# request (.github/dependabot.yml) bumps Timber/Twig in composer.lock but
# cannot touch the committed vendor/, which is what a tag ships. Composer's
# own record of what it installed into vendor/ (vendor/composer/installed.json)
# must therefore list exactly the packages and versions the lock does. The
# plugin headers above can't catch this: SCF/ASE are the only packages with a
# header, and Timber/Twig are the ones Dependabot moves.
if ! php -r '
	$lock      = json_decode( file_get_contents( $argv[1] ), true );
	$installed = json_decode( (string) @file_get_contents( $argv[2] ), true );
	if ( ! is_array( $installed ) ) {
		echo "    DRIFT: vendor/composer/installed.json is missing or unreadable\n";
		exit( 1 );
	}
	$on_disk = array();
	foreach ( $installed["packages"] ?? $installed as $p ) {
		$on_disk[ $p["name"] ] = $p["version"];
	}
	$bad = 0;
	foreach ( $lock["packages"] as $p ) {
		$have = $on_disk[ $p["name"] ] ?? "(not installed)";
		unset( $on_disk[ $p["name"] ] );
		if ( $have !== $p["version"] ) {
			echo "    DRIFT: ", $p["name"], " is ", $p["version"], " in composer.lock but ", $have, " in vendor/\n";
			$bad = 1;
		}
	}
	foreach ( $on_disk as $name => $version ) {
		echo "    DRIFT: ", $name, " ", $version, " is in vendor/ but not in composer.lock\n";
		$bad = 1;
	}
	if ( $bad ) {
		echo "           -> composer install, run the suite, then commit vendor/ with composer.lock\n";
	} else {
		echo "    ok: vendor/ has every package at the composer.lock version (", count( $lock["packages"] ), ")\n";
	}
	exit( $bad );
' "$THEME_DIR/composer.lock" "$THEME_DIR/vendor/composer/installed.json"; then
	DRIFT=1
fi

exit "$DRIFT"
