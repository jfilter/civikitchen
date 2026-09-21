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

Steps 1 and 2 belong together, and `ckconform`'s `release-tags` is what holds
them together after the fact: it compares the `<version>` values `info.xml` has
carried with the repo's tags, and fails on a version the repo bumped past
without ever tagging — that release exists in the history and on no site.

### A version that will never be released

A bump that was re-scoped or taken back leaves a number the repo passed through
and never wants to publish. Tagging it now is not the fix: the tag push runs the
release workflow and publishes the code of that moment under a version nobody
reviewed for release. Name it instead, with the reason:

```yaml
policy:
  untagged_versions:
    - version: "1.2.0"
      reason: bump re-scoped into 1.3.0 before anything shipped
```

Quote the version: a two-component number like `1.0` is a YAML float unquoted
and the schema rejects it as "expected string". Write it exactly as `info.xml`
carries it, without the `v` — `release-tags` prefixes the `v` itself.

`release-tags` then skips exactly that version and keeps failing on every other
one. An entry warns as stale when it excuses nothing: the version is tagged
after all, it is the version `info.xml` carries right now (that window belongs
to `release-tag-coherence`), or `info.xml` never carried it. The list is meant
to shrink. With `policy.release: none` there is no tag to miss at all, so a
list next to it warns as well.

## Adopting it in a repo

The caller `.github/workflows/release.yml` is a template-managed file:

```bash
/path/to/civikitchen/scaffold/ckinit.php --update .
```

writes it, and the template drift job in CI keeps it in line afterwards. The
managed part is the trigger, the permissions and the `uses:` line:

```yaml
on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+', 'v[0-9]+.[0-9]+.[0-9]+-*']
```

GitHub matches a tag filter against the whole tag name, so the second pattern
is what lets a pre-release tag (`v1.3.0-rc.1`) start a run.

Inputs and secrets for the call go below the `# END CIVIKITCHEN MANAGED caller`
marker and survive `ckinit --update`:

```yaml
# END CIVIKITCHEN MANAGED caller
    with:
      # The install needs a payment processor no headless site can reach.
      smoke_test: false
      require_changelog: true
```

A caller written before the markers is rewritten onto the template, with its
job's `with:` and `secrets:` carried below the marker as written, comments
included. If it holds anything else of its own — another trigger, `env:`, a
further job — `--update` writes nothing and lists what it would drop, and
`--check` reports the same. Move that content, or declare the file under
`policy.template_custom`. `--update` also refuses to create `release.yml` while
another workflow already calls `extension-release.yml`: one tag push would
publish twice.

Then, once:

- give `info.xml` a SemVer `<version>` (`X.Y.Z` or `X.Y.Z-<pre-release>`).
  `civix generate:module` writes `1.0`, which `ckconform`'s `version-format`
  fails; set it to `0.1.0` and release that. `ckcreate` does this for you,
- add `.ckrelease/` to `.gitignore` (where `ckrelease dist` writes locally),
- run `ckrelease check` and fix whatever it says before the first tag.

A repo that never releases declares that instead, with the reason. `ckinit`
then neither writes the caller nor reports it missing; delete an existing one:

```yaml
policy:
  release:
    mode: none
    reason: internal tooling, never installed on a site
```

