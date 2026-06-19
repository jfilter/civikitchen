#!/bin/bash
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# Xdebug toggle (shared with standalone image).
. /usr/local/share/civikitchen/xdebug-toggle.sh

# DB connection. Uses the CIVICRM_DB_* prefix for symmetry with the
# standalone image. CIVICRM_DB_ROOT_PASSWORD is the *admin* password (not
# the runtime app password) — civibuild creates a per-site user during
# `civibuild create site`, so the entrypoint needs GRANT-level access.
export CIVICRM_DB_HOST="${CIVICRM_DB_HOST:-db}"
export CIVICRM_DB_PORT="${CIVICRM_DB_PORT:-3306}"
export CIVICRM_DB_ROOT_PASSWORD="${CIVICRM_DB_ROOT_PASSWORD:-root}"
# Default site type comes from the build arg DEFAULT_SITE_TYPE — each published
# CMS tag sets its own civibuild template. Users can override at runtime by
# setting CIVICRM_SITE_TYPE.
CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-${CIVICRM_SITE_TYPE_DEFAULT:-drupal10-demo}}"

# Legacy name: SITE_URL was renamed to CIVIKITCHEN_SITE_URL (kitchen-owned
# behavior knob); the old spelling keeps working with a warning.
if [[ -z "${CIVIKITCHEN_SITE_URL+x}" && -n "${SITE_URL+x}" ]]; then
    echo "[civikitchen] WARN: SITE_URL is deprecated - use CIVIKITCHEN_SITE_URL" >&2
    export CIVIKITCHEN_SITE_URL="${SITE_URL}"
fi

# CIVIKITCHEN_SITE_URL is the URL the browser uses to reach this container.
# Must match the external port from your Docker port mapping (-p flag).
# Examples:
#   docker run -p 8080:80  →  CIVIKITCHEN_SITE_URL=http://localhost:8080
#   docker run -p 80:80    →  CIVIKITCHEN_SITE_URL=http://localhost (default)
if [[ -z "${CIVIKITCHEN_SITE_URL}" ]]; then
    HTTPD_DOMAIN="${HTTPD_DOMAIN:-localhost}"
    HTTPD_PORT="${HTTPD_PORT:-80}"
    if [[ "${HTTPD_PORT}" == "80" ]]; then
        CIVIKITCHEN_SITE_URL="http://${HTTPD_DOMAIN}"
    else
        CIVIKITCHEN_SITE_URL="http://${HTTPD_DOMAIN}:${HTTPD_PORT}"
    fi
fi

MARKER_FILE="/home/buildkit/.site-installed"

echo "CiviCRM Dev Image (${CIVICRM_SITE_TYPE})"
echo "=========================================="
echo "Site URL: ${CIVIKITCHEN_SITE_URL}"

# Wait for the database via PHP mysqli — same probe the standalone image
# uses. mysqli (mysqlnd) sidesteps the TLS-enforcement default that newer
# mariadb-client builds apply to plain dev sidecars.
echo "Waiting for database at ${CIVICRM_DB_HOST}:${CIVICRM_DB_PORT}..."
attempt=0
until php -r '
    // mysqli_report() must be OFF or PHP 8.1+ throws on every failed
    // connect attempt during the wait loop, which is just noise here.
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli(
        getenv("CIVICRM_DB_HOST"),
        "root",
        getenv("CIVICRM_DB_ROOT_PASSWORD"),
        "",
        (int) getenv("CIVICRM_DB_PORT")
    );
    exit($m->connect_errno ? 1 : 0);
' 2>/dev/null; do
    attempt=$((attempt + 1))
    if [[ "${attempt}" -ge 60 ]]; then
        echo "ERROR: database not reachable after 120s" >&2
        exit 1
    fi
    sleep 2
done
echo "Database is ready."

# First boot: re-install the BAKED site against the external DB. The codebase
# (CMS + CiviCRM) was baked at image-build time, so this is a fast `civibuild
# reinstall` (~60s) — it recreates the DBs on the external host and regenerates
# the settings + isolated test DB for it, without re-downloading anything.
if [[ ! -f "${MARKER_FILE}" ]]; then
    echo "First run: installing ${CIVICRM_SITE_TYPE} site against ${CIVICRM_DB_HOST}..."

    BK="su -s /bin/bash buildkit -c"

    # Point amp/civibuild at the external DB (root creds for GRANT-level access:
    # reinstall recreates the per-site databases + users).
    cat > /home/buildkit/.my.cnf <<MYCNF
[client]
host=${CIVICRM_DB_HOST}
port=${CIVICRM_DB_PORT}
user=root
password=${CIVICRM_DB_ROOT_PASSWORD}
MYCNF
    chown buildkit:buildkit /home/buildkit/.my.cnf

    ${BK} "export PATH='${PATH}' && amp config:set --mysql_type=mycnf --httpd_type=none --perm_type=none"

    # The site was baked against a throwaway MariaDB on 127.0.0.1 (see bake.sh),
    # so the civibuild build config + amp instance registry still point there.
    # Repoint them at the external DB host before reinstall — civibuild's
    # `amp create -f` (run by reinstall via amp_install) then re-creates the
    # per-site DBs + users on ${CIVICRM_DB_HOST}. Without this, drush/cv dial
    # 127.0.0.1 inside the container and get "[2002] Connection refused".
    ${BK} "sed -i 's/127\.0\.0\.1/${CIVICRM_DB_HOST}/g' \
        /home/buildkit/buildkit/build/*.sh \
        /home/buildkit/.amp/instances.yml \
        /home/buildkit/.amp/my.cnf.d/* 2>/dev/null || true"

    # reinstall (not create): reuse the baked codebase, recreate the DBs on the
    # external host, regenerate settings for ${CIVIKITCHEN_SITE_URL}.
    ${BK} "export PATH='${PATH}' && civibuild reinstall site --url '${CIVIKITCHEN_SITE_URL}'"

    touch "${MARKER_FILE}"
    echo "Site installed."
else
    echo "Site already installed (skipping)."
fi

# Shared first-boot provisioning (auto-composer, SMTP, CIVIKITCHEN_PROFILE,
# extension knobs, init.d hooks, readiness marker) — identical for the dev and
# demo images, so it lives in entrypoint-common.sh.
. /usr/local/share/civikitchen/entrypoint-common.sh

# Start Apache (needs root for port 80)
echo "Starting Apache..."
echo "Access: ${CIVIKITCHEN_SITE_URL}"
echo "Login: admin / admin"
apachectl -D FOREGROUND
