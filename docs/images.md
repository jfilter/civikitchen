# Images

Development and demo image flavors, all published to
`ghcr.io/jfilter/civikitchen`. For the first-boot knobs each flavor
understands, see the
[configuration reference](configuration.md).

## Standalone (dev)

CiviCRM Standalone, installed from the release tarball onto a `php:apache`
base (the base mirrors what the official `civicrm/civicrm` image provided,
without depending on its Docker Hub publishing), with the development
toolchain added:

- **cv, civix, composer**
- **node + npm**: Node 24 (current LTS) with a pinned npm 12 installed over the
  distro package's. npm 12 does not run a dependency's install scripts unless
  the project approved them; the images turn that back on globally, because
  CiviCRM core, civicrm-buildkit and most frontend toolchains still depend on
  postinstall doing real work. The reasoning sits at `NPM_VERSION` in
  `toolbelt/install-dev-tools.sh`.
- **phpunit 9** (pinned for CiviCRM compatibility), **pcov** (always on) and
  **xdebug** (off until `XDEBUG_MODE` is set, see
  [IDE step debugging](extension-development.md#ide-step-debugging))
- **phpstan, phpcs with civicrm/coder, psalm, rector, mago, oxlint, oxfmt**,
  each pinned ([Bumping a dev tool](building.md#bumping-a-dev-tool))
- **the `ck*` tools**: the CI gates `ck ci` runs
  ([Extension standards](extension-standards.md#tooling-every-repo-must-have)),
  `ckmodernize` ([Modernizing](extension-development.md#modernizing)), the
  compatibility jobs' `cklifecycle` and `ckschemadiff`
  ([Compatibility](extension-standards.md#compatibility-test-the-range-you-claim)),
  the scheduled `ckmutate` ([Tests and coverage](extension-standards.md#tests-and-coverage)),
  and `ckrelease` ([Releasing an extension](extension-releases.md))
- **standalone only**: `cktestreset`, which rebuilds the headless test database
  ([Headless tests](extension-development.md#headless-tests)), and
  `ckcoretest`, which runs core's own suites
  ([CiviCRM core development](#civicrm-core-development))

**Core patches.** The image carries fixes for core bugs a dev stack must not
reproduce, until a release contains them: one file per bug in
[`docker/standalone/core-patches/`](../docker/standalone/core-patches/), each
naming its upstream issue on an `Upstream:` line. The build applies them in name
order, skips a patch the release already contains, and fails on any other
mismatch. `/usr/local/share/civikitchen/core-patches.log` lists what the image
applied. A site running the unpatched release still shows the bug
([ADR-0016](adr/0016-core-bugs-are-patched-into-the-standalone-image.md)).

CiviCRM is auto-installed on first container start when `CIVICRM_AUTO_INSTALL=1`. See [Extension development](extension-development.md) for the full setup.

```yaml
services:
  app:
    image: ghcr.io/jfilter/civikitchen:standalone
    ports: ["8080:80"]
    environment:
      CIVICRM_AUTO_INSTALL: "1"   # DB host/name/user/password default to db/civicrm
    depends_on:
      db: { condition: service_healthy }
    volumes:
      - ../:/var/www/html/ext/myextension   # your extension repo
  db:
    image: mariadb:10.11
    # ... see examples/standalone/docker-compose.yml
```

Ready-to-run: [`examples/standalone/`](../examples/standalone/)

## Buildkit flavors (dev)

`:drupal10`, `:drupal11`, `:wordpress` and `:joomla` run CiviCRM on a CMS via
[civicrm-buildkit](https://github.com/civicrm/civicrm-buildkit). All four are
built from the same Dockerfile (`docker/buildkit/`); only the default civibuild
site type differs (`drupal10-demo`, `drupal11-demo`, `wp-demo`, `joomla-demo`,
overridable with `CIVICRM_SITE_TYPE`). The site is built on first container
start with `civibuild` (~60s), against an external MariaDB.

They carry the standalone image's tools except the two standalone-only ones,
and share [`docker/runtime/provision.sh`](../docker/runtime/provision.sh) with
it, so the first-boot knobs marked *all* in the
[configuration reference](configuration.md) work the same. The standalone
install knobs (*standalone* there) do not apply: civibuild builds the
site and provides the admin users, components and an isolated `sitetest_*` test
database itself. Use these flavors to test CMS-specific behaviour.

```yaml
services:
  app:
    image: ghcr.io/jfilter/civikitchen:drupal10
    ports: ["8080:80"]
    environment:
      CIVICRM_DB_HOST: db
      CIVICRM_DB_ROOT_PASSWORD: root
      CIVIKITCHEN_SITE_URL: http://localhost:8080   # must match the port mapping, or assets 404
    depends_on: [db]
  db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
```

> **Recreated containers don't eat your data.** The site build lives in the app
> container, the databases on the DB volume. When a *fresh* app container (image
> update, `docker rm`) finds an existing civikitchen site in the external DB, it
> refuses to rebuild — `civibuild reinstall` would drop the site databases.
> Opt in explicitly with `CIVIKITCHEN_REINSTALL=1`, or start clean with
> `docker compose down -v`.

Ready-to-run: [`examples/drupal10/`](../examples/drupal10/),
[`examples/drupal11/`](../examples/drupal11/),
[`examples/wordpress/`](../examples/wordpress/),
[`examples/joomla/`](../examples/joomla/). The compose files show each CMS's
extension mount path.

### Drupal 11

civibuild itself ships no `drupal11-demo` site type, so CiviKitchen vendors one:
[`docker/buildkit/site-types/drupal11-demo/`](../docker/buildkit/site-types/drupal11-demo/)
— `drupal10-demo`'s recipe adapted for Drupal 11.4 (content types now come
from core recipes, Navigation replaced Toolbar; details in the file headers).
`bake.sh` installs it into the buildkit clone, so Drupal 11 gets the same demo
data, admin users, and profile support as the other flavors.

### Joomla

The image uses civicrm-buildkit's `joomla-demo` site type. Buildkit's
`joomla5-empty` template is a CMS-only site, so CiviKitchen does not publish it
as a CiviCRM flavor.

civibuild's `joomla-demo` install is deliberately incomplete (it leaves
CiviCRM's Joomla component registration as a `#fixme`). On first boot the dev
image runs `civibuild reinstall` against your external DB and then re-runs the
same finish the demo image bakes in — registering CiviCRM's Joomla
component/plugins, enabling the standard component extensions, and the
`ckjoomlaidentity` identity shim (see
[`joomla-finish.sh`](../docker/buildkit/joomla-finish.sh)). So `option=com_civicrm`
(the admin UI and the api_key API) and the profiles behave the same as on
the other flavors.

civibuild registers no `com_civicrm` ACL asset, so the build creates one and
grants each role its permissions on it, the same least-privilege model as the
other CMSs. `ckjoomlaidentity` loads the matching Joomla identity for headless
requests, so both api_key reads and writes enforce those permissions.

## Demo images

Single-container images with an **embedded MariaDB and baked demo data** — no
compose, no external DB. `docker run` and you have a working CiviCRM with demo
content in a few seconds. For demos, evaluation, and screenshots — **not** for
development (the DB is inside the container; data resets on `docker rm`).

Five demo flavors, all built from the same `docker/buildkit/` `demo` target:

```bash
# Pick a flavor: standalone-demo (CMS-less), drupal10-demo, drupal11-demo,
# wordpress-demo, joomla-demo
docker run -d -p 80:80 --name civicrm ghcr.io/jfilter/civikitchen:drupal10-demo

# then open http://localhost  —  login: admin / admin
```

The site is baked at `http://localhost`, so either map port **80**
(`-p 80:80`) or set `CIVIKITCHEN_SITE_URL` to the URL you actually open —
the entrypoint then rewrites the baked base URL at boot (settings files plus,
on WordPress, the `siteurl`/`home` options), e.g.
`-p 8080:80 -e CIVIKITCHEN_SITE_URL=http://localhost:8080`.

On Joomla the build finishes civibuild's `joomla-demo` install (see
[Joomla](#joomla)), so the demo behaves like the others with **one**
difference: authx's password/basic-auth flow doesn't work on Joomla, so API
access there uses the **api_key** credential (`X-Civi-Auth: Bearer …`), not HTTP
basic auth.

## Profiles (`CIVIKITCHEN_PROFILE`)

A profile layers a curated extension stack + seed data + API users on top of
the base site at **first boot**, on every flavor, demo and dev images alike. On
the `:standalone` dev image the profile needs an admin user to seed as, so
combine it with `CIVICRM_AUTO_INSTALL=1` and `CIVIKITCHEN_DEMO_USER=admin`.

API users are created through each CMS's native API — `cv` boots CiviCRM *and*
the host CMS, so the driver uses the Drupal entity API / WordPress users+roles /
Standalone APIv4 / Joomla users+usergroups directly, with no drush or wp-cli.
The profiles live in [`docker/profiles/`](../docker/profiles/) (one dir per
profile: `profile.json` + `seeds/*.php`, applied by the shared driver):

| Profile | Extensions | Seed data | API users |
|---|---|---|---|
| `verein` | CiviBanking, CiviSEPA, Contract, Twingle, GDPRX, XCM, IdentityTracker, ContactLayout | Musterverein e.V.: membership types (Voll-/Förder-/Ehrenmitglied), 24 members with addresses + fee history, SEPA creditor + 21 direct-debit mandates | readonly, fundraiser, eventmanager, caseworker, bankimporter |
| `fundraising` | CiviRules, DonRec | 2 campaigns, 18 donors with varied giving history, 6 recurring donors, 4 pledges with installment schedules | readonly, fundraiser |
| `events` | RemoteEvent, EventMessages, RemoteTools, XCM, IdentityTracker | 6 past + upcoming events, 18 contacts with participant records in varied statuses | readonly, eventmanager |
| `mailing` | Mosaico (+ core FlexMailer) | 3 segmented mailing lists, 30 subscribers, a draft newsletter | readonly, mailer |

RemoteEvent is skipped on Standalone and ContactLayout on Standalone and
Joomla. A dependency declares that in `profile.json` with
`"skipUf": ["Standalone", ...]` plus `"skipUfReason"`; it is then neither
fetched nor enabled on that framework.

```bash
# German Verein showcase: Drupal 10 + DACH extension stack + seed data + API users
docker run -d -p 80:80 --name civicrm \
    -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/jfilter/civikitchen:drupal10-demo
```

Profiles are **combinable** — a comma-separated list is applied left to right
(`CIVIKITCHEN_PROFILE=verein,mailing` gives the Verein world plus mailing
lists and Mosaico). Each layer converges instead of colliding: extensions the
site already has are skipped, seeds skip when their anchor org exists, and a
username/role declared by several profiles ends up with the union of the
permissions (one line per username in the credentials file; the last
profile's random password and api_key are the valid pair). Profiles with API
users must agree on one global AuthX header policy; a conflicting combination
fails before the first profile changes the site.

The profile applies once, on first boot — it clones the extensions from
GitHub, so it **needs network access and takes a few minutes** (watch
`docker logs -f civicrm`; the container turns healthy when done). The
generated API-user credentials stay out of the logs and are kept in a
mode-`0600` container file, by default
`docker exec civicrm cat /home/buildkit/api-credentials.txt`.
`CK_CREDENTIALS_FILE` and `CK_CREDENTIALS_OUTPUT` move or redirect them
([configuration](configuration.md)).

Profiles can live outside the image. Mount a root read-only, point
`CIVIKITCHEN_PROFILE_PATH` at it, and select its directory name normally:

```yaml
services:
  app:
    volumes:
      - ./profiles:/profiles:ro
    environment:
      CIVIKITCHEN_PROFILE_PATH: /profiles
      CIVIKITCHEN_TRUST_EXTERNAL_PROFILES: "1"
      CIVIKITCHEN_PROFILE: my-ngo
```

`ck profile validate ./profiles/my-ngo` validates the data shape with the same
dependency-free canonical schema used at boot. It does not sandbox the optional
root/profile `apply.sh` or PHP seeds: external profile roots are fully trusted
executable code with app and CiviCRM-admin access, which is why the separate
trust flag is mandatory. Runtime validation and trust checks cover the complete
selected list before the first profile changes the site; duplicate names and
conflicting AuthX policies are errors.

## Toolbelt layout

Every image carries this repository's `toolbelt/`, `packages/` and
`docker/profiles/` under `/opt/civikitchen/`, laid out as in a checkout;
`/usr/local/bin/ck*` are links into `/opt/civikitchen/toolbelt/bin/`. A repo
config names files there, for example
`/opt/civikitchen/toolbelt/phpstan-config/civicrm-disallowed.neon` in
`phpstan.neon.dist`.

The v1 paths `/opt/civikitchen-<tool>` and the v1 profile directory
`/usr/local/share/civikitchen/profiles` are links to the same directories and
are **deprecated**: they go away in v2. A profile a stack mounts below the old
directory still lands in `/opt/civikitchen/docker/profiles`. `ckconform` warns
about every file that still names one, with the replacement.

## Tags & versions

All images rebuild **daily** (and on pushes that touch the image inputs, `docker/**` and `toolbelt/**` among them) against the
current CiviCRM stable release, resolved from
[latest.civicrm.org](https://latest.civicrm.org/stable.php) at build time. The
pipeline is test-then-promote ([ADR-0014](adr/0014-images-are-tested-then-promoted.md)): a release that breaks the build or the boot
tests never reaches the stable tags — they keep serving the last good image
until the breakage is fixed. The cron run builds with the layer cache disabled,
so `apt-get upgrade` in the Dockerfiles actually re-runs and Debian security
updates reach the images within a day of their release.

These **moving** tags track current CiviCRM and are the right choice for local
development and for the canary repo:

| Tag | What it points at |
|-----|-------------------|
| `:standalone`, `:standalone-latest` | The most recent CiviCRM `latest` build. |
| `:standalone-<minor>` | Latest patch of that minor (e.g. `:standalone-6.16`). The current stable minor always gets one; older minors keep being rebuilt (newest patch, current tooling) as long as they are listed in `CK_STANDALONE_EXTRA_MINORS` in `toolbelt/versions.env` — the supported-versions list. A minor dropped from the list freezes at its last built patch; a one-off rebuild of another minor is *Build Dev Images* → `workflow_dispatch` → `extra_standalone_minors`. |
| `:drupal10`, `:drupal11`, `:wordpress`, `:joomla`, `:*-demo` | Bake the current stable at image-build time. Check what a pulled image contains without booting it: `docker inspect <image> --format '{{ index .Config.Labels "org.opencontainers.image.version" }}'`. |
| `:<flavor>-php<version>` | The buildkit dev flavors also publish a PHP-suffixed tag (e.g. `:drupal10-php8.3`) — same image, explicit about the PHP it carries. |

The **release** tags (`:v1`, `:v1.2.3` and their per-flavor spellings such as
`:standalone-v1` or `:drupal10-v1.2.3`) mark a deliberate release of the whole
CiviKitchen contract — workflow, template, `ck*` tools and images together —
and are what extension repos pin. They move only when a release is cut; see
[Releases](releases.md#what-a-version-names).

Need a minor pinned longer than that — or a version the published images don't
offer at all? Build your own: see
[Custom or older CiviCRM versions](#custom-or-older-civicrm-versions).

### Database compatibility

The standalone candidate is promotion-gated against `mariadb:10.11`, the
recommended `mariadb:11.4`, `mariadb:12.2` and `mysql:8.0`. Each leg performs a real install,
mounted-extension provisioning, locale rendering, and a boot through the
isolated `CIVICRM_UF=UnitTests` scratch database. The gate asserts
`SELECT DATABASE()` is `civicrm_test` and writes a canary that must remain
absent from `civicrm`, not merely that a UnitTests process can boot. The
examples keep MariaDB 10.11 as their conservative default; changing it still
requires passing this matrix, and automated database-image PRs are not
compatibility proof.

## Custom or older CiviCRM versions

To run an older or arbitrary version — e.g. to mirror a production server —
build the image yourself with `--build-arg CIVICRM_VERSION=<tag/branch>`. Which
flavor reaches which version:

- **Standalone** (`docker/standalone/`) installs the
  `civicrm-<version>-standalone.tar.gz` release tarball, so it reaches any
  release download.civicrm.org has one for — Standalone itself exists from
  ~5.69 — provided the image's core patches (see
  [Standalone (dev)](#standalone-dev)) apply to it or it already contains
  them; otherwise the build fails. The version must be EXACT (`6.15.1`, not `6.15`): it names a tarball.
  With `--build-arg CIVICRM_SOURCE=git`, `CIVICRM_VERSION` is a civicrm-core
  branch or tag instead (`master`, `6.19`), built from git into the same
  layout — see [Standalone on an unreleased branch](#standalone-on-an-unreleased-branch).
- **Buildkit** (`docker/buildkit/`) bakes the site with
  `civibuild create --civi-ver <version>`, which fetches **any** civicrm
  tag/branch. The Drupal 10 site type covers modern older versions such as
  CiviCRM 5.78.x; for versions older than ~5.47 switch `DEFAULT_SITE_TYPE` to a
  Drupal 9 / 7 civibuild site type. A Drupal target also mirrors a real
  Drupal server's CMS, not just its CiviCRM version.

So: Standalone for current releases; buildkit (Drupal) for older ones, or to
mirror a Drupal site.

```bash
docker build -f docker/buildkit/Dockerfile \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION=5.78.2 \
    --build-arg PHP_VERSION=8.1 \
    -t civikitchen:drupal10-5.78.2 .
```

Or let compose build it on demand (no prebuilt image needed) — ready-to-run:
[`examples/custom-version/`](../examples/custom-version/), parameterized by
`CIVICRM_VERSION` / `PHP_VERSION`. More build arguments:
[Building locally](building.md).

## CiviCRM core development

### Core test suites: `ckcoretest`

`ckcoretest` runs CiviCRM *core* phpunit suites against the installed core
(standalone only). The composer dist export-ignores `**/tests/**`,
`phpunit.xml.dist` and the `sql/test_data*.mysql` seed files; on first use it
fetches exactly those for the installed version (sparse blob-filtered checkout
of the matching tag, cached in the container) — including the per-extension
suites under `ext/*/tests` — then execs `CIVICRM_UF=UnitTests phpunit <args>`
from the core dir (`--ext <name>` runs from a core extension's dir instead). It
refuses to run without a provisioned `TEST_DB_DSN`, and covers the headless PHP
suites (`api`, `CRM`, `Civi`, ext).

`ckcoretest --e2e` runs `@group e2e` tests instead, against the dev site rather
than the test DB: they install the extensions they need into it (`cv
ext:disable` them afterwards) and log in as `ADMIN_USER`, which provisioning
writes to `~/.cv.json` from `CIVIKITCHEN_DEMO_USER`. Browser tests
(`Civi\Test\MinkBase`) also need the compose stack's `browser` service
(`docker compose --profile browser up -d`), a headless Chrome sharing the app's
network; tests that read `DEMO_USER`, a second user without admin rights, still
fail, since the image creates only the admin.

Upgrade tests and the karma/qunit JS tests need a buildkit/civibuild
environment — an accepted gap, since civicrm.org's Jenkins runs the full matrix
on every core PR anyway. This is for the local loop: run the suites your patch
touches, let Jenkins do the rest. To verify a core patch or backport, patch the
file under `/var/www/html/core`, then `ckcoretest tests/phpunit/api/v4/Query`.

`ckeslint --core [dir]` type-checks CiviCRM core's own JavaScript (default
`$CIVICRM_CORE_DIR`, then `/var/www/html/core`): it copies core's first-party
`.js` — no vendored trees, minified bundles or tests — beside a `tsconfig.json`
with `allowJs`/`checkJs` and a declaration file for the globals core's pages
load by script tag, and runs oxlint with `--type-check`, the correctness
category and the APIv4 contract rule. It is an analysis tool for upstream bug
reports, not a gate.

A buildkit image built with `--build-arg KEEP_GIT=1` keeps the git history of
the civibuild site
([Building locally](building.md#keeping-the-civicrm-git-history-keep_git1)).

### Standalone on an unreleased branch

To test against core `master` (or a release branch before its tarball exists),
build Standalone from git:

```bash
make build CIVICRM_SOURCE=git CIVICRM_VERSION=master   # tags civikitchen:standalone-master
```

The build does what core's release script does for the tarball: civicrm-core
and civicrm-packages at the same branch, `composer install --no-dev` with the
bower assets, then core's standalone scaffold. Every build fetches the
branch's current commit; `/usr/local/share/civikitchen/civicrm-source.log` names
the commits the image holds. Unlike the tarball, core keeps its `tests/`
directories and seed SQL, so `ckcoretest` runs without fetching them.

To test a core change, mount the directory it touches from your civicrm-core
checkout over the image's copy, and toggle the change there (`git stash`,
`git stash pop`) for a failing and a passing run:

```yaml
services:
  app:
    image: civikitchen:standalone-master
    volumes:
      - ../civicrm-core/ext/afform:/var/www/html/core/ext/afform
```

Run `cv flush` after toggling; the Angular and asset caches otherwise keep the
old files. A change to composer dependencies or outside the mounted directory
needs a rebuilt image.
