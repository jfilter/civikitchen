# ADR-0001: One versioned contract, pinned at a moving `@v1`

*Status: accepted · 2026-07-31*

## Context

Extension repos depend on four things from this repo that only work as a set:
the reusable workflow `extension-ci.yml`, the extension template, `ckinit`,
which stamps it, and the images that carry the tools the workflow calls.
Before releases existed, every repo tracked `extension-ci.yml@main` and the
moving image tags, so a change reached every repo the moment it was pushed,
and a repo could run one week's workflow against another week's template or
image.

## Decision

- Workflows, template, tools and images are released together under one
  version. A release is a git tag `vX.Y.Z` plus image tags on the digests that
  already passed test-then-promote; nothing is rebuilt for a release.
- Consumers pin the moving major tag: `extension-ci.yml@v1` and `:v1`. The
  git `v1` tag moves to each new `v1.x.y` release, never backwards.
- A change a conforming repo has to react to ships as a minor release marked
  **Breaking** in the changelog, after every affected consumer has been
  adjusted. A major version is reserved for changes the consumers cannot absorb
  before the tag.
- Exactly one canary repo calls `@main` with the moving `:standalone` image and
  declares that deviation in its `civikitchen.yaml`.

## Rationale

- Releasing the parts separately is how a repo ends up running a template rule
  against an image whose `ckconform` never heard of it.
- `@v1` against full SHA pins trades review coverage for patch latency. With
  `@v1`, a fix in the shared pipeline reaches the fleet the moment it is
  released. With SHA pins, it reaches whichever repos merge the update bot's
  PR, which turns each release into one PR per consumer. Neither is obviously
  right; `@v1` holds until the maintainer decides otherwise.
- Every `@v1` caller is a repo this project maintains, so a break is cheaper to
  fix in the consumers than to carry as a parallel `@v2` line.
- A canary exercises a change in a real repo before it is released. A second
  canary doubles the noise without adding a signal.

## Consequences

- Whoever can move the `v1` tag can change what runs in every consumer's CI
  without a PR in any of them. Tag protection narrows that window; it does not
  close it. The exemption from SHA pinning is recorded in `zizmor.yml`.
- A release can only ship image content that is already live: `release.yml`
  fails when the promoted images were built from other `docker/` or
  `toolbelt/` trees than the release commit.
- The test for "breaking" is whether a repo that was green yesterday goes red
  without touching its own code. It decides the changelog marker and the
  consumer pass, not the version number.
