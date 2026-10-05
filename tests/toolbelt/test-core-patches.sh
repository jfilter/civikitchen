#!/usr/bin/env bash
# apply-core-patches.sh over a fixture core tree: a patch applies once, a
# contained one is skipped, a mismatch or a patch without upstream link fails.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
apply="$root/docker/standalone/apply-core-patches.sh"

for shipped in "$root"/docker/standalone/core-patches/*.patch; do
  grep -q '^Upstream: https://' "$shipped" || fail "$(basename "$shipped") names no Upstream: link"
done

mkdir -p "$work/core/CRM" "$work/patches" "$work/bad"
printf 'one\nkeep\n' > "$work/core/CRM/A.php"
cat > "$work/patches/10-change.patch" <<'EOF'
Fixture change.

Upstream: https://example.org/issue/1

--- a/CRM/A.php
+++ b/CRM/A.php
@@ -1,2 +1,2 @@
-one
+two
 keep
EOF

out=$(bash "$apply" "$work/core" "$work/patches") || fail "a fitting patch was rejected"
[ "$out" = "applied 10-change.patch" ] || fail "expected 'applied', got: $out"
[ "$(head -1 "$work/core/CRM/A.php")" = "two" ] || fail "the patch was not applied"

out=$(bash "$apply" "$work/core" "$work/patches") || fail "a contained patch was rejected"
[ "$out" = "contained 10-change.patch" ] || fail "expected 'contained', got: $out"
[ "$(head -1 "$work/core/CRM/A.php")" = "two" ] || fail "a contained patch changed the file"

sed 's/^-one$/-three/; s/^+two$/+four/' "$work/patches/10-change.patch" > "$work/bad/20-stale.patch"
out=$(bash "$apply" "$work/core" "$work/bad" 2>&1) && fail "a stale patch passed"
grep -q '20-stale.patch neither applies' <<<"$out" || fail "stale patch not named: $out"

grep -v '^Upstream:' "$work/patches/10-change.patch" > "$work/bad/20-stale.patch"
out=$(bash "$apply" "$work/core" "$work/bad" 2>&1) && fail "a patch without upstream link passed"
grep -q 'names no Upstream: link' <<<"$out" || fail "missing link not named: $out"

mkdir "$work/empty"
out=$(bash "$apply" "$work/core" "$work/empty") || fail "an empty patch directory failed"
[ -z "$out" ] || fail "an empty patch directory printed: $out"

echo "core patches: OK"
