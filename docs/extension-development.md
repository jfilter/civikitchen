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
image creates that database; on every boot it writes `TEST_DB_DSN` to
`~/.cv.json` (for both `root` and `www-data`) so the framework finds it. Without
it, a headless boot stops with `$GLOBALS[_CV][TEST_DB_DSN] is not set`. Opt out
with `CIVIKITCHEN_TEST_DB=0` if you manage `TEST_DB_DSN` yourself — **never point
it at the dev database: a headless `phpunit` run rebuilds that schema.**

Headless boots also get `CIVICRM_DB_CACHE_CLASS=ArrayCache` — from the
patched boot stub on `:standalone`, from
`/etc/civicrm.settings.d/pre.d/000-civikitchen-test-db-cache.php` on the
buildkit images — so they cache in the test database even when
`civicrm.settings.php` selects `FileCache`, `Redis` or `Memcache` (the
settings template guards that define with `if (!defined(...))`). CiviCRM keys
those caches by CiviCRM version only: the test DB would read the dev site's
entries and keep its own across a `Civi\Test` schema rebuild, and a boot then
queries tables the rebuilt schema does not have
(`Table 'civicrm_test.civicrm_search_display' doesn't exist`).

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

It is also the fix when a suite does not see a change to what `install()` seeds.
`Civi\Test` rebuilds `<db>_test` only when its step signature changes, the list
of steps and extension keys it stores in `civitest_revs`. New seed data or a
bumped `info.xml` version leaves the signature as it was, and the tests run
against the previous install.

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

### Running the CI gates locally

`ck ci` runs every gate the shared workflow's `ci` job runs inside the
container, in the same order and with the same arguments, and ends with a
summary table. The workflow calls the same command, so the two runs cannot
drift apart:

```bash
docker compose exec -u www-data -w /var/www/html/ext/myextension app ck ci
docker compose exec -u www-data -w /var/www/html/ext/myextension app ck ci --only cklint,ckfmt
docker compose exec -u www-data -w /var/www/html/ext/myextension app ck ci --skip ckcoverage
```

