#!/bin/bash
# Functional test for civikitchen dev images.
#
# Exercises every bundled dev tool against real input — not just --version
# checks. Run inside a built dev image, e.g.:
#
#   docker run --rm -v "$(pwd)/tests/images:/civikitchen-test" \
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
#   3g. ckcompat rejects syntax the declared PHP floor cannot parse
#   4. phpstan actually analyses a sample file (intentionally with type errors)
#   5. phpunit actually runs a passing assertion
#   5b. ckphpunit injects the transaction canary listener, after CiviTestListener
#   5c. cklifecycle's guards (the cycle itself needs a booted site)
#   5d. ckmutate: red on a surviving mutant with a floor, no-op without one
#   5e. cktaint: twelve source/sink fixture pairs — the flow is reported, the
#       escaped twin stays silent
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
for bin in composer node npm civix phpunit phpstan phpcs phpcbf cv infection; do
    if command -v "${bin}" >/dev/null 2>&1 && "${bin}" --version >/dev/null 2>&1; then
        ok "${bin} --version"
    else
        fail "${bin} --version"
    fi
done

# ---------------------------------------------------------------------------
# 1b. Standalone base parity. The image stopped building FROM civicrm/civicrm
#     and mirrors its base itself; these pin the parity points a consumer
#     would notice — PHP extensions, gd's WebP support, the ini values, and
#     the boot stub patch-test-db-boot.php anchors on.
if [ "${CIVICRM_UF:-}" = "Standalone" ]; then
    echo "== standalone base parity =="
    for ext in imagick soap opcache gd intl mysqli pdo_mysql zip bcmath pcov; do
        name="${ext}"
        [ "${ext}" = opcache ] && name="Zend OPcache"
        if php -m | grep -qix -- "${name}"; then
            ok "php extension ${ext} loaded"
        else
            fail "php extension ${ext} loaded"
        fi
    done
    if php -r 'exit(empty(gd_info()["WebP Support"]) ? 1 : 0);'; then
        ok "gd built with WebP support"
    else
        fail "gd built with WebP support"
    fi
    if [ "$(php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("max_input_vars");')" = "64M 10000" ]; then
        ok "civicrm.ini values apply (upload_max_filesize, max_input_vars)"
    else
        fail "civicrm.ini values apply — got '$(php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("max_input_vars");')'"
    fi
    if grep -q 'bootSettings' /var/www/html/civicrm.standalone.php; then
        ok "civicrm.standalone.php carries the bootSettings anchor (patch-test-db-boot)"
    else
        fail "civicrm.standalone.php carries the bootSettings anchor (patch-test-db-boot)"
    fi
fi

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
# NOTE: the banned CiviCRM call sites (civicrm_api3, CRM_Core_Error::debug_*)
# are NOT asserted here any more — they left this standard for phpstan
# (civicrm-disallowed.neon) and phpat, which resolve types instead of matching
# tokens. See the ruleset description.
if echo "${CK_OUT}" | grep -q "DeclareStrictTypesMissing\|Missing declare(strict_types"; then
    ok "CiviKitchen DeclareStrictTypes (Slevomat) flags the missing declare"
else
    fail "Slevomat DeclareStrictTypes didn't fire — is slevomat installed? (output: ${CK_OUT:0:200})"
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
if echo "${CKLINT_OUT}" | grep -qi "translation domain"; then
    ok "cklint lints with the CiviKitchen fallback standard"
else
    fail "cklint didn't report the bare ts() (output: ${CKLINT_OUT:0:200})"
fi

# The declare spacing has to be the SAME shape in the sniff and in ckfmt, or
# every file in the fleet is unfixable: Slevomat and Drupal.WhiteSpace.
# OperatorSpacing would each demand what the other forbids. Both spellings are
# asserted so a Slevomat bump that changes the default cannot pass silently.
cat > "${WORKDIR}/DeclSpaced.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Acme;

/**
 * A widget.
 */
class Widget {

  /**
   * Go.
   *
   * @return int
   *   The number.
   */
  public function go(): int {
    return 1;
  }

}
PHP
sed 's/strict_types = 1/strict_types=1/' "${WORKDIR}/DeclSpaced.php" > "${WORKDIR}/DeclTight.php"
if phpcs --standard=CiviKitchen --extensions=php "${WORKDIR}/DeclSpaced.php" >/dev/null 2>&1; then
    ok "declare(strict_types = 1) is clean under the CiviKitchen standard"
else
    DECL_OUT="$(phpcs --standard=CiviKitchen --extensions=php "${WORKDIR}/DeclSpaced.php" 2>&1 || true)"
    fail "CiviKitchen rejects the formatter's declare spelling (output: ${DECL_OUT:0:300})"
fi
if phpcs --standard=CiviKitchen --extensions=php "${WORKDIR}/DeclTight.php" >/dev/null 2>&1; then
    fail "CiviKitchen accepts declare(strict_types=1) — it must match ckfmt's spacing"
else
    ok "CiviKitchen rejects the unspaced declare (ckfmt owns the spelling)"
fi

# Same contract for nested array literals, the other shape the two gates can
# disagree about: ckfmt writes the first key on the opening line, and
# Squiz.Arrays.ArrayDeclaration wants it on its own. A repo that runs both
# would have no way to be green, so the sniff yields — layout is the
# formatter's.
cat > "${WORKDIR}/ArrayShape.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Acme;

/**
 * A widget.
 */
class Widget {

  /**
   * Go.
   *
   * @return array
   *   The rows.
   */
  public function go(): array {
    return [
      'rows' => ['a:1' => [
        'id' => 1,
        'label' => 'one',
      ]],
    ];
  }

}
PHP
if phpcs --standard=CiviKitchen --extensions=php "${WORKDIR}/ArrayShape.php" >/dev/null 2>&1; then
    ok "CiviKitchen accepts ckfmt's nested-array layout"
