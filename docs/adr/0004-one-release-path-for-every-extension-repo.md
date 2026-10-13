# ADR-0004: One release path for every extension repo

*Status: accepted · 2026-09-14*

## Context

An extension repo could reach a tagged release in three ways. It could call
the reusable `extension-release.yml`. It could use a deploy tool that bumped
`info.xml`, committed and tagged locally. Or it could be released by hand, or
not at all. The caller was not template-managed, so adoption happened repo by
repo, and the `release-workflow` check only warned.

Cutting a release across the fleet showed what this cost. Some versions had
been bumped past without a tag. Some version strings were outside SemVer, in
the form `X.Y.Z.W`, which no release trigger accepts. Some callers had a tag
filter that never matched a pre-release, because GitHub matches tag filters
against the whole ref name. And the release commit had two spellings.

## Decision

Every extension repo calls `extension-release.yml` or declares
`policy.release: none` with a reason. There is nothing in between, and
`release-workflow` fails instead of warning.

The caller `.github/workflows/release.yml` is a template-managed file. That
includes the trigger that matches plain and pre-release tags, so the template
drift job catches a stale caller.

The `<version>` in `info.xml` is `X.Y.Z` or `X.Y.Z-<pre-release>`. The
`version-format` check fails on anything else.

The release commit is spelled `Release X.Y.Z`, in the docs and in every tool
that writes one.

A repo that has never released declares `release: none` with the reason
`TODO: not released yet; switch to the managed release caller with the first
release`. The text is the same everywhere, so the remaining repos can be found
by searching for it.

A repo whose `info.xml` is below an existing tag releases a version above that
tag. Tags are not deleted.

`require_changelog` stays opt-in. The smoke test stays on by default. A repo
whose install cannot be reached headless sets `smoke_test: false` with a
comment giving the reason. Both inputs live below the managed block of the
caller, so `ckinit --update` keeps them.

## Rationale

A version that no release path can publish is a failure, not a style issue.
The format check therefore fails rather than warns.

With the caller managed, a fix to the trigger reaches every repo through
`ckinit --update` instead of one hand edit per repo.

## Consequences

GitHub runs the release workflow from the tagged commit. A historic tag that
closes a `release-tags` gap must go on the last commit that carried the
version and does not trigger a release. Otherwise it publishes that old code.

A commit from before `civikitchen.yaml` replaced `.ckconform` cannot be
released through `@v1`. Tagging it records history and publishes nothing.

The smoke test installs a private dependency from `<requires>` only from a
pinned release of that dependency. Private dependencies therefore release
before the repos that pin them.
