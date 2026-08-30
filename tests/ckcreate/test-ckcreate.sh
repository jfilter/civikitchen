#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT

export HOME="$work/home"
export XDG_CONFIG_HOME="$work/config"
export TMPDIR="$work/tmp"
export FAKE_DOCKER_LOG="$work/docker.log"
mkdir -p "$HOME" "$XDG_CONFIG_HOME" "$TMPDIR" "$work/bin"

cat > "$work/bin/docker" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_DOCKER_LOG"
printf '\n' >> "$FAKE_DOCKER_LOG"

for ((i = 1; i <= $#; i++)); do
  if [ "${!i}" = "generate:module" ]; then
    if [ "${FAKE_CIVIX_FAIL:-0}" = "1" ]; then
      exit 17
    fi
    next=$((i + 1))
    key="${!next}"
    break
  fi
done

if [ -z "${key:-}" ]; then
  exit 0
fi

author="" email="" license="" compatibility=""
for ((i = 1; i <= $#; i++)); do
  case "${!i}" in
    --author|--email|--license|--compatibility)
      option="${!i}"
      next=$((i + 1))
      value="${!next}"
      case "$option" in
        --author) author="$value" ;;
        --email) email="$value" ;;
        --license) license="$value" ;;
        --compatibility) compatibility="$value" ;;
      esac
      ;;
  esac
done

out="$HOME/.cache/civikitchen/ckcreate.$PPID/$key"
mkdir -p "$out"
cat > "$out/info.xml" <<EOF
<?xml version="1.0"?>
<extension key="$key" type="module">
  <file>$key</file>
  <name>$key</name>
  <description>FIXME</description>
  <license>$license</license>
  <authors><author><name>$author</name><email>$email</email><role>Maintainer</role></author></authors>
  <urls><url desc="Licensing">https://opensource.org/licenses/$license</url></urls>
  <compatibility><ver>$compatibility</ver></compatibility>
  <php_compatibility mode="list"><ver>8.0</ver><ver>8.1</ver><ver>8.2</ver><ver>8.3</ver><ver>8.4</ver></php_compatibility>
  <civix><namespace>CRM/$key</namespace><format>25.10.2</format></civix>
  <mixins><mixin>scan-classes@1.0.0</mixin></mixins>
</extension>
EOF
cat > "$out/README.md" <<EOF
# $key

This is an extension for CiviCRM, licensed under [$license](LICENSE.txt).
EOF
cat > "$out/LICENSE.txt" <<EOF
Copyright (C) 2020 $author

$license fixture text.
EOF
printf '%s\n' '<?php' > "$out/$key.php"
printf '%s\n' '<?php' > "$out/$key.civix.php"
FAKE
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH"

expect_failure() {
  local label="$1"
  shift
  if "$@" >"$work/failure.out" 2>&1; then
    echo "$label unexpectedly succeeded" >&2
    exit 1
  fi
}

common=(--author "Acme Maintainer" --email dev@example.org --copyright "Acme Collective")

# Mandatory input and key validation happen before Docker or output creation.
expect_failure "missing key" "$root/scaffold/ckcreate"
expect_failure "missing author" "$root/scaffold/ckcreate" probe --email dev@example.org --copyright Acme
expect_failure "missing email" "$root/scaffold/ckcreate" probe --author Acme --copyright Acme
expect_failure "missing copyright" "$root/scaffold/ckcreate" probe --author Acme --email dev@example.org
expect_failure "missing option value" "$root/scaffold/ckcreate" probe --author --email dev@example.org
expect_failure "invalid key" "$root/scaffold/ckcreate" bad-key "${common[@]}"
expect_failure "unknown license" "$root/scaffold/ckcreate" probe "${common[@]}" --license MPL-2.0
test ! -e "$FAKE_DOCKER_LOG"

mkdir -p "$work/existing"
expect_failure "existing destination" "$root/scaffold/ckcreate" probe "${common[@]}" --dir "$work/existing"
test ! -e "$FAKE_DOCKER_LOG"

