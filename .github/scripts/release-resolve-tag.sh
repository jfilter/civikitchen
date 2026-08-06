#!/usr/bin/env bash
# Resolve the version under release from $EVENT/$REF_NAME/$INPUT_TAG, verify
# the tag exists, and emit tag/major/sha outputs.
set -euo pipefail

if [ "${EVENT}" = workflow_dispatch ]; then TAG="${INPUT_TAG}"; else TAG="${REF_NAME}"; fi
if ! [[ "${TAG}" =~ ^v([0-9]+)\.[0-9]+\.[0-9]+$ ]]; then
  echo "not a release tag: '${TAG}' (expected v<major>.<minor>.<patch>)" >&2
  exit 1
fi
# The dispatch path takes a tag NAME; make sure it is one, so a typo cannot
# release whatever HEAD happens to be.
git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null \
  || { echo "tag does not exist: ${TAG}" >&2; exit 1; }
{
  echo "tag=${TAG}"
  echo "major=v${BASH_REMATCH[1]}"
  echo "sha=$(git rev-parse "refs/tags/${TAG}^{commit}")"
} >> "$GITHUB_OUTPUT"
