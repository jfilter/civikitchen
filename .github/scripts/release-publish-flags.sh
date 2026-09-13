#!/usr/bin/env bash
# Decide how the release for tag $1 is published, given the repo's tag names on
# stdin (one per line, refs/tags/ prefix optional). Prints prerelease= and
# latest= lines; a tag that is neither vX.Y.Z nor vX.Y.Z-<pre> fails.
set -euo pipefail

num='(0|[1-9][0-9]*)'
plain="^v${num}\.${num}\.${num}\$"
ident='[0-9A-Za-z-]+'
pre="^v${num}\.${num}\.${num}-(${ident}(\.${ident})*)\$"

tag="${1:?usage: release-publish-flags.sh <tag> < tag-list}"

# Numeric components carry no leading zeros (SemVer 2), so a longer string is
# the larger number and equal lengths compare as strings — no integer overflow.
greater() {
  if [ "${#1}" -ne "${#2}" ]; then
    [ "${#1}" -gt "${#2}" ]
  else
    [[ "$1" > "$2" ]]
  fi
}

if [[ "${tag}" =~ ${pre} ]]; then
  IFS=. read -ra ids <<< "${BASH_REMATCH[4]}"
  for id in "${ids[@]}"; do
    if [[ "${id}" =~ ^0[0-9]+$ ]]; then
      echo "not a release tag: '${tag}' (numeric pre-release identifier '${id}' has a leading zero)" >&2
      exit 1
    fi
  done
  printf 'prerelease=true\nlatest=false\n'
  exit 0
fi

if ! [[ "${tag}" =~ ${plain} ]]; then
  echo "not a release tag: '${tag}' (expected v<major>.<minor>.<patch> or v<major>.<minor>.<patch>-<pre-release>)" >&2
  exit 1
fi
mine=("${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}")

latest=true
while IFS= read -r other; do
  other="${other#refs/tags/}"
  [[ "${other}" =~ ${plain} ]] || continue
  for i in 1 2 3; do
    theirs="${BASH_REMATCH[i]}" own="${mine[i - 1]}"
    if greater "${theirs}" "${own}"; then
      latest=false
      break 2
    fi
    if [ "${theirs}" != "${own}" ]; then
      break
    fi
  done
done

printf 'prerelease=false\nlatest=%s\n' "${latest}"
