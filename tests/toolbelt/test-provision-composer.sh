#!/usr/bin/env bash
# ck_auto_composer hands vendor/ and composer.lock back to the bind-mount owner
# whether composer succeeded or not: a half-written root-owned vendor/ after
# a failed install would block the host user from removing it. Fake composer
# and chown record what happens.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/ext/alpha" "$work/ext/beta" "$work/ext/gamma/vendor"
fail() { echo "FAIL: $*" >&2; exit 1; }

cat > "$work/bin/composer" <<'FAKE'
#!/usr/bin/env bash
# fake composer: writes a partial vendor/ then fails when COMPOSER_FAILS names the dir.
mkdir -p vendor/partial; touch composer.lock
[[ "$(basename "$PWD")" == "${COMPOSER_FAILS:-}" ]] && exit 1
exit 0
FAKE
cat > "$work/bin/chown" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CHOWN_LOG"
FAKE
chmod +x "$work/bin/composer" "$work/bin/chown"
export PATH="$work/bin:$PATH" CHOWN_LOG="$work/chown.log" COMPOSER_FAILS=beta
: > "$CHOWN_LOG"
echo '{}' > "$work/ext/alpha/composer.json"
echo '{}' > "$work/ext/beta/composer.json"
echo '{}' > "$work/ext/gamma/composer.json"

ck_as_web() { "$@"; }
export CK_EXT_DIR="$work/ext"
# shellcheck source=../../docker/runtime/provision.sh
. "$root/docker/runtime/provision.sh"

ck_auto_composer 2>"$work/err"
for ext in alpha beta; do
  grep -q -- "--reference=$work/ext/$ext $work/ext/$ext/vendor" "$CHOWN_LOG" || fail "$ext: vendor/ not handed back to the mount owner"
  grep -q -- "--reference=$work/ext/$ext $work/ext/$ext/composer.lock" "$CHOWN_LOG" || fail "$ext: composer.lock not handed back"
done
grep -q 'composer install failed in ext/beta' "$work/err" || fail "a failed install must be reported"
! grep -q 'gamma' "$CHOWN_LOG" || fail "an extension with vendor/ already is skipped entirely"

echo "provision composer: ok"
