#!/usr/bin/env bash
# Diff the regenerated catalogs beyond the version lines. -I twice: the
# release is embedded as CORE_VERSION and in "Generated from" headers, and a
# diff of only those lines is a release with nothing catalog-relevant in it.
# Fails (with a job-summary report on $LATEST vs $PINNED) when a bump is due.
set -euo pipefail

if git diff --quiet -I "CORE_VERSION = '" -I 'Generated from CiviCRM ' ; then
  echo "### Catalog preview: no catalog-relevant changes up to ${LATEST} (pinned: ${PINNED})" \
    >> "$GITHUB_STEP_SUMMARY"
  exit 0
fi
{
  echo "### Catalog preview: ${LATEST} would change the catalogs (pinned: ${PINNED})"
  echo
  echo '```'
  git diff --stat -I "CORE_VERSION = '" -I 'Generated from CiviCRM '
  echo '```'
  echo
  echo "Time to regenerate from one release and bump the pins — see the drift gates in \`make test\`."
} >> "$GITHUB_STEP_SUMMARY"
git diff --stat -I "CORE_VERSION = '" -I 'Generated from CiviCRM '
exit 1
