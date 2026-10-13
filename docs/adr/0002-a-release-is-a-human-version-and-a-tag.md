# ADR-0002: A release is a human-written version and a tag; the rest is automated

*Status: accepted · 2026-07-31*

## Context

An extension release needs several things. It needs a version in `info.xml`, a
tag, and a distribution archive without development files. It also needs
proof that the archive installs, and a GitHub release. release-please is the
obvious off-the-shelf candidate for the version and changelog part.

## Decision

A release is three steps in order. The first is a commit that bumps
`<version>` in `info.xml`, along with `composer.json` and, where the repo keeps
one, `CHANGELOG.md`. The second is a tag `v<version>`. The third is everything
after the tag.

The first two steps stay manual. Everything after the tag is the shared
`extension-release.yml`. It runs `ckrelease check` and `ckrelease dist`, an
install smoke test into a fresh CiviCRM, and the GitHub release.

Release notes come from GitHub's generator, and tagging is plain git.
`ckrelease` covers only the part that is specific to CiviCRM. We do not use
release-please.

## Rationale

A version number is a compatibility claim about a change, and nothing can
derive that claim from a diff.

release-please automates the half of the work that two `gh` flags already
cover, the notes and the tag. It does nothing for the half that costs real
money, because a wrong archive reaches every site that installs the
extension. Keeping `info.xml` as the source of truth would also need a
generic-updater config per repo.

Adopting it would mean conventional-commit discipline across the fleet and two
more config files per repo. It would still need a second workflow for the
archive.

## Consequences

The first two steps can drift apart. A version that was bumped past without a
tag exists in the history but on no site. `ckconform`'s `release-tags` check
catches this after the fact. `policy.untagged_versions` names a version that
will never be released, together with a reason.
