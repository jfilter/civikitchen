#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext" "$work/profile" "$work/repo"

printf '%s\n' '<?php' > "$work/repo/demo.php"
printf '%s\n' '<extension key="org.example.demo" type="module"><file>demo</file></extension>' \
  > "$work/repo/info.xml"
git -C "$work/repo" init -q
git -C "$work/repo" add demo.php info.xml
git -C "$work/repo" -c user.name=CiviKitchen -c user.email=ci@example.org commit -qm initial
commit=$(git -C "$work/repo" rev-parse HEAD)

cat > "$work/bin/cv" <<'SH'
#!/bin/bash
set -euo pipefail
case " $* " in
  *extensionsDir*) printf '%s' "$CK_FAKE_EXT_DIR" ;;
  *getFullContainer*) printf '%s' "${CK_FAKE_LOCAL_KEYS:-}" ;;
  *CIVICRM_UF*) printf 'Standalone' ;;
  *' ext:list '*'--statuses=installed,disabled'*)
    [ "${CK_FAKE_STATUS_QUERY_FAIL:-0}" != 1 ] || exit 1
    printf '%s\n' "${CK_FAKE_DB_INSTALLED:-}"
    ;;
  *' ext:list '*'--statuses=uninstalled'*)
    [ "${CK_FAKE_STATUS_QUERY_FAIL:-0}" != 1 ] || exit 1
    printf '%s\n' "${CK_FAKE_UNINSTALLED:-${CK_FAKE_LOCAL_KEYS:-}}"
    ;;
  *' ext:enable '*) [ "${CK_FAKE_FAIL_ENABLE:-0}" != 1 ] ;;
  *' ext:download '*) printf '%s\n' "$*" >> "${CK_FAKE_CV_LOG:?}" ;;
  *'--user=admin'*) printf 'ok' ;;
  *) : ;;
esac
SH
chmod +x "$work/bin/cv"

write_profile() {
  php -r '
    file_put_contents($argv[1], json_encode([
      "description" => "clone retry fixture",
      "dependencies" => [["name" => "org.example.demo", "repo" => $argv[2], "version" => $argv[3]]],
    ], JSON_THROW_ON_ERROR));
  ' "$work/profile/profile.json" "$work/repo" "$1"
}

write_profile does-not-exist
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone accepted a missing ref" >&2
  exit 1
fi
[ ! -e "$work/ext/org.example.demo" ] \
  || { echo "failed checkout left a discovery-visible extension directory" >&2; exit 1; }

write_profile "$commit"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
[ "$(git -C "$work/ext/org.example.demo" rev-parse HEAD)" = "$commit" ] \
  || { echo "profile clone did not install the requested commit" >&2; exit 1; }

# A retry at the same immutable ref is a verified no-op, not another clone.
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null

# A packaged extension is recognizable to CiviCRM but has no Git metadata.
# Profiles still converge it to their immutable Git commit, while an arbitrary
# leftover directory must not receive this privilege.
printf '%s\n' '<extension key="org.example.demo" type="module"><file>demo</file></extension>' \
  > "$work/ext/org.example.demo/info.xml"
rm -rf "$work/ext/org.example.demo/.git"
printf '%s\n' packaged > "$work/ext/org.example.demo/demo.php"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
[ "$(git -C "$work/ext/org.example.demo" rev-parse HEAD)" = "$commit" ] \
  || { echo "profile clone did not replace packaged code with the pinned commit" >&2; exit 1; }
[ "$(cat "$work/ext/org.example.demo/demo.php")" = '<?php' ] \
  || { echo "profile clone retained packaged code instead of the pinned commit" >&2; exit 1; }

# A failure after the filesystem swap restores disabled packaged code. Enabled
# packaged extensions are refused by apply.sh because DB upgrades are not
# safely reversible by swapping PHP files.
rm -rf "$work/ext/org.example.demo/.git"
printf '%s\n' packaged > "$work/ext/org.example.demo/demo.php"
php -r '
  $v=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $v["dependencies"][0]["enable"]=true;
  file_put_contents($argv[1], json_encode($v, JSON_THROW_ON_ERROR));
' "$work/profile/profile.json"
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_FAIL_ENABLE=1 CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone accepted a downstream enable failure" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.demo/demo.php")" = packaged ] && [ ! -d "$work/ext/org.example.demo/.git" ] \
  || { echo "downstream failure did not restore packaged extension" >&2; exit 1; }
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_DB_INSTALLED=org.example.demo CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone replaced an installed packaged extension without a reversible DB lifecycle" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.demo/demo.php")" = packaged ] && [ ! -d "$work/ext/org.example.demo/.git" ] \
  || { echo "installed packaged extension changed despite fail-closed refusal" >&2; exit 1; }
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_LOCAL_KEYS=org.example.demo \
  CK_FAKE_STATUS_QUERY_FAIL=1 CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone replaced packaged code after a failed lifecycle status query" >&2
  exit 1
fi
[ "$(cat "$work/ext/org.example.demo/demo.php")" = packaged ] && [ ! -d "$work/ext/org.example.demo/.git" ] \
  || { echo "packaged extension changed after failed lifecycle status query" >&2; exit 1; }

# Registry acquisition is download-only. `enable` is handled exclusively by
# the ordered enable phase, so absent/false keeps the extension disabled.
printf '%s\n' '{"description":"registry fetch only","dependencies":[{"name":"org.example.registry","registry":true}]}' \
  > "$work/profile/profile.json"
: > "$work/cv.log"
PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" CK_FAKE_CV_LOG="$work/cv.log" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null
grep -qx 'ext:download -n --no-install org.example.registry' "$work/cv.log" \
  || { echo "registry dependency was not fetched with --no-install" >&2; exit 1; }

write_profile "$commit"
php -r '
  $v=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $v["dependencies"][0]["name"]="org.example.wrong";
  file_put_contents($argv[1], json_encode($v, JSON_THROW_ON_ERROR));
' "$work/profile/profile.json"
if PATH="$work/bin:$PATH" CK_FAKE_EXT_DIR="$work/ext" \
  bash "$root/docker/profiles/apply.sh" "$work/profile" >/dev/null 2>&1; then
  echo "profile clone accepted a repository with the wrong extension key" >&2
  exit 1
fi
[ ! -e "$work/ext/org.example.wrong" ] \
  || { echo "wrong-key clone left a discovery-visible extension directory" >&2; exit 1; }

echo "profile clone tests passed"