else
    ARR_OUT="$(phpcs --standard=CiviKitchen --extensions=php "${WORKDIR}/ArrayShape.php" 2>&1 || true)"
    fail "CiviKitchen rejects the formatter's array layout (output: ${ARR_OUT:0:300})"
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

declare(strict_types = 1);

function acme_probe(): void {
  acme_helper('x', TRUE);
}
PHP
cat > "${WARNDIR}/Err.php" <<'PHP'
<?php

declare(strict_types = 1);

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

declare(strict_types = 1);

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

declare(strict_types = 1);

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
    { echo "<?php"; echo; echo "declare(strict_types = 1);"; echo;
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
# mago prints the rule code and the file path on separate lines, and the phpcs
# stage names both fixtures in the same output — so look only at the lines
# belonging to the too-many-methods diagnostic itself.
TMM_CTX="$(echo "${MAGO_OUT3}" | grep -A3 'too-many-methods' || true)"
if echo "${TMM_CTX}" | grep -q "BigProd.php"; then
    ok "cklint mago stage flags too-many-methods in production code"
else
    fail "too-many-methods didn't fire outside tests/ (output: ${MAGO_OUT3:0:300})"
fi
if echo "${TMM_CTX}" | grep -q "BigTest.php"; then
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
# 3d-bis. ckcommon.sh's readers parse XML as XML.
#
# Regression fixtures for two inputs that a tag-shaped regex gets wrong, and
# that three tools plus one CI step used to read that way. Both are legal XML
# and both were silently wrong rather than loud:
#   - a single-quoted attribute yielded an EMPTY key, so the tool aborted
#   - a commented-out root element won over the real one, so the tool ran under
#     the wrong extension name
# Tested against the shared helper because it is now the only implementation.
echo "== ckcommon xml reader =="
# shellcheck source=/dev/null
. /usr/local/lib/ckcommon.sh

XMLDIR="${WORKDIR}/xmlread"
mkdir -p "${XMLDIR}"

printf '%s\n' "<extension key='org.example.single' type='module'><file>single</file></extension>" \
    > "${XMLDIR}/single-quoted.xml"
if [ "$(ck_xml_field "${XMLDIR}/single-quoted.xml" key)" = "org.example.single" ]; then
    ok "ck_xml_field reads a single-quoted key attribute"
else
    fail "ck_xml_field returned '$(ck_xml_field "${XMLDIR}/single-quoted.xml" key)' for a single-quoted key"
fi

{
    printf '%s\n' '<?xml version="1.0"?>'
    printf '%s\n' '<!-- <extension key="org.example.decoy" type="module"> -->'
    printf '%s\n' '<extension key="org.example.real" type="module"><file>real</file></extension>'
} > "${XMLDIR}/commented-decoy.xml"
if [ "$(ck_xml_field "${XMLDIR}/commented-decoy.xml" key)" = "org.example.real" ]; then
    ok "ck_xml_field ignores a commented-out root element"
else
    fail "ck_xml_field returned '$(ck_xml_field "${XMLDIR}/commented-decoy.xml" key)' instead of the real key"
fi

# A child element, which is how <file>, <version> and core's <version_no> are read.
if [ "$(ck_xml_field "${XMLDIR}/commented-decoy.xml" file)" = "real" ]; then
    ok "ck_xml_field reads a child element"
else
    fail "ck_xml_field did not read <file>"
fi

# Unparseable input must be loud: exit 2, not an empty string a caller treats
# as "the field is absent".
if ! printf 'not xml at all\n' > "${XMLDIR}/broken.xml" \
    || ck_xml_field "${XMLDIR}/broken.xml" key >/dev/null 2>&1; then
    fail "ck_xml_field accepted unparseable XML instead of failing"
else
    ok "ck_xml_field fails on unparseable XML"
fi

# ---------------------------------------------------------------------------
# 3e. ckeslint: the pinned oxlint toolchain is installed and every layer of the
#     baseline fires. Four findings on purpose, one per layer, because each can
#     go missing on its own without anything else looking wrong:
#       - oxlint's own `correctness` category (the native rules)
#       - no-unsanitized on .js AND on .ts — that plugin runs through oxlint's
#         alpha jsPlugins bridge, and if the bridge stops loading it, oxlint
#         still exits 0 over the same file
#       - a type-aware rule, which needs the tsgolint binary to be found
#     A silently empty gate is the failure mode this block exists to catch.
echo "== ckeslint =="
ESDIR="${WORKDIR}/eslintext"
mkdir -p "${ESDIR}/js"
cat > "${ESDIR}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="org.example.widget" type="module">
  <file>widget</file>
</extension>
XML
cat > "${ESDIR}/tsconfig.json" <<'JSON'
{
  "compilerOptions": {
    "strict": true,
    "noEmit": true,
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler"
  },
  "include": ["js/**/*.ts"]
}
JSON
cat > "${ESDIR}/js/widget.js" <<'JS'
function render(el, userInput) {
  var unused = 1;
  el.innerHTML = userInput;
}
JS
cat > "${ESDIR}/js/widget.ts" <<'TS'
export function render(el: HTMLElement, userInput: string): void {
  el.innerHTML = userInput;
}
async function work(): Promise<void> {}
export function boot(): void {
  work();
}
TS
# Node code outside the conventional *.config.js names: a build script and a
# Playwright spec. The baseline lints with the browser env, so without a node
# env for these paths every `require`/`process`/`__dirname` reads as no-undef —
# a gate that fires on correct code, which a repo can only silence by replacing
# the whole baseline.
mkdir -p "${ESDIR}/scripts" "${ESDIR}/tests/e2e"
cat > "${ESDIR}/scripts/build-widget.js" <<'NODEJS'
const path = require('path');
const out = path.join(__dirname, 'widget.js');
if (process.env.CK_DEBUG) {
  console.log(out);
}
NODEJS
cat > "${ESDIR}/tests/e2e/widget.spec.js" <<'NODEJS'
const { test } = require('@playwright/test');
test('boots', async ({ page }) => {
  await page.goto(process.env.CK_BASE_URL || 'http://localhost');
});
NODEJS
(cd "${ESDIR}" && git init -q . && git add -A) >/dev/null 2>&1

# --format=unix: oxlint's default reporter is the graphical one in some
# terminals and a one-liner in others, and these assertions name file AND rule.
ESLINT_OUT="$( (cd "${ESDIR}" && ckeslint --format=unix) 2>&1 || true)"
if echo "${ESLINT_OUT}" | grep -q "^js/widget.js:.*no-unsanitized(property)"; then
    ok "ckeslint flags an unsafe innerHTML assignment in .js"
else
    fail "ckeslint didn't flag innerHTML in .js (output: ${ESLINT_OUT:0:400})"
fi
if echo "${ESLINT_OUT}" | grep -q "^js/widget.ts:.*no-unsanitized(property)"; then
    ok "ckeslint flags an unsafe innerHTML assignment in .ts"
else
    fail "ckeslint didn't flag innerHTML in .ts (output: ${ESLINT_OUT:0:400})"
fi
if echo "${ESLINT_OUT}" | grep -q "no-unused-vars"; then
    ok "ckeslint applies oxlint's correctness rules"
else
    fail "ckeslint didn't report the unused variable (output: ${ESLINT_OUT:0:400})"
fi
if echo "${ESLINT_OUT}" | grep -q "no-floating-promises"; then
    ok "ckeslint runs the type-aware rules (tsgolint is wired up)"
else
    fail "ckeslint didn't report the floating promise (output: ${ESLINT_OUT:0:400})"
fi
if echo "${ESLINT_OUT}" | grep -E "^(scripts/build-widget|tests/e2e/widget.spec)\.js:.*no-undef"; then
    fail "ckeslint reported no-undef for Node globals outside *.config.js (output: ${ESLINT_OUT:0:400})"
else
    ok "ckeslint gives Node scripts and e2e specs the node env"
fi

# The type-aware rules are the half that can vanish quietly: oxlint reports the
# missing binary and exits non-zero, and the gate must not read that as a pass.
TSGO_OUT="$( (cd "${ESDIR}" && OXLINT_TSGOLINT_PATH=/nonexistent/tsgolint ckeslint --format=unix) 2>&1 || true)"
if (cd "${ESDIR}" && OXLINT_TSGOLINT_PATH=/nonexistent/tsgolint ckeslint --format=unix) >/dev/null 2>&1; then
    fail "ckeslint passed with an unusable tsgolint (output: ${TSGO_OUT:0:300})"
else
    ok "ckeslint fails when tsgolint is missing"
fi

# Without a root tsconfig there is no TypeScript program: every import is
# `any` and no-unsafe-* would bury the gate in false positives. The wrapper
# must pick the no-type-aware baseline then — while no-unsanitized stays live.
ESNOTS="${WORKDIR}/eslintnotsext"
mkdir -p "${ESNOTS}/js"
cp "${ESDIR}/info.xml" "${ESNOTS}/info.xml"
cat > "${ESNOTS}/js/loose.ts" <<'TS'
const v: any = JSON.parse('{}');
export const picked = v.thing;
export function render(el: HTMLElement, userInput: string): void {
  el.innerHTML = userInput;
}
TS
(cd "${ESNOTS}" && git init -q . && git add -A) >/dev/null 2>&1
NOTS_OUT="$( (cd "${ESNOTS}" && ckeslint --format=unix) 2>&1 || true)"
if echo "${NOTS_OUT}" | grep -q "no-unsafe"; then
    fail "ckeslint ran type-aware rules without a tsconfig (output: ${NOTS_OUT:0:300})"
else
    ok "ckeslint skips type-aware rules when the repo has no tsconfig"
fi
if echo "${NOTS_OUT}" | grep -q "no-unsanitized(property)"; then
    ok "ckeslint keeps no-unsanitized live without a tsconfig"
else
    fail "ckeslint lost no-unsanitized in the no-tsconfig baseline (output: ${NOTS_OUT:0:300})"
fi

# A repo that still ships only an eslint.config.* has to stop the gate loudly:
# ESLint is not in the image any more, and running the baseline over rules that
# repo never chose is the silent-wrong-answer case.
ESCFG="${WORKDIR}/eslintcfgext"
mkdir -p "${ESCFG}/js"
cp "${ESDIR}/info.xml" "${ESCFG}/info.xml"
cp "${ESDIR}/js/widget.js" "${ESCFG}/js/widget.js"
echo 'export default [];' > "${ESCFG}/eslint.config.mjs"
(cd "${ESCFG}" && git init -q . && git add -A) >/dev/null 2>&1
ESCFG_OUT="$( (cd "${ESCFG}" && ckeslint) 2>&1 || true)"
if (cd "${ESCFG}" && ckeslint) >/dev/null 2>&1; then
    fail "ckeslint passed a repo whose only config is an eslint.config.* (output: ${ESCFG_OUT:0:300})"
elif echo "${ESCFG_OUT}" | grep -q "the image gate is oxlint now"; then
    ok "ckeslint refuses a stale eslint.config.* with a pointer to the fix"
else
    fail "ckeslint's eslint.config.* refusal is unclear (output: ${ESCFG_OUT:0:300})"
fi

# ... and an .oxlintrc.json of the repo's own takes over from the baseline.
cat > "${ESCFG}/.oxlintrc.json" <<'JSON'
{ "rules": { "no-unused-vars": "off" } }
JSON
(cd "${ESCFG}" && git add -A) >/dev/null 2>&1
OWN_OUT="$( (cd "${ESCFG}" && ckeslint --format=unix) 2>&1 || true)"
if echo "${OWN_OUT}" | grep -q "own .oxlintrc.json"; then
    ok "ckeslint prefers the repo's own .oxlintrc.json"
else
    fail "ckeslint ignored the repo's .oxlintrc.json (output: ${OWN_OUT:0:300})"
fi

# CRM/cj/ts/_/angular must not read as undefined identifiers, or every real
# extension's frontend drowns in no-undef and the gate gets switched off.
cat > "${ESDIR}/js/globals.js" <<'JS'
export function boot() {
  return [CRM.url('civicrm/x'), cj('body'), ts('Hi'), _.size([]), angular.module('x')];
}
JS
(cd "${ESDIR}" && git add -A) >/dev/null 2>&1
GLOBALS_OUT="$( (cd "${ESDIR}" && ckeslint --format=unix js/globals.js) 2>&1 || true)"
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

# Node globals: an e2e suite reading process.env must not drown in
# no-unsafe-* just because the repo does not carry @types/node. The image's
# copy is linked in for the run — and must be gone again afterwards, because a
# stray node_modules/ in a checkout is exactly what the gate must not leave.
ESNODE="${WORKDIR}/eslintnodeext"
mkdir -p "${ESNODE}/e2e"
cp "${ESDIR}/info.xml" "${ESNODE}/info.xml"
cat > "${ESNODE}/tsconfig.json" <<'JSON'
{
  "compilerOptions": {
    "strict": true,
    "noEmit": true,
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "types": ["node"]
  },
  "include": ["e2e/**/*.ts"]
}
JSON
cat > "${ESNODE}/e2e/env.ts" <<'TS'
export function baseUrl(): string {
  const raw = process.env.WIDGET_BASE_URL;
  return raw ?? 'http://localhost';
}
TS
(cd "${ESNODE}" && git init -q . && git add -A) >/dev/null 2>&1
NODE_OUT="$( (cd "${ESNODE}" && ckeslint --format=unix) 2>&1 || true)"
if echo "${NODE_OUT}" | grep -q "no-unsafe"; then
    fail "ckeslint reports no-unsafe-* on process.env — the image's @types/node did not reach the program (output: ${NODE_OUT:0:400})"
else
    ok "ckeslint types process.env from the image's @types/node"
fi
if [ -e "${ESNODE}/node_modules" ]; then
    fail "ckeslint left a node_modules/ behind in the repo"
else
    ok "ckeslint removes its @types/node overlay again"
fi

# A repo that carries its own @types/node keeps it: the overlay must not
# overwrite it, and two copies in one program is the conflict to avoid.
ESOWNNODE="${WORKDIR}/eslintownnodeext"
mkdir -p "${ESOWNNODE}/e2e/../node_modules/@types"
cp "${ESNODE}/info.xml" "${ESOWNNODE}/info.xml"
cp "${ESNODE}/tsconfig.json" "${ESOWNNODE}/tsconfig.json"
cp "${ESNODE}/e2e/env.ts" "${ESOWNNODE}/e2e/env.ts"
cp -R /opt/civikitchen-oxlint/node_modules/@types/node "${ESOWNNODE}/node_modules/@types/node"
(cd "${ESOWNNODE}" && git init -q . && git add -A) >/dev/null 2>&1
OWNNODE_OUT="$( (cd "${ESOWNNODE}" && ckeslint --format=unix) 2>&1 || true)"
if echo "${OWNNODE_OUT}" | grep -q "no-unsafe"; then
    fail "ckeslint broke a repo that ships its own @types/node (output: ${OWNNODE_OUT:0:400})"
elif [ -L "${ESOWNNODE}/node_modules/@types/node" ]; then
    fail "ckeslint replaced the repo's own @types/node with its overlay"
else
    ok "ckeslint leaves a repo's own @types/node alone"
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

# The same agreement property on the constructs where the two gates have
# actually collided: whatever ckfmt does with an overlong line, phpcs must
# accept. Each fixture is its own file so a regression names the construct.
AGREEDIR="${WORKDIR}/fmtagreeext"
mkdir -p "${AGREEDIR}"
cp "${FMTDIR}/info.xml" "${AGREEDIR}/info.xml"
cat > "${AGREEDIR}/extends.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Civi\Acme\Widget;

class AcmeWidgetSubscriptionRenewalNotificationDispatchHandler extends AbstractAcmeWidgetSubscriptionRenewalNotificationHandlerBase {

  public function handle(): void {
  }

}
PHP
cat > "${AGREEDIR}/extendsimplements.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Civi\Acme\Widget;

class AcmeWidgetSubscriptionRenewalDispatchHandler extends AbstractAcmeWidgetSubscriptionRenewalNotificationHandlerBase implements AcmeWidgetNotificationInterface {

  public function handle(): void {
  }

}
PHP
cat > "${AGREEDIR}/implements.php" <<'PHP'
<?php

declare(strict_types = 1);

namespace Civi\Acme\Widget;

class AcmeWidgetRenewalHandler implements AcmeWidgetNotificationInterface, AcmeWidgetSchedulableInterface, AcmeWidgetLoggableInterface {

  public function handle(): void {
  }

}
PHP
cat > "${AGREEDIR}/echoconcat.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_render(string $alpha, string $beta, string $gamma, string $delta): void {
  echo 'The widget ' . $alpha . ' has been renewed for ' . $beta . ' until ' . $gamma . ' and dispatched to ' . $delta . '.';
}
PHP
cat > "${AGREEDIR}/nestedarray.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_rows(): array {
  return [['id' => 1, 'label' => 'one', 'description' => 'the first widget row'], ['id' => 2, 'label' => 'two', 'description' => 'the second widget row']];
}
PHP
# The conflict case: a multi-line inner array literal opening as `[[` — ckfmt
# keeps the shared brackets, Squiz's CloseBraceNewLine used to reject the `]]`.
cat > "${AGREEDIR}/nestedarrayliteral.php" <<'PHP'
<?php

