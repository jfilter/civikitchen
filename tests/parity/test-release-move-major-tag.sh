#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
move="${root}/.github/scripts/release-move-major-tag.sh"
work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

# origin keeps its github.com URL; insteadOf delivers the push to a local bare
# repo. A git shim records what each push saw, then runs the real git.
real_git=$(command -v git)
mkdir -p "${work}/bin"
cat > "${work}/bin/git" <<SHIM
#!/bin/bash
if [ "\$1" = push ]; then
  printf '%s\n' "\$*" >> "${work}/push.args"
  printf '%s=%s\n' "\${GIT_CONFIG_KEY_0-}" "\${GIT_CONFIG_VALUE_0-}" >> "${work}/push.env"
fi
exec "${real_git}" "\$@"
SHIM
chmod +x "${work}/bin/git"
export PATH="${work}/bin:${PATH}"
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="${work}/gitconfig"
git config --global url."${work}/remote.git".insteadOf https://github.com/owner/repo
git config --global user.name test
git config --global user.email test@example.org
git config --global init.defaultBranch main

git init -q --bare "${work}/remote.git"
git init -q "${work}/clone"
cd "${work}/clone"
git remote add origin https://github.com/owner/repo
git commit -q --allow-empty -m one
old=$(git rev-parse HEAD)
git tag v1.0.0
git tag v1 "${old}"
git commit -q --allow-empty -m two
new=$(git rev-parse HEAD)
git tag v1.1.0
git push -q origin main v1.0.0 v1.1.0 v1
: > "${work}/push.env"
: > "${work}/push.args"

remote_v1() { git --git-dir="${work}/remote.git" rev-parse refs/tags/v1; }
export MAJOR=v1 TAG=v1.1.0 RELEASE_SHA="${new}"

if RELEASE_PUSH_TOKEN='' "${move}" > "${work}/out" 2>&1; then
  fail "an empty token must fail the push"
fi
grep -q 'RELEASE_PUSH_TOKEN' "${work}/out" || fail "the failure must name the token"
[ "$(remote_v1)" = "${old}" ] || fail "v1 moved without a token"

token=ghs_test-token
RELEASE_PUSH_TOKEN="${token}" "${move}" > "${work}/out" 2>&1 \
  || { cat "${work}/out" >&2; fail "push with a token"; }
[ "$(remote_v1)" = "${new}" ] || fail "v1 did not move to the release commit"
basic=$(printf 'x-access-token:%s' "${token}" | base64 | tr -d '\n')
[ "$(head -1 "${work}/push.env")" = "http.https://github.com/.extraheader=AUTHORIZATION: basic ${basic}" ] \
  || fail "the push did not carry the App token"
if grep -q "${token}" "${work}/push.args"; then fail "the token leaked into git's argv"; fi
if grep -q "${token}\|${basic}" .git/config "${GIT_CONFIG_GLOBAL}"; then fail "the token was written to git config"; fi
if grep -q "${token}\|${basic}" "${work}/out"; then fail "the token was printed"; fi

RELEASE_PUSH_TOKEN="${token}" "${move}" > "${work}/out" 2>&1 \
  || { cat "${work}/out" >&2; fail "re-run on an already moved tag"; }
[ "$(remote_v1)" = "${new}" ] || fail "re-run changed v1"

TAG=v1.0.0 RELEASE_SHA="${old}" RELEASE_PUSH_TOKEN="${token}" "${move}" > "${work}/out" 2>&1
grep -q 'v1.1.0 is newer than v1.0.0' "${work}/out" || fail "an older patch must leave v1 alone"
[ "$(remote_v1)" = "${new}" ] || fail "an older patch dragged v1 backwards"

echo "release major-tag move regression test passed"
