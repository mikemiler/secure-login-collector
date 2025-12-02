#!/bin/bash
# SVN Update Script for WordPress.org
# Use this script for UPDATES after your initial deployment

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}WordPress.org SVN Update${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Configuration
PLUGIN_SLUG="secure-login-collector"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
# Dev root is 6 levels up from scripts folder
DEV_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"
SVN_DIR="$DEV_ROOT/svn"
BUILD_DIR="$DEV_ROOT/build/wordpress-org/$PLUGIN_SLUG"

# Prompt for WordPress.org username
echo -e "${YELLOW}Enter your WordPress.org username:${NC}"
read -r WP_USERNAME

if [ -z "$WP_USERNAME" ]; then
    echo -e "${RED}Error: Username is required${NC}"
    exit 1
fi

# Check if SVN directory exists
if [ ! -d "$SVN_DIR" ]; then
    echo -e "${RED}Error: SVN directory not found. Run initial deploy first.${NC}"
    exit 1
fi

# Check if free version is built
if [ ! -d "$BUILD_DIR" ]; then
    echo -e "${RED}Error: Free version not built. Run './scripts/build-free-version.sh' from plugin directory first${NC}"
    exit 1
fi

# Get plugin version
VERSION=$(grep "Version:" "$BUILD_DIR/$PLUGIN_SLUG.php" | head -1 | awk -F: '{print $2}' | tr -d ' ')
echo -e "${GREEN}Updating to Version: $VERSION${NC}"
echo ""

# Step 1: Update SVN
echo -e "${BLUE}Step 1: Updating SVN repository...${NC}"
cd "$SVN_DIR"
svn up --username="$WP_USERNAME"
echo -e "${GREEN}✓ SVN updated${NC}"
echo ""

# Step 2: Check if tag already exists
if [ -d "tags/$VERSION" ]; then
    echo -e "${RED}Error: Tag $VERSION already exists!${NC}"
    echo -e "${YELLOW}If you want to update this version:${NC}"
    echo "1. Increment version number in plugin file"
    echo "2. Rebuild free version"
    echo "3. Run this script again"
    exit 1
fi

# Step 3: Update Trunk
echo -e "${BLUE}Step 2: Updating trunk...${NC}"

# Remove old trunk contents (except .svn)
find "$SVN_DIR/trunk" -mindepth 1 -maxdepth 1 -not -name '.svn' -exec rm -rf {} +

# Copy new plugin files
cp -r "$BUILD_DIR"/* "$SVN_DIR/trunk/"

# Add new files and remove deleted files
cd "$SVN_DIR/trunk"
svn add * --force 2>/dev/null || true
svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn delete {} 2>/dev/null || true

echo -e "${GREEN}✓ Trunk updated locally${NC}"
echo ""

# Show changes
echo -e "${YELLOW}Changes to be committed:${NC}"
svn status
echo ""

# Confirm before committing
echo -e "${YELLOW}Ready to commit changes. Continue? (y/n)${NC}"
read -r confirm
if [ "$confirm" != "y" ]; then
    echo -e "${RED}Update cancelled${NC}"
    exit 1
fi

# Commit trunk
echo -e "${YELLOW}Committing trunk...${NC}"
svn ci -m "Update to version $VERSION" --username="$WP_USERNAME"
echo -e "${GREEN}✓ Trunk committed${NC}"
cd - > /dev/null
echo ""

# Step 4: Create New Tag
echo -e "${BLUE}Step 3: Creating tag $VERSION...${NC}"
cd "$SVN_DIR"
svn cp trunk "tags/$VERSION"
svn ci -m "Tagging version $VERSION" --username="$WP_USERNAME"
echo -e "${GREEN}✓ Tag $VERSION created${NC}"
cd - > /dev/null
echo ""

# Final Summary
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}✓ Update Completed Successfully!${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "Plugin: ${GREEN}$PLUGIN_SLUG${NC}"
echo -e "Version: ${GREEN}$VERSION${NC}"
echo ""
echo -e "${YELLOW}Update should be live in 15-30 minutes at:${NC}"
echo -e "${GREEN}https://wordpress.org/plugins/$PLUGIN_SLUG/${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Wait 15-30 minutes for propagation"
echo "2. Verify update appears on WordPress.org"
echo "3. Test auto-update on a test site"
echo ""
