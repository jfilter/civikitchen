#!/usr/bin/env bash
# scaffold/ckinit: the mounts and arguments it hands Docker, run through the
# fake docker so ckinit.php really executes against the mapped paths.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(cd "$(mktemp -d)" && pwd -P)"
trap '/bin/rm -rf "$work"' EXIT

mkdir -p "$work/bin"
ln -s "$root/tests/ckinit/fake-docker" "$work/bin/docker"
export PATH="$work/bin:$PATH"
export FAKE_DOCKER_LOG="$work/docker.log"

fail() { echo "ckinit wrapper: $*" >&2; exit 1; }

# An extension below the git root: the root is mounted, the target maps below it.
mono="$work/mono"
mkdir -p "$mono/ext"
git -C "$mono" init -q
printf '%s\n' '<extension key="org.acme.ext" type="module"><file>ext</file><license>AGPL-3.0</license></extension>' > "$mono/ext/info.xml"
"$root/scaffold/ckinit" "$mono/ext" >/dev/null
grep -qF -- "-v $mono:/repo" "$FAKE_DOCKER_LOG" || fail "git root not mounted"
grep -qF -- " /repo/ext" "$FAKE_DOCKER_LOG" || fail "target not passed below /repo"
grep -rq -- '- \.\./\.\.:/civikitchen-repo' "$mono/ext/.docker/" || fail "repository mount missing"
"$root/scaffold/ckinit" --check "$mono/ext" >/dev/null || fail "--check reports drift right after seeding"

# The org policy file is mounted and named inside the container.
org="$work/org"
mkdir -p "$org"
printf '%s\n' '<extension key="org.acme.org" type="module"><file>org</file><license>AGPL-3.0</license></extension>' > "$org/info.xml"
printf '%s\n' 'version: 1' 'policy:' '  renovate_preset: github>org/renovate' > "$work/org-policy"
CK_DEFAULT_CONFIG="$work/org-policy" "$root/scaffold/ckinit" "$org" >/dev/null
grep -q '"extends": \["github>org/renovate"\]' "$org/renovate.json" || fail "CK_DEFAULT_CONFIG not applied"

: > "$FAKE_DOCKER_LOG"
if CK_DEFAULT_CONFIG="$work/missing" "$root/scaffold/ckinit" "$org" >/dev/null 2>&1; then
  fail "a missing CK_DEFAULT_CONFIG must fail"
fi
if "$root/scaffold/ckinit" "$org" "$mono/ext" >/dev/null 2>&1; then
  fail "two directories must fail"
fi
test ! -s "$FAKE_DOCKER_LOG" || fail "invalid input reached Docker"

"$root/scaffold/ckinit" --help >/dev/null
echo "ckinit wrapper: ok"
