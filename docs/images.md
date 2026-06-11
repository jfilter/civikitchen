# Images

Four image flavors, all published to `ghcr.io/jfilter/civikitchen`. For the
first-boot knobs each flavor understands, see the
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

## WordPress (dev)

CiviCRM on WordPress via buildkit. Same pattern and env vars as Drupal 10. The `:wordpress` and `:drupal10` tags are built from the same Dockerfile (`images/buildkit/`) — only the default civibuild site type differs (`wp-demo` vs `drupal10-demo`). Both images carry the same dev tools as the standalone image (composer, node/npm, phpunit, phpstan, phpcs+coder, civix, pcov, xdebug).

Ready-to-run: [`examples/wordpress/`](../examples/wordpress/)

> **Scope note.** The buildkit images (`:drupal10`, `:wordpress`) share
> [`images/lib/provision.sh`](../images/lib/provision.sh) with standalone, so the
> same first-boot knobs work: `CIVIKITCHEN_AUTO_COMPOSER`,
> `CIVIKITCHEN_SMTP_HOST`, `CIVIKITCHEN_EXTRA_EXTENSIONS` /
> `CIVIKITCHEN_ENABLE_EXTENSIONS`, and `/civikitchen-init.d` hooks (marked
> *all* in the [env-var table](configuration.md)). Only the CMS-less standalone
> install knobs differ
> — `CIVICRM_AUTO_INSTALL`, the `CIVICRM_DB_NAME`/`_USER`/`_PASSWORD` app-user
> vars, `CIVIKITCHEN_TEST_DB`, and the `CIVIKITCHEN_DEMO_*` /
> `CIVIKITCHEN_COMPONENTS` knobs don't apply: civibuild builds the site, so it
> provides the equivalent admin/demo users, components, and an isolated
> `sitetest_*` test DB itself. Use buildkit to test CMS-specific behaviour.

## Demo images

Single-container images with an **embedded MariaDB and baked demo data** — no
compose, no external DB. `docker run` and you have a working CiviCRM with demo
content in a few seconds. For demos, evaluation, and screenshots — **not** for
development (the DB is inside the container; data resets on `docker rm`).

Three flavors (one per CMS), all built from the same `images/buildkit/` `demo`
target:

```bash
# Pick a flavor: standalone-demo (CMS-less), drupal10-demo, or wordpress-demo
docker run -d -p 80:80 --name civicrm ghcr.io/jfilter/civikitchen:drupal10-demo

# then open http://localhost  —  login: admin / admin
```

> Map to port **80** (`-p 80:80`): the site is baked at `http://localhost`, so a
> different host port would serve CiviCRM's assets at the wrong base URL.

### Profiles (`CIVIKITCHEN_PROFILE`)

A profile layers a curated extension stack + seed data + API users on top of
the base site at **first boot**. Profiles work on **all three flavors**
(standalone, drupal10, wordpress — demo and dev images alike); API users are
created through the matching mechanism per CMS (Drupal roles via drush,
WordPress roles/capabilities via wp-cli, native users on Standalone). They
live in [`images/profiles/`](../images/profiles/) (one dir per profile:
`profile.json` + `seeds/*.php`, applied by the shared driver):

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

Profiles also work on the dev images (`:standalone`, `:drupal10`,
`:wordpress`) — set the same env var in your compose file to develop against
a realistic stack. On the `:standalone` dev image the profile needs an admin
user to seed as, so combine it with `CIVICRM_AUTO_INSTALL=1` and
`CIVIKITCHEN_DEMO_USER=admin`.

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
| `:drupal10`, `:wordpress`, `:*-demo` | Bake the current stable at image-build time. Check what a pulled image contains without booting it: `docker inspect <image> --format '{{ index .Config.Labels "org.opencontainers.image.version" }}'`. |

Need a minor pinned longer than that? Build your own image — see
[Building locally](building.md) (`--build-arg CIVICRM_VERSION=...` works for
all flavors).
