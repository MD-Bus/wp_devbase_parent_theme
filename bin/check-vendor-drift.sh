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

exit "$DRIFT"
