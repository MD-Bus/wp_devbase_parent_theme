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
# CI IS PART OF THE GATE (check 9). The suite below runs on THIS machine's PHP,
# which is newer than the theme's minimum (style.css: Requires PHP 8.2). Code
# that only a newer PHP can parse passes here and is a fatal error on every
# site still on 8.2. CI (.github/workflows/tests.yml) runs the suite on 8.2 and
# 8.4, but a run started by the tag is too late: Warden acts on the tag push
# itself. So the BRANCH is pushed first, without the tag (a commit on main
# publishes nothing), the script waits for CI on that exact commit, and the tag
# is created and pushed only if CI is green.
#
# Checks, in order (1 to 8c run before anything is written):
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
#   8. the test suite passes (on this machine's PHP)
#   8b. when a PHP of the minimum version is on this machine, every PHP file
#      the theme ships parses on it (a head start on check 9, not a gate)
#   8c. PHPStan finds nothing (phpstan.neon.dist: level 5, the theme's own code)
#   9. after a confirmation, the branch is pushed and the Tests workflow on
#      that commit is green (PHP 8.2 and 8.4)
#
# Then: `git tag -a vX.Y.Z -m X.Y.Z` and a push of the tag alone. The commit it
# points to is already on the branch, so the tag never lands on GitHub without
# it. A failed push removes the local tag so the script can simply be run
# again; so does a red CI, with nothing tagged and nothing published.
#
# Usage (from anywhere — paths resolve from this file):
#   ./bin/release.sh              release the version in style.css
#   ./bin/release.sh 1.4.2        same, but refuse unless style.css says 1.4.2
#   ./bin/release.sh --dry-run    run checks 1 to 8, report CI for this commit
#                                 if it has run, push and tag nothing
#   ./bin/release.sh --yes        skip the confirmation (needed without a TTY)
#
# Environment:
#   RELEASE_BRANCH  branch releases are cut from (default: main)
#   RELEASE_REMOTE  remote that Warden watches   (default: origin)
#   WP_TESTS_DB_SOCKET  passed through to the suite (see tests/wp-tests-config.php)
#   GH_TOKEN / GITHUB_TOKEN  a token that can read the repository's Actions runs.
#                   Without one: `gh auth token`, then git's own stored
#                   credential for github.com, then no token (a public repo).
#   RELEASE_CI_TIMEOUT  seconds to wait for CI (default: 1800)
#   RELEASE_SKIP_CI=1   release without waiting for CI. For an emergency only
#                   (GitHub Actions is down and a fix cannot wait): it removes
#                   the one check that runs on the PHP version sites run.

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

# --- 8b. Syntax on the minimum PHP, when one is at hand --------------------
#
# Not the gate (CI is, check 9): a head start. If a PHP of the theme's minimum
# version is on this machine, every PHP file the theme ships is parsed with it,
# which finds "only newer PHP can read this" in seconds instead of after the
# branch is pushed. Local by Flywheel installs one; RELEASE_MIN_PHP_BIN names
# another. Skipped, with a note, when there is none.

MIN_PHP="$(grep -m1 '^Requires PHP:' style.css | sed 's/Requires PHP: *//' | tr -d '\r')"
MIN_PHP_BIN="${RELEASE_MIN_PHP_BIN:-}"
if [ -z "$MIN_PHP_BIN" ] && [ -n "$MIN_PHP" ]; then
	MIN_PHP_BIN="$(ls -d "$HOME/Library/Application Support/Local/lightning-services/php-$MIN_PHP".*/bin/*/bin/php 2>/dev/null | tail -1 || true)"
fi

