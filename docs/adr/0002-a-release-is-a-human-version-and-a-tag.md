# ADR-0002: A release is a human-written version and a tag; the rest is automated

*Status: accepted · 2026-07-31*

## Context

An extension release needs a version in `info.xml`, a tag, a distribution
archive without development files, a proof that the archive installs, and a
GitHub release. release-please is the obvious off-the-shelf candidate for the
version and changelog part.

## Decision

- A release is three steps in order: a commit that bumps `info.xml`
  `<version>` (and `composer.json`, and `CHANGELOG.md` where the repo keeps
  one), a tag `v<version>`, and everything after the tag.
- The first two steps stay manual. Everything after the tag is the shared
  `extension-release.yml`: `ckrelease check`, `ckrelease dist`, an install
  smoke test into a fresh CiviCRM, and the GitHub release.
- Release notes come from GitHub's generator; tagging is plain git.
  `ckrelease` is only the CiviCRM-shaped part. No release-please.

## Rationale

- A version number is a compatibility claim about a change, and nothing
  derives that from a diff.
- release-please automates the half that two `gh` flags already cover (notes,
  tag) and does nothing for the half that costs real money: a wrong archive
  reaches every site that installs the extension. `info.xml` as the source of
  truth would need a generic-updater config per repo.
- Adopting it would mean conventional-commit discipline across the fleet and
  two more config files per repo, and still a second workflow for the
  archive.

## Consequences

- Steps 1 and 2 can drift apart: a version bumped past without a tag exists in
  the history and on no site. `ckconform`'s `release-tags` check catches it
  after the fact, and `policy.untagged_versions` names a version that will
  never be released, with a reason.
