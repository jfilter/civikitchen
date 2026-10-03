#!/usr/bin/env bash
# ckmodernize's civix step: a failing civix stops the run, and convert-entity
# (which boots CiviCRM) runs only when EFv1 schema XML exists.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/civix" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CIVIX_LOG"
[ "$1" != "${CIVIX_FAIL:-}" ]
FAKE
chmod +x "$work/bin/civix"
export PATH="$work/bin:$PATH"
export CIVIX_LOG="$work/civix.log"

cat > "$work/ext/info.xml" <<'EOF'
<?xml version="1.0"?>
<extension key="fixture" type="module">
  <file>fixture</file>
  <civix><format>25.10.2</format></civix>
</extension>
EOF

run() { (cd "$work/ext" && bash "$root/toolbelt/bin/ckmodernize" --fix --no-rector) > "$work/out" 2>&1; }

: > "$CIVIX_LOG"
run || fail "clean run exited non-zero: $(cat "$work/out")"
grep -qx 'upgrade -n' "$CIVIX_LOG" || fail "civix upgrade did not run"
if grep -q '^convert-entity' "$CIVIX_LOG"; then fail "convert-entity ran without schema XML"; fi

mkdir -p "$work/ext/xml/schema/CRM/Fixture"
touch "$work/ext/xml/schema/CRM/Fixture/Thing.xml"
: > "$CIVIX_LOG"
run || fail "run with schema XML exited non-zero: $(cat "$work/out")"
grep -qx 'convert-entity -n' "$CIVIX_LOG" || fail "convert-entity did not run with schema XML"

: > "$CIVIX_LOG"
if CIVIX_FAIL=convert-entity run; then fail "a failing convert-entity was swallowed"; fi
grep -q 'convert-entity failed' "$work/out" || fail "no message for a failing convert-entity"

/bin/rm -r "$work/ext/xml"
: > "$CIVIX_LOG"
if CIVIX_FAIL=upgrade run; then fail "a failing civix upgrade was swallowed"; fi
grep -q 'civix upgrade failed' "$work/out" || fail "no message for a failing civix upgrade"

echo "ckmodernize civix step OK"