declare(strict_types = 1);

function acme_grouped(): array {
  return [
    'groups' => [[
      'group_id' => 7,
      'group_title' => 'Newsletter',
      'candidates' => 12,
    ]],
  ];
}
PHP
mkdir -p "${AGREEDIR}/tests/phpunit"
cat > "${AGREEDIR}/tests/phpunit/bootstrap.php" <<'PHP'
<?php

if (!function_exists('cv')) {

  function cv(string $cmd) {
    return $cmd;
  }

}
PHP
(cd "${AGREEDIR}" && git init -q . && git add -A) >/dev/null 2>&1
if AGREE_FMT_OUT="$( (cd "${AGREEDIR}" && ckfmt) 2>&1 )"; then
    ok "ckfmt formats the overlong-line fixtures"
else
    fail "ckfmt failed on the overlong-line fixtures (output: ${AGREE_FMT_OUT:0:300})"
fi
for fixture in extends extendsimplements implements echoconcat nestedarray tests/phpunit/bootstrap; do
    if AGREE_OUT="$( (cd "${AGREEDIR}" && phpcs --standard=CiviKitchen --extensions=php -s "${fixture}.php") 2>&1 )"; then
        ok "ckfmt output for ${fixture}.php is clean under the CiviKitchen standard"
    else
        fail "phpcs rejects ckfmt output for ${fixture}.php (output: ${AGREE_OUT:0:400})"
    fi