`ckconform`'s `release-workflow` fails a repo that does neither. A repository
of several extensions releases them together from one caller at its root; see
[Several extensions in one repository](extension-development.md#several-extensions-in-one-repository).

### Inputs

| Input | Default | What it is for |
|-------|---------|----------------|
| `working_directory` | `.` | the extension's directory in a repository of several extensions |
| `stage` | `release` | `release` builds and publishes; `build` builds, verifies, smoke-tests and uploads the archive as `ckrelease-dist-<key>`; `publish` builds nothing and publishes one release with every archive of the run |
| `dry_run` | `false` | build the version `info.xml` carries without a tag; the publish job lists the archives instead of publishing |
| `image` | `ghcr.io/jfilter/civikitchen:v1` | image the smoke test installs into |
| `smoke_test` | `true` | set `false` only for an extension whose install genuinely cannot be reached this way — and say why in the caller |
| `require_changelog` | `false` | fail when the repo has no `CHANGELOG.md` at all |
| `draft` | `false` | publish the GitHub release as a draft |
| `composer_install` | `false` | install the lockfile with `--no-dev` and bundle the generated `vendor/` tree |
| `composer_app_repositories` | unset | comma- or newline-separated repository names within the caller's owner; required with the App secrets and used as the token allowlist |
| `composer_app_id`, `composer_app_private_key` | unset | GitHub App (`contents: read`, installed on the private dependency repos) for private Composer packages and for staged dependency releases; pass both explicitly under `secrets:`. One without the other fails the run rather than resolving as "no app" |

## Cutting one

```bash
# on a clean main
$EDITOR info.xml composer.json CHANGELOG.md   # the version bump
ckrelease check                               # before committing, not after
git commit -am 'Release 1.3.0'
git tag -a v1.3.0 -m 'v1.3.0' && git push origin main v1.3.0
```

The tag push runs the workflow: `ckrelease check` → `ckrelease dist` → install
smoke test → `gh release create --generate-notes` with the zip and its
`.sha256` attached.

A tag with a SemVer pre-release suffix (`v1.3.0-rc.1`) is published as a
pre-release and never marked Latest; a plain tag is marked Latest only when it
is the highest plain `vX.Y.Z` in the repo, so a late release of an older version
leaves Latest where it is — and a higher tag whose release never succeeded keeps
Latest from lower releases until it does. A draft is never marked Latest. A tag
the trigger lets through that is no SemVer version (`v1.2.3-rc..1`) fails the
run before the build; shapes such as `v1.3` or `v2.2.7.1` start no run at all.

Locally, `ckrelease dist` produces exactly the same archive from the same
commit — `git archive` is deterministic — so "what will ship" is inspectable
before anything is pushed. A caller with `composer_install: true` additionally
materializes the locked production dependencies on the runner and archives a
temporary Git tree containing them; the release tag itself is not changed. A repo
that declares [build output](#build-output-git-does-not-track) is archived from
such a tree as well, on a laptop too. An archive of a tree carries the time it
was built instead of the commit time, so it matches another build of the same
tree in content, not byte for byte.

## What the archive contains

Tracked files at the tag plus any declared build output, under a single
top-level directory named the extension key, minus the development layer:

```
.github/ .docker/ .claude/ tests/ node_modules/
.gitattributes .gitignore .editorconfig civikitchen.yaml .phpunit.result.cache
phpcs.xml(.dist) phpstan.neon(.dist) phpstanBootstrap.php phpunit.xml(.dist)
playwright.config.ts package-lock.json bun.lock(b) tsconfig.json
```

Two things are deliberately *not* on that list. `vendor/` — a repo either
commits it because the site needs it at runtime, or sets `composer_install: true`
so the release workflow bundles the locked production tree. A packager that
silently drops runtime code is worse than one that ships a test file. And
`dist/` — for a frontend-building extension the committed build *is* the
shipped artifact. A build that is not committed is declared instead, see below.

When `composer_install` is enabled, package-level copies of the same excluded
development paths (for example `vendor/acme/package/.github/`) are removed
before the temporary tree is archived. The resulting ZIP still goes through
the ordinary `ckrelease verify` and fresh-install smoke test.

Because the archive is built from tracked files, everything a `.gitignore`
already covers is absent for free, unless it is declared build output; the list
above is only about files that are committed on purpose and still have no
business on a production site.

Per repo, in `civikitchen.yaml` (where every other repo-level policy lives):

```yaml
policy:
  dist:
    exclude:
      - build
      - docs/internal
    include:
      - path: tests
        reason: release fixture needed by this extension
```

`ckrelease verify` re-checks the built archive: one top-level directory named
after the key, an `info.xml` at the released version, every declared build
output, and no excluded name as a path segment *at any depth* — which is how a
second `tests/` under a sub-package gets caught, since the build only excludes
at the root.

### Build output git does not track

A frontend bundle or a third-party dist that a repo builds instead of committing
is declared in `civikitchen.yaml`, together with the build that writes it:

```yaml
policy:
  dist:
    build:
      tool: bun
      outputs:
        - ang/example/app.bundle.js
        - dist/vendor-js/
```

On a tag the release workflow sets up the Bun that `package.json` pins as
`"packageManager": "bun@x.y.z"`, runs `bun install --frozen-lockfile` and
`bun run build`, and `ckrelease dist` adds exactly the listed paths — files, or
directories with everything in them — to the tree it archives, on top of the
bundled `vendor/` when `composer_install` is on. Nothing else the build leaves
in the checkout reaches the zip. The release fails when

- a listed output is missing after the build (each one is named),
- a listed output is tracked by git, which ships it from git already,
- a listed output lies in the development layer or under `dist.exclude`,
- `package.json` has no exact Bun pin, or `bun.lock` is not committed: without a
  lockfile `bun install --frozen-lockfile` installs unlocked and succeeds.

The build runs before any credential is minted on the runner, because it
executes the repo's and its dependencies' code. A build script that calls a Node
binary gets the runner's own Node.

Locally, run the same two Bun commands first. `ckrelease dist` never runs the
build: it stages the outputs the working tree holds and refuses, naming each
missing one, until they exist. Build at the commit you archive, since the
outputs come from the working tree and not from `--ref`.

No caller input is involved, so a repo adopts this in `civikitchen.yaml` alone.

### Why not `.gitattributes export-ignore`

It is the git-native way to say this, and it was rejected on purpose.
`.gitattributes` is a template-**managed** file: civikitchen owns its bytes and
every repo's CI compares them. Putting the exclude list there means (a) shipping
packaging costs a fleet-wide drift round and a contract version bump, and (b) the
list then exists in as many copies as there are repos, free to drift apart —
the exact state the shared tooling exists to end. The central list plus
`civikitchen.yaml` gives one source of truth and a declared, reasoned per-repo
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
downloads. Before the install, the `info.xml` `<requires>` are resolved the way
the image entrypoint resolves them for a mounted extension: a dependency the
fresh site lacks is downloaded from the registry, or from the
version-constrained, digest-pinned `policy.extension_sources` entry in the repo's `civikitchen.yaml` (copied in for
that step — the archive itself carries no `civikitchen.yaml`), and enabled first. The
smoke test catches the failure class that no amount of green CI does: a PHP
file that the exclude list swallowed, a `<requires>` on an extension that no
pin and no registry serves, an upgrader that fatals on a first install.

### A dependency only a credential can reach

A `<requires>` on an extension in a private repository has no public URL to
pin: the browser download path answers 404 without a session, and the
authenticated REST endpoint addresses the asset by an id that changes whenever
the asset is replaced — which `--clobber` does on every re-run of a release.
Such a dependency is pinned by *what it is* rather than by where it happens to
sit today:

```yaml
policy:
  extension_sources:
    - key: exampleframe
      version: '^0.1'
      release:
        repository: example-org/exampleframe
        tag: v0.1.0
        asset: exampleframe-0.1.0.zip
      sha256: <the release's own .sha256>
      reason: private repository, no registry serves it
```

The runner resolves that by tag and asset name, verifies the SHA-256, and
mounts **only the verified bytes** into the smoke container, which verifies
them again against the same pin before installing. The App token stays on the
runner — the container never sees the credential, only its result, the same
rule the sibling checkout follows in `extension-ci.yml`. List the dependency's
repository in `composer_app_repositories`, or the download is a 404.

Outside the release workflow the archive comes from `CK_DEP_ARCHIVE_DIR`
([configuration](configuration.md)); in a dev stack you normally mount the
dependency into the ext dir instead and no pin is consulted at all.

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
unzip. A repo with declared build output needs its build run first.
