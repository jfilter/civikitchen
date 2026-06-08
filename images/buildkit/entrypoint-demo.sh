#!/bin/bash
# Entrypoint for the single-container DEMO images (civikitchen:*-demo,
# civicrm-eu-ngo). Unlike images/buildkit/entrypoint.sh (external DB + fast
# `civibuild reinstall` on first boot), the demo image carries an EMBEDDED
# MariaDB whose data dir was baked at build time (the civibuild `-demo` site +
# any profile). So first boot is just: start MariaDB on the baked data → run
# the shared opt-in provisioning → start Apache. No external DB, no reinstall,
# no 127.0.0.1->host rewrite (the DB host is 127.0.0.1 at both bake and run
# time, so the baked grants stay valid).
set -e

export PATH="/home/buildkit/buildkit/bin:${PATH}"

# Xdebug toggle (shared with the dev images).
. /usr/local/share/civikitchen/xdebug-toggle.sh

CIVICRM_SITE_TYPE="${CIVICRM_SITE_TYPE:-${CIVICRM_SITE_TYPE_DEFAULT:-drupal10-demo}}"

# The site was baked at --url http://localhost. CIVIKITCHEN_SITE_URL is shown
# for reference; the demo is meant to run on `-p 80:80`. (A non-default port
# would need the baked base-URL rewritten — out of scope for the demo.)
if [[ -z "${CIVIKITCHEN_SITE_URL:-}" ]]; then
    CIVIKITCHEN_SITE_URL="http://localhost"
fi

echo "CiviCRM Demo Image (${CIVICRM_SITE_TYPE})"
echo "=========================================="
echo "Access: ${CIVIKITCHEN_SITE_URL}"
echo "Login:  admin / admin"

# Start the embedded MariaDB on the baked data dir and wait for it.
echo "Starting embedded MariaDB..."
service mariadb start
attempt=0
until mysqladmin --user=root --password=root ping >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [[ "${attempt}" -ge 60 ]]; then
        echo "ERROR: embedded MariaDB not ready after 60s" >&2
        exit 1
    fi
    sleep 1
done
echo "Database is ready."

# ---------------------------------------------------------------------------
# Shared first-boot provisioning — the same images/lib/provision.sh the dev
# images use. For a demo the extension/data is already baked, so these are
# mostly opt-in knobs (SMTP, enabling bind-mounted extensions, /civikitchen-
# init.d hooks); running them keeps the demo and dev images behaviourally in
# lockstep and writes the readiness marker the HEALTHCHECK looks for.
SITE_WEB="/home/buildkit/buildkit/build/site/web"

# Accept legacy CIVICRM_-spelled kitchen vars (parity with the dev images).
_ck_legacy() {
    local new="$1" legacy="$2"
    if [[ -z "${!new+x}" && -n "${!legacy+x}" ]]; then
        echo "[civikitchen] WARN: ${legacy} is deprecated - use ${new}" >&2
        export "${new}=${!legacy}"
    fi
}
_ck_legacy CIVIKITCHEN_SMTP_HOST         CIVICRM_SMTP_HOST
_ck_legacy CIVIKITCHEN_SMTP_PORT         CIVICRM_SMTP_PORT
_ck_legacy CIVIKITCHEN_EXTRA_EXTENSIONS  CIVICRM_EXTRA_EXTENSIONS
_ck_legacy CIVIKITCHEN_ENABLE_EXTENSIONS CIVICRM_ENABLE_EXTENSIONS
_ck_legacy CIVIKITCHEN_AUTO_COMPOSER     CIVICRM_AUTO_COMPOSER

# Run a command as the buildkit user from the site docroot so cv auto-detects
# the civibuild site (identical helper to the dev entrypoint).
ck_as_web() {
    su -s /bin/bash buildkit -c "export PATH=$(printf '%q' "${PATH}") && cd $(printf '%q' "${SITE_WEB}") && $(printf '%q ' "$@")"
}

# provision.sh parameters for this civibuild site.
export CK_WEB_USER=buildkit
export CK_WEB_GROUP=buildkit
export CK_WEB_USER_HOME=/home/buildkit
export CK_DATA_DIRS="/home/buildkit/buildkit/build/site"
export CK_PROVISIONED_MARKER=/home/buildkit/.civikitchen-provisioned
CK_EXT_DIR="$(ck_as_web cv ev 'echo rtrim(CRM_Core_Config::singleton()->extensionsDir, "/");' 2>/dev/null || true)"
export CK_EXT_DIR

. /usr/local/share/civikitchen/provision.sh

# Auto-composer runs every boot (picks up newly bind-mounted extensions).
ck_auto_composer

if [[ ! -f "${CK_PROVISIONED_MARKER}" ]]; then
    echo "[civikitchen] First-boot provisioning (${CIVICRM_SITE_TYPE})..."
    ck_smtp
    ck_post_install_provision
    echo "[civikitchen] Provisioning complete."
fi

# Heal any root-owned files in the site tree (runs every boot, cheap).
ck_heal_perms

# Start Apache (needs root for port 80).
echo "Starting Apache..."
apachectl -D FOREGROUND
