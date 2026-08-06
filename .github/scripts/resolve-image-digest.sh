#!/usr/bin/env bash
# Resolve the published digest behind $IMAGE_PREFIX:$IMAGE — the artifact a
# pull actually gets — and emit it as the `ref` output.
set -euo pipefail

ref="${IMAGE_PREFIX}:${IMAGE}"
digest=$(docker buildx imagetools inspect "$ref" --format '{{.Manifest.Digest}}')
[[ "$digest" =~ ^sha256:[a-f0-9]{64}$ ]] \
  || { echo "could not resolve a digest for $ref (got: '$digest')" >&2; exit 1; }
echo "$ref -> $digest"
echo "ref=${IMAGE_PREFIX}@${digest}" >> "$GITHUB_OUTPUT"
