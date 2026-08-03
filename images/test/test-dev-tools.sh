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
#   3c. ckmodernize boots rector and previews a CiviCRM footgun rewrite
#   3d. ckrelease builds a dist zip and rejects a mismatched tag
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
function myext_civicrm_managed(&$entities) {
  $entities = [];
}
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
if echo "${CK_OUT}" | grep -q "civicrm_managed"; then
    ok "CiviKitchen UseMixinsForStandardHooks flags legacy mixin hooks"
else
    fail "CiviKitchen UseMixinsForStandardHooks didn't flag legacy hook (output: ${CK_OUT:0:200})"
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

# Warning tier: a phpcs warning is reported but must not fail the gate, while
# an error still does. Scoped to a two-sniff project ruleset so the assertion
# is about cklint's exit code, not about the rest of the standard. No git init
# here, so the mago stage skips itself and only the phpcs stage is under test.
WARNDIR="${WORKDIR}/warnext"
mkdir -p "${WARNDIR}"
cat > "${WARNDIR}/phpcs.xml.dist" <<'XML'
<?xml version="1.0"?>
<ruleset name="WarnGate">
  <rule ref="CiviKitchen.Modern.NameBooleanArguments"/>
  <rule ref="CiviKitchen.I18n.UseExtensionTs"/>
  <file>.</file>
</ruleset>
XML
cat > "${WARNDIR}/Warn.php" <<'PHP'
<?php

declare(strict_types=1);

function acme_probe(): void {
  acme_helper('x', TRUE);
}
PHP
cat > "${WARNDIR}/Err.php" <<'PHP'
<?php

declare(strict_types=1);

function acme_label(): string {
  return ts('hello');
}
PHP
if WARN_OUT="$( (cd "${WARNDIR}" && cklint Warn.php) 2>&1 )"; then
    if echo "${WARN_OUT}" | grep -qi "warning"; then
        ok "cklint reports a phpcs warning and still exits 0"
    else
        fail "cklint exited 0 but swallowed the warning (output: ${WARN_OUT:0:300})"
    fi
else
    fail "cklint failed on a warning-only file (output: ${WARN_OUT:0:300})"
fi
if ERR_OUT="$( (cd "${WARNDIR}" && cklint Err.php) 2>&1 )"; then
    fail "cklint exited 0 on a phpcs error (output: ${ERR_OUT:0:300})"
else
    ok "cklint still fails on a phpcs error"
fi

# cklint's second engine: a mago bug-pattern rule fires, a house-idiom rule
# stays disabled by the bundled baseline, and @mago-expect suppresses.
MAGODIR="${WORKDIR}/magoext"
mkdir -p "${MAGODIR}"
cat > "${MAGODIR}/Mago.php" <<'PHP'
<?php

declare(strict_types=1);

function magoext_probe(array $params): bool {
  return !empty($params['id']) && @unlink('/tmp/nope');
}
PHP
(cd "${MAGODIR}" && git init -q . && git add -A) >/dev/null 2>&1
MAGO_OUT="$( (cd "${MAGODIR}" && cklint --all) 2>&1 || true)"
if echo "${MAGO_OUT}" | grep -q "no-error-control-operator"; then
    ok "cklint mago stage flags the error control operator"
else
    fail "cklint mago stage didn't flag @ (output: ${MAGO_OUT:0:300})"
fi
if echo "${MAGO_OUT}" | grep -q "no-empty"; then
    fail "cklint mago stage runs no-empty — the baseline should disable it"
else
    ok "cklint mago stage honors the baseline's disabled rules"
fi
cat > "${MAGODIR}/Mago.php" <<'PHP'
<?php

declare(strict_types=1);

function magoext_probe(array $params): bool {
  // @mago-expect lint:no-error-control-operator
  return !empty($params['id']) && @unlink('/tmp/nope');
}
PHP
MAGO_OUT2="$( (cd "${MAGODIR}" && cklint --all) 2>&1 || true)"
if echo "${MAGO_OUT2}" | grep -q "no-error-control-operator"; then
    fail "@mago-expect did not suppress the finding (output: ${MAGO_OUT2:0:300})"
else
    ok "cklint mago stage honors @mago-expect"
fi

# too-many-methods is path-scoped in the baseline: a signal for production
# classes, noise for a PHPUnit case. The per-rule `exclude` that does it is the
# only path-aware setting in the config, so a mago upgrade dropping it is worth
# catching here.
mago_methods_class() {
    { echo "<?php"; echo; echo "declare(strict_types=1);"; echo;
      echo "class $1 {";
      for i in $(seq 1 25); do echo "  public function m${i}(): void {}"; done
      echo "}"; } > "$2"
}
mkdir -p "${MAGODIR}/tests/phpunit" "${MAGODIR}/Civi"
rm -f "${MAGODIR}/Mago.php"
mago_methods_class BigProd "${MAGODIR}/Civi/BigProd.php"
mago_methods_class BigTest "${MAGODIR}/tests/phpunit/BigTest.php"
(cd "${MAGODIR}" && git add -A) >/dev/null 2>&1
MAGO_OUT3="$( (cd "${MAGODIR}" && cklint --all) 2>&1 || true)"
if echo "${MAGO_OUT3}" | grep -q "Civi/BigProd.php.*too-many-methods"; then
    ok "cklint mago stage flags too-many-methods in production code"
