#!/bin/bash
# Deploy Freemius Free Version to WordPress.org SVN
# This script takes a ZIP file (downloaded from Freemius) and deploys it to WordPress.org

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Deploy Freemius Free Version to SVN${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Get script directory and project roots
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
# Dev root is 6 levels up from scripts folder (scripts->plugin->plugins->wp-content->public->app->dev-root)
DEV_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"

# Configuration
PLUGIN_SLUG="secure-login-collector"
SVN_DIR="$DEV_ROOT/svn"
DOWNLOADS_DIR="$DEV_ROOT/freemius-downloads"
TEMP_DIR="$DEV_ROOT/temp-deploy"

# Check if ZIP file path is provided
if [ -z "$1" ]; then
    echo -e "${YELLOW}No ZIP file specified. Looking in freemius-downloads folder...${NC}"
    echo ""

    # Check if downloads directory exists and has ZIPs
    if [ -d "$DOWNLOADS_DIR" ]; then
        ZIP_FILES=($(find "$DOWNLOADS_DIR" -name "*.zip" -type f | sort -r))

        if [ ${#ZIP_FILES[@]} -eq 0 ]; then
            echo -e "${RED}No ZIP files found in: $DOWNLOADS_DIR${NC}"
            echo ""
            echo -e "${YELLOW}Please:${NC}"
            echo "1. Download free version from Freemius Dashboard"
            echo "2. Save it to: $DOWNLOADS_DIR/"
            echo "3. Run this script again"
            echo ""
            echo -e "${YELLOW}Or specify ZIP path directly:${NC}"
            echo "  $0 <path-to-zip>"
            exit 1
        fi

        echo -e "${GREEN}Found ZIP files:${NC}"
        for i in "${!ZIP_FILES[@]}"; do
            BASENAME=$(basename "${ZIP_FILES[$i]}")
            echo "  $((i+1)). $BASENAME"
        done
        echo ""

        if [ ${#ZIP_FILES[@]} -eq 1 ]; then
            ZIP_FILE="${ZIP_FILES[0]}"
            echo -e "${GREEN}Using: $(basename "$ZIP_FILE")${NC}"
        else
            echo -e "${YELLOW}Select ZIP file number (1-${#ZIP_FILES[@]}):${NC}"
            read -r selection

            if [[ ! "$selection" =~ ^[0-9]+$ ]] || [ "$selection" -lt 1 ] || [ "$selection" -gt ${#ZIP_FILES[@]} ]; then
                echo -e "${RED}Invalid selection${NC}"
                exit 1
            fi

            ZIP_FILE="${ZIP_FILES[$((selection-1))]}"
            echo -e "${GREEN}Selected: $(basename "$ZIP_FILE")${NC}"
        fi
    else
        echo -e "${RED}Downloads directory not found: $DOWNLOADS_DIR${NC}"
        echo ""
        echo -e "${YELLOW}Creating directory...${NC}"
        mkdir -p "$DOWNLOADS_DIR"
        echo -e "${GREEN}✓ Created: $DOWNLOADS_DIR${NC}"
        echo ""
        echo -e "${YELLOW}Please:${NC}"
        echo "1. Download free version from Freemius Dashboard"
        echo "2. Save it to: $DOWNLOADS_DIR/"
        echo "3. Run this script again"
        echo ""
        echo -e "${YELLOW}Or specify ZIP path directly:${NC}"
        echo "  $0 <path-to-zip>"
        exit 1
    fi
else
    ZIP_FILE="$1"

    # Check if ZIP file exists
    if [ ! -f "$ZIP_FILE" ]; then
        echo -e "${RED}Error: ZIP file not found: $ZIP_FILE${NC}"
        exit 1
    fi
fi

echo -e "${GREEN}ZIP File: $ZIP_FILE${NC}"
echo ""

# Prompt for WordPress.org username
echo -e "${YELLOW}Enter your WordPress.org username:${NC}"
read -r WP_USERNAME

if [ -z "$WP_USERNAME" ]; then
    echo -e "${RED}Error: Username is required${NC}"
    exit 1
fi

# Check if SVN directory exists
if [ ! -d "$SVN_DIR" ]; then
    echo -e "${YELLOW}SVN directory not found at: $SVN_DIR${NC}"
    echo ""
    echo -e "${YELLOW}Would you like to checkout WordPress.org SVN repository now? (y/n)${NC}"
    read -r checkout_confirm
    if [ "$checkout_confirm" = "y" ]; then
        echo -e "${YELLOW}Checking out SVN repository...${NC}"
        svn co "https://plugins.svn.wordpress.org/$PLUGIN_SLUG" "$SVN_DIR" --username="$WP_USERNAME"
        echo -e "${GREEN}✓ SVN repository checked out to: $SVN_DIR${NC}"
    else
        echo -e "${RED}SVN directory required. Exiting.${NC}"
        exit 1
    fi
fi

# Clean temp directory
rm -rf "$TEMP_DIR"
mkdir -p "$TEMP_DIR"

# Extract ZIP file
echo -e "${BLUE}Step 1: Extracting ZIP file...${NC}"
unzip -q "$ZIP_FILE" -d "$TEMP_DIR"

# The ZIP might contain a directory or files directly
# Find the actual plugin directory
if [ -d "$TEMP_DIR/$PLUGIN_SLUG" ]; then
    PLUGIN_DIR="$TEMP_DIR/$PLUGIN_SLUG"
else
    # Check if files are directly in temp dir
    if [ -f "$TEMP_DIR/$PLUGIN_SLUG.php" ]; then
        PLUGIN_DIR="$TEMP_DIR"
    else
        # Files might be in a subdirectory
        SUBDIR=$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
        if [ -n "$SUBDIR" ] && [ -f "$SUBDIR/$PLUGIN_SLUG.php" ]; then
            PLUGIN_DIR="$SUBDIR"
        else
            echo -e "${RED}Error: Cannot find plugin files in ZIP${NC}"
            rm -rf "$TEMP_DIR"
            exit 1
        fi
    fi
fi

echo -e "${GREEN}✓ ZIP extracted${NC}"
echo ""

# Validate required files
echo -e "${BLUE}Step 2: Validating plugin files...${NC}"

if [ ! -f "$PLUGIN_DIR/$PLUGIN_SLUG.php" ]; then
    echo -e "${RED}Error: Main plugin file not found${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

if [ ! -f "$PLUGIN_DIR/readme.txt" ]; then
    echo -e "${RED}Error: readme.txt not found${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

# Check for premium files (should NOT exist in free version)
PREMIUM_FILES=$(find "$PLUGIN_DIR" -name "*__premium_only*" 2>/dev/null || true)
if [ -n "$PREMIUM_FILES" ]; then
    echo -e "${RED}Error: Premium files found in free version:${NC}"
    echo "$PREMIUM_FILES"
    echo ""
    echo -e "${RED}This ZIP contains premium code!${NC}"
    echo -e "${YELLOW}Make sure you downloaded the FREE version from Freemius.${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

echo -e "${GREEN}✓ No premium files found (correct for free version)${NC}"

# Get plugin version
VERSION=$(grep "Version:" "$PLUGIN_DIR/$PLUGIN_SLUG.php" | head -1 | awk -F: '{print $2}' | tr -d ' ')
echo -e "${GREEN}✓ Plugin Version: $VERSION${NC}"
echo ""

# Update SVN repository
echo -e "${BLUE}Step 3: Updating SVN repository...${NC}"
cd "$SVN_DIR"
svn up --username="$WP_USERNAME"
echo -e "${GREEN}✓ SVN updated${NC}"
cd - > /dev/null
echo ""

# Check if tag already exists
if [ -d "$SVN_DIR/tags/$VERSION" ]; then
    echo -e "${RED}Warning: Tag $VERSION already exists in SVN!${NC}"
    echo -e "${YELLOW}Options:${NC}"
    echo "  1. Increment version number in plugin and re-export from Freemius"
    echo "  2. Skip this deployment"
    echo ""
    echo -e "${YELLOW}Continue anyway? (y/n)${NC}"
    read -r continue_confirm
    if [ "$continue_confirm" != "y" ]; then
        echo -e "${YELLOW}Deployment cancelled${NC}"
        rm -rf "$TEMP_DIR"
        exit 0
    fi
fi

# Show what will be deployed
echo -e "${BLUE}Step 4: Preparing deployment...${NC}"
echo ""
echo -e "${YELLOW}Files to be deployed:${NC}"
ls -la "$PLUGIN_DIR"
echo ""

# Confirm before proceeding
echo -e "${YELLOW}Ready to deploy to WordPress.org?${NC}"
echo -e "Version: ${GREEN}$VERSION${NC}"
echo -e "Plugin: ${GREEN}$PLUGIN_SLUG${NC}"
echo ""
echo -e "${YELLOW}This will:${NC}"
echo "  1. Replace trunk with new files"
echo "  2. Create tag $VERSION"
echo "  3. Commit to WordPress.org SVN"
echo ""
echo -e "${YELLOW}Continue? (y/n)${NC}"
read -r deploy_confirm

if [ "$deploy_confirm" != "y" ]; then
    echo -e "${YELLOW}Deployment cancelled${NC}"
    rm -rf "$TEMP_DIR"
    exit 0
fi

# Deploy to trunk
echo ""
echo -e "${BLUE}Step 5: Deploying to trunk...${NC}"

# Remove old trunk contents (except .svn)
find "$SVN_DIR/trunk" -mindepth 1 -maxdepth 1 -not -name '.svn' -exec rm -rf {} +

# Copy new files
cp -r "$PLUGIN_DIR"/* "$SVN_DIR/trunk/"

# Check and update Stable tag in readme.txt
if [ -f "$SVN_DIR/trunk/readme.txt" ]; then
    STABLE_TAG=$(grep "^Stable tag:" "$SVN_DIR/trunk/readme.txt" | awk -F: '{print $2}' | tr -d ' ' || echo "")
    if [ "$STABLE_TAG" != "$VERSION" ]; then
        echo -e "${YELLOW}Updating Stable tag in readme.txt from '$STABLE_TAG' to '$VERSION'${NC}"
        sed -i.bak "s/^Stable tag:.*/Stable tag: $VERSION/" "$SVN_DIR/trunk/readme.txt"
        rm -f "$SVN_DIR/trunk/readme.txt.bak"
        echo -e "${GREEN}✓ Stable tag updated${NC}"
    else
        echo -e "${GREEN}✓ Stable tag already correct: $VERSION${NC}"
    fi
fi

# Add new files and remove deleted files
cd "$SVN_DIR/trunk"
svn add * --force 2>/dev/null || true
svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn delete {} 2>/dev/null || true

echo -e "${GREEN}✓ Trunk prepared${NC}"
echo ""

# Show changes summary
echo -e "${YELLOW}Changes to commit:${NC}"
svn status | head -20
echo ""

# Show detailed diff
echo -e "${YELLOW}Would you like to see the full diff? (y/n)${NC}"
read -r show_diff
if [ "$show_diff" = "y" ]; then
    echo ""
    echo -e "${BLUE}===== CHANGES DIFF =====${NC}"
    svn diff | head -200
    echo ""
    if [ "$(svn diff | wc -l)" -gt 200 ]; then
        echo -e "${YELLOW}(Diff truncated, more than 200 lines)${NC}"
        echo ""
    fi
fi

# Final confirmation before commit
echo -e "${YELLOW}Ready to commit to trunk? (y/n)${NC}"
read -r commit_confirm
if [ "$commit_confirm" != "y" ]; then
    echo -e "${YELLOW}Deployment cancelled${NC}"
    cd - > /dev/null
    rm -rf "$TEMP_DIR"
    exit 0
fi

# Commit trunk
echo -e "${YELLOW}Committing to trunk...${NC}"
svn ci -m "Update to version $VERSION" --username="$WP_USERNAME"
echo -e "${GREEN}✓ Trunk committed${NC}"
cd - > /dev/null
echo ""

# Create tag
echo -e "${BLUE}Step 6: Creating tag $VERSION...${NC}"
cd "$SVN_DIR"

# Create tag from trunk
svn cp trunk "tags/$VERSION"

# Commit tag
svn ci -m "Tagging version $VERSION" --username="$WP_USERNAME"
echo -e "${GREEN}✓ Tag $VERSION created${NC}"
cd - > /dev/null
echo ""

# Cleanup
rm -rf "$TEMP_DIR"

# Final Summary
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}✓ Deployment Completed Successfully!${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "Plugin: ${GREEN}$PLUGIN_SLUG${NC}"
echo -e "Version: ${GREEN}$VERSION${NC}"
echo -e "Source: ${GREEN}Freemius Free Version${NC}"
echo ""
echo -e "${YELLOW}Your plugin will be live in 15-30 minutes at:${NC}"
echo -e "${GREEN}https://wordpress.org/plugins/$PLUGIN_SLUG/${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Wait 15-30 minutes for WordPress.org propagation"
echo "2. Visit your plugin page and verify it looks correct"
echo "3. Test installation from WordPress.org"
echo ""
