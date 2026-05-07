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
# CiviCRM:    http://localhost:8080   (login: admin / admin)
# phpMyAdmin: http://localhost:8081
# Maildev:    http://localhost:1080
```

For extension development, see [Extension development](#extension-development).

## Images

### Standalone (dev)

Official `civicrm/civicrm` image with dev tools added:
- **composer** — most modern extensions ship vendor deps
- **node + npm** — for extensions with Angular/JS assets
- **pcov** — fast code coverage (always on)
- **xdebug** — step debugging, opt-in via `XDEBUG_MODE` (see [IDE step debugging](#ide-step-debugging))
- **civix** — scaffolding/build tool for extensions
- **phpunit 9** — pinned for CiviCRM compatibility
- **phpstan** — static analysis
- **phpcs + civicrm/coder** — the de-facto CiviCRM style guide (relaxed Drupal CS)

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

CiviCRM on WordPress via buildkit. Same pattern and env vars as Drupal 10. The `:wordpress` and `:drupal10` tags are built from the same Dockerfile (`images/buildkit/`) — only the default civibuild site type differs (`wp-demo` vs `drupal10-demo`). Both images carry the same dev tools as the standalone image (composer, node/npm, phpunit, phpstan, phpcs+coder, civix, pcov, xdebug).

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

**3. Install vendor deps (if your extension uses composer):**

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && composer install"
```

**4. Enable + test:**

```bash
docker compose exec app cv ext:enable myextension
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpunit"
```

For headless tests (extending `CiviUnitTestCase` or implementing `HeadlessInterface`), set `CIVICRM_UF=UnitTests` so PHPUnit boots an isolated test database:

```bash
docker compose exec -e CIVICRM_UF=UnitTests app \
  bash -c "cd /var/www/html/ext/myextension && phpunit"
```

### UI tests with Playwright

For browser-level tests of your extension's UI (forms, Angular/React widgets, JS behaviour) there's a copy-pasteable starter at [`examples/extension-with-playwright/`](examples/extension-with-playwright/). It boots the same standalone stack, runs Playwright on the host against `localhost:8080`, and handles login once via a shared session.

```bash
cd examples/extension-with-playwright
docker compose up -d
npm install && npx playwright install chromium
npm test
```

