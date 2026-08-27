#!/usr/bin/env bash
# The managed phpstanBootstrap.php autoloads the classes of every extension
# the repo's info.xml <requires> that exists under CK_EXT_DIR, so a class
# extended from a required extension resolves without a repo-specific
# bootstrap. Core is stubbed to the three files the bootstrap requires.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

core="$work/core"
mkdir -p "$core/vendor" "$core/CRM/Core" "$core/api"
echo '<?php' > "$core/vendor/autoload.php"
echo '<?php' > "$core/api/api.php"
cat > "$core/CRM/Core/ClassLoader.php" <<'PHP'
<?php
class CRM_Core_ClassLoader {
  public static function singleton(): self { return new self(); }
  public function register(): void {}
}
PHP

ext="$work/ext"
mkdir -p "$ext/org.example.dep/CRM/Dep" "$ext/org.example.dep/Civi/Dep" "$ext/org.example.dep/vendor" "$ext/repo"
echo '<?php class CRM_Dep_Base {}' > "$ext/org.example.dep/CRM/Dep/Base.php"
echo '<?php namespace Civi\Dep; interface Contract {}' > "$ext/org.example.dep/Civi/Dep/Contract.php"
echo '<?php define("CK_DEP_VENDOR_LOADED", 1);' > "$ext/org.example.dep/vendor/autoload.php"
cp "$root/scaffold/template/extension/phpstanBootstrap.php" "$ext/repo/"
cat > "$ext/repo/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.repo" type="module">
  <file>repo</file>
  <requires>
    <ext>org.example.dep</ext>
    <ext>org.example.absent</ext>
  </requires>
</extension>
XML

probe='require $argv[1];
  echo class_exists("CRM_Dep_Base") ? "crm" : "-", " ",
    interface_exists("Civi\\Dep\\Contract") ? "civi" : "-", " ",
    defined("CK_DEP_VENDOR_LOADED") ? "vendor" : "-", "\n";'
out="$(CIVICRM_CORE_DIR="$core" CK_EXT_DIR="$ext" php -r "$probe" "$ext/repo/phpstanBootstrap.php" 2>"$work/stderr")"
[[ "$out" == "crm civi vendor" ]] || fail "required extension classes did not resolve: '$out'"
grep -q 'org.example.absent is not under' "$work/stderr" \
  || fail "a required extension with no directory must be noted on stderr: $(cat "$work/stderr")"
if grep -q 'org.example.dep' "$work/stderr"; then
  fail "a present extension must not be reported: $(cat "$work/stderr")"
fi

# No <requires>: nothing registered, nothing said.
printf '%s\n' '<extension key="org.example.repo" type="module"><file>repo</file></extension>' > "$ext/repo/info.xml"
out="$(CIVICRM_CORE_DIR="$core" CK_EXT_DIR="$ext" php -r "$probe" "$ext/repo/phpstanBootstrap.php" 2>"$work/stderr")"
[[ "$out" == "- - -" ]] || fail "without <requires> nothing may be autoloaded: '$out'"
[[ ! -s "$work/stderr" ]] || fail "without <requires> stderr must stay empty: $(cat "$work/stderr")"

echo "phpstan bootstrap: ok"
