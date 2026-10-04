# civikitchen

[![Build Dev Images](https://github.com/jfilter/civikitchen/actions/workflows/build-dev-images.yml/badge.svg)](https://github.com/jfilter/civikitchen/actions/workflows/build-dev-images.yml)
[![Lint](https://github.com/jfilter/civikitchen/actions/workflows/lint.yml/badge.svg)](https://github.com/jfilter/civikitchen/actions/workflows/lint.yml)
[![GHCR](https://img.shields.io/badge/GHCR-ghcr.io%2Fjfilter%2Fcivikitchen-24292f?logo=github)](https://github.com/jfilter/civikitchen/pkgs/container/civikitchen)
[![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE.md)

civikitchen is a set of CiviCRM Docker images with development tools built in,
plus an extension template and reusable GitHub workflows for testing, checking
and releasing CiviCRM extensions.

## Quickstart

Look around in a throwaway CiviCRM Standalone with demo data:

```bash
docker run -d -p 80:80 --name civicrm ghcr.io/jfilter/civikitchen:standalone-demo
```

Open http://localhost and log in as admin / admin.

Start a new extension:

```bash
git clone https://github.com/jfilter/civikitchen
composer install --no-dev --working-dir=civikitchen/packages/civikitchen-scenario-schema
civikitchen/scaffold/ckcreate myext \
    --author "Example Maintainer" --email dev@example.org --copyright "Example Org"
cd myext
git add -A && git commit -m "Scaffold myext"   # after reviewing it
../civikitchen/scaffold/ckup         # dev stack on free host ports, prints the URL
../civikitchen/scaffold/ckx ck ci    # tests and every check, as CI runs them
```

`ckcreate` runs `civix generate:module` in a throwaway stack, then adds the
template: dev and CI compose files under `.docker/`, the CI and release
workflows, phpcs/phpstan/phpunit config, the test bootstrap and a first test,
in a fresh git repository that passes `ck ci`. `ckup` writes free host ports to
`.docker/.env` and runs `docker compose up -d`. `ckx <command>` runs a command
in that stack as `www-data`, in the extension's directory; without a command it
opens a shell.

For an existing civix extension, `scaffold/ckinit.php <extension-dir>` adds the
same files. It refuses to overwrite files that already exist.

## Extension development

The dev stack from `ckup` runs CiviCRM Standalone with MariaDB, phpMyAdmin and
Maildev. For an extension that does not use the template, the standalone
example has the same services:

```bash
git clone https://github.com/jfilter/civikitchen
cd civikitchen/examples/standalone
# uncomment the volumes: block in docker-compose.yml and point it at your extension
docker compose up -d
```

CiviCRM is at http://localhost:8080 (admin / admin), phpMyAdmin at :8081,
Maildev at :1080. On first boot the container installs CiviCRM and enables
every extension mounted under `/var/www/html/ext`, installing its `<requires>`
first.

Run headless tests:

```bash
docker compose exec -e CIVICRM_UF=UnitTests app \
    bash -c "cd /var/www/html/ext/myextension && phpunit"
```

They run against a separate `civicrm_test` database, not the dev site. If a
suite leaves that database broken, `docker compose exec app cktestreset`
rebuilds it.

The image includes cv, civix, composer, node/npm, phpunit, phpstan, phpcs,
pcov, and xdebug (off until `XDEBUG_MODE` is set). Tool versions are pinned in
`toolbelt/versions.env`, `toolbelt/install-dev-tools.sh` and the composer roots
under `toolbelt/`.

The `:drupal10`, `:drupal11`, `:wordpress` and `:joomla` images work the same
way, but the extension directory differs per CMS. The compose files in
`examples/drupal10/`, `examples/drupal11/`, `examples/wordpress/` and
`examples/joomla/` show the mount path. For browser
tests, start from `examples/extension-with-playwright/`.

## CI for extensions

An extension created by `ckcreate` or `ckinit.php` has a CI caller in
`.github/workflows/ci.yml`:

```yaml
jobs:
  ci:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
```

The workflow boots the stack from `.docker/docker-compose.ci.yml`, runs PHPUnit
under coverage and the checks listed in the next section, and fails when
template-managed files have drifted (`ckinit.php --check`). More jobs are
opt-in through inputs such as `matrix_images`, `lifecycle`,
`upgrade_from_last_release`, `schema_parity`, `core_upgrade_from`, `js_tests`,
`playwright` and `mutation`. Each input is documented in
[`extension-ci.yml`](.github/workflows/extension-ci.yml).

`@v1` pins the workflow, the template, the `ck*` tools and the `:v1` image as
one version. See [Releases](docs/releases.md).

`ckx ck ci` runs the in-container part of CI locally; `--only cklint,ckfmt`
picks gates.

Releases work the same way: the template's `release.yml` calls
`extension-release.yml@v1` on a `vX.Y.Z` tag push. See
[Releasing an extension](docs/extension-releases.md).

For a repository that does not use the template, `examples/ci/` has a
self-contained workflow and compose file that boot `:standalone` and run
phpunit, with a commented-out matrix job for the CMS flavors.

## Coding standards in CI

`ck ci` runs these gates in order and prints a summary table. The shared
workflow calls the same command.

| Gate | What it checks |
|------|----------------|
| `cklint` | `php -l`, phpcs with the bundled `CiviKitchen` standard (civicrm/coder base plus CiviCRM-specific sniffs), and `mago lint` bug patterns |
| `ckconform` | Repository conventions: required config files, licence and PHP-floor coherence between `info.xml` and `composer.json`, test bootstrap guards, workflow permissions, managed entities and more |
| `ckcivix` | civix format is current |
| `ckfmt` | Formatting: mago for PHP, oxfmt for JS/TS |
| `ckcoverage` | PHPUnit line coverage against the configured floor |
| `phpstan` | Level 10; the repository's `phpstan.neon.dist` includes civikitchen's CiviCRM rules, so it runs unwrapped |
| `ckcompat` | PHP compatibility with the floor declared in `composer.json` |
| `ckdeps` | `composer.json` matches what the code uses |
| `cktaint` | Taint analysis with psalm |
| `cksmarty` | Every shipped Smarty template compiles |
| `ckeslint` | oxlint baseline for JS/TS |

Each gate is also a `ck` subcommand (`ck lint`, `ck conform`, ...); see `ck help`.

Per-repository policy lives in `civikitchen.yaml` at the extension root:

```yaml
version: 1
policy:
  license: AGPL-3.0-or-later
  coverage:
    minimum: 80
```

Organisation-wide keys (licence, copyright, vendor) can live in one repository,
named by the `policy_defaults` input or the `CK_POLICY_DEFAULTS` variable.

Suppressions always carry a reason, for example
`// ckconform-ignore <check> -- <reason>`. `ck conform --format=sarif` writes
SARIF for code-scanning upload. The full checklist is in
[Extension standards](docs/extension-standards.md).

## CiviCRM core development

`ckcoretest` runs core's own PHPUnit suites against the core installed in a
`:standalone` stack:

```bash
docker compose exec app ckcoretest tests/phpunit/api/v4/Query
docker compose exec app ckcoretest --ext search_kit
```

The first run fetches the tests, phpunit config and seed SQL for the installed
version. It covers the headless PHP suites, and `--e2e` runs `@group e2e`
tests against the dev site. Upgrade tests and the karma/qunit JS tests need a
civibuild environment.

To test against `master` or an unreleased branch, build Standalone from git in
a checkout of this repository:

```bash
make build CIVICRM_SOURCE=git CIVICRM_VERSION=master   # tags civikitchen:standalone-master
```

Then mount the directory you are changing from your civicrm-core checkout over
the image's copy, and run `cv flush` after each change:

```yaml
services:
  app:
    image: civikitchen:standalone-master
    volumes:
      - ../civicrm-core/ext/afform:/var/www/html/core/ext/afform
```

`ckeslint --core` type-checks core's own JavaScript. A buildkit image built
with `--build-arg KEEP_GIT=1` keeps the git history of the civibuild site
([Building locally](docs/building.md#keeping-the-civicrm-git-history-keep_git1)).
The core fixes the standalone image applies until a release contains them are
in `docker/standalone/core-patches/`, one file per upstream issue.

## Demo instances

The `-demo` images from the quickstart keep their database inside the
container; it goes away with it. To use another host port, set the site URL to
match: `-p 8080:80 -e CIVIKITCHEN_SITE_URL=http://localhost:8080`.

A profile adds an extension stack, seed data and API users on first boot:

```bash
docker run -d -p 80:80 --name civicrm \
    -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/jfilter/civikitchen:drupal10-demo
docker logs -f civicrm
docker exec civicrm cat /home/buildkit/api-credentials.txt
```

The bundled profiles are `verein`, `fundraising`, `events` and `mailing`, in
`docker/profiles/`. A comma-separated list applies several in order. The first
boot clones extensions from GitHub, so it needs network access and takes a few
minutes. Profiles also work on the dev images.

Your own profiles are mounted with `CIVIKITCHEN_PROFILE_PATH` and
`CIVIKITCHEN_TRUST_EXTERNAL_PROFILES=1`; they run with admin rights.

For a CiviCRM version the published tags do not cover, for example to mirror
a production server, `examples/custom-version/` builds a Drupal 10 image for
any version civibuild can fetch:

```bash
cd examples/custom-version
CIVICRM_VERSION=5.78.2 docker compose up -d --build
```

## Images

`ghcr.io/jfilter/civikitchen` is multi-arch. `:standalone` is the main image;
`:drupal10`, `:drupal11`, `:wordpress` and `:joomla` carry the same tools on a
civibuild site, and each has a `-demo` variant with an embedded database.
Moving tags are rebuilt daily from the current CiviCRM stable and move only
after their tests pass; extension repositories pin the `:v1` release tags. See
[Images](docs/images.md) and [Tags & versions](docs/images.md#tags--versions).

## Requirements

- Using the images: Docker with the compose plugin.
- Scaffolding (`ckcreate`, `ckinit.php`, `ckup`, `ckx`): bash, PHP 8.1+, composer, Docker.
- Working on civikitchen: GNU Make 3.82+, bash, git, curl, php, composer, pipx.
  `make doctor` reports what is missing. `make lint` and `make test` need no
  Docker; `make build` and `make test-images` do.

## Documentation

| Document | Contents |
|----------|----------|
| [Images](docs/images.md) | Each flavor, demo profiles, tags, database compatibility, custom versions |
| [Configuration](docs/configuration.md) | Every environment variable the images read |
| [Extension development](docs/extension-development.md) | Test loop, civix, PHPStan, linting, Playwright, step debugging, provisioning hooks, several extensions in one repository |
| [Extension standards](docs/extension-standards.md) | What the checks enforce and why |
| [Reusable workflows](docs/reusable-workflows.md) | `frontend-ci.yml`, `playwright-e2e.yml`, `command-check.yml` |
| [Declarative scenarios](docs/scenarios.md) | `civikitchen.yaml`: policy and the scenario a stack is built from |
| [Releasing an extension](docs/extension-releases.md) | `ckrelease` and `extension-release.yml` |
| [Releases](docs/releases.md) | How civikitchen itself is versioned and released |
| [Building locally](docs/building.md) | Build arguments, tool pins, running the image tests |
| [Implementation architecture](docs/implementation-architecture.md) | Where PHP, shell and TypeScript each sit |
| [Architecture decisions](docs/adr/) | ADRs for the contract, release model, checks and images |
| [Changelog](CHANGELOG.md) | Changes per release |

## License

AGPL-3.0. See [LICENSE.md](LICENSE.md).
