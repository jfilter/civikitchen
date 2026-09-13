#!/usr/bin/env bash
# The changelog gate: one grammar, three consumers — `make lint` (--lint),
# the release job (--release), and the GitHub release body (--section).
#
# There is no markdown parser in this repo, so the grammar below is the whole
# definition of the format and it is deliberately strict: anything it does not
# spell out is an error, not a tolerated variant.
#
#   <prose>                     free text before the first "## " heading
#   ## [Unreleased]             exactly once, and the first "## " heading
#   ## [X.Y.Z] - YYYY-MM-DD     one per released version, descending semver
#   ### <Section>               only Added, Changed, Deprecated, Removed,
#                               Fixed, Security; at least one per version
#   - <bullet>                  at least one per "### " section
#   [X.Y.Z]: <url>              one link reference per version heading
#
set -euo pipefail

usage() {
  cat >&2 <<'USAGE'
usage: changelog-check.sh --lint
       changelog-check.sh --release <version>
       changelog-check.sh --section <version>
USAGE
  exit 2
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
file="${CHANGELOG_FILE:-$root/CHANGELOG.md}"

mode=''
version=''
case "${1:-}" in
  --lint) mode=lint; [ "$#" -eq 1 ] || usage ;;
  --release) mode=release; [ "$#" -eq 2 ] || usage; version="$2" ;;
  --section) mode=section; [ "$#" -eq 2 ] || usage; version="$2" ;;
  *) usage ;;
esac

[ -f "$file" ] || { echo "changelog: $file does not exist" >&2; exit 1; }

if [ -n "$version" ]; then
  version="${version#v}"
  case "$version" in
    [0-9]*.[0-9]*.[0-9]*) ;;
    *) echo "changelog: '$version' is not an X.Y.Z version" >&2; exit 2 ;;
  esac
fi

# --section prints the section body — the subsections and bullets, without the
# heading, which the release already carries as its title.
if [ "$mode" = section ]; then
  body=$(awk -v want="## [$version] - " '
    index($0, want) == 1 { inside = 1; next }
    inside && (/^## / || /^\[[^]]+\]: /) { exit }
    inside { print }
  ' "$file")
  # Trim leading and trailing blank lines.
  body=$(printf '%s\n' "$body" | sed -e '/./,$!d' | awk '
    { lines[NR] = $0 }
    END { last = NR; while (last > 0 && lines[last] ~ /^[[:space:]]*$/) last--;
          for (i = 1; i <= last; i++) print lines[i] }')
  if [ -z "$body" ]; then
    echo "changelog: no section for version $version in $file" >&2
    exit 1
  fi
  printf '%s\n' "$body"
  exit 0
fi

errors=$(awk -v mode="$mode" -v want="$version" '
BEGIN {
  split("Added Changed Deprecated Removed Fixed Security", allowed, " ")
  for (i in allowed) ok[allowed[i]] = 1
  unreleased = 0; versions = 0; seen_heading = 0
  unreleased_bullets = 0; cur = ""; cur_bullets = 0; cur_sections = 0
}
function err(msg) { printf "%s:%d: %s\n", FILENAME, FNR, msg }
function close_section() {
  if (cur != "" && cur_sections == 0) err("version " cur " has no ### section")
  if (cur != "" && cur_bullets == 0) err("version " cur " has no entries")
  if (sub_open != "" && sub_bullets == 0) err("### " sub_open " has no entries")
  sub_open = ""; sub_bullets = 0
}
/^## / {
  close_section()
  cur = ""; cur_bullets = 0; cur_sections = 0
  if ($0 == "## [Unreleased]") {
    if (seen_heading) err("[Unreleased] must be the first ## heading")
    unreleased++
    in_unreleased = 1; seen_heading = 1
    next
  }
  in_unreleased = 0; seen_heading = 1
  if ($0 !~ /^## \[[0-9]+\.[0-9]+\.[0-9]+\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$/) {
    err("bad version heading: " $0)
    next
  }
  v = $0; sub(/^## \[/, "", v); sub(/\].*$/, "", v)
  d = $0; sub(/^.* - /, "", d)
  versions++
  order[versions] = v
  date[v] = d
  if (v in date_seen) err("duplicate version heading " v)
  date_seen[v] = 1
  cur = v
  next
}
/^### / {
  if (sub_open != "" && sub_bullets == 0) err("### " sub_open " has no entries")
  name = substr($0, 5)
  if (!(name in ok)) err("unknown subsection: " name)
  if (cur == "" && !in_unreleased) err("### outside any version section")
  cur_sections++
  sub_open = name; sub_bullets = 0
  next
}
/^- / {
  cur_bullets++; sub_bullets++
  if (in_unreleased) unreleased_bullets++
}
/^\[[^]]+\]: / {
  ref = $0; sub(/^\[/, "", ref); sub(/\]:.*$/, "", ref)
  linkref[ref] = 1
}
END {
  close_section()
  if (unreleased != 1) err("expected exactly one \"## [Unreleased]\" heading, found " unreleased)
  if (versions == 0) err("no version sections")
  for (i = 1; i < versions; i++) {
    if (cmp(order[i], order[i + 1]) <= 0)
      err("versions out of descending order: " order[i] " before " order[i + 1])
  }
  for (i = 1; i <= versions; i++) {
    if (!(order[i] in linkref)) err("no link reference for [" order[i] "]")
  }
  if (!("Unreleased" in linkref)) err("no link reference for [Unreleased]")
  if (mode == "release") {
    if (!(want in date_seen)) err("version " want " has no changelog section")
    else if (date[want] > today) err("version " want " is dated " date[want] ", in the future")
    if (unreleased_bullets > 0)
      err("[Unreleased] still has entries — move them under [" want "]")
  }
}
function cmp(a, b,   pa, pb, i) {
  split(a, pa, "."); split(b, pb, ".")
  for (i = 1; i <= 3; i++) {
    if (pa[i] + 0 > pb[i] + 0) return 1
    if (pa[i] + 0 < pb[i] + 0) return -1
  }
  return 0
}
' today="$(date -u +%F)" "$file" || true)

if [ -n "$errors" ]; then
  printf '%s\n' "$errors" >&2
  echo "changelog: the format is Keep a Changelog 1.1.0 — see the grammar in $(basename "$0")" >&2
  exit 1
fi

if [ "$mode" = release ]; then
  echo "changelog: $version is documented"
else
  echo "changelog clean ($file)"
fi
