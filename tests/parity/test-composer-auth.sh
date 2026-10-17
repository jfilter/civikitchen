#!/usr/bin/env bash
# The composer_auth secret reaches only RUNs that use the image's own composer.
# buildkit's pinned composer rejects GitHub's ghs_<id>_<jwt> tokens and prints them.
set -euo pipefail

cd "$(dirname "$0")/../.."
fail() { echo "FAIL: $*" >&2; exit 1; }

# One line per RUN instruction, continuations joined.
runs() {
  awk '/^RUN /{r=$0; while (r ~ /\\$/ && (getline l) > 0) r = substr(r, 1, length(r) - 1) l; print r}' "$1"
}

check() {
  local dockerfile=$1 out
  out=$(runs "$dockerfile")
  if grep -E 'civi-download-tools|bake\.sh' <<<"$out" | grep -q 'id=composer_auth'; then
    fail "$dockerfile hands composer_auth to a buildkit composer step"
  fi
  if grep -E 'composer (global )?install|install-dev-tools' <<<"$out" | grep -vE 'civi-download-tools|bake\.sh' | grep -vq 'id=composer_auth'; then
    fail "$dockerfile runs its own composer without composer_auth"
  fi
}

check docker/buildkit/Dockerfile
check docker/standalone/Dockerfile

# Fixture leg: the check is silent on success.
fixture=$(mktemp)
trap '/bin/rm -f "$fixture"' EXIT
printf 'RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH cd buildkit \\\n    && ./bin/civi-download-tools\n' > "$fixture"
(check "$fixture") 2>/dev/null && fail "check passed a buildkit step that mounts composer_auth"

echo "composer auth wiring OK"
