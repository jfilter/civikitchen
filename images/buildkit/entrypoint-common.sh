# shellcheck shell=bash
# Shared tail of the buildkit entrypoints (dev entrypoint.sh + demo
# entrypoint-demo.sh) — sourced AFTER the site's database is reachable (dev:
# external DB + `civibuild reinstall`; demo: embedded MariaDB started). It is
# buildkit-image-specific (hardcodes the buildkit user + civibuild site paths),
# which is why it lives here and not in images/lib/ next to the files the
# standalone image shares.
#
# Wires up images/lib/provision.sh for the civibuild site and runs the shared
# first-boot provisioning: auto-composer for bind-mounted extensions, SMTP,
# profiles (CIVIKITCHEN_PROFILE), extension knobs, /civikitchen-init.d hooks,
# and the readiness marker the HEALTHCHECK looks for.

SITE_WEB="/home/buildkit/buildkit/build/site/web"

# Accept legacy CIVICRM_-spelled kitchen vars (parity with the standalone image).
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
# the civibuild site. PATH and SITE_WEB are expanded in THIS (root) shell — the
# entrypoint's PATH already has cv + the dev tools — and printf %q preserves
# both the PATH and each argument's quoting through `su -c`. (Single-quoting the
# PATH here would pass a literal ${PATH} to the inner shell and drop /usr/bin.)
ck_as_web() {
    su -s /bin/bash buildkit -c "export PATH=$(printf '%q' "${PATH}") && cd $(printf '%q' "${SITE_WEB}") && $(printf '%q ' "$@")"
}

# provision.sh parameters for this civibuild site (override standalone defaults).
export CK_WEB_USER=buildkit
export CK_WEB_GROUP=buildkit
export CK_WEB_USER_HOME=/home/buildkit
export CK_DATA_DIRS="/home/buildkit/buildkit/build/site"
export CK_PROVISIONED_MARKER=/home/buildkit/.civikitchen-provisioned
# Discover the extension dir from cv (CMS-agnostic: Drupal + WordPress).
CK_EXT_DIR="$(ck_as_web cv ev 'echo rtrim(CRM_Core_Config::singleton()->extensionsDir, "/");' 2>/dev/null || true)"
export CK_EXT_DIR

. /usr/local/share/civikitchen/provision.sh

# Auto-composer runs every boot (picks up newly bind-mounted extensions).
ck_auto_composer

if [[ ! -f "${CK_PROVISIONED_MARKER}" ]]; then
    echo "[civikitchen] First-boot provisioning (${CIVICRM_SITE_TYPE})..."
    ck_smtp
    # No ck_setup_test_db here: civibuild already provisions an isolated
    # TEST_DB_DSN (its own sitetest_* build) — unlike standalone, which has
    # no civibuild and sets its own.
    ck_post_install_provision
    echo "[civikitchen] Provisioning complete."
fi

# Heal any root-owned files left in the site tree (runs every boot, cheap).
ck_heal_perms
