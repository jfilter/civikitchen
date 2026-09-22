#!/bin/bash
# The profile driver resolves a git-sourced dependency's composer.json before
# `cv ext:enable`, skips it when vendor/ exists, and fails hard on composer errors.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext" "$work/profile" "$work/repo"

printf '%s\n' '<?php' > "$work/repo/demo.php"
printf '%s\n' '<extension key="org.example.demo" type="module"><file>demo</file></extension>' \
  > "$work/repo/info.xml"
printf '%s\n' '{"name":"example/demo","require":{"webmozart/assert":"^1"}}' > "$work/repo/composer.json"
git -C "$work/repo" init -q
git -C "$work/repo" add -A
git -C "$work/repo" -c user.name=CiviKitchen -c user.email=ci@example.org commit -qm initial
commit=$(git -C "$work/repo" rev-parse HEAD)

cat > "$work/bin/cv" <<'SH'
#!/bin/bash
set -euo pipefail
case " $* " in
  *extensionsDir*) printf '%s' "$CK_FAKE_EXT_DIR" ;;
  *getFullContainer*) printf '%s' "" ;;
  *CIVICRM_UF*) printf 'Standalone' ;;
  *' ext:enable '*) printf 'ext:enable %s\n' "${*: -1}" >> "${CK_FAKE_CV_LOG:?}" ;;
  *'--user=admin'*) printf 'ok' ;;
  *) : ;;
esac
SH
chmod +x "$work/bin/cv"

cat > "$work/bin/composer" <<'SH'
#!/bin/bash
set -euo pipefail
printf 'composer %s\n' "$*" >> "${CK_FAKE_CV_LOG:?}"
if [ "${CK_FAKE_COMPOSER_FAIL:-0}" = 1 ]; then
  echo "fixture: could not resolve dependencies" >&2
  exit 2
fi
for arg in "$@"; do
  case "$arg" in --working-dir=*) mkdir -p "${arg#--working-dir=}/vendor" ;; esac
done
SH
chmod +x "$work/bin/composer"

php -r '
  file_put_contents($argv[1], json_encode([
    "description" => "composer resolution fixture",
    "dependencies" => [["name" => "org.example.demo", "repo" => $argv[2], "version" => $argv[3], "enable" => TRUE]],
  ], JSON_THROW_ON_ERROR));
' "$work/profile/profile.json" "$work/repo" "$commit"

run() { PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_CV_LOG="$work/log" env "$@"; }

# A failing composer aborts the apply before anything is enabled.
: > "$work/log"
if run CK_FAKE_COMPOSER_FAIL=1 bash "$root/docker/profiles/apply.sh" "$work/profile" >"$work/out" 2>&1; then
  echo "driver accepted a failing composer run" >&2; exit 1
fi
grep -q 'fixture: could not resolve dependencies' "$work/out" \
  || { echo "driver swallowed the composer output" >&2; exit 1; }
grep -q '^ext:enable' "$work/log" \
  && { echo "driver enabled an extension despite unresolved composer dependencies" >&2; exit 1; }
rm -rf "$work/ext/org.example.demo"

# No lock file in the checkout, so the driver must use `update`, before enable.
: > "$work/log"
run bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
grep -q -- "^composer update --no-dev --no-interaction --no-progress --working-dir=$work/ext/org.example.demo\$" "$work/log" \
  || { echo "driver did not run composer update for the git-sourced dependency" >&2; cat "$work/log" >&2; exit 1; }
[ "$(grep -n 'ext:enable org.example.demo' "$work/log" | cut -d: -f1)" \
   -gt "$(grep -n '^composer ' "$work/log" | head -1 | cut -d: -f1)" ] \
  || { echo "composer did not run before ext:enable" >&2; exit 1; }

# vendor/ present: no second composer run.
: > "$work/log"
run bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
grep -q '^composer ' "$work/log" \
  && { echo "driver re-resolved composer although vendor/ exists" >&2; exit 1; }
grep -q 'ext:enable org.example.demo' "$work/log" \
  || { echo "driver skipped the enable step" >&2; exit 1; }

# A lock file switches the mode to `install`.
rm -rf "$work/ext/org.example.demo/vendor"
printf '%s\n' '{}' > "$work/ext/org.example.demo/composer.lock"
: > "$work/log"
run bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
grep -q -- '^composer install --no-dev' "$work/log" \
  || { echo "driver did not use composer install with a lock file present" >&2; exit 1; }

echo "profile composer tests passed"
