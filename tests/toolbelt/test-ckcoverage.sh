#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
script="$root/toolbelt/bin/ckcoverage"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

grep -q 'run_log=$(mktemp /tmp/ckcoverage-run-XXXXXX.log)' "$script" \
  || fail 'ckcoverage must allocate a unique run log'
grep -q '>"$run_log" 2>&1' "$script" \
  || fail 'phpunit output must go to the unique run log'
grep -q 'tail -25 "$run_log"' "$script" \
  || fail 'failure output must read the unique run log'
if grep -q '/tmp/ckcoverage-run.log' "$script"; then
  fail 'the shared fixed run-log path can collide or retain foreign ownership'
fi

echo 'ok   ckcoverage uses a unique temporary run log'
