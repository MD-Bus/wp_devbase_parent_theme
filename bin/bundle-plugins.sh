#!/usr/bin/env bash
# Builds bundled-plugins/<slug>.zip from bundled-plugins/composer.lock.
#
# WHY A ZIP, AND WHY IT IS COMMITTED: WooCommerce is not loaded from vendor/
# like SCF and ASE. It is installed into wp-content/plugins/ by
# inc/bundled-plugins/, which unpacks this zip. Warden publishes GitHub's archive
# of a release tag, so what the tag ships is what is committed: the zip must be
# in git. (Nothing runs this script on a site or in Warden.)
#
# Run it after the pinned version in bundled-plugins/composer.json changes
# (a Dependabot pull request for /bundled-plugins moves the pin and the lock;
# the zip is the step it cannot do), then commit the zip with the lock.
# bin/check-vendor-drift.sh, which bin/release.sh runs, refuses to release
# while the zip and the lock disagree.
#
# Usage:  ./bin/bundle-plugins.sh

set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIR="$THEME_DIR/bundled-plugins"
BUILD="$DIR/.build"

cd "$DIR"

echo "==> Installing the locked versions into bundled-plugins/.build/"
rm -rf "$BUILD"
composer install --no-dev --no-scripts --no-interaction --no-progress --quiet

while IFS='|' read -r SLUG MAIN_FILE; do
	[ -z "$SLUG" ] && continue

	SRC="$BUILD/$SLUG"
	if [ ! -f "$SRC/$MAIN_FILE" ]; then
		echo "ABORTED: $SRC/$MAIN_FILE not found after composer install."
		exit 1
	fi

	VERSION="$(grep -m1 -i '^[[:space:]*]*Version:' "$SRC/$MAIN_FILE" | sed 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '\r')"
	LOCKED="$(php -r '
		$lock = json_decode( file_get_contents( $argv[1] ), true );
		foreach ( $lock["packages"] as $p ) {
			if ( "wp-plugin/" . $argv[2] === $p["name"] ) { echo $p["version"]; return; }
		}
	' "$DIR/composer.lock" "$SLUG")"

	if [ "$VERSION" != "$LOCKED" ]; then
		echo "ABORTED: $SLUG is $VERSION on disk but $LOCKED in composer.lock."
		exit 1
	fi

	ZIP="$DIR/$SLUG.zip"
	rm -f "$ZIP"
	# -X: no extra file attributes, so the same files give the same zip.
	( cd "$BUILD" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )
	echo "==> $SLUG $VERSION -> bundled-plugins/$SLUG.zip ($(du -h "$ZIP" | cut -f1))"
done <<-PLUGINS
	woocommerce|woocommerce.php
	wpvivid-backuprestore|wpvivid-backuprestore.php
PLUGINS

rm -rf "$BUILD"
