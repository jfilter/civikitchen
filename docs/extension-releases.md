# Releasing an extension

This page explains how a conforming extension repo cuts a release. Versioning
civikitchen itself is a different topic, covered in [releases.md](releases.md),
where the workflow, template, tools and images form one contract.

Releases work the same way as CI. The pipeline lives here once, in
`.github/workflows/extension-release.yml`, and the extension repo's own
workflow only calls it. The tool it runs, ckrelease, ships in the images. A
laptop and GitHub Actions therefore build the archive with the same code.

## What a release is

A release consists of three things, in this order.

1. A commit that bumps the version in `info.xml`. It also bumps
   `composer.json`, and `CHANGELOG.md` if the repo keeps one. `ckrelease check`
   fails when the two version numbers disagree. A human writes and reviews
   this commit.
2. A tag named `v<version>`. It states that the commit is ready.
3. Everything after the tag. This part is mechanical and therefore automated.
   It covers the consistency check, the distribution archive, an install into a
   real CiviCRM, and the GitHub release.

Step 1 is deliberately manual. A version number is a claim about the
compatibility of a change, and no tool can derive that from a diff
([ADR-0002](adr/0002-a-release-is-a-human-version-and-a-tag.md)).

Steps 1 and 2 belong together. The `release-tags` check in ckconform enforces
this after the fact. It compares every version `info.xml` has carried with the
repo's tags, and fails on a version the repo moved past without tagging it.
Such a release exists in the history but on no site.

### A version that will never be released

Sometimes a bump is re-scoped or taken back. The repo then passed through a
version number it never wants to publish. Tagging it late does not fix this.
The tag push runs the release workflow and publishes the code of that moment
under a version nobody reviewed for release. Name the version instead, with
the reason:

```yaml
policy:
  untagged_versions:
    - version: "1.2.0"
      reason: bump re-scoped into 1.3.0 before anything shipped
```

Quote the version. Unquoted, a two-component number like 1.0 is a YAML float,
and the schema rejects it as "expected string". Write it exactly as `info.xml`
carries it, without the leading v. The check adds the v itself.

`release-tags` then skips exactly that version and keeps failing on every
other one. An entry that excuses nothing warns as stale. That happens in three
cases:

- the version is tagged after all,
- it is the version `info.xml` carries right now, which is the job of
  `release-tag-coherence`,
- `info.xml` never carried it.

The list is meant to shrink. With `policy.release.mode: none` there is no tag
to miss at all, so a list next to that setting warns as well.

## Adopting it in a repo

The caller workflow `.github/workflows/release.yml` is managed by the
template. Run `/path/to/civikitchen/scaffold/ckinit --update .` to write it.
After that, the template drift job in CI keeps it in line. The managed part is
the trigger, the permissions and the `uses:` line:

```yaml
on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+', 'v[0-9]+.[0-9]+.[0-9]+-*']
```

GitHub matches a tag filter against the whole tag name. The second pattern is
what lets a pre-release tag such as `v1.3.0-rc.1` start a run.

Inputs and secrets for the call go below the `# END CIVIKITCHEN MANAGED caller`
marker. Everything there survives `ckinit --update`:

```yaml
# END CIVIKITCHEN MANAGED caller
    with:
      # The install needs a payment processor no headless site can reach.
      smoke_test: false
      require_changelog: true
```

A caller written before the markers existed is rewritten onto the template.
Its job's `with:` and `secrets:` blocks move below the marker as written,
comments included. If the file holds anything else of its own, such as another
trigger, an `env:` block or a further job, `--update` writes nothing and lists
what it would drop. `--check` reports the same. Move that content elsewhere or
declare the file under `policy.template_custom`. `--update` also refuses to
create `release.yml` while another workflow already calls
`extension-release.yml`, because one tag push would then publish twice.

Then do the following once:

- Give `info.xml` a SemVer version, either `X.Y.Z` or `X.Y.Z-<pre-release>`.
  `civix generate:module` writes 1.0, which the `version-format` check in
  ckconform fails. Set it to 0.1.0 and release that. ckcreate does this for
  you.
- Add `.ckrelease/` to `.gitignore`. That is where `ckrelease dist` writes
  locally.
- Run `ckrelease check` and fix whatever it reports before the first tag.

A repo that never releases declares that instead, with the reason. ckinit then
neither writes the caller nor reports it missing. `ckinit --update` removes an
existing caller unless it carries lines of your own.