Every gate runs even when an earlier one failed; the exit code is 1 when any
gate failed and 2 for a usage error, such as an unknown gate name in `--only`
or `--skip`. `ck ci --help` lists the gates. Two of them are opt-in, as in the
workflow: `phpstan-tests` runs when `phpstan-tests.neon(.dist)` exists, and
`phpunit-extra` runs `phpunit -c FILE` for `--extra-phpunit-config FILE` (the
workflow's `extra_phpunit_config` input).

The gates that read git run in `CK_TOOL_PATH`, everything that boots CiviCRM
in `CK_EXT_PATH`. Both default to the current directory. For an extension
below the repository root, pass `-e CK_TOOL_PATH=/civikitchen-repo/<directory>`
(see the next section).

CI also passes the organisation defaults file of the `policy_defaults` input
as `CK_DEFAULT_CONFIG` ([organisation-wide defaults](../toolbelt/ckconform/README.md#organisation-wide-defaults)).
To check against it locally, mount that `civikitchen.yaml` into the container
and add `-e CK_DEFAULT_CONFIG=<its path in the container>`.

The host-side steps of the job stay in the workflow: the template drift
check, the lockfile and secret scans, the JS unit tests, and the stack start
itself.

Boots that need something the runner has to fetch first — an uncommitted
`vendor/` behind a private composer package, or private sibling extensions
mounted beside this one, the CI equivalent of the sibling mounts in the dev
compose file below — are two opt-in inputs and a `secrets:` block on the
shared workflow: [extension-standards.md](extension-standards.md#private-dependencies).

## Several extensions in one repository

A repository can hold several extensions that ship together. Its root carries
no `info.xml`; each extension sits in a direct subdirectory with its own
`info.xml`, `civikitchen.yaml`, `composer.json` and test suite. Extensions
nested deeper, and a repository that is an extension at its root and holds
more below it, are not covered.

Run `ckinit` at the root. There it manages `.gitattributes`, `renovate.json`,
`.github/workflows/ci.yml` and `.github/workflows/release.yml`, then runs the
usual per-extension pass on every direct subdirectory that has an `info.xml`.
Run on an extension below the root, it skips the files GitHub and Renovate read
only at the root (the two workflows, `renovate.json`), and its CI compose file gets a
second managed mount of the repository root at `/civikitchen-repo`.
`cklint`, `ckfmt` and `ckconform` need `.git` and run there; everything that
boots CiviCRM stays at `/var/www/html/ext/<key>`. The dev compose file is
seeded, not managed, so an existing repository adds that mount by hand.

The root workflow has one job per extension, each calling `extension-ci.yml`
with the extension's `working_directory` (see
[Reusable workflows](reusable-workflows.md)):

```yaml
jobs:
  base:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: base
  addon:
    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: addon
      playwright: true
```

`ckinit` owns each job's id, `uses:` line and `working_directory`; inputs
added after a job's END marker are the repository's. `--update` appends a job
for a new extension directory and drops the job of one that is gone, and
`--check` fails while the jobs and the directories disagree. Use static jobs,
not a matrix: the workflow checks in `ckconform` pick the job whose
`working_directory` names the extension and cannot evaluate `${{ matrix.* }}`.
Every push runs every extension's jobs, each with its own stack.

**Dependencies inside the repository.** An extension that `<requires>` a
neighbour mounts the neighbour's directory itself, with a volume line after the
managed block of its compose file. The target is the dependency's **key**, not
its `<file>` name, because that is where a required extension is looked up:

```yaml
# END CIVIKITCHEN MANAGED app
      - ../../base:/var/www/html/ext/org.example.base
```

The line belongs in the CI compose file (`compose_file`, default
`.docker/docker-compose.ci.yml`): the shared CI boots only that file and mounts
no same-repository neighbour itself, so a mount in the dev compose file alone
does not reach CI. The `ckconform` check `monorepo-requires-mounted` fails when
that file does not mount a same-repository dependency into the `app` service at
that path. The order of the volume lines does not matter.

**Versions move in lockstep.** All extensions carry the same `<version>` and
`<releaseDate>`, so one `vX.Y.Z` tag describes all of them; the check
`monorepo-version-lockstep` enforces it.

**One tag releases every extension.** The root release caller has one job per
extension that builds, verifies and smoke-tests that extension's archive
(`stage: build`), and one `publish` job that needs all of them and creates the
single GitHub release for the tag with every archive attached:

```yaml
jobs:
  base:
    permissions:
      contents: write        # a called workflow can only narrow this
    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      working_directory: base
      stage: build
  addon:
    needs: [base]
    permissions:
      contents: write
    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      working_directory: addon
      stage: build
  publish:
    needs: [addon, base]
    permissions:
      contents: write
    uses: jfilter/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      stage: publish
```

`ckinit` owns every job's id, `uses:`, `working_directory`, `stage` and
`needs`; inputs and `secrets:` after a job's END marker are the repository's.
A job needs the jobs of the same-repository extensions its extension
`<requires>`: the smoke test installs their archives from the same run, since
no pinned release of them exists yet. If one build job fails, nothing is
published. An extension that declares `release: none` gets no job; `ckinit`
refuses a releasing extension that requires one of those. When none releases,
`--check` reports a leftover `release.yml` and `--update` deletes it, unless
lines outside its managed blocks belong to the repository. `ckinit` never
overwrites a root `release.yml` without managed markers, and reports a second
workflow calling `extension-release.yml`. The
`release-workflow` check fails an extension whose job is missing, runs another
stage than `build`, does not need the build jobs of the same-repository
extensions it requires, or is not needed by a `stage: publish` job. A job
needed through intermediate jobs counts as needed.

[`examples/monorepo/`](../examples/monorepo/) is a two-extension tree, one
requiring the other, that this repository's CI runs `extension-ci.yml` and a
dry run of `extension-release.yml` against.

### What adopting it costs a repository

Expect the first run of the real gates to be red: a repository that only
validated its configuration has never been formatted by `ckfmt`, linted by
`cklint`, analysed by PHPStan or tested in CI. Fix that debt per extension, in
its own commits, before switching the root workflow over, rather than waiving
it through `ignore_checks`. In the same change, delete the per-extension
`.github/workflows/ci.yml` and `renovate.json` files and align the versions
once.

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

## No calls to civicrm.org

CiviCRM's admin status check contacts civicrm.org: the version check posts
to latest.civicrm.org, and the extension check fetches the civicrm.org/extdir
feed. It runs on the first admin page of a fresh site and after every
`cv flush`. On a runner without outbound network the first call waits
PHP's full socket timeout (60 s) and the second at least 10 s, long enough to
time out a Playwright test.

First-boot provisioning therefore turns both off, on every flavor, before
profiles and init hooks run:

- the `version_check` scheduled job is set inactive;
- `ext_repo_url` is set to boolean `false`, the value core reads as "do not
  check extensions". An empty string does not do this.

The status page then shows two notices, *Update Check Disabled* and
*Extensions check disabled*. `cv ext:download` passes its own feed URL and
still works. An init hook can switch either back on, for example
`cv api4 Job.update +w api_action=version_check +v is_active=1`.
Both settings are applied once, with the rest of first-boot provisioning; a
stack provisioned without them gets them when recreated with
`docker compose down -v`.

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
composer install --no-dev --working-dir=/path/to/civikitchen/packages/civikitchen-scenario-schema
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
composer install --no-dev --working-dir=/path/to/civikitchen/packages/civikitchen-scenario-schema
/path/to/civikitchen/scaffold/ckinit.php /path/to/org.example.myext
```

The argument is the extension directory, not the extension key. `ckinit.php`
reads the extension `<file>` value from that directory's `info.xml`, renders
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
/path/to/civikitchen/scaffold/ckinit.php --check  /path/to/org.example.myext   # report drift, exit 1 on any
/path/to/civikitchen/scaffold/ckinit.php --update /path/to/org.example.myext   # rewrite managed files, create missing ones
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
See [Releases](releases.md) for what a version covers and how one is cut.

The CI caller and the CI compose file carry **managed blocks** —
`# BEGIN CIVIKITCHEN MANAGED <name>` … `# END CIVIKITCHEN MANAGED <name>`.
Only the blocks are compared and refreshed; what a repo writes outside them is
its own and survives `ckinit --update`: workflow inputs and further jobs after
the caller's END marker, a sibling mount between the compose file's `app` and
`db` blocks. A repo that must deviate *inside* a block, or on a managed file
without blocks, declares it in its `civikitchen.yaml` — the reason is mandatory, and
only managed files may be listed:

```yaml
policy:
  template_custom:
    paths:
      - tests/phpunit/bootstrap.php
    reason: Drupal settings discovery and its own test DSN
```

A third-party `<requires>` is not a reason to deviate: the entrypoint reads
`info.xml` and installs missing dependencies before `ext:enable` (from the
registry, or from a Composer-version-constrained, digest-pinned
`policy.extension_sources` entry in
`civikitchen.yaml` for a
release the registry does not serve — see [Configuration](configuration.md)).

Repo-specific test setup belongs in `tests/phpunit/bootstrap.local.php` (the
managed bootstrap requires it when present), not in edits to the managed
`tests/phpunit/bootstrap.php`.

## PHPStan

PHPStan needs to know about CiviCRM's autoloader to resolve `CRM_*` and
`Civi\*` symbols. The managed
[`phpstanBootstrap.php`](../scaffold/template/extension/phpstanBootstrap.php)
registers core's class loader, then the civix layout (`CRM_*`, `Civi\*`,
`api_*`, `vendor/`) of core's own ext packages (`civi_member`,
`civi_contribute`, … — they ship with core but live off its classloader path)
and of every extension the repo's `info.xml` `<requires>` that exists under
the ext dir (`CK_EXT_DIR`, default `/var/www/html/ext` — the mount and
download target of the images). `Civi\Api4\Membership` and a class extended
from a required extension therefore resolve without a repo-specific bootstrap
or `scanDirectories` entry; a required extension that is not present is noted on
stderr and its classes stay unresolved, which phpstan then reports where the
code touches them. The same `<requires>` list tells the fleet-wide phpat
boundary rule which extensions the repo may use directly — a required
extension found beside the repo (`../<key>`, `.civikitchen-siblings/<key>` or
`CK_EXT_DIR/<key>`, matched by its `info.xml` key) is allowed like own code;
one that is not found is reported, with the message naming it. Run:

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

The headless test harness works in the separate `<db>_test` schema and runs
`SET global innodb_flush_log_at_trx_commit` during schema init
(`Civi\Test\Schema::setStrict`). On MariaDB 10.11 no privilege narrower than
`SUPER` permits that statement, so without it every run dies with error 1227.

First-boot provisioning does the privileged part as the database root user: it
creates `<db>_test`, grants the app user `ALL` on that database plus `SUPER`,
sets `log_bin_trust_function_creators` (a binlog-enabled server otherwise
refuses the harness's triggers and functions), and copies the installed schema
over. No grant script in your compose file. The example and template stacks
pass `--log-bin-trust-function-creators=1` to the db service so that setting
survives a database restart.

Root is reached with `CIVICRM_DB_ROOT_PASSWORD` (default `root`, matching
`MYSQL_ROOT_PASSWORD` in the example stacks). If the db service uses a
different root password, pass it to the app service; otherwise first boot
fails with the server's error and the site stays unhealthy. Set
`CIVIKITCHEN_TEST_DB=0` to manage `TEST_DB_DSN` yourself.

## Idempotency

`docker compose down` (without `-v`): drops the container, keeps the DB volume. The entrypoint detects existing tables and runs `cv core:install -K` to keep them — boot stays fast and DB state survives.

`docker compose down -v`: drops everything, including the DB volume. Next `up` is a fresh install.
