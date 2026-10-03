#!/usr/bin/env bash
# ckx finds the dev stack upward and execs into the extension's directory as
# www-data, mapping the host subdirectory and, below a git root, CK_TOOL_PATH.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(cd "$(mktemp -d)" && pwd -P)"
trap '/bin/rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

mkdir -p "$work/bin"
cat > "$work/bin/docker" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" > "$DOCKER_LOG"
FAKE
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH" DOCKER_LOG="$work/docker.log"

ext() {
  mkdir -p "$1/.docker" "$1/tests/phpunit"
  touch "$1/.docker/docker-compose.yml"
  printf '<extension key="org.example.%s"><file>%s</file></extension>\n' "$2" "$2" > "$1/info.xml"
}

ext "$work/single" single
git -C "$work/single" init -q
(cd "$work/single/tests/phpunit" && "$root/scaffold/ckx" ck ci --only cklint </dev/null)
expected="compose -f $work/single/.docker/docker-compose.yml exec -u www-data -w /var/www/html/ext/single/tests/phpunit -T app ck ci --only cklint"
[ "$(cat "$DOCKER_LOG")" = "$expected" ] || fail "single: $(cat "$DOCKER_LOG")"

mkdir -p "$work/mono"
git -C "$work/mono" init -q
ext "$work/mono/exts/addon" addon
(cd "$work/mono/exts/addon" && CKX_USER=root "$root/scaffold/ckx" </dev/null)
grep -qF -- '-u root -w /var/www/html/ext/addon -T -e CK_TOOL_PATH=/civikitchen-repo/exts/addon app bash' "$DOCKER_LOG" \
  || fail "monorepo: $(cat "$DOCKER_LOG")"

printf '<extension/>\n' > "$work/single/info.xml"
if (cd "$work/single" && "$root/scaffold/ckx" true 2>/dev/null); then fail "no <file> must fail"; fi
if (cd "$work" && "$root/scaffold/ckx" true 2>/dev/null); then fail "no compose file must fail"; fi

echo "ckx: ok"
