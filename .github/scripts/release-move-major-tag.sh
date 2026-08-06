#!/usr/bin/env bash
# Move the moving major tag $MAJOR to $RELEASE_SHA — unless a newer patch than
# $TAG exists on that line, so a patch cut on an older line cannot drag the
# major tag backwards. Pushes through the checkout's persisted credentials.
set -euo pipefail

newest=$(git tag -l "${MAJOR}.*" \
  | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1)
if [ "${newest}" != "${TAG}" ]; then
  echo "${newest} is newer than ${TAG}; leaving ${MAJOR} where it is"
  exit 0
fi
git tag -f "${MAJOR}" "${RELEASE_SHA}"
git push -f origin "refs/tags/${MAJOR}"
echo "${MAJOR} -> ${RELEASE_SHA}"
