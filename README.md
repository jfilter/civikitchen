# civikitchen

CiviCRM Docker images for development, testing, and demos. All published to GHCR.

## When to use what

| Image | Use case | DB | First start |
|-------|----------|-----|-------------|
| [`:standalone`](#standalone-dev) | Extension dev — fast iteration, headless tests | external (compose) | runs `cv core:install` |
| [`:drupal10`](#drupal-10-dev) | Test extensions against the Drupal 10 stack | external (compose) | runs `civibuild` |
| [`:wordpress`](#wordpress-dev) | Test extensions against the WordPress stack | external (compose) | runs `civibuild` |
| [`civicrm-eu-ngo`](#eu-ngo-all-in-one-demo) | Demos, evaluation, EU-NGO showcase | embedded | imports baked-in SQL dump |

**Most users want `standalone`** — it's the fastest dev loop and works for any extension that doesn't depend on a specific CMS. Use the buildkit images (`drupal10`, `wordpress`) only when you need to test CMS-specific behavior.

## Quickstart

Each image has a ready-to-run compose example with phpMyAdmin and Maildev:

```bash
git clone https://github.com/jfilter/civikitchen
cd civikitchen/examples/standalone   # or drupal10 / wordpress
docker compose up -d
# CiviCRM:    http://localhost:8080
# phpMyAdmin: http://localhost:8081
# Maildev:    http://localhost:1080
```

For extension development, see [Extension development](#extension-development).

## Images

### Standalone (dev)

Official `civicrm/civicrm` image with dev tools added:
- **pcov** — fast code coverage (replaces xdebug for coverage-only use)
- **civix** — scaffolding/build tool for extensions
- **phpunit 9** — pinned for CiviCRM compatibility
- **phpstan** — static analysis

CiviCRM is auto-installed on first container start when `CIVICRM_AUTO_INSTALL=1`. See [Extension development](#extension-development) for the full setup.

```yaml
services:
  app:
    image: ghcr.io/jfilter/civicrm-dev:standalone
    ports: ["8080:80"]
    environment:
      CIVICRM_AUTO_INSTALL: "1"
      CIVICRM_DB_HOST: db
      CIVICRM_DB_NAME: civicrm
      CIVICRM_DB_USER: civicrm
      CIVICRM_DB_PASSWORD: civicrm
    depends_on:
      db: { condition: service_healthy }
    volumes:
      - ../:/var/www/html/ext/myextension   # your extension repo
  db:
    image: mariadb:10.11
    # ... see examples/standalone/docker-compose.yml
```

Ready-to-run: [`examples/standalone/`](examples/standalone/)

### Drupal 10 (dev)

CiviCRM on Drupal 10 via [civicrm-buildkit](https://github.com/civicrm/civicrm-buildkit). Site is built on first container start using `civibuild` (~60s). Requires an external MariaDB.

```yaml
services:
  app:
    image: ghcr.io/jfilter/civicrm-dev:drupal10
    ports: ["8080:80"]
    environment:
      MYSQL_HOST: db
      MYSQL_ROOT_PASSWORD: root
      SITE_URL: http://localhost:8080   # must match the port mapping
    depends_on: [db]
  db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
```

> **`SITE_URL` matters.** CiviCRM uses it for all asset paths (JS, CSS, fonts). If the port mapping differs from the URL the user opens, assets 404. Default is `http://localhost` (port 80).

Ready-to-run: [`examples/drupal10/`](examples/drupal10/)

### WordPress (dev)

CiviCRM on WordPress via buildkit. Same pattern and env vars as Drupal 10.

Ready-to-run: [`examples/wordpress/`](examples/wordpress/)

### EU-NGO (all-in-one demo)

Pre-built single-container image: CiviCRM + Drupal 10 + 9 EU-nonprofit extensions + embedded MariaDB + demo data. For demos and evaluation, **not** for development.

```bash
docker run -d -p 8080:80 --name civicrm ghcr.io/jfilter/civicrm-eu-ngo:latest
# Wait ~30s, then open http://localhost:8080
# Login: admin / admin
```

See [`allinone/README.md`](allinone/README.md) for details and the bundled extension list.

## Extension development

The standalone image is designed for the test loop most extension authors run:
edit code → reload → run phpunit. The example at [`examples/standalone/`](examples/standalone/) is the recommended starting point.

**1. Mount your extension into the container:**

```yaml
volumes:
  - /path/to/your/extension:/var/www/html/ext/myextension
```

**2. First start does the install automatically:**

```bash
docker compose up -d
# Container runs `cv core:install` against the linked DB on first boot.
# Subsequent starts skip the install (idempotent: settings.php and DB persist).
```

**3. Enable + test:**

```bash
docker compose exec app cv ext:enable myextension
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpunit"
```

### Configuration

| Env var | Default | Purpose |
|---------|---------|---------|
| `CIVICRM_AUTO_INSTALL` | `0` | Set to `1` to auto-install CiviCRM on first start (when `civicrm.settings.php` is missing). |
| `CIVICRM_DB_HOST` | `db` | DB hostname. |
| `CIVICRM_DB_PORT` | `3306` | DB port. |
| `CIVICRM_DB_NAME` | `civicrm` | Database name. |
| `CIVICRM_DB_USER` | `civicrm` | DB user. |
| `CIVICRM_DB_PASSWORD` | `civicrm` | DB password. |

### Headless test setup

Headless extension tests (`cv('php:boot --level=classloader', …)`) create a scratch database `civicrm_test` on the fly, which needs global privileges — not just on `civicrm.*`. The `examples/standalone/` setup ships [`db-init/01-grants.sql`](examples/standalone/db-init/01-grants.sql) which mariadb runs on first boot:

```sql
GRANT ALL PRIVILEGES ON *.* TO 'civicrm'@'%' WITH GRANT OPTION;
```

If you roll your own compose file, replicate this — otherwise headless tests fail with "you need (at least one of) the SUPER privilege(s)".

### Idempotency

`docker compose down` (without `-v`): drops the container, keeps the DB volume. The entrypoint detects existing tables and runs `cv core:install -K` to keep them — boot stays fast and DB state survives.

`docker compose down -v`: drops everything, including the DB volume. Next `up` is a fresh install.

## Tags & versions

The standalone image is published in two flavors on GHCR:

| Tag | What it points at |
|-----|-------------------|
| `:standalone` | The most recent CiviCRM `latest` build (rebuilt weekly). |
| `:standalone-latest` | Same as `:standalone`. |
| `:standalone-<version>` | Pinned to a specific upstream `civicrm/civicrm:<version>` tag (e.g. `:standalone-6.12.1`). |

Use a pinned tag when you want reproducible builds — for CI matrix testing across CiviCRM versions, or to test an extension against a release before upgrading.

To add a new pinned version, edit the `version: [...]` matrix in [`.github/workflows/build-dev-images.yml`](.github/workflows/build-dev-images.yml) and trigger the workflow. Only versions actually published as `civicrm/civicrm:<x.y.z>` on Docker Hub work.

## Building locally

```bash
# Standalone (tracks civicrm/civicrm:latest)
docker build -t civicrm-dev:standalone images/standalone/

# Standalone pinned to a specific CiviCRM version
docker build --build-arg CIVICRM_VERSION=6.12.1 -t civicrm-dev:standalone-6.12.1 images/standalone/

# Drupal 10 (with specific PHP version)
docker build --build-arg PHP_VERSION=8.3 -t civicrm-dev:drupal10 images/drupal10/

# WordPress
docker build --build-arg PHP_VERSION=8.3 -t civicrm-dev:wordpress images/wordpress/
```

## License

AGPL-3.0 — see [LICENSE.md](LICENSE.md).
