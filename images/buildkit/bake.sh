#!/bin/bash
# Build-time site bake (runs in the Dockerfile `builder` stage).
#
# Starts a THROWAWAY MariaDB inside this build layer, points amp/civibuild at it,
# and runs `civibuild create` so the CMS + CiviCRM codebase (~3-4 min download)
# is baked into the image. The final stage copies only the resulting code tree —
# this DB is discarded. At runtime the entrypoint does a fast `civibuild
# reinstall` against the external DB sidecar instead of a full create.
#
# Reads CIVICRM_VERSION + DEFAULT_SITE_TYPE from the environment (Dockerfile ARGs).
set -euxo pipefail

service mariadb start
for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
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

# Best-effort stop; the throwaway DB data is discarded with the builder stage,
# so a non-clean shutdown here is harmless (and must not fail the build).
service mariadb stop || true
