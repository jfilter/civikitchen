#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

"${root}/toolbelt/bin/ck" help | grep -q 'ck conform'
printf '%s\n' 'version: 1' 'policy:' '  coverage:' '    minimum: 73' > "${work}/civikitchen.yaml"
value=$(cd "${work}" && "${root}/toolbelt/bin/ck" conform --policy min_coverage)
[ "${value}" = 73 ] || { echo "ck conform did not dispatch (got: ${value})" >&2; exit 1; }
cat > "${work}/info.xml" <<'XML'
<?xml version="1.0"?>
<extension key="demo" type="module">
  <file>demo</file><name>Demo</name><license>Proprietary</license>
  <compatibility><ver>6.17</ver></compatibility>
</extension>
XML
(cd "${work}" && git init -q && git add .)
(
  cd "${work}"
  rc=0
  "${root}/toolbelt/bin/ck" conform --format=json > report.json || rc=$?
  [ "$rc" = 1 ]
)
php -r '
  $v = json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  if (($v["tool"] ?? NULL) !== "ckconform" || !isset($v["results"][0]["rule"])) exit(1);
' "${work}/report.json"
(
  cd "${work}"
  rc=0
  "${root}/toolbelt/bin/ck" conform --format=sarif > report.sarif || rc=$?
  [ "$rc" = 1 ]
)
php -r '
  $v = json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  if (($v["version"] ?? NULL) !== "2.1.0" || !isset($v["runs"][0]["tool"]["driver"])) exit(1);
' "${work}/report.sarif"
"${root}/toolbelt/bin/ck" profile validate "${root}/docker/profiles/mailing" >/dev/null
"${root}/toolbelt/bin/ckprofile" validate "${root}/docker/profiles/mailing" >/dev/null
profiles=$("${root}/toolbelt/bin/ck" profile list)
grep -q $'^mailing\t' <<<"${profiles}"
"${root}/toolbelt/bin/ckdeps" --help | grep -q 'ck dependencies'
for alias in ckcivix ckcompat ckconform ckcoverage ckdeps ckeslint ckfmt cklifecycle cklint ckmutate ckphpunit ckprofile ckrelease ckscenario ckschemadiff cksmarty; do
  [ -L "${root}/toolbelt/bin/${alias}" ] || { echo "${alias} is not a symlink" >&2; exit 1; }
  [ "$(readlink "${root}/toolbelt/bin/${alias}")" = ck ] || { echo "${alias} does not target ck" >&2; exit 1; }
done
if "${root}/toolbelt/bin/ck" no-such-command >/dev/null 2>&1; then
  echo "unknown ck command passed" >&2
  exit 1
fi

echo "ck dispatcher tests passed"
