#!/usr/bin/env bash
# The entrypoint resolves a mounted extension's info.xml <requires> before
# enabling it: a missing dependency is downloaded (pinned by extension_source
# in the extension's .ckconform, else from the registry) and enabled first.
# Fake cv records every call; a real ckconform reads the policy file.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext/fixture"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
# fake cv: records calls; ext:list serves $CV_LIST; a download adds its key to it.
printf '%s\n' "$*" >> "$CV_LOG"
case "$1" in
  api4)
    # Extension.get +w key=<key>: rows of $CV_LIST with that key; CV_LIST_FAILS breaks the query.
    [[ -n "${CV_LIST_FAILS:-}" ]] && exit 1
    key="${4#key=}"
    php -r '$l = json_decode(file_get_contents($argv[1]), TRUE); echo json_encode(array_values(array_filter((array) $l, fn($e) => ($e["key"] ?? "") === $argv[2])));' "$CV_LIST" "$key"
    ;;
  ext:download)
    spec="$4"; key="${spec%%@*}"
    [[ -n "${CV_DOWNLOAD_FAILS:-}" ]] && exit 1
    php -r '$l = json_decode(file_get_contents($argv[1]), TRUE); $l[] = ["key" => $argv[2], "status" => "uninstalled"]; file_put_contents($argv[1], json_encode($l));' "$CV_LIST" "$key"
    ;;
  ext:enable) ;;
esac
FAKE
chmod +x "$work/bin/cv"
export PATH="$work/bin:$root/toolbelt/bin:$PATH"
export CV_LOG="$work/cv.log" CV_LIST="$work/list.json"

ck_as_web() { "$@"; }
sleep() { :; }
export CK_EXT_DIR="$work/ext"
export CIVIKITCHEN_ENABLE_EXTENSIONS=fixture
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

write_info() {
  cat > "$work/ext/fixture/info.xml" <<INFO
<?xml version="1.0"?>
<extension key="fixture" type="module">
  <file>fixture</file>
  $1
</extension>
INFO
}
reset_site() {
  printf '%s' "$1" > "$CV_LIST"
  : > "$CV_LOG"
}
expect_log() {
  local expected="$1"
  local actual
  actual="$(grep -v '^api4 Extension.get' "$CV_LOG" | tr '\n' ';')"
  [[ "$actual" == "$expected" ]] || fail "$2 — expected '$expected', got '$actual'"
}

# A pinned dependency: downloaded from the pin, enabled, then the extension.
write_info '<requires><ext>org.example.dep</ext></requires>'
printf '%s\n' 'extension_source=org.example.dep@https://example.org/dep-1.0.zip -- not in the feed' > "$work/ext/fixture/.ckconform"
reset_site '[{"key":"civi_contribute","status":"installed"}]'
ck_enable_extensions
expect_log 'ext:download -n --no-install org.example.dep@https://example.org/dep-1.0.zip;ext:enable org.example.dep;ext:enable fixture;' 'pinned dependency'

# Already present (even if not enabled): nothing to download, cv enables it as a requirement.
reset_site '[{"key":"org.example.dep","status":"uninstalled"}]'
ck_enable_extensions
expect_log 'ext:enable fixture;' 'present dependency'

# No pin: the bare key goes to the registry.
/bin/rm "$work/ext/fixture/.ckconform"
reset_site '[]'
ck_enable_extensions
expect_log 'ext:download -n --no-install org.example.dep;ext:enable org.example.dep;ext:enable fixture;' 'registry dependency'

# A download that keeps failing aborts provisioning before the extension is enabled.
reset_site '[]'
if CV_DOWNLOAD_FAILS=1 ck_enable_extensions 2>/dev/null; then
  fail "a failed dependency download should fail ck_enable_extensions"
fi
if grep -q 'ext:enable fixture' "$CV_LOG"; then
  fail "the extension must not be enabled without its dependency"
fi

# A failing extension query aborts instead of downloading what the site may well have.
reset_site '[{"key":"org.example.dep","status":"installed"}]'
write_info '<requires><ext>org.example.dep</ext></requires>'
if CV_LIST_FAILS=1 ck_enable_extensions 2>/dev/null; then
  fail "a failed extension query must fail ck_enable_extensions"
fi
if grep -q 'ext:download' "$CV_LOG"; then
  fail "a failed extension query must not turn into a download"
fi

# No <requires> at all: just the enable.
write_info ''
reset_site '[]'
ck_enable_extensions
expect_log 'ext:enable fixture;' 'no requires'

echo "provision requires: ok"
