#!/usr/bin/env bash
# Attach the $MAJOR/$TAG release tags to every digest in digests.txt (written
# by release-verify-images.sh). The source is deliberately digest-qualified;
# --prefer-index=false prevents Buildx from wrapping a source manifest in a
# fresh one-item index when the registry does not expose its media type during
# resolution. That keeps the release tag on the exact verified digest while
# preserving multi-platform indexes as indexes.
set -euo pipefail

while IFS=$'\t' read -r flavor digest; do
  echo "tagging ${flavor} (${digest}) as ${flavor}-${MAJOR} / ${flavor}-${TAG}"
  docker buildx imagetools create \
    --prefer-index=false \
    -t "${IMAGE_PREFIX}:${flavor}-${MAJOR}" \
    -t "${IMAGE_PREFIX}:${flavor}-${TAG}" \
    "${IMAGE_PREFIX}@${digest}"
  if [ "${flavor}" = standalone ]; then
    # The contract image the extension template pins, unprefixed.
    docker buildx imagetools create \
      --prefer-index=false \
      -t "${IMAGE_PREFIX}:${MAJOR}" \
      -t "${IMAGE_PREFIX}:${TAG}" \
      "${IMAGE_PREFIX}@${digest}"
  fi
done < digests.txt
