#!/bin/bash
# Download Free Version from Freemius via API
# Optional helper script to download the free version programmatically

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Download Free Version from Freemius${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Check if version is provided
if [ -z "$1" ]; then
    echo -e "${RED}Error: Version required${NC}"
    echo ""
    echo "Usage: $0 <version>"
    echo ""
    echo "Examples:"
    echo "  $0 1.0.0"
    echo "  $0 1.0.1"
    echo ""
    exit 1
fi

VERSION="$1"

# Check for required environment variables
if [ -z "$FS_USER_ID" ] || [ -z "$FS_PLUGIN_ID" ] || [ -z "$FS_PUBLIC_KEY" ] || [ -z "$FS_SECRET_KEY" ]; then
    echo -e "${YELLOW}Freemius API credentials not found in environment.${NC}"
    echo ""
    echo "You can either:"
    echo ""
    echo "1. Set environment variables:"
    echo "   export FS_USER_ID='your-user-id'"
    echo "   export FS_PLUGIN_ID='your-plugin-id'"
    echo "   export FS_PUBLIC_KEY='your-public-key'"
    echo "   export FS_SECRET_KEY='your-secret-key'"
    echo "   Then run: $0 $VERSION"
    echo ""
    echo "2. Download manually from Freemius Dashboard:"
    echo "   https://dashboard.freemius.com"
    echo "   → Your Plugin → Deployments → Version $VERSION → Download Free Version"
    echo ""
    exit 1
fi

# Get script directory and project roots
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Dev root is 6 levels up from scripts folder
DEV_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"

OUTPUT_DIR="$DEV_ROOT/freemius-downloads"
OUTPUT_FILE="$OUTPUT_DIR/secure-login-collector-$VERSION-free.zip"

# Create output directory
mkdir -p "$OUTPUT_DIR"

echo -e "${YELLOW}Downloading version $VERSION from Freemius...${NC}"
echo ""

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo -e "${RED}Error: PHP is required for Freemius API${NC}"
    echo ""
    echo "Alternative: Download manually from Freemius Dashboard"
    echo "https://dashboard.freemius.com"
    exit 1
fi

# Clone Freemius PHP SDK if not exists
if [ ! -d "freemius-sdk" ]; then
    echo -e "${YELLOW}Downloading Freemius PHP SDK...${NC}"
    git clone --quiet https://github.com/Freemius/php-sdk.git freemius-sdk
    echo -e "${GREEN}✓ SDK downloaded${NC}"
fi

# Create download script
cat > temp-download.php << 'EOF'
<?php
require_once 'freemius-sdk/src/Freemius.php';

$freemius = new \Freemius\SDK(
    $_ENV['FS_USER_ID'],
    $_ENV['FS_PUBLIC_KEY'],
    $_ENV['FS_SECRET_KEY']
);

$version = $argv[1];
$outputFile = $argv[2];

// Get deployment download URL
$url = sprintf(
    '/v1/developers/%s/plugins/%s/tags/%s.zip?is_premium=false',
    $_ENV['FS_USER_ID'],
    $_ENV['FS_PLUGIN_ID'],
    $version
);

try {
    echo "Requesting download URL from Freemius...\n";
    $response = $freemius->api($url, 'GET');

    if (isset($response->download_url)) {
        echo "✓ Download URL received\n";
        echo "Downloading file...\n";

        // Download the file
        $zipContent = file_get_contents($response->download_url);

        if ($zipContent === false) {
            echo "Error: Failed to download file\n";
            exit(1);
        }

        file_put_contents($outputFile, $zipContent);
        echo "✓ File downloaded successfully\n";
        exit(0);
    } else {
        echo "Error: No download URL in response\n";
        if (isset($response->error)) {
            echo "API Error: " . $response->error->message . "\n";
        }
        exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
EOF

# Execute download
php temp-download.php "$VERSION" "$OUTPUT_FILE"

# Cleanup
rm temp-download.php

# Verify download
if [ ! -f "$OUTPUT_FILE" ]; then
    echo -e "${RED}Error: Download failed${NC}"
    exit 1
fi

FILE_SIZE=$(du -sh "$OUTPUT_FILE" | awk '{print $1}')

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}✓ Download Completed Successfully!${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "Version: ${GREEN}$VERSION${NC}"
echo -e "File: ${GREEN}$OUTPUT_FILE${NC}"
echo -e "Size: ${GREEN}$FILE_SIZE${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Test the free version locally"
echo "2. Deploy to WordPress.org: ./scripts/deploy-freemius-to-svn.sh $OUTPUT_FILE"
echo ""
