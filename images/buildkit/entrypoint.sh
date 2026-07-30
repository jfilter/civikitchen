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
    # The marker lives in the container filesystem; the DB may live on a
    # persistent volume. A recreated container (image update + pull_policy:
    # always, docker rm) loses the marker — and `civibuild reinstall` DROPS
    # the site databases. Refuse to wipe data that survived us: a sentinel
    # row written after every successful install marks the external DB as
    # carrying a civikitchen site.
    if [[ "${CIVIKITCHEN_REINSTALL:-0}" != "1" ]] && php -r '
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new mysqli(getenv("CIVICRM_DB_HOST"), "root",
            getenv("CIVICRM_DB_ROOT_PASSWORD"), "", (int) getenv("CIVICRM_DB_PORT"));
        if ($m->connect_errno) { exit(1); }
        $r = @$m->query("SELECT 1 FROM civikitchen_state.site_installed LIMIT 1");
        exit(($r && $r->num_rows > 0) ? 0 : 1);
    ' 2>/dev/null; then
        echo "ERROR: this container is fresh, but the database at ${CIVICRM_DB_HOST} already holds a civikitchen site." >&2
        echo "       Rebuilding the site would DROP those databases (your dev data)." >&2
        echo "       Either set CIVIKITCHEN_REINSTALL=1 to rebuild anyway (drops the site DBs)," >&2
        echo "       or remove the DB volume for a clean start (docker compose down -v)." >&2
        exit 1
    fi

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
    chmod 600 /home/buildkit/.my.cnf

    ${BK} "export PATH=$(printf '%q' "${PATH}") && amp config:set --mysql_type=mycnf --httpd_type=none --perm_type=none"

    # The site was baked against a throwaway MariaDB on 127.0.0.1 (see bake.sh),
    # so the civibuild build config + amp instance registry still point there.
    # Repoint them at the external DB host before reinstall — civibuild's
    # `amp create -f` (run by reinstall via amp_install) then re-creates the
    # per-site DBs + users on ${CIVICRM_DB_HOST}. Without this, drush/cv dial
    # 127.0.0.1 inside the container and get "[2002] Connection refused".
    # Escape sed-replacement metacharacters in the host (/, &, \).
    DB_HOST_SED=$(printf '%s' "${CIVICRM_DB_HOST}" | sed 's/[\/&\\]/\\&/g')
    ${BK} "sed -i 's/127\.0\.0\.1/${DB_HOST_SED}/g' \
        /home/buildkit/buildkit/build/*.sh \
        /home/buildkit/.amp/instances.yml \
        /home/buildkit/.amp/my.cnf.d/* 2>/dev/null || true"

    # reinstall (not create): reuse the baked codebase, recreate the DBs on the
    # external host, regenerate settings for ${CIVIKITCHEN_SITE_URL}.
    ${BK} "export PATH=$(printf '%q' "${PATH}") && civibuild reinstall site --url $(printf '%q' "${CIVIKITCHEN_SITE_URL}")"

    # joomla-demo's civibuild install is deliberately incomplete (component
    # registration left as a #fixme, only civi_contribute enabled). The demo
    # image bakes the finish into its embedded DB; the dev image rebuilds the
    # site from that incomplete install on every first boot (the reinstall above)
    # and discards the baked DB — so re-run the same finish here, or the dev
    # :joomla image's option=com_civicrm route (admin UI + api_key API) and the
    # extra component extensions are missing. Idempotent + Joomla-only (the
    # script self-guards); runs as buildkit, before the profile apply in
    # entrypoint-common.sh below.
    if [[ "${CIVICRM_SITE_TYPE}" == joomla* ]]; then
        ${BK} "export PATH=$(printf '%q' "${PATH}") && bash /usr/local/share/civikitchen/joomla-finish.sh"
    fi

    # Sentinel for the fresh-container guard above: mark the external DB as
    # holding an installed site (survives container recreation).
    php -r '
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @new mysqli(getenv("CIVICRM_DB_HOST"), "root",
            getenv("CIVICRM_DB_ROOT_PASSWORD"), "", (int) getenv("CIVICRM_DB_PORT"));
        if ($m->connect_errno) { exit(1); }
        $m->query("CREATE DATABASE IF NOT EXISTS civikitchen_state");
        $m->query("CREATE TABLE IF NOT EXISTS civikitchen_state.site_installed (
            id TINYINT UNSIGNED PRIMARY KEY, installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $m->query("REPLACE INTO civikitchen_state.site_installed (id) VALUES (1)");
    ' || echo "WARNING: could not record the install sentinel in civikitchen_state" >&2

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
