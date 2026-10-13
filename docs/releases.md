# Releases: one versioned contract

This page covers how this repo is versioned. For how an extension repo cuts
its own release, see [Releasing an extension](extension-releases.md).

Extension repos depend on four things from this repo that only work as a set:
the reusable workflow `.github/workflows/extension-ci.yml`, the extension
template in `scaffold/template/extension/`, the ckinit script that stamps it,
and the images that hold cklint, ckconform, ckcoverage, PHPStan and CiviCRM.
Versioned separately, a repo could run last week's workflow against this
week's template. So they are released together, and a consumer pins one
version. [ADR-0001](adr/0001-one-versioned-contract-pinned-at-v1.md) explains
why the contract has a moving v1 tag and what that costs.

## What a version names

A release is a git tag on this repo plus the image tags that go with it.

| Tag | Kind | Points at |
|-----|------|-----------|
| `v1.4.2` (git) | immutable | the commit the release was cut from |
| `v1` (git) | **moving** | the newest v1.x.y commit |
| `:v1.4.2`, `:v1` | image | the standalone dev image, which the template's compose stacks use |
| `:standalone-v1.4.2`, `:standalone-v1` | image | the same digest, spelled with its flavor |
| `:drupal10-v1`, `:wordpress-v1`, `:joomla-demo-v1`, … | image | every other published flavor, with and without the patch level |
| `:standalone`, `:drupal10`, `:*-demo`, … | image | the **moving edge**: whatever last passed test-then-promote |

The moving tags stay as they are and follow the daily rebuild. The
standalone-master and standalone-beta images from *Build Git Images* get no
release tags.

## What a consumer pins

The template sets both pins for you. In `.github/workflows/ci.yml`:

```yaml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
```

In `.docker/docker-compose.ci.yml`:

```yaml
image: ${CIVIKITCHEN_IMAGE:-ghcr.io/civico-de/civikitchen:v1}
```

There is no third pin. The drift job checks the template out at the commit the
caller's own ref resolved to, so it compares against whatever the caller
pinned: v1, main or a SHA.

## Images are retagged, not rebuilt

A release builds nothing. The release workflow attaches the release tags to
the digest each moving tag points at, so a released image has already passed
test-then-promote.

It follows that a release can only ship image content that is already live. If
the release commit touches docker/, toolbelt/ or packages/, wait for a full
*Build Dev Images* run to promote it first; a push builds only the standalone
flavors. Start one with:

```bash
gh workflow run build-dev-images.yml
```

The release workflow checks this: it compares those trees between the commit
each live image was built from and the release commit, and fails on a
mismatch. If the difference cannot affect the image, such as a comment-only
change, re-run the release from the Actions tab with **allow_image_drift**.

Between releases, :v1 keeps the CiviCRM the last release blessed while
:standalone moves on. A CiviCRM refresh reaches the fleet only through a patch
release, which costs one tag push, so cut them liberally.

Published vX.Y.Z image tags never move to a different digest. Re-running a
release is idempotent; a changed release needs a new patch version.

## Cutting a release

Start from a clean main whose images have been built and promoted.

Move the Unreleased bullets in
[CHANGELOG.md](https://github.com/civico-de/civikitchen/blob/main/CHANGELOG.md)
under a new `## [X.Y.Z] - YYYY-MM-DD` heading and add the compare link at the
bottom ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format). The
release fails if the version has no section or Unreleased still has bullets.
That section becomes the GitHub release body.

The release script runs the gates the tag push would hit, before the tag
exists: you are on a clean main in sync with origin, the tag is free locally
and on origin, the changelog and ckinit tests pass, and a full (daily or
dispatched) *Build Dev Images* run succeeded at or after the newest commit
touching the image paths. Without APPLY it only reports.

```bash
git switch main && git pull
make release VERSION=1.0.1            # report only
make release VERSION=1.0.1 APPLY=1    # tag and push
```

The tag push then

1. verifies the live image digests as described above,
2. attaches the release tags (v1.0.1, v1 and the per-flavor spellings),
3. moves the git v1 tag, but only if v1.0.1 is the newest v1.x.y,
4. creates the GitHub release from the changelog section.

Callers on v1 pick up the release on their next run. Re-running an old run
does not count: GitHub keeps the workflow commit of the first attempt, which
this shows:

```bash
gh api repos/<owner>/<repo>/actions/runs/<id> --jq .referenced_workflows
```

To test a release, push a commit, or run the tools locally against
`ghcr.io/civico-de/civikitchen:v1`.

### Versioning rules

- **patch**: an image content refresh, a bug fix in a tool or the workflow,
  doc changes.
- **minor**: a new workflow input, template file, ckconform check or tool. A
  change conforming repos must react to is also minor if every affected
  consumer is adjusted before the tag; the changelog marks it **Breaking**.
- **major**: a change consumers cannot absorb before the tag, such as a
  dropped flavor or tag, or a contract that must run beside the old one.

Every v1 caller is a repo this project maintains, so fixing consumers is
cheaper than a second v2 line.

## The canary

One repo deliberately stays on the moving edge:

```yaml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@main
    with:
      image: ghcr.io/civico-de/civikitchen:standalone
```

Its CI workflow is template-managed, so it declares the deviation in its
`civikitchen.yaml`:

```yaml
policy:
  template_custom:
    paths:
      - .github/workflows/ci.yml
    reason: Canary tracks main ahead of the fleet
```

A workflow change that calls something new in the image turns the canary red
until *Build Dev Images* has promoted :standalone. That is expected. Keep
exactly one canary, one whose CI someone reads. Every other repo pins v1 and
moves when a release is cut.

## Bootstrapping order (once)

The template pins refs that do not exist until the first release, so the
first time the order is fixed:

1. Merge the release machinery and the template change into main.
2. Tag v1.0.0 and push it immediately. Until then, repos on main show a red
   drift job, because the new template says v1.
3. Then update the repos with `scaffold/ckinit --update <repo>`.
4. Leave the canary on main with the policy entry shown above.

A repo pointed at v1 before step 2 gets a run that cannot start at all.

Before that first tag, create a GitHub App to move the major tag; the run's
own token cannot move a tag onto a commit with different workflow files.
Install it on this repository only, with Contents and Workflows read & write.
Put its client id in the variable `RELEASE_APP_CLIENT_ID` and its private key
in the secret `RELEASE_APP_PRIVATE_KEY`. The git-tag job fails when either is
missing.