else
    fail "too-many-methods didn't fire outside tests/ (output: ${MAGO_OUT3:0:300})"
fi
if echo "${MAGO_OUT3}" | grep -q "tests/phpunit/BigTest.php.*too-many-methods"; then
    fail "too-many-methods fired under tests/ — the baseline excludes that path"
else
    ok "cklint mago stage excludes tests/ from too-many-methods"
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
# 3c. ckmodernize boots rector and previews a CiviCRM-specific rewrite
echo "== ckmodernize =="
cat > "${WORKDIR}/Modernize.php" <<'PHP'
<?php
function f(array $a) {
    return CRM_Utils_Array::value('k', $a, 'd');
}
PHP

if ckmodernize --help 2>&1 | grep -q "modernize a CiviCRM extension"; then
    ok "ckmodernize --help"
else
    fail "ckmodernize --help"
fi

CKMOD_OUT="$( (cd "${WORKDIR}" && ckmodernize Modernize.php) 2>&1 || true)"
if echo "${CKMOD_OUT}" | grep -q '\?\?'; then
    ok "ckmodernize previews CRM_Utils_Array::value rewrite"
else
    fail "ckmodernize did not preview the array-value rewrite (output: ${CKMOD_OUT:0:300})"
fi

# ---------------------------------------------------------------------------
# 3d. ckrelease builds a dist archive from a throwaway extension repo
echo "== ckrelease =="
RELDIR="${WORKDIR}/rel"
mkdir -p "${RELDIR}/tests/phpunit"
cat > "${RELDIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.greeter" type="module">
  <file>greeter</file>
  <version>1.3.0</version>
</extension>
XML
echo '<?php' > "${RELDIR}/greeter.php"
echo '<?php' > "${RELDIR}/tests/phpunit/GreeterTest.php"
echo 'x' > "${RELDIR}/phpunit.xml.dist"
(
    cd "${RELDIR}"
    git init -q .
    git add -A
    git -c user.email=test@example.org -c user.name=test commit -qm init
) >/dev/null 2>&1

CKREL_OUT="$( (cd "${RELDIR}" && ckrelease dist --version v1.3.0) 2>&1 || true)"
if [ -f "${RELDIR}/.ckrelease/org.example.greeter-1.3.0.zip" ] \
    && [ -f "${RELDIR}/.ckrelease/org.example.greeter-1.3.0.zip.sha256" ]; then
    ok "ckrelease builds the dist zip + checksum"
else
    fail "ckrelease did not build the dist zip (output: ${CKREL_OUT:0:300})"
fi

# The whole point of the archive: dev/CI files stay out of what a site installs.
CKREL_LIST="$(unzip -Z1 "${RELDIR}/.ckrelease/org.example.greeter-1.3.0.zip" 2>/dev/null || true)"
if echo "${CKREL_LIST}" | grep -q 'org.example.greeter/greeter.php' \
    && ! echo "${CKREL_LIST}" | grep -qE 'tests/|phpunit.xml.dist'; then
    ok "ckrelease excludes dev/CI files from the archive"
else
    fail "ckrelease archive contents wrong (${CKREL_LIST:0:300})"
fi

# A tag that disagrees with info.xml is the failure this exists to catch.
if (cd "${RELDIR}" && ckrelease check --version v9.9.9) >/dev/null 2>&1; then
    fail "ckrelease accepted a tag that disagrees with info.xml"
else
    ok "ckrelease rejects a tag that disagrees with info.xml"
fi

# ---------------------------------------------------------------------------
# 3e. ckeslint: the pinned toolchain is installed and the baseline config fires.
#     Two findings on purpose — one from @eslint/js recommended and one from
#     no-unsanitized — because either could be missing on its own if the
#     toolchain install half-worked.
echo "== ckeslint =="
ESDIR="${WORKDIR}/eslintext"
mkdir -p "${ESDIR}/js"
cat > "${ESDIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.widget" type="module">
  <file>widget</file>
</extension>
XML
cat > "${ESDIR}/js/widget.js" <<'JS'
function render(el, userInput) {
  var unused = 1;
  el.innerHTML = '<b>' + userInput + '</b>';
}
JS
(cd "${ESDIR}" && git init -q . && git add -A) >/dev/null 2>&1

