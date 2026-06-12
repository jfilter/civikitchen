#!/bin/bash
# Functional test for civikitchen dev images.
#
# Exercises every bundled dev tool against real input — not just --version
# checks. Run inside a built dev image, e.g.:
#
#   docker run --rm -v "$(pwd)/images/test:/civikitchen-test" \
#       ghcr.io/jfilter/civikitchen:standalone \
#       bash /civikitchen-test/test-dev-tools.sh
#
# Or via the workflow's smoke-test job (see .github/workflows/build-dev-images.yml).
#
# What it checks:
#   1. Every binary responds to --version
#   2. phpcs has the Drupal + DrupalPractice + CiviKitchen standards registered
#   3. phpcs actually lints a sample file (intentionally non-conforming)
#   3b. The CiviKitchen footgun sniffs fire, and cklint applies them
#   4. phpstan actually analyses a sample file (intentionally with type errors)
#   5. phpunit actually runs a passing assertion
#   6. composer can install a real package from packagist
#   7. civix can render help (signals the phar boots)
#   8. Xdebug toggle: php -m has no xdebug by default; setting XDEBUG_MODE
#      via the entrypoint enables it
set -euo pipefail

PASS=0
FAIL=0
FAILURES=()

ok()   { echo "  ✓ $1"; PASS=$((PASS+1)); }
fail() { echo "  ✗ $1"; FAIL=$((FAIL+1)); FAILURES+=("$1"); }

# ---------------------------------------------------------------------------
# 1. Binaries respond to --version
echo "== versions =="
for bin in composer node npm civix phpunit phpstan phpcs phpcbf cv; do
    if command -v "${bin}" >/dev/null 2>&1 && "${bin}" --version >/dev/null 2>&1; then
        ok "${bin} --version"
    else
        fail "${bin} --version"
    fi
done

# ---------------------------------------------------------------------------
# 2. phpcs has Drupal + DrupalPractice + the bundled CiviKitchen standard
echo "== phpcs standards =="
STANDARDS="$(phpcs -i 2>&1)"
if echo "${STANDARDS}" | grep -q Drupal; then ok "Drupal standard registered"; else fail "Drupal standard missing ($STANDARDS)"; fi
if echo "${STANDARDS}" | grep -q DrupalPractice; then ok "DrupalPractice standard registered"; else fail "DrupalPractice standard missing"; fi
if echo "${STANDARDS}" | grep -q CiviKitchen; then ok "CiviKitchen standard registered"; else fail "CiviKitchen standard missing"; fi

# ---------------------------------------------------------------------------
# 3. phpcs lints a sample file
echo "== phpcs run =="
WORKDIR="$(mktemp -d)"
cat > "${WORKDIR}/Bad.php" <<'PHP'
<?php
// Intentionally non-conforming code to force phpcs to find issues.
class bad_name {
    function    foo($x){return$x+1;}
}
PHP

# phpcs exits non-zero when issues are found — that's the *success* path here.
# We want to confirm it actually parses + reports, not that the code is clean.
PHPCS_OUT="$(phpcs --standard=Drupal "${WORKDIR}/Bad.php" 2>&1 || true)"
if echo "${PHPCS_OUT}" | grep -qiE "error|warning|FOUND"; then
    ok "phpcs --standard=Drupal reports issues"
else
    fail "phpcs --standard=Drupal didn't report any issue (output: ${PHPCS_OUT:0:200})"
fi

# ---------------------------------------------------------------------------
# 3b. The CiviKitchen footgun sniffs fire, and cklint runs them
echo "== CiviKitchen standard + cklint =="
cat > "${WORKDIR}/Legacy.php" <<'PHP'
<?php
function f() {
  CRM_Core_Error::debug_log_message(ts('hello'));
  return civicrm_api3('Contact', 'get', []);
}
PHP

CK_OUT="$(phpcs --standard=CiviKitchen --extensions=php "${WORKDIR}/Legacy.php" 2>&1 || true)"
if echo "${CK_OUT}" | grep -q "civicrm_api3"; then
    ok "CiviKitchen NoLegacyCall flags civicrm_api3"
else
    fail "CiviKitchen NoLegacyCall didn't flag civicrm_api3 (output: ${CK_OUT:0:200})"
fi
if echo "${CK_OUT}" | grep -q "debug_log_message"; then
    ok "CiviKitchen NoLegacyCall flags CRM_Core_Error::debug_log_message"
else
    fail "CiviKitchen NoLegacyCall didn't flag debug_log_message (output: ${CK_OUT:0:200})"
fi
if echo "${CK_OUT}" | grep -qi "translation domain"; then
    ok "CiviKitchen UseExtensionTs flags bare ts()"
else
    fail "CiviKitchen UseExtensionTs didn't flag bare ts() (output: ${CK_OUT:0:200})"
fi

if cklint --help 2>&1 | grep -q "uncommitted git changes"; then
    ok "cklint --help"
else
    fail "cklint --help"
fi

