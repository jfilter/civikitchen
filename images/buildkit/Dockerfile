# CiviCRM Buildkit Development Image
#
# Used for both Drupal 10 and WordPress dev images — the only difference is
# the default civibuild site type, set via the DEFAULT_SITE_TYPE build arg.
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
#                --build-arg DEFAULT_SITE_TYPE=wp-demo \
#                -t civikitchen:wordpress images/
#
# Run (needs MariaDB):
#   docker run -d -p 8080:80 \
#     -e CIVICRM_DB_HOST=db -e CIVICRM_DB_ROOT_PASSWORD=root \
#     civikitchen:drupal10

ARG PHP_VERSION=8.3
ARG CIVICRM_VERSION=6.12.1
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

# Shared dev-tools install (civix, phpunit, phpstan, phpcs+civicrm-coder).
# Lives in images/lib/ so the standalone image uses the same script.
ENV PATH="/opt/composer/vendor/bin:${PATH}"
COPY lib/install-dev-tools.sh /tmp/install-dev-tools.sh
RUN bash /tmp/install-dev-tools.sh && rm /tmp/install-dev-tools.sh

# PHP runtime tuning — matches what civibuild expects for site builds.
RUN { \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/civikitchen.ini

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
# Stage 3: Final image
# =============================================================================
FROM buildkit-setup AS final

# /opt/composer/vendor/bin is prepended ahead of /home/buildkit/buildkit/bin
# so the dev-tools phpcs (with the civicrm/coder Drupal standard registered)
# wins over the plain phpcs that civi-download-tools installs into buildkit's
# own bin dir.
ARG DEFAULT_SITE_TYPE
ENV PATH="/opt/composer/vendor/bin:/home/buildkit/buildkit/bin:${PATH}" \
    CIVIBUILD_ADMIN_PASS="admin" \
    CIVICRM_SITE_TYPE_DEFAULT="${DEFAULT_SITE_TYPE}"

USER root

# Shared xdebug toggle — sourced from the entrypoint, also used by the
# standalone image so the two stay byte-identical.
COPY lib/xdebug-toggle.sh /usr/local/share/civikitchen/xdebug-toggle.sh

COPY buildkit/entrypoint.sh /home/buildkit/entrypoint.sh
RUN chmod +x /home/buildkit/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
