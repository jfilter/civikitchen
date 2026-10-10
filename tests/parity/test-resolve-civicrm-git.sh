#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
# Stands in for git ls-remote against civicrm-core and civicrm-packages.
cat > "$work/bin/git" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[ "$1 $2" = "ls-remote --heads" ] || { echo "unexpected git $*" >&2; exit 1; }
case "$3" in
  https://github.com/civicrm/civicrm-core.git) branches=$FAKE_CORE ;;
  https://github.com/civicrm/civicrm-packages.git) branches=$FAKE_PACKAGES ;;
  *) echo "unexpected repository $3" >&2; exit 1 ;;
esac
for b in $branches; do printf '%040d\trefs/heads/%s\n' 0 "$b"; done
SH
chmod +x "$work/bin/git"

resolve() { # $1 = civicrm-core branches, $2 = civicrm-packages branches
  : > "$work/out"
  PATH="$work/bin:$PATH" GITHUB_OUTPUT="$work/out" FAKE_CORE="$1" FAKE_PACKAGES="$2" \
    bash "$root/.github/scripts/resolve-civicrm-git.sh" >/dev/null || return
  sed -n 's/^git_refs=//p' "$work/out"
}
beta() { printf '[{"ref":"master","tag":"master"},{"ref":"%s","tag":"beta"}]' "$1"; }

# Version order, not string order.
test "$(resolve 'master 6.9 6.20 5.81' 'master 6.9 6.20 5.81')" = "$(beta 6.20)"
test "$(resolve 'master 6.20 7.0' 'master 6.20 7.0')" = "$(beta 7.0)"
# Only X.Y names are release branches.
test "$(resolve 'master 6.20 6.21-security 7.x 6.20.1 hotfix/7.1' 'master 6.20')" = "$(beta 6.20)"

# A release branch civicrm-packages has not cut yet fails by name, with no output.
if err=$(resolve 'master 6.20' 'master 6.19' 2>&1); then
  echo 'resolve-civicrm-git accepted 6.20 without a civicrm-packages branch' >&2
  exit 1
fi
grep -qxF 'no branch 6.20 in https://github.com/civicrm/civicrm-packages.git' <<<"$err"
test ! -s "$work/out"

echo 'ok   resolve-civicrm-git picks the newest X.Y branch and requires it in civicrm-packages'
