# ADR-0004: One release path for every extension repo

*Status: accepted · 2026-09-14*

## Context

An extension repo could reach a tagged release three ways: a caller of the
reusable `extension-release.yml`, a deploy tool that bumped `info.xml`,
committed and tagged locally, or by hand, or not at all. The caller was not
template-managed, so adoption was per repo and the `release-workflow` check
only warned. Cutting a release across the fleet turned up the cost:

- versions bumped past without a tag;
- version strings outside SemVer (`X.Y.Z.W`), which no release trigger accepts;
- callers whose tag filter never matched a pre-release, because GitHub matches
  tag filters against the whole ref name;
- two spellings of the release commit.

## Decision

- Every extension repo calls `extension-release.yml`, or declares
  `policy.release: none` with a reason. Nothing in between: `release-workflow`
  fails instead of warning.
- The caller `.github/workflows/release.yml` is a template-managed file,
  including the trigger that matches plain and pre-release tags, so the
  template drift job catches a stale one.
- `info.xml` `<version>` is `X.Y.Z` or `X.Y.Z-<pre-release>`. The
  `version-format` check fails on anything else.
- The release commit is spelled `Release X.Y.Z`, in the docs and in every tool
  that writes one.
- A repo that has never released declares `release: none` with the reason
  `TODO: not released yet; switch to the managed release caller with the first
  release`, the same text everywhere, so the remaining ones are found by
  searching for it.
- A repo whose `info.xml` is below an existing tag releases a version above
  that tag. Tags are not deleted.
- `require_changelog` stays opt-in. The smoke test stays on by default; a repo
  whose install cannot be reached headless sets `smoke_test: false` with a
  reason comment. Both inputs live below the managed block of the caller, so
  `ckinit --update` keeps them.

## Rationale

- A version that no release path can publish is a failure, not a style issue,
  so the format check fails rather than warns.
- With the caller managed, a fix to the trigger reaches every repo through
  `ckinit --update`, instead of one hand edit per repo.

## Consequences

- GitHub runs the release workflow from the tagged commit. A historic tag that
  closes a `release-tags` gap goes on the last commit that carried the version
  *and* does not trigger a release, otherwise it publishes that old code.
- A commit from before `civikitchen.yaml` replaced `.ckconform` cannot be
  released through `@v1`; tagging it records history and publishes nothing.
- The smoke test installs a private `<requires>` only from a pinned release of
  that dependency, so private dependencies release before the repos that pin
  them.
