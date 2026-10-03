# ADR-0003: Release exclusions live in civikitchen, not in `.gitattributes`

*Status: accepted · 2026-07-31*

## Context

The release archive is built from tracked files, but some committed files have
no business on a production site: CI configuration, tests, linter configs,
lockfiles. `.gitattributes export-ignore` is the git-native way to say this.

## Decision

- One central list of development paths is left out of every archive. It
  lives in `ckconform` (`DistPaths`), and `ckrelease` reads it through
  `ckconform --dist-paths`.
- A repo adds to it or excepts from it under `policy.dist` in its
  `civikitchen.yaml`: `exclude`, `include` with a reason, and `build` for
  output git does not track.
- `vendor/` and `dist/` are deliberately not on the central list.
- `git archive` still honours a repo's own `export-ignore` attributes on top.
  They are allowed, but they are not where the standard lives.

## Rationale

- `.gitattributes` is a template-managed file: civikitchen owns its bytes and
  every repo's CI compares them. A packaging change there costs a fleet-wide
  drift round and a contract version bump, and the list would exist in as many
  copies as there are repos, free to drift apart.
- A central list plus a declared, reasoned per-repo exception is the pattern
  already used for coverage floors and template deviations.
- A repo commits `vendor/` because the site needs it at runtime, or sets
  `composer_install: true` so the release bundles the locked production tree.
  A packager that silently drops runtime code is worse than one that ships a
  test file. For a frontend-building extension, a committed `dist/` *is* the
  shipped artifact.

## Consequences

- `ckrelease verify` re-checks the built archive for excluded names at any
  depth, since the build only excludes at the root.
