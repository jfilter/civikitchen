# Plan: several extensions in one repository

Status: phases 1–3 and 5 implemented, not yet released; phase 4 (release)
open. It depends on the unified release path in
[release-unification.md](release-unification.md).

## The layout this plan covers

A git repository whose root holds **no** `info.xml`, and whose direct
subdirectories are CiviCRM extensions — each with its own `info.xml`,
`civikitchen.yaml`, `composer.json` and test suite. The extensions ship
together and usually `<requires>` one base extension from the same repository.

Out of scope: extensions nested deeper than one level, and a repository that is
an extension at its root *and* carries further extensions below it.

## Where things stand

The changelog says "Extension monorepo layouts are supported by the shared
workflows." What exists is narrower: `ckconform` finds the repository root by
walking up to `.git` (`Context::repositoryRoot()`), and `Context::workflows()`
returns the root's workflow files for an extension in a subdirectory. One
fixture (`CheckTestCase::monorepoExtension()`) and one test cover it. No
workflow, no `ckinit` code and no `ckrelease` code knows about the layout.

What happens today in such a repository:

- **`extension-ci.yml` cannot be called.** Its `key` job requires `info.xml` at
  the checkout root, no job sets a working directory, and the template-drift
  job runs `ckinit --check .` at the root, which exits 2 without an `info.xml`.
  npm, Bun, Composer, the lockfile scan and the secret scan all read the root.
  The only path-like input is `compose_file`.
- **`ckinit` stamps files that never take effect.** It has no notion of the git
  root, so `--update <subdir>` writes `.github/workflows/ci.yml` and
  `renovate.json` into every extension. GitHub runs workflows only from
  `<root>/.github/workflows/`, and Renovate reads the root config.
  `--check <subdir>` then reports them as missing or drifted forever, unless
  each extension lists them under `policy.template_custom`.
- **A repository in this layout therefore writes its own root workflow.** The
  one observed runs a matrix over the subdirectories with a single step
  (`ck config validate`). None of the real gates run: no template drift, no
  cklint, ckfmt, PHPStan or PHPUnit. Template drift and lint debt accumulate
  unseen.
- **Same-repository dependencies are mounted by hand.** `sibling_repo` accepts
  only `owner/repo[@ref]` and clones it. A dependency that lives two
  directories away is a hand-written volume line outside the managed compose
  block (`- ../../base:/var/www/html/ext/base`), which the template comment
  already describes. It works locally and in CI, but nothing checks it.
- **Release checks mix the extensions up.** `Context::tags()` and `newestTag()`
  read every `v*` tag of the repository, while `infoVersionHistory()` is scoped
  to the extension's own `info.xml`. `commitsSince()` runs `git log` without a
  pathspec, so commits to a neighbouring extension count as unreleased changes.
  `extension-release.yml` maps one `vX.Y.Z` tag to one root `info.xml` and
  uploads under the fixed artifact name `ckrelease-dist`.

## Decisions

### 1. `working_directory` input, one job per extension

`extension-ci.yml` and `extension-release.yml` gain `working_directory`
(default `.`), the name `command-check.yml`, `frontend-ci.yml` and
`playwright-e2e.yml` already use. Every job sets
`defaults.run.working-directory`; paths handed to actions (`compose_file`,
cache dependency paths, artifact paths, scan targets) are resolved against it.
Helper checkouts (`.civikitchen-ci`, `.civikitchen-siblings`) stay at the
workspace root and are addressed absolutely. Everything two jobs of one run
could share is per extension: artifact names and compose project names carry
the extension key, concurrency groups carry the directory — otherwise the jobs
cancel each other, and one job's `down -v` takes the neighbour's stack with it.
`git cat-file -e "<rev>:<path>"` reads from the repository root, so the
compose-file probes of the upgrade jobs spell the path `./<path>`.

The root caller has **one job per extension**, not a matrix:

```yaml
jobs:
  base:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: base
  addon:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: addon
      playwright: true
```