ESLINT_OUT="$( (cd "${ESDIR}" && ckeslint) 2>&1 || true)"
if echo "${ESLINT_OUT}" | grep -q "no-unsanitized/property"; then
    ok "ckeslint flags an unsafe innerHTML assignment"
else
    fail "ckeslint didn't flag innerHTML (output: ${ESLINT_OUT:0:300})"
fi
if echo "${ESLINT_OUT}" | grep -q "no-unused-vars"; then
    ok "ckeslint applies the @eslint/js recommended rules"
else
    fail "ckeslint didn't report the unused variable (output: ${ESLINT_OUT:0:300})"
fi

# CRM/cj/ts/_/angular must not read as undefined identifiers, or every real
# extension's frontend drowns in no-undef and the gate gets switched off.
cat > "${ESDIR}/js/globals.js" <<'JS'
export function boot() {
  return [CRM.url('civicrm/x'), cj('body'), ts('Hi'), _.size([]), angular.module('x')];
}
JS
(cd "${ESDIR}" && git add -A) >/dev/null 2>&1
GLOBALS_OUT="$( (cd "${ESDIR}" && ckeslint js/globals.js) 2>&1 || true)"
if echo "${GLOBALS_OUT}" | grep -q "no-undef"; then
    fail "ckeslint reports CiviCRM globals as undefined (output: ${GLOBALS_OUT:0:300})"
else
    ok "ckeslint knows the CiviCRM globals"
fi

# A repo with no JS at all must PASS, and say so — the common case in this
# fleet, and the one where a silent exit 0 is indistinguishable from a broken
# tool.
NOJS="${WORKDIR}/nojsext"
mkdir -p "${NOJS}"
cp "${ESDIR}/info.xml" "${NOJS}/info.xml"
(cd "${NOJS}" && git init -q . && git add -A) >/dev/null 2>&1
if NOJS_OUT="$( (cd "${NOJS}" && ckeslint) 2>&1 )" \
    && echo "${NOJS_OUT}" | grep -q "nothing to lint"; then
    ok "ckeslint passes with a log line when there is no JS"
else
    fail "ckeslint on a JS-free repo (output: ${NOJS_OUT:0:200})"
fi

# ---------------------------------------------------------------------------
# 3e2. ckfmt: both formatter halves fire, converge, and the mago output agrees
#      with the phpcs standard cklint enforces — the property the whole gate
#      rests on, so it is asserted here, not assumed.
echo "== ckfmt =="
FMTDIR="${WORKDIR}/fmtext"
mkdir -p "${FMTDIR}/js"
cat > "${FMTDIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.acme" type="module">
  <file>acme</file>
</extension>
XML
cat > "${FMTDIR}/acme.php" <<'PHP'
<?php
declare(strict_types = 1);

function acme_greet( string $name ){
    if($name===''){ return 'nobody'; }
  return "hi " . $name;
}
PHP
cat > "${FMTDIR}/js/acme.js" <<'JS'
const acme = {a:1,   b:2};
JS
(cd "${FMTDIR}" && git init -q . && git add -A) >/dev/null 2>&1

if (cd "${FMTDIR}" && ckfmt --check) >/dev/null 2>&1; then
    fail "ckfmt --check passed on unformatted PHP + JS"
else
    ok "ckfmt --check fails on unformatted code"
fi
if FMT_OUT="$( (cd "${FMTDIR}" && ckfmt && ckfmt --check) 2>&1 )"; then
    ok "ckfmt formats and then reports clean"
else
    fail "ckfmt did not converge (output: ${FMT_OUT:0:300})"
fi
# The agreement property: formatted output is clean under the full cklint
# gate — the phpcs standard AND the mago lint baseline.
if CKLINT_FMT_OUT="$( (cd "${FMTDIR}" && cklint --all) 2>&1 )"; then
    ok "ckfmt output is clean under the full cklint gate (phpcs + mago)"
else
    fail "cklint rejects ckfmt output (output: ${CKLINT_FMT_OUT:0:300})"
fi

# No formattable files at all must PASS, and say so.
NOFMT="${WORKDIR}/nofmtext"
mkdir -p "${NOFMT}"
cp "${FMTDIR}/info.xml" "${NOFMT}/info.xml"
(cd "${NOFMT}" && git init -q . && git add -A) >/dev/null 2>&1
if NOFMT_OUT="$( (cd "${NOFMT}" && ckfmt --check) 2>&1 )" \
    && echo "${NOFMT_OUT}" | grep -q "no PHP files"; then
    ok "ckfmt passes with a log line when there is nothing to format"
else
    fail "ckfmt on an empty repo (output: ${NOFMT_OUT:0:200})"
fi

