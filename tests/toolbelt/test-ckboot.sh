#!/usr/bin/env bash
# ckboot hands the entrypoint what the CI compose stack mounts: the extension,
# its init.d hooks, its dev profile and the admin user that profile seeds as.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
mkdir -p "$work/bin" "$work/web" "$work/ext/.docker/init.d" "$work/ext/.docker/profiles/dev"
printf '%s\n' '<extension key="org.example.booted" type="module"><file>booted</file></extension>' > "$work/ext/info.xml"
printf '%s\n' '{"description": "dev", "dependencies": []}' > "$work/ext/.docker/profiles/dev/profile.json"

cat > "$work/bin/civikitchen-entrypoint" <<'FAKE'
#!/usr/bin/env bash
env | grep -E '^(CK_INIT_D|CIVIKITCHEN_DEMO_USER|CIVIKITCHEN_PROFILE|CIVIKITCHEN_PROFILE_PATH|CIVIKITCHEN_TRUST_EXTERNAL_PROFILES)=' | LC_ALL=C sort > "$ENV_DUMP"
FAKE
printf '#!/bin/sh\n' > "$work/bin/apache2ctl"
printf '#!/bin/sh\n' > "$work/bin/curl"
chmod +x "$work/bin/"*
# ckboot enters the image's web root, which the host does not have.
cd() { if [ "$1" = /var/www/html ]; then builtin cd "$CK_FAKE_WEB"; else builtin cd "$@"; fi; }
export -f cd
export PATH="$work/bin:$PATH" ENV_DUMP="$work/env" CK_FAKE_WEB="$work/web"

(builtin cd "$work/ext" && "$root/toolbelt/bin/ckboot" 2>/dev/null) > "$work/out"
[ "$(cat "$work/out")" = /var/www/html/ext/booted ] || fail "ckboot printed $(cat "$work/out")"
[ "$(cat "$work/env")" = "CIVIKITCHEN_DEMO_USER=admin
CIVIKITCHEN_PROFILE=dev
CIVIKITCHEN_PROFILE_PATH=$work/ext/.docker/profiles
CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1
CK_INIT_D=$work/ext/.docker/init.d" ] || fail "ckboot passed $(cat "$work/env")"

# A job's own demo user stays.
(builtin cd "$work/ext" && CIVIKITCHEN_DEMO_USER=editor "$root/toolbelt/bin/ckboot" 2>/dev/null) > /dev/null
grep -qx CIVIKITCHEN_DEMO_USER=editor "$work/env" || fail "ckboot replaced the job's demo user"

# A repository without a dev profile selects none.
/bin/rm -r "$work/ext/.docker/profiles"
(builtin cd "$work/ext" && "$root/toolbelt/bin/ckboot" 2>/dev/null) > /dev/null
[ "$(cat "$work/env")" = "CK_INIT_D=$work/ext/.docker/init.d" ] || fail "ckboot without a profile passed $(cat "$work/env")"

echo "ckboot: ok"
