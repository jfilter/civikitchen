# Releases: one versioned contract

How **this** repo is versioned. For how a consuming extension repo cuts its own
release — `ckrelease`, the dist archive, the shared `extension-release.yml` —
see [Releasing an extension](extension-releases.md).

Extension repos depend on four things from this repo, and they only work as a
set:

- the reusable workflow `.github/workflows/extension-ci.yml`,
- the extension template `scaffold/template/extension/` (its managed files are compared
  in every repo's CI),
- `scaffold/ckinit.php`, which stamps that template,
- the images the pipeline runs in — `cklint`, `ckconform`, `ckcoverage`,
  `phpstan` and the CiviCRM stack itself all live there.

The workflow calls tools that ship in the image and compares the calling repo
against a template that ships next to it. Version them separately and a repo
can end up running last week's workflow against this week's template, or a
template rule against an image whose `ckconform` never heard of it. So they
are released together, under one version, and a consumer pins that one
version.

Before this, every repo tracked `extension-ci.yml@main` and the moving
`:standalone` image. That is a working rollout mechanism — it is also one with
no step between "pushed" and "every repo has it".

## What a version names

A release is a git tag on this repo plus the image tags that go with it.

| Tag | Kind | Points at |
|-----|------|-----------|
| `v1.4.2` (git) | immutable | the commit the release was cut from |
| `v1` (git) | **moving** | the newest `v1.x.y` commit |
| `:v1.4.2`, `:v1` | image | the standalone dev image of that release — the one the template's compose stacks use |
| `:standalone-v1.4.2`, `:standalone-v1` | image | same digest, spelled with its flavor |
| `:drupal10-v1`, `:wordpress-v1`, `:joomla-demo-v1`, … | image | every other published flavor, with and without the patch level |
| `:standalone`, `:drupal10`, `:*-demo`, … | image | unchanged: the **moving edge**, whatever last passed test-then-promote |

`v1` is the pragmatic middle. Full SHA pinning is stricter and noisier — every
fix becomes a PR in every repo. A maintained major tag lets a fix reach the
fleet the moment it is released, while a *breaking* change (a removed input, a
renamed managed file, a tool that starts failing what it used to pass) has to
announce itself as `v2` and be adopted deliberately.

The moving tags stay exactly as they are. They are the development and canary
edge, and the daily rebuild keeps pointing them at current CiviCRM.

### The honest cost of the moving `@v1`

This repo's own workflows pin every third-party action to a 40-hex commit SHA,
and `zizmor` enforces it. The extension repos' `@v1` is the one exception, and
it is worth naming what it buys and what it costs rather than filing it under
"our repo, our rules".

What `@v1` costs: whoever can move the `v1` tag — a maintainer account, or
anyone who compromises one — can change what runs in all eleven extension
repos' CI, retroactively, with no PR and no review anywhere. A SHA pin makes
that a reviewable diff in each repo. Tag protection rules on `v1` narrow the
window; they do not close it.

What `@v1` buys: a security fix in the shared pipeline reaches the fleet the
moment it is released. Under SHA pins it reaches whichever repos someone
remembers to update — which in practice means the update bot. That is the real
alternative, not "SHA pin instead": SHA pins plus Renovate in every extension
repo, which turns each civikitchen release into eleven PRs to review and merge.
Eleven ignorable PRs is its own failure mode.

The trade is therefore *review coverage* against *patch latency*, and it is not
obviously settled either way. It stays `@v1` until the maintainer decides
otherwise; the exemption is written down in `zizmor.yml` rather than silently
skipped.

## What a consumer pins

The template does both pins for you:

```yaml
# .github/workflows/ci.yml
jobs:
  ci:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
```

```yaml
# .docker/docker-compose.ci.yml
image: ${CIVIKITCHEN_IMAGE:-ghcr.io/jfilter/civikitchen:v1}
```

There is no third pin to keep in sync. The drift job inside `extension-ci.yml`
checks the template out at `job.workflow_sha` — the commit the caller's own ref
resolved to — so it compares against the template of whatever the caller
pinned, `@v1`, `@main` or a SHA.

## Images are retagged, not rebuilt

Cutting a release does not build anything. `release.yml` looks up the digest
each moving tag currently points at and attaches the release tags to that same
digest. Two consequences worth knowing:

- A released image has already been through test-then-promote. There is no
  second, differently-built artifact that only the release path produces.
- A release can only ship image content that is already live. If the release
  commit touches `docker/**` or `toolbelt/**`, let *Build Dev Images* finish and promote first.

`release.yml` enforces the second point rather than trusting it: for every
flavor it recovers the commit the live image was built from (the promoted
digest still carries its `…-<sha>` candidate tag) and compares that commit's
`docker/` + `toolbelt/` trees with the release commit's. A mismatch fails the release. Re-run
it from the Actions tab with **allow_image_drift** when the difference is
genuinely irrelevant to the built image, e.g. a comment-only change under
`docker/` or `toolbelt/`.

The retag command uses `--prefer-index=false`. This matters when its source is
digest-qualified: without it, Buildx may wrap a source manifest in a new
single-entry index if registry media-type discovery is inconclusive. The
layers and platforms would still be the same, but the top-level digest would
change, breaking both the immutable-tag check and idempotent release reruns.

The flip side: `:v1` does **not** follow the daily rebuild. Between releases
the fleet keeps running the CiviCRM the last release blessed, while
`:standalone` moves on. That is the point — one mechanism, and image content
changes when someone decides it does — but it means a CiviCRM refresh reaches
the fleet only via a patch release. That release costs one tag push and builds
nothing, so cut them liberally; if `:v1` and `:standalone` drift apart for
months, the contract has stopped being maintained, not stopped being safe.

Already-published `vX.Y.Z` image tags are never moved onto a different digest:
re-running a release is idempotent, but a *changed* one has to be a new patch
version.

## Cutting a release

From a clean `main` whose images have been built and promoted:

```bash
git switch main && git pull
# sanity: the template still stamps and checks cleanly
bash tests/ckinit/test-ckinit.sh

git tag -a v1.0.1 -m 'v1.0.1'
git push origin v1.0.1
```

The tag push runs `release.yml`, which

1. resolves and verifies the live image digests (above),
2. attaches `:v1.0.1` / `:v1` (and the per-flavor spellings) to them,
3. moves the git `v1` tag to the release commit — but only if `v1.0.1` really
   is the newest `v1.x.y`, so a late patch on an older line cannot drag `v1`
   backwards,
4. creates the GitHub release with generated notes.

Nothing else is needed: callers on `@v1` and `:v1` pick the release up on their
next run.

### Versioning rules

- **patch** — image content refresh, bug fix in a tool or the workflow, doc
  changes.
- **minor** — new workflow input, new template file, a new `ckconform` check
  that only *warns*, a new tool.
- **major** — anything a conforming repo has to react to: a removed or renamed
  workflow input, a managed template file that changes shape, a check that
  turns from warning to failure, a dropped image flavor or tag.

The test for "is this breaking" is not the size of the diff; it is whether a
repo that was green yesterday goes red without touching its own code.

## The canary

One repo deliberately stays on the moving edge:

```yaml
jobs:
  ci:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@main
    with:
      image: ghcr.io/jfilter/civikitchen:standalone
```

Its `.github/workflows/ci.yml` is a template-managed file, so the deviation is
declared where every deviation is declared, in the repo's `civikitchen.yaml`:

```yaml
policy:
  template_custom:
    paths:
      - .github/workflows/ci.yml
    reason: Canary tracks main ahead of the fleet
```

The canary exists so that a change is exercised by a real repo before it is
released, not after. Pick one whose CI someone actually reads, and keep it at
exactly one: a second repo on `@main` doubles the noise without adding a
signal. The `image` input keeps the image pin in the caller, so the canary
needs no second exception for its compose file.

Everything else pins `@v1` and `:v1` and moves when a release is cut.

## Bootstrapping order (once)

The template pins `@v1` and `:v1` — refs that do not exist until the first
release does. So the first time through, the order is not negotiable:

1. Merge the release machinery and the template change into `main`.
2. **Immediately** tag `v1.0.0` and push the tag. Until this finishes, repos
   still on `@main` see template drift (their caller says `@main`, the new
   template says `@v1`), which shows up as a red drift job — the one check
   whose whole purpose is to notice exactly this.
3. Only now update the repos: `scaffold/ckinit.php --update <repo>` rewrites the
   caller and the CI compose stack to the released refs.
4. Leave the canary on `@main` with the `policy.template_custom` line above.

Pointing a repo at `@v1` before step 2 does not produce a red run — it produces
a run that cannot start at all, because the ref does not resolve.
