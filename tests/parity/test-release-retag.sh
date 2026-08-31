#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
attach="${root}/.github/scripts/release-attach-tags.sh"

# Release sources are digest-qualified. Buildx defaults to preferring an index
# and may therefore wrap a manifest whose media type was not discovered,
# changing the top-level digest despite identical image content. Both the
# flavor-prefixed and standalone contract aliases must disable that behavior.
creates=$(grep -Ec '^[[:space:]]+docker buildx imagetools create' "${attach}")
preserves=$(grep -Ec '^[[:space:]]+--prefer-index=false' "${attach}")

if [ "${creates}" -ne 2 ] || [ "${preserves}" -ne "${creates}" ]; then
  echo "every release retag must preserve the verified source manifest digest" >&2
  exit 1
fi

grep -Fq '"${IMAGE_PREFIX}@${digest}"' "${attach}" \
  || { echo "release retags must use the verified digest, not a moving tag" >&2; exit 1; }

echo "release retag digest-preservation regression test passed"
