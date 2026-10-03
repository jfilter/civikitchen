# ADR-0006: Lockstep releases in a repository of several extensions

*Status: accepted · 2026-09-19*

## Context

In a repository of several extensions (ADR-0005), the release checks read
every `v*` tag of the repository while each extension has its own `info.xml`,
and `extension-release.yml` mapped one tag to one root `info.xml`.

## Decision

- All extensions of the repository carry the same `<version>` and
  `<releaseDate>`; the `monorepo-version-lockstep` check enforces it.
- One `vX.Y.Z` tag releases all of them as one GitHub release.
- `extension-release.yml` takes a `stage` input: `release` (the default; one
  extension builds and publishes), `build` (build, verify and smoke-test one
  extension's archive) and `publish` (build nothing; publish one release with
  every archive of the run). The root caller has one `build` job per extension
  and one `publish` job that needs all of them.
- A build job whose extension `<requires>` a same-repository extension installs
  that extension's archive from the same run in its smoke test, and `needs` its
  job.

## Rationale

- With every `info.xml` moving together, the tag namespace, `release-tags`,
  `release-tag-coherence` and the Latest computation stay exactly as they are.
- Rejected: per-extension tags (`<key>-vX.Y.Z`). They need a tag prefix in
  `ckrelease`, the release workflow, the publish flags, four `ckconform` checks
  and every deploy tool that reads tags, for extensions that are deployed
  together anyway. A repository that needs independent versions should be
  split.
- One workflow file with a `stage` input, not a separate publish workflow: a
  reusable workflow cannot call a sibling at its own resolved SHA, so a second
  file would duplicate the build or pin it to `@v1`.
- The publish flags (pre-release, Latest) are computed in the publish job,
  the one that knows the tag and the whole release.
- No pin can name a release that the current tag is still creating, so a
  same-repository dependency comes from the same run.

## Consequences

- Callers grant `contents: write` to every job calling the workflow; the build
  job narrows itself to read.
- A version bump touches every extension's `info.xml`, even an unchanged one.
