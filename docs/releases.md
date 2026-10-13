# Releases: one versioned contract

This page explains how this repo is versioned. For how an extension repo cuts
its own release with ckrelease, the dist archive and the shared
`extension-release.yml`, see [Releasing an extension](extension-releases.md).

Extension repos depend on four things from this repo, and these only work as a
set:

- the reusable workflow `.github/workflows/extension-ci.yml`,
- the extension template in `scaffold/template/extension/`, whose managed
  files every repo's CI compares,
- `scaffold/ckinit.php`, which stamps that template,
- the images the pipeline runs in, which hold cklint, ckconform, ckcoverage,
  PHPStan and the CiviCRM stack itself.

The workflow calls tools that ship in the image. It compares the calling repo
against a template that ships next to it. If these were versioned separately,
a repo could run last week's workflow against this week's template, or a
template rule against an image whose ckconform has never heard of it. So they
are released together under one version, and a consumer pins that one
version.

[ADR-0001](adr/0001-one-versioned-contract-pinned-at-v1.md) explains why there
is one contract with a moving `@v1`, and what that costs.

## What a version names

A release is a git tag on this repo plus the image tags that go with it.

| Tag | Kind | Points at |
|-----|------|-----------|
| `v1.4.2` (git) | immutable | the commit the release was cut from |
| `v1` (git) | **moving** | the newest `v1.x.y` commit |
| `:v1.4.2`, `:v1` | image | the standalone dev image of that release, which the template's compose stacks use |
| `:standalone-v1.4.2`, `:standalone-v1` | image | the same digest, spelled with its flavor |
| `:drupal10-v1`, `:wordpress-v1`, `:joomla-demo-v1`, … | image | every other published flavor, with and without the patch level |
| `:standalone`, `:drupal10`, `:*-demo`, … | image | unchanged: the **moving edge**, whatever last passed test-then-promote |

The moving tags stay exactly as they are. They are the development and canary
edge, and the daily rebuild keeps pointing them at current CiviCRM. The
*Build Git Images* workflow builds `:standalone-master` and `:standalone-beta`
from core's branches. These get no release tags, and a release does not check
that workflow.

## What a consumer pins

The template sets both pins for you:

```yaml
# .github/workflows/ci.yml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1

# .docker/docker-compose.ci.yml
image: ${CIVIKITCHEN_IMAGE:-ghcr.io/civico-de/civikitchen:v1}
```

There is no third pin to keep in sync. The drift job inside `extension-ci.yml`
checks the template out at `job.workflow_sha`, the commit the caller's own ref
resolved to. It therefore compares against the template of whatever the
caller pinned, whether `@v1`, `@main` or a SHA.

## Images are retagged, not rebuilt

Cutting a release builds nothing. `release.yml` looks up the digest each
moving tag currently points at and attaches the release tags to that same
digest. This has two consequences:

- A released image has already been through test-then-promote. The release
  path produces no second, differently built artifact.
- A release can only ship image content that is already live. If the release
  commit touches `docker/**`, `toolbelt/**` or `packages/**`, wait for a full
  *Build Dev Images* run to promote it. A push builds only the standalone
  flavors. The full run is the daily one, or you start it with
  `gh workflow run build-dev-images.yml`.

`release.yml` enforces the second point instead of trusting it. For every
flavor, it recovers the commit the live image was built from. The promoted
digest still carries its `…-<sha>` candidate tag. It then compares the
`docker/`, `toolbelt/` and `packages/` trees of that commit with those of the
release commit, and a mismatch fails the release. If the difference is
genuinely irrelevant to the built image, re-run the release from the Actions
tab with **allow_image_drift**. An example is a comment-only change under
`docker/` or `toolbelt/`.

The retag command uses `--prefer-index=false`. This matters when the source is
digest-qualified. Without the flag, Buildx may wrap a source manifest in a new
single-entry index when registry media-type discovery is inconclusive. The
layers and platforms would stay the same, but the top-level digest would
change. That would break both the immutable-tag check and idempotent release
reruns.

The flip side is that `:v1` does not follow the daily rebuild. Between
releases, the fleet keeps running the CiviCRM the last release blessed, while
`:standalone` moves on. That is intended: there is one mechanism, and image
content changes when someone decides it does. It also means a CiviCRM refresh
reaches the fleet only through a patch release. Such a release costs one tag
push and builds nothing, so cut them liberally. If `:v1` and `:standalone`
drift apart for months, the contract has stopped being maintained, though it
has not stopped being safe.

Image tags of the form `vX.Y.Z` that are already published never move onto a
different digest. Re-running a release is idempotent, but a changed release
has to be a new patch version.

## Cutting a release

Start from a clean main whose images have been built and promoted.