# Closed licences are generated through civix's MIT template, then made
# consistent across info.xml, composer.json, README, LICENSE and policy.
proprietary="$work/output/proprietary"
"$root/scaffold/ckcreate" probe "${common[@]}" --dir "$proprietary" > "$work/proprietary.out"
php -r '
  $xml = simplexml_load_file($argv[1] . "/info.xml");
  assert((string) $xml->license === "Proprietary");
  assert((string) $xml->php_compatibility->ver[0] === "8.1");
  foreach ($xml->urls->url ?? [] as $url) { assert((string) $url["desc"] !== "Licensing"); }
  $composer = json_decode(file_get_contents($argv[1] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
  assert($composer["license"] === "proprietary");
  assert($composer["private"] === true);
' "$proprietary"
grep -q 'distributed as proprietary software' "$proprietary/README.md"
if grep -q 'MIT' "$proprietary/README.md"; then
  echo "MIT remained in the proprietary README" >&2
  exit 1
fi
grep -q '^Copyright (C) .* Acme Collective\. All rights reserved\.$' "$proprietary/LICENSE.txt"
grep -q $'^\tphpVersion: 80100$' "$proprietary/phpstan.neon.dist"
php -r '
  require $argv[1]; $d=ck_config_load($argv[2]);
  assert($d["policy"]["license"] === "Proprietary");
  assert($d["policy"]["copyright"] === "Acme Collective");
' "$root/packages/civikitchen-scenario-schema/scenario.php" "$proprietary/civikitchen.yaml"
"$root/scaffold/ckinit.php" --check "$proprietary" | grep -q 'up to date'
grep -q -- '--license MIT --compatibility 6.12' "$FAKE_DOCKER_LOG"
grep -q -- '--enable=no' "$FAKE_DOCKER_LOG"
grep -q 'down -v --remove-orphans' "$FAKE_DOCKER_LOG"

# A civix-supported licence stays intact while composer and the copyright
# holder are still normalised to the caller's values.
mit="$work/output/mit"
"$root/scaffold/ckcreate" mitprobe "${common[@]}" --license MIT --php 8.3 --dir "$mit" > "$work/mit.out"
php -r '
  $xml = simplexml_load_file($argv[1] . "/info.xml");
  assert((string) $xml->license === "MIT");
  assert((string) $xml->php_compatibility->ver[0] === "8.3");
  $composer = json_decode(file_get_contents($argv[1] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
  assert($composer["license"] === "MIT");
  assert($composer["require"]["php"] === ">=8.3");
  assert(!array_key_exists("private", $composer));
' "$mit"
grep -q 'licensed under \[MIT\](LICENSE.txt)' "$mit/README.md"
grep -q '^Copyright (C) .* Acme Collective$' "$mit/LICENSE.txt"
grep -q $'^\tphpVersion: 80300$' "$mit/phpstan.neon.dist"
php -r '
  require $argv[1]; $d=ck_config_load($argv[2]);
  assert($d["policy"]["license"] === "MIT");
  assert($d["policy"]["copyright"] === "Acme Collective");
' "$root/packages/civikitchen-scenario-schema/scenario.php" "$mit/civikitchen.yaml"

# A failed civix run leaves no partial extension and cleans its bind mount.
failed="$work/output/failed"
export FAKE_CIVIX_FAIL=1
expect_failure "civix failure" "$root/scaffold/ckcreate" failed "${common[@]}" --dir "$failed"
unset FAKE_CIVIX_FAIL
test ! -e "$failed"
test -z "$(find "$HOME/.cache/civikitchen" -mindepth 1 -print -quit 2>/dev/null)"

# Debug mode preserves both the compose file and its bind mount and does not
# tear the stack down behind the caller.
before_down=$(grep -c 'down -v --remove-orphans' "$FAKE_DOCKER_LOG")
kept="$work/output/kept"
"$root/scaffold/ckcreate" kept "${common[@]}" --dir "$kept" --keep-stack > "$work/kept.out"
compose=$(sed -n 's/^  compose: //p' "$work/kept.out")
mount=$(sed -n 's/^  output:  //p' "$work/kept.out")
test -f "$compose"
test -d "$mount"
after_down=$(grep -c 'down -v --remove-orphans' "$FAKE_DOCKER_LOG")
test "$before_down" -eq "$after_down"

"$root/scaffold/ckcreate" --help >/dev/null
echo "ckcreate integration checks passed"
