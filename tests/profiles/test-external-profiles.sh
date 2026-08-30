#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bundled/builtin" "$work/external/custom"
printf '{"description":"built in","dependencies":[]}\n' > "$work/bundled/builtin/profile.json"
printf '{"description":"custom","dependencies":[]}\n' > "$work/external/custom/profile.json"

CK_PROFILE_DIR="$work/bundled"
CK_PROFILE_SCHEMA_DIR="$root/packages/civicrm-profile-schema"
CIVIKITCHEN_PROFILE_PATH="$work/external"
# shellcheck source=../../docker/runtime/provision.sh
source "$root/docker/runtime/provision.sh"

[ "$(ck_resolve_profile custom)" = "$work/external/custom" ] || { echo "external profile not resolved" >&2; exit 1; }
[ "$(ck_resolve_profile builtin)" = "$work/bundled/builtin" ] || { echo "bundled profile not resolved" >&2; exit 1; }
ck_validate_profile "$work/external/custom"
if ck_require_profile_trust "$work/external/custom" >/dev/null 2>&1; then
  echo "untrusted external profile passed" >&2; exit 1
fi
CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1
ck_require_profile_trust "$work/external/custom"
ck_require_profile_trust "$work/bundled/builtin"

printf '{"description":"one","dependencies":[],"authx":{"header_cred":["api_key"]},"apiUsers":[{"username":"apiOne","role":"civikitchen_api_one","permissions":["access CiviCRM"]}]}\n' > "$work/external/custom/profile.json"
mkdir -p "$work/external/second"
printf '{"description":"two","dependencies":[],"authx":{"header_cred":["api_key"]},"apiUsers":[{"username":"apiTwo","role":"civikitchen_api_two","permissions":["access CiviCRM"]}]}\n' > "$work/external/second/profile.json"
[ "$(ck_resolve_authx_policy "$work/external/custom" "$work/external/second")" = api_key ] \
  || { echo "equal AuthX policies did not converge" >&2; exit 1; }
printf '{"description":"two","dependencies":[],"authx":{"header_cred":["pass"]},"apiUsers":[{"username":"apiTwo","role":"civikitchen_api_two","permissions":["access CiviCRM"]}]}\n' > "$work/external/second/profile.json"
if ck_resolve_authx_policy "$work/external/custom" "$work/external/second" >/dev/null 2>&1; then
  echo "conflicting AuthX policies passed" >&2; exit 1
fi

mkdir -p "$work/external/builtin"
cp "$work/bundled/builtin/profile.json" "$work/external/builtin/profile.json"
if ck_resolve_profile builtin >/dev/null 2>&1; then echo "ambiguous profile passed" >&2; exit 1; fi
if ck_resolve_profile ../escape >/dev/null 2>&1; then echo "profile traversal passed" >&2; exit 1; fi

printf '{"description":"broken","dependencies":[],"surprise":true}\n' > "$work/external/custom/profile.json"
if ck_validate_profile "$work/external/custom" >/dev/null 2>&1; then echo "invalid external profile passed schema" >&2; exit 1; fi

echo "external profile tests passed"
