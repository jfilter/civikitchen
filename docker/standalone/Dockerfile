# CiviCRM Standalone Development Image
# CiviCRM Standalone from the release tarball plus the dev tools (coverage,
# debug, testing, static analysis, linting).
#
# Self-built base, not FROM civicrm/civicrm: the Docker Hub image lags the
# release (stable.php said 6.17.0 while civicrm/civicrm:6.17 did not exist,
# and every build died on the FROM line), while the tarball this installs is
# published with the release itself. The base block below mirrors what the
# official image provides (github.com/civicrm/civicrm-docker), so consumers
# see the same PHP extensions, Apache vhost and ini values as before the
# switch — test-dev-tools.sh asserts the parity points.
#
# Build context: the repo root, so this can COPY from both toolbelt/ (what gets
# baked in) and docker/ (this image's own runtime files) without either having
# to live inside the other. See docs/building.md.
#
#   docker build -f docker/standalone/Dockerfile --build-arg CIVICRM_VERSION=<exact> .
#
# In your extension's .docker/compose.yaml:
#   services:
#     app:
#       image: ghcr.io/jfilter/civikitchen:standalone

# Same PHP the official image published (civicrm/civicrm:6.16 ships 8.4).
ARG PHP_VERSION=8.4
# Exact release, never a minor: this names a tarball. CI passes the resolved
# stable; the default here only serves a bare local `docker build` and rots.
ARG CIVICRM_VERSION=6.17.0

# --- fetch stage: the CiviCRM Standalone release tarball ---------------------
FROM php:${PHP_VERSION}-apache-bookworm AS download
ARG CIVICRM_VERSION
ARG CIVICRM_DOWNLOAD_URL="https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-standalone.tar.gz"
RUN curl -fsSL "${CIVICRM_DOWNLOAD_URL}" -o /tmp/civicrm-standalone.tar.gz \
    && mkdir /tmp/civicrm \
    && tar -xzf /tmp/civicrm-standalone.tar.gz -C /tmp/civicrm \
    && test -f /tmp/civicrm/civicrm-standalone/civicrm.standalone.php

FROM php:${PHP_VERSION}-apache-bookworm

# OS-level deps:
# - libfreetype/libjpeg62-turbo/libpng/libwebp-dev: the PHP gd extension
# - libicu-dev: intl; libzip-dev: zip
# - imagemagick + libmagickwand-dev: the imagick extension (e.g. Mosaico)
# - mariadb-client: cv, ckschemadiff and poking the DB from inside the container
# - unzip/git: composer needs them for dist/source installs
# - nodejs/npm: required by extensions with Angular/JS assets. 24.x is the
#   current LTS line (Node 20 went end-of-life 2026-04-30); npm is upgraded
#   past what the distro package ships in install-dev-tools.sh (NPM_VERSION),
#   which is what keeps npm's own bundled dependency tree out of the image scan.
# - jq: the profile driver (CIVIKITCHEN_PROFILE) parses profile.json with it
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip jq \
        libfreetype-dev libicu-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libzip-dev imagemagick libmagickwand-dev mariadb-client \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# The extension set the official image shipped, plus soap and opcache to stay
# in lockstep with the buildkit base. gd with webp: Mosaico.
# pcov: fast code-coverage driver (PHPUnit's default coverage path).
# xdebug: installed but NOT enabled — entrypoint activates it when XDEBUG_MODE is set.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd bcmath intl mysqli pdo_mysql zip soap opcache \
    && pecl install imagick pcov xdebug \
    && docker-php-ext-enable imagick pcov

# cv, pinned phar + checksum (the official image took a floating cv.phar).
# Re-derive the hash on a bump from the release's SHA256SUMS file.
ARG CV_VERSION=0.3.71
ARG CV_SHA256=06794d7ea5ea7dc901ae9b2a1c14acd79c63979b73a00be9303990688e6f6e46
RUN curl -fsSLo /usr/local/bin/cv \
        "https://github.com/civicrm/cv/releases/download/v${CV_VERSION}/cv-${CV_VERSION}.phar" \
    && echo "${CV_SHA256}  /usr/local/bin/cv" | sha256sum -c --strict - \
    && chmod +x /usr/local/bin/cv

# Apache: same modules, vhost and ServerName the official image configured.
# civikitchen's own extras stay in civikitchen.conf further down.
RUN a2enmod rewrite remoteip
COPY docker/standalone/apache/civicrm.conf /etc/apache2/conf-available/civicrm.conf
COPY docker/standalone/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
RUN a2enconf civicrm

# php.ini values 1:1 with the official image, so the switch changes no
# behavior for the repos running CI in here.
COPY docker/standalone/php/civicrm.ini ${PHP_INI_DIR}/conf.d/civicrm.ini
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php-cli.ini" \
    && echo 'memory_limit = ${PHP_MEMORY_LIMIT:-"256M"}\n' >> "$PHP_INI_DIR/php.ini" \
    && echo 'memory_limit = -1\n' >> "$PHP_INI_DIR/php-cli.ini"

ENV CIVICRM_UF=Standalone

# composer: required by most modern extensions to install vendor deps.
RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

