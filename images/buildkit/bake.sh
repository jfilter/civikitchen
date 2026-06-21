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
civibuild create site --type '${DEFAULT_SITE_TYPE}' --civi-ver '${CIVICRM_CREATE_VERSION}' --url http://localhost --admin-pass admin
# brick/money 0.12+ renamed ISOCurrencyProvider; CiviCRM still references the old
# name. Pin compatible (non-fatal — only matters for some CMS/Civi combos).
{ cd /home/buildkit/buildkit/build/site && [ -f composer.json ] && composer require 'brick/money:<0.12' -W --no-interaction; } || true
if [ '${DEFAULT_SITE_TYPE}' = 'joomla-demo' ]; then
  # civibuild's joomla-demo install is deliberately incomplete: its install.sh
  # leaves the Joomla component registration as a commented-out "#fixme" and
  # enables only the civi_contribute component extension. Finish that install so
  # the demo matches the Drupal/WordPress ones. (cv here relies on the Joomla CLI
  # bootstrap shim baked in the base stage.)
  cd /home/buildkit/buildkit/build/site/web
  # (a) Register + enable CiviCRM's Joomla plugins (civibuild's #fixme step):
  #     this gives CiviCRM its HTTP request hook, so the api_key API works. The
  #     com_civicrm *component* install fails on civibuild's path layout
  #     (script.civicrm.php -> admin/admin/configure.php) and isn't needed for
  #     the API route, so only the plugins are installed.
  php cli/joomla.php extension:discover
  cv scr /tmp/joomla-enable-plugins.php
  # (b) Enable the standard component extensions the Drupal/WP demo types enable
  #     (joomla-demo ships only civi_contribute) so CIVIKITCHEN_PROFILE seeds
  #     find their APIv4 entities (MembershipType, …). One at a time, deps first
  #     (afform needs search_kit; civi_case needs afform) — a batched enable
  #     can't autoload a just-enabled dependency in the same process.
  for ext in org.civicrm.search_kit org.civicrm.afform civi_member civi_event civi_case civi_campaign civi_pledge civi_report civi_mail; do
    cv ext:enable "\$ext"
  done
  # (c) Joomla bundles an ancient brick/math (0.8.17) that nothing in Joomla
  #     uses, but which shadows CiviCRM's at runtime (both autoload in one
  #     process, Joomla's wins) — breaking every CiviCRM Money operation
  #     (Money::of() type error → contributions, membership fees, SEPA seeds).
  #     Align Joomla's copy to CiviCRM's bundled version so Money works.
  jbm=/home/buildkit/buildkit/build/site/web/libraries/vendor/brick/math
  cbm=/home/buildkit/buildkit/build/site/src/civicrm/admin/civicrm/vendor/brick/math
  if [ -d "\$jbm" ] && [ -d "\$cbm" ]; then
    rm -rf "\$jbm" && cp -a "\$cbm" "\$jbm"
  fi
  # (d) Ship + enable the ckjoomlaidentity extension. On Joomla it loads the
  #     Joomla identity matching the authx-authenticated CiviCRM user, so
  #     permission-checked operations work on headless requests (api_key writes
  #     and cv --user seeds) — which cv/authx otherwise leave running as guest.
  mkdir -p media/civicrm/ext/ckjoomlaidentity
  cp -a /tmp/ck-ext/ckjoomlaidentity/. media/civicrm/ext/ckjoomlaidentity/
  cv ext:enable ckjoomlaidentity
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
