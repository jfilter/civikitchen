#!/usr/bin/env bash
# The managed test bootstrap's ck_headless() queues the extension's whole
# <requires> closure (as CRM_Extension_Manager computes it) before the
# extension itself. Civi is stubbed: what is under test is the key reader and
# that the builder receives the manager's list, dependencies first.
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
  <requires><ext>org.example.dep</ext></requires>
</extension>
XML

cat > "$work/stubs.php" <<'PHP'
<?php
namespace Civi\Test {
  class CiviEnvBuilder {
    public array $installed = [];
    public function install($names): self { $this->installed = (array) $names; return $this; }
  }
}
namespace Civi {
  class Test {
    public static function headless(): \Civi\Test\CiviEnvBuilder { return new \Civi\Test\CiviEnvBuilder(); }
  }
}
namespace {
  class CRM_Extension_Manager {
    public array $asked = [];
    // The real one returns the transitive closure, sorted, the asked key last.
    public function findInstallRequirements($keys): array {
      $this->asked = $keys;
      return ['org.example.base', 'org.example.dep', $keys[0]];
    }
  }
  class CRM_Extension_System {
    private static ?self $instance = NULL;
    public CRM_Extension_Manager $manager;
    public function __construct() { $this->manager = new CRM_Extension_Manager(); }
    public static function singleton(): self { return self::$instance ??= new self(); }
    public function getManager(): CRM_Extension_Manager { return $this->manager; }
  }
}
PHP

out="$(php -r '
  require $argv[1];
  require $argv[2];
  $b = ck_headless();
  echo implode(",", CRM_Extension_System::singleton()->getManager()->asked), "|", implode(",", $b->installed), "\n";
' "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php")"
[[ "$out" == "org.example.fixture|org.example.base,org.example.dep,org.example.fixture" ]] \
  || fail "expected the manager closure, own key last; got '$out'"

# No key in info.xml: a loud error, never a guessed key.
printf '%s\n' '<extension type="module"><file>fixture</file></extension>' > "$work/ext/info.xml"
if php -r 'require $argv[1]; require $argv[2]; ck_headless();' "$work/stubs.php" "$work/ext/tests/phpunit/ckHeadless.php" >/dev/null 2>&1; then
  fail "a missing extension key must throw"
fi

echo "ck_headless: ok"
