# CiviCRM core development

## Core test suites: `ckcoretest`

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

## Standalone on an unreleased branch

`:standalone-master` and `:standalone-beta` are Standalone built from core's
`master` and its newest release branch, published daily
([Tags and versions](images.md#tags-and-versions)). For another branch or tag, or a build
of your own, build Standalone from git:

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
    image: ghcr.io/civico-de/civikitchen:standalone-master
    volumes:
      - ../civicrm-core/ext/afform:/var/www/html/core/ext/afform
```

Run `cv flush` after toggling; the Angular and asset caches otherwise keep the
old files. A change to composer dependencies or outside the mounted directory
needs a rebuilt image.