done

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
# 3g. ckcompat: the declared PHP floor is checked by BOTH stages — mago's
#     parser at the floor version (categorical: syntax the floor cannot read)
#     and PHPCompatibility (version-specific APIs). The image runs a single PHP
#     8.4, so `php -l` is structurally blind to 8.4-only syntax; this is the
#     only gate that sees it.
echo "== ckcompat =="
CMPDIR="${WORKDIR}/compatext"
mkdir -p "${CMPDIR}/Civi"
echo '{"require":{"php":">=8.3"}}' > "${CMPDIR}/composer.json"
cat > "${CMPDIR}/Civi/Floor.php" <<'PHP'
<?php

declare(strict_types = 1);

class AcmeFloor {

  public function m(): int {
    return 1;
  }

}

function acme_floor_run(): int {
  return new AcmeFloor()->m();
}
PHP
CMP_OUT="$( (cd "${CMPDIR}" && ckcompat) 2>&1 || true)"
if (cd "${CMPDIR}" && ckcompat) >/dev/null 2>&1; then
    fail "ckcompat passed PHP-8.4-only syntax against an 8.3 floor (output: ${CMP_OUT:0:300})"
else
    ok "ckcompat rejects PHP-8.4-only syntax against the declared 8.3 floor"
fi
if echo "${CMP_OUT}" | grep -q "semantics"; then
    ok "ckcompat runs the mago floor-parse stage"
