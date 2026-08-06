#!/usr/bin/env bash
# Prune stale sha-candidate GHCR versions of package $PKG under $OWNER.
# Reference-aware: shas still reachable from a stable tag are protected, and
# only versions older than 2 days whose tags are ALL candidate tags of
# unprotected shas are deleted. $DRY_RUN=1 only logs. Needs $GH_TOKEN.
set -euo pipefail

gh api "/users/${OWNER}/packages/container/${PKG}/versions?per_page=100" \
    --paginate > versions.json

# A candidate tag is "<anything>-<40-hex-sha>" or that plus an arch suffix.
# Shas still reachable from a stable tag are protected.
jq -r '
  def sha_of: capture("-(?<sha>[0-9a-f]{40})(-(amd64|arm64))?$").sha;
  def is_sha_tag: test("-[0-9a-f]{40}(-(amd64|arm64))?$");
  [ .[] | select(.metadata.container.tags | length > 0)
        | select([.metadata.container.tags[] | is_sha_tag] | all | not)
        | .metadata.container.tags[] | select(is_sha_tag) | sha_of
  ] | unique | .[]' versions.json > protected-shas.txt
echo "protected shas:"; cat protected-shas.txt

jq -r --slurpfile protected <(jq -R . protected-shas.txt | jq -s .) '
  def sha_of: capture("-(?<sha>[0-9a-f]{40})(-(amd64|arm64))?$").sha;
  def is_sha_tag: test("-[0-9a-f]{40}(-(amd64|arm64))?$");
  .[] | select(.metadata.container.tags | length > 0)
      | select([.metadata.container.tags[] | is_sha_tag] | all)
      | select([.metadata.container.tags[] | sha_of | IN($protected[0][])] | any | not)
      | select(.updated_at < (now - 2*86400 | todate))
      | "\(.id)\t\(.metadata.container.tags | join(","))"
' versions.json > victims.tsv

if [ ! -s victims.tsv ]; then echo "nothing to prune"; exit 0; fi
echo "pruning $(wc -l < victims.tsv) versions:"; cat victims.tsv
if [ "${DRY_RUN}" = "1" ]; then echo "dry run - nothing deleted"; exit 0; fi
while IFS=$'\t' read -r id tags; do
    echo "deleting version ${id} (${tags})"
    gh api -X DELETE "/users/${OWNER}/packages/container/${PKG}/versions/${id}"
done < victims.tsv
