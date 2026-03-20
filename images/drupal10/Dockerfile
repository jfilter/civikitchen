# CiviCRM Drupal 10 Development Image
# Pre-built Drupal 10 + CiviCRM site via buildkit for instant startup.
# Requires external MariaDB (not embedded).
#
# Build:
#   docker build --build-arg PHP_VERSION=8.3 -t civicrm-dev:drupal10 images/drupal10/
#
# Run (needs MariaDB):
#   docker run -d -p 8080:80 \
#     -e MYSQL_HOST=db -e MYSQL_ROOT_PASSWORD=root \
#     civicrm-dev:drupal10

ARG PHP_VERSION=8.3
ARG CIVICRM_VERSION=6.7.1

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

# Install dev tools (pcov, civix, phpunit, phpstan)
RUN pecl install pcov && docker-php-ext-enable pcov \
    && curl -LsS https://download.civicrm.org/civix/civix.phar -o /usr/local/bin/civix && chmod +x /usr/local/bin/civix \
    && curl -LsS https://phar.phpunit.de/phpunit-9.phar -o /usr/local/bin/phpunit && chmod +x /usr/local/bin/phpunit \
    && curl -LsS https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar -o /usr/local/bin/phpstan && chmod +x /usr/local/bin/phpstan

# Configure PHP
RUN sed -i -e 's/upload_max_filesize = .*/upload_max_filesize = 64M/' \
           -e 's/post_max_size = .*/post_max_size = 64M/' \
           -e 's/memory_limit = .*/memory_limit = 512M/' \
           -e 's/max_execution_time = .*/max_execution_time = 300/' \
           /etc/php/${PHP_VERSION}/apache2/php.ini

# Create buildkit user
RUN useradd -m -s /bin/bash buildkit \
    && echo "buildkit ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers \
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
# Stage 2: Install buildkit and create Drupal 10 site
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

ENV PATH="/home/buildkit/buildkit/bin:${PATH}"
ENV CIVIBUILD_ADMIN_PASS="admin"

COPY entrypoint.sh /home/buildkit/entrypoint.sh

USER root
RUN chmod +x /home/buildkit/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
