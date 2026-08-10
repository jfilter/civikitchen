#!/usr/bin/env bash
# Resolve the CiviCRM version every image bakes: current upstream stable plus
# the supported extra standalone minors, each resolved to its newest patch
# release. Emits stable/minor/standalone_versions outputs. The extras come
# from CK_STANDALONE_EXTRA_MINORS in toolbelt/versions.env; a non-empty
# $EXTRA_MINORS (the workflow_dispatch input) replaces the list for one run.
set -euo pipefail

if [ -z "${EXTRA_MINORS:-}" ]; then
  # shellcheck source=/dev/null
  source toolbelt/versions.env
  EXTRA_MINORS="${CK_STANDALONE_EXTRA_MINORS:-}"
fi

STABLE=$(curl -fsS https://latest.civicrm.org/stable.php)
echo "upstream stable: ${STABLE}"
if ! [[ "${STABLE}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "unexpected version string from stable.php: '${STABLE}'" >&2
  exit 1
fi
MINOR=${STABLE%.*}
# EXACT versions, because each one names a release tarball — the standalone
# image installs CiviCRM itself since it stopped building FROM civicrm/civicrm
# (whose Docker Hub tags lagged the release). Requested extra minors flow
# through the whole standalone chain (build → merge → smoke → e2e → promote)
# via this one array, resolved to their newest patch release here.
VERSIONS_ARR=("${STABLE}")
IFS=',' read -ra EXTRAS <<< "${EXTRA_MINORS:-}"
for v in "${EXTRAS[@]}"; do
  v="${v// /}"
  [ -z "${v}" ] && continue
  if ! [[ "${v}" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "invalid extra standalone minor: '${v}'" >&2
    exit 1
  fi
  [ "${v}" = "${MINOR}" ] && continue
  exact=$(curl -fsS https://latest.civicrm.org/versions.json \
    | jq -re --arg m "${v}" '.[$m].releases[-1].version')
  if ! [[ "${exact}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "could not resolve minor ${v} to a release via versions.json" >&2
    exit 1
  fi
  VERSIONS_ARR+=("${exact}")
done
# The tarball is the build input; a version without one should fail HERE with
# a message, not twelve minutes later on a Dockerfile curl.
for v in "${VERSIONS_ARR[@]}"; do
  curl -fsIL --retry 2 -o /dev/null \
      "https://download.civicrm.org/civicrm-${v}-standalone.tar.gz" \
    || { echo "no standalone tarball for ${v} on download.civicrm.org" >&2; exit 1; }
done
VERSIONS=$(printf '"%s",' "${VERSIONS_ARR[@]}")
VERSIONS="[${VERSIONS%,}]"
echo "standalone versions: ${VERSIONS}"
{
  echo "stable=${STABLE}"
  echo "minor=${MINOR}"
  echo "standalone_versions=${VERSIONS}"
} >> "$GITHUB_OUTPUT"
