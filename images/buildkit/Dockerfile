# CiviCRM Buildkit Development Image
#
# Used for Drupal 10, Drupal 11, WordPress, and Joomla dev images — the only
# difference is the default civibuild site type, set via the DEFAULT_SITE_TYPE
# build arg.
# Pre-built CiviCRM site via buildkit for instant startup on first run.
# Requires external MariaDB (not embedded).
#
# Base image: php:${PHP_VERSION}-apache (same family as standalone, which
# inherits from civicrm/civicrm → php:apache). Keeps PHP-config paths and
# extension-install flow consistent across both images.
#
# Build context: images/  (so we can COPY ../lib/install-dev-tools.sh).
#
# Build:
#   docker build -f images/buildkit/Dockerfile \
#                --build-arg PHP_VERSION=8.3 \
#                --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
#                -t civikitchen:drupal10 images/
#   docker build -f images/buildkit/Dockerfile \
#                --build-arg PHP_VERSION=8.3 \
#                --build-arg DEFAULT_SITE_TYPE=drupal11-dev \
#                -t civikitchen:drupal11 images/
#   docker build -f images/buildkit/Dockerfile \
#                --build-arg PHP_VERSION=8.3 \
#                --build-arg DEFAULT_SITE_TYPE=wp-demo \
#                -t civikitchen:wordpress images/
#   docker build -f images/buildkit/Dockerfile \
#                --build-arg PHP_VERSION=8.3 \
#                --build-arg DEFAULT_SITE_TYPE=joomla-demo \
#                -t civikitchen:joomla images/
#
# Run (needs MariaDB):
#   docker run -d -p 8080:80 \
#     -e CIVICRM_DB_HOST=db -e CIVICRM_DB_ROOT_PASSWORD=root \
#     civikitchen:drupal10

ARG PHP_VERSION=8.3
# Local-build fallback only: CI resolves the current stable from
# latest.civicrm.org and passes it as --build-arg CIVICRM_VERSION.
ARG CIVICRM_VERSION=6.15.1
ARG DEFAULT_SITE_TYPE=drupal10-demo

# =============================================================================
# Stage 1: Base system with PHP, Apache, and tools
# =============================================================================
# Pin to -bookworm (Debian 12) explicitly. Trixie (Debian 13, the rolling
# default) ships mariadb-client 11.8 which enforces TLS by default and breaks
# every dev MariaDB sidecar that doesn't have a cert configured (drush, amp,
# civibuild all fail with "ERROR 2026 ... SSL is required, but the server
# does not support it"). Bookworm's mariadb-client is 10.11 — same as the
# civicrm/civicrm:latest base of the standalone image, so both images now
# sit on the same Debian release. Revisit when MariaDB 11.4+ servers are
# the norm.
FROM php:${PHP_VERSION}-apache-bookworm AS base

ARG DEBIAN_FRONTEND=noninteractive

# OS-level deps. The php:apache base ships PHP + Apache; we add:
# - git/unzip/zip: composer source/dist installs
# - rsync/patch/acl/bzip2/jq: civibuild and its site templates use these
# - default-mysql-client: civibuild + entrypoint DB probe
# - lib*-dev: headers needed by docker-php-ext-install for gd/intl/zip/soap
# - nodejs: extensions with Angular/JS assets
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip rsync patch acl bzip2 jq \
        default-mysql-client \
        libpng-dev libjpeg-dev libfreetype6-dev \
        libicu-dev libxml2-dev libzip-dev libonig-dev \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd intl zip bcmath soap opcache \
    && a2enmod rewrite ssl headers \
    && curl -sS https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# pcov: fast code-coverage driver (always on). xdebug: installed but disabled
# — entrypoint enables it when XDEBUG_MODE is set. Same approach as standalone.
RUN pecl install pcov xdebug \
    && docker-php-ext-enable pcov

# Shared dev-tools install (civix, phpunit, phpstan, phpcs+civicrm-coder,
# rector/ckmodernize).
# Lives in images/lib/ so the standalone image uses the same script.
# PHPSTAN_VERSION / CODER_REF / RECTOR_VERSION are pinned in the script; pass
# --build-arg to override.
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
RUN PHPSTAN_VERSION="${PHPSTAN_VERSION}" CODER_REF="${CODER_REF}" RECTOR_VERSION="${RECTOR_VERSION}" \
        bash /tmp/install-dev-tools.sh && rm /tmp/install-dev-tools.sh \
    && chmod +x /usr/local/bin/cklint /usr/local/bin/ckmodernize

