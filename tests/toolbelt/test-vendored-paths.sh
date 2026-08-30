#!/usr/bin/env bash
# `policy.vendored_paths` in civikitchen.yaml keeps third-party source a repo carries
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

# No civikitchen.yaml at all: the filter must be a no-op, not a filter that drops
# everything (an empty `grep -Ev` pattern matches every line).
[ "$(list | wc -l | tr -d ' ')" = 2 ] \
  || fail "without civikitchen.yaml the file list should hold both files, got: $(list | tr '\n' ' ')"

printf '%s\n' \
  'version: 1' \
  'policy:' \
  '  vendored_paths:' \
  '    - path: .docker/upstream/proxy' \
  '      reason: unmodified upstream, must stay byte-identical' > civikitchen.yaml
list | grep -q '^own/Thing.php$' || fail "the repo's own file was dropped"
list | grep -q 'upstream/proxy' && fail "the declared vendored path was still listed"

# A declared prefix names ONE place, not every directory sharing its name.
mkdir -p other/.docker/upstream/proxy
printf '<?php\n' > other/.docker/upstream/proxy/proxy.php
list | grep -q '^other/.docker/upstream/proxy/proxy.php$' \
  || fail "the prefix matched a same-named directory somewhere else"

# A top-level prefix (civix drops `mixin/` into every extension) must survive
# the same path, not just a nested one like .docker/upstream/proxy.
mkdir -p mixin
printf '<?php\n' > mixin/polyfill.php
printf '%s\n' \
  '    - path: mixin' \
  '      reason: upstream polyfill, copied verbatim' >> civikitchen.yaml
list | grep -q '^mixin/polyfill.php$' && fail "a top-level vendored prefix was still listed"

# phpcs keeps only the FIRST --ignore it is given and discards every later one.
# So the exclusions must arrive as ONE comma-separated value: the moment they
# are split across flags, phpcs silently style-checks the vendored source.
ignore=$(ck_phpcs_ignore)
case "$ignore" in
  *'.civikitchen-siblings'*) : ;;
  *) fail "ck_phpcs_ignore dropped the .civikitchen-siblings pattern: $ignore" ;;
esac
for expected in '*/mixin/*' 'mixin/*' '*/.docker/upstream/proxy/*'; do
  case ",$ignore," in
    *",$expected,"*) : ;;
    *) fail "ck_phpcs_ignore is missing '$expected': $ignore" ;;
  esac
done
# One value, so no caller can be tempted to emit a second --ignore.
[ "$(printf '%s' "$ignore" | tr -cd '\n' | wc -c | tr -d ' ')" = 0 ] \
  || fail "ck_phpcs_ignore must echo a single line, got: $ignore"

echo "vendored_paths suite OK"
