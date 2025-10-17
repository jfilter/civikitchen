FROM ubuntu:22.04

# Build arguments for version configuration
ARG PHP_VERSION=8.2

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV PHP_VERSION=${PHP_VERSION}

# Install system dependencies
RUN apt-get update && apt-get install -y \
    software-properties-common \
    ca-certificates \
    lsb-release \
    apt-transport-https \
    wget \
    curl \
    git \
    unzip \
    zip \
    sudo \
    nano \
    vim \
    && add-apt-repository ppa:ondrej/php -y \
    && apt-get update

# Install Apache
RUN apt-get install -y \
    apache2 \
    && a2enmod rewrite \
    && a2enmod ssl \
    && a2enmod headers

# Install PHP and required extensions (version configured via build arg)
# Supported versions: 8.1, 8.2, 8.3, 8.4
RUN apt-get install -y \
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
    libapache2-mod-php${PHP_VERSION}

# Install MySQL client
RUN apt-get install -y \
    mysql-client \
    default-mysql-client

# Install Node.js 18.x and npm
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install additional tools
RUN apt-get install -y \
    rsync \
    patch \
    acl \
    bzip2

# Clean up
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Create buildkit user with sudo access
RUN useradd -m -s /bin/bash buildkit \
    && echo "buildkit ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers \
    && mkdir -p /var/www/html \
    && chown -R buildkit:buildkit /var/www/html

# Configure Apache to run as buildkit user
RUN sed -i 's/export APACHE_RUN_USER=www-data/export APACHE_RUN_USER=buildkit/' /etc/apache2/envvars \
    && sed -i 's/export APACHE_RUN_GROUP=www-data/export APACHE_RUN_GROUP=buildkit/' /etc/apache2/envvars \
    && chown -R buildkit:buildkit /var/log/apache2 \
    && chown -R buildkit:buildkit /var/run/apache2

# Configure PHP
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 64M/' /etc/php/${PHP_VERSION}/apache2/php.ini \
    && sed -i 's/post_max_size = .*/post_max_size = 64M/' /etc/php/${PHP_VERSION}/apache2/php.ini \
    && sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/${PHP_VERSION}/apache2/php.ini \
    && sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/${PHP_VERSION}/apache2/php.ini

# Set buildkit PATH globally
ENV PATH="/home/buildkit/buildkit/bin:${PATH}"

# Set working directory
WORKDIR /home/buildkit

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Switch to buildkit user
USER buildkit

# Expose Apache port
EXPOSE 80

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