```yaml
policy:
  release:
    mode: none
    reason: internal tooling, never installed on a site
```

The `release-workflow` check in ckconform fails a repo that does neither. A
repository with several extensions releases them together from one caller at
its root. See
[Several extensions in one repository](extension-development.md#several-extensions-in-one-repository).

### Inputs

| Input | Default | What it is for |
|-------|---------|----------------|
| `working_directory` | `.` | The extension's directory in a repository of several extensions. |
| `stage` | `release` | `release` builds and publishes. `build` builds, verifies, smoke-tests and uploads the archive as `ckrelease-dist-<key>`. `publish` builds nothing and publishes one release with every archive of the run. |
| `dry_run` | `false` | Builds the version `info.xml` carries without a tag. The publish job lists the archives instead of publishing them. |
| `image` | empty | The image the smoke test installs into. When empty, it uses `:standalone-<minor>` from `policy.civicrm`, otherwise `ghcr.io/civico-de/civikitchen:v1`. Setting both is an error. |
| `smoke_test` | `true` | Set it to `false` only for an extension whose install genuinely cannot be reached this way, and say why in the caller. |
| `require_changelog` | `false` | Fails when the repo has no `CHANGELOG.md` at all. |
| `draft` | `false` | Publishes the GitHub release as a draft. |
| `composer_install` | `false` | Installs the lockfile with `--no-dev` and bundles the generated vendor tree. |
| `composer_app_repositories` | unset | Repository names within the caller's owner, separated by commas or newlines. Required with the App secrets, and used as the token allowlist. |
| `composer_app_id`, `composer_app_private_key` | unset | A GitHub App with `contents: read`, installed on the private dependency repos. It serves private Composer packages and staged dependency releases. Pass both explicitly under `secrets:`. Passing one without the other fails the run instead of counting as "no app". |

## Cutting one

Start on a clean main:

```bash
$EDITOR info.xml composer.json CHANGELOG.md   # the version bump
ckrelease check                               # before committing, not after
git commit -am 'Release 1.3.0'
git tag -a v1.3.0 -m 'v1.3.0' && git push origin main v1.3.0
```

The tag push runs the workflow. It runs `ckrelease check`, then
`ckrelease dist`, then the install smoke test, and finally
`gh release create --generate-notes` with the zip and its `.sha256` file
attached.

Which release GitHub marks as Latest follows these rules:

- A tag with a SemVer pre-release suffix, such as `v1.3.0-rc.1`, is published
  as a pre-release and never marked Latest.
- A plain tag is marked Latest only when it is the highest plain `vX.Y.Z` in
  the repo. A late release of an older version leaves Latest where it is.
- A higher tag whose release never succeeded keeps Latest away from lower
  releases until it does succeed.
- A draft is never marked Latest.

A tag that passes the trigger but is no SemVer version, such as
`v1.2.3-rc..1`, fails the run before the build. Shapes such as `v1.3` or
`v2.2.7.1` start no run at all.

Locally, `ckrelease dist` produces exactly the same archive from the same
commit, because `git archive` is deterministic. You can inspect what will ship
before pushing anything.

Some archives are built from a temporary Git tree instead of the commit:

- With `composer_install: true`, the runner also installs the locked
  production dependencies and archives a temporary tree containing them. The
  release tag itself does not change.
- A repo that declares [build output](#build-output-git-does-not-track) is
  archived from such a tree as well, on a laptop too.

An archive of a temporary tree carries the time it was built instead of the
commit time. It matches another build of the same tree in content, but not
byte for byte.

## What the archive contains

The archive holds the tracked files at the tag plus any declared build output.
They sit under one top-level directory named after the extension key. The
development layer is left out:

```
.github/ .docker/ .civikitchen/ .claude/ tests/ node_modules/
.gitattributes .gitignore .editorconfig civikitchen.yaml .phpunit.result.cache
.gitlab-ci.yml renovate.json mago.toml phpcs.xml(.dist)
composer-dependency-analyser.php phpstan.neon(.dist) phpstan-tests.neon(.dist)
phpstanBootstrap.php phpunit.xml(.dist) phpunit-unit.xml.dist .oxlintrc.json
playwright.config.ts/.js vitest.config.ts/.js
package-lock.json bun.lock(b) tsconfig.json
```

Secrets are never packed, whatever the policy says. That covers `.git`,
`.env`, `.env.*`, `.envrc`, `.netrc`, `.npmrc`, `.pypirc`, `auth.json` and
`credentials.json`. Run `ckconform --dist-paths` to print the effective list
for a repo.

Two directories are deliberately not on the list.

- `vendor/`. A repo either commits it because the site needs it at runtime, or
  sets `composer_install: true` so the release workflow bundles the locked
  production tree. A packager that silently drops runtime code is worse than
  one that ships a test file.
- `dist/`. For an extension that builds a frontend, the committed build is the
  shipped artifact. A build that is not committed is declared instead, as
  described below.

When `composer_install` is enabled, package-level copies of the same excluded
development paths are removed before the temporary tree is archived. An
example is `vendor/acme/package/.github/`. The resulting zip still goes through
the ordinary `ckrelease verify` and the fresh-install smoke test.

### Prefixed dependencies (Strauss)

PHP has one class table per process. When two extensions bundle different
versions of the same package, both use whichever copy loads first. The result
is a fatal error or a silently wrong result. A package that other extensions
may bundle too is therefore prefixed with
[Strauss](https://github.com/BrianHenryIE/strauss), in place in the vendor
directory:

```json
"extra": {
  "strauss": {
    "target_directory": "vendor",
    "namespace_prefix": "Civi\\Myext\\Vendor\\",
    "classmap_prefix": "Civi_Myext_Vendor_",
    "packages": ["acme/lib"]
  }
},
"scripts": {
  "prefix-namespaces": ["@php .civikitchen/strauss.php", "@composer dump-autoload"],
  "post-install-cmd": ["@prefix-namespaces"],
  "post-update-cmd": ["@prefix-namespaces"]
}
```

How this setup behaves:

- Declaring `extra.strauss` makes `.civikitchen/strauss.php` a managed file
  that `ckinit --update` writes. The script caches the pinned Strauss release
  in the system temp directory. It runs a private copy after checking its
  SHA-256, so the phar never lands in the extension. The archive leaves
  `.civikitchen/` out. A deploy from the git tag does ship the file, which then
  exits at once outside the CLI. Removing `extra.strauss` leaves the script in
  place, so delete it together with the config.
- Every `composer install` runs the script. That includes the release
  workflow's install with `composer_install: true`, the one in CI, and a deploy
  that builds from the tag. The archive carries the prefixed tree.
- The extension's main file loads `vendor/autoload.php`.
- Packages that core ships, such as Guzzle, psr/log and psr/http-message, go
  under `provide`, with a range that covers the supported core versions. Never
  use `*`. A bundled copy would replace core's copy for every extension. When
  the range does not satisfy a requirement, Composer installs its own copy
  without a warning.
- Strauss also writes `vendor/composer/autoload_aliases.php`, which would
  resolve the unprefixed names. The `composer dump-autoload` that runs after it
  leaves that file unreferenced. Code that names a package without the prefix
  therefore fails in development just as in production.
- That `composer dump-autoload` writes a plain autoloader, so
  `--optimize-autoloader` on the outer install has no effect. Classes resolve
  through the PSR-4 and PSR-0 rules instead of a classmap.

Because the archive is built from tracked files, everything `.gitignore`
covers is already absent, unless it is declared build output. The list above
only concerns files that are committed on purpose but have no business on a
production site.

Per repo, adjust the list in `civikitchen.yaml`, where every other repo-level
policy lives:

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

`ckrelease verify` re-checks the built archive. It expects one top-level
directory named after the key, an `info.xml` at the released version and every
declared build output. It also checks that no excluded name appears as a path
segment at any depth. The build only excludes at the root, so this is how a
second `tests/` under a sub-package gets caught.

The exclusion list lives in civikitchen (`ckconform --dist-paths`) and in
`civikitchen.yaml`, not in `export-ignore` entries in `.gitattributes`. See
[ADR-0003](adr/0003-release-exclusions-live-in-civikitchen.md). `git archive`
still honours a repo's own `export-ignore` attributes on top.

### Build output git does not track

Some repos build a frontend bundle or a third-party dist instead of committing
it. Declare that output in `civikitchen.yaml`, together with the build that
writes it:

```yaml
policy:
  dist:
    build:
      tool: bun
      outputs:
        - ang/example/app.bundle.js
        - dist/vendor-js/
```

On a tag, the release workflow sets up the Bun version that `package.json`
pins as `"packageManager": "bun@x.y.z"`. It runs `bun install --frozen-lockfile`
and `bun run build`. `ckrelease dist` then adds exactly the listed paths to
the tree it archives. A path can be a file or a directory with everything in
it. With `composer_install` on, these paths come on top of the bundled vendor
tree. Nothing else the build leaves in the checkout reaches the zip.

The release fails when

- a listed output is missing after the build (each one is named),
- a listed output is tracked by git, which already ships it,
- a listed output lies in the development layer or under `dist.exclude`,
- `package.json` has no exact Bun pin, or `bun.lock` is not committed. Without
  a lockfile, `bun install --frozen-lockfile` installs unlocked and succeeds.

The build executes code from the repo and its dependencies. It therefore runs
before any credential is minted on the runner. A build script that calls a
Node binary gets the Node version from the `node_version` input, with the same
default as extension CI.

Locally, run the same two Bun commands first. `ckrelease dist` never runs the
build. It stages the outputs present in the working tree, and refuses until
they all exist, naming each missing one. Build at the commit you archive,
because the outputs come from the working tree and not from `--ref`.

Extension CI builds the same output before every job that runs the test suite
or the browser tests: `ci`, `mutation`, `compat` and `playwright`. Those jobs
load the output, and git does not carry it. Other jobs skip the build:

- `upgrade` and `schema-parity` check the previous release out into the mount.
  An untracked build would collide there with a `dist/` that the old release
  still committed.
- `core-upgrade` runs no suite.

A Bun build needs `bun: true` in the CI caller, and CI fails without it. The
release builds from one tree installed one way, and CI has to match it. The
GitLab pipeline has no Bun, so a release-only build works on GitHub only.

## The smoke test

The smoke test unzips the archive into a fresh CiviCRM in the standalone image
and installs it with `cv en`. There is no source mount, so the site sees
exactly what a user downloads.

Before the install, the `<requires>` entries in `info.xml` are resolved the
same way the image entrypoint resolves them for a mounted extension. A
dependency the fresh site lacks is downloaded and enabled first. It comes from
the registry, or from the matching `policy.extension_sources` entry in the
repo's `civikitchen.yaml`, which is version-constrained and digest-pinned. The
`civikitchen.yaml` is copied in for that step only, because the archive itself
does not carry it.

The smoke test catches failures that green CI never does:

- a PHP file that the exclude list swallowed,
- a `<requires>` on an extension that no pin and no registry serves,
- an upgrader that fatals on a first install.

### A dependency only a credential can reach

An extension in a private repository has no public URL to pin. The browser
download path answers 404 without a session. The authenticated REST endpoint
addresses the asset by an id that changes whenever the asset is replaced, and
`--clobber` replaces it on every re-run of a release. Such a dependency is
therefore pinned by what it is rather than by where it happens to sit today:

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

The runner resolves the archive by tag and asset name and verifies the
SHA-256. It mounts only the verified bytes into the smoke container, which
verifies them again against the same pin before installing. The App token
stays on the runner. The container never sees the credential, only its
result. The sibling checkout in `extension-ci.yml` follows the same rule. List
the dependency's repository in `composer_app_repositories`, or the download
returns 404.

Outside the release workflow, the archive comes from `CK_DEP_ARCHIVE_DIR`
([configuration](configuration.md)). In a dev stack you normally mount the
dependency into the extension directory instead, and no pin is consulted.

The smoke test costs a CiviCRM boot, a few minutes, per release. If it turns
out flaky rather than informative, `smoke_test: false` turns it off in one
line. Turn it off visibly in the caller rather than quietly widening what
"released" means.

## `ckrelease` outside CI

```
ckrelease check  [--version <v>] [--require-changelog]
ckrelease dist   [--version <v>] [--ref <ref>] [--output <dir>]
ckrelease verify <zip> [--version <v>]
ckrelease info   key|file|version|dist-name
```

ckrelease ships in the civikitchen images, so `docker compose exec app ckrelease …`
works. It also runs standalone from a checkout with bash, git, php and unzip.
It first needs the YAML parser, which
`composer install --working-dir=packages/civikitchen-scenario-schema` or
`make tools` fetches. A repo with declared build output needs its build run
first.
