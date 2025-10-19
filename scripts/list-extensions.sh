#!/bin/bash
set -e

# Script to list all extensions with their status, dependencies, and seeding configuration
# Usage: ./scripts/list-extensions.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo "===================================="
echo "CiviCRM Extensions Overview"
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
    SITE_DIR="/home/buildkit/buildkit/build/site/web"

    if [ ! -d "$EXT_DIR" ]; then
        echo "Extensions directory not found"
        exit 1
    fi

    echo "Custom Extensions (symlinked):"
    echo "==============================="
    echo ""

    # List symlinked extensions
    found_symlinks=false
    for ext_path in "$EXT_DIR"/*; do
        if [ -L "$ext_path" ]; then
            found_symlinks=true
            ext_name=$(basename "$ext_path")
            target=$(readlink "$ext_path")

            echo "📦 $ext_name"
            echo "   Target: $target"

            # Check if it has dependencies
            if [ -f "$ext_path/civikitchen.json" ]; then
                dep_count=$(cat "$ext_path/civikitchen.json" | jq -r ".dependencies // [] | length")
                echo "   Dependencies: $dep_count defined"

                # Check if seeding is configured
                seed_enabled=$(cat "$ext_path/civikitchen.json" | jq -r ".seeding.enabled // false")
                if [ "$seed_enabled" = "true" ]; then
                    seed_marker="$ext_path/.civicrm-seeded"
                    if [ -f "$seed_marker" ]; then
                        echo "   Seeding: ✓ Configured and run"
                    else
                        echo "   Seeding: Configured but not yet run"
                    fi
                fi
            fi

            # Check if enabled in CiviCRM
            cd "$SITE_DIR"
            if cv ext:list --local 2>/dev/null | grep -q "^$ext_name.*enabled"; then
                echo "   Status: ✓ Enabled"
            elif cv ext:list --local 2>/dev/null | grep -q "^$ext_name"; then
                echo "   Status: Disabled"
            else
                echo "   Status: Not detected by CiviCRM"
            fi

            echo ""
        fi
    done

    if [ "$found_symlinks" = false ]; then
        echo "No custom extensions found."
        echo ""
        echo "To link an extension:"
        echo "  ./scripts/link-extension.sh /path/to/your/extension"
        echo ""
    fi

    echo ""
    echo "Installed Dependencies:"
    echo "======================="
    echo ""

    # List extensions that are not symlinks and not standard CiviCRM extensions
    found_deps=false
    for ext_path in "$EXT_DIR"/*; do
        if [ ! -L "$ext_path" ] && [ -d "$ext_path" ]; then
            ext_name=$(basename "$ext_path")

            # Skip hidden directories and gitkeep
            if [[ "$ext_name" == .* ]] || [ "$ext_name" = ".gitkeep" ]; then
                continue
            fi

            # Check if this is a dependency (has a .git directory)
            if [ -d "$ext_path/.git" ]; then
                found_deps=true
                echo "📚 $ext_name"

                # Get git version info
                cd "$ext_path"
                git_version=$(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD 2>/dev/null || echo "unknown")
                echo "   Version: $git_version"

                # Check if enabled
                cd "$SITE_DIR"
                if cv ext:list --local 2>/dev/null | grep -q "^$ext_name.*enabled"; then
                    echo "   Status: ✓ Enabled"
                elif cv ext:list --local 2>/dev/null | grep -q "^$ext_name"; then
                    echo "   Status: Disabled"
                fi

                echo ""
            fi
        fi
    done

    if [ "$found_deps" = false ]; then
        echo "No dependency extensions installed."
        echo ""
    fi

    echo ""
    echo "All CiviCRM Extensions:"
    echo "======================="
    cd "$SITE_DIR"
    cv ext:list --local 2>/dev/null || echo "Could not retrieve extension list"
'

echo ""
