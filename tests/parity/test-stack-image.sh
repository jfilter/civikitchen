#!/usr/bin/env bash
# stack-image.sh: policy.civicrm or the image input picks the stack image,
# declaring both is refused, neither falls back.
set -euo pipefail

cd "$(dirname "$0")/../.."
script=.github/scripts/stack-image.sh
fail() { echo "FAIL: $*" >&2; exit 1; }

[ "$("$script" 6.17 '' fallback)" = ghcr.io/jfilter/civikitchen:standalone-6.17 ] || fail "policy minor"
[ "$("$script" '' ghcr.io/acme/img:1 fallback)" = ghcr.io/acme/img:1 ] || fail "image input"
[ "$("$script" '' '' fallback)" = fallback ] || fail "fallback"
[ -z "$("$script" '' '' '')" ] || fail "empty fallback stays empty"
out=$("$script" 6.17 ghcr.io/acme/img:1 fallback 2>&1) && fail "accepted policy.civicrm and image together"
grep -q "declare it once" <<<"$out" || fail "no reason for the refusal (got: $out)"
"$script" '6.17; rm -rf /' '' '' > /dev/null 2>&1 && fail "accepted a minor that is no version"

echo "stack image suite OK"
