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
# Container runs `cv core:install` against the linked DB on first boot, then
# enables every extension mounted under ext/ (its <requires> resolved first).
# Subsequent starts skip the install (idempotent: settings.php and DB persist).
```

Several stacks on one machine collide on host ports. The template compose
reads them from a gitignored `.docker/.env` (`CK_HTTP_PORT`, `CK_MAILDEV_PORT`,
`CK_SMTP_PORT`, `CK_PMA_PORT`) and derives `CIVIKITCHEN_SITE_URL` from the
HTTP one; `/path/to/civikitchen/scaffold/ckup` writes that file with free
ports on its first run and then runs `docker compose up -d` (any further
arguments are passed through). Without it the defaults 8080/1080/1025/8081
apply.

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
inconsistent: `Civi\Test`'s `installMe()` does *not* resolve `<requires>`
(the image entrypoint does, for the dev site — the test framework is its own
installer), so
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

The durable fix belongs in the extension: build the environment with the
managed bootstrap's `ck_headless()` instead of `\Civi\Test::headless()`. It
queues one install step per `info.xml` `<requires>` entry, then this
extension — so `setUpHeadless()` is `return ck_headless()->apply();` and the
dependency list lives in `info.xml` only. One level deep, like the image
entrypoint; and read from the file rather than asked of
`CRM_Extension_Manager`, because touching the extension system before
`Civi\Test` rebuilds the headless schema leaves caches the rebuilt site no
longer matches. A dependency the site does not have fails the install loudly;
further steps chain as before (`ck_headless()->sqlFile(...)->apply()`).

All first-boot knobs (SMTP, extra extensions, demo users, …) are listed in the
[configuration reference](configuration.md).

## CI

The same loop runs unattended in CI: boot the stack, enable the extension,
run phpunit headless. A copy-pasteable GitHub Actions setup (workflow +
minimal compose stack + DB grants) lives at [`examples/ci/`](../examples/ci/).

Boots that need something the runner has to fetch first — an uncommitted
`vendor/` behind a private composer package, or a private sibling extension
mounted beside this one, the CI equivalent of the sibling mount in the dev
compose file below — are two opt-in inputs and a `secrets:` block on the
shared workflow: [extension-standards.md](extension-standards.md#private-dependencies).

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
    volumes:
      - ../../de.systopia.civioffice:/var/www/html/ext/de.systopia.civioffice
      - ./init.d:/civikitchen-init.d:ro
```

## UI tests with Playwright

For browser-level tests of your extension's UI (forms, Angular/React widgets, JS behaviour) there's a copy-pasteable starter at [`examples/extension-with-playwright/`](../examples/extension-with-playwright/). It boots the same standalone stack, runs Playwright on the host against `localhost:8080`, and handles login once via a shared session.

```bash
cd examples/extension-with-playwright
docker compose up -d
npm install && npx playwright install chromium
npm run test:e2e
```

