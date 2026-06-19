# civikitchen

CiviCRM Docker images for development, testing, and demos. All published to GHCR.

## When to use what

| Image | Use case | DB | First start |
|-------|----------|-----|-------------|
| [`:standalone`](docs/images.md#standalone-dev) | Extension dev — fast iteration, headless tests | external (compose) | runs `cv core:install` |
| [`:drupal10`](docs/images.md#drupal-10-dev) | Test extensions against the Drupal 10 stack | external (compose) | runs `civibuild` |
| [`:drupal11`](docs/images.md#drupal-11-dev) | Test extensions against the Drupal 11 stack | external (compose) | runs `civibuild` |
| [`:wordpress`](docs/images.md#wordpress-dev) | Test extensions against the WordPress stack | external (compose) | runs `civibuild` |
| [`:joomla5`](docs/images.md#joomla-5-dev) | Test extensions against the Joomla 5 stack | external (compose) | runs `civibuild` |
| [`:{standalone,drupal10,wordpress}-demo`](docs/images.md#demo-images) | Single-container demos — `docker run` and go | embedded (baked) | boots from baked data dir |

**Most users want `standalone`** — it's the fastest dev loop and works for any extension that doesn't depend on a specific CMS. Use the buildkit images (`drupal10`, `drupal11`, `wordpress`, `joomla5`) only when you need to test CMS-specific behavior.

## Quickstart

Each dev image has a ready-to-run compose example with phpMyAdmin and Maildev:

```bash
git clone https://github.com/jfilter/civikitchen
cd civikitchen/examples/standalone   # or drupal10 / drupal11 / wordpress / joomla5
docker compose up -d
# CiviCRM:    http://localhost:8080   (login: admin / admin)
# phpMyAdmin: http://localhost:8081
# Maildev:    http://localhost:1080
```

For a throwaway demo (no compose, embedded DB and demo data):

```bash
docker run -d -p 80:80 --name civicrm ghcr.io/jfilter/civikitchen:drupal10-demo
# then open http://localhost  —  login: admin / admin
```

Need a CiviCRM version the published images don't offer (older than ~6.0, or to
mirror a production server)? Build it locally via civibuild on a Drupal base —
ready-to-run: [`examples/custom-version/`](examples/custom-version/)
(`CIVICRM_VERSION=5.78.2 docker compose up -d --build`). See
[Custom or older CiviCRM versions](docs/images.md#custom-or-older-civicrm-versions).

## Documentation

- [Images](docs/images.md) — image flavors in detail, demo profiles (`CIVIKITCHEN_PROFILE`), tags & versions
- [Extension development](docs/extension-development.md) — mount, test (phpunit/headless/Playwright), civix, PHPStan, linting, IDE step debugging, provisioning hooks
- [Configuration](docs/configuration.md) — every env var the images understand
- [Building locally](docs/building.md) — build args, `KEEP_GIT=1`, verifying a built image

## License

AGPL-3.0 — see [LICENSE.md](LICENSE.md).
