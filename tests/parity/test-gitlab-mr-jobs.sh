#!/usr/bin/env bash
# Every job of the shared GitLab pipeline runs in merge request pipelines — plus
# the fixture leg, because the check is silent on success.
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/gitlab-mr-jobs.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$CHECK" ci/gitlab/extension-ci.yml \
  || fail "a job of the shared GitLab pipeline is left out of merge request pipelines"

out=$(php "$CHECK" tests/parity/fixtures/gitlab.job-without-rules.yml 2>&1) \
  && fail "checker passed a pipeline with a job that has no rules"
grep -q "'bare'" <<<"$out" \
  || fail "checker failed but did not name job 'bare' (got: $out)"
if grep -qE "'(inherited|own)'" <<<"$out"; then
  fail "checker flagged a job with its own or inherited rules (got: $out)"
fi

echo "gitlab merge request jobs suite OK"
