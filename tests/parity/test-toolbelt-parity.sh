#!/usr/bin/env bash
# Toolbelt/Dockerfile parity suite: the real Dockerfiles must copy every
# toolbelt component, and the checker must actually catch a forgotten COPY
# (the fixture leg — most of this suite is silent on success).
set -euo pipefail

cd "$(dirname "$0")/../.."
CHECK="tests/parity/toolbelt-parity.php"
fail() { echo "FAIL: $*" >&2; exit 1; }

# Standalone bakes the full belt.
php "$CHECK" --toolbelt toolbelt --dockerfile docker/standalone/Dockerfile \
  || fail "docker/standalone/Dockerfile is missing toolbelt components"

# Buildkit deliberately omits the two standalone-only helpers: cktestreset
# reseeds the standalone CI scratch DB, ckcoretest runs core suites against
# the dist core the standalone image installs.
php "$CHECK" --toolbelt toolbelt --dockerfile docker/buildkit/Dockerfile \
  --allow bin/cktestreset --allow bin/ckcoretest \
  || fail "docker/buildkit/Dockerfile is missing toolbelt components"

# The fixture drops one COPY (bin/ckfmt); the checker must go red and name it.
out=$(php "$CHECK" --toolbelt toolbelt \
  --dockerfile tests/parity/fixtures/Dockerfile.missing-copy 2>&1) \
  && fail "checker passed a Dockerfile with a missing COPY"
echo "$out" | grep -q 'bin/ckfmt' \
  || fail "checker failed but did not name the missing component (got: $out)"

# A stale --allow (component is copied anyway) must fail too, or exceptions
# outlive their reason.
php "$CHECK" --toolbelt toolbelt --dockerfile docker/standalone/Dockerfile \
  --allow bin/ckfmt >/dev/null 2>&1 \
  && fail "checker accepted a stale --allow entry"

echo "toolbelt parity suite OK"
