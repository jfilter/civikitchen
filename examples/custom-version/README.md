# Custom / older CiviCRM version

Run a CiviCRM version the published images don't provide — built locally via
`civibuild`, with **no reliance on the official `civicrm/civicrm` image**.

Why this exists:

- The official `civicrm/civicrm` image (which the `:standalone` flavor builds
  `FROM`) only publishes back to ~6.0, so `--build-arg CIVICRM_VERSION=` on the
  standalone flavor can't reach older versions.
- **Standalone** itself only exists from ~5.69.
- **Drupal/civibuild** site types have been used across a much wider CiviCRM
  range. This example uses Drupal 10, which is the right fit for modern older
  installs such as CiviCRM 5.78.x. It also matches a real Drupal production
  server more faithfully (same CMS, not just the same CiviCRM version).

## Usage

```bash
# First run builds the image (~10-15 min: civibuild downloads the CMS + CiviCRM
# and bakes the site into the image). Pick the version your server runs.
CIVICRM_VERSION=5.78.2 docker compose up -d --build

# Watch the first-boot install (civibuild reinstall against the db sidecar):
docker compose logs -f app
# Ready when the container is healthy. Then open:
#   http://localhost:8080   (admin / admin)

# Later starts reuse the built image — drop --build:
docker compose up -d
```

Override to match your target server:

| Var | Default | Notes |
|-----|---------|-------|
| `CIVICRM_VERSION` | `5.78.2` | Any tag/branch civibuild can fetch (e.g. `5.77.0`, `6.2.1`). |
| `PHP_VERSION` | `8.1` | Match your server's PHP. Drupal 10 needs 8.1+. |

For CiviCRM older than ~5.47 (pre-Drupal-10), change `DEFAULT_SITE_TYPE` in the
compose file to a Drupal 9 / Drupal 7 civibuild type and lower `PHP_VERSION`.

## Developing an extension against it

Uncomment the `volumes:` mount and `CIVIKITCHEN_ENABLE_EXTENSIONS` in
`docker-compose.yml` to bind-mount your extension and enable it on first boot;
use `CIVIKITCHEN_EXTRA_EXTENSIONS` to pull dependencies from the registry. On the
buildkit CMS flavor the extension dir is the CMS `extensionsDir`
(`…/web/sites/default/files/civicrm/ext`), not `/var/www/html/ext` — see the
mount note in the compose file. Then:

```bash
docker compose exec app cv ext:list                  # list extensions
EXT=/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext
docker compose exec -e CIVICRM_UF=UnitTests app \
    bash -c "cd $EXT/myextension && phpunit"
```

See [`docs/configuration.md`](../../docs/configuration.md) for every first-boot
knob and [`docs/images.md`](../../docs/images.md#custom-or-older-civicrm-versions)
for the rationale.