A matrix would force per-extension inputs into `${{ matrix.* }}` expressions,
which the workflow-reading checks cannot evaluate. With static jobs,
`ci-coverage`, `npm-install`, `playwright-diagnostics`,
`config-without-runner` and `release-workflow` select the job whose
`working_directory` equals the extension's directory and judge exactly that
one. A job with steps of its own is selected by
`defaults.run.working-directory`, since `npm install` and Playwright runs only
occur there. An extension no job names fails `ci-workflow`, once. `floating-tag` and `workflow-permissions` keep judging the whole file.

Every push runs every extension's jobs. With seven extensions that is seven
stacks per push (measured: about 50 s to a healthy stack, the gates after it
5–20 s each, jobs in parallel); on self-hosted runners this is accepted rather than solved
with path filters, which would let a change in the base extension skip the
extensions that depend on it.

### 2. `ckinit` learns where the repository root is

`ckinit` finds the root the same way `ckconform` does (walk up to `.git`).

- **Target is an extension below the root:** the root-only files
  (`.github/workflows/ci.yml`, `renovate.json`) are neither written nor
  checked. Everything else behaves as today.
- **Target is a root without `info.xml` whose subdirectories are extensions:**
  `ckinit` manages the root files — `renovate.json`, `.gitattributes`, and in
  `.github/workflows/ci.yml` one managed block per extension holding the
  `uses:` line and `working_directory`. Inputs below `with:` stay the
  repository's. It then runs the per-extension pass for each subdirectory.
  `--check` fails when an extension directory has no job, or a job points at a
  directory that is gone.

`--update` on the root appends the job of a new extension directory and drops
the job of one that is gone; it refuses when repository-owned inputs follow the
block it would drop. Repository-owned lines live after a block's END marker —
inputs below `with:` in the root workflow, a sibling volume in the compose file.

The drift job in `extension-ci.yml` runs `ckinit --check` on
`working_directory` and, below the root, `ckinit --check <root>` as well. Every
extension's job repeats the root check: which job is "first" cannot be seen
from inside a called workflow, and the check is cheap. The root check runs
only when `working_directory` is exactly one path segment: a deeper tree, like
the self-test's `examples/monorepo/<ext>`, is not the layout `ckinit` manages.

`private-deps` takes `working_directory` too, because a composite action's
`run` steps do not inherit the caller's `defaults.run`. Compose project names
use the lower-cased key (`key_slug`); Compose rejects upper-case names.

The dev compose file is seeded, not managed, so an existing repository adds its
`/civikitchen-repo` mount by hand; only the CI compose file is checked for it.

### 3. Same-repository dependencies stay a compose line, and get a check

No new input. The hand-written volume line outside the managed block is kept:
it is the one mechanism that works for a local `docker compose up` and in CI
alike. New `ckconform` check `monorepo-requires-mounted`: for every
`<requires>` key that belongs to an extension in the same repository, the CI
compose file must mount that directory under `/var/www/html/ext/<key>`. Provisioning resolves
`<requires>` before enabling, so the order of the volume lines does not matter.
A missing mount is a failure, because
the stack would otherwise boot without the dependency and fail late.

The target is the dependency's **key**, not its `<file>` name: the PHPStan
bootstrap and `private-deps` look a required extension up under `ext/<key>`.
Only the extension's own managed mount uses `<file>`. The check reads the `app`
service alone, since that is the service the workflow provisions and tests.

### 4. Releases are lockstep: one tag, every extension

All extensions of the repository carry the same `<version>`, and one `vX.Y.Z`
tag releases all of them. This keeps the tag namespace, `release-tags`,
`release-tag-coherence` and the Latest computation exactly as they are — they
are correct when every `info.xml` moves together.

- New check `monorepo-version-lockstep`: every `info.xml` in the repository
  carries the same `<version>` and `<releaseDate>`.
- `commitsSince()` takes the extension directory as pathspec and returns paths
  relative to it. This is a bug fix independent of the release model.
- The release caller has one job per extension like the CI caller. Each job
  builds and verifies its own zip; one final job creates the GitHub release and
  attaches all zips, so there is one release per tag.

Rejected: per-extension tags (`<key>-vX.Y.Z`). They need a tag prefix in
`ckrelease`, the release workflow, the publish flags, four `ckconform` checks
and every deploy tool that reads tags — for extensions that are deployed
together anyway. If a repository ever needs independent versions, that is the
signal to split it.

