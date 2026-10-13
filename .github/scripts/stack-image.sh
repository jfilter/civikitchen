#!/usr/bin/env bash
# The CiviKitchen image a stack runs: the :standalone-<minor> image of
# policy.civicrm ($1) or the caller's image input ($2), never both; else $3.
set -euo pipefail

minor="${1-}"
input="${2-}"
fallback="${3-}"

if [ -n "${minor}" ] && [ -n "${input}" ]; then
  echo "civikitchen.yaml sets policy.civicrm (${minor}) and the caller passes image (${input}) — declare it once" >&2
  exit 1
fi
if [ -z "${minor}" ]; then
  echo "${input:-${fallback}}"
  exit 0
fi
if [[ ! "${minor}" =~ ^[0-9]+\.[0-9]+$ ]]; then
  echo "policy.civicrm is not a CiviCRM minor like 6.17: '${minor}'" >&2
  exit 1
fi
echo "ghcr.io/civico-de/civikitchen:standalone-${minor}"
