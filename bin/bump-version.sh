#!/usr/bin/env bash
# Bumps this theme's version — the single number the whole update system
# turns on.
#
# WHERE THE VERSION LIVES: the `Version:` header in style.css. That is the
# only place. Everything else reads it:
#
#   wp_get_theme( $stylesheet )->get( 'Version' )   <- WordPress
#   devbase_update_installed_version()             <- the theme's updater
#   GET {warden}/check/{key}?version=1.0.0          <- sent to Warden
#   bin/build-zip.sh                                <- names the zip
#
# Warden compares the version a site reports against the release published
# on that site's track, and only offers an update when the release is
# strictly newer. So a release built WITHOUT bumping this reaches nobody:
# every site reports the same version Warden is offering, and correctly
# concludes it has nothing to do. That is the single easiest mistake to
# make in the release flow, which is why this script exists instead of a
# line in a checklist.
#
# Usage:
#   ./bin/bump-version.sh patch     1.2.3 -> 1.2.4   (fixes)
#   ./bin/bump-version.sh minor     1.2.3 -> 1.3.0   (new features, compatible)
#   ./bin/bump-version.sh major     1.2.3 -> 2.0.0   (breaking)
#   ./bin/bump-version.sh 2.5.0     set it explicitly

set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STYLE="$THEME_DIR/style.css"

if [ ! -f "$STYLE" ]; then
	echo "ABORTED: no style.css at $STYLE"
	exit 1
fi

CURRENT="$(grep -m1 '^Version:' "$STYLE" | sed 's/Version: *//' | tr -d '\r')"

if [ -z "$CURRENT" ]; then
	echo "ABORTED: no 'Version:' header in style.css."
	echo "Without one, Warden can never offer an update to this theme."
	exit 1
fi

BUMP="${1:-}"

if [ -z "$BUMP" ]; then
	echo "Current version: $CURRENT"
	echo "Usage: ./bin/bump-version.sh [patch|minor|major|X.Y.Z]"
	exit 0
fi

case "$BUMP" in
	patch|minor|major)
		IFS='.' read -r MAJOR MINOR PATCH <<-EOF
			$CURRENT
		EOF
		MAJOR="${MAJOR:-0}"; MINOR="${MINOR:-0}"; PATCH="${PATCH:-0}"
		case "$BUMP" in
			patch) PATCH=$((PATCH + 1)) ;;
			minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
			major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
		esac
		NEW="$MAJOR.$MINOR.$PATCH"
		;;
	*)
		# An explicit version. Must look like X.Y.Z, because version_compare()
		# on both sides of this system expects it.
		if ! printf '%s' "$BUMP" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
			echo "ABORTED: '$BUMP' is not X.Y.Z."
			exit 1
		fi
		NEW="$BUMP"
		;;
esac

# Only ever move forward. A release numbered below what sites already run
# would be invisible to all of them, and silently so.
LOWER="$(printf '%s\n%s\n' "$CURRENT" "$NEW" | sort -V | head -1)"
if [ "$NEW" = "$CURRENT" ] || { [ "$LOWER" = "$NEW" ] && [ "$NEW" != "$CURRENT" ]; }; then
	echo "ABORTED: $NEW is not newer than $CURRENT."
	echo "Sites only accept a strictly higher version; this release would reach nobody."
	exit 1
fi

# sed -i '' is the BSD/macOS form.
sed -i '' "s/^Version: .*/Version: $NEW/" "$STYLE"

echo "$CURRENT -> $NEW"
echo ""
echo "Next:"
echo "  1. Add a $NEW section to CHANGELOG.md"
echo "  2. ./bin/build-zip.sh"
echo "  3. Upload the zip to Warden as a $NEW release on this theme's track"