### 5. The container sees the repository at a second, neutral path

The managed compose mount `..:/var/www/html/ext/<key>` stays: provisioning
enables exactly that directory and resolves `<requires>`, and cross-extension
paths keep the `ext/<key>` convention. But in this layout `..` is the extension
directory, so `.git` stays outside the container: `cklint` reports "no changed
PHP files" with exit 0, `ckfmt` exits 2.

For an extension below the repository root, `ckinit` adds one managed mount,
`../..:/civikitchen-repo`. The git-reading tools (`cklint`, `ckfmt`,
`ckconform`) run with `/civikitchen-repo/<dir>` as working directory — the same
host directory as `ext/<key>`, so writes land in the right place. Everything
that boots CiviCRM (`cv`, PHPUnit, PHPStan, lifecycle probes) stays on
`ext/<key>`. `extension-ci.yml` derives both paths once and uses them at every
`cd` site.

Measured on `ghcr.io/jfilter/civikitchen:v1` with a two-extension repository:

- `git ls-files` is relative to the working directory, so file selection is
  correct in a subdirectory. `git diff --name-only` is relative to the
  repository root: `Files::changedPhp()` needs `--relative`, otherwise the mago
  stage lints nothing and sees the neighbour's files.
- `ck_git` and `Files::git()` pass the working directory as `safe.directory`;
  git wants the worktree root. `ckconform` already passes the root.
- Rejected — `.git` bound into the extension directory (or `GIT_DIR` /
  `GIT_WORK_TREE`): the index holds `<dir>/…` paths, the tools are handed paths
  that do not exist, and `ckfmt` answers "all files are already formatted" with
  exit 0.
- Rejected — the repository root mounted under `ext/`: provisioning enables
  nothing ("no readable info.xml key"), and siblings sit at `ext/<repo>/<dir>`
  instead of `ext/<key>`, which the PHPStan cross-extension paths rely on.

### 6. No new policy keys

The layout is detected from the filesystem; nothing is declared in
`civikitchen.yaml`. `Policy::KEYS` and the JSON Schema stay unchanged.

## Phases

Each phase ships with the fixture that would have failed before it.

1. **`ckconform` and tool correctness.** Path-scoped `commitsSince()`;
   `--relative` in `Files::changedPhp()` and the worktree root as
   `safe.directory`; job selection by
   `working_directory` in the workflow-reading checks; the two new checks.
   Fixtures: `monorepoExtension()` grows tags, a second extension and commits
   touching only the neighbour.
2. **`ckinit`.** Root detection, the root pass, root-only files skipped below
   the root, the `/civikitchen-repo` mount below the root. `tests/ckinit/test-ckinit.sh` gets a two-extension tree: stamping,
   `--check` clean, a new directory without a job fails, no root-only file
   appears in a subdirectory.
3. **`extension-ci.yml`.** `working_directory` through every job. Verified by a
   self-test caller in this repository that runs the workflow against a
   two-extension example tree, one extension requiring the other.
4. **Release.** `working_directory` in `extension-release.yml` and `ckrelease`,
   keyed artifact names, the single release job, and a managed release caller
   in the root pass of `ckinit` — until then `release-workflow` warns "not
   evaluated" for every extension of such a repository, and `ckinit` stamps
   `release.yml` neither at the root nor below it.
   `ReleaseCommand` calls git without the `safe.directory` guard. After the unified release path
   has landed.
5. **Documentation.** A "Several extensions in one repository" section in
   `docs/extension-development.md` and `docs/reusable-workflows.md`; the
   changelog entry states what is supported from which version on.

## What adopting it costs a repository

The first run of the real gates will be red. A repository that only validated
its configuration so far has never been formatted by `ckfmt`, linted by
`cklint`, analysed by PHPStan or tested in CI. That debt is fixed in the
repository — per extension, in its own commits — before the caller is switched
over; it is not waived through `ignore_checks`. Per-extension
`.github/workflows/ci.yml` and `renovate.json` files are deleted in the same
change, and versions are aligned once for lockstep.
