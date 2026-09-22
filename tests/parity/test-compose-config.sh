#!/usr/bin/env bash
# Every shipped compose file must pass `docker compose config`: a dangling
# key left by an edit fails the consumer's CI at `up`, not here.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
rc=0
while IFS= read -r file; do
  if ! err=$(DOCKER_HOST=unix:///nonexistent docker compose -f "$file" config -q 2>&1); then
    echo "FAIL: $file: $err" >&2
    rc=1
  fi
done < <(git ls-files | grep -E '(^|/)docker-compose[^/]*\.ya?ml$')
[[ "$rc" -eq 0 ]] || exit 1
echo "compose config: ok"
