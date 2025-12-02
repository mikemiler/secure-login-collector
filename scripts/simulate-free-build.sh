#!/usr/bin/env bash

set -euo pipefail

# Determine paths relative to repository root.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
# Dev root is 6 levels up from scripts folder
DEV_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"

PLUGIN_SLUG="secure-login-collector"
SOURCE_DIR="${SOURCE_DIR:-${PLUGIN_DIR}}"
TARGET_PLUGINS_DIR="${TARGET_PLUGINS_DIR:-${DEV_ROOT}/../seculoco-test/app/public/wp-content/plugins}"
TARGET_DIR="${TARGET_PLUGINS_DIR}/${PLUGIN_SLUG}"
DEPLOY_EXCLUDES=(
  "--exclude=.git*"
  "--exclude=node_modules"
  "--exclude=tests"
  "--exclude=.claude"
  "--exclude=.claude-flow"
  "--exclude=.swarm"
  "--exclude=*.log"
  "--exclude=.DS_Store"
  "--exclude=phpunit.xml"
  "--exclude=composer.json"
  "--exclude=composer.lock"
  "--exclude=package.json"
  "--exclude=package-lock.json"
  "--exclude=.github"
  "--exclude=deploy"
  "--exclude=deploy.sh"
  "--exclude=docs"
)

if [ ! -d "${SOURCE_DIR}" ]; then
  echo "❌ Source plugin directory not found: ${SOURCE_DIR}" >&2
  exit 1
fi

mkdir -p "${TARGET_PLUGINS_DIR}"

TEMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf "${TEMP_DIR}"
}
trap cleanup EXIT

echo "🚧 Building simulated free version..."
echo "   Source: ${SOURCE_DIR}"
echo "   Target: ${TARGET_DIR}"

echo "📋 Applying deploy excludes..."
rsync -a "${DEPLOY_EXCLUDES[@]}" "${SOURCE_DIR}/" "${TEMP_DIR}/${PLUGIN_SLUG}/"

echo "🔍 Removing premium-only files..."
PREMIUM_COUNT=0
while IFS= read -r -d '' file; do
  rm -f "${file}"
  rel="${file#"${TEMP_DIR}/${PLUGIN_SLUG}/"}"
  echo "   Removed: ${rel}"
  PREMIUM_COUNT=$((PREMIUM_COUNT + 1))
done < <(find "${TEMP_DIR}/${PLUGIN_SLUG}" -type f -name '*__premium_only*' -print0)

if [ "${PREMIUM_COUNT}" -eq 0 ]; then
  echo "   No premium-only files detected."
fi

echo "📦 Syncing cleaned plugin into test site..."
rsync -a --delete "${TEMP_DIR}/${PLUGIN_SLUG}/" "${TARGET_DIR}/"

echo "✅ Free build deployed to ${TARGET_DIR}"
echo "   Tip: ensure the target site's wp-config.php defines SECULOCO_SIMULATE_FREE_VERSION."