# ---------------------------------------------------------------------------
# 3f. ckschemadiff: table discovery from both schema formats, and the
#     normalisation that makes two dumps of the same schema compare equal.
#     The database half is exercised by the shared CI's schema-parity job,
#     which is the only place two CiviCRM databases exist.
echo "== ckschemadiff =="
SCHDIR="${WORKDIR}/schemaext"
mkdir -p "${SCHDIR}/schema" "${SCHDIR}/xml/schema/CRM/Widget"
cp "${ESDIR}/info.xml" "${SCHDIR}/info.xml"
cat > "${SCHDIR}/schema/Widget.entityType.php" <<'PHP'
<?php
return ['name' => 'Widget', 'table' => 'civicrm_widget', 'getFields' => fn() => []];
PHP
cat > "${SCHDIR}/xml/schema/CRM/Widget/Gadget.xml" <<'XML'
<?xml version="1.0" encoding="iso-8859-1" ?>
<table>
  <name>civicrm_gadget</name>
  <field><name>id</name></field>
</table>
XML
SCH_TABLES="$( (cd "${SCHDIR}" && ckschemadiff tables) 2>&1 || true)"
if [ "${SCH_TABLES}" = "civicrm_gadget
civicrm_widget" ]; then
    ok "ckschemadiff reads tables from both schema formats"
else
    fail "ckschemadiff table discovery (output: ${SCH_TABLES:0:200})"
fi

printf 'CREATE TABLE `civicrm_widget` (\n  `id` int NOT NULL AUTO_INCREMENT\n) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4;\n' > "${WORKDIR}/schema-a.sql"
printf '/*!40101 SET @saved_cs_client = @@character_set_client */;\nCREATE TABLE `civicrm_widget` (\n  `id` int NOT NULL AUTO_INCREMENT\n) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;\n' > "${WORKDIR}/schema-b.sql"
ckschemadiff normalize < "${WORKDIR}/schema-a.sql" > "${WORKDIR}/schema-a.norm"
ckschemadiff normalize < "${WORKDIR}/schema-b.sql" > "${WORKDIR}/schema-b.norm"
if ckschemadiff diff "${WORKDIR}/schema-a.norm" "${WORKDIR}/schema-b.norm" >/dev/null 2>&1; then
    ok "ckschemadiff normalises away the AUTO_INCREMENT counter and dump wrappers"
else
    fail "ckschemadiff reported a difference between two dumps of the same schema"
fi

printf 'CREATE TABLE `civicrm_widget` (\n  `label` varchar(64) DEFAULT NULL\n) ENGINE=InnoDB;\n' \
    | ckschemadiff normalize > "${WORKDIR}/schema-c.norm"
if ckschemadiff diff "${WORKDIR}/schema-a.norm" "${WORKDIR}/schema-c.norm" >/dev/null 2>&1; then
    fail "ckschemadiff called two genuinely different schemas equal"
else
    ok "ckschemadiff reports a real schema difference"
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
# 4b. deprecation rules fire, and @ck-legacy exempts the scope that must call
#     a deprecated API (CiviKitchen's bundled deprecated-scope resolver).
echo "== phpstan deprecation rules + @ck-legacy =="
cat > "${WORKDIR}/plain-caller.php" <<'PHP'
<?php
/** @deprecated use Greeter */
class OldGreeter {
    public function greet(): string { return 'hi'; }
}
class PlainCaller {
    public function call(): string { return (new OldGreeter())->greet(); }
}
PHP
# Same code, only the marker differs — so a green run can only come from it.
cat > "${WORKDIR}/marked-caller.php" <<'PHP'
<?php
/** @deprecated use Greeter */
class OldGreeter {
    public function greet(): string { return 'hi'; }
}
/** @ck-legacy */
class PlainCaller {
    public function call(): string { return (new OldGreeter())->greet(); }
}
PHP

for fixture in plain-caller marked-caller; do
    cat > "${WORKDIR}/phpstan-${fixture}.neon" <<NEON
parameters:
    level: 5
    paths:
        - ${fixture}.php
NEON
done

DEPR_OUT="$(phpstan analyse -c "${WORKDIR}/phpstan-plain-caller.neon" --no-progress 2>&1 || true)"
if echo "${DEPR_OUT}" | grep -q "deprecatedClass"; then
    ok "phpstan reports the call into a deprecated class"
else
    fail "deprecation rules not active (output: ${DEPR_OUT:0:200})"
fi

MARKED_OUT="$(phpstan analyse -c "${WORKDIR}/phpstan-marked-caller.neon" --no-progress 2>&1 || true)"
if echo "${MARKED_OUT}" | grep -q "deprecatedClass"; then
    fail "@ck-legacy scope was still reported (output: ${MARKED_OUT:0:400})"
else
    ok "@ck-legacy exempts the scope from the deprecation rules"
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
