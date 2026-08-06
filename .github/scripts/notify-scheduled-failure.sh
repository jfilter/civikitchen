#!/usr/bin/env bash
# Open (or bump) the ci-scheduled-failure issue in $REPO pointing at $RUN_URL,
# so a failed weekly rebuild has a place to be seen. Needs $GH_TOKEN.
set -euo pipefail

gh label create ci-scheduled-failure --repo "$REPO" \
  --description "Weekly image rebuild failed" --color B60205 2>/dev/null || true
existing=$(gh issue list --repo "$REPO" --label ci-scheduled-failure \
  --state open --json number --jq '.[0].number // empty')
if [ -n "$existing" ]; then
  gh issue comment "$existing" --repo "$REPO" \
    --body "The scheduled image build failed again: $RUN_URL"
else
  gh issue create --repo "$REPO" --label ci-scheduled-failure \
    --title "Scheduled image build is failing" \
    --body "$(printf 'The weekly image rebuild failed: %s\n\nThe stable tags keep serving the last good images (test-then-promote), but no new CiviCRM release reaches users until this is fixed.' "$RUN_URL")"
fi
