#!/usr/bin/env bash
#
# deploy.sh - cut a Secure Login Collector release.
#
# Bumps the version in all three places (plugin header, SECULOCO_VERSION,
# readme "Stable tag"), commits "Release version X.Y.Z", creates the annotated
# tag vX.Y.Z and pushes main with the tag. The tag push triggers
# .github/workflows/freemius-deploy.yml, which verifies the versions again,
# builds the package from .distignore and uploads it to Freemius as "pending".
#
# Usage: scripts/deploy.sh                          (asks for the version)
#        scripts/deploy.sh <X.Y.Z> [--dry-run] [--yes]
#
#   --dry-run   validate and show the plan only; changes nothing
#   --yes       skip the confirmation prompt
#
# Guards: clean working tree, on the release branch, not behind origin,
# valid X.Y.Z, version greater than the current one, tag free locally and on
# origin, changelog entry "= X.Y.Z =" present in readme.txt.
#
# Portability: runs on macOS (bash 3.2, BSD sed). Rewrites go through a temp
# file instead of in-place sed.

set -eu

RELEASE_BRANCH="main"
PLUGIN_FILE="secure-login-collector.php"
README_FILE="readme.txt"
REPO_SLUG="mikemiler/secure-login-collector"
VERSION_PATTERN='^[0-9]+\.[0-9]+\.[0-9]+$'

DRY_RUN=0
ASSUME_YES=0
NEW_VERSION=""

usage() {
	sed -n '11,16p' "$0" | sed 's/^# \{0,1\}//'
}

die() {
	echo "Error: $*" >&2
	exit 1
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--dry-run) DRY_RUN=1 ;;
		--yes|-y) ASSUME_YES=1 ;;
		-h|--help) usage; exit 0 ;;
		-*) usage >&2; die "Unknown option: $1" ;;
		*)
			[ -z "$NEW_VERSION" ] || die "Unexpected extra argument: $1"
			NEW_VERSION="$1"
			;;
	esac
	shift
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$(dirname "$SCRIPT_DIR")"

# --- Preconditions ---------------------------------------------------------

[ -f "$PLUGIN_FILE" ] && [ -f "$README_FILE" ] || die "Plugin root not found (missing $PLUGIN_FILE or $README_FILE)."
command -v git >/dev/null 2>&1 || die "git is required."
command -v php >/dev/null 2>&1 || die "php is required (version comparison)."
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "Not inside a git working tree."

[ -z "$(git status --porcelain)" ] || die "Working tree is not clean. Commit or stash your changes first."

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" = "$RELEASE_BRANCH" ] || die "On branch '$BRANCH', releases are cut from '$RELEASE_BRANCH'."

git fetch --quiet origin "$RELEASE_BRANCH" || die "git fetch origin $RELEASE_BRANCH failed."
BEHIND="$(git rev-list --count "HEAD..origin/$RELEASE_BRANCH")"
[ "$BEHIND" -eq 0 ] || die "Local $RELEASE_BRANCH is $BEHIND commit(s) behind origin. Pull first."

read_header_version() {
	sed -E -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN_FILE" | head -n1
}
read_constant_version() {
	sed -E -n "s/^define\( 'SECULOCO_VERSION', '([^']+)' \);.*/\1/p" "$PLUGIN_FILE" | head -n1
}
read_stable_tag() {
	sed -E -n 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' "$README_FILE" | head -n1
}

CURRENT_VERSION="$(read_header_version)"
CURRENT_CONSTANT="$(read_constant_version)"
CURRENT_STABLE="$(read_stable_tag)"
[ -n "$CURRENT_VERSION" ] || die "Could not read the 'Version:' header from $PLUGIN_FILE."
[ -n "$CURRENT_CONSTANT" ] || die "Could not read SECULOCO_VERSION from $PLUGIN_FILE."
[ -n "$CURRENT_STABLE" ] || die "Could not read 'Stable tag:' from $README_FILE."

# --- Resolve the version ----------------------------------------------------

if [ -z "$NEW_VERSION" ]; then
	[ "$ASSUME_YES" -eq 0 ] || die "--yes needs an explicit version."
	[ -t 0 ] || die "No version given and stdin is not a terminal. Pass the version explicitly."
	IFS='.' read -r MAJOR MINOR PATCH <<EOF
$CURRENT_VERSION
EOF
	SUGGESTED="${MAJOR}.${MINOR}.$((PATCH + 1))"
	echo "Current version: $CURRENT_VERSION"
	printf 'Version to release [%s]: ' "$SUGGESTED"
	read -r NEW_VERSION || { echo; echo "Aborted. Nothing was changed."; exit 0; }
	[ -n "$NEW_VERSION" ] || NEW_VERSION="$SUGGESTED"
fi

