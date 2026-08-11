#!/usr/bin/env bash
# `vendored_paths` in .ckconform keeps third-party source a repo carries
# verbatim out of the file lists cklint/ckfmt/ckeslint build. Without it a repo
# can only choose between a permanently red gate and reformatting code that has
# to stay byte-identical to its upstream.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# ck_policy_all shells out to ckconform, so the fixture needs a real checkout.
cd "$work"
git init -q .
git config user.email ck@example.org
git config user.name ck
mkdir -p own .docker/upstream/proxy
printf '<?php\n' > own/Thing.php
printf '<?php\n' > .docker/upstream/proxy/proxy.php
git add -A
git commit -qm fixture

# shellcheck source=../../toolbelt/lib/ckcommon.sh
. "$root/toolbelt/lib/ckcommon.sh"

list() {
  { ck_git ls-files --cached --others --exclude-standard -- '*.php' 2>/dev/null \
    | grep -Ev "$ck_re_vendored" \
    | ck_drop_repo_vendored || true; }
}

# No .ckconform at all: the filter must be a no-op, not a filter that drops
# everything (an empty `grep -Ev` pattern matches every line).
[ "$(list | wc -l | tr -d ' ')" = 2 ] \
  || fail "without .ckconform the file list should hold both files, got: $(list | tr '\n' ' ')"

printf '%s\n' 'vendored_paths=.docker/upstream/proxy -- unmodified upstream, must stay byte-identical' > .ckconform
list | grep -q '^own/Thing.php$' || fail "the repo's own file was dropped"
list | grep -q 'upstream/proxy' && fail "the declared vendored path was still listed"

# A declared prefix names ONE place, not every directory sharing its name.
mkdir -p other/.docker/upstream/proxy
printf '<?php\n' > other/.docker/upstream/proxy/proxy.php
list | grep -q '^other/.docker/upstream/proxy/proxy.php$' \
  || fail "the prefix matched a same-named directory somewhere else"

echo "vendored_paths suite OK"