See the [example's README](examples/extension-with-playwright/README.md) for how to drop the four config files into your own extension repo.

### Civix workflow

The image ships [`civix`](https://github.com/totten/civix) for scaffolding. Common commands:

```bash
docker compose exec app civix generate:module org.example.myext   # bootstrap a new extension
docker compose exec app civix generate:entity MyEntity            # APIv4-exposed entity + schema
docker compose exec app civix generate:test --template headless \
    \\Civi\\Myext\\Test\\MyHeadlessTest                            # boilerplate for a headless test
docker compose exec app civix upgrade                             # re-run periodically; bumps mixins,
                                                                  # backports polyfills, refreshes
                                                                  # generated stubs to current civix
```

Modern extensions configure features in `info.xml` via [standard mixins](https://docs.civicrm.org/dev/en/latest/framework/mixin/standard/) (`mgd-php`, `menu-xml`, `setting-php`, `entity-types-php@2.0.0`, `smarty-v2`, `ang-php`, …) instead of bespoke hooks — `civix upgrade` keeps the mixin block current.

### PHPStan

PHPStan needs to know about CiviCRM's autoloader to resolve `CRM_*` and `Civi\*` symbols. Each extension typically ships its own `phpstanBootstrap.php` that boots civi enough for static analysis (`extensions/de.systopia.contract/phpstanBootstrap.php` is a working reference). Run:

```bash
docker compose exec app bash -c \
    "cd /var/www/html/ext/myextension && phpstan analyse"
```

### Linting

`phpcs` is preinstalled with the [civicrm/coder](https://github.com/civicrm/coder) fork of `drupal/coder` on the `8.x-2.x-civi` branch. The ruleset registers itself as the standard `Drupal` and `DrupalPractice` standards (the civi fork relaxes a handful of rules but keeps the names).

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpcs --standard=Drupal ."
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpcbf --standard=Drupal ."  # auto-fix
```

Most extensions ship a `phpcs.xml.dist` that scopes the run to the right files and excludes generated DAOs — see `extensions/de.systopia.contract/phpcs.xml.dist` for a working reference.

### IDE step debugging

Xdebug is installed but disabled until you set `XDEBUG_MODE`. Add it to your compose file:

```yaml
services:
  app:
    environment:
      XDEBUG_MODE: "debug,develop"
      # XDEBUG_CLIENT_HOST: host.docker.internal   # default — works for Docker Desktop
      # XDEBUG_CLIENT_PORT: "9003"                 # default
      # XDEBUG_START_WITH_REQUEST: trigger         # default; "yes" to break on every request
      # XDEBUG_IDEKEY: VSCODE                      # default
```

VS Code `.vscode/launch.json` (path mapping must match your volume mount):

```json
{
  "version": "0.2.0",
  "configurations": [{
    "name": "Listen for Xdebug",
    "type": "php",
    "request": "launch",
    "port": 9003,
    "pathMappings": {
      "/var/www/html/ext/myextension": "${workspaceFolder}"
    }
  }]
}
```

PhpStorm: enable "Listen for PHP Debug Connections", set the port to 9003, and add a path mapping from your project to `/var/www/html/ext/myextension`.

`XDEBUG_START_WITH_REQUEST=trigger` (the default) means xdebug only activates when the request carries `XDEBUG_TRIGGER=1` (cookie, GET/POST param, or env var) — no overhead on regular requests. Use the [Xdebug Helper](https://xdebug.org/docs/step_debug#start_with_request) browser extension to send the trigger.

### Configuration

| Env var | Default | Purpose |
|---------|---------|---------|
| `CIVICRM_AUTO_INSTALL` | `0` | Set to `1` to auto-install CiviCRM on first start (when `civicrm.settings.php` is missing). |
| `CIVICRM_DB_HOST` | `db` | DB hostname. |
| `CIVICRM_DB_PORT` | `3306` | DB port. |
| `CIVICRM_DB_NAME` | `civicrm` | Database name. |
| `CIVICRM_DB_USER` | `civicrm` | DB user. |
| `CIVICRM_DB_PASSWORD` | `civicrm` | DB password. |
| `CIVICRM_DEMO_USER` | _(unset)_ | If set during auto-install, creates a CiviCRM login user with this username and the `admin` role. Requires `CIVICRM_AUTO_INSTALL=1`. |
| `CIVICRM_DEMO_PASS` | `admin` | Password for the demo user. |
| `CIVICRM_DEMO_EMAIL` | `admin@example.org` | Email for the demo user's contact record. |
| `CIVICRM_COMPONENTS` | all standard | Comma-separated CiviCRM components to enable at install. Defaults to the full set: `CiviEvent,CiviContribute,CiviMember,CiviMail,CiviPledge,CiviCase,CiviReport,CiviCampaign`. Override to narrow the set, or pass an empty string for `cv`'s own core-only default. |
| `CIVICRM_SMTP_HOST` | _(unset)_ | If set, points Civi's `mailing_backend` at this SMTP host after install. The example compose stack uses `maildev` so outbound mail lands in the maildev UI on `:1080`. |
| `CIVICRM_SMTP_PORT` | `1025` | Port for `CIVICRM_SMTP_HOST`. |

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

The build context is the `images/` dir for both the standalone and buildkit-based images, so the Dockerfiles can `COPY lib/install-dev-tools.sh` (the shared phars + phpcs/coder install).

```bash
# Standalone (tracks civicrm/civicrm:latest)
docker build -f images/standalone/Dockerfile -t civicrm-dev:standalone images/

# Standalone pinned to a specific CiviCRM version
docker build -f images/standalone/Dockerfile \
    --build-arg CIVICRM_VERSION=6.12.1 \
    -t civicrm-dev:standalone-6.12.1 images/

# Buildkit-based images. The :drupal10 and :wordpress tags are built from
# the same Dockerfile (images/buildkit/) — DEFAULT_SITE_TYPE picks which
# civibuild site type the entrypoint creates on first run.
docker build -f images/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    -t civicrm-dev:drupal10 images/

docker build -f images/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=wp-demo \
    -t civicrm-dev:wordpress images/
```

## Verifying a built image

`images/test/test-dev-tools.sh` is a functional check of every bundled tool — it lints non-conforming PHP through phpcs, runs phpstan against a typed mistake, executes a phpunit assertion, installs a real package via composer, and verifies the xdebug toggle. The same script runs in CI against both `:standalone` and `:drupal10`/`:wordpress`.

```bash
docker run --rm -v "$(pwd)/images/test:/civikitchen-test:ro" \
    --entrypoint='' \
    ghcr.io/jfilter/civicrm-dev:standalone \
    bash /civikitchen-test/test-dev-tools.sh
```

## License

AGPL-3.0 — see [LICENSE.md](LICENSE.md).
