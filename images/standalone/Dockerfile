# CiviCRM Standalone Development Image
# Adds dev tools (coverage, debug, testing, static analysis, linting) to the
# official CiviCRM image.
#
# Build context: images/  (so we can COPY ../lib/* — see Building locally
# section in the repo README).
#
#   docker build -f images/standalone/Dockerfile images/
#
# In your extension's .docker/compose.yaml:
#   services:
#     app:
#       image: ghcr.io/jfilter/civikitchen:standalone

ARG CIVICRM_VERSION=latest
FROM civicrm/civicrm:${CIVICRM_VERSION}

# OS-level deps:
# - unzip/git: composer needs them for dist/source installs
# - default-mysql-client: for poking the DB from inside the container
# - nodejs/npm: required by extensions with Angular/JS assets. 24.x is the
#   current LTS line (Node 20 went end-of-life 2026-04-30); npm is upgraded
#   past what the distro package ships in install-dev-tools.sh (NPM_VERSION),
#   which is what keeps npm's own bundled dependency tree out of the image scan.
# - jq: the profile driver (CIVIKITCHEN_PROFILE) parses profile.json with it
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip default-mysql-client jq \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# pcov: fast code-coverage driver (PHPUnit's default coverage path).
# xdebug: installed but NOT enabled — entrypoint activates it when XDEBUG_MODE is set.
RUN pecl install pcov xdebug \
    && docker-php-ext-enable pcov

# composer: required by most modern extensions to install vendor deps.
RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

# Shared dev-tools install (civix, phpunit, phpstan, phpcs+civicrm-coder,
# rector/ckmodernize).
# Lives in images/lib/ so the buildkit image uses the same script.
# Every tool version is pinned in the script; pass --build-arg to override
# (e.g. to test a newer phpstan before bumping the default).
ENV PATH="/opt/composer/vendor/bin:${PATH}"
ARG PHPSTAN_VERSION
ARG PHPSTAN_DEPRECATION_RULES_VERSION
ARG PHPSTAN_EXTENSION_INSTALLER_VERSION
ARG CODER_REF
ARG RECTOR_VERSION
ARG PSALM_VERSION
# Overriding a phar *_VERSION requires overriding its *_SHA256 too — the
# install script fails a mismatch on purpose.
ARG CIVIX_VERSION
ARG CIVIX_SHA256
ARG PHPUNIT_VERSION
ARG PHPUNIT_SHA256
ARG MAGO_VERSION
ARG MAGO_SHA256_X86_64
ARG MAGO_SHA256_AARCH64
ARG NPM_VERSION
COPY lib/install-dev-tools.sh /tmp/install-dev-tools.sh
# The bundled CiviKitchen phpcs standard (registered into installed_paths by
# install-dev-tools.sh) and the cklint linter that runs it.
COPY coder/CiviKitchen /opt/civikitchen-coder/CiviKitchen
COPY lib/cklint /usr/local/bin/cklint
# cross-version compatibility against the declared PHP floor (PHPCompatibility).
COPY lib/ckcompat /usr/local/bin/ckcompat
# composer.json vs the dependencies the code really uses.
COPY lib/ckdeps /usr/local/bin/ckdeps
COPY lib/civikitchen-composer-deps.php /opt/civikitchen-composer-deps.php
# repo-conformance check against the extension template (structure, not style).
# This image is what the extension repos run their CI in, so it needs the same
# tools the buildkit image has — ckconform and ckcoverage were added there only,
# which would have made every repo's lint job fail on 'ckconform: command not
# found' the moment CI first ran.
COPY ckconform /opt/civikitchen-ckconform
COPY lib/ckconform /usr/local/bin/ckconform
# coverage gate: runs the suite with clover and enforces .ckconform's min_coverage
COPY lib/ckcoverage /usr/local/bin/ckcoverage
# release helper: version consistency + the installable dist zip (docs/extension-releases.md)
COPY lib/ckrelease /usr/local/bin/ckrelease
# rector setup (config + CiviCRM footgun rules) + the `ckmodernize` wrapper.
COPY lib/civikitchen-rector /opt/civikitchen-rector
COPY lib/ckmodernize /usr/local/bin/ckmodernize
# CiviKitchen's phpstan extension (@ck-legacy deprecated-scope resolver) —
# install-dev-tools.sh pulls it in as a composer path repository from here.
COPY lib/civikitchen-phpstan /opt/civikitchen-phpstan-ext
# Ban lists as config (spaze/phpstan-disallowed-calls); inert until included.
COPY lib/civikitchen-phpstan-config /opt/civikitchen-phpstan-config
# psalm taint setup (taint-only config + CiviCRM source/sink/escape stubs) +
# the `cktaint` wrapper. Advisory gate — see docs/extension-standards.md.
COPY lib/civikitchen-psalm /opt/civikitchen-psalm
COPY lib/cktaint /usr/local/bin/cktaint
# Reset helper for the headless-test scratch DB (reseeds <db>_test, clears
# the per-DSN boot caches — see the script header for the failure mode).
COPY lib/cktestreset /usr/local/bin/cktestreset
# Core phpunit suites against the installed dist core (fetches the export-
# ignored tests/ tree for the exact installed version on first use).
COPY lib/ckcoretest /usr/local/bin/ckcoretest
# Smarty compile smoke: the runtime counterpart to ckconform's static template
# checks. Needs a booted site, so it drives `cv scr` with the payload below.
COPY lib/cksmarty /usr/local/bin/cksmarty
COPY lib/civikitchen-smarty-compile.php /usr/local/share/civikitchen/cksmarty-compile.php
# install()-vs-upgrader schema parity (used by the shared CI's schema-parity
# job; needs the mysql client this image already has).
COPY lib/ckschemadiff /usr/local/bin/ckschemadiff
# ESLint: the pinned toolchain (installed by install-dev-tools.sh) plus the
# CiviKitchen baseline flat config, and the ckeslint runner that picks between
# it and a repo's own eslint.config.*.
COPY lib/eslint /opt/civikitchen-eslint
COPY lib/ckeslint /usr/local/bin/ckeslint
# Formatting (ckfmt): the mago baseline tuned to the bundled phpcs standard,
# the pinned oxfmt toolchain, and the runner that picks between baseline and a
# repo's own mago.toml / .oxfmtrc.*. mago itself is installed by
# install-dev-tools.sh (per-arch binary, checksum-verified).
COPY lib/civikitchen-mago /opt/civikitchen-mago
COPY lib/oxfmt /opt/civikitchen-oxfmt
COPY lib/ckfmt /usr/local/bin/ckfmt
RUN PHPSTAN_VERSION="${PHPSTAN_VERSION}" CODER_REF="${CODER_REF}" RECTOR_VERSION="${RECTOR_VERSION}" \
        PSALM_VERSION="${PSALM_VERSION}" \
        PHPSTAN_DEPRECATION_RULES_VERSION="${PHPSTAN_DEPRECATION_RULES_VERSION}" \
        PHPSTAN_EXTENSION_INSTALLER_VERSION="${PHPSTAN_EXTENSION_INSTALLER_VERSION}" \
        CIVIX_VERSION="${CIVIX_VERSION}" CIVIX_SHA256="${CIVIX_SHA256}" \
        PHPUNIT_VERSION="${PHPUNIT_VERSION}" PHPUNIT_SHA256="${PHPUNIT_SHA256}" \
        MAGO_VERSION="${MAGO_VERSION}" MAGO_SHA256_X86_64="${MAGO_SHA256_X86_64}" \
        MAGO_SHA256_AARCH64="${MAGO_SHA256_AARCH64}" \
        bash /tmp/install-dev-tools.sh && rm /tmp/install-dev-tools.sh \
    && chmod +x /usr/local/bin/cklint /usr/local/bin/ckconform /usr/local/bin/ckcoverage \
        /usr/local/bin/ckrelease /usr/local/bin/ckmodernize /usr/local/bin/cktestreset \
        /usr/local/bin/ckcoretest \
        /usr/local/bin/cktaint \
        /usr/local/bin/cksmarty /usr/local/bin/ckschemadiff /usr/local/bin/ckeslint \
        /usr/local/bin/ckfmt

