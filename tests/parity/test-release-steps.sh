#!/usr/bin/env bash
# The dist-job steps of extension-release.yml that decide what the smoke test
# installs, run as written against fixture repositories: working_directory
# normalization, the same-repository closure, the pins union and the staging.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$root/.github/workflows/extension-release.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

step() {
  php "$root/tests/parity/workflow-step.php" "$1" "$2" "$3" > "$work/step.sh"
}

# Runs the extracted step in $ws with the runner's files; $out holds stdout+stderr.
run_step() {
  : > "$work/output" && : > "$work/env"
  rc=0
  out=$(cd "$ws/${1:-.}" && GITHUB_WORKSPACE="$ws" GITHUB_OUTPUT="$work/output" GITHUB_ENV="$work/env" \
    RUNNER_TEMP="$work/runner" GITHUB_RUN_ID=1 GITHUB_RUN_ATTEMPT=1 \
    bash --noprofile --norc -eo pipefail "$work/step.sh" 2>&1) || rc=$?
}

output() { sed -n "s/^$1=//p" "$work/output"; }

# extension <dir> <key> [required key ...]
extension() {
  local dir="$ws/$1" key="$2" requires=''
  shift 2
  for required in "$@"; do requires+="<ext>$required</ext>"; done
  mkdir -p "$dir"
  printf '<extension key="%s" type="module"><file>%s</file><version>1.0.0</version><requires>%s</requires></extension>\n' \
    "$key" "${key##*.}" "$requires" > "$dir/info.xml"
}

# pin <dir> <key> <asset>: a staged-release pin in that extension's civikitchen.yaml.
pin() {
  printf '%s\n' 'version: 1' 'policy:' '  extension_sources:' \
    "    - key: $2" "      version: '^1'" '      release:' \
    '        repository: example-org/dep' '        tag: v1.0.0' "        asset: $3" \
    "      sha256: $(printf 'a%.0s' {1..64})" '      reason: private, no registry serves it' \
    > "$ws/$1/civikitchen.yaml"
}

