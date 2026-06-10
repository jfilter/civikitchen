#!/bin/bash
# Entrypoint for the single-container DEMO images (civikitchen:*-demo).
# Unlike images/buildkit/entrypoint.sh (external DB + fast `civibuild
# reinstall` on first boot), the demo image carries an EMBEDDED MariaDB whose
# data dir was baked at build time (the civibuild `-demo` site). So first boot
# is just: start MariaDB on the baked data → run the shared opt-in
# provisioning (incl. an optional CIVIKITCHEN_PROFILE apply, e.g. eu-ngo) →
# start Apache. No external DB, no reinstall, no 127.0.0.1->host rewrite (the
# DB host is 127.0.0.1 at both bake and run time, so the baked grants stay
# valid).
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

# Shared first-boot provisioning (auto-composer, SMTP, CIVIKITCHEN_PROFILE,
# extension knobs, init.d hooks, readiness marker) — identical for the dev and
# demo images, so it lives in entrypoint-common.sh. For a demo the base site is
# already baked, so these are mostly opt-in knobs; a CIVIKITCHEN_PROFILE apply
# (git clones + seeds) makes first boot take minutes instead of seconds.
. /usr/local/share/civikitchen/entrypoint-common.sh

# Start Apache (needs root for port 80).
echo "Starting Apache..."
apachectl -D FOREGROUND
