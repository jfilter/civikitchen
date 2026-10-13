# civikitchen

[![Build Dev Images](https://github.com/civico-de/civikitchen/actions/workflows/build-dev-images.yml/badge.svg)](https://github.com/civico-de/civikitchen/actions/workflows/build-dev-images.yml)
[![Lint](https://github.com/civico-de/civikitchen/actions/workflows/lint.yml/badge.svg)](https://github.com/civico-de/civikitchen/actions/workflows/lint.yml)
[![GHCR](https://img.shields.io/badge/GHCR-ghcr.io%2Fcivico--de%2Fcivikitchen-24292f?logo=github)](https://github.com/civico-de/civikitchen/pkgs/container/civikitchen)
[![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE.md)

CiviCRM Docker images with the development toolchain built in, an extension
template, and reusable GitHub workflows that test, check and release CiviCRM
extensions. Documentation: <https://civico-de.github.io/civikitchen/>

| You want to … | Start with |
|---------------|------------|
| Develop an extension | [Extension development](#extension-development) |
| Test and release it on GitHub | [CI and releases](#ci-and-releases) |
| Work on CiviCRM core | [Core development](#core-development) |
| Try or demo CiviCRM | [Demo instances](#demo-instances) |

The host needs Docker with the compose plugin and bash. Everything else runs
in the image.

## Extension development

```bash
git clone https://github.com/civico-de/civikitchen
civikitchen/scaffold/ckcreate myext \
    --author "Example Maintainer" --email dev@example.org --copyright "Example Org"
cd myext
git add -A && git commit -m "Scaffold myext"   # after reviewing it
../civikitchen/scaffold/ckup --wait --wait-timeout 900   # dev stack on free ports; waits out the first boot
../civikitchen/scaffold/ckx ck ci                         # tests and every check, as CI runs them
```

`ckcreate` generates the module with civix and adds the template: the
compose files under `.docker/`, the CI and release workflows, the phpstan and
phpunit config, and a first test. For an existing civix extension,
`scaffold/ckinit <dir>` adds the same files. It refuses when any of them
already exists.

`ckup` starts CiviCRM Standalone with MariaDB, phpMyAdmin and Maildev, enables
the extension on first boot and prints the URL. Its arguments go to
`docker compose up -d`, and `--wait` returns once the first boot is done.

`ckx` runs a command in the stack as www-data, in the extension's directory.
Without a command it opens a shell. Headless tests run against a separate test
database, not the dev site.

The image carries cv, civix, composer, node and npm, phpunit, phpstan, mago,
pcov, xdebug and the ck tools. Without the template, mount the extension into
one of the stacks in `examples/`: standalone, drupal10, drupal11, wordpress or
joomla. `examples/extension-with-playwright/` adds browser tests.

→ [Extension development](docs/extension-development.md): test loop, civix,
static analysis, Playwright, step debugging, several extensions in one
repository.

## CI and releases

A templated extension calls the shared workflows:

```yaml
# .github/workflows/ci.yml
jobs:
  ci:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
```

CI boots the stack from `.docker/docker-compose.ci.yml` and runs PHPUnit with
coverage and the gates below. It fails when template-managed files have
drifted. Matrix images, lifecycle and upgrade tests, schema parity, JS tests,
Playwright and mutation testing are opt-in inputs, documented in
[`extension-ci.yml`](.github/workflows/extension-ci.yml). On a `vX.Y.Z` tag
push, the template's release workflow calls `extension-release.yml@v1`
([Releasing an extension](docs/extension-releases.md)).

`ck ci` runs the gates in this order. Run `ckx ck ci` for the same locally,
and add `--only cklint,ckfmt` to pick gates.

| Gate | Checks |
|------|--------|
| `cklint` | `php -l`, mago lint with CiviCRM-specific rules |
| `ckconform` | Repository conventions: config files, licence and PHP floor, test bootstrap, workflow permissions, managed entities |
| `ckcivix` | civix format is current |
| `ckfmt` | Formatting: mago for PHP, oxfmt for JS/TS |
| `ckcoverage` | PHPUnit line coverage against the floor |
| `phpunit-extra` | A second PHPUnit config, if `--extra-phpunit-config` names one |
| `phpstan` | Level 10 with civikitchen's CiviCRM rules |
| `phpstan-tests` | phpstan over the tests, if `phpstan-tests.neon[.dist]` exists |
| `ckanalyze` | `mago analyze` against core; findings are reported, not gating |
| `ckcompat` | PHP compatibility with the declared floor |
| `ckdeps` | `composer.json` matches what the code uses |
| `cktaint` | Taint analysis with psalm |
| `cksmarty` | Every Smarty template compiles |
| `ckeslint` | oxlint for JS/TS |

Per-repository policy, such as the licence and the coverage floor, lives in
`civikitchen.yaml` at the extension root. Suppressions always carry a reason.
Pinning `@v1` pins the workflows, the template, the tools and the v1 image as
one version ([Releases](docs/releases.md)). Without the template,
`examples/ci/` has a self-contained workflow.

→ [Extension standards](docs/extension-standards.md),
[Declarative scenarios](docs/scenarios.md)

## Core development

`ckcoretest` runs core's own PHPUnit suites against the core in a standalone
stack. It fetches the tests that the release tarball leaves out:

```bash
docker compose exec app ckcoretest tests/phpunit/api/v4/Query
docker compose exec app ckcoretest --ext search_kit
```

With `--e2e` it runs the e2e group against the dev site instead. The
`:standalone-master` and `:standalone-beta` images are built daily from core's
master and its newest release branch. To test a core change, mount the
directory it touches from a civicrm-core checkout over the image's copy:

```yaml
services:
  app:
    image: ghcr.io/civico-de/civikitchen:standalone-master
    volumes:
      - ../civicrm-core/ext/afform:/var/www/html/core/ext/afform
```

`ckeslint --core` type-checks core's JavaScript for upstream bug reports.

→ [CiviCRM core development](docs/core-development.md):
other branches, e2e and browser tests, what is not covered.

## Demo instances

```bash
docker run -d -p 80:80 --name civicrm ghcr.io/civico-de/civikitchen:standalone-demo
```

Open http://localhost and log in as admin / admin. The database lives in the
container and goes away with it. For another host port, set
`CIVIKITCHEN_SITE_URL` to the URL you open.

A profile adds an extension stack, seed data and API users on first boot. The
profiles are `verein`, `fundraising`, `events` and `mailing`:

```bash
docker run -d -p 80:80 --name civicrm -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/civico-de/civikitchen:drupal10-demo
docker logs -f civicrm   # first boot installs the profile, takes a few minutes
docker exec civicrm cat /home/buildkit/api-credentials.txt
```

To mirror a version the published tags do not cover, `examples/custom-version/`
builds a Drupal 10 image for any version civibuild can fetch.

→ [Demo images](docs/images.md#demo-images),
[Profiles](docs/images.md#profiles-civikitchen_profile)

## Images

`ghcr.io/civico-de/civikitchen` is multi-arch. The standalone image is the
main one. The drupal10, drupal11, wordpress and joomla images carry the same
tools on a CMS site, and each has a demo variant. Moving tags are rebuilt daily
from the current CiviCRM stable and move only after their tests pass.
Extensions pin the v1 release tags. → [Images](docs/images.md),
[Tags and versions](docs/images.md#tags-and-versions)

## Documentation

| Document | Contents |
|----------|----------|
| [Images](docs/images.md) | Flavors, demo images, profiles, tags, database compatibility, custom versions |
| [Configuration](docs/configuration.md) | Environment variables the images read |
| [Core development](docs/core-development.md) | `ckcoretest`, unreleased core branches, mounting a core checkout |
| [Extension development](docs/extension-development.md) | Dev stack, tests, civix, analysis, Playwright, debugging |
| [Extension standards](docs/extension-standards.md) | What the gates enforce and why |
| [Declarative scenarios](docs/scenarios.md) | `civikitchen.yaml`: policy and stack scenarios |
| [Reusable workflows](docs/reusable-workflows.md) | `frontend-ci.yml`, `playwright-e2e.yml`, `command-check.yml` |
| [Releasing an extension](docs/extension-releases.md) | `ckrelease` and `extension-release.yml` |
| [Releases](docs/releases.md) | How civikitchen itself is versioned |
| [Building locally](docs/building.md) | Build arguments, tool pins, image tests; `make doctor` |
| [Architecture](docs/implementation-architecture.md), [ADRs](docs/adr/) | Design decisions; [mago](docs/mago-consolidation.md) and [core types](docs/core-types.md) background |
| [Changelog](CHANGELOG.md) | Changes per release |

## License

AGPL-3.0. See [LICENSE.md](LICENSE.md).
