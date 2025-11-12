#!/bin/bash
set -e

# Script to manually install extension dependencies without restarting the container
# Usage: ./scripts/install-dependencies.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "${SCRIPT_DIR}")"

echo "===================================="
echo "Installing Extension Dependencies"
echo "===================================="
echo ""

# Check if container is running
CONTAINER_STATUS=$(docker-compose ps civicrm || true)
if ! echo "${CONTAINER_STATUS}" | grep -q "Up"; then
    echo "Error: CiviCRM container is not running"
    echo "Start it with: docker-compose up -d"
    exit 1
fi

# Execute the dependency installation function from entrypoint.sh
# shellcheck disable=SC2016
docker-compose exec civicrm bash -c '
    EXT_DIR="/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext"

    if [[ ! -d "${EXT_DIR}" ]]; then
        echo "Extensions directory not found"
        exit 1
    fi

    echo "Scanning for dependencies..."
    echo ""

    # First, check for stack configuration file
    STACK_NAME="${STACK:-eu-nonprofit}"
    STACK_CONFIG="/config/${STACK_NAME}/civikitchen.json"

    if [[ -f "${STACK_CONFIG}" ]]; then
        echo "Found stack configuration: ${STACK_CONFIG}"

        # Parse and install each dependency from stack config
        DEPS=$(jq -r < "${STACK_CONFIG}" -r ".dependencies[]? | @json")

        if [[ -n "${DEPS}" ]]; then
            echo "${DEPS}" | while IFS= read -r dep; do
                DEP_NAME=$(echo "${dep}" | jq -r ".name")
                DEP_REPO=$(echo "${dep}" | jq -r ".repo")
                DEP_VERSION=$(echo "${dep}" | jq -r ".version")
                DEP_ENABLE=$(echo "${dep}" | jq -r ".enable // true")

                DEP_PATH="${EXT_DIR}/${DEP_NAME}"

                # Check if dependency already exists
                if [[ -d "${DEP_PATH}" ]]; then
                    echo "  ✓ Dependency ${DEP_NAME} already installed, skipping"
                    continue
                fi

                echo "  Installing dependency: ${DEP_NAME} @ ${DEP_VERSION}"

                # Clone the repository
                cd "${EXT_DIR}"
                if ! git clone "${DEP_REPO}" "${DEP_NAME}" 2>/dev/null; then
                    echo "  ✗ Failed to clone ${DEP_NAME} from ${DEP_REPO}"
                    continue
                fi

                # Checkout specified version
                cd "${DEP_PATH}"
                if ! git checkout "${DEP_VERSION}" 2>/dev/null; then
                    echo "  ⚠ Warning: Could not checkout version ${DEP_VERSION} for ${DEP_NAME}"
                fi

                echo "  ✓ Installed ${DEP_NAME}"

                # Enable the extension if requested
                if [[ "${DEP_ENABLE}" = "true" ]]; then
                    cd /home/buildkit/buildkit/build/site/web
                    if cv ext:enable "${DEP_NAME}" 2>/dev/null; then
                        echo "  ✓ Enabled ${DEP_NAME}"
                    else
                        echo "  ⚠ Could not enable ${DEP_NAME} (may need manual enabling)"
                    fi
                fi
            done
        fi
        echo ""
    fi

    # Then, check for extension-specific civikitchen.json files
    for config_file in "${EXT_DIR}"/*/civikitchen.json; do
        if [[ ! -f "${config_file}" ]]; then
            continue
        fi

        echo "Found extension config: ${config_file}"
        EXTENSION_DIR=$(dirname "${config_file}")
        EXTENSION_NAME=$(basename "${EXTENSION_DIR}")

        # Parse and install each dependency
        DEPS=$(jq -r < "${config_file}" -r ".dependencies[]? | @json")

        if [[ -z "${DEPS}" ]]; then
            echo "  No dependencies found in ${EXTENSION_NAME}"
            echo ""
            continue
        fi

        echo "${DEPS}" | while IFS= read -r dep; do
            DEP_NAME=$(echo "${dep}" | jq -r ".name")
            DEP_REPO=$(echo "${dep}" | jq -r ".repo")
            DEP_VERSION=$(echo "${dep}" | jq -r ".version")
            DEP_ENABLE=$(echo "${dep}" | jq -r ".enable // true")

            DEP_PATH="${EXT_DIR}/${DEP_NAME}"

            # Check if dependency already exists
            if [[ -d "${DEP_PATH}" ]]; then
                echo "  ✓ Dependency ${DEP_NAME} already installed, skipping"
                continue
            fi

            echo "  Installing dependency: ${DEP_NAME} @ ${DEP_VERSION}"

            # Clone the repository
            cd "${EXT_DIR}"
            if ! git clone "${DEP_REPO}" "${DEP_NAME}" 2>/dev/null; then
                echo "  ✗ Failed to clone ${DEP_NAME} from ${DEP_REPO}"
                continue
            fi

            # Checkout specified version
            cd "${DEP_PATH}"
            if ! git checkout "${DEP_VERSION}" 2>/dev/null; then
                echo "  ⚠ Warning: Could not checkout version ${DEP_VERSION} for ${DEP_NAME}"
            fi

            echo "  ✓ Installed ${DEP_NAME}"

            # Enable the extension if requested
            if [[ "${DEP_ENABLE}" = "true" ]]; then
                cd /home/buildkit/buildkit/build/site/web
                if cv ext:enable "${DEP_NAME}" 2>/dev/null; then
                    echo "  ✓ Enabled ${DEP_NAME}"
                else
                    echo "  ⚠ Could not enable ${DEP_NAME} (may need manual enabling)"
                fi
            fi
        done

        echo ""
    done

    echo "✓ Dependency installation complete!"
'

echo ""
echo "Dependencies have been installed."
echo ""
echo "To verify:"
echo "  ./scripts/list-extensions.sh"
echo ""
