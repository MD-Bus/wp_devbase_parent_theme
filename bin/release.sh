#!/usr/bin/env bash
# Tags and pushes a release — the ONLY supported way to cut one.
#
# WHY THIS EXISTS: a release is just a pushed `vX.Y.Z` tag. Warden's GitHub
# App sees the push and publishes GitHub's archive of the tag to every site on
# this theme's track. Nothing in that path runs bin/build-zip.sh, so neither
# of its gates (vendor drift, the test suite) sees the code before sites do,
# and nothing checks that style.css and CHANGELOG.md carry the version being
# tagged. A tag that is wrong cannot be fully recalled once sites have
# installed it. This script is the gate: it refuses unless every check below
# passes, and only then creates the annotated tag and pushes it.
#
# CI, when it exists, runs after the push and can only warn; Warden acts on
# the push itself. This script is the gate that actually blocks.
#
# Checks, in order (all run before anything is written):
#   1. on the release branch (main), with a clean tree — untracked files
#      count, ignored ones (tests/vendor/, AGENT.md) don't
#   2. style.css carries an X.Y.Z version (and the argument, if given, matches)
#   3. no tag vX.Y.Z exists, locally or on origin
#   4. the version is newer than every existing vX.Y.Z tag
#   5. CHANGELOG.md: [Unreleased] is empty, and the first release section
#      under it is [X.Y.Z], dated, and not empty
#   6. the branch is not behind origin (what is tagged includes what is
#      already published)
#   7. vendor/ matches composer.lock (bin/check-vendor-drift.sh)
#   8. the test suite passes
#
# Then, after a confirmation: `git tag -a vX.Y.Z -m X.Y.Z` and one atomic push
# of the branch and the tag, so the tag never lands on GitHub without the
# commit it points to being on the branch (or the other way round). A failed
# push removes the local tag so the script can simply be run again.
#
# Usage (from anywhere — paths resolve from this file):
#   ./bin/release.sh              release the version in style.css
#   ./bin/release.sh 1.4.2        same, but refuse unless style.css says 1.4.2
#   ./bin/release.sh --dry-run    run every check, tag and push nothing
#   ./bin/release.sh --yes        skip the confirmation (needed without a TTY)
#
# Environment:
#   RELEASE_BRANCH  branch releases are cut from (default: main)
#   RELEASE_REMOTE  remote that Warden watches   (default: origin)
#   WP_TESTS_DB_SOCKET  passed through to the suite (see tests/wp-tests-config.php)

set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BRANCH="${RELEASE_BRANCH:-main}"
REMOTE="${RELEASE_REMOTE:-origin}"

DRY_RUN=0
ASSUME_YES=0
WANTED=""
for ARG in "$@"; do
	case "$ARG" in
		--dry-run) DRY_RUN=1 ;;
		--yes|-y) ASSUME_YES=1 ;;
		-h|--help)
			sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
			exit 0
			;;
		*)
			if [ -n "$WANTED" ]; then
				echo "ABORTED: unexpected argument '$ARG'."
				exit 1
			fi
			WANTED="${ARG#v}"
			;;
	esac
done

fail() {
	echo ""
	echo "RELEASE ABORTED: $1"
	shift
	for LINE in "$@"; do
		echo "    $LINE"
	done
	exit 1
}

cd "$THEME_DIR"

# --- 1. Branch and working tree -------------------------------------------

echo "==> Checking the working tree"

if [ "$(git rev-parse --show-toplevel 2>/dev/null)" != "$THEME_DIR" ]; then
	fail "$THEME_DIR is not the root of a git repository."
fi

CURRENT_BRANCH="$(git branch --show-current)"
if [ "$CURRENT_BRANCH" != "$BRANCH" ]; then
	fail "on branch '${CURRENT_BRANCH:-(detached)}', releases are cut from '$BRANCH'."
fi

DIRTY="$(git status --porcelain)"
if [ -n "$DIRTY" ]; then
	printf '%s\n' "$DIRTY" | sed 's/^/    /'
	fail "the working tree is not clean (above). A tag ships what is committed," \
		"and the tests below must run against exactly that. Commit or stash first."
fi
echo "    ok: on $BRANCH, nothing uncommitted"

# --- 2. Version in style.css ----------------------------------------------

VERSION="$(grep -m1 '^Version:' style.css | sed 's/Version: *//' | tr -d '\r')"
TAG="v$VERSION"

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
	fail "style.css has version '$VERSION', which is not X.Y.Z."
fi
if [ -n "$WANTED" ] && [ "$WANTED" != "$VERSION" ]; then
	fail "asked to release $WANTED, but style.css says $VERSION." \
		"Run ./bin/bump-version.sh $WANTED and commit it first."
fi
echo "    ok: style.css says $VERSION"

# --- 3 and 4. The tag is new, and newer than every other ------------------

echo "==> Checking tags"

if ! git fetch --quiet --tags "$REMOTE" "$BRANCH"; then
	fail "could not fetch from '$REMOTE'. Every remaining check needs it."
fi

if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
	fail "tag $TAG already exists. Bump the version: ./bin/bump-version.sh minor (anything Added) or patch (fixes only)."
