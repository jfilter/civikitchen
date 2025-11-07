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
    && npm install -g npm@latest \
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

# =============================================================================
# Stage 2: Install and Configure Buildkit
# =============================================================================
FROM base AS buildkit-install

USER buildkit
WORKDIR /home/buildkit

# Clone and install buildkit
RUN git clone https://github.com/civicrm/civicrm-buildkit.git buildkit \
    && cd buildkit \
    && ./bin/civi-download-tools \
    && echo 'export PATH=/home/buildkit/buildkit/bin:$PATH' >> /home/buildkit/.bashrc

# Configure AMP
RUN mkdir -p /home/buildkit/.amp \
    && echo "mysql:" > /home/buildkit/.amp/apache-vhost-default.yml \
    && echo "  dsn: 'mysql://root:root@127.0.0.1:3306'" >> /home/buildkit/.amp/apache-vhost-default.yml \
    && echo "  dsn: 'mysql://root:root@127.0.0.1:3306'" > /home/buildkit/.amp/my.cnf.yml

# Create MySQL config for buildkit user
RUN echo "[client]" > /home/buildkit/.my.cnf \
    && echo "user=root" >> /home/buildkit/.my.cnf \
    && echo "password=root" >> /home/buildkit/.my.cnf \
    && echo "host=127.0.0.1" >> /home/buildkit/.my.cnf

# =============================================================================
# Stage 3: Download All EU-NGO Extensions
# =============================================================================
FROM buildkit-install AS extensions-download

USER buildkit
WORKDIR /home/buildkit

# Create extensions directory
RUN mkdir -p /home/buildkit/extensions

# Copy extension configuration
COPY --chown=buildkit:buildkit extensions/eu-nonprofit/civikitchen.json /home/buildkit/extensions/eu-nonprofit/civikitchen.json

# Clone all 9 EU-NGO extensions
RUN mkdir -p /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext \
    && cd /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext \
    && git clone https://github.com/Project60/org.project60.banking.git \
    && cd org.project60.banking && git checkout 0.7.5 && cd .. \
    && git clone https://github.com/Project60/org.project60.sepa.git \
    && cd org.project60.sepa && git checkout v1.5.2 && cd .. \
    && git clone https://github.com/systopia/de.systopia.contract.git \
    && git clone https://github.com/systopia/de.systopia.twingle.git \
    && git clone https://github.com/systopia/de.systopia.gdprx.git \
    && git clone https://github.com/systopia/de.systopia.xcm.git \
    && git clone https://github.com/systopia/de.systopia.identitytracker.git \
    && git clone https://github.com/civicrm/org.civicrm.shoreditch.git \
    && git clone https://lab.civicrm.org/extensions/contactlayout.git org.civicrm.contactlayout

# =============================================================================
# Stage 4: Create CiviCRM Site with MariaDB
# =============================================================================
FROM extensions-download AS site-build

USER root

# Initialize MariaDB and create database
RUN service mariadb start \
    && sleep 5 \
    && mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'root';" \
    && mysql -u root -proot -e "CREATE DATABASE IF NOT EXISTS civicrm;" \
    && mysql -u root -proot -e "GRANT ALL PRIVILEGES ON civicrm.* TO 'civicrm'@'localhost' IDENTIFIED BY 'civicrm';" \
    && mysql -u root -proot -e "FLUSH PRIVILEGES;"

USER buildkit
WORKDIR /home/buildkit

# Set environment variables for site creation
ENV PATH="/home/buildkit/buildkit/bin:${PATH}"
ENV CIVIBUILD_ADMIN_PASS="admin"
ENV CIVICRM_SITE_TYPE="drupal10-demo"
ENV CIVICRM_VERSION="6.7.1"

# Create CiviCRM site with buildkit
RUN service mariadb start \
    && sleep 5 \
    && cd /home/buildkit/buildkit \
    && ./bin/civibuild create site \
        --type ${CIVICRM_SITE_TYPE} \
        --civi-ver ${CIVICRM_VERSION} \
        --url http://localhost:80 \
        --admin-pass ${CIVIBUILD_ADMIN_PASS} \
    && cd /home/buildkit/buildkit/build/site/web \
    && chmod -R 755 sites/default/files

# =============================================================================
# Stage 5: Enable and Seed Extensions
# =============================================================================
FROM site-build AS extensions-seed

USER root
COPY --chown=buildkit:buildkit scripts /home/buildkit/scripts

USER buildkit
WORKDIR /home/buildkit/buildkit/build/site/web

# Enable all extensions
RUN service mariadb start \
    && sleep 5 \
    && /home/buildkit/buildkit/bin/cv ext:enable org.project60.banking org.project60.sepa \
    && /home/buildkit/buildkit/bin/cv ext:enable de.systopia.contract de.systopia.twingle \
    && /home/buildkit/buildkit/bin/cv ext:enable de.systopia.gdprx de.systopia.xcm \
    && /home/buildkit/buildkit/bin/cv ext:enable de.systopia.identitytracker \
    && /home/buildkit/buildkit/bin/cv ext:enable org.civicrm.shoreditch org.civicrm.contactlayout \
    && /home/buildkit/buildkit/bin/cv flush

# Run seeding for all extensions
RUN service mariadb start \
    && sleep 5 \
    && bash /home/buildkit/scripts/lib/seeds/org.project60.banking.sh || true \
    && bash /home/buildkit/scripts/lib/seeds/org.project60.sepa.sh || true \
    && bash /home/buildkit/scripts/lib/seeds/de.systopia.contract.sh || true \
    && bash /home/buildkit/scripts/lib/seeds/de.systopia.twingle.sh || true \
    && bash /home/buildkit/scripts/lib/seeds/de.systopia.gdprx.sh || true \
    && bash /home/buildkit/scripts/lib/seeds/de.systopia.xcm.sh || true \
    && bash /home/buildkit/scripts/lib/seeds/de.systopia.identitytracker.sh || true \
    && bash /home/buildkit/scripts/lib/seeds/org.civicrm.shoreditch.sh || true \
    && /home/buildkit/buildkit/bin/cv flush

# =============================================================================
# Stage 6: Final Production Image
# =============================================================================
FROM base AS final

# Copy buildkit and site from build stages
COPY --from=extensions-seed --chown=buildkit:buildkit /home/buildkit /home/buildkit

# Copy MariaDB data
COPY --from=extensions-seed /var/lib/mysql /var/lib/mysql

# Copy scripts
COPY --chown=buildkit:buildkit scripts /home/buildkit/scripts
COPY --chown=buildkit:buildkit allinone/entrypoint.sh /home/buildkit/entrypoint.sh
RUN chmod +x /home/buildkit/entrypoint.sh

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

# Set proper permissions
RUN chown -R buildkit:buildkit /home/buildkit \
    && chown -R mysql:mysql /var/lib/mysql \
    && chmod 755 /var/lib/mysql

# Expose HTTP port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Set entrypoint
ENTRYPOINT ["/home/buildkit/entrypoint.sh"]
