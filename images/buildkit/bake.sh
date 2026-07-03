#!/bin/bash
# Build-time site bake (runs in the Dockerfile `builder` stage).
#
# Starts a MariaDB inside this build layer, points amp/civibuild at it, and
# runs `civibuild create` so the CMS + CiviCRM codebase (~3-4 min download) is
# baked into the image. The dev `final` stage copies only the resulting code
# tree and discards this DB (its entrypoint does a fast `civibuild reinstall`
# against the external DB sidecar). The `demo` target however is `FROM builder`
# and SHIPS this /var/lib/mysql as its embedded DB — hence the verified clean
# shutdown at the end.
#
# Reads CIVICRM_VERSION + CIVICRM_BUILD_VERSION + DEFAULT_SITE_TYPE + KEEP_GIT
# from the environment (Dockerfile ARGs). CIVICRM_BUILD_VERSION lets CI feed
# civibuild a minor branch (e.g. 6.15) while labels still record the resolved
# upstream stable patch (e.g. 6.15.4).
set -euxo pipefail

CIVICRM_CREATE_VERSION="${CIVICRM_BUILD_VERSION:-${CIVICRM_VERSION}}"

service mariadb start
for i in $(seq 1 60); do
    mysqladmin ping >/dev/null 2>&1 && break
    [ "$i" -eq 60 ] && { echo "bake.sh: MariaDB never became ready" >&2; exit 1; }
    sleep 1
done
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'root'; FLUSH PRIVILEGES;"

# Drupal 11.4+ (Standard profile) installs the new Navigation module instead of
# Toolbar, so the 'access toolbar' permission no longer exists and upstream
# buildkit's civicrm_apply_d8_perm_defaults (src/civibuild.lib.sh) hard-fails
# the whole `civibuild create` on it — drush: "Permission(s) not found: access
# toolbar" (first seen 2026-07-01, hours after Drupal 11.4.0 shipped; Drupal
# =<11.3 and 10.x still install Toolbar and are unaffected). Grant whichever
# admin-bar permission the installed core provides; neither is worth sinking a
# build over. The patched clone is COPY'd into the demo and final images, so
# the runtime `civibuild reinstall` takes the same path. Drop this once
# upstream handles Drupal 11.4 (the sed then quietly no-ops and the grep
# below says so).
sed -i 's@^  drush8 -y role-add-perm demoadmin "access toolbar"$@  drush8 -y role-add-perm demoadmin "access toolbar" || drush8 -y role-add-perm demoadmin "access navigation" || true@' \
    /home/buildkit/buildkit/src/civibuild.lib.sh
grep -q 'access navigation' /home/buildkit/buildkit/src/civibuild.lib.sh \
    || echo "bake.sh: NOTE: upstream 'access toolbar' line changed; Drupal 11.4 toolbar-perm patch no longer applies (probably fixed upstream)" >&2

# Upstream civibuild has no drupal11-demo site type — only drupal11-dev (a
# git/path-repo dev build without demo data, components, or a demoadmin) and
# drupal11-empty (CMS-only). Install CiviKitchen's own drupal11-demo type
# (images/buildkit/site-types/drupal11-demo/ — drupal10-demo's recipe with the
# Drupal 11.4 adaptations documented there) so the drupal11 images get the
# identical demo data / demoadmin / component set as every other flavor, and
# CIVICRM_SITE_TYPE=drupal11-demo works at runtime on any buildkit image. The
# version-agnostic demo assets (logo, config/, install-*.php, uninstall.sh)
# are reused from the sibling drupal10-demo dir. Guarded: the day upstream
# ships app/config/drupal11-demo, this block steps aside.
BK_CONFIG=/home/buildkit/buildkit/app/config
if [ ! -d "${BK_CONFIG}/drupal11-demo" ]; then
    cp -r "${BK_CONFIG}/drupal10-demo" "${BK_CONFIG}/drupal11-demo"
    cp /usr/local/share/civikitchen/site-types/drupal11-demo/download.sh \
       /usr/local/share/civikitchen/site-types/drupal11-demo/install.sh \
       "${BK_CONFIG}/drupal11-demo/"
    chown -R buildkit:buildkit "${BK_CONFIG}/drupal11-demo"
fi

