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
#   - mago     (Rust binary; PHP half of `ckfmt`, formats to the bundled
#              phpcs standard's expectations)
#   - oxlint   (npm toolchain + tsgolint Go binary; powers `ckeslint`)
#   - oxfmt    (npm toolchain; JS/TS half of `ckfmt`)
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
# FLOOR: 2.2.3 is the lowest release phpstan-phpunit 2.0.18 accepts (^2.2.3);
# every other extension pinned below resolves against it, so keep the bump small.
PHPSTAN_VERSION="${PHPSTAN_VERSION:-2.2.3}"
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
#
# CEILING: 8.22.1 is the last release that accepts phpcs ^3 — from 8.23 on it
# requires php_codesniffer ^4.0.1, and this whole install would stop resolving.
# phpcs cannot follow: the civicrm/coder fork pinned at CODER_REF still requires
# phpcs ^3, and under 4.0 the Drupal standard aborts outright ("Referenced sniff
# Squiz.CSS.* does not exist", plus the CSS/JS scanning removal). So this pin
# moves only together with a coder fork that speaks phpcs 4 — do not bump it on
# its own, and do not let a bot do it.
SLEVOMAT_VERSION="${SLEVOMAT_VERSION:-8.22.1}"
# civicrm/coder has no usable release tags, so pin to a commit on 8.x-2.x-civi.
CODER_REF="${CODER_REF:-aa31dd918e302f6c01f6d28a495256e171abf581}"
# rector powers `ckmodernize`; pin it too so a rebuild can't silently change
# what gets rewritten.
RECTOR_VERSION="${RECTOR_VERSION:-2.4.6}"
# CEILING: rector reads a private property of phpstan's RichParser that 2.2.6
# removed, so its (loose ^2.2.2) phpstan must be pinned or every run fatals.
RECTOR_PHPSTAN_VERSION="${RECTOR_PHPSTAN_VERSION:-2.2.5}"
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
# mago powers the PHP half of `ckfmt`. A formatter that changes its output
# after an unrelated rebuild would turn every repo's format gate red, so the
# same drift rule as everything else applies.
MAGO_VERSION="${MAGO_VERSION:-1.45.0}"
# npm bundles its own dependency tree (tar, glob, minimatch, sigstore, ...) and
# that tree lands in the image scan; nothing in this repo's lockfiles can move
# it, so npm gets installed over the distro package's own — and pinned, like
# every other tool here, because an unpinned `npm@latest` would drift the
# resolver under two committed lockfiles on every monthly rebuild.
NPM_VERSION="${NPM_VERSION:-12.0.2}"

# ---------------------------------------------------------------------------
# Phars: civix, phpunit. (phpstan is NOT a phar: it is composer-installed
# below so extensions can register, and its integrity is composer's dist
# checksum — a phar fetched here would be overwritten by that wrapper and its
# hash check would guard nothing.)
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
# mago ships per-arch binaries and publishes no checksum file, so both hashes
# are derived locally at pin time (curl -LsS <url> | shasum -a 256) — one per
# platform the images build for.
MAGO_SHA256_X86_64="${MAGO_SHA256_X86_64:-c18bde21c4f2be586ccfcb070694cdde99e8522df9ff182d993c617d3907d5ef}"
MAGO_SHA256_AARCH64="${MAGO_SHA256_AARCH64:-4b97298b31e294b0c17928a788392b6dfaaab59c5fb4df2a3504c4e4f847a62f}"

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

# ---------------------------------------------------------------------------
# mago (PHP half of `ckfmt`) — a prebuilt Rust binary per architecture, same
# verify-then-trust treatment as the phars. gnu build, not musl: these are
# Debian-based images.
case "$(uname -m)" in
    x86_64)  MAGO_ARCH=x86_64;  MAGO_SHA256="${MAGO_SHA256_X86_64}" ;;
    aarch64) MAGO_ARCH=aarch64; MAGO_SHA256="${MAGO_SHA256_AARCH64}" ;;
    *) echo "unsupported architecture for mago: $(uname -m)" >&2; exit 1 ;;
esac
curl -LsS -o /tmp/mago.tar.gz \
    "https://github.com/carthage-software/mago/releases/download/${MAGO_VERSION}/mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu.tar.gz"
got=$(sha256sum < /tmp/mago.tar.gz | cut -d' ' -f1)
if [ "${got}" != "${MAGO_SHA256}" ]; then
    echo "checksum mismatch for mago ${MAGO_VERSION} (${MAGO_ARCH}): expected ${MAGO_SHA256}, got ${got}" >&2
    exit 1
