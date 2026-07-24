#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

make_extension() {
  local target="$1"
  mkdir -p "$target"
  printf '%s\n' '<extension><file>example_ext</file></extension>' > "$target/info.xml"
}

make_extension "$work/clean"
"$root/tools/ckinit.php" "$work/clean" >/dev/null
grep -R -q 'example_ext' "$work/clean/composer.json"
if grep -R -q '__EXTKEY__' "$work/clean"; then
  echo "placeholder remained after rendering" >&2
  exit 1
fi

if "$root/tools/ckinit.php" "$work/clean" >/dev/null 2>&1; then
  echo "existing files were overwritten without --force" >&2
  exit 1
fi
"$root/tools/ckinit.php" --force "$work/clean" >/dev/null

make_extension "$work/symlink"
mkdir -p "$work/outside"
ln -s "$work/outside" "$work/symlink/.docker"
if "$root/tools/ckinit.php" --force "$work/symlink" >/dev/null 2>&1; then
  echo "symlink destination was accepted" >&2
  exit 1
fi
test -z "$(find "$work/outside" -mindepth 1 -print -quit)"

mkdir -p "$work/invalid"
printf '%s\n' '<extension><file>bad-key</file></extension>' > "$work/invalid/info.xml"
if "$root/tools/ckinit.php" "$work/invalid" >/dev/null 2>&1; then
  echo "invalid extension key was accepted" >&2
  exit 1
fi

"$root/tools/ckinit.php" --help >/dev/null
echo "ckinit integration checks passed"