# Add our vhost extras (e.g. the bare-/ → /civicrm/login redirect) via the
# standard apache conf-available + a2enconf flow rather than editing the
# upstream image's civicrm.conf.
COPY standalone/apache/civikitchen.conf /etc/apache2/conf-available/civikitchen.conf
RUN a2enconf civikitchen

# Wrap cv so it runs as www-data even when invoked as root (the default for
# `docker compose exec`). Root-run cv leaves root-owned caches/locks that the
# web workers can't write. Opt out with CIVIKITCHEN_CV_AS_ROOT=1.
COPY standalone/cv-wrapper.sh /usr/local/bin/cv-wrapper
RUN mv /usr/local/bin/cv /usr/local/bin/cv.real \
    && mv /usr/local/bin/cv-wrapper /usr/local/bin/cv \
    && chmod +x /usr/local/bin/cv

# Post-install demo-user script (run via `cv scr` from the entrypoint when
# CIVIKITCHEN_DEMO_USER is set). Lives outside the docroot so it's not web-served.
COPY standalone/demo-user.php /usr/local/share/civikitchen/demo-user.php

# Shared xdebug toggle + provisioning library — sourced from the entrypoint,
# also used by the buildkit image so the two stay in lockstep.
COPY lib/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY lib/provision.sh /usr/local/share/civikitchen/provision.sh
COPY lib/patch-test-db-boot.php /usr/local/share/civikitchen/patch-test-db-boot.php

# Demo profiles (CIVIKITCHEN_PROFILE) — same set as the buildkit images;
# applied at first boot after the auto-install (needs an admin user, see
# docs/images.md).
COPY profiles /usr/local/share/civikitchen/profiles

COPY standalone/entrypoint.sh /usr/local/bin/civikitchen-entrypoint
RUN chmod +x /usr/local/bin/civikitchen-entrypoint

# Readiness signal for the auto-install flow: the entrypoint writes this marker
# only after install + provisioning (extensions, init.d hooks) complete, then
# hands off to Apache. Lets dependent services wait via
# `depends_on: { condition: service_healthy }` instead of racing a half-installed
# site. start-period covers a cold install; without CIVICRM_AUTO_INSTALL the
# marker is never written and the container just reports unhealthy (harmless).
HEALTHCHECK --interval=10s --timeout=5s --start-period=180s --retries=12 \
    CMD test -f /var/www/html/private/.civikitchen-provisioned || exit 1

ENTRYPOINT ["/usr/local/bin/civikitchen-entrypoint"]
CMD ["apache2-foreground"]
