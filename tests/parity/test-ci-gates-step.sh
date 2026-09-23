#!/usr/bin/env bash
# The `ci` job's gate step of extension-ci.yml, run as written over a fake
# `docker`: what reaches `ck ci` in the container, its exit code, and the
# summary it wrote there landing in the job summary.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$root/tests/parity/workflow-step.php" "$root/.github/workflows/extension-ci.yml" ci 'Extension gates (ck ci)' \
  > "$work/step.sh"

mkdir "$work/bin"
cat > "$work/bin/docker" <<'SH'
#!/bin/bash
printf '%s\n' "$*" >> "$FAKE_LOG"
case "$*" in
  *' ck ci --help') [ "${FAKE_OLD_IMAGE:-}" != 1 ] || { echo 'ck: unknown command: ci' >&2; exit 2; } ;;
  *' ck ci') echo 'gate output'; exit "${FAKE_RC:-0}" ;;
  *' app cat /tmp/ck-ci-summary.md') echo '### ck ci' ;;
esac
SH
chmod +x "$work/bin/docker"

run_step() {
  : > "$work/log"
  printf '%s\n' '# earlier step' > "$work/summary.md"
  rc=0
  out=$(PATH="$work/bin:$PATH" FAKE_LOG="$work/log" GITHUB_STEP_SUMMARY="$work/summary.md" \
    CK_COMPOSE_FILE=.docker/docker-compose.ci.yml CK_EXT_PATH=/var/www/html/ext/demo \
    CK_TOOL_PATH=/civikitchen-repo/demo CK_REPO_PATH=/civikitchen-repo \
    CK_POLICY_DEFAULTS="${defaults:-}" CK_EXTRA_PHPUNIT_CONFIG=phpunit-unit.xml.dist "$@" \
    bash --noprofile --norc -eo pipefail "$work/step.sh" 2>&1) || rc=$?
}

defaults=example-org/policy run_step env FAKE_RC=1
[ "$rc" = 1 ] || fail "a failing ck ci must fail the step (rc=$rc)"$'\n'"$out"
call=$(grep ' ck ci$' "$work/log") || fail "ck ci was not run"$'\n'"$(cat "$work/log")"
for arg in '-u www-data' '-e GITHUB_ACTIONS=true' '-e GITHUB_STEP_SUMMARY=/tmp/ck-ci-summary.md' \
  '-e CK_EXT_PATH=/var/www/html/ext/demo' '-e CK_TOOL_PATH=/civikitchen-repo/demo' \
  '-e CK_EXTRA_PHPUNIT_CONFIG=phpunit-unit.xml.dist' \
  '-e CK_DEFAULT_CONFIG=/civikitchen-repo/.civikitchen-siblings/policy/civikitchen.yaml'; do
  grep -qF -- " $arg " <<<"$call" || fail "ck ci not passed '$arg': $call"
done
[ "$(cat "$work/summary.md")" = $'# earlier step\n### ck ci' ] \
  || fail "summary of a failed run not appended: $(cat "$work/summary.md")"

run_step
[ "$rc" = 0 ] || fail "a passing ck ci must pass the step (rc=$rc)"$'\n'"$out"
grep -qF -- ' -e CK_DEFAULT_CONFIG= ' "$work/log" || fail "no policy_defaults must pass an empty layer"

run_step env FAKE_OLD_IMAGE=1
[ "$rc" = 1 ] || fail "an image without ck ci must fail the step (rc=$rc)"
grep -qF "predates \`ck ci\`" <<<"$out" || fail "old image not named: $out"
if grep -q ' ck ci$' "$work/log"; then fail "ck ci run on an image without it"; fi

echo "ci gates step tests passed"
