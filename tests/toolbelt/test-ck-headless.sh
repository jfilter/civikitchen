#!/usr/bin/env bash
# The managed test bootstrap's ck_headless() queues the extension's info.xml
# <requires> before the extension itself, without touching the extension
# system. Civi is stubbed: what is under test is the info.xml reader and the
# list the builder receives.
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
    // One step per call, recorded in order — the helper must not batch them.
    public function install($names): self { $this->installed[] = implode("+", (array) $names); return $this; }
  }
}
namespace Civi {
  class Test {
    public static function headless(): \Civi\Test\CiviEnvBuilder { return new \Civi\Test\CiviEnvBuilder(); }
  }
}
namespace {
  // Touching the extension system before the headless rebuild is the bug this
  // helper avoids: the stub makes any such call loud.
  class CRM_Extension_System {
    public static function singleton(): never { throw new RuntimeException('ck_headless touched CRM_Extension_System'); }
  }
}
PHP

out="$(php -r '
  require $argv[1];
  require $argv[2];
  $b = ck_headless();
  echo implode(",", $b->installed), "\n";
' "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php")"
[[ "$out" == "org.example.base,org.example.dep,org.example.fixture" ]] \
  || fail "expected the info.xml requires, trimmed, own key last; got '$out'"

# No key in info.xml: a loud error, never a guessed key.
printf '%s\n' '<extension type="module"><file>fixture</file></extension>' > "$work/ext/info.xml"
if php -r 'require $argv[1]; require $argv[2]; ck_headless();' "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php" >/dev/null 2>&1; then
  fail "a missing extension key must throw"
fi

echo "ck_headless: ok"