if [ -n "$MIN_PHP_BIN" ] && [ -x "$MIN_PHP_BIN" ]; then
	echo "==> Parsing the theme's own PHP files with $("$MIN_PHP_BIN" -r 'echo PHP_VERSION;')"
	BROKEN=""
	while IFS= read -r FILE; do
		if ! OUT="$("$MIN_PHP_BIN" -l "$FILE" 2>&1)"; then
			BROKEN="$BROKEN$OUT"$'\n'
		fi
	done < <(git ls-files '*.php' | grep -vE '^(vendor|tests)/')
	if [ -n "$BROKEN" ]; then
		printf '%s' "$BROKEN" | sed 's/^/    /'
		fail "PHP $MIN_PHP cannot parse the file(s) above, and style.css says the theme runs on it."
	fi
	echo "    ok: every file parses on PHP $MIN_PHP"
else
	echo "==> No PHP ${MIN_PHP:-(minimum unknown)} on this machine: syntax on the minimum version is left to CI (check 9)"
fi

# --- 8c. Static analysis (PHPStan, phpstan.neon.dist) ----------------------
#
# What the suite does not run: a misspelled function in an error path, a wrong
# argument count, a type a WordPress function does not take. Level and paths
# are in phpstan.neon.dist; the vendored plugins are scanned for their symbols,
# never analysed. Installed with the test tooling (tests/composer.json).

echo "==> Static analysis (PHPStan)"

if [ ! -x "tests/vendor/bin/phpstan" ]; then
	fail "tests/vendor/bin/phpstan not found." \
		"Run 'composer install' inside tests/ once (see tests/AGENT.md)."
fi
if ! php -d memory_limit=2G tests/vendor/bin/phpstan analyse --no-progress --configuration phpstan.neon.dist; then
	fail "PHPStan found the problem(s) above. Nothing was tagged."
fi

# --- 9. CI on this commit (helpers; used below) ----------------------------

SHA="$(git rev-parse HEAD)"
CI_TIMEOUT="${RELEASE_CI_TIMEOUT:-1800}"
CI_WORKFLOW="tests.yml"

# owner/repo of the remote, or empty when it is not on github.com.
REMOTE_URL="$(git remote get-url "$REMOTE" 2>/dev/null || true)"
SLUG="$(printf '%s' "$REMOTE_URL" | sed -nE 's#^(https://([^@/]+@)?github\.com/|git@github\.com:|ssh://git@github\.com/)([^/]+/[^/]+)$#\3#p' | sed 's/\.git$//')"

# A token to read the Actions runs with, or empty (enough for a public repo).
ci_token() {
	if [ -n "${GH_TOKEN:-}" ]; then printf '%s' "$GH_TOKEN"; return; fi
	if [ -n "${GITHUB_TOKEN:-}" ]; then printf '%s' "$GITHUB_TOKEN"; return; fi
	if command -v gh >/dev/null 2>&1 && gh auth token >/dev/null 2>&1; then gh auth token; return; fi
	printf 'protocol=https\nhost=github.com\n\n' | GIT_TERMINAL_PROMPT=0 git credential fill 2>/dev/null | sed -n 's/^password=//p' | head -1
}

# The Tests workflow's verdict on $SHA as pushed to the branch. Prints one of:
#   success | pending | none | failed:<conclusion> | error:<why>
# Only a run started by a push to the release branch counts: a run for a pull
# request or for a tag is about something else.
ci_state() {
	local TOKEN BODY
	TOKEN="$(ci_token || true)"

	if ! BODY="$(curl -sS --max-time 30 \
		-H 'Accept: application/vnd.github+json' \
		${TOKEN:+-H "Authorization: Bearer $TOKEN"} \
		"https://api.github.com/repos/$SLUG/actions/workflows/$CI_WORKFLOW/runs?head_sha=$SHA&per_page=30" 2>&1)"; then
		printf 'error:%s' "$(printf '%s' "$BODY" | head -1)"
		return
	fi

	printf '%s' "$BODY" | RELEASE_BRANCH_NAME="$BRANCH" php -r '
		$data = json_decode( stream_get_contents( STDIN ), true );
		if ( ! is_array( $data ) || ! isset( $data["workflow_runs"] ) ) {
			echo "error:", is_array( $data ) && isset( $data["message"] ) ? $data["message"] : "unreadable answer from GitHub";
			exit;
		}
		foreach ( $data["workflow_runs"] as $run ) { // newest first
			if ( "push" !== ( $run["event"] ?? "" ) || getenv( "RELEASE_BRANCH_NAME" ) !== ( $run["head_branch"] ?? "" ) ) {
				continue;
			}
			if ( "completed" !== ( $run["status"] ?? "" ) ) { echo "pending"; exit; }
			echo "success" === ( $run["conclusion"] ?? "" ) ? "success" : "failed:" . ( $run["conclusion"] ?? "unknown" );
			exit;
		}
		echo "none";
	'
}