fi
if git ls-remote --exit-code --tags "$REMOTE" "refs/tags/$TAG" >/dev/null 2>&1; then
	fail "tag $TAG already exists on $REMOTE."
fi

LATEST="$(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' | sed 's/^v//' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1 || true)"
if [ -n "$LATEST" ]; then
	HIGHEST="$(printf '%s\n%s\n' "$LATEST" "$VERSION" | sort -V | tail -1)"
	if [ "$HIGHEST" != "$VERSION" ]; then
		fail "$VERSION is not newer than the latest release, $LATEST." \
			"Sites only accept a strictly higher version; this release would reach nobody."
	fi
	echo "    ok: $TAG is new, latest was v$LATEST"
else
	echo "    ok: $TAG is the first release tag"
fi

# --- 5. CHANGELOG.md -------------------------------------------------------

echo "==> Checking CHANGELOG.md"

# Prints the lines of the section whose heading starts with "## [$1]", up to
# the next "## [" heading.
changelog_section() {
	awk -v name="$1" '
		index($0, "## [") == 1 { inside = ( index($0, "## [" name "]") == 1 ); next }
		inside { print }
	' CHANGELOG.md
}

if [ ! -f CHANGELOG.md ]; then
	fail "CHANGELOG.md is missing."
fi

if changelog_section "Unreleased" | grep -q '[^[:space:]]'; then
	fail "[Unreleased] in CHANGELOG.md is not empty." \
		"Move its entries under a '## [$VERSION] — $(date +%Y-%m-%d)' heading."
fi

FIRST_RELEASE="$(grep -m1 -E '^## \[[0-9]' CHANGELOG.md || true)"
if ! printf '%s' "$FIRST_RELEASE" | grep -qE "^## \[$(printf '%s' "$VERSION" | sed 's/\./\\./g')\]"; then
	fail "the newest section in CHANGELOG.md is '${FIRST_RELEASE:-none}', not [$VERSION]." \
		"Every release needs a section, and it goes directly under [Unreleased]."
fi
if ! printf '%s' "$FIRST_RELEASE" | grep -qE '[0-9]{4}-[0-9]{2}-[0-9]{2}'; then
	fail "the [$VERSION] heading in CHANGELOG.md has no date." \
		"Expected: ## [$VERSION] — $(date +%Y-%m-%d)"
fi
if ! changelog_section "$VERSION" | grep -q '[^[:space:]]'; then
	fail "the [$VERSION] section in CHANGELOG.md is empty."
fi
echo "    ok: $FIRST_RELEASE"

# --- 6. Not behind the remote ----------------------------------------------

echo "==> Checking $BRANCH against $REMOTE/$BRANCH"

if git rev-parse -q --verify "refs/remotes/$REMOTE/$BRANCH" >/dev/null; then
	if ! git merge-base --is-ancestor "$REMOTE/$BRANCH" HEAD; then
		fail "$BRANCH is behind or has diverged from $REMOTE/$BRANCH." \
			"Pull first, so this release includes what is already published."
	fi
	AHEAD="$(git rev-list --count "$REMOTE/$BRANCH..HEAD")"
	echo "    ok: $AHEAD commit(s) ahead of $REMOTE/$BRANCH, none behind"
else
	echo "    ok: $REMOTE has no $BRANCH yet; it will be created"
fi

# --- 7. Vendor drift -------------------------------------------------------

if ! "$THEME_DIR/bin/check-vendor-drift.sh"; then
	fail "vendor/ does not match composer.lock." \
		"Run the command(s) above, re-test, commit, then release."
fi

# --- 8. Test suite ---------------------------------------------------------

echo "==> Running the test suite"

if [ ! -x "tests/vendor/bin/phpunit" ]; then
	fail "tests/vendor/bin/phpunit not found." \
		"Run 'composer install' inside tests/ once (see tests/AGENT.md)."
fi
if ! php tests/vendor/bin/phpunit --configuration phpunit.xml.dist; then
	fail "the test suite is failing. Nothing was tagged."
fi

# --- Tag and push ----------------------------------------------------------

COMMIT="$(git rev-parse --short HEAD)"
echo ""
echo "All checks passed: $TAG at $COMMIT ($(git log -1 --format=%s))"

if [ "$DRY_RUN" -eq 1 ]; then
	echo "Dry run: nothing tagged, nothing pushed."
	exit 0
fi

if [ "$ASSUME_YES" -ne 1 ]; then
	if [ ! -t 0 ]; then
		fail "no terminal to confirm on. Re-run with --yes to release without asking."
	fi
	echo ""
	echo "Pushing $TAG publishes it through Warden to every site on this track."
	printf 'Tag and push %s to %s? [y/N] ' "$TAG" "$REMOTE"
	read -r ANSWER
	case "$ANSWER" in
		y|Y|yes|YES) ;;
		*) echo "Nothing tagged."; exit 1 ;;
	esac
fi

git tag -a "$TAG" -m "$VERSION"

if ! git push --atomic "$REMOTE" "$BRANCH" "refs/tags/$TAG"; then
	git tag -d "$TAG" >/dev/null
	fail "the push failed, so nothing was published. The local tag was removed;" \
		"fix the cause and run this again."
fi

echo ""
echo "==> Released $TAG. Warden publishes it on the push; check the release"
echo "    appears on this theme's track there."
