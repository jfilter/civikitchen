#!/usr/bin/env bash
# Unfiltered trivy report over $IMAGE_REF ($IMAGE, $PLATFORM) into the job
# summary — all severities, unfixed included, exit code 0.
set -euo pipefail

report="$RUNNER_TEMP/trivy-report.txt"
"$RUNNER_TEMP/trivy" image \
  --image-src remote \
  --platform "$PLATFORM" \
  --scanners vuln \
  --exit-code 0 \
  --skip-db-update \
  --skip-java-db-update \
  --no-progress \
  --quiet \
  --output "$report" \
  "$IMAGE_REF"
# GitHub drops the WHOLE summary over 1 MiB, so an untruncated report made
# this step silently produce nothing. Cut it and say so.
budget=900000
total=$(wc -l < "$report")
head -c "$budget" "$report" > "$report.cut"
kept=$(wc -l < "$report.cut")
{
  echo "## trivy: $IMAGE ($PLATFORM)"
  echo
  echo "\`$IMAGE_REF\` — all severities, unfixed included."
  if [ "$kept" -lt "$total" ]; then
    echo
    echo "**Truncated to fit GitHub's 1 MiB summary limit: $kept of $total lines.**"
    echo "Re-run the scan locally for the rest: \`trivy image $IMAGE_REF\`"
  fi
  echo
  echo '```'
  cat "$report.cut"
  echo '```'
} >> "$GITHUB_STEP_SUMMARY"