# PHP runtime tuning — matches what civibuild expects for site builds.
RUN { \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/civikitchen.ini

# Joomla CLI bootstrap shim (see the file header). Inert on every other flavor
# (CLI + CIVICRM_UF==Joomla guarded), so it ships on all buildkit images. The
# civibuild site settings auto-load /etc/civicrm.settings.d/post.d/*.php, which
# is what makes cv/civibuild/phpunit able to boot CiviCRM on Joomla at all —
# during the build-time bake (builder stage) and at runtime (demo + final).
COPY buildkit/joomla-cli-bootstrap.php /etc/civicrm.settings.d/post.d/000-joomla-cli-bootstrap.php

# buildkit user owns the site tree so civibuild can write to it. Apache is
# reconfigured to run as buildkit so the served files match the writer's uid
# — otherwise Civi's runtime cache writes (templates_c, files/civicrm) from
# Apache fail with "does not have permission to write temp files".
#
# The php:apache envvars file uses POSIX default-assignment syntax
# (: ${APACHE_RUN_USER:=www-data}), so an `ENV` here wins over the default
# without the in-place sed dance Ubuntu's apache2 package required.
ENV APACHE_RUN_USER=buildkit \
    APACHE_RUN_GROUP=buildkit
RUN useradd -m -s /bin/bash buildkit \
    && chown -R buildkit:buildkit /var/log/apache2 /var/run/apache2 \
    && printf '<VirtualHost *:80>\n\
    ServerName localhost\n\
    DocumentRoot /home/buildkit/buildkit/build/site/web\n\
    <Directory /home/buildkit/buildkit/build/site/web>\n\
        Options Indexes FollowSymLinks MultiViews\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog /var/log/apache2/error.log\n\
    CustomLog /var/log/apache2/access.log combined\n\
</VirtualHost>\n' > /etc/apache2/sites-available/civicrm.conf \
    && a2dissite 000-default.conf \
    && a2ensite civicrm.conf

# =============================================================================
# Stage 2: Install buildkit
# =============================================================================
FROM base AS buildkit-setup

ARG CIVICRM_VERSION

USER buildkit
WORKDIR /home/buildkit

# Clone buildkit (remove .git to save space)
RUN git clone https://github.com/civicrm/civicrm-buildkit.git buildkit \
    && rm -rf buildkit/.git \
    && cd buildkit \
    && ./bin/civi-download-tools

ENV PATH="/home/buildkit/buildkit/bin:${PATH}"

# =============================================================================
# Stage 3: Bake the civibuild site at BUILD time (the slow part)
# =============================================================================
# civibuild downloads the CMS + CiviCRM (~3-4 min) and installs a site. We run
# it HERE against a THROWAWAY MariaDB started just for this layer, so the heavy
# codebase is baked into the image. The DB data (/var/lib/mysql) is NOT carried
# into the final image — at runtime the entrypoint does a fast `civibuild
# reinstall` (~60s) against the external DB instead of a full create.
FROM buildkit-setup AS builder

ARG CIVICRM_VERSION
ARG CIVICRM_BUILD_VERSION
ARG DEFAULT_SITE_TYPE
# KEEP_GIT=1 keeps the ~550MB of git history civibuild clones into the site
# (vendor/civicrm/civicrm-core etc.). Default strips it — the published images
# target extension development; build your own image to work on core.
ARG KEEP_GIT=0

# procps provides pgrep, which bake.sh uses to verify the clean DB shutdown.
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-server procps \
    && rm -rf /var/lib/apt/lists/*

# Bake the site (logic in buildkit/bake.sh to keep the shell quoting sane).
# joomla-enable-plugins.php and ext/ (the ckjoomlaidentity extension) are used
# by bake.sh's joomla-demo path.
COPY buildkit/bake.sh /tmp/bake.sh
COPY buildkit/joomla-enable-plugins.php /tmp/joomla-enable-plugins.php
COPY buildkit/ext /tmp/ck-ext
RUN CIVICRM_VERSION="${CIVICRM_VERSION}" CIVICRM_BUILD_VERSION="${CIVICRM_BUILD_VERSION}" \
        DEFAULT_SITE_TYPE="${DEFAULT_SITE_TYPE}" \
        KEEP_GIT="${KEEP_GIT}" \
        bash /tmp/bake.sh && rm -rf /tmp/bake.sh /tmp/joomla-enable-plugins.php /tmp/ck-ext

# =============================================================================
# Stage 4: Demo image — single-container, embedded MariaDB, baked demo data
# =============================================================================
# Reuses the `builder` stage's baked civibuild site AND its populated
# /var/lib/mysql (which the dev `final` stage discards via a selective COPY).
# The demo therefore boots in seconds: start the embedded MariaDB on the baked
# data + Apache — no external DB, no `civibuild reinstall`. `docker run -p 80:80
# IMAGE` → a working CiviCRM with demo data. Built with `--target demo`.
#
# Profiles (extensions + seeds + API users, e.g. eu-ngo) are NOT baked — they
# apply at first boot via `-e CIVIKITCHEN_PROFILE=<name>` (see images/profiles/
# and provision.sh's ck_apply_profile). That keeps one image per CMS flavor.
FROM builder AS demo

ARG DEFAULT_SITE_TYPE
ARG CIVICRM_VERSION
# Which CiviCRM the image bakes, readable via `docker inspect` without booting.
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"
ENV PATH="/opt/composer/vendor/bin:/home/buildkit/buildkit/bin:${PATH}" \
    CIVIBUILD_ADMIN_PASS="admin" \
    CIVICRM_SITE_TYPE_DEFAULT="${DEFAULT_SITE_TYPE}"

USER root

# Standalone serves CiviCRM directly: anonymous `/` (→ /civicrm/home) returns
# 403 under the standaloneusers extension, so bounce bare-/ to the login form
# (the same rule the standalone dev image ships). drupal10/wp serve their CMS
# front page at `/`, so only add the redirect for the standalone flavor.
RUN if [ "${DEFAULT_SITE_TYPE}" = "standalone-demo" ]; then \
        echo 'RedirectMatch 302 ^/$ /civicrm/login' \
            > /etc/apache2/conf-available/civikitchen-demo.conf \
        && a2enconf civikitchen-demo; \
    fi

# Shared xdebug toggle + provisioning library — same files the dev images use.
# The demo runs provision.sh too, so its opt-in knobs (SMTP, mounted-extension
# enabling, CIVIKITCHEN_PROFILE, /civikitchen-init.d hooks) work here as well.
COPY lib/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY lib/provision.sh /usr/local/share/civikitchen/provision.sh
COPY buildkit/entrypoint-common.sh /usr/local/share/civikitchen/entrypoint-common.sh
COPY profiles /usr/local/share/civikitchen/profiles

COPY buildkit/entrypoint-demo.sh /home/buildkit/entrypoint.sh
RUN chmod +x /home/buildkit/entrypoint.sh

EXPOSE 80

# The site + DB are baked, so a plain first boot is just "start embedded
# MariaDB + provisioning + Apache" — fast. With CIVIKITCHEN_PROFILE set, first
# boot also clones + enables + seeds the profile (minutes, needs network) —
# hence the generous start period. The entrypoint writes the provisioned
# marker before handing off to Apache.
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /home/buildkit/.civikitchen-provisioned || exit 1

ENTRYPOINT ["/home/buildkit/entrypoint.sh"]

# =============================================================================
# Stage 5: Final dev image — copies the baked site, NO embedded DB (default)
# =============================================================================
# This is the LAST stage, so a plain `docker build` (no --target) builds the dev
# image; the demo above is opt-in via `--target demo`.
#
# FROM base (not buildkit-setup) on purpose: the COPY --from=builder below
# brings the WHOLE of /home/buildkit (buildkit tools + baked site). Inheriting
# buildkit-setup would mean its ~200MB buildkit-clone layer ships shadowed
# underneath that COPY, inflating the image for nothing.
FROM base AS final

# /opt/composer/vendor/bin is prepended ahead of /home/buildkit/buildkit/bin
# so the dev-tools phpcs (with the civicrm/coder Drupal standard registered)
# wins over the plain phpcs that civi-download-tools installs into buildkit's
# own bin dir.
ARG DEFAULT_SITE_TYPE
ARG CIVICRM_VERSION
# Which CiviCRM the image bakes, readable via `docker inspect` without booting.
LABEL org.opencontainers.image.version="${CIVICRM_VERSION}"
ENV PATH="/opt/composer/vendor/bin:/home/buildkit/buildkit/bin:${PATH}" \
    CIVIBUILD_ADMIN_PASS="admin" \
    CIVICRM_SITE_TYPE_DEFAULT="${DEFAULT_SITE_TYPE}"

USER root
# Parity with the buildkit-setup-derived stages (base leaves /var/www/html).
WORKDIR /home/buildkit

# Bake: bring in the civibuild site tree + civibuild/amp registry built in the
# `builder` stage. This is the heavy part (CMS + CiviCRM codebase) — having it
# here means runtime only needs a fast `civibuild reinstall`. The builder's
# throwaway MariaDB data is left behind (not copied). The baked ~/.my.cnf points
# at the build-time localhost DB; the entrypoint overwrites it for the runtime
# external DB.
COPY --from=builder --chown=buildkit:buildkit /home/buildkit /home/buildkit

# Shared xdebug toggle + provisioning library — sourced from the entrypoint,
# also used by the standalone image so the two stay in lockstep. Profiles ship
# here too: CIVIKITCHEN_PROFILE works on the dev images as well (applied after
# the first-boot `civibuild reinstall`).
COPY lib/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh
COPY lib/provision.sh /usr/local/share/civikitchen/provision.sh
COPY buildkit/entrypoint-common.sh /usr/local/share/civikitchen/entrypoint-common.sh
COPY profiles /usr/local/share/civikitchen/profiles

COPY buildkit/entrypoint.sh /home/buildkit/entrypoint.sh
RUN chmod +x /home/buildkit/entrypoint.sh

EXPOSE 80

# Readiness signal: the entrypoint writes this marker after the first-boot
# install + provisioning complete, before handing off to Apache. The site
# codebase is baked, so first boot is a fast `civibuild reinstall` (~60s) +
# provisioning — but a CIVIKITCHEN_PROFILE apply on top (git clones + seeds)
# can take minutes, hence the generous start period.
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=18 \
    CMD test -f /home/buildkit/.civikitchen-provisioned || exit 1

ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
