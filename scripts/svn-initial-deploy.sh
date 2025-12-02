#!/bin/bash
# Initial SVN Deployment Script for WordPress.org
# Use this script for your FIRST deployment only

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}WordPress.org Initial SVN Deployment${NC}"
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
ASSETS_DIR="$PLUGIN_DIR/.wordpress-org"

# Prompt for WordPress.org username
echo -e "${YELLOW}Enter your WordPress.org username:${NC}"
read -r WP_USERNAME

if [ -z "$WP_USERNAME" ]; then
    echo -e "${RED}Error: Username is required${NC}"
    exit 1
fi

SVN_URL="https://plugins.svn.wordpress.org/$PLUGIN_SLUG"

# Check if free version is built
if [ ! -d "$BUILD_DIR" ]; then
    echo -e "${RED}Error: Free version not built. Run './scripts/build-free-version.sh' from plugin directory first${NC}"
    exit 1
fi

# Get plugin version
VERSION=$(grep "Version:" "$BUILD_DIR/$PLUGIN_SLUG.php" | head -1 | awk -F: '{print $2}' | tr -d ' ')
echo -e "${GREEN}Plugin Version: $VERSION${NC}"
echo ""

# Step 1: Checkout SVN Repository (if not already done)
if [ ! -d "$SVN_DIR" ]; then
    echo -e "${BLUE}Step 1: Checking out SVN repository...${NC}"
    mkdir -p "$(dirname "$SVN_DIR")"
    svn co "$SVN_URL" "$SVN_DIR" --username="$WP_USERNAME"
    echo -e "${GREEN}✓ SVN repository checked out${NC}"
    echo ""
else
    echo -e "${YELLOW}SVN directory already exists. Updating...${NC}"
    cd "$SVN_DIR"
    svn up --username="$WP_USERNAME"
    cd - > /dev/null
    echo -e "${GREEN}✓ SVN repository updated${NC}"
    echo ""
fi

# Step 2: Add Assets (if they exist and not already added)
if [ -d "$ASSETS_DIR" ]; then
    echo -e "${BLUE}Step 2: Adding plugin assets...${NC}"

    # Create assets directory if it doesn't exist
    mkdir -p "$SVN_DIR/assets"

    # Copy assets
    cp -r "$ASSETS_DIR"/* "$SVN_DIR/assets/" 2>/dev/null || echo "No assets to copy"

    # Add to SVN if not already added
    cd "$SVN_DIR/assets"
    svn add * --force 2>/dev/null || true

    # Check if there are changes to commit
    if svn status | grep -q "^[AM]"; then
        echo -e "${YELLOW}Committing assets...${NC}"
        svn ci -m "Add/update plugin assets" --username="$WP_USERNAME"
        echo -e "${GREEN}✓ Assets committed${NC}"
    else
        echo -e "${GREEN}✓ Assets already up to date${NC}"
    fi
    cd - > /dev/null
    echo ""
else
    echo -e "${YELLOW}Step 2: No assets directory found. Skipping...${NC}"
    echo -e "${YELLOW}Note: You should add assets later for better plugin listing${NC}"
    echo ""
fi

# Step 3: Add Plugin Files to Trunk
echo -e "${BLUE}Step 3: Adding plugin files to trunk...${NC}"

# Remove old trunk contents (except .svn)
find "$SVN_DIR/trunk" -mindepth 1 -maxdepth 1 -not -name '.svn' -exec rm -rf {} +

# Copy new plugin files
cp -r "$BUILD_DIR"/* "$SVN_DIR/trunk/"

# Add new files to SVN
cd "$SVN_DIR/trunk"
svn add * --force 2>/dev/null || true

# Also handle any deletions
svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn delete {} 2>/dev/null || true

echo -e "${GREEN}✓ Plugin files prepared for commit${NC}"
echo ""

# Show what will be committed
echo -e "${YELLOW}Files to be committed:${NC}"
svn status
echo ""

# Confirm before committing
echo -e "${YELLOW}Ready to commit to trunk. Continue? (y/n)${NC}"
read -r confirm
if [ "$confirm" != "y" ]; then
    echo -e "${RED}Deployment cancelled${NC}"
    exit 1
fi

# Commit to trunk
echo -e "${YELLOW}Committing to trunk...${NC}"
svn ci -m "Initial commit of $PLUGIN_SLUG v$VERSION" --username="$WP_USERNAME"
echo -e "${GREEN}✓ Trunk committed${NC}"
cd - > /dev/null
echo ""

# Step 4: Create Tag
echo -e "${BLUE}Step 4: Creating release tag $VERSION...${NC}"
cd "$SVN_DIR"

# Create tag from trunk
svn cp trunk "tags/$VERSION"

# Commit the tag
svn ci -m "Tagging version $VERSION" --username="$WP_USERNAME"
echo -e "${GREEN}✓ Tag $VERSION created${NC}"
cd - > /dev/null
echo ""

# Final Summary
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}✓ Deployment Completed Successfully!${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "Plugin: ${GREEN}$PLUGIN_SLUG${NC}"
echo -e "Version: ${GREEN}$VERSION${NC}"
echo -e "SVN URL: ${GREEN}$SVN_URL${NC}"
echo ""
echo -e "${YELLOW}Your plugin should be live in 15-30 minutes at:${NC}"
echo -e "${GREEN}https://wordpress.org/plugins/$PLUGIN_SLUG/${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Wait 15-30 minutes for WordPress.org to propagate changes"
echo "2. Visit your plugin page and verify it looks correct"
echo "3. Test installing from WordPress.org"
echo "4. For future updates, use './scripts/svn-update.sh' from plugin directory"
echo ""