First, move the `[Unreleased]` bullets in
[CHANGELOG.md](https://github.com/civico-de/civikitchen/blob/main/CHANGELOG.md)
under a new `## [X.Y.Z] - YYYY-MM-DD` heading with today's date, and add the
compare link at the bottom. The file follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). `release.yml`
refuses a tag whose version has no section. It also refuses when
`[Unreleased]` still carries bullets, which means the entry was never moved.
The same section becomes the body of the GitHub release.

`scripts/release.sh` runs every gate the tag push would hit, locally and
before the tag exists. It checks that

- you are on main, the tree is clean and in sync with origin/main,
- the tag is free both locally and on origin,
- the changelog and ckinit test suites pass,
- a full *Build Dev Images* run completed successfully at or after the newest
  commit that touched the image trigger paths. It must be a daily or
  dispatched run, not a push run.

Without `--apply` the script only reports.

```bash
git switch main && git pull
make release VERSION=1.0.1            # report only
make release VERSION=1.0.1 APPLY=1    # tag and push
```

The tag push runs `release.yml`, which

1. resolves and verifies the live image digests, as described above,
2. attaches `:v1.0.1` and `:v1`, plus the per-flavor spellings, to them,
3. moves the git `v1` tag to the release commit, but only if `v1.0.1` really
   is the newest `v1.x.y`, so a late patch on an older line cannot drag `v1`
   backwards,
4. creates the GitHub release from that version's changelog section.

Nothing else is needed. Callers on `@v1` and `:v1` pick up the release on
their next run. Re-running an earlier run does not count as a next run.
GitHub resolves the reusable workflow at the first attempt and keeps that
commit for every re-run. You can see it with
`gh api repos/<owner>/<repo>/actions/runs/<id> --jq .referenced_workflows`.
To test a release, push a commit, or run the tools locally against
`ghcr.io/civico-de/civikitchen:v1`.

### Versioning rules

- **patch**: an image content refresh, a bug fix in a tool or the workflow,
  doc changes.
- **minor**: a new workflow input, a new template file, a new ckconform check
  or a new tool. A change a conforming repo has to react to is also minor, as
  long as every affected consumer is adjusted before the tag. The changelog
  marks such an entry **Breaking**.
- **major**: a change the consumers cannot absorb before the tag. Examples are
  a dropped image flavor or tag, or a contract that has to run side by side
  with the old one while repos move over.

Every `@v1` caller is a repo this project maintains. Fixing a break in the
consumers is therefore cheaper than carrying a second `@v2` line. The test
for a breaking change is still whether a repo that was green yesterday goes
red without touching its own code. The answer decides the changelog marker
and the consumer pass, not the version number.

## The canary

One repo deliberately stays on the moving edge:

```yaml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@main
    with:
      image: ghcr.io/civico-de/civikitchen:standalone
```

Its `.github/workflows/ci.yml` is managed by the template. The deviation is
therefore declared where every deviation is declared, in the repo's
`civikitchen.yaml`:

```yaml
policy:
  template_custom:
    paths:
      - .github/workflows/ci.yml
    reason: Canary tracks main ahead of the fleet
```

The canary exists so that a real repo exercises a change before it is
released, not after. A workflow change that calls something new in the image
turns the canary red from the merge until *Build Dev Images* has promoted
`:standalone`. That red run is expected and not a finding.

Pick a canary whose CI someone actually reads, and keep exactly one. A second
repo on `@main` doubles the noise without adding signal. The `image` input
keeps the image pin in the caller, so the canary needs no second exception for
its compose file.

Every other repo pins `@v1` and `:v1` and moves when a release is cut.

## Bootstrapping order (once)

The template pins `@v1` and `:v1`, refs that do not exist until the first
release. The first time through, the order is therefore fixed:

1. Merge the release machinery and the template change into main.
2. Tag `v1.0.0` and push the tag immediately. Until that finishes, repos still
   on `@main` see template drift: their caller says `@main`, while the new
   template says `@v1`. This shows up as a red drift job, which is the check
   built to notice exactly this.
3. Only then update the repos. `scaffold/ckinit --update <repo>` rewrites the
   caller and the CI compose stack to the released refs.
4. Leave the canary on `@main` with the `policy.template_custom` entry shown
   above.

Pointing a repo at `@v1` before step 2 does not produce a red run. It produces
a run that cannot start at all, because the ref does not resolve.

Before that first tag, the release workflow needs a GitHub App to move the
major tag. The run's own `GITHUB_TOKEN` cannot move a tag onto a commit whose
workflow files differ from those of the old target. The repo owner owns the
App, and it is installed on this repository only. It needs the repository
permissions Contents: read & write and Workflows: read & write. Its client id
goes into the variable `RELEASE_APP_CLIENT_ID` and its private key into the
secret `RELEASE_APP_PRIVATE_KEY`. The `git-tag` job fails when either is
missing.
