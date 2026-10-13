# ADR-0014: Images are tested, then promoted

*Status: accepted · 2026-06-11*

## Context

The images rebuild daily against the current CiviCRM stable release. Pushes
that touch the image inputs also rebuild the standalone flavors. The inputs
are `docker/`, `toolbelt/` and the files that the build and its tests read. A
new CiviCRM release, a Debian update or a tool change can break the build or
the boot. Local development and the canary run on the moving tags, such as
`:standalone` and `:drupal10`.

## Decision

A build pushes only candidate tags with a commit suffix. The moving tags move
to a candidate after its tests pass. For the dev images these are smoke and
end-to-end tests, and for the demo images smoke tests.

The standalone candidate is gated against several database images. The gate
proves that headless tests hit the isolated `civicrm_test` database, not
merely that a test process boots.

The daily run builds with the layer cache disabled, so `apt-get upgrade`
actually runs again.

A release does not build anything. It attaches the release tags to the digests
that the moving tags already point at (ADR-0001).

## Rationale

A broken CiviCRM release or a broken build never reaches a stable tag. The
tags keep serving the last good image until the breakage is fixed.

Without the daily run with the cache off, Debian security updates would sit
behind a cached layer until something else invalidated it.

Retagging instead of rebuilding means that a released image is exactly the
one that already passed the gates. There is no second, differently built
artifact that only the release path produces.

## Consequences

The moving tags are last-writer-wins. Image builds are therefore serialised in
one non-cancelling concurrency group, and the newest commit promotes last.

A release refuses when the promoted images were built from other `docker/` or
`toolbelt/` trees than the release commit. We call this image drift.

A failing gate leaves the moving tags where they were, and consumers do not
see that. A failed scheduled run therefore opens or updates a GitHub issue.
