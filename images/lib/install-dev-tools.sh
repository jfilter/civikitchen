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
# Pinned tool versions — bump deliberately here. The image tags are floating
# (standalone-6.12 rebuilds monthly for CiviCRM patches); without pins the dev
# tools would drift too, so a rebuild could silently bump phpstan to a new
# major and turn a green `phpstan analyse` red with no code change. Override at
# build time with --build-arg PHPSTAN_VERSION=... / CODER_REF=...
PHPSTAN_VERSION="${PHPSTAN_VERSION:-2.2.2}"
# civicrm/coder has no usable release tags, so pin to a commit on 8.x-2.x-civi.
CODER_REF="${CODER_REF:-aa31dd918e302f6c01f6d28a495256e171abf581}"
# civix is intentionally NOT pinned: it ships only as a floating phar on
# download.civicrm.org (no versioned URLs), and as a scaffolding tool it
# generates code on demand rather than running in CI, so its drift doesn't turn
# existing extensions' pipelines red.

# ---------------------------------------------------------------------------
# Phars: civix, phpunit, phpstan
curl -LsS https://download.civicrm.org/civix/civix.phar -o /usr/local/bin/civix
chmod +x /usr/local/bin/civix

curl -LsS https://phar.phpunit.de/phpunit-9.phar -o /usr/local/bin/phpunit
chmod +x /usr/local/bin/phpunit

curl -LsS "https://github.com/phpstan/phpstan/releases/download/${PHPSTAN_VERSION}/phpstan.phar" \
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
# still registers as "Drupal" / "DrupalPractice" via phpcs). Pinned to
# CODER_REF: clone the branch (small repo, no --depth so an older commit stays
# checkout-able) and check out the exact ref for a reproducible ruleset.
CODER_DIR=/opt/civicrm-coder
git clone --branch 8.x-2.x-civi https://github.com/civicrm/coder.git "${CODER_DIR}"
git -C "${CODER_DIR}" checkout --quiet "${CODER_REF}"
rm -rf "${CODER_DIR}/.git"

# Register with phpcs. --config-set writes to the CodeSniffer.conf alongside
# the phpcs install (in /opt/composer/vendor/squizlabs/php_codesniffer/), so
# the setting applies to every user that invokes phpcs in this image.
# Two paths (comma-separated): the civicrm-coder fork (Drupal/DrupalPractice)
# AND the bundled CiviKitchen standard (CiviCRM-tuned Drupal + footgun sniffs,
# what `cklint` runs). The Dockerfile COPYs the CiviKitchen dir to
# ${CIVIKITCHEN_CODER_DIR} before this script runs.
CIVIKITCHEN_CODER_DIR=/opt/civikitchen-coder
phpcs --config-set installed_paths "${CODER_DIR}/coder_sniffer,${CIVIKITCHEN_CODER_DIR}"

rm -rf /opt/composer/cache
chmod -R a+rX /opt/composer "${CODER_DIR}" "${CIVIKITCHEN_CODER_DIR}"
