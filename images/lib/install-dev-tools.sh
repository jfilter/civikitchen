#!/bin/bash
# Install OS-agnostic CiviCRM extension dev tools used by both the standalone
# and buildkit images:
#   - civix    (CiviCRM extension scaffolding/build tool)
#   - phpunit  (pinned to 9 for CiviCRM compatibility)
#   - phpstan  (isolated; static analysis incl. the deprecation rules and
#              CiviKitchen's `@ck-legacy` deprecated-scope resolver)
#   - phpcs + the civicrm/coder fork of drupal/coder (the de-facto CiviCRM
#              style guide — registers as the standard "Drupal" /
#              "DrupalPractice" phpcs standards).
#   - rector   (isolated; powers `ckmodernize` — rector's PHP-version + quality
#              sets plus CiviKitchen's CiviCRM footgun rules)
#   - psalm    (isolated; powers `cktaint` — used ONLY as a taint engine, with
#              CiviCRM source/sink/escape stubs)
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
# Bans as CONFIG instead of sniff code (civicrm-disallowed.neon). Inert until a
# project includes a config, so installing it changes nothing by itself.
PHPSTAN_DISALLOWED_CALLS_VERSION="${PHPSTAN_DISALLOWED_CALLS_VERSION:-v4.14.0}"
# Opt-in per repo (see the ignore list below) — switching these on fleet-wide
# at level 10 would turn every repo red in one build.
PHPSTAN_STRICT_RULES_VERSION="${PHPSTAN_STRICT_RULES_VERSION:-2.0.12}"
# Type narrowing for phpunit assertions (fewer level-10 false reports in tests)
# and architecture rules AS phpstan rules. Both inert until a repo has tests
# resp. writes an ArchitectureRule class, so both register automatically.
PHPSTAN_PHPUNIT_VERSION="${PHPSTAN_PHPUNIT_VERSION:-2.0.18}"
PHPAT_VERSION="${PHPAT_VERSION:-0.12.4}"
# Powers `ckdeps`: composer.json against the dependencies the code really uses.
COMPOSER_DEPENDENCY_ANALYSER_VERSION="${COMPOSER_DEPENDENCY_ANALYSER_VERSION:-1.8.4}"
# Cherry-picked sniffs only (DeclareStrictTypes today); the full standard would
# fight the Drupal base the CiviKitchen ruleset is built on.
SLEVOMAT_VERSION="${SLEVOMAT_VERSION:-8.31.1}"
# civicrm/coder has no usable release tags, so pin to a commit on 8.x-2.x-civi.
CODER_REF="${CODER_REF:-aa31dd918e302f6c01f6d28a495256e171abf581}"
# rector powers `ckmodernize`; pin it too so a rebuild can't silently change
# what gets rewritten.
RECTOR_VERSION="${RECTOR_VERSION:-2.4.6}"
# PHPCompatibility powers `ckcompat`. See the require below for why an alpha.
PHPCOMPATIBILITY_VERSION="${PHPCOMPATIBILITY_VERSION:-10.0.0-alpha2}"
# psalm powers `cktaint`. Pinned for the same reason, and one more: a taint
# engine that reports differently after an unrelated rebuild is worthless as a
# review signal. Note psalm's own `php` constraint is patch-level
# (~8.3.16 || ~8.4.3 || …), so a base image on an older PHP patch fails this
# install loudly rather than silently skipping the tool.
PSALM_VERSION="${PSALM_VERSION:-6.16.1}"
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
# PHPCompatibility powers `ckcompat` (does this code RUN on the declared PHP
# floor — the question phpstan's phpVersion does not answer). Pinned to an
# alpha deliberately: the last stable is 9.3.5 from 2019, which predates every
# PHP version these extensions target. 10.x is the line that knows PHP 8.
composer global require --no-interaction --no-progress \
    "squizlabs/php_codesniffer:^3" \
    "dealerdirect/phpcodesniffer-composer-installer:^1" \
    "phpcompatibility/php-compatibility:${PHPCOMPATIBILITY_VERSION}" \
    "slevomat/coding-standard:${SLEVOMAT_VERSION}" \
    "shipmonk/composer-dependency-analyser:${COMPOSER_DEPENDENCY_ANALYSER_VERSION}"

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
    "${CODER_DIR}/coder_sniffer,${CIVIKITCHEN_CODER_DIR},${COMPOSER_HOME}/vendor/phpcompatibility/php-compatibility,${COMPOSER_HOME}/vendor/phpcsstandards/phpcsutils,${COMPOSER_HOME}/vendor/slevomat/coding-standard"

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
# CiviKitchen's own phpstan extension (@ck-legacy deprecated-scope resolver).
# The Dockerfile COPYs it here; it joins the install as a composer path
# repository so extension-installer registers it exactly like the upstream
# rules — see docs/extension-standards.md. Its composer.json carries an
# explicit `version` because the COPYed directory has no git history for
# composer to derive one from.
PHPSTAN_EXT_DIR=/opt/civikitchen-phpstan-ext
mkdir -p "${PHPSTAN_DIR}"
[ -f "${PHPSTAN_DIR}/composer.json" ] || echo '{}' > "${PHPSTAN_DIR}/composer.json"
composer config --working-dir="${PHPSTAN_DIR}" --no-plugins \
    allow-plugins.phpstan/extension-installer true
