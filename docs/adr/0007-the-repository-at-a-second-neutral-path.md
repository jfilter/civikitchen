# ADR-0007: The container sees the repository at a second, neutral path

*Status: accepted · 2026-09-19*

## Context

The managed compose mount `..:/var/www/html/ext/<file>` uses the `<file>`
value from `info.xml`. It gives CiviCRM the extension at the path that
provisioning enables and that cross-extension paths rely on. In a repository
of several extensions (ADR-0005), `..` is the extension directory, so `.git`
stays outside the container. As a result `cklint` reported "no changed PHP
files" with exit 0, and `ckfmt` exited 2.

## Decision

For an extension below the repository root, `ckinit` adds one managed mount,
`../..:/civikitchen-repo`.

The git-reading tools `cklint`, `ckfmt` and `ckconform` run in
`/civikitchen-repo/<dir>`. That is the same host directory as `ext/<file>`, so
their writes land in the right place.

Everything that boots CiviCRM stays on `ext/<file>`. That covers `cv`,
PHPUnit, PHPStan and the lifecycle probes.

## Rationale

We measured the alternatives on the `:v1` image with a two-extension
repository and rejected two of them.

Binding `.git` into the extension directory, or setting `GIT_DIR` and
`GIT_WORK_TREE`, does not work. The index holds paths of the form `<dir>/…`,
so the tools are handed paths that do not exist. `ckfmt` then answers "all
files are already formatted" with exit 0.

Mounting the repository root under `ext/` does not work either. Provisioning
enables nothing and reports "no readable info.xml key". Siblings land at
`ext/<repo>/<dir>` instead of `ext/<file>`.

## Consequences

`git diff --name-only` is relative to the repository root, so
`Files::changedPhp()` passes `--relative`. Without it, the mago stage lints
nothing and sees the neighbour's files.

`safe.directory` names the worktree root, not the working directory.

The dev compose file is seeded, not managed. An existing repository therefore
adds the mount by hand, and only the CI compose file is checked for it.
