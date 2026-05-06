# CiviCRM Buildkit Development Image
#
# Used for both Drupal 10 and WordPress dev images — the only difference is
# the default civibuild site type, set via the DEFAULT_SITE_TYPE build arg.
# Pre-built CiviCRM site via buildkit for instant startup on first run.
# Requires external MariaDB (not embedded).
#
# Build context: images/  (so we can COPY ../lib/install-dev-tools.sh).
#
# Build:
#   docker build -f images/buildkit/Dockerfile \
#                --build-arg PHP_VERSION=8.3 \
#                --build-arg DEFAULT_SITE_TYPE=drupal10-demo \
#                -t civicrm-dev:drupal10 images/
#   docker build -f images/buildkit/Dockerfile \
#                --build-arg PHP_VERSION=8.3 \
#                --build-arg DEFAULT_SITE_TYPE=wp-demo \
#                -t civicrm-dev:wordpress images/
#
# Run (needs MariaDB):
#   docker run -d -p 8080:80 \
#     -e MYSQL_HOST=db -e MYSQL_ROOT_PASSWORD=root \
#     civicrm-dev:drupal10

ARG PHP_VERSION=8.3
ARG CIVICRM_VERSION=6.12.1
ARG DEFAULT_SITE_TYPE=drupal10-demo

# =============================================================================
# Stage 1: Base system with PHP, Apache, and tools
# =============================================================================
FROM ubuntu:24.04 AS base

ARG PHP_VERSION
ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    software-properties-common \
    ca-certificates \
    curl \
    git \
    unzip \
    zip \
    rsync \
    patch \
    acl \
    bzip2 \
    jq \
    && add-apt-repository ppa:ondrej/php -y \
    && curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get update \
    && apt-get install -y \
    apache2 \
    nodejs \
    php${PHP_VERSION} \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-common \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-soap \
    php${PHP_VERSION}-dev \
    libapache2-mod-php${PHP_VERSION} \
    mysql-client \
    && a2enmod rewrite ssl headers \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get purge -y software-properties-common \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# pcov: fast code-coverage driver. xdebug: installed but disabled — the
# entrypoint activates it when XDEBUG_MODE is set.
RUN pecl install pcov xdebug \
    && echo "extension=pcov.so" > /etc/php/${PHP_VERSION}/mods-available/pcov.ini \
    && echo "zend_extension=xdebug.so" > /etc/php/${PHP_VERSION}/mods-available/xdebug.ini \
    && phpenmod pcov

# Shared dev-tools install (civix, phpunit, phpstan, phpcs+civicrm-coder).
# Lives in images/lib/ so the standalone image uses the same script.
ENV PATH="/opt/composer/vendor/bin:${PATH}"
COPY lib/install-dev-tools.sh /tmp/install-dev-tools.sh
RUN bash /tmp/install-dev-tools.sh && rm /tmp/install-dev-tools.sh

# Configure PHP
RUN sed -i -e 's/upload_max_filesize = .*/upload_max_filesize = 64M/' \
           -e 's/post_max_size = .*/post_max_size = 64M/' \
           -e 's/memory_limit = .*/memory_limit = 512M/' \
           -e 's/max_execution_time = .*/max_execution_time = 300/' \
           /etc/php/${PHP_VERSION}/apache2/php.ini

# Create buildkit user
RUN useradd -m -s /bin/bash buildkit \
    && mkdir -p /var/log/apache2 /var/run/apache2 \
    && chown -R buildkit:buildkit /var/log/apache2 /var/run/apache2

# Configure Apache
RUN sed -i 's/export APACHE_RUN_USER=www-data/export APACHE_RUN_USER=buildkit/' /etc/apache2/envvars \
    && sed -i 's/export APACHE_RUN_GROUP=www-data/export APACHE_RUN_GROUP=buildkit/' /etc/apache2/envvars \
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

COPY buildkit/entrypoint.sh /home/buildkit/entrypoint.sh

USER root
RUN chmod +x /home/buildkit/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
