#!/bin/bash
# Install OS-agnostic CiviCRM extension dev tools used by both the standalone
# and buildkit images:
#   - civix    (CiviCRM extension scaffolding/build tool)
#   - phpunit  (pinned to 9 for CiviCRM compatibility)
#   - phpstan  (static analysis)
#   - phpcs + the civicrm/coder fork of drupal/coder (the de-facto CiviCRM
#              style guide — registers as the standard "Drupal" /
#              "DrupalPractice" phpcs standards).
#
# Prerequisites (handled per image):
#   - composer, php, curl, git on PATH
#   - /opt/composer/vendor/bin on PATH (so phpcs/phpcbf are picked up)
set -euo pipefail

# ---------------------------------------------------------------------------
# Phars: civix, phpunit, phpstan
curl -LsS https://download.civicrm.org/civix/civix.phar -o /usr/local/bin/civix
chmod +x /usr/local/bin/civix

curl -LsS https://phar.phpunit.de/phpunit-9.phar -o /usr/local/bin/phpunit
chmod +x /usr/local/bin/phpunit

curl -LsS https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar \
    -o /usr/local/bin/phpstan
chmod +x /usr/local/bin/phpstan

# ---------------------------------------------------------------------------
# phpcs (from packagist) + civicrm/coder fork (cloned directly).
#
# Why we don't `composer require` civicrm/coder via a VCS repo:
# Composer's GitHub VCS driver hits the REST API to enumerate branches.
# Anonymous requests are rate-limited (60/hr per IP). When the API is
# exhausted, composer falls back to plain `git clone`, which then defaults
# to the SSH remote (git@github.com:…) and fails with "Host key verification
# failed" in unauthenticated build environments (Docker, GHA matrix, etc.).
# A direct `git clone https://…` avoids the API entirely and is deterministic.
#
# COMPOSER_HOME is scoped to this script so it doesn't leak into the runtime
# image — users who run `composer install` inside a container later
# shouldn't be steered at /opt/composer (they can't write its cache as
# www-data, and it breaks multi-stage builds where a non-root stage runs
# composer).
export COMPOSER_HOME=/opt/composer
export COMPOSER_ALLOW_SUPERUSER=1

composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global require --no-interaction --no-progress \
    "squizlabs/php_codesniffer:^3" \
    "dealerdirect/phpcodesniffer-composer-installer:^1"

# Clone the civicrm fork of drupal/coder (relaxed Drupal CS rules; ruleset
# still registers as "Drupal" / "DrupalPractice" via phpcs).
CODER_DIR=/opt/civicrm-coder
git clone --depth 1 --branch 8.x-2.x-civi https://github.com/civicrm/coder.git "${CODER_DIR}"
rm -rf "${CODER_DIR}/.git"

# Register with phpcs. --config-set writes to the CodeSniffer.conf alongside
# the phpcs install (in /opt/composer/vendor/squizlabs/php_codesniffer/), so
# the setting applies to every user that invokes phpcs in this image.
phpcs --config-set installed_paths "${CODER_DIR}/coder_sniffer"

rm -rf /opt/composer/cache
chmod -R a+rX /opt/composer "${CODER_DIR}"
