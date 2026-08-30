#!/usr/bin/env bash
# The entrypoint enables whatever is bind-mounted into the ext dir — keys read
# from each mount's info.xml, the mount table telling mounts from downloads —
# with CIVIKITCHEN_ENABLE_EXTENSIONS as the ordering/extra override. A bare
# key in CIVIKITCHEN_EXTRA_EXTENSIONS takes the extension_source pin of a
# mounted extension. Fake cv records every call; a real ckconform reads the
# policy file.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext/alpha" "$work/ext/beta-dir" "$work/ext/downloaded" "$work/ext/no key"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CV_LOG"
case "$1" in
  api4) echo '[]' ;;
esac
FAKE
chmod +x "$work/bin/cv"
export PATH="$work/bin:$root/toolbelt/bin:$PATH"
export CV_LOG="$work/cv.log"
CK_WEB_USER="$(id -un)"
CK_WEB_GROUP="$(id -gn)"
export CK_WEB_USER CK_WEB_GROUP

ck_as_web() { "$@"; }
sleep() { :; }
curl() {
  local output='' previous='' arg
  for arg in "$@"; do
    [[ "$previous" == '-o' ]] && output="$arg"
    previous="$arg"
  done
  cp "$ARCHIVE_FIXTURE" "$output"
}
export CK_EXT_DIR="$work/ext"
export CK_MOUNTINFO="$work/mountinfo"
export CIVIKITCHEN_ENABLE_EXTENSIONS=""
export CIVIKITCHEN_EXTRA_EXTENSIONS=""
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

write_info() {
  cat > "$1/info.xml" <<INFO
<?xml version="1.0"?>
<extension key="$2" type="module">
  <file>$2</file>
</extension>
INFO
}
write_info "$work/ext/alpha" alpha
write_info "$work/ext/beta-dir" org.example.beta
write_info "$work/ext/downloaded" downloaded
printf '<extension' > "$work/ext/no key/info.xml"
# Field 5 is the mount point; a space in it is escaped as \040. The ext dir
# itself and a nested mount below an extension are not extensions.
cat > "$work/mountinfo" <<MOUNTS
100 90 0:37 /host/a $work/ext/alpha rw,relatime - fakeowner /dev/x rw
101 90 0:37 /host/b $work/ext/beta-dir rw,relatime - fakeowner /dev/x rw
102 90 0:37 /host/n $work/ext/no\\040key rw,relatime - fakeowner /dev/x rw
103 90 0:37 /host/root $work/ext rw,relatime - fakeowner /dev/x rw
104 90 0:37 /host/nested $work/ext/alpha/vendor rw,relatime - fakeowner /dev/x rw
MOUNTS

expect_log() {
  local actual
  actual="$( (grep -v '^api4 Extension.get' "$CV_LOG" || true) | tr '\n' ';' | sed -E 's#@[A-Za-z0-9_./-]*civikitchen-extension\.[A-Za-z0-9]+\.zip#@<archive>#g')"
  [[ "$actual" == "$1" ]] || fail "$2 — expected '$1', got '$actual'"
}

# Mounts are enabled by their info.xml key, in name order; the download and
# the key-less mount are not.
: > "$CV_LOG"
ck_enable_extensions 2>"$work/err"
expect_log 'ext:enable alpha;ext:enable org.example.beta;' 'mounted extensions'
grep -q 'no key' "$work/err" || fail "a mount without a key should be reported"

# The explicit list goes first, in its order, and is not repeated.
: > "$CV_LOG"
CIVIKITCHEN_ENABLE_EXTENSIONS="org.example.beta, downloaded" ck_enable_extensions 2>/dev/null
expect_log 'ext:enable org.example.beta;ext:enable downloaded;ext:enable alpha;' 'explicit list first'

# Nothing mounted and nothing listed: nothing to do, no output.
: > "$CV_LOG"
CK_MOUNTINFO="$work/none" ck_enable_extensions
expect_log '' 'nothing to enable'

# A bare key in CIVIKITCHEN_EXTRA_EXTENSIONS takes a mounted extension's pin;
# an explicit key@URL and an unpinned key pass through.
mkdir -p "$work/archive/de.example.opt"
printf '%s\n' '<extension key="de.example.opt" type="module"><file>opt</file></extension>' > "$work/archive/de.example.opt/info.xml"
(cd "$work/archive" && zip -qr "$work/opt.zip" de.example.opt)
export ARCHIVE_FIXTURE="$work/opt.zip"
digest=$(sha256sum < "$ARCHIVE_FIXTURE" | cut -d' ' -f1)
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: de.example.opt' \
  '      url: https://example.org/opt-2.0.zip' \
  "      sha256: $digest" \
  '      reason: not in the feed' > "$work/ext/alpha/civikitchen.yaml"
: > "$CV_LOG"
CIVIKITCHEN_EXTRA_EXTENSIONS="de.example.opt,de.example.bare,de.example.opt@https://example.org/own.zip" ck_extra_extensions >/dev/null
expect_log 'ev CRM_Extension_System::singleton()->getFullContainer()->refresh();;ext:enable de.example.opt;ext:download -n --no-install de.example.bare;ext:enable de.example.bare;ext:download -n --no-install de.example.opt@https://example.org/own.zip;ext:enable de.example.opt;' 'extra extension pins'

echo "provision mounts: ok"
