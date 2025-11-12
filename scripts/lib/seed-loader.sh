#!/bin/bash
# Main Seed Loader Script
#
# This script dynamically loads and executes seeding scripts for CiviCRM extensions.
# It looks for seed scripts in scripts/lib/seeds/ directory based on extension keys.
#
# Usage:
#   source scripts/lib/seed-loader.sh
#   seed_extension "org.project60.banking"

set -e

# Change to CiviCRM root for all cv commands
cd /home/buildkit/buildkit/build/site/web 2>/dev/null || true

# Path to seed scripts directory
SEEDS_DIR="/home/buildkit/scripts/lib/seeds"

# Helper: Check if extension is enabled
# Uses API4 first (more cache-resistant) with cv ext:list fallback
is_extension_enabled() {
    local ext_key="$1"

    # Try API4 first - more reliable and doesn't rely on cached extension list
    API4_RESULT=$(cv api4 Extension.get +w key="${ext_key}" +w status="installed" +l 1 2>/dev/null || true)
    if echo "${API4_RESULT}" | grep -q '"key"'; then
        return 0
    fi

    # Fallback to cv ext:list for older CiviCRM versions or if API4 fails
    EXT_LIST=$(cv ext:list --local 2>/dev/null || true)
    EXT_GREP=$(echo "${EXT_LIST}" | grep "| ${ext_key}" || true)
    if echo "${EXT_GREP}" | grep -q "installed"; then
        return 0
    fi
    return 1
}

# Main seeding function
# Usage: seed_extension "org.project60.banking"
seed_extension() {
    local ext_key="$1"
    local seed_script="${SEEDS_DIR}/${ext_key}.sh"

    # Check if seed script exists for this extension
    if [[ -f "${seed_script}" ]]; then
        # Verify extension is enabled before seeding
        # shellcheck disable=SC2310
        if is_extension_enabled "${ext_key}"; then
            echo "🌱 Seeding: ${ext_key}"
            # Execute the seed script
            bash "${seed_script}"
        else
            echo "  ⚠️  Skipping ${ext_key} - not enabled"
        fi
    else
        echo "  ℹ️  No seeding script for ${ext_key}"
        echo "     (looked for: ${seed_script})"
    fi
}

# List all available seed scripts
list_seed_scripts() {
    echo "Available seed scripts:"
    if [[ -d "${SEEDS_DIR}" ]]; then
        for script in "${SEEDS_DIR}"/*.sh; do
            if [[ -f "${script}" ]]; then
                basename "${script}" .sh
            fi
        done
    else
        echo "  No seeds directory found at ${SEEDS_DIR}"
    fi
}

# Seed all extensions that have scripts
seed_all() {
    echo "🌱 Running all available seeding scripts..."
    if [[ -d "${SEEDS_DIR}" ]]; then
        for script in "${SEEDS_DIR}"/*.sh; do
            if [[ -f "${script}" ]]; then
                ext_key=$(basename "${script}" .sh)
                seed_extension "${ext_key}"
            fi
        done
    else
        echo "  ⚠️  No seeds directory found at ${SEEDS_DIR}"
    fi
}

# If called directly with extension name as argument
if [[ $# -gt 0 ]]; then
    if [[ "$1" = "list" ]]; then
        list_seed_scripts
    elif [[ "$1" = "all" ]]; then
        seed_all
    else
        seed_extension "$1"
    fi
fi