# --- Confirm ---------------------------------------------------------------

COMMIT="$(git rev-parse --short HEAD)"
echo ""
echo "Checks 1 to 8c passed: $TAG at $COMMIT ($(git log -1 --format=%s))"

if [ "$DRY_RUN" -eq 1 ]; then
	if [ -n "$SLUG" ]; then
		echo "CI for this commit on $BRANCH: $(ci_state)  (none = not pushed yet; a release pushes it and waits)"
	else
		echo "CI: '$REMOTE' is not a github.com remote, so a release would stop at check 9."
	fi
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

# --- 9. Push the branch, wait for CI ---------------------------------------

echo "==> Pushing $BRANCH (no tag yet: this publishes nothing)"

if ! git push "$REMOTE" "$BRANCH"; then
	fail "could not push $BRANCH. Nothing was tagged."
fi

if [ "${RELEASE_SKIP_CI:-0}" = "1" ]; then
	echo ""
	echo "    WARNING: RELEASE_SKIP_CI=1. Releasing without CI: nothing has run this"
	echo "    commit on PHP 8.2, the version the theme promises to support."
else
	echo "==> Waiting for CI on $COMMIT (PHP 8.2 and 8.4)"

	if [ -z "$SLUG" ]; then
		fail "'$REMOTE' ($REMOTE_URL) is not a github.com remote, so CI cannot be checked." \
			"$BRANCH was pushed; nothing was tagged."
	fi

	WAITED=0
	MISSING=0
	while :; do
		STATE="$(ci_state)"
		case "$STATE" in
			success)
				echo "    ok: the Tests workflow passed on $COMMIT"
				break
				;;
			failed:*)
				fail "CI did not pass on $COMMIT (${STATE#failed:}). Nothing was tagged, nothing was published." \
					"https://github.com/$SLUG/actions/workflows/$CI_WORKFLOW" \
					"Fix it in a new commit and run this again."
				;;
			none)
				# GitHub takes a few seconds to create the run. Two minutes without one is not that.
				MISSING=$((MISSING + 15))
				if [ "$MISSING" -ge 120 ]; then
					fail "no Tests run appeared for $COMMIT on $BRANCH after two minutes." \
						"Is GitHub Actions enabled, and does $CI_WORKFLOW still run on a push to $BRANCH?" \
						"$BRANCH was pushed; nothing was tagged."
				fi
				;;
			error:*)
				echo "    could not read CI (${STATE#error:}); trying again"
				;;
		esac

		if [ "$WAITED" -ge "$CI_TIMEOUT" ]; then
			fail "CI had not finished after ${CI_TIMEOUT}s (last state: $STATE). Nothing was tagged." \
				"Run this again once it has: a finished green run is picked up at once."
		fi

		sleep 15
		WAITED=$((WAITED + 15))
	done
fi

# The tag goes on the commit that was checked and that CI ran: a commit made in
# this working copy during the wait has been through neither.
if [ "$(git rev-parse HEAD)" != "$SHA" ]; then
	fail "HEAD moved while waiting for CI. Nothing was tagged; run this again."
fi

# --- Tag and push ----------------------------------------------------------

git tag -a "$TAG" -m "$VERSION"

if ! git push "$REMOTE" "refs/tags/$TAG"; then
	git tag -d "$TAG" >/dev/null
	fail "the push failed, so nothing was published. The local tag was removed;" \
		"fix the cause and run this again."
fi

echo ""
echo "==> Released $TAG. Warden publishes it on the push; check the release"
echo "    appears on this theme's track there."
