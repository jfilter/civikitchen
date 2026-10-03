# ADR-0014: Images are tested, then promoted

*Status: accepted · 2026-06-11*

## Context

The images rebuild daily against the current CiviCRM stable release and on
pushes that touch the image inputs (`docker/`, `toolbelt/` and the files the
build and its tests read). A new CiviCRM release, a Debian
update or a tool change can break the build or the boot, and the moving tags
(`:standalone`, `:drupal10`, …) are what local development and the canary
run on.

## Decision

- A build pushes only commit-suffixed candidate tags. The moving tags are
  moved to a candidate after its tests pass: smoke and end-to-end tests for
  the dev images, smoke tests for the demo images.
- The standalone candidate is gated against several database images, and the
  gate proves that headless tests hit the isolated `civicrm_test` database,
  not merely that a test process boots.
- The daily run builds with the layer cache disabled, so `apt-get upgrade`
  actually re-runs.
- A release does not build anything. It attaches the release tags to the
  digests the moving tags already point at (ADR-0001).

## Rationale

- A broken CiviCRM release or a broken build never reaches a stable tag; the
  tags keep serving the last good image until the breakage is fixed.
- Without the cache-off daily run, Debian security updates would sit behind a
  cached layer until something else invalidated it.
- Retagging instead of rebuilding means a released image is exactly the one
  that already passed the gates; there is no second, differently built artifact
  that only the release path produces.

## Consequences

- The moving tags are last-writer-wins, so image builds are serialised in one
  non-cancelling concurrency group and the newest commit promotes last.
- A release refuses when the promoted images were built from other `docker/`
  or `toolbelt/` trees than the release commit ("image drift").
- A failing gate leaves the moving tags where they were, which consumers do
  not see. A failed scheduled run therefore opens or updates a GitHub issue.