# Shared dev-tools install (civix, phpunit, phpstan, phpcs+civicrm-coder,
# rector/ckmodernize).
# Lives in toolbelt/ so the buildkit image uses the same script.
# Every tool version is pinned in the script; pass --build-arg to override
# (e.g. to test a newer phpstan before bumping the default).
ENV PATH="/opt/composer/vendor/bin:${PATH}"
ARG CODER_REF
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
ARG INFECTION_VERSION
# The phpcs installation root IS composer's home dir — `composer global
# install` in install-dev-tools.sh reads exactly these two files.
COPY toolbelt/phpcs-root /opt/composer
COPY toolbelt/install-dev-tools.sh /tmp/install-dev-tools.sh
# Beside the script, because that is where it looks for it — the same relative
# path that resolves in a checkout. Shared pins only (the phpunit phar the tool
# test suites also run on); everything single-consumer stays in the script.
COPY toolbelt/versions.env /tmp/versions.env

# Shared plumbing every ck* tool sources. The destination is /usr/local/lib on
# purpose: the tools resolve it as "../lib/ckcommon.sh" relative to their own
# location, which then works unchanged in a checkout (toolbelt/bin +
# toolbelt/lib) and here (/usr/local/bin + /usr/local/lib). One source line, no
# image-vs-checkout branch to keep in sync.
COPY toolbelt/lib/ckcommon.sh /usr/local/lib/ckcommon.sh
# The bundled CiviKitchen phpcs standard (registered into installed_paths by
# install-dev-tools.sh) and the cklint linter that runs it.
COPY toolbelt/phpcs/CiviKitchen /opt/civikitchen-coder/CiviKitchen
COPY toolbelt/bin/cklint /usr/local/bin/cklint
# cross-version compatibility against the declared PHP floor (PHPCompatibility).
COPY toolbelt/bin/ckcompat /usr/local/bin/ckcompat
COPY toolbelt/bin/ckcivix /usr/local/bin/ckcivix
# composer.json vs the dependencies the code really uses.
COPY toolbelt/bin/ckdeps /usr/local/bin/ckdeps
COPY toolbelt/lib/composer-deps.php /opt/civikitchen-composer-deps.php
# repo-conformance check against the extension template (structure, not style).
# This image is what the extension repos run their CI in, so it needs the same
# tools the buildkit image has — ckconform and ckcoverage were added there only,
# which would have made every repo's lint job fail on 'ckconform: command not
# found' the moment CI first ran.
COPY toolbelt/ckconform /opt/civikitchen-ckconform
COPY toolbelt/bin/ckconform /usr/local/bin/ckconform
# coverage gate: runs the suite with clover and enforces .ckconform's min_coverage
COPY toolbelt/bin/ckcoverage /usr/local/bin/ckcoverage
# mutation testing (infection): coverage's harder question, nightly-only.
COPY toolbelt/bin/ckmutate /usr/local/bin/ckmutate
# Transactional-test gate: the phpunit listener that proves a Transactional
# test was really rolled back, plus the runner that injects it into the config.
COPY toolbelt/bin/ckphpunit /usr/local/bin/ckphpunit
COPY toolbelt/lib/tx-canary.php /usr/local/share/civikitchen/tx-canary.php
# Lifecycle gate: install→disable→enable→uninstall with post-uninstall DB
# invariants and a log scan, so a swallowed SQL error cannot stay green.
COPY toolbelt/bin/cklifecycle /usr/local/bin/cklifecycle
COPY toolbelt/lib/lifecycle-check.php /usr/local/share/civikitchen/lifecycle-check.php
# release helper: version consistency + the installable dist zip (docs/extension-releases.md)
COPY toolbelt/bin/ckrelease /usr/local/bin/ckrelease
# rector setup (config + CiviCRM footgun rules) + the `ckmodernize` wrapper.
COPY toolbelt/rector /opt/civikitchen-rector
COPY toolbelt/bin/ckmodernize /usr/local/bin/ckmodernize
# The phpstan installation root: pinned engine + extensions, from its lock.
COPY toolbelt/phpstan-root /opt/civikitchen-phpstan
# CiviKitchen's phpstan extension (@ck-legacy deprecated-scope resolver) —
# install-dev-tools.sh pulls it in as a composer path repository from here.
COPY toolbelt/phpstan /opt/civikitchen-phpstan-ext
# Ban lists as config (spaze/phpstan-disallowed-calls); inert until included.
COPY toolbelt/phpstan-config /opt/civikitchen-phpstan-config
# psalm taint setup (taint-only config + CiviCRM source/sink/escape stubs) +
# the `cktaint` wrapper. Advisory gate — see docs/extension-standards.md.
COPY toolbelt/psalm /opt/civikitchen-psalm
COPY toolbelt/bin/cktaint /usr/local/bin/cktaint
# Reset helper for the headless-test scratch DB (reseeds <db>_test, clears
# the per-DSN boot caches — see the script header for the failure mode).
COPY toolbelt/bin/cktestreset /usr/local/bin/cktestreset
# Core phpunit suites against the installed dist core (fetches the export-
# ignored tests/ tree for the exact installed version on first use).
COPY toolbelt/bin/ckcoretest /usr/local/bin/ckcoretest
# Smarty compile smoke: the runtime counterpart to ckconform's static template
# checks. Needs a booted site, so it drives `cv scr` with the payload below.
COPY toolbelt/bin/cksmarty /usr/local/bin/cksmarty
COPY toolbelt/lib/smarty-compile.php /usr/local/share/civikitchen/cksmarty-compile.php
# install()-vs-upgrader schema parity (used by the shared CI's schema-parity
# job; needs the mysql client this image already has).
COPY toolbelt/bin/ckschemadiff /usr/local/bin/ckschemadiff
# JS/TS lint gate: the pinned oxlint toolchain (installed by
# install-dev-tools.sh) plus the CiviKitchen baseline .oxlintrc.json, and the
# ckeslint runner that picks between it and a repo's own .oxlintrc.json.
COPY toolbelt/oxlint /opt/civikitchen-oxlint
COPY toolbelt/bin/ckeslint /usr/local/bin/ckeslint
# Formatting (ckfmt): the mago baseline tuned to the bundled phpcs standard,
# the pinned oxfmt toolchain, and the runner that picks between baseline and a
# repo's own mago.toml / .oxfmtrc.*. mago itself is installed by
# install-dev-tools.sh (per-arch binary, checksum-verified).
COPY toolbelt/mago /opt/civikitchen-mago
COPY toolbelt/oxfmt /opt/civikitchen-oxfmt
COPY toolbelt/bin/ckfmt /usr/local/bin/ckfmt
RUN CODER_REF="${CODER_REF}" \
        CIVIX_VERSION="${CIVIX_VERSION}" CIVIX_SHA256="${CIVIX_SHA256}" \
        PHPUNIT_VERSION="${PHPUNIT_VERSION}" PHPUNIT_SHA256="${PHPUNIT_SHA256}" \
        INFECTION_VERSION="${INFECTION_VERSION}" \
        MAGO_VERSION="${MAGO_VERSION}" MAGO_SHA256_X86_64="${MAGO_SHA256_X86_64}" \
        MAGO_SHA256_AARCH64="${MAGO_SHA256_AARCH64}" \
        bash /tmp/install-dev-tools.sh && rm /tmp/install-dev-tools.sh /tmp/versions.env \
    && chmod +x /usr/local/bin/cklint /usr/local/bin/ckconform /usr/local/bin/ckcoverage \
        /usr/local/bin/ckrelease /usr/local/bin/ckmodernize /usr/local/bin/cktestreset \
        /usr/local/bin/ckcoretest \
        /usr/local/bin/cktaint \
        /usr/local/bin/cksmarty /usr/local/bin/ckschemadiff /usr/local/bin/ckeslint \
        /usr/local/bin/ckfmt /usr/local/bin/ckphpunit /usr/local/bin/cklifecycle \
        /usr/local/bin/ckmutate