# Explicit-path mode without a project phpcs.xml(.dist) in cwd: cklint must
# syntax-check (php -l) and then apply the CiviKitchen fallback standard.
CKLINT_OUT="$( (cd "${WORKDIR}" && cklint Legacy.php) 2>&1 || true)"
if echo "${CKLINT_OUT}" | grep -q "civicrm_api3"; then
    ok "cklint lints with the CiviKitchen fallback standard"
else
    fail "cklint didn't report the legacy call (output: ${CKLINT_OUT:0:200})"
fi

# The sniffs' own unit tests ship with the standard (exact codes + line
# numbers per fixture, zero findings on the modern counterparts, the
# externalActions arming behavior).
if SNIFF_TESTS_OUT="$(phpunit --no-configuration /opt/civikitchen-coder/CiviKitchen/tests 2>&1)"; then
    ok "CiviKitchen sniff unit tests"
else
    fail "CiviKitchen sniff unit tests (${SNIFF_TESTS_OUT:0:300})"
fi

# ---------------------------------------------------------------------------
# 4. phpstan analyses a sample file
echo "== phpstan run =="
cat > "${WORKDIR}/typed.php" <<'PHP'
<?php
function add(int $a, int $b): int {
    return $a . $b;  // type error: returning string from int-typed function
}
PHP
cat > "${WORKDIR}/phpstan.neon" <<'NEON'
parameters:
    level: 5
    paths:
        - typed.php
NEON

PHPSTAN_OUT="$(phpstan analyse -c "${WORKDIR}/phpstan.neon" --no-progress 2>&1 || true)"
if echo "${PHPSTAN_OUT}" | grep -qiE "should return int|error"; then
    ok "phpstan reports the typed-return error"
else
    fail "phpstan didn't catch the type error (output: ${PHPSTAN_OUT:0:200})"
fi

# ---------------------------------------------------------------------------
# 5. phpunit runs a passing test
echo "== phpunit run =="
mkdir -p "${WORKDIR}/tests"
cat > "${WORKDIR}/tests/SmokeTest.php" <<'PHP'
<?php
use PHPUnit\Framework\TestCase;
class SmokeTest extends TestCase {
    public function testItPasses(): void {
        $this->assertSame(4, 2 + 2);
    }
}
PHP
cat > "${WORKDIR}/phpunit.xml" <<'XML'
<?xml version="1.0"?>
<phpunit colors="false" bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="smoke">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML
# phpunit doesn't strictly need bootstrap if we pass tests/ directly.
if (cd "${WORKDIR}" && phpunit --no-configuration tests/SmokeTest.php >/dev/null 2>&1); then
    ok "phpunit runs a passing test"
else
    fail "phpunit failed on a trivial passing test"
fi

# ---------------------------------------------------------------------------
# 6. composer can install a real package
echo "== composer install =="
mkdir -p "${WORKDIR}/composer_test"
cat > "${WORKDIR}/composer_test/composer.json" <<'JSON'
{
    "require": { "psr/log": "^3.0" }
}
JSON
if (cd "${WORKDIR}/composer_test" && \
    composer install --no-interaction --no-progress --quiet 2>&1) \
    && [[ -f "${WORKDIR}/composer_test/vendor/autoload.php" ]]; then
    ok "composer install (psr/log)"
else
    fail "composer install failed"
fi

# ---------------------------------------------------------------------------
# 7. civix renders help (proves the phar boots and core registers commands)
echo "== civix =="
if civix list 2>&1 | grep -q "generate:module"; then
    ok "civix list includes generate:module"
else
    fail "civix list didn't include generate:module"
fi

# ---------------------------------------------------------------------------
# 8. Xdebug toggle
# pcov should always be loaded; xdebug should only load when XDEBUG_MODE is set
# via the entrypoint.
echo "== xdebug toggle =="
if php -m | grep -qiE "^pcov$"; then ok "pcov enabled by default"; else fail "pcov not enabled"; fi
if php -m | grep -qiE "^xdebug$"; then
    fail "xdebug enabled by default (should be off until XDEBUG_MODE is set)"
else
    ok "xdebug off by default"
fi

# Both images now use the same php:apache layout: writing xdebug.ini to
# /usr/local/etc/php/conf.d/ enables it on next php invocation. Simulate
# the entrypoint toggle inline (entrypoints would otherwise spawn apache /
# civibuild, which we don't want here).
XDEBUG_INI="/usr/local/etc/php/conf.d/xdebug.ini"
cat > "${XDEBUG_INI}" <<EOF
zend_extension=xdebug.so
xdebug.mode=develop
EOF
if php -m 2>&1 | grep -qi "^xdebug$"; then
    ok "XDEBUG_MODE enables xdebug"
else
    fail "XDEBUG_MODE didn't enable xdebug"
fi
rm -f "${XDEBUG_INI}"

# ---------------------------------------------------------------------------
echo
echo "${PASS} passed, ${FAIL} failed"
if [[ "${FAIL}" -gt 0 ]]; then
    printf '  - %s\n' "${FAILURES[@]}"
    exit 1
fi
