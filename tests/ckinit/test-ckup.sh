#!/usr/bin/env bash
# ckup writes free host ports into .docker/.env once (existing values are
# kept), then runs docker compose up -d. Fake docker records the call; the
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
FAKE
chmod +x "$work/bin/docker"
export PATH="$work/bin:$PATH" DOCKER_LOG="$work/docker.log"
# 8080 and 8081 are "taken": the probe fails for them.
fake_probe() { [[ "$1" != 8080 && "$1" != 8081 ]]; }
export -f fake_probe
export CK_PORT_CHECK=fake_probe

# From a subdirectory: the repo is found upwards, ports skip the taken ones and
# each other, compose runs in .docker with the arguments passed through.
(cd "$work/repo/.docker/sub" && "$root/scaffold/ckup" --build >/dev/null)
[[ "$(cat "$work/repo/.docker/.env")" == $'CK_HTTP_PORT=8082\nCK_MAILDEV_PORT=1080\nCK_SMTP_PORT=1025\nCK_PMA_PORT=8083' ]] \
  || fail "unexpected .env: $(cat "$work/repo/.docker/.env")"
[[ "$(cat "$DOCKER_LOG")" == "$work/repo/.docker|compose up -d --build" ]] || fail "unexpected docker call: $(cat "$DOCKER_LOG")"

# A second run keeps the file as it is, even though its ports are now "taken".
fake_probe() { false; }
export -f fake_probe
(cd "$work/repo" && "$root/scaffold/ckup" >/dev/null)
[[ "$(grep -c . "$work/repo/.docker/.env")" == 4 ]] || fail ".env must not grow on rerun"

# Outside a repo: a clear error, no compose call.
: > "$DOCKER_LOG"
if (cd "$work" && "$root/scaffold/ckup" 2>/dev/null); then fail "no compose file must fail"; fi
[[ ! -s "$DOCKER_LOG" ]] || fail "compose must not run without a compose file"

echo "ckup: ok"
