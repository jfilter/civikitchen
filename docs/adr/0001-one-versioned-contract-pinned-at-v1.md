# ADR-0001: One versioned contract, pinned at a moving `@v1`

*Status: accepted · 2026-07-31*

## Context

Extension repos depend on four things from this repo that only work as a set.
These are the reusable workflow `extension-ci.yml`, the extension template,
`ckinit`, which stamps the template, and the images that carry the tools the
workflow calls. Before releases existed, every repo tracked
`extension-ci.yml@main` and the moving image tags. A change therefore reached
every repo the moment it was pushed. A repo could also run one week's workflow
against another week's template or image.

## Decision

Workflows, template, tools and images are released together under one
version. A release is a git tag `vX.Y.Z` plus image tags on the digests that
already passed test-then-promote. Nothing is rebuilt for a release.

Consumers pin the moving major tag, `extension-ci.yml@v1` for the workflow and
`:v1` for the images. The git tag `v1` moves to each new `v1.x.y` release and
never moves backwards.

A change that a conforming repo has to react to ships as a minor release
marked **Breaking** in the changelog. It ships only after every affected
consumer has been adjusted. A major version is reserved for changes the
consumers cannot absorb before the tag.

Exactly one canary repo calls `@main` with the moving `:standalone` image. It
declares that deviation in its `civikitchen.yaml`.

## Rationale

Releasing the parts separately is how a repo ends up running a template rule
against an image whose `ckconform` never heard of it.

Pinning `@v1` instead of full SHAs trades review coverage for patch latency.
With `@v1`, a fix in the shared pipeline reaches the fleet the moment it is
released. With SHA pins, it reaches only the repos that merge the update bot's
PR, so each release turns into one PR per consumer. Neither choice is
obviously right, and `@v1` holds until the maintainer decides otherwise.

Every `@v1` caller is a repo this project maintains. A break is therefore
cheaper to fix in the consumers than to carry as a parallel `@v2` line.

A canary exercises a change in a real repo before it is released. A second
canary would double the noise without adding a signal.

## Consequences

Whoever can move the `v1` tag can change what runs in every consumer's CI
without a PR in any of them. Tag protection narrows that window but does not
close it. The exemption from SHA pinning is recorded in `zizmor.yml`.

A release can only ship image content that is already live. `release.yml`
fails when the promoted images were built from other `docker/` or `toolbelt/`
trees than the release commit.

A change counts as breaking when a repo that was green yesterday goes red
without touching its own code. That test decides the changelog marker and the
consumer pass. It does not decide the version number.
