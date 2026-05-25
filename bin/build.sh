#!/bin/bash
set -e

PLUGIN_SLUG="performance-adapter-wp"
MAIN_FILE="properf-wordpress-adapter.php"

# Extract version from plugin header
VERSION=$(grep -m1 "Version:" "$MAIN_FILE" | awk '{print $3}')

if [ -z "$VERSION" ]; then
  echo "Error: Could not read version from $MAIN_FILE"
  exit 1
fi

BUILD_DIR="build/${PLUGIN_SLUG}"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous build
rm -rf build
mkdir -p "$BUILD_DIR"

# Install production dependencies
composer install --no-dev --optimize-autoloader --quiet

# Copy plugin files
cp "$MAIN_FILE" "$BUILD_DIR/"
cp composer.json composer.lock "$BUILD_DIR/"
cp -r includes vendor "$BUILD_DIR/"

# Create ZIP
cd build
zip -rq "$ZIP_NAME" "$PLUGIN_SLUG"
cd ..

echo "Done: build/${ZIP_NAME}"