# CiviCRM itself, AFTER the dev tools: this is the layer a version bump
# invalidates, and everything above it is version-independent. The tarball's
# own layout provides every container path consumers rely on
# (/var/www/html/{ext,private,public}, civicrm.standalone.php).
ARG CIVICRM_VERSION
COPY --from=download --chown=www-data:www-data /tmp/civicrm/civicrm-standalone /var/www/html
RUN chown www-data:www-data /var/www
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"

# Add our vhost extras (e.g. the bare-/ → /civicrm/login redirect) via the
# standard apache conf-available + a2enconf flow.
COPY docker/standalone/apache/civikitchen.conf /etc/apache2/conf-available/civikitchen.conf
RUN a2enconf civikitchen

# Wrap cv so it runs as www-data even when invoked as root (the default for
# `docker compose exec`). Root-run cv leaves root-owned caches/locks that the
# web workers can't write. Opt out with CIVIKITCHEN_CV_AS_ROOT=1.
COPY docker/standalone/cv-wrapper.sh /usr/local/bin/cv-wrapper
RUN mv /usr/local/bin/cv /usr/local/bin/cv.real \
    && mv /usr/local/bin/cv-wrapper /usr/local/bin/cv \
    && chmod +x /usr/local/bin/cv

# Post-install demo-user script (run via `cv scr` from the entrypoint when
# CIVIKITCHEN_DEMO_USER is set). Lives outside the docroot so it's not web-served.
COPY docker/standalone/demo-user.php /usr/local/share/civikitchen/demo-user.php

# Shared xdebug toggle + provisioning library — sourced from the entrypoint,
# also used by the buildkit image so the two stay in lockstep.
COPY docker/runtime/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY docker/runtime/provision.sh /usr/local/share/civikitchen/provision.sh
COPY docker/runtime/patch-test-db-boot.php /usr/local/share/civikitchen/patch-test-db-boot.php

# Demo profiles (CIVIKITCHEN_PROFILE) — same set as the buildkit images;
# applied at first boot after the auto-install (needs an admin user, see
# docs/images.md).
COPY docker/profiles /usr/local/share/civikitchen/profiles

COPY docker/standalone/entrypoint.sh /usr/local/bin/civikitchen-entrypoint
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
