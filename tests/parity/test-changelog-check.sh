#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
check="$root/.github/scripts/changelog-check.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

valid="$work/valid.md"
cat > "$valid" <<'MD'
# Changelog

Prose that the grammar ignores.

## [Unreleased]

## [1.2.0] - 2026-01-05

### Added

- A thing.

### Fixed

- Another thing.

## [1.1.0] - 2026-01-01

### Changed

- An older thing.

[Unreleased]: https://example.org/compare/v1.2.0...HEAD
[1.2.0]: https://example.org/compare/v1.1.0...v1.2.0
[1.1.0]: https://example.org/releases/tag/v1.1.0
MD

# Each case: a description, the sed-free fixture body, and the expected message.
expect_pass() {
  local label="$1" f="$2"; shift 2
  if ! CHANGELOG_FILE="$f" "$check" "$@" >"$work/out" 2>&1; then
    echo "expected pass: $label" >&2; cat "$work/out" >&2; exit 1
  fi
}

expect_fail() {
  local label="$1" f="$2" needle="$3"; shift 3
  if CHANGELOG_FILE="$f" "$check" "$@" >"$work/out" 2>&1; then
    echo "expected failure: $label" >&2; cat "$work/out" >&2; exit 1
  fi
  if ! grep -q "$needle" "$work/out"; then
    echo "wrong message for: $label" >&2; cat "$work/out" >&2; exit 1
  fi
}

expect_pass "valid file lints" "$valid" --lint
expect_pass "valid file releases" "$valid" --release 1.2.0
expect_pass "a v-prefixed version is accepted" "$valid" --release v1.2.0

expect_fail "missing version section" "$valid" \
  "version 9.9.9 has no changelog section" --release 9.9.9

future="$work/future.md"
sed 's/2026-01-05/2999-01-05/' "$valid" > "$future"
expect_fail "a future date is not releasable" "$future" \
  "in the future" --release 1.2.0

leftover="$work/leftover.md"
awk '{ print } /^## \[Unreleased\]$/ { print ""; print "### Added"; print ""; print "- Not moved yet." }' \
  "$valid" > "$leftover"
expect_pass "leftover unreleased entries still lint" "$leftover" --lint
expect_fail "leftover unreleased entries block a release" "$leftover" \
  "still has entries" --release 1.2.0

unknown="$work/unknown.md"
sed 's/^### Added$/### Improved/' "$valid" > "$unknown"
expect_fail "unknown subsection" "$unknown" "unknown subsection: Improved" --lint

order="$work/order.md"
sed -e 's/^## \[1\.2\.0\] - 2026-01-05$/## [1.0.0] - 2026-01-05/' \
    -e 's/^\[1\.2\.0\]:/[1.0.0]:/' "$valid" > "$order"
expect_fail "out-of-order versions" "$order" "out of descending order" --lint

noref="$work/noref.md"
grep -v '^\[1\.1\.0\]:' "$valid" > "$noref"
expect_fail "missing link reference" "$noref" "no link reference for \[1.1.0\]" --lint

empty="$work/empty.md"
sed '/^- An older thing\.$/d' "$valid" > "$empty"
expect_fail "empty version section" "$empty" "has no entries" --lint

twice="$work/twice.md"
awk '{ print } /^## \[Unreleased\]$/ && !done { print ""; print "## [Unreleased]"; done = 1 }' \
  "$valid" > "$twice"
expect_fail "two Unreleased headings" "$twice" "exactly one" --lint

CHANGELOG_FILE="$valid" "$check" --section 1.1.0 > "$work/section"
cat > "$work/section.expected" <<'MD'
### Changed

- An older thing.
MD
diff -u "$work/section.expected" "$work/section"

expect_fail "no section for an unknown version" "$valid" \
  "no section for version 9.9.9" --section 9.9.9

# The repository's own changelog is the last fixture: the grammar has to hold
# for the file it gates.
"$check" --lint >/dev/null

echo "changelog gate: ok"
