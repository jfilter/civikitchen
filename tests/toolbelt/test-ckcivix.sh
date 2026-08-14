#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext"

cat > "$work/bin/civix" <<'FAKE'
#!/usr/bin/env bash
# phar fixture strings: upgrades/25.01.1.up.php upgrades/25.10.2.up.php
printf '%s\n' "$*" >> "$CIVIX_LOG"
FAKE
chmod +x "$work/bin/civix"
export PATH="$work/bin:$PATH"
export CIVIX_LOG="$work/civix.log"

write_info() {
  local format="$1"
  cat > "$work/ext/info.xml" <<EOF
<?xml version="1.0"?>
<extension key="fixture" type="module">
  <file>fixture</file>
  <civix><format>$format</format></civix>
</extension>
EOF
}

expect_failure() {
  local label="$1"
  shift
  if (cd "$work/ext" && "$@" > "$work/failure.out" 2>&1); then
    echo "$label unexpectedly succeeded" >&2
    exit 1
  fi
}

write_info 25.10.2
printf '%s\n' '<?php' > "$work/ext/fixture.civix.php"
out=$(cd "$work/ext" && "$root/toolbelt/bin/ckcivix" --check)
echo "$out" | grep -q 'format 25.10.2 (current)'

write_info 25.01.1
out=$(cd "$work/ext" && "$root/toolbelt/bin/ckcivix")
echo "$out" | grep -q 'format 25.01.1 is behind 25.10.2'
expect_failure "behind scaffold in check mode" "$root/toolbelt/bin/ckcivix" --check

write_info 26.01.0
expect_failure "scaffold ahead of image" "$root/toolbelt/bin/ckcivix" --check
grep -q 'AHEAD of this image' "$work/failure.out"

write_info 25.10.2
/bin/rm "$work/ext/fixture.civix.php"
expect_failure "format without scaffold file" "$root/toolbelt/bin/ckcivix" --check
grep -q 'INCONSISTENT' "$work/failure.out"

cat > "$work/ext/info.xml" <<'EOF'
<?xml version="1.0"?><extension key="fixture" type="module"><file>fixture</file></extension>
EOF
out=$(cd "$work/ext" && "$root/toolbelt/bin/ckcivix")
echo "$out" | grep -q 'no scaffold'
expect_failure "missing scaffold in check mode" "$root/toolbelt/bin/ckcivix" --check
expect_failure "updating missing scaffold" "$root/toolbelt/bin/ckcivix" --update

write_info 25.01.1
printf '%s\n' '<?php' > "$work/ext/fixture.civix.php"
(cd "$work/ext" && "$root/toolbelt/bin/ckcivix" --update)
grep -q '^upgrade -n$' "$CIVIX_LOG"

expect_failure "unknown argument" "$root/toolbelt/bin/ckcivix" --unknown
"$root/toolbelt/bin/ckcivix" --help >/dev/null
echo "ckcivix integration checks passed"
