# civikitchen 🍳

[![Build Dev Images](https://github.com/jfilter/civikitchen/actions/workflows/build-dev-images.yml/badge.svg)](https://github.com/jfilter/civikitchen/actions/workflows/build-dev-images.yml)
[![Lint](https://github.com/jfilter/civikitchen/actions/workflows/lint.yml/badge.svg)](https://github.com/jfilter/civikitchen/actions/workflows/lint.yml)
[![GHCR](https://img.shields.io/badge/GHCR-ghcr.io%2Fjfilter%2Fcivikitchen-24292f?logo=github)](https://github.com/jfilter/civikitchen/pkgs/container/civikitchen)
[![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE.md)

**CiviCRM Docker images for extension development, CI testing, and demos**: a fast Standalone dev loop, CMS compatibility targets for Drupal 10/11, WordPress, and Joomla, and single-container demos with realistic data profiles. Built for `linux/amd64` and `linux/arm64`.

```bash
docker run -d -p 80:80 ghcr.io/jfilter/civikitchen:standalone-demo
# open http://localhost — admin / admin
```

For extension work, start with [`examples/standalone/`](examples/standalone/); for a throwaway demo, use a `*-demo` image.

## Why

Testing a CiviCRM extension properly means running it against a real CiviCRM — ideally against several CMS flavors, with realistic data, and without spending a day on setup. civikitchen bakes that setup into images:

- **One `docker run` to a working CiviCRM** — the demo images embed MariaDB and demo data.
- **A fast dev loop** — mount your extension, `docker compose up`, edit, reload, `phpunit`.
- **Shared workflow across CMS flavors** — profiles, dev tools, SMTP capture, extension provisioning, and init hooks work consistently on Standalone, Drupal 10/11, WordPress, and Joomla; CMS-specific install knobs are documented per image.
- **Batteries included** — composer, node, civix, phpunit 9, phpstan, phpcs + civicrm/coder, xdebug, pcov, `cklint` (opinionated extension linting), and `ckmodernize` (Rector-based CiviCRM modernization, including opt-in assisted API3→API4 rewrites for safe cases).
- **Realistic demo data via profiles** — one env var installs a curated extension stack, seed data, and API users (e.g. a German Verein with SEPA mandates and membership history).

## Pick an image

| Image | Use case | DB | First start |
|-------|----------|-----|-------------|
| [`:standalone`](docs/images.md#standalone-dev) | Extension dev — fastest loop, headless tests | external (compose) | `cv core:install` (seconds) |
| [`:drupal10`](docs/images.md#drupal-10-dev) | Test against the Drupal 10 stack | external (compose) | `civibuild` (~60 s) |
| [`:drupal11`](docs/images.md#drupal-11-dev) | Test against the Drupal 11 stack | external (compose) | `civibuild` (~60 s) |
| [`:wordpress`](docs/images.md#wordpress-dev) | Test against the WordPress stack | external (compose) | `civibuild` (~60 s) |
| [`:joomla`](docs/images.md#joomla-dev) | Test against the Joomla stack | external (compose) | `civibuild` (~60 s) |
| [`:{standalone,drupal10,drupal11,wordpress,joomla}-demo`](docs/images.md#demo-images) | Single-container demos — `docker run` and go | embedded (baked) | boots from baked data (seconds) |

**Most users want `:standalone`** — it's the fastest dev loop and works for any extension that doesn't depend on a specific CMS. Reach for the buildkit images (`drupal10`, `drupal11`, `wordpress`, `joomla`) when you need to test CMS-specific behavior.

## Quickstart: extension development

Each dev image has a ready-to-run compose example with phpMyAdmin and Maildev (all you need is Docker with the compose plugin):

```bash
git clone https://github.com/jfilter/civikitchen
cd civikitchen/examples/standalone   # or drupal10 / drupal11 / wordpress / joomla
docker compose up -d
# CiviCRM:    http://localhost:8080   (login: admin / admin)
# phpMyAdmin: http://localhost:8081
# Maildev:    http://localhost:1080
```

Mount your extension, enable it, run its tests. The mount path below is the standalone one; each CMS example documents its own extension path in its compose file:

```bash
# docker-compose.yml:  volumes: ["../my-extension:/var/www/html/ext/myextension"]
docker compose exec app cv ext:enable myextension
docker compose exec -e CIVICRM_UF=UnitTests app \
    bash -c "cd /var/www/html/ext/myextension && phpunit"
```

On `:standalone`, headless tests run against an isolated `<db>_test` scratch database the image configures automatically — a stray `phpunit` can't wipe your dev data; the CMS images get an isolated test database from civibuild. See [Extension development](docs/extension-development.md) for the full workflow (civix, Playwright UI tests, PHPStan, step debugging, provisioning hooks).

## Quickstart: demo with realistic data

Profiles layer a curated extension stack + seed data + API users on top of any flavor at first boot:

```bash
# German Verein showcase: SEPA mandates, membership types, 24 members, API users
docker run -d -p 80:80 --name civicrm \
    -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/jfilter/civikitchen:drupal10-demo
docker logs -f civicrm            # first boot clones extensions — needs network, takes a few minutes
```

Available profiles: [`verein`, `fundraising`, `events`, `mailing`](docs/images.md#profiles-civikitchen_profile) — each with seed data and least-privilege API users (credentials land in the logs and in the container). One thing to know about demo images: the database lives inside the container — great for demos and screenshots, wrong for data you want to keep. To run on a port other than 80, set `CIVIKITCHEN_SITE_URL` to match (e.g. `-p 8080:80 -e CIVIKITCHEN_SITE_URL=http://localhost:8080`).

## CI usage

Use the same images in CI: boot a compose stack, mount the extension, run `phpunit` inside the container — headless via `CIVICRM_UF=UnitTests` as above. A copy-pasteable GitHub Actions setup (workflow + minimal compose stack) is at [`examples/ci/`](examples/ci/); `CIVIKITCHEN_EXTRA_EXTENSIONS` and `/civikitchen-init.d` hooks replace hand-rolled provisioning scripts ([Configuration](docs/configuration.md)).

## Need an older CiviCRM?

The published tags track the current stable. For an older or pinned version — say, to mirror a production server — build locally via civibuild on a Drupal base; a parameterized compose setup is at [`examples/custom-version/`](examples/custom-version/):

```bash
cd examples/custom-version
CIVICRM_VERSION=5.78.2 docker compose up -d --build
```

Details in [Custom or older CiviCRM versions](docs/images.md#custom-or-older-civicrm-versions).

## Documentation

- [Images](docs/images.md) — every flavor in detail, demo profiles, tags & versioning
- [Extension development](docs/extension-development.md) — mount, test (phpunit/headless/Playwright), civix, PHPStan, linting, IDE step debugging, provisioning hooks
- [Configuration](docs/configuration.md) — every env var the images understand
- [Building locally](docs/building.md) — build args, `KEEP_GIT=1`, running the test suite locally

## Reliability

Images rebuild **weekly** and on image-pipeline changes, against the current CiviCRM stable, and every tag is test-then-promote: dev tags move only after dev-tool checks, first-boot tests against an external DB, and real-browser smoke tests of the compose examples; demo tags move only after single-container boot tests, including every profile on every demo flavor. If a candidate fails its gate, the previous stable tag stays in place.

## License

AGPL-3.0 — see [LICENSE.md](LICENSE.md).
