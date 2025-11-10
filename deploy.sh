#!/bin/bash
#
# Deployment Script for Secure Login Collector
#
# This script:
# 1. Suggests next version number
# 2. Updates version in plugin file and readme
# 3. Creates git commit with auto-generated message
# 4. Tags the commit with version number
#
# Usage: ./deploy.sh

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# File paths (we're in plugin directory now!)
PLUGIN_FILE="secure-login-collector.php"
README_FILE="readme.txt"

# Check if files exist
if [ ! -f "$PLUGIN_FILE" ]; then
    echo -e "${RED}Error: Plugin file not found${NC}"
    echo "Make sure you're running this script from the plugin directory."
    exit 1
fi

if [ ! -d ".git" ]; then
    echo -e "${RED}Error: Not a git repository${NC}"
    exit 1
fi

echo -e "${BLUE}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  Secure Login Collector - Deployment Script    ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════╝${NC}"
echo ""

# Get current version from plugin file
CURRENT_VERSION=$(grep "^define( 'SECULOCO_VERSION'" "$PLUGIN_FILE" | sed -E "s/.*'([0-9.]+)'.*/\1/")

if [ -z "$CURRENT_VERSION" ]; then
    echo -e "${RED}Error: Could not detect current version${NC}"
    exit 1
fi

echo -e "${GREEN}Current version: ${CURRENT_VERSION}${NC}"
echo ""

# Calculate next minor version
IFS='.' read -r -a VERSION_PARTS <<< "$CURRENT_VERSION"
MAJOR="${VERSION_PARTS[0]}"
MINOR="${VERSION_PARTS[1]}"
PATCH="${VERSION_PARTS[2]}"

# Increment patch version
NEXT_PATCH=$((PATCH + 1))
SUGGESTED_VERSION="${MAJOR}.${MINOR}.${NEXT_PATCH}"

# Ask for new version
echo -e "${YELLOW}Suggested next version: ${SUGGESTED_VERSION}${NC}"
echo ""
read -p "Press Enter to use ${SUGGESTED_VERSION}, or type a different version: " NEW_VERSION

# Use suggested version if user just pressed Enter
if [ -z "$NEW_VERSION" ]; then
    NEW_VERSION="$SUGGESTED_VERSION"
fi

# Validate version format
if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo -e "${RED}Error: Invalid version format. Use X.Y.Z (e.g., 1.2.11)${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}New version: ${NEW_VERSION}${NC}"
echo ""

# Confirm before proceeding
read -p "Continue with deployment? (y/N): " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}Deployment cancelled.${NC}"
    exit 0
fi

echo ""
echo -e "${BLUE}Step 1: Updating version numbers...${NC}"

# Update plugin file header (Version: X.Y.Z)
sed -i '' "s/^\( \* Version: \).*/\1${NEW_VERSION}/" "$PLUGIN_FILE"
echo -e "${GREEN}✓ Updated plugin header${NC}"

# Update PHP constant (define( 'SECULOCO_VERSION', 'X.Y.Z' );)
sed -i '' "s/^define( 'SECULOCO_VERSION', '[0-9.]*' );/define( 'SECULOCO_VERSION', '${NEW_VERSION}' );/" "$PLUGIN_FILE"
echo -e "${GREEN}✓ Updated PHP constant${NC}"

# Update readme.txt (Stable tag: X.Y.Z)
sed -i '' "s/^Stable tag: .*/Stable tag: ${NEW_VERSION}/" "$README_FILE"
echo -e "${GREEN}✓ Updated readme.txt${NC}"

echo ""
echo -e "${BLUE}Step 2: Generating commit message...${NC}"

# Generate commit message
COMMIT_MSG="Release version ${NEW_VERSION}"

echo ""
echo -e "${YELLOW}Commit message:${NC}"
echo "─────────────────────────────────────────────────"
echo "$COMMIT_MSG"
echo "─────────────────────────────────────────────────"
echo ""

# Stage changes
echo -e "${BLUE}Step 3: Staging changes...${NC}"
git add "$PLUGIN_FILE" "$README_FILE"
echo -e "${GREEN}✓ Changes staged${NC}"

# Create commit
echo ""
echo -e "${BLUE}Step 4: Creating commit...${NC}"
git commit -m "$COMMIT_MSG"
COMMIT_HASH=$(git rev-parse --short HEAD)
echo -e "${GREEN}✓ Commit created: ${COMMIT_HASH}${NC}"

# Create tag
echo ""
echo -e "${BLUE}Step 5: Creating tag...${NC}"
TAG_NAME="v${NEW_VERSION}"
git tag -a "$TAG_NAME" -m "Version ${NEW_VERSION}"
echo -e "${GREEN}✓ Tag created: ${TAG_NAME}${NC}"

# Summary
echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Deployment Complete! ✓              ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}Version:${NC}     ${CURRENT_VERSION} → ${NEW_VERSION}"
echo -e "${GREEN}Commit:${NC}      ${COMMIT_HASH}"
echo -e "${GREEN}Tag:${NC}         ${TAG_NAME}"
echo ""

# Ask to push
read -p "Push to remote now? (y/N): " PUSH_CONFIRM
if [[ "$PUSH_CONFIRM" =~ ^[Yy]$ ]]; then
    echo ""
    echo -e "${BLUE}Pushing to remote...${NC}"
    git push && git push --tags
    echo -e "${GREEN}✓ Pushed to remote${NC}"
    echo ""
    echo -e "${YELLOW}GitHub Actions will now auto-deploy to Freemius${NC}"
else
    echo ""
    echo -e "${YELLOW}Skipped push. When ready:${NC}"
    echo "  ${BLUE}git push && git push --tags${NC}"
fi

echo ""
echo -e "${GREEN}Happy deploying! 🚀${NC}"
