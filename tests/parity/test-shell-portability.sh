#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cat > "$work/portable.sh" <<'SH'
#!/usr/bin/env bash
output="${1}.tmp"
sed 's/old/new/' "$1" > "$output"
cat "$output" > "$1"
rm "$output"
SH
"$root/scripts/lint-shell-portability.sh" "$work/portable.sh" >/dev/null

single_quote_suffix="''"
double_quote_suffix='""'
for fixture in single double; do
  if [ "$fixture" = single ]; then
    suffix="$single_quote_suffix"
  else
    suffix="$double_quote_suffix"
  fi
  printf '%s\n' \
    '#!/usr/bin/env bash' \
    "sed -i $suffix 's/old/new/' \"\$1\"" \
    > "$work/bsd-only-${fixture}.sh"
  if "$root/scripts/lint-shell-portability.sh" "$work/bsd-only-${fixture}.sh" >"$work/out" 2>&1; then
    echo "BSD-only sed in-place syntax with ${fixture} quotes was accepted" >&2
    exit 1
  fi
  grep -q "bsd-only-${fixture}.sh:2:" "$work/out"
  grep -q 'BSD-only sed -i' "$work/out"
done

echo "shell portability lint: ok"
