#!/bin/bash
# Applies the core patches in <patch-dir> to a CiviCRM core tree, in name order.
# A patch the release already contains is skipped; any other mismatch fails the build.
#
# Usage: apply-core-patches.sh <core-dir> <patch-dir>
set -euo pipefail

core="${1:?usage: apply-core-patches.sh <core-dir> <patch-dir>}"
patches="${2:?usage: apply-core-patches.sh <core-dir> <patch-dir>}"
[ -d "${core}" ] || { echo "apply-core-patches: no core directory ${core}" >&2; exit 1; }

for file in "${patches}"/*.patch; do
    [ -e "${file}" ] || continue
    name="$(basename "${file}")"
    grep -q '^Upstream: https://' "${file}" \
        || { echo "apply-core-patches: ${name} names no Upstream: link" >&2; exit 1; }
    if patch -d "${core}" -p1 --forward --dry-run -s < "${file}" >/dev/null 2>&1; then
        patch -d "${core}" -p1 --forward -s < "${file}"
        echo "applied ${name}"
    elif patch -d "${core}" -p1 --reverse --dry-run -s < "${file}" >/dev/null 2>&1; then
        echo "contained ${name}"
    else
        echo "apply-core-patches: ${name} neither applies to nor is contained in ${core}" >&2
        exit 1
    fi
done