printf '%s' "$NEW_VERSION" | grep -Eq "$VERSION_PATTERN" || die "Invalid version '$NEW_VERSION'. Expected X.Y.Z, e.g. 2.1.0."

if [ "$(php -r 'echo version_compare($argv[1], $argv[2], ">") ? "1" : "0";' "$NEW_VERSION" "$CURRENT_VERSION")" != "1" ]; then
	die "New version $NEW_VERSION is not greater than the current version $CURRENT_VERSION."
fi

TAG="v${NEW_VERSION}"
git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null && die "Tag $TAG already exists locally."
[ -z "$(git ls-remote --tags origin "refs/tags/${TAG}" 2>/dev/null)" ] || die "Tag $TAG already exists on origin."

grep -q "^= ${NEW_VERSION} =$" "$README_FILE" \
	|| die "No changelog entry '= ${NEW_VERSION} =' in $README_FILE. Add it (and commit) first."

LAST_TAG="$(git describe --tags --abbrev=0 2>/dev/null || echo "")"

# --- Plan ------------------------------------------------------------------

echo
echo "Release plan"
echo "  Branch:            $RELEASE_BRANCH (up to date with origin)"
echo "  Version header:    $CURRENT_VERSION -> $NEW_VERSION"
echo "  SECULOCO_VERSION:  $CURRENT_CONSTANT -> $NEW_VERSION"
echo "  Stable tag:        $CURRENT_STABLE -> $NEW_VERSION"
echo "  Commit:            Release version $NEW_VERSION"
echo "  Tag:               $TAG (annotated)"
echo "  Push:              git push origin $RELEASE_BRANCH, then git push origin $TAG"
echo "  Then:              GitHub Actions deploys to Freemius (pending)"
if [ -n "$LAST_TAG" ]; then
	echo
	echo "Commits since $LAST_TAG:"
	git log --oneline "${LAST_TAG}..HEAD"
fi
echo

if [ "$DRY_RUN" -eq 1 ]; then
	echo "Dry run: nothing was changed."
	exit 0
fi

if [ "$ASSUME_YES" -eq 0 ]; then
	printf 'Cut and push release %s? (y/N): ' "$NEW_VERSION"
	read -r CONFIRM || CONFIRM=""
	case "$CONFIRM" in
		y|Y) ;;
		*) echo "Aborted. Nothing was changed."; exit 0 ;;
	esac
fi

# --- Rewrite versions --------------------------------------------------------

rewrite() {
	# $1 = file, $2 = sed expression
	local tmp
	tmp="$(mktemp)"
	sed -E "$2" "$1" > "$tmp"
	cat "$tmp" > "$1"
	rm -f "$tmp"
}

rewrite "$PLUGIN_FILE" "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*/\1${NEW_VERSION}/"
rewrite "$PLUGIN_FILE" "s/^define\( 'SECULOCO_VERSION', '[^']+' \);/define( 'SECULOCO_VERSION', '${NEW_VERSION}' );/"
rewrite "$README_FILE" "s/^Stable tag:.*/Stable tag: ${NEW_VERSION}/"

# Verify the rewrite before committing anything.
if [ "$(read_header_version)" != "$NEW_VERSION" ] \
	|| [ "$(read_constant_version)" != "$NEW_VERSION" ] \
	|| [ "$(read_stable_tag)" != "$NEW_VERSION" ]; then
	git checkout -- "$PLUGIN_FILE" "$README_FILE"
	die "Version rewrite failed verification. Files were reverted."
fi

git add "$PLUGIN_FILE" "$README_FILE"
git commit --quiet -m "Release version ${NEW_VERSION}"
git tag -a "$TAG" -m "Version ${NEW_VERSION}"
echo "Committed $(git rev-parse --short HEAD) and tagged $TAG."

push_failed() {
	echo >&2
	echo "Error: $1" >&2
	echo "The release commit and tag $TAG exist locally. Do NOT rerun this script for $NEW_VERSION." >&2
	echo "Fix the problem, then push manually:" >&2
	echo "  git push origin $RELEASE_BRANCH" >&2
	echo "  git push origin $TAG" >&2
	exit 1
}

# Push the branch first, then only this tag (the tag push triggers the Freemius workflow).
git push origin "$RELEASE_BRANCH" || push_failed "Pushing $RELEASE_BRANCH failed."
git push origin "$TAG" || push_failed "Pushing $TAG failed."

if [ -z "$(git ls-remote --tags origin "refs/tags/${TAG}" 2>/dev/null)" ]; then
	push_failed "Push finished but $TAG is not on origin."
fi

echo
echo "Released $NEW_VERSION. Tag $TAG is on origin."
echo "Watch the Freemius deploy: https://github.com/${REPO_SLUG}/actions"
echo "Then: release $NEW_VERSION in the Freemius dashboard, download the free ZIP"
echo "and run scripts/deploy-freemius-to-svn.sh --dry-run before the real SVN deploy."
