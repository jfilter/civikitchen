#!/bin/bash
# Install OS-agnostic CiviCRM extension dev tools used by both the standalone
# and buildkit images:
#   - civix    (CiviCRM extension scaffolding/build tool)
#   - phpunit  (pinned to 9 for CiviCRM compatibility)
#   - phpstan  (static analysis)
#   - phpcs + the civicrm/coder fork of drupal/coder (the de-facto CiviCRM
#              style guide — registers as the standard "Drupal" /
#              "DrupalPractice" phpcs standards).
#   - rector   (isolated; powers `ckmodernize` — rector's PHP-version + quality
#              sets plus CiviKitchen's CiviCRM footgun rules)
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
# rector powers `ckmodernize`; pin it too so a rebuild can't silently change
# what gets rewritten.
RECTOR_VERSION="${RECTOR_VERSION:-2.4.6}"
# download.civicrm.org also serves versioned civix phars next to the floating
# civix.phar, so the scaffolding tool can be pinned like everything else.
CIVIX_VERSION="${CIVIX_VERSION:-26.02.0}"
# phpunit 9 is the last line CiviCRM's test base supports; pin the patch too.
PHPUNIT_VERSION="${PHPUNIT_VERSION:-9.6.35}"

# ---------------------------------------------------------------------------
# Phars: civix, phpunit, phpstan
#
# A pinned version only pins what the server chooses to serve — over TLS or
# not, an unverified phar is arbitrary code entering every image. The hashes
# below are the actual pin. Re-derive them after a version bump with:
#
#   curl -LsS <url> | shasum -a 256      # or sha256sum on Linux
#
# A mismatch is fatal on purpose: it means the artifact behind a version that
# is supposed to be immutable changed. Overriding a *_VERSION at build time
# therefore means overriding its *_SHA256 as well.
CIVIX_SHA256="${CIVIX_SHA256:-1c480133bee248c1f09f19c724e2e44266297b63ff2e55a7cbf3ea17a910d906}"
PHPUNIT_SHA256="${PHPUNIT_SHA256:-f39d634a5e5bcafd71565b33328ae4fb173703296c12ac94a24550cb8291e964}"
PHPSTAN_SHA256="${PHPSTAN_SHA256:-487ab20ffe29ce405cf19b4e803933aa7dd97cdb871f457ca57fc9267f5a0f1a}"

# Compared in the shell rather than with `sha256sum -c`: that exits 0 on a
# malformed checksum line, so an empty or truncated *_SHA256 would wave the
# download straight through.
fetch_phar() {
    local url="$1" dest="$2" want="$3" got
    curl -LsS "${url}" -o "${dest}"
    got=$(sha256sum < "${dest}" | cut -d' ' -f1)
    if [ "${got}" != "${want}" ]; then
        echo "checksum mismatch for ${url}: expected ${want}, got ${got}" >&2
        exit 1
    fi
    chmod +x "${dest}"
}

fetch_phar "https://download.civicrm.org/civix/civix-${CIVIX_VERSION}.phar" \
    /usr/local/bin/civix "${CIVIX_SHA256}"

fetch_phar "https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar" \
    /usr/local/bin/phpunit "${PHPUNIT_SHA256}"

fetch_phar "https://github.com/phpstan/phpstan/releases/download/${PHPSTAN_VERSION}/phpstan.phar" \
    /usr/local/bin/phpstan "${PHPSTAN_SHA256}"

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

# ---------------------------------------------------------------------------
# rector (isolated install in its own dir) — powers `ckmodernize`. Its config +
# CiviCRM rules dir is COPYed to /opt/civikitchen-rector by the Dockerfile
# before this script runs; here we add the pinned rector and dump the autoload.
composer require --working-dir=/opt/civikitchen-rector --no-interaction --no-progress \
    "rector/rector:${RECTOR_VERSION}"

rm -rf /opt/composer/cache
chmod -R a+rX /opt/composer "${CODER_DIR}" "${CIVIKITCHEN_CODER_DIR}" /opt/civikitchen-rector
