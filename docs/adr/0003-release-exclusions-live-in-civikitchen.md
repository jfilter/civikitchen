# ADR-0003: Release exclusions live in civikitchen, not in `.gitattributes`

*Status: accepted · 2026-07-31*

## Context

The release archive is built from tracked files. Some committed files have no
business on a production site, such as CI configuration, tests, linter configs
and lockfiles. Marking them `export-ignore` in `.gitattributes` is the
git-native way to say this.

## Decision

One central list of development paths is left out of every archive. It lives
in `ckconform` as DistPaths, and `ckrelease` reads it through
`ckconform --dist-paths`.

A repo adds to that list or makes exceptions from it under `policy.dist` in its
`civikitchen.yaml`. The key `exclude` adds paths, `include` excepts a path and
requires a reason, and `build` names output that git does not track.

`vendor/` and `dist/` are deliberately not on the central list.

`git archive` still honours a repo's own `export-ignore` attributes on top.
They are allowed, but they are not where the standard lives.

## Rationale

`.gitattributes` is a template-managed file. civikitchen owns its bytes, and
every repo's CI compares them. A packaging change there would cost a
fleet-wide drift round and a contract version bump. The list would also exist
in as many copies as there are repos, free to drift apart.

A central list plus a declared per-repo exception with a reason is the pattern
already used for coverage floors and template deviations.

A repo commits `vendor/` because the site needs it at runtime. Alternatively
it sets `composer_install: true`, so the release bundles the locked production
tree. A packager that silently drops runtime code is worse than one that ships
a test file. For an extension that builds a frontend, a committed `dist/` is
the shipped artifact.

## Consequences

`ckrelease verify` re-checks the built archive for excluded names at any
depth, because the build only excludes them at the root.
