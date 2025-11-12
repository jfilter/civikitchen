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

    echo \"Scanning for seeding configurations...\"
    echo \"\"

    # Flush cache to ensure extension list is current
    cd /home/buildkit/buildkit/build/site/web 2>/dev/null && cv flush 2>/dev/null || true

    # Source the seed loader script if available
    if [ -f \"/home/buildkit/scripts/lib/seed-loader.sh\" ]; then
        source /home/buildkit/scripts/lib/seed-loader.sh
    elif [ -f \"/home/buildkit/scripts/lib/seed-common-extensions.sh\" ]; then
        source /home/buildkit/scripts/lib/seed-common-extensions.sh
    fi

    # First, check for stack configuration file
    STACK_NAME=\"\${STACK:-eu-nonprofit}\"
    STACK_CONFIG=\"/config/\${STACK_NAME}/civikitchen.json\"

    if [ -f \"\$STACK_CONFIG\" ]; then
        echo \"Found stack configuration: \$STACK_CONFIG\"

        # Parse dependencies and check which ones need seeding
        DEPS=\$(cat \"\$STACK_CONFIG\" | jq -r '.dependencies[]? | @json')

        if [ -n \"\$DEPS\" ]; then
            echo \"\$DEPS\" | while IFS= read -r dep; do
                DEP_NAME=\$(echo \"\$dep\" | jq -r '.name')
                DEP_SEED=\$(echo \"\$dep\" | jq -r '.seed // false')

                if [ \"\$DEP_SEED\" = \"false\" ]; then
                    continue
                fi

                SEED_MARKER=\"/tmp/.civicrm-seeded-\$DEP_NAME\"

                # Check if already seeded (unless force mode)
                if [ \"\$FORCE\" != \"true\" ] && [ -f \"\$SEED_MARKER\" ]; then
                    echo \"  ✓ \$DEP_NAME already seeded, skipping\"
                    echo \"    Use --force to re-run seeding\"
                    echo \"\"
                    continue
                fi

                if [ \"\$DEP_SEED\" = \"true\" ]; then
                    # Use built-in seeding
                    echo \"  Running built-in seeding for \$DEP_NAME...\"
                    if declare -f seed_extension > /dev/null; then
                        if seed_extension \"\$DEP_NAME\"; then
                            echo \"  ✓ Seeding completed for \$DEP_NAME\"
                            touch \"\$SEED_MARKER\"
                        else
                            echo \"  ✗ Seeding failed for \$DEP_NAME\"
                        fi
                    else
                        echo \"  ⚠️  Built-in seeding not available\"
                    fi
                elif [ \"\$DEP_SEED\" = \"custom\" ] || [ \"\$DEP_SEED\" != \"false\" ]; then
                    # Custom seed script
                    echo \"  Running custom seeding for \$DEP_NAME...\"
                    EXT_PATH=\"\$EXT_DIR/\$DEP_NAME\"
                    if [ -f \"\$EXT_PATH/seed.sh\" ]; then
                        chmod +x \"\$EXT_PATH/seed.sh\"
                        if bash \"\$EXT_PATH/seed.sh\"; then
                            echo \"  ✓ Custom seeding completed for \$DEP_NAME\"
                            touch \"\$SEED_MARKER\"
                        else
                            echo \"  ✗ Custom seeding failed for \$DEP_NAME\"
                        fi
                    else
                        echo \"  ⚠️  Custom seed script not found: \$EXT_PATH/seed.sh\"
                    fi
                fi

                echo \"\"
            done
        fi
        echo \"\"
    fi

    # Then, check for extension-specific seeding configurations
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
