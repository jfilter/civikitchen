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

How it works:

- The repo itself is bind-mounted as `/var/www/html/ext/myextension`; if it
  ships a `composer.json`, `vendor/` is installed automatically on container
  start (`CIVIKITCHEN_AUTO_COMPOSER`, default on).
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
