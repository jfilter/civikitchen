#!/usr/bin/env bash
# Resolve the latest stable civicrm-core release (X.Y.Z only, no pre-release
# tags) and the catalogs' pinned release; emits latest/pinned outputs.
set -euo pipefail

latest=$(git ls-remote --tags https://github.com/civicrm/civicrm-core.git \
  | sed -n 's|.*refs/tags/\([0-9][0-9.]*\)$|\1|p' \
  | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' \
  | sort -V | tail -1)
[ -n "$latest" ] || { echo "could not resolve a latest release" >&2; exit 1; }
pinned=$(toolbelt/bin/ck internal hook-catalog-core-version)
echo "latest=$latest" >> "$GITHUB_OUTPUT"
echo "pinned=$pinned" >> "$GITHUB_OUTPUT"
echo "pinned $pinned, latest $latest"
