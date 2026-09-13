#!/usr/bin/env bash
# Sibling-wiring suite: every stack-booting job of the shared extension CI
# workflow must reach the private-dependency steps and layer their override —
# plus the fixture leg, because the check is silent on success.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/sibling-wiring.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/extension-ci.yml .github/workflows/playwright-e2e.yml \
  || fail "a stack-booting job is not wired up for sibling_repo / composer_install"

out=$(php "$CHECK" tests/parity/fixtures/workflow.sibling-unwired.yml 2>&1) \
  && fail "checker passed a workflow whose booting job has no sibling wiring"
echo "$out" | grep -q "'boots'" \
  || fail "checker failed but did not name job 'boots' (got: $out)"
echo "$out" | grep -q "'execs'" \
  && fail "checker flagged a job that only execs into an existing stack"

out=$(php "$CHECK" tests/parity/fixtures/workflow.sibling-bespoke.yml 2>&1) \
  && fail "checker passed a workflow that checks the sibling out itself"
echo "$out" | grep -q "'bespoke'" \
  || fail "checker failed but did not name job 'bespoke' (got: $out)"

# The same bespoke checkout with a comment that names the action it does not
# use: the gate reads the steps' `uses:`, so the comment changes nothing.
out=$(php "$CHECK" tests/parity/fixtures/workflow.sibling-comment-bait.yml 2>&1) \
  && fail "checker passed a bespoke checkout carrying a private-deps comment"
echo "$out" | grep -q "'bait'" \
  || fail "checker failed but did not name job 'bait' (got: $out)"

echo "sibling wiring suite OK"
