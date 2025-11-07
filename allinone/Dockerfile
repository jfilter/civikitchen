# Multi-Stage Dockerfile for All-In-One CiviCRM EU-NGO Image
# This builds a fully configured, ready-to-run CiviCRM + Drupal 10 + all EU NGO extensions
# with embedded MariaDB for single-container deployment

# Build arguments
ARG PHP_VERSION=8.2
ARG CIVICRM_VERSION=6.7.1
ARG CIVICRM_SITE_TYPE=drupal10-demo

# =============================================================================
# Stage 1: Base System with All Dependencies
# =============================================================================
FROM ubuntu:22.04 AS base

ARG PHP_VERSION
ARG DEBIAN_FRONTEND=noninteractive

# Install system packages and repositories
RUN apt-get update && apt-get install -y \
    software-properties-common \
    ca-certificates \
    gnupg \
    curl \
    wget \
    git \
    unzip \
    zip \
    sudo \
    rsync \
    patch \
    acl \
    bzip2 \
    jq \
    && add-apt-repository ppa:ondrej/php -y \
    && curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get update

# Install Apache
RUN apt-get install -y \
    apache2 \
    && a2enmod rewrite ssl headers \
    && rm -rf /var/lib/apt/lists/*

# Install PHP with all required extensions
RUN apt-get update && apt-get install -y \
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
    libapache2-mod-php${PHP_VERSION} \
    && rm -rf /var/lib/apt/lists/*

# Install MariaDB Server (embedded)
RUN apt-get update && apt-get install -y \
    mariadb-server \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js and Composer
RUN apt-get update && apt-get install -y \
    nodejs \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

# Configure PHP
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 64M/' /etc/php/${PHP_VERSION}/apache2/php.ini \
    && sed -i 's/post_max_size = .*/post_max_size = 64M/' /etc/php/${PHP_VERSION}/apache2/php.ini \
    && sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/${PHP_VERSION}/apache2/php.ini \
    && sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/${PHP_VERSION}/apache2/php.ini \
    && sed -i 's/upload_max_filesize = .*/upload_max_filesize = 64M/' /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i 's/post_max_size = .*/post_max_size = 64M/' /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/${PHP_VERSION}/cli/php.ini

# Create buildkit user
RUN useradd -m -s /bin/bash buildkit \
    && echo "buildkit ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers \
    && mkdir -p /var/log/apache2 /var/run/apache2 \
    && chown -R buildkit:buildkit /var/log/apache2 /var/run/apache2

# Configure Apache to run as buildkit user
RUN sed -i 's/export APACHE_RUN_USER=www-data/export APACHE_RUN_USER=buildkit/' /etc/apache2/envvars \
    && sed -i 's/export APACHE_RUN_GROUP=www-data/export APACHE_RUN_GROUP=buildkit/' /etc/apache2/envvars

# Configure Apache for the site
RUN echo "<VirtualHost *:80>" > /etc/apache2/sites-available/civicrm.conf \
    && echo "    ServerName localhost" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "    DocumentRoot /home/buildkit/buildkit/build/site/web" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "    <Directory /home/buildkit/buildkit/build/site/web>" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "        Options Indexes FollowSymLinks MultiViews" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "        AllowOverride All" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "        Require all granted" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "    </Directory>" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "    ErrorLog /var/log/apache2/error.log" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "    CustomLog /var/log/apache2/access.log combined" >> /etc/apache2/sites-available/civicrm.conf \
    && echo "</VirtualHost>" >> /etc/apache2/sites-available/civicrm.conf \
    && a2dissite 000-default.conf \
    && a2ensite civicrm.conf

# =============================================================================
# Stage 2: Install Buildkit
# =============================================================================
FROM base AS buildkit-setup

USER buildkit
WORKDIR /home/buildkit

# Clone and install buildkit
RUN git clone https://github.com/civicrm/civicrm-buildkit.git buildkit \
    && cd buildkit \
    && ./bin/civi-download-tools \
    && echo 'export PATH=/home/buildkit/buildkit/bin:$PATH' >> /home/buildkit/.bashrc

# Set PATH for buildkit tools
ENV PATH="/home/buildkit/buildkit/bin:${PATH}"

# =============================================================================
# Stage 3: Setup Extensions
# =============================================================================
FROM buildkit-setup AS extensions-setup

USER buildkit
WORKDIR /home/buildkit

# Copy stack configuration
RUN mkdir -p /home/buildkit/stacks
COPY --chown=buildkit:buildkit stacks/eu-nonprofit/civikitchen.json /home/buildkit/stacks/eu-nonprofit/civikitchen.json

# =============================================================================
# Stage 4: Final Image (Runtime Setup)
# =============================================================================
FROM extensions-setup AS final

USER root

# Copy scripts and entrypoint
COPY --chown=buildkit:buildkit scripts /home/buildkit/scripts
COPY --chown=buildkit:buildkit allinone/entrypoint-allinone.sh /home/buildkit/entrypoint.sh
RUN chmod +x /home/buildkit/entrypoint.sh \
    && chmod +x /home/buildkit/scripts/lib/*.sh

# Environment variables
ENV PATH="/home/buildkit/buildkit/bin:${PATH}"
ENV CIVIBUILD_ADMIN_PASS="admin"
ENV CIVICRM_SITE_TYPE="drupal10-demo"
ENV CIVICRM_VERSION="6.7.1"

# Expose HTTP port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Set entrypoint
ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
