#!/usr/bin/env bash
# patch-test-db-boot.php routes CIVICRM_UF=UnitTests boots of the standalone
# stub at the test database and at a cache that lives in it; the buildkit
# settings shim test-db-cache.php does the cache half. Core's bootSettings()
# and civibuild's settings header are stubbed; the settings files set a file
# cache the way a site would in civicrm.settings.php.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
patcher="$root/docker/runtime/patch-test-db-boot.php"

stub="$work/civicrm.standalone.php"
cat > "$stub" <<'PHP'
<?php
namespace Civi\Core {
  class SettingsManager {
    public static function bootSettings($settingsPath) { require $settingsPath; }
  }
}
namespace {
  $settingsPath = __DIR__ . '/civicrm.settings.php';
  \Civi\Core\SettingsManager::bootSettings($settingsPath);
}
PHP
cat > "$work/civicrm.settings.php" <<'PHP'
<?php
if (!defined('CIVICRM_DB_CACHE_CLASS')) {
  define('CIVICRM_DB_CACHE_CLASS', 'FileCache');
}
PHP

cp "$stub" "$work/unpatched"
mkdir "$work/old"
php "$patcher" "$stub" >/dev/null || fail "patching the stub failed"
cp "$stub" "$work/patched"
php "$patcher" "$stub" >/dev/null || fail "re-patching the stub failed"
cmp -s "$stub" "$work/patched" || fail "a second patch run must leave the stub unchanged"

# Boots the patched stub; prints the cache class and the DB name core would compose.
boot() {
  local cv="$1"
  shift
  env CIVICRM_DB_NAME=civicrm "$@" php -r '
    $cv = $argv[2];
    if ($cv !== "") { $GLOBALS["_CV"]["TEST_DB_DSN"] = $cv; }
    require $argv[1];
    echo CIVICRM_DB_CACHE_CLASS, " ", getenv("CIVICRM_DB_NAME"), "\n";' "$stub" "$cv"
}

out="$(boot 'mysql://u:p@db:3306/scratch_test' CIVICRM_UF=UnitTests)"
[[ "$out" == "ArrayCache scratch_test" ]] \
  || fail "a UnitTests boot must use the TEST_DB_DSN database and a DB-backed cache: '$out'"

out="$(boot '' CIVICRM_UF=UnitTests)"
[[ "$out" == "ArrayCache civicrm_test" ]] \
  || fail "a UnitTests boot without TEST_DB_DSN must fall back to <db>_test and a DB-backed cache: '$out'"

out="$(boot 'mysql://u:p@db:3306/scratch_test')"
[[ "$out" == "FileCache civicrm" ]] \
  || fail "the dev site must keep its own database and cache class: '$out'"

php "$patcher" "$work/absent.php" || fail "a missing stub must be a no-op"

# A stub patched by an older image (block without the cache define) gets the
# current block, then boots with a DB-backed cache.
cp "$work/civicrm.settings.php" "$work/old/civicrm.settings.php"
cp "$work/unpatched" "$work/old/civicrm.standalone.php"
php -r '
  $f = $argv[1];
  $old = "// [civikitchen-test-db] Route UnitTests boots at the test DB.\n"
    . "if (getenv(\"CIVICRM_UF\") === \"UnitTests\" && !defined(\"CIVICRM_DSN\")) {\n"
    . "  putenv(\"CIVICRM_DB_NAME=\" . getenv(\"CIVICRM_DB_NAME\") . \"_test\");\n}\n\n";
  $a = "\\Civi\\Core\\SettingsManager::bootSettings";
  $s = file_get_contents($f);
  file_put_contents($f, str_replace($a, $old . $a, $s));' "$work/old/civicrm.standalone.php"
out="$(env CIVICRM_DB_NAME=civicrm CIVICRM_UF=UnitTests php -r 'require $argv[1];
  echo CIVICRM_DB_CACHE_CLASS, " ", getenv("CIVICRM_DB_NAME"), "\n";' "$work/old/civicrm.standalone.php")"
