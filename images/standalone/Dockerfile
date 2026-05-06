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
#       image: ghcr.io/jfilter/civicrm-dev:standalone

ARG CIVICRM_VERSION=latest
FROM civicrm/civicrm:${CIVICRM_VERSION}

# OS-level deps:
# - unzip/git: composer needs them for dist/source installs
# - default-mysql-client: for poking the DB from inside the container
# - nodejs/npm: required by extensions with Angular/JS assets
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip default-mysql-client \
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

# Shared dev-tools install (civix, phpunit, phpstan, phpcs+civicrm-coder).
# Lives in images/lib/ so the buildkit image uses the same script.
ENV PATH="/opt/composer/vendor/bin:${PATH}"
COPY lib/install-dev-tools.sh /tmp/install-dev-tools.sh
RUN bash /tmp/install-dev-tools.sh && rm /tmp/install-dev-tools.sh

# Add our vhost extras (e.g. the bare-/ → /civicrm/login redirect) via the
# standard apache conf-available + a2enconf flow rather than editing the
# upstream image's civicrm.conf.
COPY standalone/apache/civikitchen.conf /etc/apache2/conf-available/civikitchen.conf
RUN a2enconf civikitchen

COPY standalone/entrypoint.sh /usr/local/bin/civikitchen-entrypoint
RUN chmod +x /usr/local/bin/civikitchen-entrypoint

ENTRYPOINT ["/usr/local/bin/civikitchen-entrypoint"]
CMD ["apache2-foreground"]
