#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

make_extension() {
  local target="$1"
  mkdir -p "$target"
  printf '%s\n' '<extension key="org.acme.example_ext" type="module"><file>example_ext</file></extension>' > "$target/info.xml"
}

make_extension "$work/clean"
"$root/scaffold/ckinit.php" "$work/clean" >/dev/null
grep -q 'acme/example_ext' "$work/clean/composer.json"
if grep -R -q '__EXTKEY__\|__VENDOR__' "$work/clean"; then
  echo "placeholder remained after rendering" >&2
  exit 1
fi

mkdir -p "$work/nokey"
printf '%s\n' '<extension><file>example_ext</file></extension>' > "$work/nokey/info.xml"
"$root/scaffold/ckinit.php" "$work/nokey" >/dev/null
grep -q 'example/example_ext' "$work/nokey/composer.json"

if "$root/scaffold/ckinit.php" "$work/clean" >/dev/null 2>&1; then
  echo "existing files were overwritten without --force" >&2
  exit 1
fi
"$root/scaffold/ckinit.php" --force "$work/clean" >/dev/null

make_extension "$work/symlink"
mkdir -p "$work/outside"
ln -s "$work/outside" "$work/symlink/.docker"
if "$root/scaffold/ckinit.php" --force "$work/symlink" >/dev/null 2>&1; then
  echo "symlink destination was accepted" >&2
  exit 1
fi
test -z "$(find "$work/outside" -mindepth 1 -print -quit)"

mkdir -p "$work/invalid"
printf '%s\n' '<extension><file>bad-key</file></extension>' > "$work/invalid/info.xml"
if "$root/scaffold/ckinit.php" "$work/invalid" >/dev/null 2>&1; then
  echo "invalid extension key was accepted" >&2
  exit 1
fi

# --check on a freshly seeded extension: no drift.
make_extension "$work/drift"
"$root/scaffold/ckinit.php" "$work/drift" >/dev/null
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
echo "$out" | grep -q 'up to date'

# A drifted MANAGED file fails --check; an edited SEEDED file does not.
printf '%s\n' '# local edit' >> "$work/drift/.github/workflows/ci.yml"
printf '%s\n' '{"name": "acme/example_ext", "edited": true}' > "$work/drift/composer.json"
if out=$("$root/scaffold/ckinit.php" --check "$work/drift" 2>&1); then
  echo "managed drift was not detected" >&2
  exit 1
fi
echo "$out" | grep -q 'drifted   .github/workflows/ci.yml'
if echo "$out" | grep -q 'composer.json'; then
  echo "seeded file was reported as drift" >&2
  exit 1
fi

# --update restores the managed file (re-stamped) and leaves the seeded edit.
/bin/rm "$work/drift/phpcs.xml.dist"
printf '%s\n' '# local edit' >> "$work/drift/.docker/docker-compose.ci.yml"
out=$("$root/scaffold/ckinit.php" --update "$work/drift")
echo "$out" | grep -q 'updated   .github/workflows/ci.yml'
echo "$out" | grep -q 'updated   .docker/docker-compose.ci.yml'
echo "$out" | grep -q 'created   phpcs.xml.dist'
grep -q 'ext/example_ext' "$work/drift/.docker/docker-compose.ci.yml"
grep -q '"edited": true' "$work/drift/composer.json"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
echo "$out" | grep -q 'up to date'

# A deviation declared in .ckconform (with its mandatory reason) is respected.
printf '%s\n' 'template_custom=.github/workflows/ci.yml -- bespoke pipeline' > "$work/drift/.ckconform"
printf '%s\n' '# local edit' >> "$work/drift/.github/workflows/ci.yml"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
echo "$out" | grep -q 'custom    .github/workflows/ci.yml'
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
grep -q '# local edit' "$work/drift/.github/workflows/ci.yml"

# ... but the reason is not optional, and only managed files may be listed.
printf '%s\n' 'template_custom=.github/workflows/ci.yml' > "$work/drift/.ckconform"
if "$root/scaffold/ckinit.php" --check "$work/drift" >/dev/null 2>&1; then
  echo "template_custom without a reason was accepted" >&2
  exit 1
fi
# A typo'd file name must fail loudly, not silently disable nothing.
printf '%s\n' 'template_custom=composer.jsn -- edited anyway' > "$work/drift/.ckconform"
if "$root/scaffold/ckinit.php" --check "$work/drift" >/dev/null 2>&1; then
  echo "template_custom with a typo'd file name was accepted" >&2
  exit 1
fi

# A SEEDED file declared custom may be absent: the repo owns its existence.
# --check accepts the absence and --update must not reseed it.
/bin/rm "$work/drift/.ckconform"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
printf '%s\n' 'template_custom=phpunit.xml.dist -- no PHP suite in this repo' > "$work/drift/.ckconform"
/bin/rm "$work/drift/phpunit.xml.dist"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
echo "$out" | grep -q 'custom    phpunit.xml.dist'
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
if [ -e "$work/drift/phpunit.xml.dist" ]; then
  echo "--update reseeded a custom-declared seeded file" >&2
  exit 1
fi

# The executable bit is part of the managed contract (the one mode bit git
# tracks); content-identical but chmod +x must read as drift.
/bin/rm "$work/drift/.ckconform"
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
chmod +x "$work/drift/phpstanBootstrap.php"
if out=$("$root/scaffold/ckinit.php" --check "$work/drift" 2>&1); then
  echo "executable-bit drift was not detected" >&2
  exit 1
fi
echo "$out" | grep -q 'drifted   phpstanBootstrap.php'
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
if [ -x "$work/drift/phpstanBootstrap.php" ]; then
  echo "--update did not restore the file mode" >&2
  exit 1
fi

# phpcs.xml.dist is SEEDED (a repo's project layer): edits must not be drift.
printf '%s\n' '<!-- local layer -->' >> "$work/drift/phpcs.xml.dist"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
echo "$out" | grep -q 'up to date'

# phpstan-tests.neon.dist is seeded but OPTIONAL: its absence is not drift,
# and --update must not reintroduce it (existence is the CI opt-in switch).
test -f "$work/drift/phpstan-tests.neon.dist"
/bin/rm "$work/drift/phpstan-tests.neon.dist"
out=$("$root/scaffold/ckinit.php" --check "$work/drift")
echo "$out" | grep -q 'optional  phpstan-tests.neon.dist'
echo "$out" | grep -q 'up to date'
"$root/scaffold/ckinit.php" --update "$work/drift" >/dev/null
if [ -e "$work/drift/phpstan-tests.neon.dist" ]; then
  echo "--update recreated the opt-in phpstan-tests.neon.dist" >&2
  exit 1
fi

# --force is a seeding flag; refuse the ambiguous combinations.
if "$root/scaffold/ckinit.php" --force --update "$work/drift" >/dev/null 2>&1; then
  echo "--force --update was accepted" >&2
  exit 1
fi
if "$root/scaffold/ckinit.php" --update --check "$work/drift" >/dev/null 2>&1; then
  echo "--update --check was accepted" >&2
  exit 1
fi

"$root/scaffold/ckinit.php" --help >/dev/null
echo "ckinit integration checks passed"
