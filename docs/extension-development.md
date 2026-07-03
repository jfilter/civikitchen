# Extension development

The standalone image is designed for the test loop most extension authors run:
edit code → reload → run phpunit. The example at [`examples/standalone/`](../examples/standalone/) is the recommended starting point.

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

For headless tests (extending `CiviUnitTestCase` or implementing `HeadlessInterface`), set `CIVICRM_UF=UnitTests`:

```bash
docker compose exec -e CIVICRM_UF=UnitTests app \
  bash -c "cd /var/www/html/ext/myextension && phpunit"
```

This runs the test framework against a **separate** scratch database
(`<db>_test`, e.g. `civicrm_test`), not your dev site. On first install the
image creates that database and writes `TEST_DB_DSN` to `~/.cv.json` (for both
`root` and `www-data`) so the framework finds it. **Without `TEST_DB_DSN` set,
CiviCRM falls back to the main database and a headless `phpunit` run wipes your
dev data** — so this is configured automatically. Opt out with
`CIVIKITCHEN_TEST_DB=0` if you manage `TEST_DB_DSN` yourself.

**Resetting the scratch DB — `cktestreset`.** A suite can leave `<db>_test`
inconsistent: `Civi\Test`'s `installMe()` does *not* resolve `<requires>`, so
a test that installs only its own extension leaves it enabled with its
dependencies missing. Every later `CIVICRM_UF=UnitTests` boot then dies during
the class scan (`Interface "..." not found`) — and it does not heal itself,
because the stale class index also survives in the test DB's SQL cache and in
the per-DSN `CachedCiviContainer.*`/`CachedExtLoader.*` files. `cktestreset`
resets all layers in one go (drop + reseed `<db>_test` from the main DB, clear
the cached containers/loaders):

```bash
docker compose exec app cktestreset
```

The durable fix belongs in the extension: list the dependency chain explicitly
in `setUpHeadless()`, e.g.
`\Civi\Test::headless()->install(['my-dependency'])->installMe(__DIR__)->apply()`.

All first-boot knobs (SMTP, extra extensions, demo users, …) are listed in the
[configuration reference](configuration.md).

## CI

The same loop runs unattended in CI: boot the stack, enable the extension,
run phpunit headless. A copy-pasteable GitHub Actions setup (workflow +
minimal compose stack + DB grants) lives at [`examples/ci/`](../examples/ci/).

## Provisioning hooks

Anything a test setup needs beyond `cv ext:enable` — renderer config, seed
data, system packages — can run automatically on first boot. Mount scripts
into `/civikitchen-init.d/`; after a fresh auto-install they run in lexical
order: `*.sh` via bash (as root), `*.php` via `cv scr` (as www-data). A
failing hook aborts the boot, so broken provisioning is loud.

```yaml
services:
  app:
    environment:
      CIVIKITCHEN_EXTRA_PACKAGES: "libreoffice-writer,unoconv"
      CIVIKITCHEN_ENABLE_EXTENSIONS: "mailattachment,de.systopia.civioffice"
    volumes:
      - ./init.d:/civikitchen-init.d:ro
```

## UI tests with Playwright

For browser-level tests of your extension's UI (forms, Angular/React widgets, JS behaviour) there's a copy-pasteable starter at [`examples/extension-with-playwright/`](../examples/extension-with-playwright/). It boots the same standalone stack, runs Playwright on the host against `localhost:8080`, and handles login once via a shared session.

```bash
cd examples/extension-with-playwright
docker compose up -d
npm install && npx playwright install chromium
npm test
```

See the [example's README](../examples/extension-with-playwright/README.md) for how to drop the four config files into your own extension repo.

## Civix workflow

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

## PHPStan

PHPStan needs to know about CiviCRM's autoloader to resolve `CRM_*` and `Civi\*` symbols. Each extension typically ships its own `phpstanBootstrap.php` that boots civi enough for static analysis (`extensions/de.systopia.contract/phpstanBootstrap.php` is a working reference). Run:

```bash
docker compose exec app bash -c \
    "cd /var/www/html/ext/myextension && phpstan analyse"
```

## Linting

`phpcs` is preinstalled with the [civicrm/coder](https://github.com/civicrm/coder) fork of `drupal/coder` on the `8.x-2.x-civi` branch. The ruleset registers itself as the standard `Drupal` and `DrupalPractice` standards (the civi fork relaxes a handful of rules but keeps the names).

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpcs --standard=Drupal ."
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpcbf --standard=Drupal ."  # auto-fix
```

For the stricter CiviKitchen extension checks, use `cklint` instead. It wraps
`php -l` + `phpcs`, defaults to changed PHP files, and uses the bundled
`CiviKitchen` standard when the extension does not provide its own
`phpcs.xml(.dist)`:

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && cklint"
docker compose exec app bash -c "cd /var/www/html/ext/myextension && cklint --all"
```

Most extensions ship a `phpcs.xml.dist` that scopes the run to the right files and excludes generated DAOs — see `extensions/de.systopia.contract/phpcs.xml.dist` for a working reference.

## Modernizing

`ckmodernize` runs the bundled Rector setup from an extension root. By default
it previews changes; pass `--fix` to apply them. If the extension ships its own
`rector.php`, that config wins.

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && ckmodernize"
docker compose exec app bash -c "cd /var/www/html/ext/myextension && ckmodernize --fix --php 8.2"
```

The default config combines Rector's PHP-version / code-quality sets with
CiviKitchen rules for CiviCRM-specific footguns such as
`CRM_Utils_Array::value()` and `CRM_Core_Error::fatal()`.

## IDE step debugging

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

## Database grants for headless tests

The headless test harness needs more than rights on the dev database: it works
in the separate `<db>_test` schema (created at first install, see above) and
runs a `SET GLOBAL` performance tweak during schema init, which requires the
`SUPER` privilege. The `examples/standalone/` setup ships
[`db-init/01-grants.sql`](../examples/standalone/db-init/01-grants.sql), which
mariadb applies on first boot:

```sql
GRANT ALL PRIVILEGES ON *.* TO 'civicrm'@'%' WITH GRANT OPTION;
```

If you roll your own compose file, replicate this — otherwise headless tests
fail with "you need (at least one of) the SUPER privilege(s)" or with access
denied on `<db>_test`.

## Idempotency

`docker compose down` (without `-v`): drops the container, keeps the DB volume. The entrypoint detects existing tables and runs `cv core:install -K` to keep them — boot stays fast and DB state survives.

`docker compose down -v`: drops everything, including the DB volume. Next `up` is a fresh install.
