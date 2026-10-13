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

The host needs Docker with the compose plugin and bash; everything else runs in
the image.

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

- `ckcreate` generates the module with civix and adds the template: compose
  files under `.docker/`, CI and release workflows, phpstan/phpunit config and
  a first test. For an existing civix extension, `scaffold/ckinit <dir>` adds
  the same files; it refuses when any of them already exists.
- `ckup` starts CiviCRM Standalone with MariaDB, phpMyAdmin and Maildev,
  enables the extension on first boot and prints the URL. Its arguments go to
  `docker compose up -d`; `--wait` returns once the first boot is done.
- `ckx <command>` runs a command in the stack as `www-data` in the extension's
  directory; without a command it opens a shell. Headless tests run against a
  separate test database, not the dev site.

The image carries cv, civix, composer, node/npm, phpunit, phpstan, mago, pcov,
xdebug and the `ck*` tools. Without the template, mount the extension into one
of the stacks in `examples/` (`standalone`, `drupal10`, `drupal11`,
`wordpress`, `joomla`); `examples/extension-with-playwright/` adds browser
tests.

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

CI boots the stack from `.docker/docker-compose.ci.yml`, runs PHPUnit under
coverage and the gates below, and fails when template-managed files have
drifted. Matrix images, lifecycle and upgrade tests, schema parity, JS tests,
Playwright and mutation testing are opt-in inputs, documented in
[`extension-ci.yml`](.github/workflows/extension-ci.yml). The template's
`release.yml` calls `extension-release.yml@v1` on a `vX.Y.Z` tag push
([Releasing an extension](docs/extension-releases.md)).

`ck ci` runs the gates in this order; `ckx ck ci` runs the same locally and
`--only cklint,ckfmt` picks gates.

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

Per-repository policy (licence, coverage floor, …) lives in `civikitchen.yaml`
at the extension root; suppressions always carry a reason. `@v1` pins
workflows, template, tools and the `:v1` image as one version
([Releases](docs/releases.md)). Without the template, `examples/ci/` has a
self-contained workflow.

→ [Extension standards](docs/extension-standards.md),
[Declarative scenarios](docs/scenarios.md)

## Core development

`ckcoretest` runs core's own PHPUnit suites against the core in a
`:standalone` stack, fetching the tests the release tarball leaves out:

```bash
docker compose exec app ckcoretest tests/phpunit/api/v4/Query
docker compose exec app ckcoretest --ext search_kit
```

`--e2e` runs the `@group e2e` tests against the dev site instead.
`:standalone-master` and `:standalone-beta` are built daily from core's
`master` and newest release branch. To test a core change, mount the
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
container and goes with it; for another host port, set `CIVIKITCHEN_SITE_URL`
to the URL you open.

A profile adds an extension stack, seed data and API users on first boot
(`verein`, `fundraising`, `events`, `mailing`):

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

`ghcr.io/civico-de/civikitchen` is multi-arch. `:standalone` is the main image;
`:drupal10`, `:drupal11`, `:wordpress` and `:joomla` carry the same tools on a
CMS site, each with a `-demo` variant. Moving tags are rebuilt daily from the
current CiviCRM stable and move only after their tests pass; extensions pin the
`:v1` release tags. → [Images](docs/images.md),
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