new_workspace() {
  ws="$work/ws-$1"
  mkdir -p "$ws" "$work/runner"
  rm -f "$work/runner"/*.txt
  ln -s "$root" "$ws/.civikitchen-ci"
}

# --- working_directory normalization -------------------------------------
step "$workflow" dist 'Validate the inputs and read the extension key'
new_workspace single
extension . org.example.single
for input in . ./; do
  CK_STAGE=release CK_DRY_RUN=true CK_WORKING_DIRECTORY=$input run_step
  [ "$rc" -eq 0 ] || fail "working_directory '$input' was refused: $out"
  [ "$(output wd)" = . ] || fail "working_directory '$input' normalized to '$(output wd)', expected '.'"
  [ "$(output key)" = org.example.single ] || fail "working_directory '$input' read key '$(output key)'"
done
new_workspace mono
extension base org.example.base
for input in base ./base/ base/; do
  CK_STAGE=build CK_DRY_RUN=true CK_WORKING_DIRECTORY=$input run_step
  [ "$rc" -eq 0 ] || fail "working_directory '$input' was refused: $out"
  [ "$(output wd)" = base ] || fail "working_directory '$input' normalized to '$(output wd)', expected 'base'"
done
for input in '' /base ../base base/../base base//x; do
  CK_STAGE=build CK_DRY_RUN=true CK_WORKING_DIRECTORY=$input run_step
  [ "$rc" -ne 0 ] || fail "working_directory '$input' was accepted"
done

# extension-ci.yml validates the same input in its key job.
step "$root/.github/workflows/extension-ci.yml" key 'Validate working_directory and read the extension key from info.xml'
new_workspace ci
extension . org.example.single
CK_EXT_KEY_INPUT='' CK_WORKING_DIRECTORY=./ run_step
[ "$rc" -eq 0 ] || fail "extension-ci refused working_directory './': $out"
[ "$(output key)" = single ] || fail "extension-ci read key '$(output key)' for './'"

# --- the same-repository closure ------------------------------------------
step "$workflow" dist 'Find the same-repository extensions this one requires'
new_workspace closure
extension base org.example.base org.civicrm.registryonly
extension mid org.example.mid org.example.base
extension addon org.example.addon org.example.mid org.example.base
extension other org.example.other
CK_EXT_KEY=org.example.addon CK_WD=addon run_step
[ "$rc" -eq 0 ] || fail "closure of addon failed: $out"
expected=$'org.example.mid mid org.example.mid-1.0.0.zip\norg.example.base base org.example.base-1.0.0.zip'
[ "$(cat "$work/runner/release-siblings.txt")" = "$expected" ] \
  || fail "closure of addon: $(cat "$work/runner/release-siblings.txt")"
[ "$(output any)" = true ] || fail "closure of addon did not report any=true"

CK_EXT_KEY=org.example.base CK_WD=base run_step
[ "$rc" -eq 0 ] && [ ! -s "$work/runner/release-siblings.txt" ] && [ "$(output any)" = false ] \
  || fail "base has no same-repository requirement: $out"

# A sibling whose info.xml cannot be read aborts; it never silently drops out.
printf '<extension key=' > "$ws/other/info.xml"
CK_EXT_KEY=org.example.addon CK_WD=addon run_step
[ "$rc" -ne 0 ] || fail "a malformed sibling info.xml was ignored"
case "$out" in *'other/info.xml carries no key'*) ;; *) fail "malformed sibling: $out" ;; esac

# A failing parser aborts the step instead of reading as "no requirements".
printf '<extension key="org.example.other"/>\n' > "$ws/other/info.xml"
mkdir -p "$work/failphp"
printf '#!/usr/bin/env bash\ncase " $* " in *" extension-requires "*) exit 70 ;; esac\nexec %q "$@"\n' "$(command -v php)" \
  > "$work/failphp/php"
chmod +x "$work/failphp/php"
PATH="$work/failphp:$PATH" CK_EXT_KEY=org.example.addon CK_WD=addon run_step
[ "$rc" -ne 0 ] || fail "a failing extension-requires was read as no requirements"

# --- the pins union --------------------------------------------------------
step "$workflow" dist 'Read the staged-release dependency pins'
new_workspace pins
extension base org.example.base org.example.dep
extension addon org.example.addon org.example.base org.example.dep
pin base org.example.dep dep-1.0.0.zip
pin addon org.example.dep dep-1.0.0.zip
printf '%s\n' 'org.example.base base org.example.base-1.0.0.zip' > "$work/runner/release-siblings.txt"
run_step addon
[ "$rc" -eq 0 ] || fail "pins step failed: $out"
[ "$(wc -l < "$work/runner/release-pins.txt")" -eq 1 ] || fail "identical pins were not merged: $(cat "$work/runner/release-pins.txt")"
pin base org.example.dep2 dep2-1.0.0.zip
run_step addon
grep -q '^org.example.dep2 ' "$work/runner/release-pins.txt" || fail "a sibling's own pin was not staged"
[ "$(output any)" = true ] || fail "pins step did not report any=true"

# --- staging by archive name ----------------------------------------------
step "$workflow" dist 'Stage the required same-repository archives'
new_workspace stage
run_dir="$work/run" && siblings="$work/siblings"
mkdir -p "$run_dir"
# Merged downloads: both build jobs' archives side by side, whatever else ran.
for name in org.example.base-1.0.0.zip org.example.addon-1.0.0.zip; do
  printf 'zip %s' "$name" > "$run_dir/$name"
  (cd "$run_dir" && sha256sum "$name" > "$name.sha256")
done
printf '%s\n' 'org.example.base base org.example.base-1.0.0.zip' > "$work/runner/release-siblings.txt"
CK_RUN_ARCHIVES=$run_dir CK_SIBLINGS=$siblings run_step
[ "$rc" -eq 0 ] || fail "staging failed: $out"
[ "$(ls "$siblings")" = org.example.base-1.0.0.zip ] || fail "staged $(ls "$siblings")"

printf 'tampered' > "$run_dir/org.example.base-1.0.0.zip"
CK_RUN_ARCHIVES=$run_dir CK_SIBLINGS=$siblings run_step
[ "$rc" -ne 0 ] || fail "a tampered sibling archive was staged"

printf '%s\n' 'org.example.mid mid org.example.mid-1.0.0.zip' > "$work/runner/release-siblings.txt"
CK_RUN_ARCHIVES=$run_dir CK_SIBLINGS=$siblings run_step
[ "$rc" -ne 0 ] || fail "a missing sibling archive was not reported"
case "$out" in *"no org.example.mid-1.0.0.zip in this run"*) ;; *) fail "missing archive: $out" ;; esac

# --- publish: one version across the archives, flags even in a dry run -----
step "$workflow" publish 'Publish the GitHub release'
new_workspace publish
dist="$work/dist" && mkdir -p "$dist" "$work/bin"
# archive <key> <version>
archive() {
  mkdir -p "$work/src/$1"
  printf '<extension key="%s"><version>%s</version></extension>\n' "$1" "$2" > "$work/src/$1/info.xml"
  (cd "$work/src" && zip -q "$dist/$1-$2.zip" "$1/info.xml")
}
printf '#!/usr/bin/env bash\nprintf "refs/tags/v0.9.0\\n"\n' > "$work/bin/gh"
chmod +x "$work/bin/gh"
archive org.example.base 1.0.0
archive org.example.addon 1.0.0
PATH="$work/bin:$PATH" GITHUB_REPOSITORY=example-org/mono GITHUB_REF=refs/heads/main \
  CK_DRY_RUN=true CK_DRAFT=false CK_DIST=$dist run_step
[ "$rc" -eq 0 ] || fail "dry-run publish failed: $out"
case "$out" in *'latest=true'*'dry run: release v1.0.0 would carry'*) ;; *) fail "dry-run publish: $out" ;; esac

PATH="$work/bin:$PATH" GITHUB_REPOSITORY=example-org/mono GITHUB_REF=refs/tags/v1.0.1 GITHUB_REF_NAME=v1.0.1 \
  CK_DRY_RUN=false CK_DRAFT=false CK_DIST=$dist run_step
[ "$rc" -ne 0 ] || fail "archives of 1.0.0 were published under v1.0.1"

rm "$dist/org.example.addon-1.0.0.zip"
archive org.example.addon 1.1.0
PATH="$work/bin:$PATH" GITHUB_REPOSITORY=example-org/mono GITHUB_REF=refs/heads/main \
  CK_DRY_RUN=true CK_DRAFT=false CK_DIST=$dist run_step
[ "$rc" -ne 0 ] || fail "archives of two versions passed as one release"
case "$out" in *'different versions (1.0.0, 1.1.0)'*) ;; *) fail "version mismatch: $out" ;; esac

echo "release step tests passed"
