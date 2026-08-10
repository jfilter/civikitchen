#!/usr/bin/env bash
# Compose-project isolation suite: the real workflows must give every
# stack-booting job its own compose project, and the checker must actually
# catch one that does not (the fixture leg — the check is silent on success).
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/compose-project-isolation.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" .github/workflows/*.yml \
  scaffold/template/extension/.github/workflows/*.yml \
  || fail "a workflow job boots a compose stack without its own project name"

# The fixture shares one project across two jobs on a configurable runner; the
# checker must go red and name both.
out=$(php "$CHECK" tests/parity/fixtures/workflow.shared-compose-project.yml 2>&1) \
  && fail "checker passed a workflow with a shared compose project"
for job in ci compat; do
  echo "$out" | grep -q "'$job'" \
    || fail "checker failed but did not name job '$job' (got: $out)"
done

# The GitHub-hosted job in the same fixture must NOT be reported, or the gate
# would demand a project name where no collision is possible.
echo "$out" | grep -q "'hosted'" \
  && fail "checker flagged a job pinned to a GitHub-hosted runner"

echo "compose project isolation suite OK"