else
    fail "ckcompat's mago parse stage did not run (output: ${CMP_OUT:0:300})"
fi
# The same code with the floor it really needs must be silent, or the gate is
# just noise.
if (cd "${CMPDIR}" && ckcompat --php 8.4) >/dev/null 2>&1; then
    ok "ckcompat passes when the floor matches the syntax"
else
    CMP_OK_OUT="$( (cd "${CMPDIR}" && ckcompat --php 8.4) 2>&1 || true)"
    fail "ckcompat failed on code that matches its floor (output: ${CMP_OK_OUT:0:300})"
fi
# Property hooks are the case PHPCompatibility 10.0.0-alpha2 gets wrong twice
# over — it reports them as a removed curly-brace string offset, at ANY
# testVersion, and offers phpcbf a "fix". The parse stage is what actually
# names them, which is the whole reason it exists. Assert the verdict only.
cat > "${CMPDIR}/Civi/Hooked.php" <<'PHP'
<?php

declare(strict_types = 1);

class AcmeHooked {

  public string $n = '' {
    get => $this->n;
  }

}
PHP
if (cd "${CMPDIR}" && ckcompat) >/dev/null 2>&1; then
    fail "ckcompat passed PHP-8.4 property hooks against an 8.3 floor"
else
    ok "ckcompat rejects PHP-8.4 property hooks against the declared floor"
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
# 5b. ckphpunit injects the transaction canary into the repo's config.
#     The canary itself needs a booted site (the shared CI's PHPUnit step is
#     where it earns its keep); what is checkable here is the wiring — that the
#     listener is added, and added AFTER CiviTestListener, which is what puts
#     the marker inside the transaction and the check after the rollback.
echo "== ckphpunit =="
CKPU="${WORKDIR}/ckphpunit"
mkdir -p "${CKPU}/fake"
printf '<extension key="org.acme.widget" type="module"><file>widget</file></extension>\n' > "${CKPU}/info.xml"
cat > "${CKPU}/phpunit.xml.dist" <<'XML'
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php">
    <listeners>
        <listener class="Civi\Test\CiviTestListener"><arguments/></listener>
    </listeners>
</phpunit>
XML
# A stand-in for phpunit that only records the config it was handed: the real
# one would need a CiviCRM to boot.
cat > "${CKPU}/fake/phpunit" <<SH
#!/bin/bash
while [ "\$#" -gt 0 ]; do
    [ "\$1" = -c ] && { cp "\$2" "${CKPU}/handed.xml"; exit 0; }
    shift
