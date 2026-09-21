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
# Stands in for phpunit: writes clover metrics plus a junit log holding
# CK_FIXTURE_TESTS run and CK_FIXTURE_SKIPPED skipped cases, and exits 0 the way
# phpunit does on an empty or entirely skipped run. The LAST --log-junit wins,
# as in phpunit.
cat > "$work/bin/ckphpunit" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
clover=
junit=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --coverage-clover) shift; clover=$1 ;;
    --coverage-clover=*) clover=${1#*=} ;;
    --log-junit) shift; junit=$1 ;;
    --log-junit=*) junit=${1#*=} ;;
  esac
  shift
done
test -n "$clover"
test -n "$junit"
cat > "$clover" <<'XML'
<coverage><project><metrics statements="8" coveredstatements="6"/></project></coverage>
XML
{
  echo '<testsuites>'
  i=0
  while [ "$i" -lt "${CK_FIXTURE_TESTS:-3}" ]; do
    echo "  <testsuite name=\"fixture\"><testcase name=\"t$i\"/></testsuite>"
    i=$((i + 1))
  done
  i=0
  while [ "$i" -lt "${CK_FIXTURE_SKIPPED:-0}" ]; do
    echo "  <testsuite name=\"fixture\"><testcase name=\"s$i\"><skipped/></testcase></testsuite>"
    i=$((i + 1))
  done
  echo '</testsuites>'
} > "$junit"
exit 0
EOF
chmod +x "$work/bin/ckphpunit"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q '75.00% line coverage (6/8 statements)'
echo "$out" | grep -q 'reporting only'

echo 'ok   ckcoverage uses the shared PHP CLI and a unique temporary run log'

# A configured floor of 0 is a floor, not an unset key: the run must report it
# as met instead of falling back to "reporting only".
cat > "$work/ext/civikitchen.yaml" <<'EOF'
version: 1
policy:
  coverage:
    minimum: 0
EOF
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q 'floor 0% met'
if echo "$out" | grep -q 'reporting only'; then exit 1; fi

echo 'ok   ckcoverage reads policy.coverage.minimum 0 as a configured floor'

# phpunit exits 0 on "No tests executed!": the empty suite must fail on its own,
# whatever the percentage says.
status=0
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 \
  "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
echo "$out" | grep -q 'no test was executed'

echo 'ok   ckcoverage fails when no test was executed'

# A suite that skipped every test exits 0 too, and is not a passing suite; the
# message has to name the skipping rather than claim an empty suite.
status=0
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 CK_FIXTURE_SKIPPED=2 \
  "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
echo "$out" | grep -q 'every test was skipped'

# One test that ran among skipped ones is a run.
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=1 CK_FIXTURE_SKIPPED=2 \
  "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q 'line coverage'

echo 'ok   ckcoverage fails when every test was skipped and passes a mixed run'

# A caller's own --log-junit wins in phpunit, so the count must come from THAT
# file - in both spellings - and the file must survive the run.
caller="$work/caller-junit.xml"
for form in separate joined; do
  if [ "$form" = separate ]; then
    set -- --log-junit "$caller"
  else
    set -- "--log-junit=$caller"
  fi
  /bin/rm -f "$caller"
  status=0
  out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 \
    "$root/toolbelt/bin/ckcoverage" "$@" 2>&1) || status=$?
  test "$status" -ne 0
  echo "$out" | grep -q 'no test was executed'
  test -s "$caller"
  out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage" "$@")
  echo "$out" | grep -q 'line coverage'
  test -s "$caller"
done

echo 'ok   ckcoverage reads the caller-supplied --log-junit in both spellings'

# The same empty suite in a repo that declares tests optional is not an error,
# even though the phpunit config exists.
cat > "$work/ext/civikitchen.yaml" <<'EOF'
version: 1
policy:
  tests:
    mode: optional
    reason: fixture extension has no PHP
EOF
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 \
  "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q 'policy.tests declares tests optional'

out=$(cd "$work/ext" && PATH="$work/bin:$PATH" CK_FIXTURE_TESTS=0 CK_FIXTURE_SKIPPED=2 \
  "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q 'every test was skipped, and policy.tests'

echo 'ok   ckcoverage honours policy.tests=optional with a phpunit config present'

# A phpunit config without a <coverage> section is likewise nothing to measure
# rather than a failure once tests are declared optional.
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit/>
EOF
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q 'nothing to measure'
cat > "$work/ext/phpunit.xml.dist" <<'EOF'
<phpunit><coverage/></phpunit>
EOF

echo 'ok   ckcoverage skips a config without <coverage> when tests are optional'

# No phpunit config at all: policy.tests=optional must still be recognised
# once ckconform renders it with its mandatory reason, not just the bare word.
rm "$work/ext/phpunit.xml.dist"
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage")
echo "$out" | grep -q 'nothing to measure'

echo 'ok   ckcoverage accepts policy.tests=optional with its reason attached'

# No phpunit config and no opt-out: an unrelated policy value must not be
# mistaken for the declaration, and the command must still fail.
cat > "$work/ext/civikitchen.yaml" <<'EOF'
version: 1
policy:
  copyright: Fixture Inc.
EOF
status=0
out=$(cd "$work/ext" && PATH="$work/bin:$PATH" "$root/toolbelt/bin/ckcoverage" 2>&1) || status=$?
test "$status" -ne 0
echo "$out" | grep -q 'no phpunit config'

echo 'ok   ckcoverage still fails without phpunit config and no tests opt-out'