fi
tar -xzf /tmp/mago.tar.gz -C /tmp "mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu/mago"
mv "/tmp/mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu/mago" /usr/local/bin/mago
chmod +x /usr/local/bin/mago
rm -rf /tmp/mago.tar.gz "/tmp/mago-${MAGO_VERSION}-${MAGO_ARCH}-unknown-linux-gnu"

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
    "rector/rector:${RECTOR_VERSION}" \
    "phpstan/phpstan:${RECTOR_PHPSTAN_VERSION}"

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
# From the committed lockfile: psalm drags ~50 transitive packages, and a
# monthly rebuild re-resolving them would be exactly the drift the version
# pins exist to prevent (oxlint installs from its lockfile for the same
# reason). Overriding PSALM_VERSION re-resolves — accepting a fresh tree is
# the meaning of that override.
PSALM_LOCKED=$(php -r 'echo json_decode(file_get_contents("/opt/civikitchen-psalm/composer.json"))->require->{"vimeo/psalm"} ?? "";')
if [ "${PSALM_VERSION}" = "${PSALM_LOCKED}" ] && [ -f /opt/civikitchen-psalm/composer.lock ]; then
    composer install --working-dir=/opt/civikitchen-psalm --no-interaction --no-progress
else
    composer require --working-dir=/opt/civikitchen-psalm --no-interaction --no-progress \
        "vimeo/psalm:${PSALM_VERSION}"
fi

# ---------------------------------------------------------------------------
# npm itself, before the two `npm ci` runs below.
npm install -g "npm@${NPM_VERSION}" --no-audit --no-fund --loglevel=error

# TRANSITIONAL: npm 12 skips a dependency's install scripts unless the project
# approved them (measured — under npm 10/11 a postinstall runs, under 12.0.2 it
# does not). CiviCRM core's package.json runs tools/scripts/npm/postinstall.sh,
# buildkit's civi-download-tools installs a set old enough to carry install
# scripts, and extension frontends lean on esbuild/playwright postinstalls —
# none of those trees has an `allowScripts` block yet, so the default leaves
# every one of them half-installed behind a warning.
# Deliberate opt-out of a real protection, with a real exit: delete this line
# once those trees approve their own scripts.
npm config set dangerously-allow-all-scripts true --location=global

# ---------------------------------------------------------------------------
# oxlint toolchain (powers `ckeslint`) — installed into the directory the
# Dockerfile COPYd the baseline .oxlintrc.json to, so the config's `jsPlugins`
# resolve against the node_modules right beside it.
#
# In the IMAGE and not in a dozen package.json files, for the same reason the
# phpcs standard lives here: an extension's frontend is a handful of files, and
# a linter devDependency block per repo is a dozen trees to keep in step and a
# dozen dependabot PRs a month for a linter nobody chose per-repo. A repo that
# wants its own rules writes an .oxlintrc.json and ckeslint steps aside.
#
# Versions are pinned in that package.json, not floated with a caret, and the
# lockfile beside it is committed and installed with `npm ci`: the image tags
# rebuild monthly, and a linter minor that adds a rule to a category — or a
# transitive dependency moving underneath a pinned direct one — would turn a
# green repo red with no code change. Exactly what the phpstan pin above
# prevents on the PHP side, and the same rule this project's own standards put
# on every extension's lockfile.
OXLINT_DIR=/opt/civikitchen-oxlint
npm ci --prefix "${OXLINT_DIR}" --no-audit --no-fund --loglevel=error

# The type-aware half is a Go binary in a platform-specific subpackage; if npm
# resolved none of them, ckeslint would silently lose every type-aware rule.
ls "${OXLINT_DIR}"/node_modules/@oxlint-tsgolint/*/tsgolint >/dev/null

# oxfmt toolchain (JS half of `ckfmt`) — same shape as the oxlint install:
# pinned in its package.json, resolved from its committed lockfile. The npm
# package selects the platform binding itself via optionalDependencies.
OXFMT_DIR=/opt/civikitchen-oxfmt
npm ci --prefix "${OXFMT_DIR}" --no-audit --no-fund --loglevel=error

rm -rf /opt/composer/cache ~/.npm
chmod -R a+rX /opt/composer "${CODER_DIR}" "${CIVIKITCHEN_CODER_DIR}" \
    /opt/civikitchen-rector "${PHPSTAN_DIR}" "${PHPSTAN_EXT_DIR}" \
    /opt/civikitchen-phpstan-config /opt/civikitchen-psalm "${OXLINT_DIR}" \
    "${OXFMT_DIR}" /opt/civikitchen-mago /usr/local/bin/mago
