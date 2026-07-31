# Releasing an extension

How a conforming extension repo cuts a release. This is *not*
[releases.md](releases.md) — that one is about versioning civikitchen itself
(workflow + template + tools + images as one contract). This one is about the
repos that consume it.

The shape is the same as CI: the pipeline lives here once
(`.github/workflows/extension-release.yml`), the repo's own workflow is a
caller, and the tool it runs (`ckrelease`) ships in the images so the same code
path builds the artifact on a laptop and in Actions.

## What a release is

Three things, in this order:

1. **A commit** that bumps `info.xml` `<version>` (and `composer.json`, and
   `CHANGELOG.md` if the repo keeps one). A human writes and reviews it.
2. **A tag**, `v<version>`, which is the statement that the commit is ready.
3. **Everything after that**, which is mechanical and therefore automated: the
   consistency check, the distribution archive, an install into a real CiviCRM,
   and the GitHub release.

Step 1 is deliberately not automated. A version number is a compatibility claim
about the change, and nothing derives that from a diff.

## Adopting it in a repo

```yaml
# .github/workflows/release.yml
name: Release

on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+']

permissions:
  contents: read

jobs:
  release:
    permissions:
      contents: write        # a called workflow can only narrow this
    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1
```

That is the whole adoption. Then, once:

- add `.ckrelease/` to `.gitignore` (where `ckrelease dist` writes locally),
- run `ckrelease check` and fix whatever it says before the first tag.

The caller is intentionally *not* a template-managed file. Managed files are
compared byte-for-byte in every repo's CI, so adding one turns the whole fleet's
drift job red until each repo runs `ckinit --update` — a fleet round, before a
single repo has released anything through this. Repos adopt one at a time; the
file can be promoted into the template later, when it has earned it.

### Inputs

| Input | Default | What it is for |
|-------|---------|----------------|
| `image` | `ghcr.io/jfilter/civikitchen:v1` | image the smoke test installs into |
| `smoke_test` | `true` | set `false` only for an extension whose install genuinely cannot be reached this way — and say why in the caller |
| `extra_extensions` | `''` | comma-separated registry keys to install into the smoke site first, for a `<requires>` on something core does not bundle |
| `require_changelog` | `false` | fail when the repo has no `CHANGELOG.md` at all |
| `draft` | `false` | publish the GitHub release as a draft |

## Cutting one

```bash
# on a clean main
$EDITOR info.xml composer.json CHANGELOG.md   # the version bump
ckrelease check                               # before committing, not after
git commit -am 'release 1.3.0'
git tag -a v1.3.0 -m 'v1.3.0' && git push origin main v1.3.0
```

The tag push runs the workflow: `ckrelease check` → `ckrelease dist` → install
smoke test → `gh release create --generate-notes` with the zip and its
`.sha256` attached.

Locally, `ckrelease dist` produces exactly the same archive from the same
commit — `git archive` is deterministic — so "what will ship" is inspectable
before anything is pushed.

## What the archive contains

Tracked files at the tag, under a single top-level directory named the
extension key, minus the development layer:

```
.github/ .docker/ .claude/ tests/ node_modules/
.gitattributes .gitignore .editorconfig .ckconform .phpunit.result.cache
phpcs.xml(.dist) phpstan.neon(.dist) phpstanBootstrap.php phpunit.xml(.dist)
playwright.config.ts package-lock.json bun.lock(b) tsconfig.json
```

Two things are deliberately *not* on that list. `vendor/` — a repo that commits
it does so because the site needs it at runtime, and a packager that silently
drops runtime code is worse than one that ships a test file. And `dist/` — for a
frontend-building extension the committed build *is* the shipped artifact.

Because the archive is built from tracked files, everything a `.gitignore`
already covers is absent for free; the list above is only about files that are
committed on purpose and still have no business on a production site.

Per repo, in `.ckconform` (where every other repo-level policy lives):

```
dist_exclude=build,docs/internal   # additionally kept out
dist_include=tests                 # kept in despite the central list
```

`ckrelease verify` re-checks the built archive: one top-level directory named
after the key, an `info.xml` at the released version, and no excluded name as a
path segment *at any depth* — which is how a second `tests/` under a sub-package
gets caught, since the build only excludes at the root.

### Why not `.gitattributes export-ignore`

It is the git-native way to say this, and it was rejected on purpose.
`.gitattributes` is a template-**managed** file: civikitchen owns its bytes and
every repo's CI compares them. Putting the exclude list there means (a) shipping
packaging costs a fleet-wide drift round and a contract version bump, and (b) the
list then exists in as many copies as there are repos, free to drift apart —
the exact state the shared tooling exists to end. The central list plus
`.ckconform` gives one source of truth and a declared, reasoned per-repo
exception, which is the pattern already used for coverage floors and template
deviations.

`git archive` still honours a repo's own `export-ignore` attributes on top of
this. Nothing forbids them; they are just not where the standard lives.

## Why not release-please

It was the obvious candidate and it solves the cheap half. What a CiviCRM
extension release actually needs:

| Need | release-please | here |
|------|----------------|------|
| version in `info.xml` as the source of truth | generic-updater config per repo | native — `info.xml` *is* the input |
| `composer.json` in step | yes | yes |
| changelog | its main strength (conventional commits) | `gh release --generate-notes`, plus a `CHANGELOG.md` check when the repo keeps one |
| tag | release-PR flow | a human tags |
| dist archive without dev files | not its problem | the point |
| install smoke test | not its problem | the point |

So the half it automates is the half already covered by two `gh` flags, and the
half that costs real money — a wrong archive reaches every site that installs
the extension — it does not address at all. Adopting it would also mean
conventional-commit discipline across the fleet plus two more config files per
repo, to still need a second workflow for the artifact.

Delegation, not rejection: notes come from GitHub's generator and tagging is
plain git. `ckrelease` is only the CiviCRM-shaped part.

## The smoke test

The archive is unzipped into a fresh CiviCRM in the standalone image and
installed with `cv en` — no source mount, so the site sees exactly what a user
downloads. It catches the failure class that no amount of green CI does: a PHP
file that the exclude list swallowed, a `<requires>` on an extension that is not
there, an upgrader that fatals on a first install.

It costs a CiviCRM boot (a few minutes) per release. If it turns out to be
flaky rather than informative, `smoke_test: false` is one line — but turn it off
in the caller, visibly, rather than quietly widening what "released" means.

## `ckrelease` outside CI

```
ckrelease check  [--version <v>] [--require-changelog]
ckrelease dist   [--version <v>] [--ref <ref>] [--output <dir>]
ckrelease verify <zip> [--version <v>]
ckrelease info   key|file|version|dist-name
```

It ships in the civikitchen images (so `docker compose exec app ckrelease …`
works) and runs standalone from a checkout with nothing but bash, git, php and
unzip.
