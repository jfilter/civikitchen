#!/usr/bin/env bash
# ckeslint's APIv4 contract rule and --core mode, through the real command and
# the pinned oxlint toolchain. Each fixture marks its expected findings inline
# (`// expect: <text>`); the reported lines must equal the marked ones, so a
# healthy call that is flagged fails as loudly as a typo that is missed.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fixtures="$root/tests/toolbelt/fixtures/ckeslint-api4"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# `file:line: text` for every marker, and for every finding of the given rule.
expected() { (cd "$1" && grep -rn --include='*.js' '// expect: ' . | sed -E 's#^\./##; s#^([^:]+:[0-9]+):.*expect: (.*)$#\1: \2#' | sort); }
reported() { grep -E "$2" "$1" | sed -E 's#^([^:]+:[0-9]+):[0-9]+: (.*) \[.*$#\1: \2#' | sort; }

# Every marker's text must occur in the finding reported on its line.
compare() {
  local want="$1" got="$2" label="$3"
  [ "$(cut -d: -f1-2 <<<"$want")" = "$(cut -d: -f1-2 <<<"$got")" ] \
    || fail "$label: reported lines differ from the marked ones
--- marked
$want
--- reported
$got"
  while IFS= read -r line; do
    local location="${line%%: *}" text="${line#*: }"
    grep -F "$location: " <<<"$got" | grep -qF "$text" || fail "$label: $location does not report '$text'"
  done <<<"$want"
}

# --- extension mode ---------------------------------------------------------
cp -R "$fixtures/ext" "$work/ext"
(cd "$work/ext" && git init -q . && git add -A && git -c user.email=ck@example.org -c user.name=ck commit -qm fixture)
status=0
(cd "$work/ext" && php "$root/toolbelt/bin/ckeslint" --format=unix) > "$work/ext.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "ckeslint should exit 1 on the fixture, got $status: $(cat "$work/ext.out")"
compare "$(expected "$work/ext")" "$(reported "$work/ext.out" 'civikitchen\(api4-contract\)')" extension

# Without the catalog the rule must not pass silently.
status=0
(cd "$work/ext" && env -u CIVIKITCHEN_API4_CATALOG "$root/toolbelt/oxlint/node_modules/.bin/oxlint" \
  -c "$root/toolbelt/oxlint/.oxlintrc-no-type-aware.json" js) > "$work/nocatalog.out" 2>&1 || status=$?
[ "$status" != 0 ] || fail "the rule passed without CIVIKITCHEN_API4_CATALOG: $(cat "$work/nocatalog.out")"
grep -q 'CIVIKITCHEN_API4_CATALOG is not set' "$work/nocatalog.out" \
  || fail "the missing catalog is not named: $(cat "$work/nocatalog.out")"

# --- core mode --------------------------------------------------------------
cp -R "$fixtures/core" "$work/core"
# Vendored, minified and test JS are not core's own code; each would fail.
mkdir -p "$work/core/ext/widget/node_modules/lib" "$work/core/tests/karma"
for file in ext/widget/node_modules/lib/index.js js/app.min.js tests/karma/widget.js; do
  echo "var x = 1; x.nope(); CRM.api4('Contatc', 'get', {});" > "$work/core/$file"
done
status=0
php "$root/toolbelt/bin/ckeslint" --core --format=unix "$work/core" > "$work/core.out" 2>&1 || status=$?
[ "$status" = 1 ] || fail "ckeslint --core should exit 1 on the fixture, got $status: $(cat "$work/core.out")"
grep -q '^ckeslint --core: 2 JS file(s) from' "$work/core.out" || fail "--core did not select exactly core's own files: $(head -1 "$work/core.out")"
compare "$(expected "$work/core")" "$(reported "$work/core.out" '^[^ ]+:[0-9]+:[0-9]+: ')" core
[ -z "$(find "${TMPDIR:-/tmp}" -maxdepth 1 -name 'ckeslint-*' -newer "$work/core.out" 2>/dev/null)" ] \
  || fail "--core left its working copy or catalog behind"

echo "ok - ckeslint api4-contract and --core"
