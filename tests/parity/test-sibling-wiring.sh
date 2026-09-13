#!/usr/bin/env bash
# Sibling-wiring suite: every stack-booting job of the shared extension CI
# workflow must reach the private-dependency steps and layer their override —
# plus the fixture leg, because the check is silent on success.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/sibling-wiring.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/extension-ci.yml \
  || fail "a stack-booting job is not wired up for sibling_repo / composer_install"

out=$(php "$CHECK" tests/parity/fixtures/workflow.sibling-unwired.yml 2>&1) \
  && fail "checker passed a workflow whose booting job has no sibling wiring"
echo "$out" | grep -q "'boots'" \
  || fail "checker failed but did not name job 'boots' (got: $out)"
echo "$out" | grep -q "'execs'" \
  && fail "checker flagged a job that only execs into an existing stack"

echo "sibling wiring suite OK"
