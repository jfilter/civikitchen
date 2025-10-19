#!/bin/bash
set -e

# Script to symlink a custom extension into the civikitchen extensions directory
# Usage: ./scripts/link-extension.sh /path/to/your/extension

if [ $# -eq 0 ]; then
    echo "Error: No extension path provided"
    echo ""
    echo "Usage: $0 /path/to/your/extension"
    echo ""
    echo "Example:"
    echo "  $0 ~/projects/com.yourorg.myextension"
    echo ""
    exit 1
fi

EXTENSION_PATH="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
EXTENSIONS_DIR="$PROJECT_ROOT/extensions"

# Validate extension path exists
if [ ! -d "$EXTENSION_PATH" ]; then
    echo "Error: Extension path does not exist: $EXTENSION_PATH"
    exit 1
fi

# Get absolute path
EXTENSION_PATH=$(cd "$EXTENSION_PATH" && pwd)
EXTENSION_NAME=$(basename "$EXTENSION_PATH")

# Check if symlink already exists
SYMLINK_PATH="$EXTENSIONS_DIR/$EXTENSION_NAME"

if [ -L "$SYMLINK_PATH" ]; then
    EXISTING_TARGET=$(readlink "$SYMLINK_PATH")
    if [ "$EXISTING_TARGET" = "$EXTENSION_PATH" ]; then
        echo "✓ Symlink already exists and points to the correct location"
        echo "  $SYMLINK_PATH -> $EXTENSION_PATH"
        exit 0
    else
        echo "Error: Symlink already exists but points to different location:"
        echo "  Current: $SYMLINK_PATH -> $EXISTING_TARGET"
        echo "  Requested: $EXTENSION_PATH"
        echo ""
        echo "Remove the existing symlink first:"
        echo "  rm $SYMLINK_PATH"
        exit 1
    fi
elif [ -e "$SYMLINK_PATH" ]; then
    echo "Error: A file or directory already exists at: $SYMLINK_PATH"
    echo "Please remove it first before creating the symlink"
    exit 1
fi

# Create symlink
echo "Creating symlink..."
echo "  From: $SYMLINK_PATH"
echo "  To:   $EXTENSION_PATH"

ln -s "$EXTENSION_PATH" "$SYMLINK_PATH"

echo ""
echo "✓ Symlink created successfully!"
echo ""
echo "Next steps:"
echo "  1. Restart the container to install dependencies and run seeding:"
echo "     docker-compose restart civicrm"
echo ""
echo "  2. Check the logs to verify dependency installation:"
echo "     docker-compose logs -f civicrm"
echo ""
echo "  3. Access your site at http://localhost:8080"
echo ""
echo "To remove the symlink later:"
echo "  rm $SYMLINK_PATH"
echo ""
