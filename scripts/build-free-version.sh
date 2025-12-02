#!/bin/bash
# Build Free Version for WordPress.org
# Excludes all __premium_only files and development files

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Building Free Version for WordPress.org${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Get script directory and project roots
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
# Dev root is 6 levels up from scripts folder
DEV_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"

# Configuration
BUILD_DIR="$DEV_ROOT/build/wordpress-org"
PLUGIN_SLUG="secure-login-collector"

# Check if plugin directory exists
if [ ! -d "$PLUGIN_DIR" ]; then
    echo -e "${RED}Error: Plugin directory not found: $PLUGIN_DIR${NC}"
    exit 1
fi

# Clean previous build
echo -e "${YELLOW}Cleaning previous build...${NC}"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Create plugin directory in build
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"

echo -e "${GREEN}Copying plugin files...${NC}"

# Copy files using rsync, excluding premium and development files
rsync -av \
    --exclude='*__premium_only*' \
    --exclude='.git*' \
    --exclude='.github/' \
    --exclude='node_modules/' \
    --exclude='tests/' \
    --exclude='docs/' \
    --exclude='scripts/' \
    --exclude='build/' \
    --exclude='.DS_Store' \
    --exclude='*.log' \
    --exclude='package*.json' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='phpunit.xml' \
    --exclude='.editorconfig' \
    --exclude='.phpcs.xml' \
    --exclude='README.md' \
    --exclude='CLAUDE.md' \
    "$PLUGIN_DIR/" "$BUILD_DIR/$PLUGIN_SLUG/"

echo ""
echo -e "${GREEN}Source: $PLUGIN_DIR${NC}"
echo -e "${GREEN}Target: $BUILD_DIR/$PLUGIN_SLUG/${NC}"

echo ""
echo -e "${YELLOW}Excluded files/directories:${NC}"
echo "  - *__premium_only* (Premium features)"
echo "  - .git*, .github/ (Version control)"
echo "  - node_modules/ (Dependencies)"
echo "  - tests/, docs/ (Development files)"
echo "  - scripts/, build/ (Build files)"
echo ""

# Validate required files exist
echo -e "${YELLOW}Validating required files...${NC}"

required_files=(
    "$BUILD_DIR/$PLUGIN_SLUG/$PLUGIN_SLUG.php"
    "$BUILD_DIR/$PLUGIN_SLUG/readme.txt"
)

missing_files=0
for file in "${required_files[@]}"; do
    if [ ! -f "$file" ]; then
        echo -e "${RED}✗ Missing: $(basename $file)${NC}"
        missing_files=$((missing_files + 1))
    else
        echo -e "${GREEN}✓ Found: $(basename $file)${NC}"
    fi
done

if [ $missing_files -gt 0 ]; then
    echo -e "${RED}Error: Missing required files. Build failed.${NC}"
    exit 1
fi

# Check for any remaining __premium_only files (should be none)
echo ""
echo -e "${YELLOW}Checking for premium files...${NC}"
premium_files=$(find "$BUILD_DIR/$PLUGIN_SLUG" -name "*__premium_only*" 2>/dev/null || true)
if [ -n "$premium_files" ]; then
    echo -e "${RED}Warning: Premium files found in free build:${NC}"
    echo "$premium_files"
    echo -e "${RED}These should not be in the WordPress.org version!${NC}"
    exit 1
else
    echo -e "${GREEN}✓ No premium files found (correct for free version)${NC}"
fi

# Get plugin version from main file
echo ""
echo -e "${YELLOW}Reading plugin version...${NC}"
VERSION=$(grep "Version:" "$BUILD_DIR/$PLUGIN_SLUG/$PLUGIN_SLUG.php" | head -1 | awk -F: '{print $2}' | tr -d ' ')
echo -e "${GREEN}Plugin Version: $VERSION${NC}"

# Create ZIP file
echo ""
echo -e "${YELLOW}Creating ZIP file...${NC}"
cd "$BUILD_DIR"
zip -r "$PLUGIN_SLUG-$VERSION.zip" "$PLUGIN_SLUG/" -q
cd - > /dev/null

# Display build summary
echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Build Summary${NC}"
echo -e "${BLUE}========================================${NC}"
echo -e "Plugin: ${GREEN}$PLUGIN_SLUG${NC}"
echo -e "Version: ${GREEN}$VERSION${NC}"
echo -e "Build Directory: ${GREEN}$BUILD_DIR/$PLUGIN_SLUG/${NC}"
echo -e "ZIP File: ${GREEN}$BUILD_DIR/$PLUGIN_SLUG-$VERSION.zip${NC}"
echo ""

# File count
file_count=$(find "$BUILD_DIR/$PLUGIN_SLUG" -type f | wc -l | tr -d ' ')
echo -e "Total Files: ${GREEN}$file_count${NC}"

# Size
size=$(du -sh "$BUILD_DIR/$PLUGIN_SLUG" | awk '{print $1}')
echo -e "Build Size: ${GREEN}$size${NC}"

zip_size=$(du -sh "$BUILD_DIR/$PLUGIN_SLUG-$VERSION.zip" | awk '{print $1}')
echo -e "ZIP Size: ${GREEN}$zip_size${NC}"

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}✓ Build completed successfully!${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Test the build: Install $BUILD_DIR/$PLUGIN_SLUG-$VERSION.zip on a test site"
echo "2. Verify all features work without premium functionality"
echo "3. If tests pass, proceed with SVN deployment"
echo ""
