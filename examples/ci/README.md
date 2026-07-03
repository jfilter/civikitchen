# CI for your extension

Run your extension's phpunit suite against a real CiviCRM in GitHub Actions,
using the `:standalone` image. Three files to copy into your extension repo:

| File | Copy to | Purpose |
|------|---------|---------|
| [`github-actions.yml`](github-actions.yml) | `.github/workflows/civicrm-tests.yml` | The workflow: boot → enable → phpunit |
| [`docker-compose.ci.yml`](docker-compose.ci.yml) | repo root | Minimal app + db stack, mounts the repo as the extension |
| [`db-init/01-grants.sql`](db-init/01-grants.sql) | `db-init/` | Grants for the isolated `<db>_test` headless-test database |

Then replace `myextension` with your extension key (in the workflow and the
compose file) and push.

**Testing against CMS flavors too?** The workflow ships a commented-out
`phpunit-cms` matrix job (Drupal 10/11, WordPress, Joomla) that uses
[`docker-compose.ci-cms.yml`](docker-compose.ci-cms.yml) — copy that file as
well and uncomment the job. Two differences from standalone: the extension
mounts at the CMS's own `extensionsDir` (the matrix carries the per-flavor
path), and `cv` must run inside the civibuild site tree (the job's steps
already do).

How it works:

- The repo itself is bind-mounted as `/var/www/html/ext/myextension`; if it
  ships a `composer.json`, `vendor/` is installed automatically on container
  start (`CIVIKITCHEN_AUTO_COMPOSER`, default on). One exception: lock files
  that vendor `civicrm/civicrm-core` (the systopia dev-tooling pattern) are
  deliberately skipped — build a runtime `vendor/` in a separate workflow
  step instead.
- Headless tests run with `CIVICRM_UF=UnitTests` against the `<db>_test`
  scratch database the image configures at install — never against the site DB.
- Registry dependencies go into `CIVIKITCHEN_EXTRA_EXTENSIONS` (supports
  `key@URL` for pinned releases); anything more exotic into
  [`/civikitchen-init.d` hooks](../../docs/extension-development.md#provisioning-hooks).

Tips:

- **Pin a minor** (`ghcr.io/jfilter/civikitchen:standalone-6.15`) for
  reproducible CI, or track `:standalone` to catch upstream breakage early —
  or run both in a matrix.
- For browser-level UI tests, start from
  [`examples/extension-with-playwright/`](../extension-with-playwright/) instead.
- Every first-boot knob is listed in the
  [configuration reference](../../docs/configuration.md).
