#!/bin/bash
# SVN Tag Repair Script
# Use this to fix partially uploaded tags

set -e  # Exit on error

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}SVN Tag Repair Tool${NC}"
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
    echo -e "${RED}Error: SVN directory not found at $SVN_DIR${NC}"
    exit 1
fi

# Prompt for version to repair
echo -e "${YELLOW}Enter the version tag to repair (e.g., 1.0.0):${NC}"
read -r VERSION

if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Version is required${NC}"
    exit 1
fi

TAG_PATH="$SVN_DIR/tags/$VERSION"

# Check if tag exists
if [ ! -d "$TAG_PATH" ]; then
    echo -e "${RED}Error: Tag $VERSION does not exist at $TAG_PATH${NC}"
    echo -e "${YELLOW}Available tags:${NC}"
    ls -1 "$SVN_DIR/tags/" 2>/dev/null || echo "No tags found"
    exit 1
fi

echo -e "${BLUE}Found tag: $VERSION${NC}"
echo ""

# Step 1: Update SVN to get latest state
echo -e "${BLUE}Step 1: Updating SVN repository...${NC}"
cd "$SVN_DIR"
svn up --username="$WP_USERNAME"
echo -e "${GREEN}✓ SVN updated${NC}"
echo ""

# Step 2: Check tag status
echo -e "${BLUE}Step 2: Checking tag status...${NC}"
cd "$TAG_PATH"
SVN_STATUS=$(svn status)

if [ -z "$SVN_STATUS" ]; then
    echo -e "${GREEN}✓ Tag appears complete (no pending changes)${NC}"
    echo -e "${YELLOW}If you're still having issues, the problem might be on the server.${NC}"
    echo -e "${YELLOW}Would you like to:${NC}"
    echo "1. Delete and recreate the tag (recommended)"
    echo "2. Exit"
    read -r choice

    if [ "$choice" != "1" ]; then
        echo -e "${YELLOW}Exiting...${NC}"
        exit 0
    fi
else
    echo -e "${YELLOW}Tag has pending changes:${NC}"
    echo "$SVN_STATUS"
    echo ""
fi

# Step 3: Offer repair options
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Repair Options${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo "1. Delete partial tag and recreate from trunk (RECOMMENDED)"
echo "2. Try to complete the partial tag"
echo "3. Revert local changes and update from server"
echo "4. Cancel"
echo ""
echo -e "${YELLOW}Select option (1-4):${NC}"
read -r option

case $option in
    1)
        echo -e "${BLUE}Option 1: Delete and recreate tag${NC}"
        echo ""

        # Confirm deletion
        echo -e "${RED}WARNING: This will delete the tag $VERSION from SVN!${NC}"
        echo -e "${YELLOW}Type 'DELETE' to confirm:${NC}"
        read -r confirm

        if [ "$confirm" != "DELETE" ]; then
            echo -e "${RED}Cancelled${NC}"
            exit 1
        fi

        # Revert any local changes in the tag
        echo -e "${YELLOW}Reverting local changes in tag...${NC}"
        cd "$TAG_PATH"
        svn revert -R .
        echo -e "${GREEN}✓ Local changes reverted${NC}"
        echo ""

        cd "$SVN_DIR"

        # Delete the tag
        echo -e "${YELLOW}Deleting tag $VERSION...${NC}"
        svn delete "tags/$VERSION" --force
        svn ci -m "Remove incomplete tag $VERSION" --username="$WP_USERNAME"
        echo -e "${GREEN}✓ Tag deleted${NC}"
        echo ""

        # Recreate from trunk
        echo -e "${YELLOW}Recreating tag from trunk...${NC}"
        svn up --username="$WP_USERNAME"
        svn cp trunk "tags/$VERSION"
        svn ci -m "Tagging version $VERSION" --username="$WP_USERNAME"
        echo -e "${GREEN}✓ Tag recreated successfully${NC}"
        ;;

    2)
        echo -e "${BLUE}Option 2: Complete partial tag${NC}"
        echo ""

        cd "$TAG_PATH"

        # Add any unversioned files
        echo -e "${YELLOW}Adding unversioned files...${NC}"
        svn add * --force 2>/dev/null || true

        # Show what will be committed
        echo -e "${YELLOW}Changes to commit:${NC}"
        svn status
        echo ""

        # Confirm
        echo -e "${YELLOW}Commit these changes? (y/n)${NC}"
        read -r confirm

        if [ "$confirm" != "y" ]; then
            echo -e "${RED}Cancelled${NC}"
            exit 1
        fi

        # Commit
        svn ci -m "Complete tag $VERSION" --username="$WP_USERNAME"
        echo -e "${GREEN}✓ Tag completed${NC}"
        ;;

    3)
        echo -e "${BLUE}Option 3: Revert and update${NC}"
        echo ""

        cd "$TAG_PATH"

        # Revert all local changes
        echo -e "${YELLOW}Reverting local changes...${NC}"
        svn revert -R .

        # Update from server
        echo -e "${YELLOW}Updating from server...${NC}"
        svn up --username="$WP_USERNAME"

        echo -e "${GREEN}✓ Reverted and updated${NC}"
        echo ""
        echo -e "${YELLOW}Status:${NC}"
        svn status
        ;;

    4)
        echo -e "${YELLOW}Cancelled${NC}"
        exit 0
        ;;

    *)
        echo -e "${RED}Invalid option${NC}"
        exit 1
        ;;
esac

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}✓ Repair Completed${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Wait 15-30 minutes for changes to propagate"
echo "2. Verify tag at: https://plugins.svn.wordpress.org/$PLUGIN_SLUG/tags/$VERSION/"
echo "3. Check plugin page: https://wordpress.org/plugins/$PLUGIN_SLUG/"
echo ""
