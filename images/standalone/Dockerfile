# CiviCRM Standalone Development Image
# Adds dev tools (coverage, testing, static analysis) to the official CiviCRM image.
#
# Usage:
#   docker build -t civicrm-dev:standalone images/standalone/
#
# In your extension's .docker/compose.yaml:
#   services:
#     app:
#       image: ghcr.io/jfilter/civicrm-dev:standalone
#       # ... volumes, env, etc.

FROM civicrm/civicrm:latest

# Install pcov for fast code coverage (replaces xdebug for coverage-only use)
RUN pecl install pcov && docker-php-ext-enable pcov

# Install development tools:
# - civix: CiviCRM extension scaffolding and build tool
# - phpunit: PHP testing framework (v9 for CiviCRM compatibility)
# - phpstan: Static analysis tool
RUN curl -LsS https://download.civicrm.org/civix/civix.phar -o /usr/local/bin/civix \
    && chmod +x /usr/local/bin/civix \
    && curl -LsS https://phar.phpunit.de/phpunit-9.phar -o /usr/local/bin/phpunit \
    && chmod +x /usr/local/bin/phpunit \
    && curl -LsS https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar -o /usr/local/bin/phpstan \
    && chmod +x /usr/local/bin/phpstan

COPY entrypoint.sh /usr/local/bin/civikitchen-entrypoint
RUN chmod +x /usr/local/bin/civikitchen-entrypoint

ENTRYPOINT ["/usr/local/bin/civikitchen-entrypoint"]
CMD ["apache2-foreground"]
