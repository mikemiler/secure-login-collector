#!/bin/bash
# Simple SVN Push Script
# Use this for manual changes like updating assets, readme, or small fixes

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Push Changes to WordPress.org SVN${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Get script directory and project roots
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
# Dev root is 6 levels up from scripts folder
DEV_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"

# Configuration
PLUGIN_SLUG="secure-login-collector"
SVN_DIR="$DEV_ROOT/svn"

# Check if SVN directory exists
if [ ! -d "$SVN_DIR" ]; then
    echo -e "${RED}Error: SVN directory not found at: $SVN_DIR${NC}"
    echo ""
    echo -e "${YELLOW}Please run the deployment script first to checkout SVN:${NC}"
    echo "  From plugin directory: ./scripts/deploy-freemius-to-svn.sh"
    exit 1
fi

# Prompt for WordPress.org username
echo -e "${YELLOW}Enter your WordPress.org username:${NC}"
read -r WP_USERNAME

if [ -z "$WP_USERNAME" ]; then
    echo -e "${RED}Error: Username is required${NC}"
    exit 1
fi

# Update SVN repository
echo -e "${BLUE}Step 1: Updating SVN repository...${NC}"
cd "$SVN_DIR"
svn up --username="$WP_USERNAME"
echo -e "${GREEN}✓ SVN updated${NC}"
echo ""

# Check for changes
echo -e "${BLUE}Step 2: Checking for changes...${NC}"
STATUS_OUTPUT=$(svn status)

if [ -z "$STATUS_OUTPUT" ]; then
    echo -e "${YELLOW}No changes detected in SVN directory.${NC}"
    echo ""
    echo -e "${YELLOW}If you made changes:${NC}"
    echo "1. Make sure you edited files in: $SVN_DIR/"
    echo "2. New files need: svn add <file>"
    echo "3. Try running: svn status"
    exit 0
fi

echo -e "${GREEN}Changes detected:${NC}"
echo "$STATUS_OUTPUT"
echo ""

# Show which directories have changes
TRUNK_CHANGES=$(echo "$STATUS_OUTPUT" | grep "^[AM?!].*trunk/" || true)
TAGS_CHANGES=$(echo "$STATUS_OUTPUT" | grep "^[AM?!].*tags/" || true)
ASSETS_CHANGES=$(echo "$STATUS_OUTPUT" | grep "^[AM?!].*assets/" || true)

if [ -n "$TRUNK_CHANGES" ]; then
    echo -e "${BLUE}📝 Trunk changes detected${NC}"
fi
if [ -n "$TAGS_CHANGES" ]; then
    echo -e "${BLUE}🏷️  Tag changes detected${NC}"
fi
if [ -n "$ASSETS_CHANGES" ]; then
    echo -e "${BLUE}🎨 Asset changes detected${NC}"
fi
echo ""

# Add any new files
NEW_FILES=$(svn status | grep "^?" | awk '{print $2}')
if [ -n "$NEW_FILES" ]; then
    echo -e "${YELLOW}New files found (not tracked by SVN):${NC}"
    echo "$NEW_FILES"
    echo ""
    echo -e "${YELLOW}Add these files to SVN? (y/n)${NC}"
    read -r add_confirm
    if [ "$add_confirm" = "y" ]; then
        echo "$NEW_FILES" | xargs svn add
        echo -e "${GREEN}✓ New files added${NC}"
        echo ""
    fi
fi

# Show detailed diff
echo -e "${BLUE}Step 3: Review changes${NC}"
echo ""
echo -e "${YELLOW}Would you like to see the diff? (y/n)${NC}"
read -r show_diff

if [ "$show_diff" = "y" ]; then
    echo ""
    echo -e "${BLUE}===== CHANGES DIFF =====${NC}"
    svn diff | head -200
    echo ""

    DIFF_LINES=$(svn diff | wc -l)
    if [ "$DIFF_LINES" -gt 200 ]; then
        echo -e "${YELLOW}(Showing first 200 lines, total: $DIFF_LINES lines)${NC}"
        echo ""
        echo -e "${YELLOW}View full diff? (y/n)${NC}"
        read -r show_full
        if [ "$show_full" = "y" ]; then
            svn diff | less
        fi
        echo ""
    fi
fi

# Prompt for commit message
echo -e "${BLUE}Step 4: Commit message${NC}"
echo ""
echo -e "${YELLOW}Enter commit message:${NC}"
echo -e "${YELLOW}(e.g., 'Update plugin assets', 'Fix typo in readme', etc.)${NC}"
read -r COMMIT_MESSAGE

if [ -z "$COMMIT_MESSAGE" ]; then
    echo -e "${RED}Error: Commit message is required${NC}"
    exit 1
fi

# Final confirmation
echo ""
echo -e "${BLUE}Step 5: Ready to commit${NC}"
echo ""
echo -e "${YELLOW}Commit message:${NC} ${GREEN}$COMMIT_MESSAGE${NC}"
echo ""
echo -e "${YELLOW}Files to commit:${NC}"
svn status | grep "^[AM]" | head -20
echo ""

if [ "$(svn status | grep "^[AM]" | wc -l)" -gt 20 ]; then
    echo -e "${YELLOW}(... and more files)${NC}"
    echo ""
fi

echo -e "${YELLOW}Push these changes to WordPress.org? (y/n)${NC}"
read -r push_confirm

if [ "$push_confirm" != "y" ]; then
    echo -e "${YELLOW}Push cancelled${NC}"
    exit 0
fi

# Commit changes
echo ""
echo -e "${YELLOW}Committing changes...${NC}"
svn ci -m "$COMMIT_MESSAGE" --username="$WP_USERNAME"

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${GREEN}✓ Changes Pushed Successfully!${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
    echo -e "${YELLOW}Changes will be live in 15-30 minutes at:${NC}"
    echo -e "${GREEN}https://wordpress.org/plugins/$PLUGIN_SLUG/${NC}"
    echo ""
else
    echo ""
    echo -e "${RED}Error: Commit failed${NC}"
    exit 1
fi
