#!/usr/bin/env bash
# First-boot provisioning turns off core's calls to civicrm.org: the
# version_check job goes inactive and ext_repo_url becomes boolean false (a
# string "false" or "" leaves the feed on). Fake cv records args and stdin.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/cv" <<'FAKE'
#!/usr/bin/env bash
printf 'args: %s\n' "$*" >> "$CV_LOG"
if [[ "$*" == *"--in=json"* ]]; then printf 'stdin: %s\n' "$(cat)" >> "$CV_LOG"; fi
FAKE
chmod +x "$work/bin/cv"
export PATH="$work/bin:$PATH" CV_LOG="$work/cv.log"

ck_as_web() { "$@"; }
export CK_PROVISIONED_MARKER="$work/provisioned"
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"
# The other provisioning steps have suites of their own.
ck_locales() { :; }
ck_apply_profile() { :; }
ck_extra_extensions() { :; }
ck_enable_extensions() { :; }
ck_run_init_hooks() { :; }

: > "$CV_LOG"
ck_post_install_provision >/dev/null
grep -qx 'args: api4 Job.update +w api_action=version_check +v is_active=0' "$CV_LOG" \
    || fail "version_check job not deactivated: $(cat "$CV_LOG")"
stdin="$(sed -n 's/^stdin: //p' "$CV_LOG")"
php -r 'exit(json_decode($argv[1], true) === ["ext_repo_url" => false] ? 0 : 1);' "$stdin" \
    || fail "ext_repo_url must be set to boolean false, got: ${stdin:-nothing}"
[[ -f "$CK_PROVISIONED_MARKER" ]] || fail "provisioning did not complete"

echo "provision phone-home: ok"
