#!/bin/bash
# Run the same test suite CI runs — locally, on a laptop or in a throwaway VM.
# Requirements: bash, docker (with the compose plugin for nothing — compose is
# NOT needed here), network access to pull the images. No gh, no node.
#
# Usage:
#   bash images/test/run-local.sh                        # everything, published images
#   bash images/test/run-local.sh drupal11 drupal11-demo # a subset
#   bash images/test/run-local.sh -p civikitchen drupal11-demo   # locally built tags
#   CK_PROFILE=verein bash images/test/run-local.sh drupal10-demo # + a profile leg
#
# What runs per flavor (same scripts as CI):
#   dev flavors    test-dev-tools.sh (bundled tooling works)
#                  boot-test.sh (buildkit flavors: first-boot reinstall
#                  against an external MariaDB sidecar; skipped for
#                  standalone, whose boot path is compose-based)
#   demo flavors   boot-test-demo.sh (embedded DB boots clean, demo data,
#                  CIVIKITCHEN_SITE_URL rewrite on a non-80 port; plus one
#                  profile leg when CK_PROFILE is set)
#
# Heads-up on time: a buildkit dev boot test is ~2-5 min, a profile leg up to
# ~15 min. The full default run is roughly an hour.
set -euo pipefail

cd "$(dirname "$0")"

PREFIX="ghcr.io/jfilter/civikitchen"
while getopts "p:h" opt; do
    case "${opt}" in
        p) PREFIX="${OPTARG}" ;;
        h) sed -n '2,20p' "$0"; exit 0 ;;
        *) exit 2 ;;
    esac
done
shift $((OPTIND - 1))

DEV_FLAVORS=(standalone drupal10 drupal11 wordpress joomla)
DEMO_FLAVORS=(standalone-demo drupal10-demo drupal11-demo wordpress-demo joomla-demo)
FLAVORS=("$@")
[ "${#FLAVORS[@]}" -gt 0 ] || FLAVORS=("${DEV_FLAVORS[@]}" "${DEMO_FLAVORS[@]}")

site_type_for() {
    case "$1" in
        drupal10)  echo drupal10-demo ;;
        drupal11)  echo drupal11-demo ;;
        wordpress) echo wp-demo ;;
        joomla)    echo joomla-demo ;;
        *)         echo "" ;;
    esac
}

declare -a RESULTS=()
run() {  # run <label> <cmd...>
    local label="$1"; shift
    echo
    echo "############ ${label}"
    if "$@"; then RESULTS+=("PASS  ${label}"); else RESULTS+=("FAIL  ${label}"); FAILED=1; fi
}

FAILED=0
for flavor in "${FLAVORS[@]}"; do
    image="${PREFIX}:${flavor}"
    case "${flavor}" in
        *-demo)
            run "boot-test-demo ${image}" bash boot-test-demo.sh "${image}"
            if [ -n "${CK_PROFILE:-}" ]; then
                run "boot-test-demo ${image} profile=${CK_PROFILE}" \
                    bash boot-test-demo.sh "${image}" "${CK_PROFILE}"
            fi
            ;;
        *)
            run "test-dev-tools ${image}" \
                docker run --rm -v "$(pwd):/civikitchen-test:ro" --entrypoint='' \
                    "${image}" bash /civikitchen-test/test-dev-tools.sh
            site_type="$(site_type_for "${flavor}")"
            if [ -n "${site_type}" ]; then
                run "boot-test ${image} (${site_type})" \
                    bash boot-test.sh "${image}" "${site_type}"
            else
                echo "(skipping boot-test for ${flavor} — compose-based, see examples/${flavor}/)"
            fi
            ;;
    esac
done

echo
echo "==================== summary ===================="
printf '%s\n' "${RESULTS[@]}"
exit "${FAILED}"
