# ADR-0007: The container sees the repository at a second, neutral path

*Status: accepted · 2026-09-19*

## Context

The managed compose mount `..:/var/www/html/ext/<file>` (info.xml `<file>`)
gives CiviCRM the extension at the path provisioning enables and
cross-extension paths rely on.
In a repository of several extensions (ADR-0005), `..` is the extension
directory, so `.git` stays outside the container: `cklint` reported "no
changed PHP files" with exit 0, and `ckfmt` exited 2.

## Decision

- For an extension below the repository root, `ckinit` adds one managed
  mount, `../..:/civikitchen-repo`.
- The git-reading tools (`cklint`, `ckfmt`, `ckconform`) run in
  `/civikitchen-repo/<dir>`, which is the same host directory as `ext/<file>`,
  so writes land in the right place.
- Everything that boots CiviCRM (`cv`, PHPUnit, PHPStan, lifecycle probes)
  stays on `ext/<file>`.

## Rationale

Measured on the `:v1` image with a two-extension repository:

- Rejected: `.git` bound into the extension directory, or `GIT_DIR` /
  `GIT_WORK_TREE`. The index holds `<dir>/…` paths, the tools are handed paths
  that do not exist, and `ckfmt` answers "all files are already formatted"
  with exit 0.
- Rejected: the repository root mounted under `ext/`. Provisioning enables
  nothing ("no readable info.xml key"), and siblings land at
  `ext/<repo>/<dir>` instead of `ext/<file>`.

## Consequences

- `git diff --name-only` is relative to the repository root, so
  `Files::changedPhp()` passes `--relative`. Without it, the mago stage lints
  nothing and sees the neighbour's files.
- `safe.directory` names the worktree root, not the working directory.
- The dev compose file is seeded, not managed, so an existing repository adds
  the mount by hand; only the CI compose file is checked for it.
