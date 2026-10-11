#!/usr/bin/env bash
# ckup writes free host ports into .docker/.env once (existing values are
# kept), installs vendor/ as the calling user, then runs docker compose up -d.
# Fake docker records the calls; the
# port probe is replaced by a list of "taken" ports.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap '/bin/rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/repo/.docker/sub"
fail() { echo "FAIL: $*" >&2; exit 1; }

: > "$work/repo/.docker/docker-compose.yml"
cat > "$work/bin/docker" <<'FAKE'
#!/usr/bin/env bash
printf '%s|%s\n' "$PWD" "$*" >> "$DOCKER_LOG"
[[ "${DOCKER_FAIL_RUN:-}" != 1 || "$2" != run ]]
FAKE
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH" DOCKER_LOG="$work/docker.log"
# The port probe is a script: ports listed in TAKEN_PORTS are "taken".
cat > "$work/bin/probe" <<'FAKE'
#!/usr/bin/env bash
[[ ",${TAKEN_PORTS}," != *",$1,"* ]]
FAKE
chmod +x "$work/bin/probe"
export CK_PORT_CHECK="$work/bin/probe" TAKEN_PORTS="8080,8081"

# From a subdirectory: the repo is found upwards, ports skip the taken ones and
# each other, compose runs in .docker with the arguments passed through.
(cd "$work/repo/.docker/sub" && "$root/scaffold/ckup" --build >/dev/null)
[[ "$(cat "$work/repo/.docker/.env")" == $'CK_HTTP_PORT=8082\nCK_MAILDEV_PORT=1080\nCK_SMTP_PORT=1025\nCK_PMA_PORT=8083' ]] \
  || fail "unexpected .env: $(cat "$work/repo/.docker/.env")"
expected="$work/repo/.docker|compose run --rm --no-deps -T -u $(id -u):$(id -g) -e HOME=/tmp --entrypoint sh app -c exec ck internal composer-install /var/www/html/ext/*/
$work/repo/.docker|compose up -d --build"
[[ "$(cat "$DOCKER_LOG")" == "$expected" ]] || fail "unexpected docker calls: $(cat "$DOCKER_LOG")"

# A failing composer install stops before the stack starts.
: > "$DOCKER_LOG"
if (cd "$work/repo" && DOCKER_FAIL_RUN=1 "$root/scaffold/ckup" >/dev/null 2>&1); then fail "a failed composer install must fail ckup"; fi
! grep -q 'compose up' "$DOCKER_LOG" || fail "the stack must not start after a failed composer install"

# A second run keeps the file as it is, even though its ports are now "taken".
export TAKEN_PORTS="8082,1080,1025,8083"
(cd "$work/repo" && "$root/scaffold/ckup" >/dev/null)
[[ "$(grep -c . "$work/repo/.docker/.env")" == 4 ]] || fail ".env must not grow on rerun"

# A partial .env: the existing value is kept and not handed out again, even
# though nothing listens on it right now.
mkdir -p "$work/partial/.docker"; : > "$work/partial/.docker/docker-compose.yml"
printf 'CK_HTTP_PORT=8081\n' > "$work/partial/.docker/.env"
export TAKEN_PORTS=""
(cd "$work/partial" && "$root/scaffold/ckup" >/dev/null)
[[ "$(cat "$work/partial/.docker/.env")" == $'CK_HTTP_PORT=8081\nCK_MAILDEV_PORT=1080\nCK_SMTP_PORT=1025\nCK_PMA_PORT=8082' ]] \
  || fail "existing port reused: $(cat "$work/partial/.docker/.env")"

# Outside a repo: a clear error, no compose call.
: > "$DOCKER_LOG"
if (cd "$work" && "$root/scaffold/ckup" 2>/dev/null); then fail "no compose file must fail"; fi
[[ ! -s "$DOCKER_LOG" ]] || fail "compose must not run without a compose file"

echo "ckup: ok"