[[ "$out" == "FileCache civicrm_test" ]] || fail "the old-block fixture must lack the cache define: '$out'"
php "$patcher" "$work/old/civicrm.standalone.php" >/dev/null || fail "upgrading an old block failed"
cmp -s "$work/old/civicrm.standalone.php" "$work/patched" \
  || fail "an old block must be replaced by exactly the current one"
out="$(env CIVICRM_DB_NAME=civicrm CIVICRM_UF=UnitTests php -r 'require $argv[1];
  echo CIVICRM_DB_CACHE_CLASS, " ", getenv("CIVICRM_DB_NAME"), "\n";' "$work/old/civicrm.standalone.php")"
[[ "$out" == "ArrayCache civicrm_test" ]] || fail "an upgraded stub must boot with a DB-backed cache: '$out'"

# The patch runs on every standalone boot, outside the marker-gated config
# bundle, so a new image over a kept private/ still gets a patched stub.
entrypoint="$root/docker/standalone/entrypoint.sh"
grep -qx 'ck_patch_boot_stub' "$entrypoint" \
  || fail "the entrypoint must call ck_patch_boot_stub unconditionally at top level"
# shellcheck source=docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"
if declare -f ck_post_install_config | grep -qE "ck_patch_boot_stub|patch-test-db-boot"; then
  fail "the boot-stub patch must not sit behind the configured marker"
fi
php() { printf '%s\n' "$*" > "$work/php-call"; }
: > "$work/php-call"
CK_BOOT_STUB="$work/stub-arg" ck_patch_boot_stub
grep -qx "/usr/local/share/civikitchen/patch-test-db-boot.php $work/stub-arg" "$work/php-call" \
  || fail "ck_patch_boot_stub must patch CK_BOOT_STUB by default"
: > "$work/php-call"
CIVIKITCHEN_TEST_DB=0 ck_patch_boot_stub
[[ ! -s "$work/php-call" ]] || fail "CIVIKITCHEN_TEST_DB=0 must skip the boot-stub patch"
unset -f php

# An unwritable stub must fail, not report a patch it did not apply. Root writes anyway.
if [[ "$(id -u)" -ne 0 ]]; then
  echo '<?php \Civi\Core\SettingsManager::bootSettings($settingsPath);' > "$work/readonly.php"
  chmod 444 "$work/readonly.php"
  if php "$patcher" "$work/readonly.php" >"$work/out" 2>/dev/null; then
    fail "an unwritable stub must exit non-zero"
  fi
  if grep -q Patched "$work/out"; then
    fail "an unwritable stub must not be reported as patched"
  fi
fi

# Buildkit flavors: civibuild's settings header loads pre.d before the template.
cat > "$work/civibuild.settings.php" <<PHP
<?php
require '$root/docker/buildkit/test-db-cache.php';
if (!defined('CIVICRM_UF')) {
  define('CIVICRM_UF', getenv('CIVICRM_UF') ?: 'WordPress');
}
if (!defined('CIVICRM_DB_CACHE_CLASS')) {
  define('CIVICRM_DB_CACHE_CLASS', 'FileCache');
}
echo CIVICRM_DB_CACHE_CLASS, "\n";
PHP
out="$(CIVICRM_UF=UnitTests php "$work/civibuild.settings.php")"
[[ "$out" == "ArrayCache" ]] || fail "a buildkit UnitTests boot must use a DB-backed cache: '$out'"
out="$(php -r 'define("CIVICRM_UF", "UnitTests"); require $argv[1];' "$work/civibuild.settings.php")"
[[ "$out" == "ArrayCache" ]] || fail "a predefined CIVICRM_UF=UnitTests must select a DB-backed cache: '$out'"
out="$(env -u CIVICRM_UF php "$work/civibuild.settings.php")"
[[ "$out" == "FileCache" ]] || fail "the buildkit dev site must keep its cache class: '$out'"

echo "test-db boot patch: ok"
