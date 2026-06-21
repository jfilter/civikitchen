# Images

Development and demo image flavors, all published to
`ghcr.io/jfilter/civikitchen`. For the first-boot knobs each flavor
understands, see the
[configuration reference](configuration.md).

## Standalone (dev)

Official `civicrm/civicrm` image with dev tools added:
- **composer** — most modern extensions ship vendor deps
- **node + npm** — for extensions with Angular/JS assets
- **pcov** — fast code coverage (always on)
- **xdebug** — step debugging, opt-in via `XDEBUG_MODE` (see [IDE step debugging](extension-development.md#ide-step-debugging))
- **civix** — scaffolding/build tool for extensions
- **phpunit 9** — pinned for CiviCRM compatibility
- **phpstan** — static analysis
- **phpcs + civicrm/coder** — the de-facto CiviCRM style guide (relaxed Drupal CS)
- **cklint + the `CiviKitchen` phpcs standard** — opinionated extension linting: the Drupal base minus the doc-comment sniffs that fight PHPStan array-shape PHPDocs, plus footgun sniffs (bans APIv3 calls and removed/deprecated core helpers like `CRM_Utils_Array::value` and `CRM_Core_Error::fatal|debug_*`; flags bare `ts()` — extensions must use `E::ts()` for their own translation domain; flags legacy managed/menu/settings/entity/angular hook implementations where standard mixins should be used; guards `@required` on externally reachable APIv4 actions). `cklint` lints your uncommitted changes by default (`--all`, `--fix`, explicit paths supported) and always defers to a project's own `phpcs.xml(.dist)`.
- **ckmodernize + rector** — opt-in code modernization for extension repos: previews by default, applies with `--fix`, and includes CiviKitchen rules for the same CiviCRM footguns `cklint` flags.

CiviCRM is auto-installed on first container start when `CIVICRM_AUTO_INSTALL=1`. See [Extension development](extension-development.md) for the full setup.

```yaml
services:
  app:
    image: ghcr.io/jfilter/civikitchen:standalone
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

Ready-to-run: [`examples/standalone/`](../examples/standalone/)

## Drupal 10 (dev)

CiviCRM on Drupal 10 via [civicrm-buildkit](https://github.com/civicrm/civicrm-buildkit). Site is built on first container start using `civibuild` (~60s). Requires an external MariaDB.

```yaml
services:
  app:
    image: ghcr.io/jfilter/civikitchen:drupal10
    ports: ["8080:80"]
    environment:
      CIVICRM_DB_HOST: db
      CIVICRM_DB_ROOT_PASSWORD: root
      CIVIKITCHEN_SITE_URL: http://localhost:8080   # must match the port mapping
    depends_on: [db]
  db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
```

> **`CIVIKITCHEN_SITE_URL` matters.** CiviCRM uses it for all asset paths (JS, CSS, fonts). If the port mapping differs from the URL the user opens, assets 404. Default is `http://localhost` (port 80).

Ready-to-run: [`examples/drupal10/`](../examples/drupal10/)

## Drupal 11 (dev)

CiviCRM on Drupal 11 via buildkit. Same runtime model as Drupal 10, but using
civicrm-buildkit's `drupal11-dev` site type. Requires an external MariaDB and
is meant for extension compatibility checks on current Drupal.

Ready-to-run: [`examples/drupal11/`](../examples/drupal11/)

## WordPress (dev)

CiviCRM on WordPress via buildkit. Same pattern and env vars as Drupal 10. The
`:wordpress`, `:drupal10`, `:drupal11`, and `:joomla` tags are built from the
same Dockerfile (`images/buildkit/`) — only the default civibuild site type
differs (`wp-demo`, `drupal10-demo`, `drupal11-dev`, or `joomla-demo`). All
buildkit dev images carry the same dev tools as the standalone image (composer,
node/npm, phpunit, phpstan, phpcs+coder, cklint, ckmodernize, civix, pcov,
xdebug).

Ready-to-run: [`examples/wordpress/`](../examples/wordpress/)

## Joomla (dev)

CiviCRM on Joomla via buildkit, using civicrm-buildkit's `joomla-demo` site
type. This is the Joomla compatibility target for extension development and
requires an external MariaDB. Buildkit's `joomla5-empty` template is a CMS-only
site, so CiviKitchen does not publish it as a CiviCRM flavor.

Ready-to-run: [`examples/joomla/`](../examples/joomla/)

> **Scope note.** The buildkit images (`:drupal10`, `:drupal11`, `:wordpress`,
> `:joomla`) share
> [`images/lib/provision.sh`](../images/lib/provision.sh) with standalone, so the
> same first-boot knobs work: `CIVIKITCHEN_AUTO_COMPOSER`,
> `CIVIKITCHEN_SMTP_HOST`, `CIVIKITCHEN_EXTRA_EXTENSIONS` /
> `CIVIKITCHEN_ENABLE_EXTENSIONS`, and `/civikitchen-init.d` hooks (marked
> *all* in the [env-var table](configuration.md)). Only the CMS-less standalone
> install knobs differ
> — `CIVICRM_AUTO_INSTALL`, the `CIVICRM_DB_NAME`/`_USER`/`_PASSWORD` app-user
> vars, `CIVIKITCHEN_TEST_DB`, and the `CIVIKITCHEN_DEMO_*` /
> `CIVIKITCHEN_COMPONENTS` knobs don't apply: civibuild builds the site, so it
> provides the equivalent admin users, components, and an isolated
> `sitetest_*` test DB itself. Use buildkit to test CMS-specific behaviour.

## Demo images

Single-container images with an **embedded MariaDB and baked demo data** — no
compose, no external DB. `docker run` and you have a working CiviCRM with demo
content in a few seconds. For demos, evaluation, and screenshots — **not** for
development (the DB is inside the container; data resets on `docker rm`).

Four demo flavors, all built from the same `images/buildkit/` `demo` target:

```bash
# Pick a flavor: standalone-demo (CMS-less), drupal10-demo, wordpress-demo, joomla-demo
docker run -d -p 80:80 --name civicrm ghcr.io/jfilter/civikitchen:drupal10-demo

# then open http://localhost  —  login: admin / admin
```

All four support the demo profiles below (`CIVIKITCHEN_PROFILE`). `joomla-demo`
needed extra work to get there — civibuild's `joomla-demo` install is
deliberately incomplete (it leaves CiviCRM's Joomla component registration as a
commented-out `#fixme` and enables only the `civi_contribute` component) — so the
image build finishes that install: it registers CiviCRM's Joomla plugins (giving
the API an HTTP route) and enables the standard component extensions
(`civi_member`, `civi_event`, …) the other demos ship. The **one** Joomla
difference: authx's password/basic-auth flow doesn't work there, so API access on
the Joomla demo is via the **api_key** credential (`X-Civi-Auth: Bearer …`), not
HTTP basic auth. Drupal 11 stays dev-image-only until its profile/API-user path
is wired and tested.

> Map to port **80** (`-p 80:80`): the site is baked at `http://localhost`, so a
> different host port would serve CiviCRM's assets at the wrong base URL.

### Profiles (`CIVIKITCHEN_PROFILE`)

A profile layers a curated extension stack + seed data + API users on top of
the base site at **first boot**. Profiles work on `standalone`, `drupal10`, and
`wordpress` (demo and dev images) and on `joomla-demo`. API users are created
through each CMS's native API — `cv` boots CiviCRM *and* the host CMS, so the
driver uses the Drupal entity API / WordPress users+roles / Standalone APIv4 /
Joomla users+usergroups directly, with no drush or wp-cli. On Joomla each role
gets exactly its permissions (the same least-privilege model as the other CMSs):
civibuild registers no `com_civicrm` ACL asset, so the build creates one and
grants per-role on it, and a small bundled extension (`ckjoomlaidentity`) loads
the matching Joomla identity for headless requests so both api_key reads and
writes enforce those permissions. They live in
[`images/profiles/`](../images/profiles/) (one dir per profile: `profile.json` +
`seeds/*.php`, applied by the shared driver):

| Profile | Extensions | Seed data | API users |
|---|---|---|---|
| `verein` | CiviBanking, CiviSEPA, Contract, Twingle, GDPRX, XCM, IdentityTracker, ContactLayout | Musterverein e.V.: membership types (Voll-/Förder-/Ehrenmitglied), 24 members with addresses + fee history, SEPA creditor + 21 direct-debit mandates | readonly, fundraiser, eventmanager, caseworker, bankimporter |
| `fundraising` | CiviRules, DonRec | 2 campaigns, 18 donors with varied giving history, 6 recurring donors, 4 pledges with installment schedules | readonly, fundraiser |
| `events` | RemoteEvent, EventMessages | 6 past + upcoming events, 18 contacts with participant records in varied statuses | readonly, eventmanager |
| `mailing` | Mosaico (+ core FlexMailer) | 3 segmented mailing lists, 30 subscribers, a draft newsletter | readonly, mailer |

```bash
# German Verein showcase: Drupal 10 + DACH extension stack + seed data + API users
docker run -d -p 80:80 --name civicrm \
    -e CIVIKITCHEN_PROFILE=verein \
    ghcr.io/jfilter/civikitchen:drupal10-demo
```

The profile applies once, on first boot — it clones the extensions from
GitHub, so it **needs network access and takes a few minutes** (watch
`docker logs -f civicrm`; the container turns healthy when done). The
generated API-user credentials are printed to the logs and kept in the
container: `docker exec civicrm cat /home/buildkit/api-credentials.txt`.

Profiles also work on the tested dev images (`:standalone`, `:drupal10`,
`:wordpress`) — set the same env var in your compose file to develop against a
realistic stack. They are not enabled for `:joomla` yet. On the `:standalone`
dev image the profile needs an admin user to seed as, so combine it with
`CIVICRM_AUTO_INSTALL=1` and `CIVIKITCHEN_DEMO_USER=admin`.

> **Migrating from `civicrm-eu-ngo:latest`?** That pre-baked image is retired;
> use `civikitchen:drupal10-demo` with `CIVIKITCHEN_PROFILE=verein` instead —
> the same extension stack (minus the deprecated Shoreditch theme), now with
> proper membership/SEPA seed data, applied at first boot.

## Tags & versions

All images rebuild **weekly** (and on every `images/**` change) against the
current CiviCRM stable release, resolved from
[latest.civicrm.org](https://latest.civicrm.org/stable.php) at build time. The
pipeline is test-then-promote: a release that breaks the build or the boot
tests never reaches the stable tags — they keep serving the last good image
until the breakage is fixed.

| Tag | What it points at |
|-----|-------------------|
| `:standalone` | The most recent CiviCRM `latest` build. |
| `:standalone-latest` | Same as `:standalone`. |
| `:standalone-<minor>` | Latest patch of the **current** stable minor (e.g. `:standalone-6.15` while 6.15.x is current). When upstream moves to the next minor, a new tag appears and the old one freezes at its last patch — handy as a known-good fallback right after a minor bump. |
| `:drupal10`, `:drupal11`, `:wordpress`, `:joomla`, `:*-demo` | Bake the current stable at image-build time. Check what a pulled image contains without booting it: `docker inspect <image> --format '{{ index .Config.Labels "org.opencontainers.image.version" }}'`. |

Need a minor pinned longer than that — or a version the published images don't
offer at all? Build your own: see
[Custom or older CiviCRM versions](#custom-or-older-civicrm-versions) below.

## Custom or older CiviCRM versions

The published tags track **current** CiviCRM. To run an older or arbitrary
version — e.g. to mirror a production server — build the image yourself with
`--build-arg CIVICRM_VERSION=<tag/branch>`. **Which flavor you can build
matters:**

- **Standalone** (`images/standalone/`) is `FROM civicrm/civicrm:<version>`, so
  it only reaches versions the official image publishes (~6.0+) — and
  Standalone itself only exists from ~5.69. `--build-arg CIVICRM_VERSION` on
  this flavor fails for anything older (no such base image).
- **Buildkit** (`:drupal10` / `:drupal11` / `:wordpress` / `:joomla`,
  `images/buildkit/`) bakes the site
  with `civibuild create --civi-ver <version>`, which fetches **any** civicrm
  tag/branch. The Drupal 10 site type is the right path for modern older
  versions such as CiviCRM 5.78.x; for pre-Drupal-10 versions, switch the
  civibuild site type to Drupal 9 / 7. A Drupal target also mirrors a real
  Drupal server's CMS, not just its CiviCRM version.

So: **for an older/custom version, build the buildkit (Drupal) flavor**, not
standalone.

```bash
docker build -f images/buildkit/Dockerfile \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION=5.78.2 \
    --build-arg PHP_VERSION=8.1 \
    -t civikitchen:drupal10-5.78.2 images/
```

Or let compose build it on demand (no prebuilt image needed) — ready-to-run:
[`examples/custom-version/`](../examples/custom-version/), parameterized by
`CIVICRM_VERSION` / `PHP_VERSION`. For CiviCRM older than ~5.47 (pre-Drupal-10),
switch `DEFAULT_SITE_TYPE` to a Drupal 9 / 7 civibuild site type.
