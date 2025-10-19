#!/bin/bash
set -e

# Script to manually run extension seeding
# Usage: ./scripts/seed-extensions.sh [--force]

FORCE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --force)
            FORCE=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--force]"
            exit 1
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo "===================================="
echo "Running Extension Seeding"
if [ "$FORCE" = true ]; then
    echo "(Force mode: ignoring runOnce markers)"
fi
echo "===================================="
echo ""

# Check if container is running
if ! docker-compose ps civicrm | grep -q "Up"; then
    echo "Error: CiviCRM container is not running"
    echo "Start it with: docker-compose up -d"
    exit 1
fi

# Execute the seeding function
docker-compose exec civicrm bash -c "
    EXT_DIR=\"/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext\"
    FORCE=$FORCE

    if [ ! -d \"\$EXT_DIR\" ]; then
        echo \"Extensions directory not found\"
        exit 1
    fi

    echo \"Scanning for extension seeding configurations...\"
    echo \"\"

    # Find all civikitchen.json files with seeding config
    for config_file in \"\$EXT_DIR\"/*/civikitchen.json; do
        if [ ! -f \"\$config_file\" ]; then
            continue
        fi

        EXTENSION_DIR=\$(dirname \"\$config_file\")
        EXTENSION_NAME=\$(basename \"\$EXTENSION_DIR\")

        # Check if seeding is enabled
        SEED_ENABLED=\$(cat \"\$config_file\" | jq -r '.seeding.enabled // false')

        if [ \"\$SEED_ENABLED\" != \"true\" ]; then
            continue
        fi

        SEED_SCRIPT=\$(cat \"\$config_file\" | jq -r '.seeding.script // \"\"')
        RUN_ONCE=\$(cat \"\$config_file\" | jq -r '.seeding.runOnce // true')
        SEED_MARKER=\"\$EXTENSION_DIR/.civicrm-seeded\"

        if [ -z \"\$SEED_SCRIPT\" ]; then
            echo \"  ⚠ Seeding enabled for \$EXTENSION_NAME but no script specified\"
            echo \"\"
            continue
        fi

        # Check if already seeded (unless force mode)
        if [ \"\$FORCE\" != \"true\" ] && [ \"\$RUN_ONCE\" = \"true\" ] && [ -f \"\$SEED_MARKER\" ]; then
            echo \"  ✓ \$EXTENSION_NAME already seeded (runOnce=true), skipping\"
            echo \"    Use --force to re-run seeding\"
            echo \"\"
            continue
        fi

        SEED_PATH=\"\$EXTENSION_DIR/\$SEED_SCRIPT\"

        if [ ! -f \"\$SEED_PATH\" ]; then
            echo \"  ✗ Seed script not found: \$SEED_PATH\"
            echo \"\"
            continue
        fi

        echo \"  Running seed script for \$EXTENSION_NAME...\"

        # Make script executable and run it
        chmod +x \"\$SEED_PATH\"
        if bash \"\$SEED_PATH\"; then
            echo \"  ✓ Seeding completed for \$EXTENSION_NAME\"

            # Create marker file if runOnce is true
            if [ \"\$RUN_ONCE\" = \"true\" ]; then
                touch \"\$SEED_MARKER\"
            fi
        else
            echo \"  ✗ Seeding failed for \$EXTENSION_NAME\"
        fi

        echo \"\"
    done

    echo \"✓ Extension seeding complete!\"
"

echo ""
echo "Seeding complete."
echo ""
echo "To reset seed markers and re-run seeding:"
echo "  ./scripts/reset-seed-markers.sh"
echo ""
