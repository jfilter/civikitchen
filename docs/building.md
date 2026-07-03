# Building locally

The build context is the `images/` dir for both the standalone and buildkit-based images, so the Dockerfiles can `COPY lib/install-dev-tools.sh` (the shared phars + phpcs/coder install).

```bash
# Standalone (tracks civicrm/civicrm:latest)
docker build -f images/standalone/Dockerfile -t civikitchen:standalone images/

# Standalone pinned to a specific CiviCRM minor (or any tag civicrm/civicrm publishes)
docker build -f images/standalone/Dockerfile \
    --build-arg CIVICRM_VERSION=6.15 \
    -t civikitchen:standalone-6.15 images/

# Buildkit-based images. The :drupal10, :drupal11, :wordpress, and :joomla
# tags are built from the same Dockerfile (images/buildkit/) —
# DEFAULT_SITE_TYPE picks which civibuild site type the entrypoint creates
# on first run. CIVICRM_VERSION pins the baked CiviCRM (any civicrm-core
# tag/branch civibuild can fetch). CIVICRM_BUILD_VERSION can override only
# the civibuild input; CI uses it to pass the stable minor branch (e.g.
# 6.15) while keeping the resolved patch version in the image metadata.
docker build -f images/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg CIVICRM_VERSION=6.15.1 \
    -t civikitchen:drupal10 images/

docker build -f images/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=drupal11-demo \
    -t civikitchen:drupal11 images/

docker build -f images/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=wp-demo \
    -t civikitchen:wordpress images/

docker build -f images/buildkit/Dockerfile \
    --build-arg PHP_VERSION=8.3 \
    --build-arg DEFAULT_SITE_TYPE=joomla-demo \
    -t civikitchen:joomla images/
```

## Keeping the CiviCRM git history (`KEEP_GIT=1`)

The published buildkit images strip the git history civibuild clones into the
site (`vendor/civicrm/civicrm-core` etc., ~550 MB) — extension development and
the runtime `civibuild reinstall` don't need it. If you want a git-enabled
site (working on core, `civibuild update`, `git log` archaeology), build your
own image with the history kept:

```bash
docker build -f images/buildkit/Dockerfile \
    --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
    --build-arg KEEP_GIT=1 \
    -t civikitchen:drupal10-git images/
```

## Verifying a built image

`images/test/test-dev-tools.sh` is a functional check of every bundled tool — it lints non-conforming PHP through phpcs, runs phpstan against a typed mistake, executes a phpunit assertion, installs a real package via composer, and verifies the xdebug toggle. The same script runs in CI against both `:standalone` and the buildkit images. CI also boots each dev flavor's compose example and runs Playwright browser smoke tests before promoting stable tags.

```bash
docker run --rm -v "$(pwd)/images/test:/civikitchen-test:ro" \
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
