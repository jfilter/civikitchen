#!/usr/bin/env bash
# Attach the $MAJOR/$TAG release tags to every digest in digests.txt (written
# by release-verify-images.sh). imagetools create from a digest copies the
# manifest list intact, so the release tags stay multi-arch — same mechanism
# as promote.
set -euo pipefail

while IFS=$'\t' read -r flavor digest; do
  echo "tagging ${flavor} (${digest}) as ${flavor}-${MAJOR} / ${flavor}-${TAG}"
  docker buildx imagetools create \
    -t "${IMAGE_PREFIX}:${flavor}-${MAJOR}" \
    -t "${IMAGE_PREFIX}:${flavor}-${TAG}" \
    "${IMAGE_PREFIX}@${digest}"
  if [ "${flavor}" = standalone ]; then
    # The contract image the extension template pins, unprefixed.
    docker buildx imagetools create \
      -t "${IMAGE_PREFIX}:${MAJOR}" \
      -t "${IMAGE_PREFIX}:${TAG}" \
      "${IMAGE_PREFIX}@${digest}"
  fi
done < digests.txt