done
exit 3
SH
chmod +x "${CKPU}/fake/phpunit"
(cd "${CKPU}" && PATH="${CKPU}/fake:${PATH}" ckphpunit tests >/dev/null 2>&1) || true
if [ -f "${CKPU}/handed.xml" ] && grep -q 'TransactionCanaryListener' "${CKPU}/handed.xml"; then
    ok "ckphpunit injects the transaction canary listener"
else
    fail "ckphpunit did not inject the canary listener"
fi
if [ -f "${CKPU}/handed.xml" ] \
    && [ "$(grep -n 'CiviTestListener' "${CKPU}/handed.xml" | cut -d: -f1)" -lt \
         "$(grep -n 'TransactionCanaryListener' "${CKPU}/handed.xml" | cut -d: -f1)" ]; then
    ok "the canary listener is registered after CiviTestListener"
else
    fail "canary listener ordering (it must come after CiviTestListener)"
fi
# The generated config lives in a temp dir, never in the (possibly read-only
# under CI's uid mismatch) repo — and its paths must survive the move.
if [ -z "$(find "${CKPU}" -maxdepth 1 -name '.ckphpunit-canary.xml' -o -name 'ckphpunit-canary-*' | head -1)" ]; then
    ok "ckphpunit writes nothing into the repo directory"
else
    fail "ckphpunit left a generated config in the repo"
fi
if [ -f "${CKPU}/handed.xml" ] && grep -q "bootstrap=\"${CKPU}/tests/bootstrap.php\"" "${CKPU}/handed.xml"; then
    ok "ckphpunit absolutizes the bootstrap path in the temp config"
else
    fail "ckphpunit did not absolutize the bootstrap path (config: $(grep -o 'bootstrap="[^"]*"' "${CKPU}/handed.xml" 2>/dev/null))"
fi
rm -f "${CKPU}/handed.xml"
(cd "${CKPU}" && PATH="${CKPU}/fake:${PATH}" CK_TX_CANARY=0 ckphpunit tests >/dev/null 2>&1) || true
if [ -f "${CKPU}/handed.xml" ]; then
    fail "CK_TX_CANARY=0 still generated a config"
else
    ok "CK_TX_CANARY=0 runs plain phpunit"
fi

# ---------------------------------------------------------------------------
# 5c. cklifecycle: the cycle itself needs a booted site, so only the guards are
#     checkable here.
echo "== cklifecycle =="
if cklifecycle --help 2>&1 | grep -q 'disable'; then
    ok "cklifecycle --help describes the cycle"
else
    fail "cklifecycle --help"
fi
if (cd "${WORKDIR}" && cklifecycle >/dev/null 2>&1); then
    fail "cklifecycle ran outside an extension root"
else
    ok "cklifecycle refuses to run without info.xml"
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
# 5d. ckmutate: the point of mutation testing in one fixture — a test that
#     COVERS a line without asserting enough to notice it changing. `$n > 10`
#     asserted only at n = 50 survives the GreaterThanOrEqual mutant, so a 100%
#     floor must go red. Without the .ckconform key the tool must be a no-op.
echo "== ckmutate =="
MUT="${WORKDIR}/ckmutate"
mkdir -p "${MUT}/Civi" "${MUT}/tests"
printf '<extension key="org.acme.widget" type="module"><file>widget</file></extension>\n' > "${MUT}/info.xml"
cat > "${MUT}/Civi/Greeter.php" <<'PHP'
<?php
namespace Civi;
class Greeter {
    public function level(int $n): string {
        return $n > 10 ? 'high' : 'low';
    }
}
PHP
cat > "${MUT}/tests/bootstrap.php" <<'PHP'
<?php
spl_autoload_register(function ($class) {
    $path = __DIR__ . '/../' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) { require $path; }
});
PHP
cat > "${MUT}/tests/GreeterTest.php" <<'PHP'
<?php
use PHPUnit\Framework\TestCase;
class GreeterTest extends TestCase {
    public function testHighLevel(): void {
        $this->assertSame('high', (new Civi\Greeter())->level(50));
    }
}
PHP
cat > "${MUT}/phpunit.xml.dist" <<'XML'
<?xml version="1.0"?>
<phpunit colors="false" bootstrap="tests/bootstrap.php">
    <testsuites><testsuite name="unit"><directory>tests</directory></testsuite></testsuites>
</phpunit>
XML
MUT_OUT="$( (cd "${MUT}" && ckmutate) 2>&1 || true)"
if (cd "${MUT}" && ckmutate) >/dev/null 2>&1 && echo "${MUT_OUT}" | grep -q 'no mutation_min_msi'; then
    ok "ckmutate is a no-op without mutation_min_msi in .ckconform"
else
    fail "ckmutate without a floor should pass and say so (output: ${MUT_OUT:0:300})"
fi

printf 'mutation_min_msi=100\nmutation_paths=Civi\n' > "${MUT}/.ckconform"
MUT_OUT="$( (cd "${MUT}" && ckmutate) 2>&1 || true)"
if (cd "${MUT}" && ckmutate) >/dev/null 2>&1; then
    fail "ckmutate passed a 100% floor although a mutant survives (output: ${MUT_OUT:0:300})"
else
    ok "ckmutate fails the floor when a mutant escapes the suite"
fi
if echo "${MUT_OUT}" | grep -q 'GreaterThan'; then
    ok "ckmutate names the escaped mutant"
else
    fail "ckmutate did not report the escaped GreaterThan mutant (output: ${MUT_OUT:0:300})"
fi
if [ -e "${MUT}/.ckmutate.json" ]; then
    fail "ckmutate left its generated infection config behind"
