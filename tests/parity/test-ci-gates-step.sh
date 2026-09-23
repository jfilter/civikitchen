#!/usr/bin/env bash
# The `ci` job's gate step of extension-ci.yml, run as written over a fake
# `docker`: what reaches `ck ci` in the container, its exit code, and the
# summary it wrote there landing in the job summary. Plus the local-fix notice.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/extension-ci.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
step() { php "$root/tests/parity/workflow-step.php" "$workflow" ci "$@"; }

gates='Extension gates (ck ci)'
step "$gates" > "$work/step.sh"

# The step's own env: mapping, each expression resolved from these inputs, so
# a variable the mapping stops providing reaches the script empty.
declare -A inputs=(
  ['${{ inputs.policy_defaults || vars.CK_POLICY_DEFAULTS }}']=example-org/policy
  ['${{ inputs.extra_phpunit_config }}']=phpunit-unit.xml.dist
)
step_env=()
while IFS=$'\t' read -r name expression; do
  [ -n "${inputs[$expression]+set}" ] || fail "no fixture value for $name=$expression"
  step_env+=("$name=${inputs[$expression]}")
done < <(step "$gates" env)

mkdir "$work/bin"
cat > "$work/bin/docker" <<'SH'
#!/bin/bash
printf '%s\n' "$*" >> "$FAKE_LOG"
case "$*" in
  *' ck ci --help') [ "${FAKE_OLD_IMAGE:-}" != 1 ] || { echo 'ck: unknown command: ci' >&2; exit 2; } ;;
  *' ck ci') echo 'gate output'; exit "${FAKE_RC:-0}" ;;
  *' app cat /tmp/ck-ci-summary.md')
    [ "${FAKE_NO_SUMMARY:-}" != 1 ] || { echo 'cat: /tmp/ck-ci-summary.md: No such file or directory' >&2; exit 1; }
    echo '### ck ci' ;;
esac
SH
chmod +x "$work/bin/docker"

run_step() {
  : > "$work/log"
  printf '%s\n' '# earlier step' > "$work/summary.md"
  rc=0
  out=$(env -u CK_POLICY_DEFAULTS -u CK_EXTRA_PHPUNIT_CONFIG PATH="$work/bin:$PATH" FAKE_LOG="$work/log" \
    GITHUB_STEP_SUMMARY="$work/summary.md" CK_COMPOSE_FILE=.docker/docker-compose.ci.yml \
    CK_EXT_PATH=/var/www/html/ext/demo CK_TOOL_PATH="${tool_path:-/civikitchen-repo/demo}" \
    CK_REPO_PATH=/civikitchen-repo "$@" bash --noprofile --norc -eo pipefail "$work/step.sh" 2>&1) || rc=$?
}

run_step "${step_env[@]}" FAKE_RC=1
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

run_step "${step_env[@]/#CK_POLICY_DEFAULTS=*/CK_POLICY_DEFAULTS=}"
[ "$rc" = 0 ] || fail "a passing ck ci must pass the step (rc=$rc)"$'\n'"$out"
grep -qF -- ' -e CK_DEFAULT_CONFIG= ' "$work/log" || fail "no policy_defaults must pass an empty layer"

# ck ci's exit code survives a missing summary file, which alone fails nothing.
for want in 0 2; do
  run_step "${step_env[@]}" FAKE_RC=$want FAKE_NO_SUMMARY=1
  [ "$rc" = "$want" ] || fail "missing summary with ck ci exit $want gave rc=$rc"$'\n'"$out"
  grep -qF '::warning::ck ci wrote no summary table' <<<"$out" || fail "missing summary not reported: $out"
done

run_step "${step_env[@]}" FAKE_OLD_IMAGE=1
[ "$rc" = 1 ] || fail "an image without ck ci must fail the step (rc=$rc)"
grep -qF "predates \`ck ci\`" <<<"$out" || fail "old image not named: $out"
if grep -q ' ck ci$' "$work/log"; then fail "ck ci run on an image without it"; fi

# The notice names a command that works in the dev stack, and only follows a
# gate run that failed, not a stack that never booted.
notice='How to fix findings locally'
[ "$(step "$notice" if)" = "\${{ failure() && steps.gates.outcome == 'failure' }}" ] \
  || fail "notice condition: $(step "$notice" if)"
step "$notice" > "$work/step.sh"
run_step
grep -qF 'exec -u www-data -w /var/www/html/ext/demo -e CK_TOOL_PATH=/civikitchen-repo/demo app ck ci ' <<<"$out" \
  || fail "notice for an extension below the root: $out"
tool_path=/var/www/html/ext/demo run_step
grep -qF 'exec -u www-data -w /var/www/html/ext/demo app ck ci ' <<<"$out" || fail "notice at the root: $out"

echo "ci gates step tests passed"
