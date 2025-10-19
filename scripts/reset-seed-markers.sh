#!/bin/bash
set -e

# Script to reset seed markers to allow re-running seeding
# Usage: ./scripts/reset-seed-markers.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo "===================================="
echo "Resetting Extension Seed Markers"
echo "===================================="
echo ""

# Check if container is running
if ! docker-compose ps civicrm | grep -q "Up"; then
    echo "Error: CiviCRM container is not running"
    echo "Start it with: docker-compose up -d"
    exit 1
fi

docker-compose exec civicrm bash -c '
    EXT_DIR="/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext"

    if [ ! -d "$EXT_DIR" ]; then
        echo "Extensions directory not found"
        exit 1
    fi

    echo "Searching for seed markers..."
    echo ""

    found_markers=false

    # Find and remove all .civicrm-seeded marker files
    for marker_file in "$EXT_DIR"/*/.civicrm-seeded; do
        if [ -f "$marker_file" ]; then
            found_markers=true
            extension_dir=$(dirname "$marker_file")
            extension_name=$(basename "$extension_dir")

            echo "  Removing marker for: $extension_name"
            rm -f "$marker_file"
        fi
    done

    echo ""

    if [ "$found_markers" = true ]; then
        echo "✓ Seed markers removed successfully!"
        echo ""
        echo "You can now re-run seeding with:"
        echo "  ./scripts/seed-extensions.sh"
        echo ""
        echo "Or restart the container:"
        echo "  docker-compose restart civicrm"
    else
        echo "No seed markers found."
        echo ""
        echo "Either:"
        echo "  - No extensions have been seeded yet"
        echo "  - Extensions are configured with runOnce: false"
    fi
'

echo ""