else
    ok "ckmutate removes its generated config"
fi

printf 'mutation_min_msi=50\nmutation_paths=Civi\n' > "${MUT}/.ckconform"
if (cd "${MUT}" && ckmutate) >/dev/null 2>&1; then
    ok "ckmutate passes a floor the suite meets"
else
    MUT_OK_OUT="$( (cd "${MUT}" && ckmutate) 2>&1 || true)"
    fail "ckmutate failed a floor it should meet (output: ${MUT_OK_OUT:0:300})"
fi

# ---------------------------------------------------------------------------
# 5e. cktaint: the stubs, proven by fixtures.
#
# Every case is a pair: a `_bad` file where request input reaches a sink and
# has to be reported, and a `_good` file with the escape (or the safe API) in
# between, which has to stay silent. A stub that stops matching core — a
# renamed method, a changed argument position — turns the pair red on the
# side that matters: the flow stops being found.
echo "== cktaint (Psalm taint analysis) =="
TAINT="$(mktemp -d)/taint"
mkdir -p "${TAINT}"

# --- PSR-7 request input (the house standard for HTTP endpoints) -> SQL
cat > "${TAINT}/01_psr7_sql_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f01(ServerRequestInterface $r): void {
  $q = $r->getQueryParams();
  CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_contact WHERE sort_name = '" . $q['name'] . "'");
}
PHP
cat > "${TAINT}/01_psr7_sql_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f01g(ServerRequestInterface $r): void {
  $q = $r->getQueryParams();
  $safe = CRM_Utils_Type::escape($q['name'], 'String');
  CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_contact WHERE sort_name = '" . $safe . "'");
}
PHP
# --- the request body (webhook payload) -> SQL
cat > "${TAINT}/02_body_sql_bad.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f02(RequestInterface $r): void {
  $body = (string) $r->getBody();
  CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_mailing WHERE name = '$body'");
}
PHP
cat > "${TAINT}/02_body_sql_good.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f02g(RequestInterface $r): void {
  $body = CRM_Core_DAO::escapeString((string) $r->getBody());
  CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_mailing WHERE name = '$body'");
}
PHP
# --- the request URI -> Location header
cat > "${TAINT}/03_uri_header_bad.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f03(RequestInterface $r): void {
  CRM_Utils_System::redirect($r->getUri()->getQuery());
}
PHP
cat > "${TAINT}/03_uri_header_good.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f03g(RequestInterface $r): void {
  CRM_Utils_System::redirect(CRM_Utils_String::munge($r->getUri()->getQuery()));
}
PHP
# --- a route attribute -> filesystem path
cat > "${TAINT}/04_attr_file_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f04(ServerRequestInterface $r): void {
  CRM_Utils_File::createDir('/tmp/x/' . $r->getAttribute('slug'));
}
PHP
cat > "${TAINT}/04_attr_file_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f04g(ServerRequestInterface $r): void {
  CRM_Utils_File::createDir('/tmp/x/' . CRM_Utils_File::makeFileName((string) $r->getAttribute('slug')));
}
PHP
# --- an upload's client filename -> moveTo()
cat > "${TAINT}/05_upload_file_bad.php" <<'PHP'
<?php
use Psr\Http\Message\UploadedFileInterface;
function f05(UploadedFileInterface $u): void {
  $u->moveTo('/var/tmp/' . $u->getClientFilename());
}
PHP
cat > "${TAINT}/05_upload_file_good.php" <<'PHP'
<?php
use Psr\Http\Message\UploadedFileInterface;
function f05g(UploadedFileInterface $u): void {
  $u->moveTo('/var/tmp/' . CRM_Utils_File::makeFileName((string) $u->getClientFilename()));
}
PHP
# --- CRM_Utils_SQL_Select: raw fragment vs. the interpolation array
cat > "${TAINT}/06_sqlselect_bad.php" <<'PHP'
<?php
function f06(): void {
  $id = CRM_Utils_Request::retrieve('cid', 'String');
  CRM_Utils_SQL_Select::from('civicrm_contact')->where('id = ' . $id)->execute();
}
PHP
cat > "${TAINT}/06_sqlselect_good.php" <<'PHP'
<?php
function f06g(): void {
  $id = CRM_Utils_Request::retrieve('cid', 'String');
  CRM_Utils_SQL_Select::from('civicrm_contact')->where('id = #cid', ['cid' => $id])->execute();
}
PHP
# --- SSRF: Guzzle (the client Civi::httpClient() hands out)
cat > "${TAINT}/07_guzzle_ssrf_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f07(ServerRequestInterface $r): void {
  $client = new \GuzzleHttp\Client();
  $client->get((string) $r->getQueryParams()['url']);
}
PHP
cat > "${TAINT}/07_guzzle_ssrf_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f07g(ServerRequestInterface $r): void {
  $client = new \GuzzleHttp\Client();
  $client->get('https://api.example.org/v1/ping', ['json' => $r->getParsedBody()]);
}
PHP
# --- SSRF: CRM_Utils_HttpClient
cat > "${TAINT}/08_httpclient_ssrf_bad.php" <<'PHP'
<?php
function f08(): void {
  $url = CRM_Utils_Request::retrieveValue('target', 'String');
  (new CRM_Utils_HttpClient())->get($url);
}
PHP
cat > "${TAINT}/08_httpclient_ssrf_good.php" <<'PHP'
<?php
function f08g(): void {
  $token = CRM_Utils_Request::retrieveValue('token', 'String');
  (new CRM_Utils_HttpClient())->post('https://api.example.org/v1/ping', ['token' => $token]);
}
PHP
# --- a request header echoed back into a response header
cat > "${TAINT}/09_setheader_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f09(ServerRequestInterface $r): void {
  CRM_Utils_System::setHttpHeader('X-Trace', (string) $r->getHeaderLine('X-Request-Id'));
}
PHP
cat > "${TAINT}/09_setheader_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f09g(ServerRequestInterface $r): void {
  CRM_Utils_System::setHttpHeader('X-Trace', CRM_Utils_String::munge((string) $r->getHeaderLine('X-Request-Id')));
}
PHP
# --- a request-chosen filename -> Content-Disposition
cat > "${TAINT}/10_download_bad.php" <<'PHP'
<?php
function f10(): void {
  $name = CRM_Utils_Request::retrieveValue('file', 'String');
  $buf = 'x';
  CRM_Utils_System::download($name, 'text/plain', $buf);
}
PHP
cat > "${TAINT}/10_download_good.php" <<'PHP'
<?php
function f10g(): void {
  $name = CRM_Utils_String::munge((string) CRM_Utils_Request::retrieveValue('file', 'String'));
  $buf = 'x';
  CRM_Utils_System::download($name, 'text/plain', $buf);
}
PHP
# --- shell (Psalm's own sink, reached from a PSR-7 source)
cat > "${TAINT}/11_shell_bad.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f11(ServerRequestInterface $r): void {
  exec('convert ' . (string) $r->getQueryParams()['f']);
}
PHP
cat > "${TAINT}/11_shell_good.php" <<'PHP'
<?php
use Psr\Http\Message\ServerRequestInterface;
function f11g(ServerRequestInterface $r): void {
  exec('convert ' . escapeshellarg((string) $r->getQueryParams()['f']));
}
PHP
# --- file_get_contents on a request-controlled path
cat > "${TAINT}/12_fgc_bad.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f12(RequestInterface $r): void {
  echo strlen((string) file_get_contents((string) $r->getUri()->getPath()));
}
PHP
cat > "${TAINT}/12_fgc_good.php" <<'PHP'
<?php
use Psr\Http\Message\RequestInterface;
function f12g(RequestInterface $r): void {
  echo strlen((string) file_get_contents('/srv/data/' . CRM_Utils_File::makeFileName((string) $r->getUri()->getPath())));
}
PHP

