# Images

CiviKitchen publishes development and demo image flavors, all to
`ghcr.io/civico-de/civikitchen`. The first-boot settings each flavor
understands are in the [configuration reference](configuration.md).

## Standalone (dev)

This image is CiviCRM Standalone, installed from the release tarball onto a
php:apache base, with the development toolchain added. The base mirrors what
the official civicrm/civicrm image provided, without depending on its Docker
Hub publishing.

The toolchain:

- cv, civix and composer.
- Node 24 (current LTS), with a pinned npm 12 installed over the distro
  package's. npm 12 skips a dependency's install scripts unless the project
  approved them. The images turn them back on globally, because CiviCRM core,
  civicrm-buildkit and most frontend toolchains still need postinstall to do
  real work. The reasoning sits at `NPM_VERSION` in
  `toolbelt/install-dev-tools.sh`.
- phpunit 9, pinned for CiviCRM compatibility, with pcov always on. Xdebug is
  off until `XDEBUG_MODE` is set
  ([IDE step debugging](extension-development.md#ide-step-debugging)).
- phpstan, psalm, rector, mago, oxlint, oxfmt and
  composer-dependency-analyser, each pinned
  ([Bumping a dev tool](building.md#bumping-a-dev-tool)).
- The ck tools:
    - the CI gates that `ck ci` runs
      ([Extension standards](extension-standards.md#tooling-every-repo-must-have))
    - ckmodernize ([Modernizing](extension-development.md#modernizing))
    - cklifecycle and ckschemadiff for the compatibility jobs
      ([Compatibility](extension-standards.md#compatibility-test-the-range-you-claim))
    - the scheduled ckmutate ([Tests and coverage](extension-standards.md#tests-and-coverage))
    - ckrelease ([Releasing an extension](extension-releases.md))
- On the standalone image only:
    - cktestreset rebuilds the headless test database
      ([Headless tests](extension-development.md#headless-tests))
    - ckcoretest runs core's own suites
      ([CiviCRM core development](core-development.md))
    - ckboot provisions the site inside a GitLab CI job
      ([CI on GitLab](extension-standards.md#ci-on-gitlab))

### Core patches

Some core bugs must not show up in a dev stack, so the image carries fixes for
them until a release contains them. There is one file per bug in
[`docker/standalone/core-patches/`](https://github.com/civico-de/civikitchen/tree/main/docker/standalone/core-patches/),
and each names its upstream issue on an `Upstream:` line. The build applies
them in name order and skips a patch the release already contains. Any other
mismatch fails the build. `/usr/local/share/civikitchen/core-patches.log`
lists what the image applied. A site running the unpatched release still
shows the bug
([ADR-0016](adr/0016-core-bugs-are-patched-into-the-standalone-image.md)).

### Running it

With `CIVICRM_AUTO_INSTALL=1`, CiviCRM installs itself on first container
start. [Extension development](extension-development.md) has the full setup.

```yaml
services:
  app:
    image: ghcr.io/civico-de/civikitchen:standalone
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

Ready to run: [`examples/standalone/`](https://github.com/civico-de/civikitchen/tree/main/examples/standalone/)

## Buildkit flavors (dev)

Use these flavors to test CMS-specific behaviour. `:drupal10`, `:drupal11`,
`:wordpress` and `:joomla` run CiviCRM on a CMS via
[civicrm-buildkit](https://github.com/civicrm/civicrm-buildkit). All four come
from the same Dockerfile in `docker/buildkit/`. Only the default civibuild
site type differs: drupal10-demo, drupal11-demo, wp-demo and joomla-demo. Set
`CIVICRM_SITE_TYPE` to override it. civibuild builds the site on first
container start, in about 60 seconds, against an external MariaDB.

They carry the standalone image's tools except the standalone-only ones. They
also share
[`docker/runtime/provision.sh`](https://github.com/civico-de/civikitchen/blob/main/docker/runtime/provision.sh)
with it, so the settings marked "all" in the
[configuration reference](configuration.md) work the same. The settings marked
"standalone" do not apply. civibuild builds the site and provides the admin
users, the components and an isolated `sitetest_*` test database itself.

```yaml
services:
  app:
    image: ghcr.io/civico-de/civikitchen:drupal10
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

Recreating the app container does not lose your data. The site build lives in
the app container and the databases live on the database volume. When a fresh
app container, after an image update or `docker rm`, finds an existing
civikitchen site in the external database, it refuses to rebuild, because
`civibuild reinstall` would drop the site databases. Opt in with
`CIVIKITCHEN_REINSTALL=1`, or start clean with `docker compose down -v`.

Ready to run:
[`examples/drupal10/`](https://github.com/civico-de/civikitchen/tree/main/examples/drupal10/),
[`examples/drupal11/`](https://github.com/civico-de/civikitchen/tree/main/examples/drupal11/),
[`examples/wordpress/`](https://github.com/civico-de/civikitchen/tree/main/examples/wordpress/),
[`examples/joomla/`](https://github.com/civico-de/civikitchen/tree/main/examples/joomla/).
The compose files show each CMS's extension mount path.

### Drupal 11

civibuild ships no drupal11-demo site type, so CiviKitchen vendors one in
[`docker/buildkit/site-types/drupal11-demo/`](https://github.com/civico-de/civikitchen/tree/main/docker/buildkit/site-types/drupal11-demo/).
It is drupal10-demo's recipe adapted for Drupal 11.4. Content types now come
from core recipes, and Navigation replaced Toolbar; the file headers have the
details. `bake.sh` installs it into the buildkit clone, so Drupal 11 gets the
same demo data, admin users and profile support as the other flavors.

### Joomla

The image uses civicrm-buildkit's joomla-demo site type. Buildkit's
joomla5-empty template is a CMS-only site, so CiviKitchen does not publish it
as a CiviCRM flavor.

civibuild's joomla-demo install is deliberately incomplete. It leaves the
registration of CiviCRM's Joomla component as a `#fixme`. On first boot the
dev image runs `civibuild reinstall` against your external database and then
re-runs the same finish the demo image bakes in
([`joomla-finish.sh`](https://github.com/civico-de/civikitchen/blob/main/docker/buildkit/joomla-finish.sh)).
That finish registers CiviCRM's Joomla component and plugins, enables the
standard component extensions, and installs the ckjoomlaidentity identity
shim. As a result `option=com_civicrm`, which serves the admin UI and the
api_key API, and the profiles behave as on the other flavors.

civibuild registers no `com_civicrm` ACL asset. The build creates one and
grants each role its permissions on it, the same least-privilege model as on
the other CMSs. ckjoomlaidentity loads the matching Joomla identity for
headless requests, so api_key reads and writes both enforce those
permissions.

## Demo images

The demo images are for demos, evaluation and screenshots, not for
development. Each is a single container with an embedded MariaDB and baked
demo data, so there is no compose file and no external database. One
`docker run` gives a working CiviCRM with demo content in a few seconds. The
database lives inside the container, so the data resets on `docker rm`.

There are five demo flavors, all built from the `demo` target in
`docker/buildkit/`:

```bash
# Pick a flavor: standalone-demo (CMS-less), drupal10-demo, drupal11-demo,
# wordpress-demo, joomla-demo
docker run -d -p 80:80 --name civicrm ghcr.io/civico-de/civikitchen:drupal10-demo

# then open http://localhost  —  login: admin / admin
```

The site is baked at `http://localhost`. Either map port 80, or set
`CIVIKITCHEN_SITE_URL` to the URL you actually open, for example
`-p 8080:80 -e CIVIKITCHEN_SITE_URL=http://localhost:8080`. The entrypoint
then rewrites the baked base URL at boot, in the settings files and, on
WordPress, in the siteurl and home options.

On Joomla the build finishes civibuild's joomla-demo install (see
[Joomla](#joomla)), so the demo behaves like the others with one difference.
authx's password and basic-auth flow does not work on Joomla. API access there
uses the api_key credential (`X-Civi-Auth: Bearer …`), not HTTP basic auth.

## Profiles (`CIVIKITCHEN_PROFILE`)

A profile gives a fresh site a realistic setup. At first boot it adds a
curated extension stack, configuration, seed data and API users on top of the
base site. Profiles work on every flavor, demo and dev images alike. On the
`:standalone` dev image the profile needs an admin user to seed as, so combine
it with `CIVICRM_AUTO_INSTALL=1` and `CIVIKITCHEN_DEMO_USER=admin`.

API users are created through each CMS's native API. cv boots CiviCRM and the
host CMS, so the driver calls the Drupal entity API, WordPress users and
roles, Standalone APIv4, or Joomla users and user groups directly, without
drush or wp-cli.

The profiles live in
[`docker/profiles/`](https://github.com/civico-de/civikitchen/tree/main/docker/profiles/),
one directory per profile. Each holds a `profile.json` and the PHP files its
`scripts` list names. The shared driver applies the sections in the order
dependencies, config, seed, scripts:

- `config` covers settings, components, domain name, from-address, mailbox
  domain, groups and membership types. It is written back on every apply
  wherever it differs. `components` only switches components on, so composed
  profiles keep each other's.
- `seed` contacts and contributions are created once, matched by their
  anchor. Later edits in the UI survive.
- `scripts` run in list order. A `seeds/` directory is an error, not a
  convention.

At boot the driver works in two phases around the extensions the stack brings
itself. First it fetches and enables the dependencies of every selected
profile. Then the mounted extensions and `CIVIKITCHEN_EXTRA_EXTENSIONS` are
enabled. After that come config, seed, scripts and API users, so a seed record
or script can use the extension under development. `init.d` hooks run last. A
root or profile `apply.sh` replaces the driver and runs once, in the second
phase.

A missing prerequisite fails the apply before anything is written, and the
error lists every problem. Examples are membership types without CiviMember,
an unknown financial type, setting or field, or a group nobody defines. Only
`config.settings` and `config.components` are applied first, because a
component must be on before its records can be checked. The schema package's
[README](https://github.com/civico-de/civikitchen/blob/main/packages/civicrm-profile-schema/README.md)
holds the exact rules.

| Profile | Includes | Extensions | API users |
|---|---|---|---|
| `verein` | `verein-data` | CiviBanking, CiviSEPA, Contract, Twingle, GDPRX, XCM, IdentityTracker, ContactLayout; adds a SEPA creditor + 21 direct-debit mandates | readonly, fundraiser, eventmanager, caseworker, bankimporter |
| `fundraising` | `fundraising-data` | CiviRules, DonRec | readonly, fundraiser |
| `events` | `events-data` | RemoteEvent, EventMessages, RemoteTools, XCM, IdentityTracker | readonly, eventmanager |
| `mailing` | `mailing-data` | Mosaico (+ core FlexMailer) | readonly, mailer |

The data profiles hold the records. They need only CiviCRM core, so they load
without any extension:

| Data profile | Components | Seed data |
|---|---|---|
| `verein-data` | CiviContribute, CiviMember | Musterverein e.V.: membership types (Voll-/Förder-/Ehrenmitglied), 24 members with addresses + fee history |
| `fundraising-data` | CiviContribute, CiviCampaign, CiviPledge | 2 campaigns, 18 donors with varied giving history, 6 recurring donors, 4 pledges with installment schedules |
| `events-data` | CiviEvent | 6 past + upcoming events, 18 contacts with participant records in varied statuses |
| `mailing-data` | CiviMail | 3 segmented mailing lists, 30 subscribers, a draft newsletter |

A profile's `include` list applies before the profile itself, depth-first,
with each profile applied once. A cycle fails the boot.
`CIVIKITCHEN_PROFILE=verein` gives the whole Verein world, and
`CIVIKITCHEN_PROFILE=verein-data` only its records.

RemoteEvent is skipped on Standalone, and ContactLayout on Standalone and
Joomla. A dependency declares this in `profile.json` with
`"skipUf": ["Standalone", ...]` plus `"skipUfReason"`. It is then neither
fetched nor enabled on that framework.

```bash
# German Verein showcase: Drupal 10 + DACH extension stack + seed data + API users
docker run -d -p 80:80 --name civicrm \
    -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/civico-de/civikitchen:drupal10-demo
```

Profiles combine. A comma-separated list is applied left to right, so
`CIVIKITCHEN_PROFILE=verein,mailing` gives the Verein world plus mailing lists
and Mosaico. Each layer converges instead of colliding:

- extensions the site already has are skipped
- config converges
- seed records exist once per anchor
- a username or role declared by several profiles gets the union of the
  permissions. The credentials file has one line per username, and the last
  profile's random password and api_key are the valid pair.

Profiles with API users must agree on one global AuthX header policy. A
conflicting combination fails before the first profile changes the site.

The profile applies once, on first boot. It clones the extensions from
GitHub, so it needs network access and takes a few minutes. Follow it with
`docker logs -f civicrm`; the container turns healthy when it is done. The
generated API-user credentials stay out of the logs. They are kept in a
mode-0600 file in the container, which you read by default with
`docker exec civicrm cat /home/buildkit/api-credentials.txt`.
`CK_CREDENTIALS_FILE` and `CK_CREDENTIALS_OUTPUT` move or redirect them
([configuration](configuration.md)).

Profiles can also live outside the image. Mount a root read-only, point
`CIVIKITCHEN_PROFILE_PATH` at it, and select a profile by its directory name:

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

`ck profile validate ./profiles/my-ngo` checks the data shape with the same
dependency-free schema the boot uses. It does not sandbox an optional root or
profile `apply.sh` or the profile's scripts. External profile roots are fully
trusted executable code with app and CiviCRM admin access, which is why the
separate trust flag is mandatory. Before the first profile changes the site,
the boot validates and trust-checks the complete selected list. Duplicate
names and conflicting AuthX policies are errors.

## Toolbelt layout

Every image carries this repository's `toolbelt/`, `packages/` and
`docker/profiles/` under `/opt/civikitchen/`, laid out as in a checkout. The
`/usr/local/bin/ck*` commands are links into `/opt/civikitchen/toolbelt/bin/`.
A repository config can name files there, for example
`/opt/civikitchen/toolbelt/phpstan-config/civicrm-disallowed.neon` in
`phpstan.neon.dist`.

The dev images run as the web user, `www-data` on standalone and `buildkit` on
the buildkit flavors. `docker exec` uses the same user, so cv and the ck tools
leave caches the web server can write. Docker lets that user bind port 80,
because it sets `net.ipv4.ip_unprivileged_port_start=0` since Docker 20.10.
The demo images run as root, because they start an embedded MariaDB.

No directory on the system PATH is writable for the web user. A `*.sh` init
hook that fetches a tool installs it into the user's `~/.local/bin`, which is
`/var/www/.local/bin` on standalone and `/home/buildkit/.local/bin` on the
buildkit flavors. That directory is on the PATH for the hooks, `docker exec`
and `ck ci`.

The v1 paths `/opt/civikitchen-<tool>` and the v1 profile directory
`/usr/local/share/civikitchen/profiles` are deprecated links to the same
directories, and they go away in v2. A profile a stack mounts below the old
directory still lands in `/opt/civikitchen/docker/profiles`. `ckconform` warns
about every file that still names an old path and gives the replacement.

## Tags and versions

All images rebuild daily against the current CiviCRM stable release, which is
resolved from [latest.civicrm.org](https://latest.civicrm.org/stable.php) at
build time. `:standalone-master` and `:standalone-beta` are the exception.
They follow core's branches, and a change to the image inputs reaches them
with their next daily build.

A push that touches the image inputs, `docker/**` and `toolbelt/**` among
them, rebuilds only the standalone flavors. The CMS and demo flavors follow
with the next daily run or a manual dispatch.

The pipeline tests first and promotes after
([ADR-0014](adr/0014-images-are-tested-then-promoted.md)). A release that
breaks the build or the boot tests never reaches the stable tags. They keep
serving the last good image until the breakage is fixed. The scheduled run
builds with the layer cache disabled, so `apt-get upgrade` in the Dockerfiles
really re-runs and Debian security updates reach the images within a day of
their release.

The moving tags track current CiviCRM. Use them for local development and for
the canary repo:

| Tag | What it points at |
|-----|-------------------|
| `:standalone`, `:standalone-latest` | The most recent build of CiviCRM's `latest` release. |
| `:standalone-<minor>` | The latest patch of that minor, for example `:standalone-6.16`. The current stable minor always has one. Older minors keep being rebuilt, with the newest patch and current tooling, while they are listed in `CK_STANDALONE_EXTRA_MINORS` in `toolbelt/versions.env`, the supported-versions list. A minor dropped from the list freezes at its last built patch. To rebuild another minor once, run *Build Dev Images* by `workflow_dispatch` with `extra_standalone_minors`. |
| `:drupal10`, `:drupal11`, `:wordpress`, `:joomla`, `:*-demo` | Bake the current stable at image build time. To see what a pulled image contains without booting it, run `docker inspect <image> --format '{{ index .Config.Labels "org.opencontainers.image.version" }}'`. |
| `:<flavor>-php<version>` | The buildkit dev flavors also publish a tag with the PHP version, for example `:drupal10-php8.3`. It is the same image, explicit about its PHP. |
| `:standalone-master` | The head of civicrm-core `master`, for example 6.21.alpha1, built daily from git by the *Build Git Images* workflow. |
| `:standalone-beta` | The head of core's newest X.Y branch, the next release (for example 6.20.beta1), built daily from git by *Build Git Images*. Between a release and core cutting the next branch, usually within the hour, this is still the branch of the minor that just shipped. |

The two git-built tags pass only the smoke tests: the bundled dev tools, and a
first boot with an external database, a mounted extension and a core locale.
They skip the end-to-end, database, scenario and scaffold gates of the other
standalone tags, and they get no vulnerability scan. They are not part of the
release contract. No release tag points at them, and a release does not wait
for *Build Git Images*.

Each of the two tags is built, tested and promoted on its own, so a failing
`master` build leaves `:standalone-beta` moving, and the other way round. Only
a failure of the branch lookup, which they share, stops both. A failed daily
run opens its own issue.

`/usr/local/share/civikitchen/civicrm-source.log` in the image names the
civicrm-core and civicrm-packages commits it was built from. Each architecture
fetches the branch on its own, so the amd64 and arm64 images can differ by the
commits that landed between the two builds.

The release tags mark a deliberate release of the whole CiviKitchen contract:
workflow, template, ck tools and images together. They are `:v1`, `:v1.2.3`
and their per-flavor spellings such as `:standalone-v1` or
`:drupal10-v1.2.3`. Extension repos pin these. They move only when a release
is cut; see [Releases](releases.md#what-a-version-names).

To keep a minor longer than that, or to run a version the published images do
not offer, build your own image. See
[Custom or older CiviCRM versions](#custom-or-older-civicrm-versions).

### Database compatibility

The standalone candidate must pass against `mariadb:10.11`, the recommended
`mariadb:11.4`, `mariadb:12.2` and `mysql:8.0` before it is promoted. Each leg
does a real install, mounted-extension provisioning, locale rendering, and a
boot through the isolated `CIVICRM_UF=UnitTests` scratch database. The gate
does more than check that a UnitTests process boots. It asserts that
`SELECT DATABASE()` returns `civicrm_test`, and it writes a canary that must
stay absent from `civicrm`.

The examples keep MariaDB 10.11 as their conservative default. Changing it
still requires passing this matrix, and automated database-image PRs do not
count as compatibility proof.

## Custom or older CiviCRM versions

To run an older or arbitrary version, for example to mirror a production
server, build the image yourself with
`--build-arg CIVICRM_VERSION=<tag/branch>`. Which flavor reaches which
version:

- Standalone (`docker/standalone/`) installs the
  `civicrm-<version>-standalone.tar.gz` release tarball. It reaches any
  release that download.civicrm.org has a tarball for; Standalone itself
  exists from about 5.69. The image's core patches (see
  [Standalone (dev)](#standalone-dev)) must apply to that release, or the
  release must already contain them, otherwise the build fails. The version
  must be exact, such as 6.15.1 and not 6.15, because it names a tarball.
  With `--build-arg CIVICRM_SOURCE=git`, `CIVICRM_VERSION` is instead a
  civicrm-core branch or tag such as `master` or `6.19`, built from git into
  the same layout. See
  [Standalone on an unreleased branch](core-development.md#standalone-on-an-unreleased-branch).
- Buildkit (`docker/buildkit/`) bakes the site with
  `civibuild create --civi-ver <version>`, which fetches any civicrm tag or
  branch. The Drupal 10 site type covers fairly recent older versions such as
  CiviCRM 5.78.x. For versions older than about 5.47, switch
  `DEFAULT_SITE_TYPE` to a Drupal 9 or 7 civibuild site type. A Drupal target
  also mirrors a real Drupal server's CMS, not only its CiviCRM version.

In short, use Standalone for current releases, and buildkit with Drupal for
older ones or to mirror a Drupal site.

```bash
docker build -f docker/buildkit/Dockerfile \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION=5.78.2 \
    --build-arg PHP_VERSION=8.1 \
    -t civikitchen:drupal10-5.78.2 .
```

Compose can also build the image on demand, with no prebuilt image needed.
The ready-to-run
[`examples/custom-version/`](https://github.com/civico-de/civikitchen/tree/main/examples/custom-version/)
is parameterized by `CIVICRM_VERSION` and `PHP_VERSION`. More build arguments
are in [Building locally](building.md).
