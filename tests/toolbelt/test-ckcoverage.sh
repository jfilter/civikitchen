#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
test -L "$root/toolbelt/bin/ckcoverage"
test "$(readlink "$root/toolbelt/bin/ckcoverage")" = ck
php -l "$root/toolbelt/lib/php/src/Cli/CoverageCommand.php" >/dev/null
"$root/toolbelt/bin/ckcoverage" --help >/dev/null 2>&1 || true

grep -q "tempnam(sys_get_temp_dir(), 'ckcoverage-run-')" \
  "$root/toolbelt/lib/php/src/Cli/CoverageCommand.php"

work=$(mktemp -d)
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext"
cat > "$work/ext/info.xml" <<'EOF'
<extension key="fixture" type="module"><file>fixture</file></extension>
EOF
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit><coverage/></phpunit>
EOF
cat > "$work/bin/ckphpunit" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
while [ "$#" -gt 0 ]; do
  if [ "$1" = --coverage-clover ]; then
    shift
    cat > "$1" <<'XML'
<coverage><project><metrics statements="8" coveredstatements="6"/></project></coverage>
XML
    exit 0
  fi
  shift
done
exit 2
EOF
chmod +x "$work/bin/ckphpunit"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q '75.00% line coverage (6/8 statements)'
echo "$out" | grep -q 'reporting only'

echo 'ok   ckcoverage uses the shared PHP CLI and a unique temporary run log'