# One run over the whole fixture set — Psalm reports one flow per source/sink
# pair, and every pair here is distinct, so all twelve findings show up
# together. --no-cache because the fixtures live in a fresh temp dir.
TAINT_RC=0
TAINT_OUT_RAW="$( (cd "${TAINT}" && cktaint --no-cache) 2>&1 )" || TAINT_RC=$?
TAINT_OUT="$(printf '%s' "${TAINT_OUT_RAW}" | sed -E 's/\x1b\[[0-9;]*m//g')"

# Blocking fixtures (TaintedSql & co.) are present, so the run must fail.
if [[ "${TAINT_RC}" -ne 0 ]]; then
    ok "cktaint exits non-zero with blocking findings present"
else
    fail "cktaint exited 0 despite blocking TaintedSql/Shell/SSRF fixtures"
fi

# A run restricted to an advisory-only fixture (header taint = INFO) must
# report the finding but keep exit 0 — that is the blocking/advisory split.
ADV_RC=0
ADV_OUT="$( (cd "${TAINT}" && cktaint --no-cache 03_uri_header_bad.php) 2>&1 )" || ADV_RC=$?
if [[ "${ADV_RC}" -eq 0 ]] && printf '%s' "${ADV_OUT}" | grep -q 'TaintedHeader'; then
    ok "cktaint reports advisory TaintedHeader without failing (exit 0)"
else
    fail "advisory-only run: expected TaintedHeader + exit 0, got exit ${ADV_RC}"
fi

# case-file -> the issue that must be reported for it
TAINT_CASES=(
    "01_psr7_sql_bad.php:TaintedSql:PSR-7 getQueryParams -> executeQuery"
    "02_body_sql_bad.php:TaintedSql:PSR-7 request body -> singleValueQuery"
    "03_uri_header_bad.php:TaintedHeader:request URI -> CRM_Utils_System::redirect"
    "04_attr_file_bad.php:TaintedFile:route attribute -> CRM_Utils_File::createDir"
    "05_upload_file_bad.php:TaintedFile:upload filename -> moveTo"
    "06_sqlselect_bad.php:TaintedSql:request input -> CRM_Utils_SQL_Select::where"
    "07_guzzle_ssrf_bad.php:TaintedSSRF:request input -> Guzzle client"
    "08_httpclient_ssrf_bad.php:TaintedSSRF:request input -> CRM_Utils_HttpClient"
    "09_setheader_bad.php:TaintedHeader:request header -> setHttpHeader"
    "10_download_bad.php:TaintedHeader:request input -> CRM_Utils_System::download"
    "11_shell_bad.php:TaintedShell:PSR-7 input -> exec"
    "12_fgc_bad.php:TaintedFile:PSR-7 input -> file_get_contents"
)
for case in "${TAINT_CASES[@]}"; do
    file="${case%%:*}"; rest="${case#*:}"; issue="${rest%%:*}"; label="${rest#*:}"
    if echo "${TAINT_OUT}" | grep -q "${issue}.*${file}"; then
        ok "cktaint reports ${issue}: ${label}"
    else
        fail "cktaint missed ${issue} in ${file} (${label})"
    fi
    good="${file/_bad.php/_good.php}"
    if echo "${TAINT_OUT}" | grep -q "${good}"; then
        fail "cktaint false positive: the escaped ${good} was reported"
    else
        ok "cktaint stays silent on the escaped ${good}"
    fi
done

# ---------------------------------------------------------------------------
echo
echo "${PASS} passed, ${FAIL} failed"
if [[ "${FAIL}" -gt 0 ]]; then
    printf '  - %s\n' "${FAILURES[@]}"
    exit 1
fi
