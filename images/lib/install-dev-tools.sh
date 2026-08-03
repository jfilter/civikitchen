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
# Reports every call into code marked @deprecated (or #[\Deprecated]). Not a
# phpstan default and not implied by any level, including 10 — it ships as a
# separate package. This is how deprecated CiviCRM *symbols* get caught without
# anyone maintaining a list; the hook catalog in ckconform covers the one thing
# it structurally cannot see, since a hook implementation is a function
# definition matching a naming convention, not a call to a deprecated symbol.
PHPSTAN_DEPRECATION_RULES_VERSION="${PHPSTAN_DEPRECATION_RULES_VERSION:-2.0.5}"
# Registers the rules with phpstan automatically, so no project has to add an
# `includes:` line to its phpstan.neon.
PHPSTAN_EXTENSION_INSTALLER_VERSION="${PHPSTAN_EXTENSION_INSTALLER_VERSION:-1.4.3}"
# civicrm/coder has no usable release tags, so pin to a commit on 8.x-2.x-civi.
CODER_REF="${CODER_REF:-aa31dd918e302f6c01f6d28a495256e171abf581}"
# rector powers `ckmodernize`; pin it too so a rebuild can't silently change
# what gets rewritten.
RECTOR_VERSION="${RECTOR_VERSION:-2.4.6}"
# PHPCompatibility powers `ckcompat`. See the require below for why an alpha.
PHPCOMPATIBILITY_VERSION="${PHPCOMPATIBILITY_VERSION:-10.0.0-alpha2}"
# civix is intentionally NOT pinned: it ships only as a floating phar on
# download.civicrm.org (no versioned URLs), and as a scaffolding tool it
# generates code on demand rather than running in CI, so its drift doesn't turn
# existing extensions' pipelines red.

# ---------------------------------------------------------------------------
# Phars: civix, phpunit
curl -LsS https://download.civicrm.org/civix/civix.phar -o /usr/local/bin/civix
chmod +x /usr/local/bin/civix

curl -LsS https://phar.phpunit.de/phpunit-9.phar -o /usr/local/bin/phpunit
chmod +x /usr/local/bin/phpunit

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
# PHPCompatibility powers `ckcompat` (does this code RUN on the declared PHP
# floor — the question phpstan's phpVersion does not answer). Pinned to an
# alpha deliberately: the last stable is 9.3.5 from 2019, which predates every
# PHP version these extensions target. 10.x is the line that knows PHP 8.
composer global require --no-interaction --no-progress \
    "squizlabs/php_codesniffer:^3" \
    "dealerdirect/phpcodesniffer-composer-installer:^1" \
    "phpcompatibility/php-compatibility:${PHPCOMPATIBILITY_VERSION}"

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
#
# This SETS the list, so the paths the composer installer plugin registered for
# PHPCompatibility (and the PHPCSUtils it is built on) have to be repeated here
# or `ckcompat` loses its standard on the next build.
CIVIKITCHEN_CODER_DIR=/opt/civikitchen-coder
phpcs --config-set installed_paths \
    "${CODER_DIR}/coder_sniffer,${CIVIKITCHEN_CODER_DIR},${COMPOSER_HOME}/vendor/phpcompatibility/php-compatibility,${COMPOSER_HOME}/vendor/phpcsstandards/phpcsutils"

# ---------------------------------------------------------------------------
# rector (isolated install in its own dir) — powers `ckmodernize`. Its config +
# CiviCRM rules dir is COPYed to /opt/civikitchen-rector by the Dockerfile
# before this script runs; here we add the pinned rector and dump the autoload.
composer require --working-dir=/opt/civikitchen-rector --no-interaction --no-progress \
    "rector/rector:${RECTOR_VERSION}"

# ---------------------------------------------------------------------------
# phpstan (isolated install, same shape as rector above).
#
# Why not the standalone phar any more: phpstan extensions are composer
# packages, and the phar cannot load one. The deprecation rules are worth that
# switch — they are the only thing that catches calls into the ~640 symbols
# CiviCRM core marks @deprecated, and they need no list anyone has to maintain.
#
# extension-installer is a composer plugin, so it needs allow-plugins like the
# phpcs installer above; it writes a GeneratedConfig.php into its own vendor
# tree, which means the rules are active for every project this phpstan
# analyses without a single project neon mentioning them.
PHPSTAN_DIR=/opt/civikitchen-phpstan
mkdir -p "${PHPSTAN_DIR}"
[ -f "${PHPSTAN_DIR}/composer.json" ] || echo '{}' > "${PHPSTAN_DIR}/composer.json"
composer config --working-dir="${PHPSTAN_DIR}" --no-plugins \
    allow-plugins.phpstan/extension-installer true
composer require --working-dir="${PHPSTAN_DIR}" --no-interaction --no-progress \
    "phpstan/phpstan:${PHPSTAN_VERSION}" \
    "phpstan/phpstan-deprecation-rules:${PHPSTAN_DEPRECATION_RULES_VERSION}" \
    "phpstan/extension-installer:${PHPSTAN_EXTENSION_INSTALLER_VERSION}"

# Goes through composer's bin proxy — the documented entry point for a composer
# install, and the one that keeps working if a future extension does need the
# autoloader. (The bundled phar happens to find the registered rules too, but
# nothing promises that.)
cat > /usr/local/bin/phpstan <<EOF
#!/bin/sh
exec php ${PHPSTAN_DIR}/vendor/bin/phpstan "\$@"
EOF
chmod +x /usr/local/bin/phpstan

rm -rf /opt/composer/cache
chmod -R a+rX /opt/composer "${CODER_DIR}" "${CIVIKITCHEN_CODER_DIR}" \
    /opt/civikitchen-rector "${PHPSTAN_DIR}"
