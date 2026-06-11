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
# Reads CIVICRM_VERSION + DEFAULT_SITE_TYPE + KEEP_GIT from the environment
# (Dockerfile ARGs).
set -euxo pipefail

service mariadb start
for i in $(seq 1 60); do
    mysqladmin ping >/dev/null 2>&1 && break
    [ "$i" -eq 60 ] && { echo "bake.sh: MariaDB never became ready" >&2; exit 1; }
    sleep 1
done
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'root'; FLUSH PRIVILEGES;"

# Run civibuild as the buildkit user (it owns the site tree). The heredoc is
# expanded by THIS shell (so ${DEFAULT_SITE_TYPE}/${CIVICRM_VERSION} resolve);
# \$PATH is escaped so it expands inside the buildkit shell.
su -s /bin/bash buildkit <<BK
set -e
export PATH=/home/buildkit/buildkit/bin:\$PATH
printf '[client]\nhost=127.0.0.1\nport=3306\nuser=root\npassword=root\n' > /home/buildkit/.my.cnf
amp config:set --mysql_type=mycnf --httpd_type=none --perm_type=none
civibuild create site --type '${DEFAULT_SITE_TYPE}' --civi-ver '${CIVICRM_VERSION}' --url http://localhost --admin-pass admin
# brick/money 0.12+ renamed ISOCurrencyProvider; CiviCRM still references the old
# name. Pin compatible (non-fatal — only matters for some CMS/Civi combos).
{ cd /home/buildkit/buildkit/build/site && [ -f composer.json ] && composer require 'brick/money:<0.12' -W --no-interaction; } || true
# Drop build-time download caches (composer + npm, ~800MB). The final image
# COPYs ~buildkit wholesale, so clearing them here keeps them out. The runtime
# \`civibuild reinstall\` reuses the baked vendor/, so nothing is re-downloaded.
rm -rf /home/buildkit/.composer/cache /home/buildkit/.npm /home/buildkit/.cache
BK

# Strip the git history civibuild cloned into the site (~550MB, most of it
# civicrm-core). Neither serving the site nor the runtime `civibuild reinstall`
# needs it — but `civibuild update` / working on core does, so it is opt-in:
# build with --build-arg KEEP_GIT=1 to keep the repos (see README "Building
# locally").
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
