#!/bin/bash
# Get version from git tags
# This script extracts the current version based on git tags

set -e

# Color codes for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get latest tag
LATEST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "")

if [ -z "$LATEST_TAG" ]; then
    # No tags exist, check package.json or use default
    if [ -f "package.json" ]; then
        VERSION=$(node -p "require('./package.json').version" 2>/dev/null || echo "0.1.0")
        echo "${VERSION}-dev"
    else
        echo "0.1.0-dev"
    fi
    exit 0
fi

# Get commit hash
COMMIT_HASH=$(git rev-parse --short HEAD)

# Check if working directory is dirty
DIRTY=""
if [ -n "$(git status --porcelain)" ]; then
    DIRTY="-dirty"
fi

# Check if current commit is tagged
CURRENT_TAG=$(git describe --exact-match --tags HEAD 2>/dev/null || echo "")

if [ -n "$CURRENT_TAG" ]; then
    # On a tag, use it directly (remove 'v' prefix if present)
    echo "${CURRENT_TAG#v}${DIRTY}"
else
    # Not on a tag, append commit info
    COMMITS_SINCE=$(git rev-list ${LATEST_TAG}..HEAD --count)
    BRANCH=$(git rev-parse --abbrev-ref HEAD)

    # Remove 'v' prefix from tag if present
    TAG_VERSION="${LATEST_TAG#v}"

    if [ "$COMMITS_SINCE" -eq 0 ]; then
        # On the tagged commit but tag not checked out
        echo "${TAG_VERSION}${DIRTY}"
    else
        # Ahead of tag, show development version
        echo "${TAG_VERSION}-dev.${COMMITS_SINCE}+${COMMIT_HASH}${DIRTY}"
    fi
fi
