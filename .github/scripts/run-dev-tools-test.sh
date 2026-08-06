#!/usr/bin/env bash
# Pull the candidate image ($IMG) for $PLATFORM and run the in-image
# functional test of the bundled dev tools.
set -euo pipefail

docker pull --platform "$PLATFORM" "$IMG"
docker run --rm --platform "$PLATFORM" \
  -v "${GITHUB_WORKSPACE}/tests/images:/civikitchen-test:ro" \
  --entrypoint='' \
  "$IMG" \
  bash /civikitchen-test/test-dev-tools.sh