See the [example's README](../examples/extension-with-playwright/README.md) for how to drop the config files into your own extension repo.

## Civix workflow

The image ships [`civix`](https://github.com/totten/civix) for scaffolding. Common commands:

```bash
docker compose exec app civix generate:entity MyEntity            # APIv4-exposed entity + schema
docker compose exec app civix generate:test --template headless \
    \\Civi\\Myext\\Test\\MyHeadlessTest                            # boilerplate for a headless test
docker compose exec app civix upgrade                             # re-run periodically; bumps mixins,
                                                                  # backports polyfills, refreshes
                                                                  # generated stubs to current civix
```

Use `generate:entity` rather than writing `schema/*.entityType.php` by hand: the
schema names a DAO class under `class`, and the matching `CRM/<Ns>/DAO/<Entity>.php`
stub is generated alongside it. A schema without its stub looks complete and
passes every static check, then fatals at install time with `Class "…" not found`
— after earlier entities' tables exist, so the retry hits `DB Error: already
exists`. `ckconform`'s `entity-dao-stub` catches both that and a stub whose
`$_tableName` has drifted from the schema.

Modern extensions configure features in `info.xml` via [standard mixins](https://docs.civicrm.org/dev/en/latest/framework/mixin/standard/) (`mgd-php@2`, `menu-xml`, `setting-php`, `entity-types-php@2.0.0`, `smarty@1`, `ang-php`, …) instead of bespoke hooks — `civix upgrade` keeps the mixin block current. (`smarty-v2` is a deprecated alias of the version-independent `smarty@1` — same behaviour, misleading name.)

Create a module with the host-side wrapper. It boots the temporary CiviCRM
database which `civix generate:module` requires, generates the civix scaffold,
applies the versioned CiviKitchen tooling layer, and only then moves the complete
directory into place:

```bash
/path/to/civikitchen/scaffold/ckcreate example_ext \
  --author "Example Maintainer" \
  --email dev@example.org \
  --copyright "Example Organisation" \
  --license Proprietary
```

The default target is `./<key>`. Keys are deliberately plain lowercase
identifiers, so CiviCRM's extension key and file name stay identical. Repeated
values may live in `~/.config/civikitchen/ckcreate.conf` as `CKCREATE_AUTHOR`,
`CKCREATE_EMAIL`, `CKCREATE_COPYRIGHT`, `CKCREATE_LICENSE`, and
`CKCREATE_COMPATIBILITY`. `CKCREATE_PHP_COMPATIBILITY` can raise the template's
PHP floor while keeping `info.xml` and Composer aligned. `ckcreate --help`
lists the flags and defaults.

`ckcreate` is atomic: missing mandatory values, a civix error, or a template
error leaves no partial target directory. For an existing civix module that
only lacks the tooling layer, run `ckinit.php` directly:

```bash
/path/to/civikitchen/scaffold/ckinit.php org.example.myext
```

`ckinit.php` reads the extension `<file>` value from `info.xml`, renders
`scaffold/template/extension/`, and refuses to overwrite existing files. Use `--force`
only after reviewing conflicts. This makes the template an executable
interface rather than a checklist to copy by hand.

The template stays an interface after day one. Its files split into two
classes: **managed** files that are meant to be byte-identical in every repo
(the thin CI caller, the test bootstraps, the CI compose stack) and **seeded**
files the repo takes ownership of after the first copy (`composer.json`,
`phpcs.xml.dist` — the project layer over the central CiviKitchen standard —
`phpstan.neon.dist`, the dev compose file, `.gitignore`).
Two more modes work with that split:

```bash
/path/to/civikitchen/scaffold/ckinit.php --check  org.example.myext   # report drift, exit 1 on any
/path/to/civikitchen/scaffold/ckinit.php --update org.example.myext   # rewrite managed files, create missing ones
```

`--update` never touches an existing seeded file; review its output with
`git diff` like any other change. The shared `extension-ci.yml` workflow runs
`--check` on every push, so a template improvement shows up in each repo as a
red CI that one `--update` fixes.

The civix layer has the matching maintenance command inside the image:

```bash
ckcivix --check    # fail when <civix><format> or *.civix.php is missing/behind
ckcivix --update   # run civix upgrade -n
```

The shared workflow runs `ckcivix --check` with the other lint gates. Tool and
format versions are separate scales; `ckcivix` reads the latest format from the
upgrade scripts in the installed civix phar.

Which template it checks against follows the ref the repo pins. The seeded
caller says `extension-ci.yml@v1` and the CI stack says
`ghcr.io/jfilter/civikitchen:v1`, because workflow, template, `ck*` tools and
images are released as one version — the drift job checks the template out at
the caller's own resolved commit, so there is nothing else to keep in sync.
See [Releases](releases.md) for what a version covers and how one is cut. A repo that must deviate on a managed file
declares it in its `.ckconform` — the reason is mandatory, and only managed
files may be listed:

```
template_custom=.docker/docker-compose.ci.yml -- sibling mounts for e2e
```

A third-party `<requires>` is not a reason to deviate: the entrypoint reads
`info.xml` and installs missing dependencies before `ext:enable` (from the
registry, or from an `extension_source=<key>@<URL>` pin in `.ckconform` for a
release the registry does not serve — see [Configuration](configuration.md)).

Repo-specific test setup belongs in `tests/phpunit/bootstrap.local.php` (the
managed bootstrap requires it when present), not in edits to the managed
`tests/phpunit/bootstrap.php`.

## PHPStan

PHPStan needs to know about CiviCRM's autoloader to resolve `CRM_*` and
`Civi\*` symbols. The managed
[`phpstanBootstrap.php`](../scaffold/template/extension/phpstanBootstrap.php)
registers core's class loader, then the civix layout (`CRM_*`, `Civi\*`,
`api_*`, `vendor/`) of every extension the repo's `info.xml` `<requires>`
that exists under the ext dir (`CK_EXT_DIR`, default `/var/www/html/ext` — the
mount and download target of the images). A class extended from a required
extension therefore resolves without a repo-specific bootstrap or
`scanDirectories` entry; a required extension that is not present is noted on
stderr and its classes stay unresolved, which phpstan then reports where the
code touches them. Run:

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

Most extensions ship a `phpcs.xml.dist` that scopes the run to the right files and excludes generated DAOs — see [`scaffold/template/extension/phpcs.xml.dist`](../scaffold/template/extension/phpcs.xml.dist) for a working reference.

`ckconform` checks the repo STRUCTURE against the extension template — the
gaps that show up in every audit: missing `phpcs.xml.dist`/`phpstan.neon.dist`
(level 10)/`composer.json`/CI workflow, a test bootstrap without the
`TEST_DB_DSN` guard, committed cache artifacts. Run it from the extension
root; see [extension-standards.md](extension-standards.md) for the checklist
it enforces.

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && ckconform"
```

## Modernizing

`ckmodernize` modernizes an extension in two layers: **structure** via civix
(`civix upgrade` + `civix convert-entity`, civix extensions only) and **code**
via the bundled Rector setup. By default it previews the code changes and
lists the civix steps; `--fix` applies both (civix has no preview mode). If
the extension ships its own `rector.php`, that config wins. Scope with
`--no-civix` / `--no-rector`, explicit paths, or `--all`; the opt-in `--api`
(OO style) / `--api=array` flags additionally migrate a safe subset of APIv3
calls to APIv4 — preview and review those.

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && ckmodernize"
docker compose exec app bash -c "cd /var/www/html/ext/myextension && ckmodernize --fix --php 8.2"
```

The default config combines Rector's PHP-version / code-quality sets with
CiviKitchen rules for CiviCRM-specific footguns such as
`CRM_Utils_Array::value()` and `CRM_Core_Error::fatal()`.

## Taint analysis

`cktaint` runs Psalm as a taint engine only: it follows request input
(`CRM_Utils_Request::retrieve`, `$_GET`/`$_POST`) into SQL, shell, path and
redirect sinks, using CiviKitchen's CiviCRM stubs. The gate **blocks** on the
classes where a true positive is an outright vulnerability — `TaintedSql`,
`TaintedShell`, `TaintedInclude`, `TaintedUnserialize`, `TaintedSSRF`. The
noisier classes (file paths, headers, cookies, callables, eval, LDAP, secrets)
are reported but never part of the exit code.

```bash
docker compose exec app bash -c "cd /var/www/html/ext/myextension && cktaint"
```

What is modelled, what it cannot see, and how to handle a finding:
[extension-standards.md](extension-standards.md#taint-analysis-cktaint).

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
