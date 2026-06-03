#!/bin/bash
set -e

# civikitchen standalone entrypoint.
#
# 1. If XDEBUG_MODE is set, activate xdebug for this container.
# 2. If CIVICRM_AUTO_INSTALL=1 and CiviCRM is not yet installed, wait for the
#    database and run `cv core:install`.
# 3. Hand off to the upstream civicrm-docker-entrypoint.
#
# Why runtime install (not a build-time SQL dump like allinone/)?
# allinone's embedded MariaDB lives in the same container as CiviCRM,
# so a build-time install + mysqldump roundtrip targets a localhost DB
# that's still localhost at runtime. This image is meant to point at an
# external MariaDB whose host/credentials are only known at runtime, so
# baking a dump would require regenerating civicrm.settings.php on first
# start anyway. The ~8s saving wasn't worth the extra build complexity.

# ---------------------------------------------------------------------------
# Xdebug toggle (shared with buildkit image).
. /usr/local/share/civikitchen/xdebug-toggle.sh

# ---------------------------------------------------------------------------
# Extra OS packages (e.g. libreoffice-writer + unoconv for CiviOffice
# rendering) without baking them into the image. Comma- or space-separated.
# Installed once per container; restarts skip already-present packages.
if [[ -n "${CIVIKITCHEN_EXTRA_PACKAGES}" ]]; then
    _ck_missing=()
    for pkg in ${CIVIKITCHEN_EXTRA_PACKAGES//,/ }; do
        dpkg -s "${pkg}" >/dev/null 2>&1 || _ck_missing+=("${pkg}")
    done
    if [[ ${#_ck_missing[@]} -gt 0 ]]; then
        echo "[civikitchen] Installing extra packages: ${_ck_missing[*]}"
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${_ck_missing[@]}"
        rm -rf /var/lib/apt/lists/*
    fi
fi

# ---------------------------------------------------------------------------
# Auto-run composer install for bind-mounted extensions.
#
# An extension's composer.json typically pulls in dev tooling (phpunit,
# phpstan) that's not in the image. Running composer install here saves
# every contributor from doing it by hand before `vendor/bin/phpunit`
# works — historically the most common new-contributor stumble.
#
# Runs before the auto-install so extensions enabled during install (e.g.
# via CIVICRM_ENABLE_EXTENSIONS) already have their vendor/ in place.
#
# Idempotent: skips when vendor/ already exists. Runs on every container
# start (not just the first install) so newly bind-mounted extensions are
# picked up without rebuilding. Failure is non-fatal — the container still
# boots; the user can fix composer.json and restart.
#
# Opt out via CIVICRM_AUTO_COMPOSER=0 (e.g. if you ship vendor/ in the
# repo or want full control). Default is on.
CIVICRM_AUTO_COMPOSER="${CIVICRM_AUTO_COMPOSER:-1}"
if [[ "${CIVICRM_AUTO_COMPOSER}" == "1" ]]; then
    shopt -s nullglob
    for composer_json in /var/www/html/ext/*/composer.json; do
        ext_dir="$(dirname "${composer_json}")"
        ext_name="$(basename "${ext_dir}")"
        if [[ -d "${ext_dir}/vendor" ]]; then
            continue
        fi
        # Extensions whose lock file vendors civicrm-core (the systopia
        # dev-tooling pattern) must NOT get that vendor/ inside a running
        # CiviCRM — their autoloader would load a second core. They need a
        # runtime vendor/ built with their own tooling instead (their
        # pre-update hook strips the civicrm packages on `composer update`).
        if grep -q '"name": "civicrm/civicrm-core"' "${ext_dir}/composer.lock" 2>/dev/null; then
            echo "[civikitchen] WARN: skipping composer install in ext/${ext_name} — its lock file vendors civicrm/civicrm-core. Build a runtime vendor/ outside the container (composer update --no-dev) instead." >&2
            continue
        fi
        echo "[civikitchen] composer install in ext/${ext_name}..."
        if ( cd "${ext_dir}" && composer install --no-interaction --no-progress --prefer-dist ); then
            # Bind mounts belong to the host user; match vendor/ to the mount
            # owner so it isn't left root-owned on Linux hosts.
            chown -R --reference="${ext_dir}" "${ext_dir}/vendor" 2>/dev/null || true
        else
            echo "[civikitchen] WARN: composer install failed in ext/${ext_name} — set CIVICRM_AUTO_COMPOSER=0 or fix composer.json" >&2
        fi
    done
    shopt -u nullglob
fi

# ---------------------------------------------------------------------------
# Auto-install.
export CIVICRM_DB_HOST="${CIVICRM_DB_HOST:-db}"
export CIVICRM_DB_PORT="${CIVICRM_DB_PORT:-3306}"
export CIVICRM_DB_NAME="${CIVICRM_DB_NAME:-civicrm}"
export CIVICRM_DB_USER="${CIVICRM_DB_USER:-civicrm}"
export CIVICRM_DB_PASSWORD="${CIVICRM_DB_PASSWORD:-civicrm}"

CIVICRM_AUTO_INSTALL="${CIVICRM_AUTO_INSTALL:-0}"
SETTINGS_FILE="/var/www/html/private/civicrm.settings.php"

if [[ "${CIVICRM_AUTO_INSTALL}" == "1" && ! -f "${SETTINGS_FILE}" ]]; then
    echo "[civikitchen] CIVICRM_AUTO_INSTALL=1, settings not present"
    echo "[civikitchen] Waiting for database at ${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}..."

    attempt=0
    until php -r '
        // mysqli_report() must be OFF or PHP 8.1+ throws on every failed
        // connect attempt during the wait loop, which is just noise here.
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new mysqli(
            getenv("CIVICRM_DB_HOST"),
            getenv("CIVICRM_DB_USER"),
            getenv("CIVICRM_DB_PASSWORD"),
            getenv("CIVICRM_DB_NAME"),
            (int) getenv("CIVICRM_DB_PORT")
        );
        exit($m->connect_errno ? 1 : 0);
    ' 2>/dev/null; do
        attempt=$((attempt + 1))
        if [[ "${attempt}" -ge 30 ]]; then
            echo "[civikitchen] ERROR: database not reachable after 60s" >&2
            exit 1
        fi
        sleep 2
    done

    DB_URL="mysql://${CIVICRM_DB_USER}:${CIVICRM_DB_PASSWORD}@${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}/${CIVICRM_DB_NAME}"
    # SITE_URL is the URL the browser uses. It MUST match the host:port the
    # user opens — Civi bakes it into every JS/CSS asset URL, so a mismatch
    # silently breaks the Angular login form because asset fetches fail.
    SITE_URL="${SITE_URL:-http://localhost}"

    # cv core:install --comp accepts a comma-separated list. cv's own default
    # enables only the core component, which is wrong for a dev image —
    # extensions assuming CiviContribute/CiviCase/etc. would silently fail.
    # Default to all standard components; user can override (or pass an
    # empty string to fall back to cv's core-only default).
    CIVICRM_COMPONENTS="${CIVICRM_COMPONENTS-CiviEvent,CiviContribute,CiviMember,CiviMail,CiviPledge,CiviCase,CiviReport,CiviCampaign}"
    INSTALL_OPTS=()
    if [[ -n "${CIVICRM_COMPONENTS}" ]]; then
        INSTALL_OPTS+=(--comp="${CIVICRM_COMPONENTS}")
    fi

    echo "[civikitchen] Running cv core:install (cmsBaseUrl=${SITE_URL}${CIVICRM_COMPONENTS:+, components=${CIVICRM_COMPONENTS}})..."
    # cv --url is the documented flag for setting cmsBaseUrl during install.
    # It populates the model BEFORE init plugins run, so every
    # $civicrm_paths[*]['url'] is derived from SITE_URL (cms.root,
    # civicrm.root, civicrm.files, civicrm.vendor — all of them).
    #
    # -K keeps existing tables — survives `docker compose down` (without -v)
    # where settings file is lost but DB volume persists.
    # Run as www-data: /var/www/html/private/ is owned by www-data, and the
    # install creates settings files + cache dirs there. Running as root
    # leaves them root-owned and apache can't later write to the cache dir.
    runuser -u www-data -- cv core:install -n -K --url="${SITE_URL}" --db="${DB_URL}" "${INSTALL_OPTS[@]}"
    echo "[civikitchen] CiviCRM installed."

    # Dev-mode defaults — buildkit's standalone-clean install.sh sets these
    # after every install, and there's no reason a dev image would want them
    # off. Quiet the output; failure here is non-fatal (settings backfill on
    # next request).
    runuser -u www-data -- cv setting:set environment=Development >/dev/null
    runuser -u www-data -- cv setting:set debug_enabled=1 >/dev/null
    echo "[civikitchen] Dev settings applied (environment=Development, debug_enabled=1)."

    # Point Civi's mail backend at an SMTP host (e.g. the maildev sidecar in
    # the example compose). Without this, Civi defaults to the PHP `mail()`
    # function and outbound mail silently goes nowhere in a containerised
    # dev environment. Opt-in via CIVICRM_SMTP_HOST.
    if [[ -n "${CIVICRM_SMTP_HOST}" ]]; then
        SMTP_PORT="${CIVICRM_SMTP_PORT:-1025}"
        echo "[civikitchen] Configuring mail backend → ${CIVICRM_SMTP_HOST}:${SMTP_PORT}..."
        runuser -u www-data -- env SMTP_HOST="${CIVICRM_SMTP_HOST}" SMTP_PORT="${SMTP_PORT}" \
            cv ev '\Civi::settings()->set("mailing_backend", ["outBound_option" => 0, "smtpServer" => getenv("SMTP_HOST"), "smtpPort" => (int) getenv("SMTP_PORT"), "smtpAuth" => 0, "smtpUsername" => "", "smtpPassword" => ""]);'
    fi

    # Demo login user. Opt-in via CIVICRM_DEMO_USER (mirroring how
    # CIVICRM_AUTO_INSTALL itself defaults to off). The shipped example
    # compose file sets it so `docker compose up -d` lands at a logged-in-able
    # CiviCRM out of the box.
    if [[ -n "${CIVICRM_DEMO_USER}" ]]; then
        DEMO_USER="${CIVICRM_DEMO_USER}"
        DEMO_PASS="${CIVICRM_DEMO_PASS:-admin}"
        DEMO_EMAIL="${CIVICRM_DEMO_EMAIL:-admin@example.org}"
        echo "[civikitchen] Creating demo user '${DEMO_USER}'..."
        # Pass env explicitly via `env` rather than relying on
        # runuser --preserve-environment, which is filtered by PAM.
        runuser -u www-data -- \
            env DEMO_USER="${DEMO_USER}" DEMO_PASS="${DEMO_PASS}" DEMO_EMAIL="${DEMO_EMAIL}" \
            cv scr /usr/local/share/civikitchen/demo-user.php
    fi

fi

# ---------------------------------------------------------------------------
# Post-install provisioning: extra/mounted extensions and first-boot hooks.
#
# Gated by a success marker rather than the settings file: the install above
# writes the settings file first, so if anything in here fails (a typo'd
# extension key, a broken hook) the container exits loudly AND the next start
# retries this block — instead of silently skipping it because the settings
# file already exists.
PROVISIONED_MARKER="/var/www/html/private/.civikitchen-provisioned"
if [[ "${CIVICRM_AUTO_INSTALL}" == "1" && -f "${SETTINGS_FILE}" && ! -f "${PROVISIONED_MARKER}" ]]; then
    # Extra extensions: download + enable a comma-separated list after the
    # core install. Replaces the per-project boilerplate of running
    # `cv ext:download` + `cv ext:enable` from a setup script (extension
    # repos' run-phpunit.sh, Playwright auth.setup.ts, etc.). Each entry can
    # be a bare key (de.systopia.xcm) — pulled from the public registry —
    # or `key@URL` for a pinned / forked release; cv ext:download accepts
    # the same syntax natively. Hard-fails on a bad key/URL so a typo in
    # compose env doesn't silently start a half-broken site.
    if [[ -n "${CIVICRM_EXTRA_EXTENSIONS}" ]]; then
        echo "[civikitchen] Installing extra extensions: ${CIVICRM_EXTRA_EXTENSIONS}"
        IFS=',' read -ra _CK_EXTS <<< "${CIVICRM_EXTRA_EXTENSIONS}"
        for ext_spec in "${_CK_EXTS[@]}"; do
            ext_spec="${ext_spec// /}"
            [[ -z "${ext_spec}" ]] && continue
            ext_key="${ext_spec%%@*}"
            echo "[civikitchen]   - ${ext_key}"
            # Split download + enable into two cv calls. The combined
            # `cv ext:download <key@URL>` (which auto-enables) bombs
            # for some extensions with "Cannot install or enable" — the
            # same code path works when run as separate steps. Also lets
            # later entries in the list (e.g. de.systopia.twingle) see
            # their dependencies (e.g. de.systopia.xcm) as installed
            # during the requirements check.
            runuser -u www-data -- cv ext:download -n --no-install "${ext_spec}"
            runuser -u www-data -- cv ext:enable "${ext_key}"
        done
    fi

    # Enable extensions that are already present (e.g. bind-mounted into
    # /var/www/html/ext) by key. Complements CIVICRM_EXTRA_EXTENSIONS, which
    # downloads from the public registry.
    if [[ -n "${CIVICRM_ENABLE_EXTENSIONS}" ]]; then
        echo "[civikitchen] Enabling extensions: ${CIVICRM_ENABLE_EXTENSIONS}"
        IFS=',' read -ra _CK_ENABLE <<< "${CIVICRM_ENABLE_EXTENSIONS}"
        for ext_key in "${_CK_ENABLE[@]}"; do
            ext_key="${ext_key// /}"
            [[ -z "${ext_key}" ]] && continue
            runuser -u www-data -- cv ext:enable "${ext_key}"
        done
    fi

    # First-boot provisioning hooks: mount scripts into /civikitchen-init.d/
    # and they run once after a fresh install, in lexical order — *.sh via
    # bash (as root, e.g. for system setup), *.php via `cv scr` (as www-data,
    # e.g. for Civi settings or seed data). A failing hook aborts the boot so
    # misconfiguration is loud, not silent.
    if [[ -d /civikitchen-init.d ]]; then
        for hook in /civikitchen-init.d/*; do
            [[ -e "${hook}" ]] || continue
            case "${hook}" in
                *.sh)
                    echo "[civikitchen] init hook (bash): ${hook}"
                    bash "${hook}"
                    ;;
                *.php)
                    echo "[civikitchen] init hook (cv scr): ${hook}"
                    runuser -u www-data -- cv scr "${hook}"
                    ;;
                *)
                    echo "[civikitchen] init hook skipped (expects *.sh or *.php): ${hook}" >&2
                    ;;
            esac
        done
    fi

    touch "${PROVISIONED_MARKER}"
fi

# ---------------------------------------------------------------------------
# Heal root-owned files in the CiviCRM data dirs. Anything run as root inside
# the container may leave files behind that the www-data web workers can't
# write — caches, lock files, upload dirs. Cheap no-op when ownership is
# already correct. -h: change symlinks themselves, never their targets.
find /var/www/html/private /var/www/html/public ! -user www-data \
    -exec chown -h www-data:www-data {} + 2>/dev/null || true

exec civicrm-docker-entrypoint "$@"
