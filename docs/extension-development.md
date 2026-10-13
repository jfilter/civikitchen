# Extension development

The standalone image is built around the loop most extension authors run: edit
the code, reload the page, run phpunit. Start from the example in
[`examples/standalone/`](https://github.com/civico-de/civikitchen/tree/main/examples/standalone/).

## The dev stack

Mount your extension into the container:

```yaml
volumes:
  - /path/to/your/extension:/var/www/html/ext/myextension
```

Then run `docker compose up -d`. With `CIVICRM_AUTO_INSTALL: "1"`, which the
example sets, the first start runs `cv core:install` against the linked
database and then enables every extension mounted under ext/, resolving its `<requires>`
first. Later starts skip the install because the settings file and the database
persist. A third-party dependency needs no setup. Where the entrypoint fetches
it from is described under `CIVIKITCHEN_ENABLE_EXTENSIONS` in
[Configuration](configuration.md).

If your extension uses composer, install its dependencies as your own user, so
that `vendor/` in your checkout belongs to you. Then enable the extension and
run its tests:

```bash
docker compose run --rm --no-deps -u "$(id -u):$(id -g)" -e HOME=/tmp \
  --entrypoint composer app install -d /var/www/html/ext/myextension
docker compose exec app cv ext:enable myextension
docker compose exec app bash -c "cd /var/www/html/ext/myextension && phpunit"
```

`ckup` runs the composer install for every mounted extension before it starts
the stack.

`docker compose down` without `-v` removes the container but keeps the
database volume. On the next boot the entrypoint finds the existing tables and
installs with `cv core:install -K` to keep them, so the boot is fast and the
data survives. A recreated container re-applies the profile, locales and
extensions, which restores its file system. It runs the `init.d` hooks only if
the database lacks the setting `civikitchen_provisioned`, which their first run
wrote, so a kept database is not seeded twice. `docker compose down -v` drops
the volume as well, and the next start is a fresh install.

Several stacks on one machine collide on host ports. The template's compose
file reads its ports from a gitignored `.docker/.env` with the keys
`CK_HTTP_PORT`, `CK_MAILDEV_PORT`, `CK_SMTP_PORT` and `CK_PMA_PORT`, and
derives `CIVIKITCHEN_SITE_URL` from the HTTP port. On its first run,
`/path/to/civikitchen/scaffold/ckup` writes that file with free ports. It then
runs `docker compose up -d` and passes any further arguments through. Without
the file the defaults 8080, 1080, 1025 and 8081 apply.

In a repository with the template's `.docker/` directory,
`/path/to/civikitchen/scaffold/ckx` runs a command in that stack as www-data,
in the extension's directory. The subdirectory you are in maps to the same one
in the container. When there is no terminal it adds `-T`. For an extension
below the git root it also sets the tool path described under
[Running the CI gates locally](#running-the-ci-gates-locally). Without a
command it opens a shell.

`CKX_USER=root` switches the user, for a command that has to write where the
bind mount does not let www-data write. Keep it to such commands. A root run
that boots CiviCRM, such as `cv` or the gates, leaves caches the web server
cannot write until the container is recreated. The examples below use `ckx`.
Outside the template layout, write
`docker compose exec -w /var/www/html/ext/<file> app …` instead.

Every first-boot setting, from SMTP to extra extensions and demo users, is in
the [configuration reference](configuration.md).

## Headless tests

Headless tests extend `CiviUnitTestCase` or implement `HeadlessInterface`. Run
them with `CIVICRM_UF=UnitTests`:

```bash
docker compose exec -e CIVICRM_UF=UnitTests app \
  bash -c "cd /var/www/html/ext/myextension && phpunit"
```

They run against a separate scratch database named after the main one with a
`_test` suffix, for example `civicrm_test`, and not against your dev site. The
image creates that database on first install. Two more things happen on every
boot. The image writes `TEST_DB_DSN` to `~/.cv.json` for both root and
www-data, keeping an existing file, so the test framework finds the database.
It also patches the boot stub `civicrm.standalone.php`, so that a
`CIVICRM_UF=UnitTests` boot defines the test DSN before core builds its own
from the `CIVICRM_DB_*` variables.

Without the stub patch, headless phpunit silently runs against the dev
database and wipes it. Without `TEST_DB_DSN`, a headless boot stops with
`$GLOBALS[_CV][TEST_DB_DSN] is not set`. Both live in the container rather than
in `private/`, so a container recreated over a kept `private/` gets them back.
If you manage `TEST_DB_DSN` yourself, opt out with `CIVIKITCHEN_TEST_DB=0`.
**Never point it at the dev database: a headless phpunit run rebuilds that
schema.**

Headless boots also get `CIVICRM_DB_CACHE_CLASS=ArrayCache`. On `:standalone`
the patched boot stub sets it, and on the buildkit images
`/etc/civicrm.settings.d/pre.d/000-civikitchen-test-db-cache.php` does. This
keeps the cache in the test database even when `civicrm.settings.php` selects
FileCache, Redis or Memcache, because the settings template only defines that
constant if it is not set yet. CiviCRM keys those caches by CiviCRM version
alone. Without the override, the test database would read the dev site's
entries and keep its own across a `Civi\Test` schema rebuild. A boot would then
query tables the rebuilt schema does not have, failing with
`Table 'civicrm_test.civicrm_search_display' doesn't exist`.

**Database grants.** During schema init the harness runs
`SET global innodb_flush_log_at_trx_commit` (in `Civi\Test\Schema::setStrict`).
On MariaDB 10.11 no privilege narrower than SUPER allows that statement, so
without it every run dies with error 1227. The app user has rights on its own
database only, so first-boot provisioning does the privileged part as the
database root user. It creates the test database, grants the app user ALL on
it plus SUPER, and sets `log_bin_trust_function_creators`, without which a
binlog-enabled server refuses the harness's triggers and functions. Then it
copies the installed schema over. Your compose file needs no grant script.

The example and template stacks pass `--log-bin-trust-function-creators=1` to
the database service so the setting survives a database restart. Provisioning
reaches root with `CIVICRM_DB_ROOT_PASSWORD`. Its default is `root`, which
matches `MYSQL_ROOT_PASSWORD` in the example stacks. If your database service
uses a different root password, pass it to the app service. Otherwise the
first boot fails with the server's error and the site stays unhealthy.

**Resetting the scratch DB — `cktestreset`.** A suite can leave the test
database inconsistent. `installMe()` in `Civi\Test` does not resolve
`<requires>`. The image entrypoint does that for the dev site, but the test
framework is its own installer. A test that installs only its own extension
therefore leaves it enabled with its dependencies missing. Every later
`CIVICRM_UF=UnitTests` boot then dies during the class scan with
`Interface "..." not found`. It does not heal itself, because the stale class
index also survives in the test database's SQL cache and in the per-DSN
`CachedCiviContainer.*` and `CachedExtLoader.*` files. Running
`docker compose exec app cktestreset` resets all of these at once. It drops
the test database, reseeds it from the main one and clears the cached
containers and loaders.

It is also the fix when a suite does not see a change to what `install()`
seeds. `Civi\Test` rebuilds the test database only when its step signature
changes. That signature is the list of steps and extension keys it stores in
`civitest_revs`. New seed data or a bumped `info.xml` version leaves it
unchanged, and the tests run against the previous install.

The lasting fix belongs in the extension. Build the environment with
`ck_headless()` from the managed bootstrap instead of `\Civi\Test::headless()`.
It queues one install step per `<requires>` entry in `info.xml`, then the
extension itself. `setUpHeadless()` becomes `return ck_headless()->apply();`
and the dependency list lives in `info.xml` only. It goes one level deep. It
reads the file instead of asking `CRM_Extension_Manager`, because touching the
extension system before `Civi\Test` rebuilds the headless schema leaves caches
the rebuilt site no longer matches. A dependency the site does not have fails
the install loudly. Further steps chain as before, for example
`ck_headless()->sqlFile(...)->apply()`.

## Dev profile and provisioning hooks

Data a test setup needs beyond enabling the extension belongs in the
repository's dev profile, `.docker/profiles/dev/profile.json`. It holds
dependencies, settings, seed records, scripts and API users, in the
[profile format](images.md#profiles-civikitchen_profile). Both template
compose stacks and `ckboot` select it. Its dependencies are enabled before the
extension and its content runs after it, so a seed script can use the
extension's own entities.

What the stack itself needs, such as a renderer binary or system packages, can
run as a first-boot hook. Mount scripts into `/civikitchen-init.d/`. After a
fresh auto-install they run after the profile, in lexical order. Files ending
in `.sh` run with bash and files ending in `.php` with `cv scr`, both as the
image's web user. That user is www-data on standalone and buildkit on the CMS
images.

A hook that needs root, like `CIVIKITCHEN_EXTRA_PACKAGES`, needs `user: root`
on the service. `docker compose exec` then defaults to root as well, so pass
`-u www-data` to `cv` and the `ck*` tools, or a root run leaves caches the web
server cannot write. A failing hook aborts the boot, so broken provisioning is
loud.

```yaml
services:
  app:
    user: root  # apt for CIVIKITCHEN_EXTRA_PACKAGES
    environment:
      CIVIKITCHEN_EXTRA_PACKAGES: "libreoffice-writer,unoconv"
    volumes:
      - ../../de.systopia.civioffice:/var/www/html/ext/de.systopia.civioffice
      - ./init.d:/civikitchen-init.d:ro
```

## No calls to civicrm.org

CiviCRM's admin status check contacts civicrm.org. The version check posts to
latest.civicrm.org, and the extension check fetches the civicrm.org/extdir
feed. The check runs on the first admin page of a fresh site and after every
`cv flush`. On a runner without outbound network the first call waits for
PHP's full socket timeout of 60 s and the second for at least 10 s. That is
long enough to time out a Playwright test.

First-boot provisioning therefore turns both off on every flavor, before
profiles and init hooks run. It sets the `version_check` scheduled job
inactive. It sets `ext_repo_url` to boolean `false`, which core reads as "do
not check extensions". An empty string does not have this effect.

The status page then shows two notices, *Update Check Disabled* and
*Extensions check disabled*. `cv ext:download` passes its own feed URL and
still works. An init hook can switch either check back on, for example with
`cv api4 Job.update +w api_action=version_check +v is_active=1`. Both settings
are applied once, with the rest of first-boot provisioning. A stack
provisioned without them gets them when recreated with `docker compose down -v`.

## Creating an extension

Create a module with the host-side wrapper. `civix generate:module` needs a
CiviCRM database, so the wrapper boots a temporary one. It generates the civix
scaffold, applies civikitchen's versioned tooling layer, and only then moves
the finished directory into place. Every step runs in the image, so the host
needs only bash and Docker.

```bash
/path/to/civikitchen/scaffold/ckcreate example_ext \
  --author "Example Maintainer" \
  --email dev@example.org \
  --copyright "Example Organisation" \
  --license Proprietary
```

The default target is `./<key>`. Keys are plain lowercase identifiers on
purpose, so that CiviCRM's extension key and file name stay identical. Values
you repeat can go into `~/.config/civikitchen/ckcreate.conf` as
`CKCREATE_AUTHOR`, `CKCREATE_EMAIL`, `CKCREATE_COPYRIGHT`, `CKCREATE_LICENSE`
and `CKCREATE_COMPATIBILITY`. `CKCREATE_PHP_COMPATIBILITY` raises the
template's PHP floor and keeps `info.xml` and Composer aligned.
`ckcreate --help` lists the flags and defaults.

The result is a git repository with nothing committed. The civix files have
been through `cklint --fix`, and there is one headless test,
`tests/phpunit/Civi/<Namespace>/InstallTest.php`, which checks that the
extension installs. Every gate of `ck ci` passes from the start.

`ckcreate` is atomic. A missing mandatory value, a civix error or a template
error leaves no partial target directory behind.

An existing civix module that only lacks the tooling layer gets it from
`ckinit`. Run it from the civikitchen checkout at the version the repository
pins, as `/path/to/civikitchen/scaffold/ckinit /path/to/org.example.myext`.
It runs the checkout's `ckinit.php` in the `:v1` image, which `CKINIT_IMAGE`
overrides. The argument is the extension directory, not the extension key.
`ckinit` reads the `<file>` value from that directory's `info.xml`, renders
`scaffold/template/extension/`, and refuses to overwrite existing files. Use
`--force` only after reviewing the conflicts.

### Managed and seeded template files

The template stays an interface after day one
([ADR-0013](adr/0013-managed-and-seeded-template-files.md)). Its files fall
into two classes. Managed files are meant to be byte-identical in every
repository: the thin CI caller, the test bootstraps and the CI compose stack.
Seeded files become the repository's own after the first copy: `composer.json`,
`phpstan.neon.dist`, the dev compose file and `.gitignore`. Two more modes of
`ckinit` work with that split:

```bash
/path/to/civikitchen/scaffold/ckinit --check  /path/to/org.example.myext   # report drift, exit 1 on any
/path/to/civikitchen/scaffold/ckinit --update /path/to/org.example.myext   # rewrite managed files, create missing ones
```

`--update` never touches an existing seeded file. Review its output with
`git diff` like any other change. The shared `extension-ci.yml` workflow runs
`--check` on every push, against the template of the ref the caller pins
([Releases](releases.md#what-a-consumer-pins)). A template improvement
therefore shows up in each repository as a red CI run that one `--update`
fixes.

The CI caller and the CI compose file contain managed blocks between
`# BEGIN CIVIKITCHEN MANAGED <name>` and `# END CIVIKITCHEN MANAGED <name>`.
Only those blocks are compared and refreshed. What a repository writes outside
them is its own and survives `ckinit --update`. Examples are workflow inputs
and further jobs after the caller's END marker, or a sibling mount between the
compose file's app and db blocks.

A repository that must deviate inside a block, or in a managed file without
blocks, declares it in its `civikitchen.yaml`. The reason is mandatory, and
only managed files may be listed:

```yaml
policy:
  template_custom:
    paths:
      - tests/phpunit/bootstrap.php
    reason: Drupal settings discovery and its own test DSN
```

Repository-specific test setup belongs in `tests/phpunit/bootstrap.local.php`,
which the managed bootstrap requires when it exists. Do not edit the managed
`tests/phpunit/bootstrap.php` for it.

## Civix workflow

The image ships [civix](https://github.com/totten/civix) for scaffolding. The
common commands:

```bash
ckx civix generate:entity MyEntity       # APIv4-exposed entity + schema
ckx civix generate:test --template headless \
    \\Civi\\Myext\\Test\\MyHeadlessTest       # boilerplate for a headless test
ckx civix upgrade                        # re-run periodically; bumps mixins,
                                         # backports polyfills, refreshes
                                         # generated stubs to current civix
```

Use `generate:entity` rather than writing `schema/*.entityType.php` by hand.
The schema names a DAO class under `class`, and the generator creates the
matching `CRM/<Ns>/DAO/<Entity>.php` stub alongside it. A schema without its
stub looks complete and passes every static check. It then fails at install
time with `Class "…" not found`, after the tables of earlier entities already
exist, so a retry hits `DB Error: already exists`. The `entity-dao-stub` check
in `ckconform` catches this, and also a stub whose `$_tableName` has drifted
from the schema.

Modern extensions configure features in `info.xml` through
[standard mixins](https://docs.civicrm.org/dev/en/latest/framework/mixin/standard/)
instead of bespoke hooks. Examples are `mgd-php@2`, `menu-xml`, `setting-php`,
`entity-types-php@2.0.0`, `smarty@1` and `ang-php`. `civix upgrade` keeps the
mixin block current. `smarty-v2` is a deprecated alias of the
version-independent `smarty@1`. It behaves the same, and only the name
misleads.

`ckcivix` maintains the civix layer. `ckcivix --check` fails when
`<civix><format>` or `*.civix.php` is missing or behind, and
`ckcivix --update` runs `civix upgrade -n`. The shared workflow runs the check
with the other lint gates. Tool versions and format versions are separate
scales. `ckcivix` reads the latest format from the upgrade scripts in the
installed civix phar.

## Static analysis and linting

What each gate enforces, and why, is in
[Extension standards](extension-standards.md). This section covers how to run
them.

PHPStan needs CiviCRM's autoloader to resolve `CRM_*` and `Civi\*` symbols.
The managed
[`phpstanBootstrap.php`](https://github.com/civico-de/civikitchen/blob/main/scaffold/template/extension/phpstanBootstrap.php)
registers core's class loader first. It then registers the civix layout
(`CRM_*`, `Civi\*`, `api_*` and `vendor/`) of two groups of extensions. The
first group is core's own ext packages such as civi_member and civi_contribute,
which ship with core but live outside its classloader path. The second is every
extension that the repository's `info.xml` lists under `<requires>` and that
exists in the ext directory. That directory is `CK_EXT_DIR`, by default
`/var/www/html/ext`, which is where the images mount and download extensions.

`Civi\Api4\Membership` and classes extended from a required extension
therefore resolve without a repository-specific bootstrap or a
`scanDirectories` entry. A required extension that is missing is noted on
stderr. Its classes stay unresolved, and phpstan reports them where the code
touches them. For the repository's own code the bootstrap adds only what has
no file behind it: the `CRM_<Prefix>_DAO_Base` alias that civix DAOs extend,
core's APIv3 helpers in `api/v3/utils.php`, and the `CiviMix\Schema` library
that core loads by pathload. A repository with its own entities can keep
`paths: [.]`. The same `<requires>` list drives the
[extension boundary rule](extension-standards.md#tooling-every-repo-must-have).
Run it with `ckx phpstan analyse`.

`cklint` wraps `php -l` and mago lint. It checks changed PHP files by default
and skips generated code (`*.civix.php`, `DAO/`) and the paths in
`policy.vendored_paths`. It uses civikitchen's bundled baseline,
`toolbelt/mago/mago.toml` plus the rules in `toolbelt/mago-ext`, unless the
extension commits its own `mago.toml`. That file should extend the baseline,
as [Extension standards](extension-standards.md#tooling-every-repo-must-have)
explains.

```bash
ckx cklint
ckx cklint --all
ckx ckconform     # repository conventions, run from the extension root
ckx cktaint       # taint analysis
```

## Modernizing

`ckmodernize` modernizes an extension in two layers. The structure layer uses
civix (`civix upgrade` and `civix convert-entity`) and applies to civix
extensions only. The code layer uses the bundled Rector setup. By default the
tool previews the code changes and lists the civix steps. `--fix` applies
both, since civix has no preview mode. If the extension ships its own
`rector.php`, that config wins. Narrow the scope with `--no-civix` or
`--no-rector`, give explicit paths, or widen it with `--all`.

The opt-in flags `--api` (object style) and `--api=array` also migrate a safe
subset of APIv3 calls to APIv4. Preview and review those. A `get` call is
migrated only when its result is discarded, or when inside a function it is
read only as `$result['values']` or `$result['count']`. Those reads become
`$result->getArrayCopy()` and `$result->countFetched()`, keyed by id like
APIv3 unless `sequential` is 1. The rows are APIv4 rows, so review the code
that reads them:

- Fields and value types follow APIv4. Ids are integers, and the joined
  contact fields of APIv3, such as `email`, are gone.
- APIv4 hides deleted, test and template records (`is_deleted`, `is_test`,
  `is_template`) unless the where clause names the field or the lookup goes
  through a unique key. APIv3 applied only each entity's own defaults. Test
  recurring contributions, deleted activities or a trashed contact found by
  `external_identifier` can therefore appear or disappear.
- Defaults that only APIv3 had, such as `entity_table = civicrm_contact` on
  Note, no longer filter.

```bash
ckx ckmodernize
ckx ckmodernize --fix --php 8.2
```

The default config combines Rector's PHP-version and code-quality sets with
civikitchen rules for CiviCRM-specific traps such as
`CRM_Utils_Array::value()` and `CRM_Core_Error::fatal()`.

When a release retires a tool, `ckx ckmigrate` moves the repository off it in
place. Review the result with `git diff`. For phpcs it turns a `phpcs:ignore`
of a sniff that became a mago rule into `// @mago-expect lint:<rule>` and keeps
the reason. It drops every other `phpcs:` comment and deletes
`phpcs.xml(.dist)`. It exits 1 and lists what needs a person to decide: a
setting the deleted config made for a sniff that became a mago rule, a comment
it cannot rewrite (a docblock, or a `phpcs:disable` of a ported sniff), and a
`template_custom` entry. A pragma covers one finding, where `phpcs:ignore`
covered the whole line, so run `cklint` afterwards.

## CI

CI runs the same loop unattended. It boots the stack, enables the extension
and runs phpunit headless. An extension created by `ckcreate` or `ckinit`
calls the shared `extension-ci.yml`. For a repository without the template,
[`examples/ci/`](https://github.com/civico-de/civikitchen/tree/main/examples/ci/)
holds a GitHub Actions setup to copy, with a workflow and a minimal compose
stack.

### Running the CI gates locally

`ck ci` runs every gate that the shared workflow's ci job runs inside the
container, in the same order and with the same arguments, and ends with a
summary table. The workflow calls the same command, so the two cannot drift
apart:

```bash
ckx ck ci
ckx ck ci --only cklint,ckfmt
ckx ck ci --skip ckcoverage
```

Every gate runs even when an earlier one failed. The exit code is 1 when any
gate failed, and 2 for a usage error such as an unknown gate name in `--only`
or `--skip`. `ck ci --help` lists the gates.

Two gates are opt-in, as in the workflow. `phpstan-tests` runs when
`phpstan-tests.neon(.dist)` exists. `phpunit-extra` runs `phpunit -c FILE` for
`--extra-phpunit-config FILE`, which matches the workflow's
`extra_phpunit_config` input. `ckanalyze` is a shadow gate on trial to replace
phpstan. Its findings show as `shadow` in the summary and never fail the run,
but a crash of the tool does. Every finding counts, notes included. A
repository's own `mago.toml` takes the baseline's place, so it loads the
civikitchen plugins only when it `extends` the baseline. Its `[source]` lists
add to the scope `ckanalyze` sets rather than replacing it.

The gates that read git run in `CK_TOOL_PATH`. These are `cklint`,
`ckconform`, `ckcivix`, `ckfmt`, `ckanalyze`, `ckcompat`, `cktaint` and
`ckeslint`. Everything that boots CiviCRM runs in `CK_EXT_PATH`. Both default
to the current directory. For an extension below the repository root, `ckx`
sets `CK_TOOL_PATH=/civikitchen-repo/<directory>` (see
[Several extensions in one repository](#several-extensions-in-one-repository)).

To check against the
[organisation-wide defaults](extension-standards.md#repository-policy-civikitchenyaml)
locally, mount that `civikitchen.yaml` into the container and add
`-e CK_DEFAULT_CONFIG=<its path in the container>`.

Some steps of the job run on the host and stay in the workflow: the template
drift check, the lockfile and secret scans, the JS unit tests, and starting
the stack.

Some boots need something the runner has to fetch first. One case is an
uncommitted `vendor/` behind a private composer package. Another is private
sibling extensions mounted beside this one, the CI counterpart of the sibling
mounts in the dev compose file described below. The shared workflow handles
both with two opt-in inputs and a `secrets:` block, described in
[extension-standards.md](extension-standards.md#private-dependencies).

## Several extensions in one repository

A repository can hold several extensions that ship together. Its root has no
`info.xml`. Each extension sits in a direct subdirectory with its own
`info.xml`, `civikitchen.yaml`, `composer.json` and test suite. Extensions
nested deeper are not covered, and neither is a repository that is an
extension at its root and holds more below it. The decisions behind the layout
are in [ADR-0005](adr/0005-several-extensions-in-one-repository.md),
[ADR-0006](adr/0006-lockstep-releases-in-a-multi-extension-repository.md) and
[ADR-0007](adr/0007-the-repository-at-a-second-neutral-path.md). How the
checks treat this layout is in
[Extension standards](extension-standards.md#several-extensions-in-one-repository).

Run `ckinit` at the root. There it manages `.gitattributes`, `renovate.json`,
`.github/workflows/ci.yml` and `.github/workflows/release.yml`. Then it runs
the usual per-extension pass on every direct subdirectory that has an
`info.xml`.

Run on an extension below the root, `ckinit` skips the files that GitHub and
Renovate read only at the root, which are the two workflows and
`renovate.json`. The extension's CI compose file gets a second managed mount
of the repository root at `/civikitchen-repo`, where the git-reading gates of
`ck ci` run. Everything that boots CiviCRM stays at `/var/www/html/ext/<file>`,
named after the `<file>` in `info.xml`. The dev compose file is seeded, not
managed, so an existing repository adds that mount by hand. The template drift
check in each extension's CI job covers that extension's directory plus the
repository's own managed root files.

The root workflow has one job per extension. Each job calls `extension-ci.yml`
with the extension's `working_directory`, as described in
[Reusable workflows](reusable-workflows.md):

```yaml
jobs:
  base:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: base
  addon:
    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1
    with:
      working_directory: addon
      playwright: true
```

`ckinit` owns each job's id, its `uses:` line and its `working_directory`.
Inputs added after a job's END marker belong to the repository. `--update`
appends a job for a new extension directory and drops the job of one that is
gone. `--check` fails while the jobs and the directories disagree. Use static
jobs, not a matrix. The workflow checks in `ckconform` pick the job whose
`working_directory` names the extension, and they cannot evaluate
`${{ matrix.* }}`. Every push runs every extension's jobs, each with its own
stack.

**Dependencies inside the repository.** An extension that requires a
neighbour mounts the neighbour's directory itself. It adds a volume line after
the managed block of its CI compose file, which is `compose_file` and defaults
to `.docker/docker-compose.ci.yml`. The mount target is the dependency's key,
not its `<file>` name, because a required extension is looked up by key:

```yaml
# END CIVIKITCHEN MANAGED app
      - ../../base:/var/www/html/ext/org.example.base
```

A mount in the dev compose file alone does not reach CI, which boots only the
CI compose file
([`monorepo-requires-mounted`](extension-standards.md#several-extensions-in-one-repository)).

**Versions move in lockstep.** All extensions carry the same `<version>` and
`<releaseDate>`, enforced by `monorepo-version-lockstep`, so one `vX.Y.Z` tag
describes all of them.

**One tag releases every extension.** The root release caller has one job per
extension, with `stage: build`, that builds, verifies and smoke-tests that
extension's archive. A single `publish` job needs all of them and creates the
one GitHub release for the tag, with every archive attached:

```yaml
jobs:
  base:
    permissions:
      contents: write        # a called workflow can only narrow this
    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      working_directory: base
      stage: build
  addon:
    needs: [base]
    permissions:
      contents: write
    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      working_directory: addon
      stage: build
  publish:
    needs: [addon, base]
    permissions:
      contents: write
    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1
    with:
      stage: publish
```

`ckinit` owns every job's id, `uses:`, `working_directory`, `stage` and
`needs`. Inputs and `secrets:` after a job's END marker belong to the
repository. The same-repository extensions are the directories next to
`working_directory`. A job needs the jobs of those that its extension
requires, because the smoke test installs their archives from the same run
while no pinned release of them exists yet. If one build job fails, nothing is
published.

An extension that declares `release: none` gets no job, and `ckinit` refuses a
releasing extension that requires one of those. When no extension releases,
`--check` reports a leftover `release.yml` and `--update` deletes it, unless
lines outside its managed blocks belong to the repository. `ckinit` never
overwrites a root `release.yml` without managed markers, and it reports a
second workflow that calls `extension-release.yml`.

The `release-workflow` check fails an extension in four cases: its job is
missing, its job runs a stage other than `build`, its job does not need the
build jobs of the same-repository extensions it requires, or no
`stage: publish` job needs it. A job needed through intermediate jobs counts
as needed.

[`examples/monorepo/`](https://github.com/civico-de/civikitchen/tree/main/examples/monorepo/)
is a tree of two extensions, one requiring the other. This repository's CI
runs `extension-ci.yml` and a dry run of `extension-release.yml` against it.

### What adopting it costs a repository

Expect the first run of the real gates to be red. A repository that only
validated its configuration has never been formatted by `ckfmt`, linted by
`cklint`, analysed by PHPStan or tested in CI. Fix that debt per extension, in
its own commits, before switching the root workflow over. Do not waive it
through `ignore_checks`. In the same change, delete the per-extension
`.github/workflows/ci.yml` and `renovate.json` files and align the versions
once.

## UI tests with Playwright

For browser tests of your extension's UI, such as forms, Angular or React
widgets and JS behaviour, start from
[`examples/extension-with-playwright/`](https://github.com/civico-de/civikitchen/tree/main/examples/extension-with-playwright/).
It boots the same standalone stack, runs Playwright on the host against
localhost:8080, and logs in once through a shared session.

```bash
cd examples/extension-with-playwright
docker compose up -d
npm install && npx playwright install chromium
npm run test:e2e
```

The
[example's README](https://github.com/civico-de/civikitchen/blob/main/examples/extension-with-playwright/README.md)
explains how to move the config files into your own extension repository.
[Extension standards](extension-standards.md#frontend-js-dependencies-js-tests-and-browser-tests)
covers the CI job.

## IDE step debugging

Xdebug is installed but stays off until you set `XDEBUG_MODE`. Add it to your
compose file:

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

For VS Code, add a `.vscode/launch.json`. Its path mapping must match your
volume mount:

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

In PhpStorm, enable "Listen for PHP Debug Connections", set the port to 9003,
and map your project to `/var/www/html/ext/myextension`.

With the default `XDEBUG_START_WITH_REQUEST=trigger`, Xdebug only activates
when a request carries `XDEBUG_TRIGGER=1` as a cookie, a GET or POST
parameter, or an environment variable. Regular requests have no overhead. The
[Xdebug Helper](https://xdebug.org/docs/step_debug#start_with_request) browser
extension sends the trigger.
