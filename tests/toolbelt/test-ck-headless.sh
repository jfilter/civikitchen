#!/usr/bin/env bash
# The managed test bootstrap's ck_headless() queues the extension's info.xml
# <requires> before the extension itself, without touching the extension
# system, and restores the core foreign keys its own signing drops. Civi is
# stubbed: what is under test is the info.xml reader and the calls the builder
# receives.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

mkdir -p "$work/ext/tests/phpunit"
cp "$root/scaffold/template/extension/tests/phpunit/ckHeadless.php" "$work/ext/tests/phpunit/"
cat > "$work/ext/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.fixture" type="module">
  <file>fixture</file>
  <requires>
    <ext>org.example.base</ext>
    <ext> org.example.dep </ext>
  </requires>
</extension>
XML

cat > "$work/stubs.php" <<'PHP'
<?php
namespace Civi\Test {
  class CiviEnvBuilder {
    public array $installed = [];
    public array $steps = [];
    // One step per call, recorded in order — the helper must not batch them.
    public function install($names): self { $this->installed[] = implode("+", (array) $names); return $this; }
    public function callback($cb, $sig = NULL): self { $this->steps[] = [$cb, $sig]; return $this; }
  }
}
namespace Civi\Test\CiviEnvBuilder {
  class CoreSchemaStep {
    public function getSql(): array { \CkProbe::$signed = TRUE; return ['digest' => 'd', 'content' => 'CORE SCHEMA SQL']; }
  }
}
namespace Civi {
  class Test {
    public static function headless(): \Civi\Test\CiviEnvBuilder {
      \CkProbe::$builtAfterRestore = \CkProbe::$executed !== [];
      return new \Civi\Test\CiviEnvBuilder();
    }
    public static function dsn($part) { return 'civicrm_test'; }
    public static function asPreInstall($cb) { \CkProbe::$preInstall = TRUE; return $cb(); }
    public static function execute($sql) { \CkProbe::$executed[] = $sql; return TRUE; }
  }
}
namespace {
  class CkProbe {
    public static bool $signed = FALSE;
    public static bool $preInstall = FALSE;
    public static bool $builtAfterRestore = FALSE;
    public static array $executed = [];
  }
  class CRM_Core_Session {
    public static array $status = ['boot noise'];
    public static function singleton(): self { return new self(); }
    public function getStatus($reset = FALSE) { $s = self::$status; if ($reset) { self::$status = []; } return $s; }
  }
  // Touching the extension system before the headless rebuild is the bug this
  // helper avoids: the stub makes any such call loud.
  class CRM_Extension_System {
    public static function singleton(): never { throw new RuntimeException('ck_headless touched CRM_Extension_System'); }
  }
}
PHP

probe='
  require $argv[1];
  require $argv[2];
  $b = ck_headless();
  echo implode(",", $b->installed), "|",
    (CkProbe::$signed && CkProbe::$preInstall ? "restored" : "no-restore"), "|",
    (CkProbe::$builtAfterRestore ? "before-build" : "after-build"), "|",
    str_replace("\n", " ", implode(";", CkProbe::$executed)), "\n";
'
out="$(php -r "$probe" "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php")"
IFS='|' read -r installed restored order executed <<<"$out"
[[ "$installed" == "org.example.base,org.example.dep,org.example.fixture" ]] \
  || fail "expected the info.xml requires, trimmed, own key last; got '$installed'"

# Signing CoreSchemaStep drops the core foreign keys, so the generated SQL has
# to be replayed — inside asPreInstall, before the builder is handed out.
[[ "$restored" == "restored" ]] || fail "ck_headless did not re-run the core schema SQL under asPreInstall"
[[ "$order" == "before-build" ]] || fail "the foreign keys must be restored before the builder is built"
[[ "$executed" == *"USE \`civicrm_test\`"* ]] \
  || fail "the restore must select the scratch database; got '$executed'"
[[ "$executed" == *"CORE SCHEMA SQL"* ]] || fail "the restore must replay the CoreSchemaStep SQL; got '$executed'"

# No key in info.xml: a loud error, never a guessed key.
printf '%s\n' '<extension type="module"><file>fixture</file></extension>' > "$work/ext/info.xml"
if php -r 'require $argv[1]; require $argv[2]; ck_headless();' "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php" >/dev/null 2>&1; then
  fail "a missing extension key must throw"
fi

echo "ck_headless: ok"
