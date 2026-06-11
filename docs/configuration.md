# Configuration

Two prefixes, by ownership: `CIVIKITCHEN_*` vars are this project's own behavior knobs; `CIVICRM_*` is reserved for the upstream image contract (`CIVICRM_AUTO_INSTALL`, `CIVICRM_DB_*`), for describing the CiviCRM/civibuild target (`CIVICRM_VERSION`, `CIVICRM_SITE_TYPE`) and for CiviCRM's own variables (e.g. `CIVICRM_UF`). Legacy `CIVICRM_`-spelled kitchen vars (and `SITE_URL`) keep working with a deprecation warning. Where a var only makes sense for one image family, the **Image** column flags it.

| Env var | Default | Image | Purpose |
|---------|---------|-------|---------|
| `CIVICRM_DB_HOST` | `db` | both | DB hostname. |
| `CIVICRM_DB_PORT` | `3306` | both | DB port. |
| `CIVIKITCHEN_SITE_URL` | derived | both | Browser-facing URL Civi bakes into asset paths. Must match the host:port the user opens — set it to `http://localhost:8080` if you mapped `-p 8080:80`. |
| `CIVICRM_AUTO_INSTALL` | `0` | standalone | Set to `1` to auto-install CiviCRM on first start (when `civicrm.settings.php` is missing). |
| `CIVICRM_DB_NAME` | `civicrm` | standalone | Database name (the standalone image connects with the app user; buildkit creates DB + user itself). |
| `CIVICRM_DB_USER` | `civicrm` | standalone | DB user used by the running CiviCRM. |
| `CIVICRM_DB_PASSWORD` | `civicrm` | standalone | Password for `CIVICRM_DB_USER`. |
| `CIVIKITCHEN_DEMO_USER` | _(unset)_ | standalone | If set during auto-install, creates a CiviCRM login user with this username and the `admin` role. Requires `CIVICRM_AUTO_INSTALL=1`. |
| `CIVIKITCHEN_DEMO_PASS` | `admin` | standalone | Password for the demo user. |
| `CIVIKITCHEN_DEMO_EMAIL` | `admin@example.org` | standalone | Email for the demo user's contact record. |
| `CIVIKITCHEN_COMPONENTS` | all standard | standalone | Comma-separated CiviCRM components to enable at install. Defaults to the full set: `CiviEvent,CiviContribute,CiviMember,CiviMail,CiviPledge,CiviCase,CiviReport,CiviCampaign`. Override to narrow the set, or pass an empty string for `cv`'s own core-only default. |
| `CIVIKITCHEN_SMTP_HOST` | _(unset)_ | all | If set, points Civi's `mailing_backend` at this SMTP host after install. The example compose stack uses `maildev` so outbound mail lands in the maildev UI on `:1080`. |
| `CIVIKITCHEN_SMTP_PORT` | `1025` | all | Port for `CIVIKITCHEN_SMTP_HOST`. |
| `CIVIKITCHEN_EXTRA_EXTENSIONS` | _(unset)_ | all | Comma-separated extension keys downloaded + enabled after install — e.g. `de.systopia.xcm,de.systopia.twingle`. Each entry can also be `key@URL` for a pinned or forked release (passed to `cv ext:download` verbatim). Replaces hand-rolled `cv ext:download` / `cv ext:enable` boilerplate in extension test setups. Runs once during first-boot provisioning (standalone gates it on `CIVICRM_AUTO_INSTALL=1`; buildkit runs it after the civibuild site build). |
| `CIVIKITCHEN_ENABLE_EXTENSIONS` | _(unset)_ | all | Comma-separated keys of extensions that are already present (e.g. bind-mounted into `/var/www/html/ext`) to enable after install — e.g. `mailattachment,de.systopia.civioffice,mailbatch`. Complements `CIVIKITCHEN_EXTRA_EXTENSIONS`, which downloads from the registry. Runs once during first-boot provisioning (standalone gates it on `CIVICRM_AUTO_INSTALL=1`; buildkit runs it after the civibuild site build). |
| `CIVIKITCHEN_PROFILE` | _(unset)_ | drupal10/wordpress + demos | Named profile from [`images/profiles/`](../images/profiles/) applied once at first boot: clones + enables the profile's extensions, loads seed data, and creates API users. Available: `verein`, `fundraising`, `events`, `mailing` (all Drupal 10 only). Needs network and takes a few minutes; credentials land in the logs and in `/home/buildkit/api-credentials.txt`. |
| `CIVIKITCHEN_AUTO_COMPOSER` | `1` | all | If `1`, scan `/var/www/html/ext/*/composer.json` on every container start and run `composer install` in each extension directory whose `vendor/` is missing. Removes the manual gate before `vendor/bin/phpunit` works. Idempotent (skips when `vendor/` exists), non-fatal on failure. Set to `0` if you ship `vendor/` in your repo or want full control. |
| `CIVIKITCHEN_TEST_DB` | `1` | standalone | If `1`, configure an isolated headless-test database on first install: create `<db>_test` (e.g. `civicrm_test`) and write `TEST_DB_DSN` to `~/.cv.json` (root + www-data) so `CIVICRM_UF=UnitTests` runs against it instead of the dev DB. Prevents a headless `phpunit` run from wiping the main database. Set to `0` to manage `TEST_DB_DSN` yourself. |
| `CIVIKITCHEN_EXTRA_PACKAGES` | _(unset)_ | standalone | Comma- or space-separated Debian packages installed on container start (e.g. `libreoffice-writer,unoconv` for CiviOffice rendering) — heavyweight or niche deps stay out of the image. Restarts skip packages that are already present. |
| `CIVIKITCHEN_CV_AS_ROOT` | `0` | standalone | `cv` inside the container is wrapped to always run as `www-data`, even from `docker compose exec` (root) — root-run `cv` leaves root-owned caches/locks that break the web workers. Set to `1` to bypass the wrapper and run `cv` as root. |
| `CIVICRM_DB_ROOT_PASSWORD` | `root` | drupal10/wordpress | DB **admin** password. civibuild uses it to create the per-site database and user during the first-run site build. |
| `CIVICRM_SITE_TYPE` | tag default | drupal10/wordpress | civibuild site type. The `:drupal10` tag defaults to `drupal10-demo`, `:wordpress` to `wp-demo`. Override to use any [civibuild template](https://docs.civicrm.org/dev/en/latest/tools/civibuild/). |
