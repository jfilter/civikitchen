#!/usr/bin/env bash
# Assemble the candidate tag list for the trivy scan matrix: every flavor's
# sha-suffixed tag, from $STANDALONE_VERSIONS (JSON array) and $SHA.
set -euo pipefail

images=$(jq -nc --arg sha "$SHA" --argjson standalone "$STANDALONE_VERSIONS" '
  ($standalone | map("standalone-" + .))
  + ["drupal10", "drupal11", "wordpress", "joomla"]
  + ["standalone-demo", "drupal10-demo", "drupal11-demo", "wordpress-demo", "joomla-demo"]
  | map(. + "-" + $sha)
')
echo "images=$images" >> "$GITHUB_OUTPUT"
echo "scanning: $images"
