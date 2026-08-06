#!/usr/bin/env bash
# Resolve the digest behind every published flavor tag of GHCR package $PKG
# (owner $OWNER) into digests.txt and verify each belongs to release
# $TAG/$RELEASE_SHA: its build commit's docker/ + toolbelt/ trees must match
# the release's, and an already-published patch tag must not move to another
# digest. $ALLOW_DRIFT=1 downgrades tree drift to a warning. Needs $GH_TOKEN.
set -euo pipefail

# --paginate concatenates one JSON array per page; --jq '.[]' streams the
# elements so `jq -s` can make them one array again.
gh api "/users/${OWNER}/packages/container/${PKG}/versions?per_page=100" \
    --paginate --jq '.[]' | jq -s '.' > versions.json

# The digest (GHCR calls it `name`) of the version carrying a tag.
digest_of() {
  jq -r --arg t "$1" \
    'map(select(.metadata.container.tags | index($t))) | .[0].name // ""' versions.json
}
# A candidate tag left on that same digest, e.g. standalone-latest-<sha>.
candidate_of() {
  jq -r --arg t "$1" '
    map(select(.metadata.container.tags | index($t)))
    | .[0].metadata.container.tags // []
    | map(select(test("-[0-9a-f]{40}$"))) | .[0] // ""' versions.json
}

# What decides whether a built image belongs to this release. TWO trees since
# the toolbelt moved out of the image directories: docker/ (the image
# definitions, entrypoints, provisioning and demo profiles) and toolbelt/
# (the ck* tools and analysers baked into them). Both are build inputs, so
# both are part of the identity. 2>/dev/null: a build commit from before that
# split carries neither path, and an empty result compares unequal — which is
# the right verdict for it anyway.
image_tree_of() { # $1 = commit-ish
  printf '%s %s' \
    "$(git rev-parse "$1:docker" 2>/dev/null)" \
    "$(git rev-parse "$1:toolbelt" 2>/dev/null)"
}
RELEASE_TREE=$(image_tree_of "${RELEASE_SHA}")
drift=()
fatal=()
: > digests.txt

# Every published flavor. standalone leads because the unprefixed :v1 /
# :v1.2.3 tags alias it — it is the image the extension template's compose
# stacks run.
for flavor in standalone drupal10 drupal11 wordpress joomla \
              standalone-demo drupal10-demo drupal11-demo \
              wordpress-demo joomla-demo; do
  digest=$(digest_of "${flavor}")
  if [ -z "${digest}" ]; then
    fatal+=("no published image behind :${flavor}")
    continue
  fi
  printf '%s\t%s\n' "${flavor}" "${digest}" >> digests.txt

  # Provenance: which commit was this digest built from, and do its docker/ +
  # toolbelt/ trees match the release?
  candidate=$(candidate_of "${flavor}")
  build_sha="${candidate##*-}"
  if [ -z "${candidate}" ]; then
    echo "::warning::cannot tell which commit :${flavor} was built from (candidate tag already pruned)"
  elif ! git cat-file -e "${build_sha}^{commit}" 2>/dev/null; then
    echo "::warning::build commit ${build_sha} of :${flavor} is not in this checkout"
  elif [ "$(image_tree_of "${build_sha}")" != "${RELEASE_TREE}" ]; then
    drift+=(":${flavor} was built from ${build_sha:0:12}, whose docker/ or toolbelt/ tree differs from ${TAG}")
  fi

  # Released patch tags are immutable. Re-running the same release is fine
  # (same digest); pointing v1.2.3 somewhere else is not.
  existing=$(digest_of "${flavor}-${TAG}")
  if [ -n "${existing}" ] && [ "${existing}" != "${digest}" ]; then
    fatal+=(":${flavor}-${TAG} is already published on another digest — cut a new patch version")
  fi
done

if [ ${#drift[@]} -gt 0 ]; then
  printf 'image drift: %s\n' "${drift[@]}" >&2
  if [ "${ALLOW_DRIFT}" = 1 ]; then
    echo "::warning::releasing anyway (allow_image_drift)"
  else
    echo "Wait for 'Build Dev Images' to promote this commit, or re-run this workflow with allow_image_drift." >&2
    exit 1
  fi
fi
if [ ${#fatal[@]} -gt 0 ]; then
  printf 'FATAL: %s\n' "${fatal[@]}" >&2
  exit 1
fi
cat digests.txt
