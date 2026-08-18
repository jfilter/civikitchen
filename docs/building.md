# Building locally

`make help` lists everything below as a target. The fast loop needs no Docker
at all:

```bash
make test     # ckconform's fixtures, the phpstan extension's rule tests, ckinit
make lint     # shellcheck, actionlint, zizmor, php -l, the profile.json schema
make build    # the standalone image, as civikitchen:standalone
```

These are the same commands `.github/workflows/lint.yml` runs — the workflow
consists of `make lint` and `make test`, so what runs in CI and what runs on a
laptop cannot drift. `make` fetches the pinned tools (phpunit phar, actionlint,
shellcheck) and the CiviCRM source tree the catalog drift gates need into
`.cache/` on first use; `make clean` removes it.

The fast loop needs, besides `git` and `curl`: **GNU Make ≥ 3.82** (the
Makefile refuses older ones — Apple ships 3.81, which silently drops the
strict-shell flags), a `bash` on PATH, `php`, `composer` (only `make
test-phpstan`), and `pipx` (zizmor and the schema check). Everything else is
fetched pinned. The slow loop (`make build`, `make test-images`, `make e2e`)
additionally needs Docker and Node.

The build context is the repo root for both the standalone and buildkit-based images. The Dockerfiles copy from two trees: `toolbelt/` (the `ck*` tools, the phpcs standard, the phpstan/psalm/rector packages — everything baked into the image) and `docker/` (the image's own entrypoints, provisioning and demo profiles). Neither has to live inside the other, and `.dockerignore` keeps `.git` and host-built artifacts out.

```bash
# Standalone (the Dockerfile's default CIVICRM_VERSION — pass the current one)
docker build -f docker/standalone/Dockerfile -t civikitchen:standalone .

# Standalone pinned to an EXACT CiviCRM release (names a tarball on
# download.civicrm.org; a bare minor like 6.15 is not a tarball)
docker build -f docker/standalone/Dockerfile \
    --build-arg CIVICRM_VERSION=6.15.1 \
    -t civikitchen:standalone-6.15.1 .

# Buildkit-based images. The :drupal10, :drupal11, :wordpress, and :joomla
# tags are built from the same Dockerfile (docker/buildkit/) —
# DEFAULT_SITE_TYPE picks which civibuild site type the entrypoint creates
# on first run. CIVICRM_VERSION pins the baked CiviCRM (any civicrm-core
# tag/branch civibuild can fetch). CIVICRM_BUILD_VERSION can override only
# the civibuild input; CI uses it to pass the stable minor branch (e.g.
# 6.15) while keeping the resolved patch version in the image metadata.
docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION=6.15.1 \
    -t civikitchen:drupal10 .

docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal11-demo \
    -t civikitchen:drupal11 .

docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=wp-demo \
    -t civikitchen:wordpress .

docker build -f docker/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=joomla-demo \
    -t civikitchen:joomla .
```

## Bumping a dev tool

Composer-installable tools are pinned in committed `composer.json` +
`composer.lock` pairs, one per install root. Each root has a README saying what
its pins are for; bumping means editing the `composer.json`, re-locking and
committing both files:

| Root | Tools |
| --- | --- |
| `toolbelt/phpcs-root` | phpcs, PHPCompatibility, Slevomat, composer-dependency-analyser |
| `toolbelt/phpstan-root` | phpstan + the extensions every image ships |
| `toolbelt/rector` | rector (and the phpstan version it may see) |
| `toolbelt/psalm` | psalm (its lock carries a PHP floor — see its README) |

```bash
composer update --working-dir=toolbelt/phpstan-root
```

The lock is the pin: it fixes the transitive tree as well, so a monthly image
rebuild cannot move a gate under a repo that did not change. There is no
`--build-arg` for these — an image whose tool tree was resolved fresh at build
time is not the image anyone reviewed.

What composer cannot install stays in `toolbelt/install-dev-tools.sh` and stays
overridable per `--build-arg`: the civix and phpunit phars and the mago binary
(each with a checksum — overriding a `*_VERSION` means overriding its `*_SHA256`
too), npm, and the `civicrm/coder` commit ref. infection is the one composer
tool still pinned there, because it is applied as a ceiling: images for older
CiviCRM lines run on PHP 8.1/8.2, and composer picks the newest release that
PHP accepts.

## Keeping the CiviCRM git history (`KEEP_GIT=1`)

The published buildkit images strip the git history civibuild clones into the
site (`vendor/civicrm/civicrm-core` etc., ~550 MB) — extension development and
the runtime `civibuild reinstall` don't need it. If you want a git-enabled
site (working on core, `civibuild update`, `git log` archaeology), build your
own image with the history kept:

```bash
docker build -f docker/buildkit/Dockerfile \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg KEEP_GIT=1 \
    -t civikitchen:drupal10-git .
```

## Running the test suite locally

`tests/images/run-local.sh` runs the same test scripts CI runs — on a laptop or
in a throwaway VM. It needs only bash and docker (no gh, no node):

```bash
bash tests/images/run-local.sh                          # everything, published images
bash tests/images/run-local.sh drupal11 drupal11-demo   # a subset
bash tests/images/run-local.sh -p civikitchen drupal11-demo   # your locally built tags
CK_PROFILE=verein bash tests/images/run-local.sh drupal10-demo # + a profile leg
```

Per dev flavor it runs the dev-tools functional check and (buildkit flavors)
the external-DB first-boot test; per demo flavor the single-container boot
test, which includes the `CIVIKITCHEN_SITE_URL` rewrite leg on a non-80 host
port. A summary table prints at the end and the exit code reflects failures.
Budget roughly an hour for the full default run; profile legs add up to
~15 min each.

## Verifying a built image

`tests/images/test-dev-tools.sh` is a functional check of every bundled tool — it lints non-conforming PHP through phpcs, runs phpstan against a typed mistake, executes a phpunit assertion, installs a real package via composer, and verifies the xdebug toggle. The same script runs in CI against both `:standalone` and the buildkit images. CI also boots each dev flavor's compose example and runs Playwright browser smoke tests before promoting stable tags.

```bash
docker run --rm -v "$(pwd)/tests/images:/civikitchen-test:ro" \
    --entrypoint='' \
    ghcr.io/jfilter/civikitchen:standalone \
    bash /civikitchen-test/test-dev-tools.sh
```

## Linting

Every push runs the `Lint` workflow: strict shellcheck (style level, see
`.shellcheckrc` for the two disabled false-positive classes) over all shell
scripts, actionlint over the workflows, `php -l` over the seed/profile
scripts, and a shape check on the `profile.json` files. Locally:

```bash
find images examples -name '*.sh' -print0 | xargs -0 shellcheck -S style
actionlint
```
