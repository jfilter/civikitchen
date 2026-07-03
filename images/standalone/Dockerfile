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
# - nodejs/npm: required by extensions with Angular/JS assets
# - jq: the profile driver (CIVIKITCHEN_PROFILE) parses profile.json with it
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip default-mysql-client jq \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
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
# PHPSTAN_VERSION / CODER_REF / RECTOR_VERSION are pinned in the script; pass
# --build-arg to override (e.g. to test a newer phpstan before bumping the
# default).
ENV PATH="/opt/composer/vendor/bin:${PATH}"
ARG PHPSTAN_VERSION
ARG CODER_REF
ARG RECTOR_VERSION
COPY lib/install-dev-tools.sh /tmp/install-dev-tools.sh
# The bundled CiviKitchen phpcs standard (registered into installed_paths by
# install-dev-tools.sh) and the cklint linter that runs it.
COPY coder/CiviKitchen /opt/civikitchen-coder/CiviKitchen
COPY lib/cklint /usr/local/bin/cklint
# rector setup (config + CiviCRM footgun rules) + the `ckmodernize` wrapper.
COPY lib/civikitchen-rector /opt/civikitchen-rector
COPY lib/ckmodernize /usr/local/bin/ckmodernize
# Reset helper for the headless-test scratch DB (reseeds <db>_test, clears
# the per-DSN boot caches — see the script header for the failure mode).
COPY lib/cktestreset /usr/local/bin/cktestreset
RUN PHPSTAN_VERSION="${PHPSTAN_VERSION}" CODER_REF="${CODER_REF}" RECTOR_VERSION="${RECTOR_VERSION}" \
        bash /tmp/install-dev-tools.sh && rm /tmp/install-dev-tools.sh \
    && chmod +x /usr/local/bin/cklint /usr/local/bin/ckmodernize /usr/local/bin/cktestreset

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