composer config --working-dir="${PHPSTAN_DIR}" --no-plugins \
    repositories.civikitchen-phpstan path "${PHPSTAN_EXT_DIR}"
# strict-rules is installed but NOT auto-registered: at level 10 it would turn
# every repo red in the build that shipped it. Repos opt in with one `includes:`
# line (see docs/extension-standards.md). disallowed-calls stays auto-registered
# because it is inert until a project includes a ban list.
composer config --working-dir="${PHPSTAN_DIR}" --no-plugins --json \
    extra.phpstan/extension-installer.ignore '["phpstan/phpstan-strict-rules"]'
composer require --working-dir="${PHPSTAN_DIR}" --no-interaction --no-progress \
    "phpstan/phpstan:${PHPSTAN_VERSION}" \
    "phpstan/phpstan-deprecation-rules:${PHPSTAN_DEPRECATION_RULES_VERSION}" \
    "phpstan/extension-installer:${PHPSTAN_EXTENSION_INSTALLER_VERSION}" \
    "spaze/phpstan-disallowed-calls:${PHPSTAN_DISALLOWED_CALLS_VERSION}" \
    "phpstan/phpstan-phpunit:${PHPSTAN_PHPUNIT_VERSION}" \
    "phpat/phpat:${PHPAT_VERSION}" \
    "phpstan/phpstan-strict-rules:${PHPSTAN_STRICT_RULES_VERSION}" \
    "civikitchen/phpstan-ck-legacy:*"

# Goes through composer's bin proxy — the documented entry point for a composer
# install, and the one that keeps working if a future extension does need the
# autoloader. (The bundled phar happens to find the registered rules too, but
# nothing promises that.)
cat > /usr/local/bin/phpstan <<EOF
#!/bin/sh
exec php ${PHPSTAN_DIR}/vendor/bin/phpstan "\$@"
EOF
chmod +x /usr/local/bin/phpstan

# ---------------------------------------------------------------------------
# psalm (isolated install in its own dir) — powers `cktaint`. Its taint config
# and CiviCRM stubs are COPYed to /opt/civikitchen-psalm by the Dockerfile
# before this script runs; here we add the pinned psalm.
#
# Isolated, NOT `composer global require` alongside phpcs, and deliberately not
# near phpstan: psalm pulls ~50 packages of its own (amphp, nikic/php-parser,
# felixfbecker/…), and the phpstan tree above is what the static-analysis gate
# every extension depends on — it must not be perturbed by another tool's
# dependency resolution. Two engines, two roots, no shared resolution.
composer require --working-dir=/opt/civikitchen-psalm --no-interaction --no-progress \
    "vimeo/psalm:${PSALM_VERSION}"

rm -rf /opt/composer/cache
chmod -R a+rX /opt/composer "${CODER_DIR}" "${CIVIKITCHEN_CODER_DIR}" \
    /opt/civikitchen-rector "${PHPSTAN_DIR}" "${PHPSTAN_EXT_DIR}" \
    /opt/civikitchen-phpstan-config /opt/civikitchen-psalm
