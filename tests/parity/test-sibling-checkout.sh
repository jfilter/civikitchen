#!/usr/bin/env bash
# The private-deps sibling checkout: one directory per extension KEY, no
# collision between repos that share a basename, and nothing written outside
# the siblings it was asked for. `git` is stubbed — the script is the unit.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$root/.github/actions/private-deps/checkout-siblings.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
# The stub takes the clone's last two arguments (URL, target) and stamps an
# info.xml whose key comes from FAKE_KEYS ("owner/repo=key ..."). A repo absent
# from FAKE_KEYS gets no info.xml at all.
cat > "$work/bin/git" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
url="${*: -2:1}"
target="${*: -1}"
repo="${url#https://github.com/}"
mkdir -p "$target"
for pair in $FAKE_KEYS; do
  if [ "${pair%%=*}" = "$repo" ]; then
    printf '<extension key="%s" type="module"/>\n' "${pair#*=}" > "$target/info.xml"
  fi
done
SH
chmod +x "$work/bin/git"

fail() { echo "FAIL: $*" >&2; exit 1; }

# Runs the script in a fresh workspace; $out holds stdout+stderr, $ws the tree.
run() {
  ws="$work/ws-$1"
  shift
  mkdir -p "$ws"
  out="$ws/out"
  rc=0
  (
    cd "$ws"
    PATH="$work/bin:$PATH" \
    CK_SIBLING_REPO="$1" \
    CK_SIBLING_TOKEN=token \
    FAKE_KEYS="$2" \
    GITHUB_OUTPUT="$ws/github_output" \
      "$script"
  ) > "$out" 2>&1 || rc=$?
}

# Two siblings whose repo basenames are identical: the old basename-derived
# directory made the second clone overwrite the first.
run collision 'orgA/shared,orgB/shared' 'orgA/shared=de.example.alpha orgB/shared=de.example.beta'
[ "$rc" = 0 ] || fail "same-basename siblings rejected (rc=$rc): $(cat "$out")"
[ -f "$ws/.civikitchen-siblings/de.example.alpha/info.xml" ] \
  || fail "first sibling missing; got: $(ls -A "$ws/.civikitchen-siblings")"
[ -f "$ws/.civikitchen-siblings/de.example.beta/info.xml" ] \
  || fail "second sibling missing; got: $(ls -A "$ws/.civikitchen-siblings")"
grep -qx 'paths=.civikitchen-siblings/de.example.alpha .civikitchen-siblings/de.example.beta' "$ws/github_output" \
  || fail "paths output not the key directories: $(cat "$ws/github_output")"
leftovers=("$ws"/.civikitchen-siblings/.staging-*)
if [ -e "${leftovers[0]}" ]; then
  fail "staging directory left behind: ${leftovers[*]}"
fi

# A sibling named like the workflow's own helper checkouts must not be able to
# claim — and the old code, rm -rf — their directories.
for name in ci policy; do
  run "reserved-$name" "org/$name" "org/$name=$name"
  [ "$rc" = 1 ] || fail "reserved key '$name' accepted (rc=$rc)"
  grep -q "reserved" "$out" || fail "reserved key '$name': unclear message: $(cat "$out")"
done

# Two repos, one key: refuse rather than silently mount one of them twice.
run duplicate 'orgA/one,orgB/two' 'orgA/one=de.example.same orgB/two=de.example.same'
[ "$rc" = 1 ] || fail "duplicate extension key accepted (rc=$rc)"
grep -q "cannot share a key" "$out" || fail "duplicate key: unclear message: $(cat "$out")"

# A glob in an entry stays literal and is rejected by the owner/repo check,
# even when the workspace holds a path it would otherwise expand to.
mkdir -p "$work/ws-glob/orgA/shared"
run glob 'orgA/*' 'orgA/shared=de.example.alpha'
[ "$rc" = 1 ] || fail "glob entry accepted (rc=$rc): $(cat "$out")"
grep -q "invalid sibling_repo entry" "$out" || fail "glob entry: unclear message: $(cat "$out")"

# Not an extension.
run no-info 'org/plain' ''
[ "$rc" = 1 ] || fail "repo without info.xml accepted (rc=$rc)"
grep -q "not a CiviCRM extension" "$out" || fail "missing info.xml: unclear message: $(cat "$out")"

echo "sibling checkout suite OK"
