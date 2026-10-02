#!/usr/bin/env bash
# ckcoretest --e2e runs only @group e2e against the dev site: no UnitTests UF and no
# TEST_DB_DSN gate. Without it the headless run keeps both. Fake phpunit records the call.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

core="$work/core"
mkdir -p "$core/xml" "$core/tests/phpunit" "$core/ext/afform/mock" "$work/bin"
echo '<?xml version="1.0"?><version><version_no>6.20.alpha1</version_no></version>' > "$core/xml/version.xml"
cat > "$work/bin/phpunit" <<'FAKE'
#!/usr/bin/env bash
printf 'dir=%s uf=%s args=%s\n' "$PWD" "${CIVICRM_UF:-}" "$*" > "$PHPUNIT_LOG"
FAKE
chmod +x "$work/bin/phpunit"
# The web user's home is $work/www, which has no ~/.cv.json.
printf '#!/bin/sh\necho "www-data:x:33:33::%s/www:/bin/sh"\n' "$work" > "$work/bin/getent"
chmod +x "$work/bin/getent"
export PATH="$work/bin:$PATH" PHPUNIT_LOG="$work/phpunit.log" CK_CORE_DIR="$core"

CIVICRM_UF=Standalone bash "$root/toolbelt/bin/ckcoretest" --e2e --ext afform/mock tests/phpunit/E2E >/dev/null
[[ "$(cat "$work/phpunit.log")" == "dir=$core/ext/afform/mock uf=Standalone args=--group e2e tests/phpunit/E2E" ]] \
  || fail "--e2e must run @group e2e from the extension with the site's UF: $(cat "$work/phpunit.log")"

/bin/rm -f "$work/phpunit.log"
if bash "$root/toolbelt/bin/ckcoretest" --ext afform/mock >/dev/null 2>"$work/err"; then
  fail "a headless run without TEST_DB_DSN must refuse"
fi
grep -q 'No TEST_DB_DSN' "$work/err" || fail "the refusal must name the missing TEST_DB_DSN: $(cat "$work/err")"
[[ ! -e "$work/phpunit.log" ]] || fail "a refused headless run must not start phpunit"

echo "ckcoretest e2e: ok"