# Run civibuild as the buildkit user (it owns the site tree). The heredoc is
# expanded by THIS shell (so ${DEFAULT_SITE_TYPE}/${CIVICRM_CREATE_VERSION}
# resolve); \$PATH is escaped so it expands inside the buildkit shell.
su -s /bin/bash buildkit <<BK
set -e
export PATH=/home/buildkit/buildkit/bin:\$PATH
printf '[client]\nhost=127.0.0.1\nport=3306\nuser=root\npassword=root\n' > /home/buildkit/.my.cnf
amp config:set --mysql_type=mycnf --httpd_type=none --perm_type=none
if [ '${DEFAULT_SITE_TYPE}' = 'joomla-demo' ]; then
  # Upstream buildkit's joomla-demo currently downloads Joomla to
  # \$WEB_ROOT/web but links CiviCRM into \$WEB_ROOT/joomla. Its install step
  # also calls joomlaxml.php without DM_TMPDIR, which makes CiviCRM 6.15 write
  # XML files below /com_civicrm. Finally, setup.sh can leave the install canary
  # table behind after a successful Joomla install; drop it before snapshotting.
  sed -i 's|pushd "\$WEB_ROOT/joomla"|pushd "\$WEB_ROOT/web"|' /home/buildkit/buildkit/app/config/joomla-demo/download.sh
  sed -i \
    -e 's|cvutil_mkdir "\$TMPDIR/\$SITE_NAME"{,/joomlaxml,/joomlaxml/admin}|cvutil_mkdir "\$TMPDIR/\$SITE_NAME"{,/com_civicrm,/com_civicrm/admin}|' \
    -e 's|php "\$CIVI_CORE/distmaker/utils/joomlaxml.php"|DM_TMPDIR="\$TMPDIR/\$SITE_NAME" php "\$CIVI_CORE/distmaker/utils/joomlaxml.php"|' \
    -e 's|"\$TMPDIR/\$SITE_NAME/joomlaxml/civicrm.xml"|"\$TMPDIR/\$SITE_NAME/com_civicrm/civicrm.xml"|' \
    -e 's|"\$TMPDIR/\$SITE_NAME/joomlaxml/admin/access.xml"|"\$TMPDIR/\$SITE_NAME/com_civicrm/admin/access.xml"|' \
    /home/buildkit/buildkit/app/config/joomla-demo/install.sh
  sed -i '/^civicrm_install$/a\
echo "DROP TABLE IF EXISTS civicrm_install_canary;" | cvutil_php_nodbg amp sql -Ncivi --root="\$CMS_ROOT" || true' \
    /home/buildkit/buildkit/app/config/joomla-demo/install.sh
  sed -i '/pushd "\$WEB_ROOT" >> \/dev\/null/a\
  [ -f web/configuration.php ] && rm -f web/configuration.php\
  [ ! -d web/installation ] && [ -f web/installation.zip ] && unzip -q web/installation.zip -d web' \
    /home/buildkit/buildkit/app/config/joomla-demo/uninstall.sh
fi
# civibuild create downloads the CMS + civicrm-core, including dozens of bundled
# JS assets the composer-downloads-plugin fetches from github.com. A single
# transient github.com 5xx on ANY one of them (seen in CI: dc-js/dc.js 2.1.10.zip
# -> HTTP 502, after composer's own retries) makes composer — and thus the whole
# multi-arch image build — fail, with civibuild's bash then dying noisily
# ("pop_var_context: ... not a function context"). Retry the create so a
# transient blip doesn't sink the build; the composer cache (cleared only at the
# end of this heredoc) means a retry re-fetches just the asset that failed, not
# the whole tree. </dev/null on both keeps a stray prompt from hanging the
# non-interactive build; destroy resets partial state between attempts.
for attempt in 1 2 3; do
  if civibuild create site --type '${DEFAULT_SITE_TYPE}' --civi-ver '${CIVICRM_CREATE_VERSION}' --url http://localhost --admin-pass admin </dev/null; then
    break
  fi
  if [ "\$attempt" = 3 ]; then
    echo "bake.sh: civibuild create failed after 3 attempts (last was likely a transient github.com download)" >&2
    exit 1
  fi
  echo "bake.sh: civibuild create attempt \$attempt failed (transient download?); resetting + retrying..." >&2
  civibuild destroy site </dev/null >/dev/null 2>&1 || true
  sleep \$((attempt * 15))
done
# brick/money 0.12+ renamed ISOCurrencyProvider; CiviCRM still references the old
# name. Pin compatible (non-fatal — only matters for some CMS/Civi combos).
{ cd /home/buildkit/buildkit/build/site && [ -f composer.json ] && composer require 'brick/money:<0.12' -W --no-interaction; } || true
if [ '${DEFAULT_SITE_TYPE}' = 'joomla-demo' ]; then
  # civibuild's joomla-demo install is deliberately incomplete (it leaves the
  # Joomla component registration as a commented-out "#fixme" and enables only
  # civi_contribute). Finish it so the demo matches the Drupal/WordPress ones.
  # Same script the dev image re-runs after its first-boot reinstall (see
  # joomla-finish.sh) — kept out of line here so there is one source of truth.
  bash /usr/local/share/civikitchen/joomla-finish.sh
fi
# Drop build-time download caches (composer + npm, ~800MB). The final image
# COPYs ~buildkit wholesale, so clearing them here keeps them out. The runtime
# \`civibuild reinstall\` reuses the baked vendor/, so nothing is re-downloaded.
rm -rf /home/buildkit/.composer/cache /home/buildkit/.npm /home/buildkit/.cache
BK

# Strip the git history civibuild cloned into the site (~550MB, most of it
# civicrm-core). Neither serving the site nor the runtime `civibuild reinstall`
# needs it — but `civibuild update` / working on core does, so it is opt-in:
# build with --build-arg KEEP_GIT=1 to keep the repos (see docs/building.md).
if [ "${KEEP_GIT:-0}" != "1" ]; then
    echo "bake.sh: stripping git history from the baked site (--build-arg KEEP_GIT=1 keeps it)"
    find /home/buildkit/buildkit/build/site -type d -name .git -prune -exec rm -rf {} +
fi

# VERIFIED clean shutdown. The `demo` target is `FROM builder` and ships this
# /var/lib/mysql as its embedded DB — an unclean stop here means InnoDB crash
# recovery on every demo first boot. Wait for the server PROCESS to exit (the
# socket file can linger after a kill, so it is no clean-shutdown signal) and
# fail the build if it doesn't.
mysqladmin -uroot -proot shutdown
for i in $(seq 1 60); do
    pgrep -x mariadbd >/dev/null || pgrep -x mysqld >/dev/null || break
    [ "$i" -eq 60 ] && { echo "bake.sh: MariaDB did not shut down cleanly" >&2; exit 1; }
    sleep 1
done
