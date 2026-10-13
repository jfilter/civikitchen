# ADR-0006: Lockstep releases in a repository of several extensions

*Status: accepted · 2026-09-19*

## Context

In a repository of several extensions (ADR-0005), the release checks read
every `v*` tag of the repository, while each extension has its own
`info.xml`. `extension-release.yml` mapped one tag to one root `info.xml`.

## Decision

All extensions of the repository carry the same `<version>` and
`<releaseDate>`. The `monorepo-version-lockstep` check enforces this. One
`vX.Y.Z` tag releases all of them as one GitHub release.

`extension-release.yml` takes a `stage` input with three values. The default,
`release`, builds and publishes one extension. `build` builds, verifies and
smoke-tests one extension's archive. `publish` builds nothing and publishes
one release with every archive of the run. The root caller has one build job
per extension and one publish job that needs all of them.

Some extensions require another extension from the same repository. The build
job of such an extension installs that dependency's archive from the same run
in its smoke test, and it lists the dependency's job under `needs`.

## Rationale

When every `info.xml` moves together, the tag namespace stays exactly as it
is. So do the `release-tags` and `release-tag-coherence` checks and the
computation of which release is Latest.

We rejected per-extension tags of the form `<key>-vX.Y.Z`. They would need a
tag prefix in `ckrelease`, in the release workflow and in the publish flags.
They would also touch four `ckconform` checks and every deploy tool that reads
tags. All of that would serve extensions that are deployed together anyway. A
repository that needs independent versions should be split.

We chose one workflow file with a `stage` input over a separate publish
workflow. A reusable workflow cannot call a sibling at its own resolved SHA.
A second file would therefore either duplicate the build or pin it to `@v1`.

The publish flags for pre-release and Latest are computed in the publish job,
because that job is the one that knows the tag and the whole release.

No pin can name a release that the current tag is still creating. A
same-repository dependency therefore comes from the same run.

## Consequences

Callers grant `contents: write` to every job that calls the workflow. The
build job narrows its own permission to read.

A version bump touches every extension's `info.xml`, even an unchanged one.
