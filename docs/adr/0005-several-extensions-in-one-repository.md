# ADR-0005: Several extensions in one repository

*Status: accepted · 2026-09-19*

## Context

Some repositories hold several CiviCRM extensions that ship together: the root
carries no `info.xml`, each direct subdirectory is an extension with its own
`info.xml`, `civikitchen.yaml`, `composer.json` and tests, and the extensions
usually `<requires>` one base extension from the same repository.

The shared tooling assumed one extension at the repository root.
`extension-ci.yml` required `info.xml` at the checkout root. `ckinit` stamped
root-only files (`.github/workflows/ci.yml`, `renovate.json`) into every
subdirectory, where GitHub and Renovate never read them. A repository in this
layout therefore wrote its own root workflow, which ran none of the real gates.

Out of scope: extensions nested deeper than one level, and a repository that is
an extension at its root and holds more below it.

## Decision

- **`working_directory`, one job per extension.** `extension-ci.yml` and
  `extension-release.yml` take `working_directory` (default `.`). The root
  caller has one static job per extension, not a matrix. Artifact names,
  compose project names and concurrency groups carry the extension, so two
  jobs of one run cannot cancel each other or tear down each other's stack.
- **`ckinit` knows the repository root.** It finds the root by walking up to
  `.git`, as `ckconform` does. At the root it manages the root files, with one
  managed block per extension in the workflows, then runs the per-extension
  pass. Below the root it skips the root-only files.
- **Same-repository dependencies stay a compose line.** An extension mounts a
  neighbour it `<requires>` with a volume line after the managed block of its
  CI compose file, at `/var/www/html/ext/<key>`. The `monorepo-requires-mounted`
  check fails when the line is missing.
- **No new policy keys.** The layout is detected from the filesystem.

## Rationale

- `ckconform`'s workflow checks select the job whose `working_directory` names
  the extension. They cannot evaluate `${{ matrix.* }}`, so a matrix would hide
  each extension's inputs from them.
- Every push runs every extension's jobs. Path filters would save runner time,
  but they would let a change in the base extension skip the extensions that
  depend on it. On self-hosted runners the cost is accepted (measured: about
  50 s to a healthy stack, jobs in parallel).
- A compose line is the one mechanism that works for a local
  `docker compose up` and in CI alike. The check turns a forgotten line from a
  late boot failure into an early one.

## Consequences

- Adopting the layout turns the real gates on for the first time, so the first
  run is red. That debt is fixed per extension before the caller is switched
  over, not waived through `ignore_checks`.
- `Context::commitsSince()` takes the extension directory as a pathspec, so a
  neighbour's commits no longer count as unreleased changes.
- How releases work in this layout is ADR-0006; how the git-reading tools see
  the repository is ADR-0007.
